<?php
declare(strict_types=1);

namespace App\Modules\Auth;

use App\Core\ApiException;
use App\Core\AuditLogger;
use App\Core\ClientContext;
use App\Core\Env;
use App\Core\Request;
use DateTimeImmutable;

final class AuthService
{
    private string $cookieName;
    private string $cookiePath;
    private string $cookieDomain;
    private string $cookieSameSite;
    private string $applicationCode;

    public function __construct(
        private readonly AuthRepository $repository,
        private readonly AuditLogger $audit
    ) {
        $this->applicationCode = trim((string)Env::get('AUTH_APPLICATION_CODE', 'backoffice'));
        $this->cookieName = trim((string)Env::get('SESSION_COOKIE_NAME', 'mutual_session'));
        $this->cookiePath = trim((string)Env::get('SESSION_COOKIE_PATH', '/')) ?: '/';
        $this->cookieDomain = trim((string)Env::get('SESSION_COOKIE_DOMAIN', ''));

        $sameSite = ucfirst(strtolower(trim((string)Env::get('SESSION_COOKIE_SAMESITE', 'Lax'))));
        $this->cookieSameSite = in_array($sameSite, ['Lax', 'Strict', 'None'], true)
            ? $sameSite
            : 'Lax';
    }

    public function login(array $input, string $correlationId): array
    {
        $credential = trim((string)($input['usuario'] ?? ''));
        $password = (string)($input['contrasena'] ?? '');

        if ($credential === '' || $password === '') {
            throw new ApiException(
                'Ingresá usuario y contraseña.',
                'VALIDATION_ERROR',
                422,
                ['usuario' => 'Obligatorio', 'contrasena' => 'Obligatoria']
            );
        }
        if (mb_strlen($credential) > 180 || strlen($password) > 255) {
            throw new ApiException('Usuario o contraseña incorrectos.', 'INVALID_CREDENTIALS', 401);
        }

        $application = $this->repository->findApplication($this->applicationCode);
        if (!$application) {
            throw new ApiException(
                'La aplicación no está registrada en el servicio de identidad.',
                'AUTH_APPLICATION_NOT_CONFIGURED',
                503
            );
        }
        if (!(bool)$application['activo']) {
            throw new ApiException(
                'El acceso a esta aplicación se encuentra deshabilitado.',
                'AUTH_APPLICATION_DISABLED',
                503
            );
        }

        $applicationId = (int)$application['id_aplicacion'];
        $lockMinutes = max(1, Env::int('LOGIN_LOCK_MINUTES', 15));
        $maxAttempts = max(1, Env::int('LOGIN_MAX_ATTEMPTS', 5));
        $ip = ClientContext::ip();
        $userAgent = ClientContext::userAgent();

        if ($this->repository->recentFailureCount($applicationId, $credential, $lockMinutes) >= $maxAttempts) {
            $this->repository->recordSecurityEvent(
                $applicationId,
                null,
                'login_bloqueado',
                'blocked',
                $ip,
                $userAgent,
                $correlationId,
                ['credencial' => $credential, 'minutos' => $lockMinutes]
            );
            throw new ApiException(
                "Demasiados intentos fallidos. Intentá nuevamente en {$lockMinutes} minutos.",
                'LOGIN_LOCKED',
                429
            );
        }
        if ($this->repository->recentIpFailureCount($applicationId, $ip, $lockMinutes) >= 20) {
            $this->repository->recordSecurityEvent(
                $applicationId,
                null,
                'login_limite_ip',
                'blocked',
                $ip,
                $userAgent,
                $correlationId
            );
            throw new ApiException(
                'Se detectaron demasiados intentos desde este origen. Intentá más tarde.',
                'IP_RATE_LIMITED',
                429
            );
        }

        $user = $this->repository->findUser($credential, $this->applicationCode);
        $valid = $user && password_verify($password, (string)$user['hash_contrasena']);

        if (!$valid) {
            $userId = $user ? (int)$user['id_usuario'] : null;
            $this->repository->recordAttempt(
                $applicationId,
                $userId,
                $credential,
                $ip,
                $userAgent,
                false,
                'INVALID_CREDENTIALS'
            );
            $this->repository->recordSecurityEvent(
                $applicationId,
                $userId,
                'login',
                'failed',
                $ip,
                $userAgent,
                $correlationId,
                ['credencial' => $credential]
            );
            $this->audit->record(
                $userId,
                'auth',
                'login',
                'usuario',
                $userId,
                ['usuario' => $credential, 'aplicacion' => $this->applicationCode],
                'failed',
                $correlationId
            );
            throw new ApiException('Usuario o contraseña incorrectos.', 'INVALID_CREDENTIALS', 401);
        }

        $userId = (int)$user['id_usuario'];
        if (!(bool)$user['aplicacion_activa']) {
            throw new ApiException(
                'El acceso a esta aplicación se encuentra deshabilitado.',
                'AUTH_APPLICATION_DISABLED',
                503
            );
        }
        if (!(bool)$user['activo']) {
            $this->recordRejectedLogin($applicationId, $userId, $credential, $ip, $userAgent, $correlationId, 'USER_DISABLED');
            throw new ApiException('El usuario se encuentra deshabilitado.', 'USER_DISABLED', 403);
        }
        if (!(bool)$user['acceso_activo']) {
            $this->recordRejectedLogin($applicationId, $userId, $credential, $ip, $userAgent, $correlationId, 'APPLICATION_ACCESS_DISABLED');
            throw new ApiException('No tenés acceso habilitado a esta aplicación.', 'APPLICATION_ACCESS_DISABLED', 403);
        }
        if (
            !empty($user['bloqueado_hasta'])
            && strtotime((string)$user['bloqueado_hasta']) > time()
        ) {
            $this->recordRejectedLogin($applicationId, $userId, $credential, $ip, $userAgent, $correlationId, 'USER_TEMPORARILY_BLOCKED');
            throw new ApiException(
                'El usuario se encuentra bloqueado temporalmente.',
                'USER_TEMPORARILY_BLOCKED',
                423
            );
        }

        $roles = $this->repository->rolesForAccess((int)$user['id_usuario_aplicacion']);
        if ($roles === []) {
            $this->recordRejectedLogin($applicationId, $userId, $credential, $ip, $userAgent, $correlationId, 'NO_ROLE_ASSIGNED');
            throw new ApiException(
                'El usuario no tiene un rol asignado para esta aplicación.',
                'NO_ROLE_ASSIGNED',
                403
            );
        }

        $securityVersion = max(1, (int)$user['version_seguridad']);
        if (password_needs_rehash((string)$user['hash_contrasena'], $this->passwordAlgorithm())) {
            $newHash = password_hash($password, $this->passwordAlgorithm());
            if (is_string($newHash)) {
                $securityVersion = $this->repository->updatePasswordHash($userId, $newHash);
                $user['version_seguridad'] = $securityVersion;
            }
        }

        $sessionToken = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(32));
        $hours = max(1, min(72, Env::int('SESSION_ABSOLUTE_HOURS', 12)));
        $expires = (new DateTimeImmutable())->modify("+{$hours} hours");

        $sessionId = $this->repository->createSession(
            (int)$user['id_usuario_aplicacion'],
            $securityVersion,
            hash('sha256', $sessionToken),
            $csrfToken,
            $expires->format('Y-m-d H:i:s'),
            $ip,
            $userAgent
        );

        $this->repository->recordAttempt(
            $applicationId,
            $userId,
            $credential,
            $ip,
            $userAgent,
            true,
            null
        );
        $this->repository->updateLastLogin($userId, (int)$user['id_usuario_aplicacion']);
        $this->setSessionCookie($sessionToken);
        $permissions = $this->repository->permissionsForAccess((int)$user['id_usuario_aplicacion']);

        $this->repository->recordSecurityEvent(
            $applicationId,
            $userId,
            'login',
            'success',
            $ip,
            $userAgent,
            $correlationId,
            ['id_sesion' => $sessionId]
        );
        $this->audit->record(
            $userId,
            'auth',
            'login',
            'sesion',
            $sessionId,
            ['aplicacion' => $this->applicationCode],
            'success',
            $correlationId
        );

        return $this->profile(
            $user,
            $roles,
            $permissions,
            $expires->format(DATE_ATOM),
            $csrfToken
        );
    }

    public function authenticate(Request $request): array
    {
        $rawToken = $request->cookie($this->cookieName);
        if ($rawToken === '' || !preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
            throw new ApiException('Sesión requerida.', 'SESSION_REQUIRED', 401);
        }

        $session = $this->repository->findActiveSession(
            hash('sha256', $rawToken),
            $this->applicationCode
        );
        if (!$session) {
            throw new ApiException('La sesión no existe o fue cerrada.', 'SESSION_REQUIRED', 401);
        }

        if (strtotime((string)$session['expira_en']) <= time()) {
            $this->repository->deactivateSession((int)$session['id_sesion'], 'ABSOLUTE_EXPIRATION');
            throw new ApiException('La sesión venció. Iniciá sesión nuevamente.', 'SESSION_EXPIRED', 401);
        }

        $idleMinutes = max(5, Env::int('SESSION_IDLE_MINUTES', 120));
        if (strtotime((string)$session['ultimo_uso_en']) < time() - ($idleMinutes * 60)) {
            $this->repository->deactivateSession((int)$session['id_sesion'], 'IDLE_EXPIRATION');
            throw new ApiException('La sesión venció por inactividad.', 'SESSION_IDLE_EXPIRED', 401);
        }

        if (!(bool)$session['aplicacion_activa']) {
            $this->repository->deactivateSession((int)$session['id_sesion'], 'APPLICATION_DISABLED');
            throw new ApiException('La aplicación se encuentra deshabilitada.', 'AUTH_APPLICATION_DISABLED', 503);
        }
        if (!(bool)$session['usuario_activo']) {
            $this->repository->deactivateUserSessions((int)$session['id_usuario'], 'USER_DISABLED');
            throw new ApiException('El usuario se encuentra deshabilitado.', 'USER_DISABLED', 403);
        }
        if (!(bool)$session['acceso_activo']) {
            $this->repository->deactivateSession((int)$session['id_sesion'], 'APPLICATION_ACCESS_DISABLED');
            throw new ApiException('Tu acceso a esta aplicación fue deshabilitado.', 'APPLICATION_ACCESS_DISABLED', 403);
        }
        if (
            !empty($session['bloqueado_hasta'])
            && strtotime((string)$session['bloqueado_hasta']) > time()
        ) {
            $this->repository->deactivateUserSessions((int)$session['id_usuario'], 'USER_TEMPORARILY_BLOCKED');
            throw new ApiException('El usuario se encuentra bloqueado temporalmente.', 'USER_TEMPORARILY_BLOCKED', 423);
        }

        $roles = $this->repository->rolesForAccess((int)$session['id_usuario_aplicacion']);
        if ($roles === []) {
            $this->repository->deactivateSession((int)$session['id_sesion'], 'NO_ROLE_ASSIGNED');
            throw new ApiException('El usuario ya no tiene un rol asignado.', 'NO_ROLE_ASSIGNED', 403);
        }

        $this->repository->touchSession((int)$session['id_sesion']);
        $session['roles'] = $roles;
        $session['permisos'] = $this->repository->permissionsForAccess(
            (int)$session['id_usuario_aplicacion']
        );
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
            is_array($session['roles'] ?? null) ? $session['roles'] : [],
            is_array($session['permisos'] ?? null) ? $session['permisos'] : [],
            (new DateTimeImmutable((string)$session['expira_en']))->format(DATE_ATOM),
            (string)$session['csrf_token']
        );
    }

    public function logout(array $session, string $correlationId): void
    {
        $sessionId = (int)$session['id_sesion'];
        $userId = (int)$session['id_usuario'];
        $applicationId = (int)$session['id_aplicacion'];

        $this->repository->deactivateSession($sessionId, 'LOGOUT');
        $this->clearSessionCookie();
        $this->repository->recordSecurityEvent(
            $applicationId,
            $userId,
            'logout',
            'success',
            ClientContext::ip(),
            ClientContext::userAgent(),
            $correlationId,
            ['id_sesion' => $sessionId]
        );
        $this->audit->record(
            $userId,
            'auth',
            'logout',
            'sesion',
            $sessionId,
            ['aplicacion' => $this->applicationCode],
            'success',
            $correlationId
        );
    }

    private function profile(
        array $user,
        array $roles,
        array $permissions,
        string $expiresAt,
        string $csrfToken
    ): array {
        $primaryRole = $roles[0] ?? null;
        $applicationCode = (string)($user['aplicacion_codigo'] ?? $this->applicationCode);
        $applicationName = (string)($user['aplicacion_nombre'] ?? $applicationCode);

        return [
            'usuario' => [
                'id' => (int)$user['id_usuario'],
                'username' => (string)$user['usuario'],
                'nombre' => (string)$user['nombre'],
                'email' => (string)($user['email'] ?? ''),
                'rol' => (string)($primaryRole['nombre'] ?? $primaryRole['codigo'] ?? ''),
                'rol_codigo' => (string)($primaryRole['codigo'] ?? ''),
                'roles' => array_values(array_map(
                    static fn (array $role): array => [
                        'codigo' => (string)$role['codigo'],
                        'nombre' => (string)$role['nombre'],
                    ],
                    $roles
                )),
                'permisos' => array_values($permissions),
                'tipo_perfil' => (string)($user['tipo_perfil'] ?? ''),
                'id_referencia' => isset($user['id_referencia_externa'])
                    ? (int)$user['id_referencia_externa']
                    : null,
            ],
            'aplicacion' => [
                'codigo' => $applicationCode,
                'nombre' => $applicationName,
            ],
            'expira_en' => $expiresAt,
            'csrf_token' => $csrfToken,
        ];
    }

    private function recordRejectedLogin(
        int $applicationId,
        int $userId,
        string $credential,
        string $ip,
        string $userAgent,
        string $correlationId,
        string $reason
    ): void {
        $this->repository->recordAttempt(
            $applicationId,
            $userId,
            $credential,
            $ip,
            $userAgent,
            false,
            $reason
        );
        $this->repository->recordSecurityEvent(
            $applicationId,
            $userId,
            'login',
            'rejected',
            $ip,
            $userAgent,
            $correlationId,
            ['motivo' => $reason]
        );
    }

    private function passwordAlgorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }

    private function setSessionCookie(string $token): void
    {
        $options = [
            'path' => $this->cookiePath,
            'secure' => Env::bool('SESSION_COOKIE_SECURE', false),
            'httponly' => true,
            'samesite' => $this->cookieSameSite,
        ];
        if ($this->cookieDomain !== '') {
            $options['domain'] = $this->cookieDomain;
        }
        setcookie($this->cookieName, $token, $options);
    }

    private function clearSessionCookie(): void
    {
        $options = [
            'expires' => time() - 3600,
            'path' => $this->cookiePath,
            'secure' => Env::bool('SESSION_COOKIE_SECURE', false),
            'httponly' => true,
            'samesite' => $this->cookieSameSite,
        ];
        if ($this->cookieDomain !== '') {
            $options['domain'] = $this->cookieDomain;
        }
        setcookie($this->cookieName, '', $options);
    }
}
