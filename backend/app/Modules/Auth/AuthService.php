<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Modules\Auth\AuthRepository;
use App\Core\AuditLogger;
use App\Core\Env;
use App\Core\ApiException;
use App\Core\Request;
use App\Core\ClientContext;
use DateTimeImmutable;

final class AuthService
{
    private string $cookieName;

    public function __construct(
        private readonly AuthRepository $repository,
        private readonly AuditLogger $audit
    ) {
        $this->cookieName = (string)Env::get('SESSION_COOKIE_NAME', 'mutual_session');
    }

    public function login(array $input, string $correlationId): array
    {
        $username = trim((string)($input['usuario'] ?? ''));
        $password = (string)($input['contrasena'] ?? '');

        if ($username === '' || $password === '') {
            throw new ApiException(
                'Ingresá usuario y contraseña.',
                'VALIDATION_ERROR',
                422,
                ['usuario' => 'Obligatorio', 'contrasena' => 'Obligatoria']
            );
        }
        if (mb_strlen($username) > 100 || strlen($password) > 255) {
            throw new ApiException('Usuario o contraseña incorrectos.', 'INVALID_CREDENTIALS', 401);
        }

        $lockMinutes = max(1, Env::int('LOGIN_LOCK_MINUTES', 15));
        $maxAttempts = max(1, Env::int('LOGIN_MAX_ATTEMPTS', 5));
        $ip = ClientContext::ip();

        if ($this->repository->recentFailureCount($username, $lockMinutes) >= $maxAttempts) {
            throw new ApiException(
                "Demasiados intentos fallidos. Intentá nuevamente en {$lockMinutes} minutos.",
                'LOGIN_LOCKED',
                429
            );
        }
        if ($this->repository->recentIpFailureCount($ip, $lockMinutes) >= 20) {
            throw new ApiException(
                'Se detectaron demasiados intentos desde este origen. Intentá más tarde.',
                'IP_RATE_LIMITED',
                429
            );
        }

        $user = $this->repository->findUser($username);
        $valid = $user && password_verify($password, (string)$user['hash_contrasena']);

        if (!$valid) {
            $this->repository->recordAttempt(
                $user ? (int)$user['id_usuario'] : null,
                $username,
                $ip,
                ClientContext::userAgent(),
                false
            );
            $this->audit->record(
                $user ? (int)$user['id_usuario'] : null,
                'auth',
                'login',
                'usuario',
                $user['id_usuario'] ?? null,
                ['usuario' => $username],
                'failed',
                $correlationId
            );
            throw new ApiException('Usuario o contraseña incorrectos.', 'INVALID_CREDENTIALS', 401);
        }

        if (!(bool)$user['activo']) {
            $this->repository->recordAttempt(
                (int)$user['id_usuario'],
                $username,
                $ip,
                ClientContext::userAgent(),
                false
            );
            throw new ApiException('El usuario se encuentra deshabilitado.', 'USER_DISABLED', 403);
        }

        if (password_needs_rehash((string)$user['hash_contrasena'], $this->passwordAlgorithm())) {
            $newHash = password_hash($password, $this->passwordAlgorithm());
            if (is_string($newHash)) {
                $this->repository->updatePasswordHash((int)$user['id_usuario'], $newHash);
            }
        }

        $sessionToken = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $hours = max(1, min(72, Env::int('SESSION_ABSOLUTE_HOURS', 12)));
        $expires = (new DateTimeImmutable())->modify("+{$hours} hours");

        $sessionId = $this->repository->createSession(
            (int)$user['id_usuario'],
            hash('sha256', $sessionToken),
            $csrfToken,
            $expires->format('Y-m-d H:i:s'),
            $ip,
            ClientContext::userAgent()
        );

        $this->repository->recordAttempt(
            (int)$user['id_usuario'],
            $username,
            $ip,
            ClientContext::userAgent(),
            true
        );
        $this->repository->updateLastLogin((int)$user['id_usuario']);
        $this->setSessionCookie($sessionToken);
        $permissions = $this->repository->permissionsForRole((int)$user['id_rol']);
        $this->audit->record(
            (int)$user['id_usuario'],
            'auth',
            'login',
            'sesion',
            $sessionId,
            [],
            'success',
            $correlationId
        );

        return $this->profile($user, $permissions, $expires->format(DATE_ATOM), $csrfToken);
    }

    public function authenticate(Request $request): array
    {
        $rawToken = $request->cookie($this->cookieName);
        if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            throw new ApiException('Sesión requerida.', 'SESSION_REQUIRED', 401);
        }

        $session = $this->repository->findActiveSession(hash('sha256', $rawToken));
        if (!$session) {
            throw new ApiException('La sesión no existe o fue cerrada.', 'SESSION_REQUIRED', 401);
        }

        if (strtotime((string)$session['expira_en']) <= time()) {
            $this->repository->deactivateSession((int)$session['id_sesion']);
            throw new ApiException('La sesión venció. Iniciá sesión nuevamente.', 'SESSION_EXPIRED', 401);
        }

        $idleMinutes = max(5, Env::int('SESSION_IDLE_MINUTES', 120));
        if (strtotime((string)$session['ultimo_uso_en']) < time() - ($idleMinutes * 60)) {
            $this->repository->deactivateSession((int)$session['id_sesion']);
            throw new ApiException('La sesión venció por inactividad.', 'SESSION_IDLE_EXPIRED', 401);
        }

        if (!(bool)$session['usuario_activo']) {
            $this->repository->deactivateUserSessions((int)$session['id_usuario']);
            throw new ApiException('El usuario se encuentra deshabilitado.', 'USER_DISABLED', 403);
        }

        $this->repository->touchSession((int)$session['id_sesion']);
        $session['permisos'] = $this->repository->permissionsForRole((int)$session['id_rol']);
        return $session;
    }

    public function assertCsrf(Request $request, array $session): void
    {
        $token = $request->header('X-CSRF-Token');
        if ($token === '' || !hash_equals((string)$session['csrf_token'], $token)) {
            throw new ApiException(
                'La validación CSRF falló. Actualizá la sesión e intentá nuevamente.',
                'CSRF_INVALID',
                403
            );
        }
    }

    public function assertPermission(array $session, ?string $permission): void
    {
        if ($permission === null || $permission === '') {
            return;
        }

        $permissions = $session['permisos'] ?? [];
        if (!is_array($permissions)) {
            $permissions = [];
        }

        if (
            !in_array('*', $permissions, true)
            && !in_array($permission, $permissions, true)
        ) {
            throw new ApiException(
                'Tu usuario no tiene permiso para realizar esta acción.',
                'FORBIDDEN',
                403
            );
        }
    }

    public function current(array $session): array
    {
        return $this->profile(
            $session,
            is_array($session['permisos'] ?? null) ? $session['permisos'] : [],
            (new DateTimeImmutable((string)$session['expira_en']))->format(DATE_ATOM),
            (string)$session['csrf_token']
        );
    }

    public function logout(array $session, string $correlationId): void
    {
        $this->repository->deactivateSession((int)$session['id_sesion']);
        $this->clearSessionCookie();
        $this->audit->record(
            (int)$session['id_usuario'],
            'auth',
            'logout',
            'sesion',
            (int)$session['id_sesion'],
            [],
            'success',
            $correlationId
        );
    }

    private function profile(array $user, array $permissions, string $expiresAt, string $csrfToken): array
    {
        return [
            'usuario' => [
                'id' => (int)$user['id_usuario'],
                'username' => (string)$user['usuario'],
                'nombre' => (string)$user['nombre'],
                'email' => (string)($user['email'] ?? ''),
                'rol' => (string)($user['rol_nombre'] ?? $user['rol_codigo']),
                'rol_codigo' => (string)$user['rol_codigo'],
                'permisos' => array_values($permissions),
            ],
            'expira_en' => $expiresAt,
            'csrf_token' => $csrfToken,
        ];
    }

    private function passwordAlgorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private function setSessionCookie(string $token): void
    {
        setcookie($this->cookieName, $token, [
            'path' => '/',
            'secure' => Env::bool('SESSION_COOKIE_SECURE', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    private function clearSessionCookie(): void
    {
        setcookie($this->cookieName, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => Env::bool('SESSION_COOKIE_SECURE', false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
