<?php
// API REST JSON. Autenticación: encabezado "Authorization: Bearer <token>".
// Rutas: api/index.php/<recurso>  o  api/index.php?r=<recurso>
require __DIR__ . '/../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$ruta = trim((string)($_GET['r'] ?? ($_SERVER['PATH_INFO'] ?? '')), '/');
$seg = $ruta === '' ? [] : explode('/', $ruta);
$body = json_decode(file_get_contents('php://input') ?: 'null', true) ?? $_POST;

function api_error(string $msg, int $status): void
{
    json_response(['error' => $msg], $status);
}

function api_usuario(): array
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if (!$h && function_exists('apache_request_headers')) {
        $hs = array_change_key_case(apache_request_headers(), CASE_LOWER);
        $h = $hs['authorization'] ?? '';
    }
    if (!preg_match('/^Bearer\s+(\S+)$/i', $h, $m)) {
        api_error('Token requerido', 401);
    }
    $t = db_one('SELECT t.id AS token_id, t.expira_at, u.* FROM api_tokens t JOIN usuarios u ON u.id = t.usuario_id WHERE t.token_hash = ? AND u.activo = 1', [hash('sha256', $m[1])]);
    if (!$t || ($t['expira_at'] && strtotime($t['expira_at']) < time())) {
        api_error('Token inválido o vencido', 401);
    }
    db_exec('UPDATE api_tokens SET ultimo_uso = ? WHERE id = ?', [now(), $t['token_id']]);
    return $t;
}

function api_paginar(string $sql, array $params): array
{
    $pag = max(1, (int)($_GET['pagina'] ?? 1));
    $por = min(500, max(1, (int)($_GET['por_pagina'] ?? 100)));
    $total = (int)db_val("SELECT COUNT(*) FROM ($sql) t", $params);
    $data = db_all($sql . " LIMIT $por OFFSET " . (($pag - 1) * $por), $params);
    return ['data' => $data, 'meta' => ['pagina' => $pag, 'por_pagina' => $por, 'total' => $total, 'paginas' => (int)ceil($total / $por)]];
}

try {
    // POST /auth/login  {email, password, nombre_token?}
    if ($seg === ['auth', 'login'] && $method === 'POST') {
        if (rate_limited('api_login', 10, 900)) {
            api_error('Demasiados intentos', 429);
        }
        rate_hit('api_login');
        $u = db_one('SELECT * FROM usuarios WHERE email = ? AND activo = 1', [strtolower((string)($body['email'] ?? ''))]);
        if (!$u || !password_verify((string)($body['password'] ?? ''), $u['password_hash'])) {
            api_error('Credenciales incorrectas', 401);
        }
        $token = bin2hex(random_bytes(32));
        db_insert('api_tokens', ['usuario_id' => $u['id'], 'nombre' => mb_substr((string)($body['nombre_token'] ?? 'API login'), 0, 100), 'token_hash' => hash('sha256', $token), 'expira_at' => date('Y-m-d H:i:s', time() + 86400 * 30)]);
        audit('api_login', 'usuario', (int)$u['id']);
        json_response(['token' => $token, 'tipo' => 'Bearer', 'expira_en_dias' => 30, 'usuario' => ['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'rol' => $u['rol']]], 201);
    }

    $u = api_usuario();
    $staff = is_staff($u);
    $filtroEmpresa = function (string $col) use ($u, $staff): array {
        return $staff ? ['1=1', []] : ["$col = ?", [(int)$u['empresa_id']]];
    };
    $empresaPermitida = function (int $empresaId) use ($u) {
        if (!can_view_empresa($u, $empresaId)) {
            api_error('Sin acceso a esta empresa', 403);
        }
    };
    $soloStaff = function () use ($staff) {
        if (!$staff) {
            api_error('Operación permitida sólo para la consultora', 403);
        }
    };

    switch (true) {
        case $seg === ['me']:
            json_response(['id' => (int)$u['id'], 'nombre' => $u['nombre'], 'email' => $u['email'], 'rol' => $u['rol'], 'empresa_id' => $u['empresa_id'] ? (int)$u['empresa_id'] : null]);

        case $seg === ['empresas'] && $method === 'GET':
            [$w, $p] = $filtroEmpresa('em.id');
            json_response(api_paginar("SELECT em.id, em.nombre, em.nombre_comercial, em.nit, te.nombre AS tipo_empresa, em.sector, em.activo FROM empresas em LEFT JOIN tipos_empresa te ON te.id = em.tipo_empresa_id WHERE $w ORDER BY em.nombre", $p));

        case $seg === ['empresas'] && $method === 'POST':
            $soloStaff();
            $nit = normalizar_nit((string)($body['nit'] ?? ''));
            if (empty($body['nombre']) || $nit === '') {
                api_error('nombre y nit son obligatorios', 422);
            }
            if (config('validar_nit') && !validar_nit($nit)) {
                api_error('NIT inválido', 422);
            }
            if (db_val('SELECT id FROM empresas WHERE nit = ?', [$nit])) {
                api_error('NIT duplicado', 409);
            }
            $id = db_insert('empresas', ['nombre' => $body['nombre'], 'nombre_comercial' => $body['nombre_comercial'] ?? null, 'nit' => $nit,
                'tipo_empresa_id' => $body['tipo_empresa_id'] ?? null, 'sector' => $body['sector'] ?? null, 'direccion' => $body['direccion'] ?? null]);
            foreach ((array)($body['contactos'] ?? []) as $c) {
                if (!empty($c['nombre']) && valid_email($c['email'] ?? '')) {
                    db_insert('empresa_contactos', ['empresa_id' => $id, 'nombre' => $c['nombre'], 'puesto' => $c['puesto'] ?? null, 'email' => strtolower($c['email']), 'telefono' => $c['telefono'] ?? null]);
                }
            }
            audit('api_crear_empresa', 'empresa', $id);
            json_response(['id' => $id], 201);

        case count($seg) === 2 && $seg[0] === 'empresas' && ctype_digit($seg[1]) && $method === 'GET':
            $empresaPermitida((int)$seg[1]);
            $em = db_one('SELECT * FROM empresas WHERE id = ?', [(int)$seg[1]]) ?? api_error('No encontrada', 404);
            $em['contactos'] = db_all('SELECT id, nombre, puesto, email, telefono FROM empresa_contactos WHERE empresa_id = ?', [(int)$seg[1]]);
            json_response($em);

        case count($seg) === 3 && $seg[0] === 'empresas' && $seg[2] === 'empleados' && $method === 'GET':
            $soloStaff();
            $p = [(int)$seg[1]];
            json_response(api_paginar("SELECT e.id, e.codigo, e.nombre, e.apellido, e.email, a.nombre AS area, e.puesto, e.nivel, j.email AS email_jefe, e.sexo, e.fecha_ingreso, e.activo
                FROM empleados e LEFT JOIN areas a ON a.id = e.area_id LEFT JOIN empleados j ON j.id = e.jefe_id WHERE e.empresa_id = ? ORDER BY e.apellido, e.nombre", $p));

        case count($seg) === 3 && $seg[0] === 'empresas' && $seg[2] === 'empleados' && $method === 'POST':
            // Alta o actualización masiva: {"empleados":[{nombre, apellido, email, codigo?, area?, puesto?, nivel?, email_jefe?}]}
            $soloStaff();
            $empresaId = (int)$seg[1];
            if (!db_val('SELECT id FROM empresas WHERE id = ?', [$empresaId])) {
                api_error('Empresa no encontrada', 404);
            }
            $lista = isset($body['empleados']) ? (array)$body['empleados'] : [$body];
            $res = ['creados' => 0, 'actualizados' => 0, 'errores' => []];
            db()->beginTransaction();
            foreach ($lista as $i => $e) {
                $email = strtolower(trim((string)($e['email'] ?? '')));
                if (empty($e['nombre']) || empty($e['apellido']) || !valid_email($email)) {
                    $res['errores'][] = ['indice' => $i, 'error' => 'nombre, apellido y email válidos son obligatorios'];
                    continue;
                }
                $areaId = null;
                if (!empty($e['area'])) {
                    $areaId = db_val('SELECT id FROM areas WHERE empresa_id = ? AND nombre = ?', [$empresaId, $e['area']]) ?: db_insert('areas', ['empresa_id' => $empresaId, 'nombre' => $e['area']]);
                }
                $data = ['codigo' => $e['codigo'] ?? null, 'nombre' => $e['nombre'], 'apellido' => $e['apellido'], 'area_id' => $areaId, 'puesto' => $e['puesto'] ?? null, 'nivel' => $e['nivel'] ?? null, 'activo' => 1];
                $id = db_val('SELECT id FROM empleados WHERE empresa_id = ? AND email = ?', [$empresaId, $email]);
                if ($id) {
                    db_update('empleados', $data, 'id = :id', ['id' => $id]);
                    $res['actualizados']++;
                } else {
                    db_insert('empleados', $data + ['empresa_id' => $empresaId, 'email' => $email]);
                    $res['creados']++;
                }
            }
            foreach ($lista as $e) {
                if (!empty($e['email_jefe']) && !empty($e['email'])) {
                    $jefe = db_val('SELECT id FROM empleados WHERE empresa_id = ? AND email = ?', [$empresaId, strtolower($e['email_jefe'])]);
                    db_exec('UPDATE empleados SET jefe_id = ? WHERE empresa_id = ? AND email = ? AND (? IS NULL OR id <> ?)', [$jefe ?: null, $empresaId, strtolower($e['email']), $jefe, $jefe]);
                }
            }
            db()->commit();
            audit('api_empleados', 'empresa', $empresaId, $res);
            json_response($res);

        case $seg === ['tipos-formulario']:
            json_response(['data' => db_all('SELECT id, nombre, descripcion, escala_id, activo FROM tipos_formulario ORDER BY nombre')]);

        case count($seg) === 2 && $seg[0] === 'tipos-formulario':
            json_response(formulario_construir((int)$seg[1]));

        case $seg === ['preguntas']:
            $soloStaff();
            json_response(api_paginar('SELECT p.id, p.texto, p.texto_auto, c.nombre AS categoria, p.tipo_respuesta, p.activo FROM preguntas p LEFT JOIN categorias c ON c.id = p.categoria_id ORDER BY p.id', []));

        case $seg === ['evaluaciones'] && $method === 'GET':
            [$w, $p] = $filtroEmpresa('ev.empresa_id');
            if (!empty($_GET['empresa_id'])) {
                $w .= ' AND ev.empresa_id = ?';
                $p[] = (int)$_GET['empresa_id'];
            }
            if (!$staff) {
                $w .= " AND ev.estatus <> 'borrador'";
            }
            json_response(api_paginar("SELECT ev.id, ev.empresa_id, em.nombre AS empresa, ev.nombre, ev.tipo, ev.periodo_inicio, ev.periodo_fin, ev.fecha_apertura, ev.fecha_cierre, ev.estatus, ev.liga_compensacion, ev.compensacion_tipos
                FROM evaluaciones ev JOIN empresas em ON em.id = ev.empresa_id WHERE $w ORDER BY ev.created_at DESC", $p));

        case count($seg) >= 2 && $seg[0] === 'evaluaciones' && ctype_digit($seg[1]):
            $ev = evaluacion_get((int)$seg[1]) ?? api_error('No encontrada', 404);
            $empresaPermitida((int)$ev['empresa_id']);
            if (!$staff && $ev['estatus'] === 'borrador') {
                api_error('No encontrada', 404);
            }
            $sub = $seg[2] ?? '';
            if ($sub === '') {
                unset($ev['snapshot_formulario']);
                $ev['enlace'] = enlace_evaluacion($ev);
                json_response($ev);
            }
            if ($sub === 'avance') {
                json_response(evaluacion_avance((int)$ev['id']));
            }
            if ($sub === 'resultados') {
                $st = evaluacion_estadisticas($ev, !empty($_GET['area_id']) ? (int)$_GET['area_id'] : null);
                unset($st['form']);
                if (!$staff && !(int)$u['puede_ver_individual']) {
                    unset($st['finales']);
                }
                json_response($st);
            }
            if ($sub === 'participantes') {
                if (!$staff && !(int)$u['puede_ver_individual']) {
                    api_error('Sin permiso para datos individuales', 403);
                }
                json_response(api_paginar("SELECT e.id, e.nombre, e.apellido, e.email, a.nombre AS area, e.puesto, p.calificacion_final,
                    (SELECT COUNT(*) FROM asignaciones x WHERE x.evaluacion_id = p.evaluacion_id AND x.evaluador_id = e.id AND x.estatus <> 'completada') = 0 AS evaluacion_completada
                    FROM participantes p JOIN empleados e ON e.id = p.empleado_id LEFT JOIN areas a ON a.id = e.area_id WHERE p.evaluacion_id = ? ORDER BY e.apellido", [(int)$ev['id']]));
            }
            api_error('Ruta no encontrada', 404);

        default:
            api_error('Ruta no encontrada', 404);
    }
} catch (Throwable $ex) {
    error_log('API: ' . $ex->getMessage());
    api_error(config('modo_desarrollo') ? $ex->getMessage() : 'Error interno', 500);
}
