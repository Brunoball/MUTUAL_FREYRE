<?php
declare(strict_types=1);

namespace App\Modules\Configuracion;

use App\Core\ApiException;
use App\Core\AuditDiff;
use App\Core\AuditLogger;
use PDOException;

final class ConfiguracionService
{
    public function __construct(
        private readonly ConfiguracionRepository $repository,
        private readonly AuditLogger $audit
    ) {}

    public function overview(array $session): array
    {
        $application = $this->application($session);
        $users = $this->repository->users((int)$application['id_aplicacion']);
        $activeUsers = count(array_filter($users, static fn (array $user): bool => $user['activo']));

        return [
            'aplicacion' => $application,
            'secciones' => [[
                'codigo' => 'usuarios',
                'titulo' => 'Usuarios del sistema',
                'descripcion' => 'Creá usuarios y asigná roles para controlar el acceso al sistema interno.',
                'estado' => 'Administrable',
                'detalle' => sprintf('%d activos de %d registrados', $activeUsers, count($users)),
                'ruta' => '/configuracion/usuarios',
            ]],
        ];
    }

    public function users(array $session): array
    {
        $application = $this->application($session);
        return [
            'aplicacion' => $application,
            'usuario_actual_id' => (int)$session['id_usuario'],
            'usuarios' => $this->repository->users((int)$application['id_aplicacion']),
            'roles' => $this->repository->roles((int)$application['id_aplicacion']),
        ];
    }

    public function createUser(array $input, array $session, string $correlationId): array
    {
        $application = $this->application($session);
        $data = $this->validate($input, true);
        $applicationId = (int)$application['id_aplicacion'];
        $role = $this->requiredRole((int)$data['id_rol'], $applicationId);

        $this->assertUniqueCredentials($data['usuario'], $data['email'], null);
        $hash = password_hash((string)$data['contrasena'], $this->passwordAlgorithm());
        if (!is_string($hash)) {
            throw new ApiException('No se pudo proteger la contraseña.', 'PASSWORD_HASH_FAILED', 500);
        }

        $after = [
            'usuario' => $data['usuario'],
            'nombre' => $data['nombre'],
            'email' => $data['email'],
            'activo' => true,
            'rol' => [
                'id' => (int)$role['id'],
                'codigo' => (string)$role['codigo'],
                'nombre' => (string)$role['nombre'],
            ],
            'aplicacion' => $application['codigo'],
        ];

        try {
            $accessId = $this->repository->createUser(
                $applicationId,
                (int)$role['id'],
                $data['usuario'],
                $data['nombre'],
                $data['email'],
                $hash,
                function ($identityDb, int $createdAccessId, int $createdUserId) use (
                    $session,
                    $correlationId,
                    $after
                ): void {
                    $snapshot = $after + [
                        'id_acceso' => $createdAccessId,
                        'id_usuario' => $createdUserId,
                    ];
                    $this->audit->recordForSession(
                        $session,
                        'configuracion',
                        'crear_usuario',
                        'usuario_aplicacion',
                        $createdAccessId,
                        "Creó el usuario {$snapshot['usuario']} con rol {$snapshot['rol']['nombre']}.",
                        AuditDiff::between([], $snapshot),
                        [
                            'aplicacion' => $snapshot['aplicacion'],
                            'snapshot_final' => $snapshot,
                            'contrasena' => '[DEFINIDA - VALOR NO REGISTRADO]',
                        ],
                        'success',
                        $correlationId
                    );
                }
            );
        } catch (PDOException $error) {
            $this->rethrowDuplicate($error);
            throw $error;
        }

        return [
            'mensaje' => 'Usuario creado correctamente.',
            'usuario' => $this->repository->access($accessId, $applicationId),
        ];
    }

    public function updateUser(
        int $accessId,
        array $input,
        array $session,
        string $correlationId
    ): array {
        $application = $this->application($session);
        $applicationId = (int)$application['id_aplicacion'];
        $target = $this->requiredAccess($accessId, $applicationId);
        $data = $this->validate($input, false);
        $role = $this->requiredRole((int)$data['id_rol'], $applicationId);
        $isSelf = (int)$target['id_usuario'] === (int)$session['id_usuario'];
        $currentRoleId = (int)($target['rol']['id'] ?? 0);
        $roleChanged = $currentRoleId !== (int)$role['id'];

        if ($isSelf && $roleChanged) {
            throw new ApiException(
                'No podés cambiar tu propio rol mientras usás el sistema.',
                'SELF_ROLE_CHANGE_NOT_ALLOWED',
                409
            );
        }
        if (
            $roleChanged
            && (bool)($target['rol']['es_super_admin'] ?? false)
            && !(bool)$role['es_super_admin']
            && $target['activo']
            && $this->repository->activeSuperAdminCount($applicationId) <= 1
        ) {
            throw new ApiException(
                'Debe quedar al menos un administrador activo en el sistema.',
                'LAST_ADMIN_REQUIRED',
                409
            );
        }

        $this->assertUniqueCredentials($data['usuario'], $data['email'], (int)$target['id_usuario']);
        $passwordHash = null;
        if ($data['contrasena'] !== '') {
            $passwordHash = password_hash((string)$data['contrasena'], $this->passwordAlgorithm());
            if (!is_string($passwordHash)) {
                throw new ApiException('No se pudo proteger la contraseña.', 'PASSWORD_HASH_FAILED', 500);
            }
        }

        $before = AuditDiff::snapshot($target);
        $after = $target;
        $after['usuario'] = $data['usuario'];
        $after['nombre'] = $data['nombre'];
        $after['email'] = $data['email'];
        $after['rol'] = [
            'id' => (int)$role['id'],
            'codigo' => (string)$role['codigo'],
            'nombre' => (string)$role['nombre'],
            'es_super_admin' => (bool)$role['es_super_admin'],
        ];
        $changes = AuditDiff::between($before, $after);
        if ($passwordHash !== null) {
            $changes['cantidad'] = (int)($changes['cantidad'] ?? 0) + 1;
            $changes['campos'][] = [
                'campo' => 'seguridad.contrasena',
                'antes' => '[NO EXPUESTA]',
                'despues' => '[MODIFICADA]',
            ];
        }

        try {
            $this->repository->updateUser(
                $accessId,
                (int)$target['id_usuario'],
                (int)$role['id'],
                $data['usuario'],
                $data['nombre'],
                $data['email'],
                $passwordHash,
                $roleChanged,
                function () use (
                    $session,
                    $correlationId,
                    $accessId,
                    $data,
                    $role,
                    $application,
                    $before,
                    $after,
                    $changes,
                    $passwordHash
                ): void {
                    $this->audit->recordForSession(
                        $session,
                        'configuracion',
                        'editar_usuario',
                        'usuario_aplicacion',
                        $accessId,
                        "Editó el usuario {$data['usuario']}; se registraron {$changes['cantidad']} cambios.",
                        $changes,
                        [
                            'aplicacion' => $application['codigo'],
                            'rol_final' => $role['codigo'],
                            'cambio_contrasena' => $passwordHash !== null,
                            'snapshot_anterior' => $before,
                            'snapshot_final' => $after,
                        ],
                        'success',
                        $correlationId
                    );
                }
            );
        } catch (PDOException $error) {
            $this->rethrowDuplicate($error);
            throw $error;
        }

        return [
            'mensaje' => $passwordHash !== null && $isSelf
                ? 'Usuario actualizado. La contraseña cambió y deberás iniciar sesión nuevamente.'
                : 'Usuario actualizado correctamente.',
            'requiere_nuevo_login' => $passwordHash !== null && $isSelf,
            'usuario' => $this->repository->access($accessId, $applicationId),
        ];
    }

    public function changeStatus(
        int $accessId,
        bool $active,
        array $session,
        string $correlationId
    ): array {
        $application = $this->application($session);
        $applicationId = (int)$application['id_aplicacion'];
        $target = $this->requiredAccess($accessId, $applicationId);

        if ((int)$target['id_usuario'] === (int)$session['id_usuario']) {
            throw new ApiException('No podés desactivar tu propio acceso.', 'SELF_DISABLE_NOT_ALLOWED', 409);
        }
        if (
            !$active
            && (bool)($target['rol']['es_super_admin'] ?? false)
            && $target['activo']
            && $this->repository->activeSuperAdminCount($applicationId) <= 1
        ) {
            throw new ApiException('Debe quedar al menos un administrador activo en el sistema.', 'LAST_ADMIN_REQUIRED', 409);
        }

        $after = $target;
        $after['acceso_activo'] = $active;
        $after['activo'] = $active && (bool)$target['usuario_activo'] && $target['rol'] !== null;
        $changes = AuditDiff::between($target, $after);

        $this->repository->setAccessStatus(
            $accessId,
            $active,
            function () use (
                $session,
                $correlationId,
                $accessId,
                $active,
                $target,
                $after,
                $changes,
                $application
            ): void {
                $this->audit->recordForSession(
                    $session,
                    'configuracion',
                    $active ? 'activar_usuario' : 'desactivar_usuario',
                    'usuario_aplicacion',
                    $accessId,
                    ($active ? 'Activó' : 'Desactivó') . " el acceso de {$target['usuario']} al sistema.",
                    $changes,
                    [
                        'aplicacion' => $application['codigo'],
                        'sesiones_revocadas' => !$active,
                        'snapshot_anterior' => $target,
                        'snapshot_final' => $after,
                    ],
                    'success',
                    $correlationId
                );
            }
        );

        return [
            'mensaje' => $active
                ? 'Acceso activado correctamente.'
                : 'Acceso desactivado y sesiones cerradas.',
        ];
    }

    public function deleteUser(int $accessId, array $session, string $correlationId): array
    {
        $application = $this->application($session);
        $applicationId = (int)$application['id_aplicacion'];
        $target = $this->requiredAccess($accessId, $applicationId);

        if ((int)$target['id_usuario'] === (int)$session['id_usuario']) {
            throw new ApiException('No podés eliminar tu propio acceso.', 'SELF_DELETE_NOT_ALLOWED', 409);
        }
        if (
            (bool)($target['rol']['es_super_admin'] ?? false)
            && $target['activo']
            && $this->repository->activeSuperAdminCount($applicationId) <= 1
        ) {
            throw new ApiException('Debe quedar al menos un administrador activo en el sistema.', 'LAST_ADMIN_REQUIRED', 409);
        }

        $this->repository->deleteAccess(
            $accessId,
            function () use ($session, $correlationId, $accessId, $target, $application): void {
                $this->audit->recordForSession(
                    $session,
                    'configuracion',
                    'eliminar_acceso_usuario',
                    'usuario_aplicacion',
                    $accessId,
                    "Eliminó el acceso de {$target['usuario']} al sistema {$application['nombre']}.",
                    AuditDiff::between($target, []),
                    [
                        'id_usuario_identidad' => $target['id_usuario'],
                        'aplicacion' => $application['codigo'],
                        'snapshot_eliminado' => $target,
                        'sesiones_revocadas' => true,
                    ],
                    'success',
                    $correlationId
                );
            }
        );

        return ['mensaje' => 'El usuario fue removido de este sistema.'];
    }

    private function application(array $session): array
    {
        $applicationId = (int)($session['id_aplicacion'] ?? 0);
        if ($applicationId <= 0) {
            throw new ApiException('La sesión no identifica una aplicación.', 'INVALID_APPLICATION', 500);
        }
        $application = $this->repository->application($applicationId);
        if (!$application) {
            throw new ApiException('La aplicación no existe.', 'APPLICATION_NOT_FOUND', 404);
        }
        return [
            'id_aplicacion' => (int)$application['id_aplicacion'],
            'codigo' => (string)$application['codigo'],
            'nombre' => (string)$application['nombre'],
            'descripcion' => (string)($application['descripcion'] ?? ''),
            'activo' => (bool)$application['activo'],
        ];
    }

    private function requiredAccess(int $accessId, int $applicationId): array
    {
        $target = $this->repository->access($accessId, $applicationId);
        if (!$target) {
            throw new ApiException(
                'El usuario no pertenece a este sistema.',
                'USER_ACCESS_NOT_FOUND',
                404
            );
        }
        return $target;
    }

    private function requiredRole(int $roleId, int $applicationId): array
    {
        $role = $this->repository->role($roleId, $applicationId);
        if (!$role) {
            throw new ApiException(
                'Seleccioná un rol válido para este sistema.',
                'INVALID_ROLE',
                422,
                ['id_rol' => 'Rol inválido']
            );
        }
        return $role;
    }

    private function validate(array $input, bool $creating): array
    {
        $username = trim((string)($input['usuario'] ?? ''));
        $name = trim((string)($input['nombre'] ?? ''));
        $email = mb_strtolower(trim((string)($input['email'] ?? '')), 'UTF-8');
        $password = (string)($input['contrasena'] ?? '');
        $roleId = filter_var($input['id_rol'] ?? null, FILTER_VALIDATE_INT);
        $errors = [];

        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            $errors['usuario'] = 'Usá entre 3 y 100 caracteres: letras, números, punto, guion o guion bajo.';
        }
        if (mb_strlen($name) < 3 || mb_strlen($name) > 160) {
            $errors['nombre'] = 'Ingresá un nombre de entre 3 y 160 caracteres.';
        }
        if ($email !== '' && (mb_strlen($email) > 180 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            $errors['email'] = 'Ingresá un correo electrónico válido.';
        }
        if (!$roleId || $roleId < 1) {
            $errors['id_rol'] = 'Seleccioná un rol.';
        }
        if ($creating && $password === '') {
            $errors['contrasena'] = 'La contraseña es obligatoria.';
        }
        if ($password !== '' && (strlen($password) < 12 || strlen($password) > 255)) {
            $errors['contrasena'] = 'La contraseña debe tener entre 12 y 255 caracteres.';
        }

        if ($errors !== []) {
            throw new ApiException(
                'Revisá los datos del usuario.',
                'VALIDATION_ERROR',
                422,
                $errors
            );
        }

        return [
            'usuario' => $username,
            'nombre' => $name,
            'email' => $email === '' ? null : $email,
            'contrasena' => $password,
            'id_rol' => (int)$roleId,
        ];
    }

    private function assertUniqueCredentials(string $username, ?string $email, ?int $excludeUserId): void
    {
        $errors = [];
        if ($this->repository->usernameExists($username, $excludeUserId)) {
            $errors['usuario'] = 'Ese nombre de usuario ya está registrado.';
        }
        if ($email !== null && $this->repository->emailExists($email, $excludeUserId)) {
            $errors['email'] = 'Ese correo electrónico ya está registrado.';
        }
        if ($errors !== []) {
            throw new ApiException('El usuario o el correo ya existen.', 'DUPLICATE_USER', 409, $errors);
        }
    }

    private function rethrowDuplicate(PDOException $error): void
    {
        if ((string)$error->getCode() !== '23000') return;
        throw new ApiException(
            'El usuario o el correo electrónico ya están registrados.',
            'DUPLICATE_USER',
            409
        );
    }

    private function passwordAlgorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
