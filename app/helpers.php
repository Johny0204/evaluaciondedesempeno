<?php

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return rtrim(config('base_url'), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('#^https?://#', $path) ? $path : url($path)));
    exit;
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function take_flashes(): array
{
    $f = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): void
{
    $token = $_POST['_csrf'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        http_response_code(419);
        exit('La sesión expiró o la solicitud no es válida. Regrese e intente de nuevo.');
    }
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, $default = '')
{
    $v = $_POST[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function get(string $key, $default = '')
{
    $v = $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function int_get(string $key): int
{
    return (int)($_GET[$key] ?? 0);
}

function client_ip(): string
{
    return substr($_SERVER['REMOTE_ADDR'] ?? 'cli', 0, 45);
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function audit(string $accion, ?string $entidad = null, ?int $entidadId = null, $detalle = null, ?int $empleadoId = null): void
{
    try {
        db_insert('auditoria', [
            'usuario_id'  => current_user()['id'] ?? null,
            'empleado_id' => $empleadoId,
            'accion'      => $accion,
            'entidad'     => $entidad,
            'entidad_id'  => $entidadId,
            'detalle'     => is_string($detalle) || $detalle === null ? $detalle : json_encode($detalle, JSON_UNESCAPED_UNICODE),
            'ip'          => client_ip(),
        ]);
    } catch (Throwable $ex) {
        error_log('auditoria: ' . $ex->getMessage());
    }
}

/** Devuelve true si se excede el límite de intentos para la clave e IP dada. */
function rate_limited(string $clave, int $max, int $segundos): bool
{
    db_exec('DELETE FROM intentos_acceso WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
    $n = (int)db_val(
        'SELECT COUNT(*) FROM intentos_acceso WHERE clave = ? AND ip = ? AND created_at >= ?',
        [$clave, client_ip(), date('Y-m-d H:i:s', time() - $segundos)]
    );
    return $n >= $max;
}

function rate_hit(string $clave): void
{
    db_insert('intentos_acceso', ['clave' => $clave, 'ip' => client_ip()]);
}

function codigo_aleatorio(int $len = 8): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $s;
}

function normalizar_nit(string $nit): string
{
    return strtoupper(preg_replace('/[^0-9kK]/', '', $nit));
}

/** Valida el NIT de Guatemala (dígito verificador módulo 11, 'K' = 10). */
function validar_nit(string $nit): bool
{
    $nit = normalizar_nit($nit);
    if ($nit === 'CF') {
        return false;
    }
    if (!preg_match('/^(\d+)([0-9K])$/', $nit, $m)) {
        return false;
    }
    $num = $m[1];
    $dv = $m[2];
    $suma = 0;
    $len = strlen($num);
    for ($i = 0; $i < $len; $i++) {
        $suma += (int)$num[$i] * ($len + 1 - $i);
    }
    $calc = (11 - ($suma % 11)) % 11;
    $esperado = $calc === 10 ? 'K' : (string)$calc;
    return $dv === $esperado;
}

function valid_email(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

function fmt_fecha(?string $f, bool $hora = false): string
{
    if (!$f) {
        return '';
    }
    $t = strtotime($f);
    return $t ? date($hora ? 'd/m/Y H:i' : 'd/m/Y', $t) : '';
}

function fmt_num($n, int $dec = 1): string
{
    return $n === null || $n === '' ? '—' : number_format((float)$n, $dec, '.', ',');
}

function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    exit;
}

function etiqueta_relacion(string $r): string
{
    return [
        'auto' => 'Autoevaluación', 'jefe' => 'Jefe', 'par' => 'Par',
        'subordinado' => 'Subordinado', 'cliente' => 'Cliente interno',
    ][$r] ?? $r;
}

function etiqueta_estatus(string $s): string
{
    $map = [
        'borrador' => ['secondary', 'Borrador'], 'publicada' => ['success', 'Publicada'],
        'cerrada' => ['dark', 'Cerrada'], 'pendiente' => ['warning', 'Pendiente'],
        'en_progreso' => ['info', 'En progreso'], 'completada' => ['success', 'Completada'],
        'enviado' => ['success', 'Enviado'], 'error' => ['danger', 'Error'],
    ];
    [$c, $t] = $map[$s] ?? ['secondary', $s];
    return '<span class="badge text-bg-' . $c . '">' . e($t) . '</span>';
}
