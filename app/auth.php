<?php

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    $id = $_SESSION['usuario_id'] ?? null;
    if (!$id) {
        return $user = null;
    }
    $user = db_one('SELECT * FROM usuarios WHERE id = ? AND activo = 1', [$id]);
    return $user ?: null;
}

function attempt_login(string $email, string $password): array
{
    $u = db_one('SELECT * FROM usuarios WHERE email = ?', [$email]);
    if (!$u || !$u['activo']) {
        password_verify($password, '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG');
        return [false, 'Credenciales incorrectas.'];
    }
    if ($u['bloqueado_hasta'] && strtotime($u['bloqueado_hasta']) > time()) {
        return [false, 'Usuario bloqueado temporalmente por intentos fallidos. Intente más tarde.'];
    }
    if (!password_verify($password, $u['password_hash'])) {
        $intentos = (int)$u['intentos_fallidos'] + 1;
        $bloqueo = $intentos >= 5 ? date('Y-m-d H:i:s', time() + 15 * 60) : null;
        db_update('usuarios', ['intentos_fallidos' => $bloqueo ? 0 : $intentos, 'bloqueado_hasta' => $bloqueo], 'id = :id', ['id' => $u['id']]);
        return [false, 'Credenciales incorrectas.'];
    }
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db_update('usuarios', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = :id', ['id' => $u['id']]);
    }
    db_update('usuarios', ['intentos_fallidos' => 0, 'bloqueado_hasta' => null, 'ultimo_acceso' => now()], 'id = :id', ['id' => $u['id']]);
    session_regenerate_id(true);
    $_SESSION['usuario_id'] = (int)$u['id'];
    $_SESSION['ultimo_movimiento'] = time();
    return [true, $u];
}

function logout(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function require_login(): array
{
    $u = current_user();
    $timeout = 60 * 60;
    if ($u && isset($_SESSION['ultimo_movimiento']) && time() - $_SESSION['ultimo_movimiento'] > $timeout) {
        logout();
        session_start();
        flash('warning', 'La sesión expiró por inactividad.');
        redirect('admin/login.php');
    }
    if (!$u) {
        redirect('admin/login.php');
    }
    $_SESSION['ultimo_movimiento'] = time();
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($u['debe_cambiar_password'] && !in_array($script, ['cambiar_password.php', 'logout.php'], true)) {
        redirect('admin/cambiar_password.php');
    }
    return $u;
}

function is_staff(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && in_array($u['rol'], ['superadmin', 'consultor'], true);
}

function require_staff(): array
{
    $u = require_login();
    if (!is_staff($u)) {
        http_response_code(403);
        render_header('Acceso denegado');
        echo '<div class="alert alert-danger">No tiene permiso para acceder a esta sección.</div>';
        render_footer();
        exit;
    }
    return $u;
}

function require_superadmin(): array
{
    $u = require_login();
    if ($u['rol'] !== 'superadmin') {
        http_response_code(403);
        render_header('Acceso denegado');
        echo '<div class="alert alert-danger">Sólo el Super Administrador puede acceder a esta sección.</div>';
        render_footer();
        exit;
    }
    return $u;
}

/** Usuarios de consulta sólo pueden ver su propia empresa. */
function can_view_empresa(array $u, int $empresaId): bool
{
    return is_staff($u) || ((int)$u['empresa_id'] === $empresaId);
}

function require_empresa_access(array $u, int $empresaId): void
{
    if (!can_view_empresa($u, $empresaId)) {
        http_response_code(403);
        exit('No tiene acceso a esta empresa.');
    }
}

function password_policy_error(string $p): ?string
{
    if (strlen($p) < 8) {
        return 'La contraseña debe tener al menos 8 caracteres.';
    }
    if (!preg_match('/[A-Z]/', $p) || !preg_match('/[a-z]/', $p) || !preg_match('/\d/', $p)) {
        return 'La contraseña debe incluir mayúsculas, minúsculas y números.';
    }
    return null;
}
