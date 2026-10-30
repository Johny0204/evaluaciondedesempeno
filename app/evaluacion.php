<?php
// Lógica de negocio de evaluaciones: formularios, asignaciones, OTP y cálculos.

const RELACIONES = ['auto', 'jefe', 'par', 'subordinado', 'cliente'];

/** Construye la estructura completa de un tipo de formulario (para vista previa o snapshot). */
function formulario_construir(int $tipoFormularioId): array
{
    $tf = db_one('SELECT * FROM tipos_formulario WHERE id = ?', [$tipoFormularioId]);
    if (!$tf) {
        throw new RuntimeException('Tipo de formulario no encontrado');
    }
    $valores = db_all('SELECT valor, etiqueta, descripcion FROM escala_valores WHERE escala_id = ? ORDER BY orden, valor', [$tf['escala_id']]);
    $nums = array_map(fn($v) => (float)$v['valor'], $valores);
    $escala = [
        'id'      => (int)$tf['escala_id'],
        'nombre'  => db_val('SELECT nombre FROM escalas WHERE id = ?', [$tf['escala_id']]),
        'valores' => array_map(fn($v) => ['valor' => (float)$v['valor'], 'etiqueta' => $v['etiqueta'], 'descripcion' => $v['descripcion']], $valores),
        'min'     => $nums ? min($nums) : 0,
        'max'     => $nums ? max($nums) : 0,
    ];
    $secciones = db_all('SELECT id, nombre, peso, orden FROM secciones WHERE tipo_formulario_id = ? ORDER BY orden, id', [$tipoFormularioId]);
    $preguntas = db_all(
        'SELECT p.id, p.texto, p.texto_auto, p.categoria_id, c.nombre AS categoria, p.tipo_respuesta, p.opciones, p.ayuda,
                fp.seccion_id, fp.orden, fp.peso, fp.obligatoria, fp.aplica_a
           FROM formulario_pregunta fp
           JOIN preguntas p ON p.id = fp.pregunta_id AND p.activo = 1
           LEFT JOIN categorias c ON c.id = p.categoria_id
          WHERE fp.tipo_formulario_id = ?
          ORDER BY fp.orden, p.id',
        [$tipoFormularioId]
    );
    $out = [];
    $sinSeccion = ['id' => 0, 'nombre' => 'General', 'peso' => 100.0, 'orden' => 999, 'preguntas' => []];
    $idx = [];
    foreach ($secciones as $s) {
        $idx[$s['id']] = count($out);
        $out[] = ['id' => (int)$s['id'], 'nombre' => $s['nombre'], 'peso' => (float)$s['peso'], 'orden' => (int)$s['orden'], 'preguntas' => []];
    }
    foreach ($preguntas as $p) {
        $item = [
            'id'           => (int)$p['id'],
            'texto'        => $p['texto'],
            'texto_auto'   => $p['texto_auto'] ?: $p['texto'],
            'categoria_id' => $p['categoria_id'] ? (int)$p['categoria_id'] : null,
            'categoria'    => $p['categoria'] ?: 'Sin categoría',
            'tipo'         => $p['tipo_respuesta'],
            'opciones'     => $p['opciones'] ? array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $p['opciones'])), 'strlen')) : [],
            'ayuda'        => $p['ayuda'],
            'peso'         => (float)$p['peso'],
            'obligatoria'  => (bool)$p['obligatoria'],
            'aplica_a'     => array_values(array_filter(explode(',', $p['aplica_a']))),
        ];
        if ($p['seccion_id'] && isset($idx[$p['seccion_id']])) {
            $out[$idx[$p['seccion_id']]]['preguntas'][] = $item;
        } else {
            $sinSeccion['preguntas'][] = $item;
        }
    }
    if ($sinSeccion['preguntas']) {
        $out[] = $sinSeccion;
    }
    return [
        'tipo_formulario_id' => $tipoFormularioId,
        'nombre'             => $tf['nombre'],
        'escala'             => $escala,
        'secciones'          => array_values(array_filter($out, fn($s) => count($s['preguntas']) > 0)),
        'generado'           => now(),
    ];
}

function evaluacion_formulario(array $ev): array
{
    if (!empty($ev['snapshot_formulario'])) {
        return json_decode($ev['snapshot_formulario'], true);
    }
    return formulario_construir((int)$ev['tipo_formulario_id']);
}

/** Preguntas del formulario aplicables a una relación (auto, jefe, ...), agrupadas por sección. */
function formulario_para_relacion(array $form, string $relacion): array
{
    $secciones = [];
    foreach ($form['secciones'] as $s) {
        $ps = array_values(array_filter($s['preguntas'], fn($p) => in_array($relacion, $p['aplica_a'], true)));
        if ($ps) {
            $s['preguntas'] = $ps;
            $secciones[] = $s;
        }
    }
    return $secciones;
}

function evaluacion_get(int $id): ?array
{
    return db_one(
        'SELECT ev.*, em.nombre AS empresa_nombre, tf.nombre AS tipo_formulario_nombre
           FROM evaluaciones ev
           JOIN empresas em ON em.id = ev.empresa_id
           JOIN tipos_formulario tf ON tf.id = ev.tipo_formulario_id
          WHERE ev.id = ?',
        [$id]
    );
}

function evaluacion_abierta(array $ev): bool
{
    $t = time();
    return $ev['estatus'] === 'publicada' && strtotime($ev['fecha_apertura']) <= $t && strtotime($ev['fecha_cierre']) >= $t;
}

function relaciones_por_tipo(string $tipo): array
{
    return [
        '90'  => ['jefe'],
        '180' => ['jefe', 'auto'],
        '270' => ['jefe', 'auto', 'subordinado'],
        '360' => ['jefe', 'auto', 'subordinado', 'par'],
    ][$tipo] ?? ['jefe'];
}

/** Genera (sin duplicar) las asignaciones evaluador → evaluado según el tipo de evaluación. */
function generar_asignaciones(int $evaluacionId): int
{
    $ev = evaluacion_get($evaluacionId);
    $rels = relaciones_por_tipo($ev['tipo']);
    $part = db_all(
        'SELECT e.id, e.jefe_id FROM participantes p JOIN empleados e ON e.id = p.empleado_id
          WHERE p.evaluacion_id = ? AND e.activo = 1 ORDER BY e.id',
        [$evaluacionId]
    );
    $activos = [];
    foreach (db_all('SELECT id, jefe_id FROM empleados WHERE empresa_id = ? AND activo = 1', [$ev['empresa_id']]) as $r) {
        $activos[(int)$r['id']] = $r['jefe_id'] ? (int)$r['jefe_id'] : null;
    }
    $porJefe = [];
    foreach ($part as $p) {
        if ($p['jefe_id']) {
            $porJefe[(int)$p['jefe_id']][] = (int)$p['id'];
        }
    }
    $subordinados = [];
    foreach ($activos as $id => $jefe) {
        if ($jefe) {
            $subordinados[$jefe][] = $id;
        }
    }
    $nuevas = [];
    foreach ($part as $p) {
        $id = (int)$p['id'];
        $jefe = $p['jefe_id'] ? (int)$p['jefe_id'] : null;
        if (in_array('jefe', $rels, true) && $jefe && array_key_exists($jefe, $activos)) {
            $nuevas[] = [$jefe, $id, 'jefe'];
        }
        if (in_array('auto', $rels, true)) {
            $nuevas[] = [$id, $id, 'auto'];
        }
        if (in_array('subordinado', $rels, true) && !empty($subordinados[$id])) {
            foreach ($subordinados[$id] as $s) {
                $nuevas[] = [$s, $id, 'subordinado'];
            }
        }
        if (in_array('par', $rels, true) && $jefe && !empty($porJefe[$jefe]) && $ev['max_pares'] > 0) {
            $grupo = $porJefe[$jefe];
            $n = count($grupo);
            $pos = array_search($id, $grupo, true);
            for ($k = 1; $k <= min((int)$ev['max_pares'], $n - 1); $k++) {
                $nuevas[] = [$grupo[($pos + $k) % $n], $id, 'par'];
            }
        }
    }
    $stmt = db()->prepare('INSERT IGNORE INTO asignaciones (evaluacion_id, evaluador_id, evaluado_id, relacion) VALUES (?, ?, ?, ?)');
    $count = 0;
    db()->beginTransaction();
    foreach ($nuevas as [$evaluador, $evaluado, $rel]) {
        $stmt->execute([$evaluacionId, $evaluador, $evaluado, $rel]);
        $count += $stmt->rowCount();
    }
    db()->commit();
    return $count;
}

function publicar_evaluacion(int $evaluacionId): void
{
    $ev = evaluacion_get($evaluacionId);
    if ((int)db_val('SELECT COUNT(*) FROM asignaciones WHERE evaluacion_id = ?', [$evaluacionId]) === 0) {
        throw new RuntimeException('La evaluación no tiene asignaciones. Agregue participantes y genere las asignaciones.');
    }
    $form = formulario_construir((int)$ev['tipo_formulario_id']);
    if (!$form['secciones']) {
        throw new RuntimeException('El tipo de formulario no tiene preguntas.');
    }
    db_update('evaluaciones', [
        'snapshot_formulario' => json_encode($form, JSON_UNESCAPED_UNICODE),
        'estatus'             => 'publicada',
        'publicada_at'        => now(),
    ], 'id = :id', ['id' => $evaluacionId]);
}

function enlace_evaluacion(array $ev): string
{
    return url('e/index.php?c=' . urlencode($ev['codigo_publico']));
}

/** Encola correos de invitación o recordatorio a evaluadores con asignaciones pendientes. */
function enviar_invitaciones(int $evaluacionId, string $tipo = 'invitacion'): int
{
    $ev = evaluacion_get($evaluacionId);
    $evaluadores = db_all(
        "SELECT e.id, e.nombre, e.apellido, e.email, COUNT(*) AS pendientes
           FROM asignaciones a JOIN empleados e ON e.id = a.evaluador_id
          WHERE a.evaluacion_id = ? AND a.estatus <> 'completada' AND e.activo = 1
          GROUP BY e.id, e.nombre, e.apellido, e.email",
        [$evaluacionId]
    );
    $link = enlace_evaluacion($ev);
    $n = 0;
    foreach ($evaluadores as $p) {
        $titulo = $tipo === 'recordatorio' ? 'Recordatorio: evaluación de desempeño pendiente' : 'Invitación a la evaluación de desempeño';
        $html = plantilla_correo($titulo,
            '<p>Hola ' . e($p['nombre']) . ',</p>'
            . '<p>' . e($ev['empresa_nombre']) . ' le invita a completar la <strong>' . e($ev['nombre']) . '</strong>. '
            . 'Tiene <strong>' . (int)$p['pendientes'] . '</strong> evaluación(es) pendiente(s).</p>'
            . '<p>Fecha límite: <strong>' . e(fmt_fecha($ev['fecha_cierre'], true)) . '</strong></p>'
            . '<p style="text-align:center;margin:24px 0"><a href="' . e($link) . '" style="background:#1f4e79;color:#fff;padding:12px 24px;border-radius:6px;text-decoration:none">Ir a la evaluación</a></p>'
            . '<p>Ingrese con este correo (' . e($p['email']) . '). Recibirá un código de verificación en su buzón.</p>'
            . '<p style="font-size:12px;color:#666">Si el botón no funciona, copie este enlace: ' . e($link) . '</p>');
        correo_encolar($p['email'], $titulo . ' - ' . $ev['empresa_nombre'], $html, $tipo, $evaluacionId, (int)$p['id']);
        $n++;
    }
    return $n;
}

// ---------------------------------------------------------------------
// OTP
// ---------------------------------------------------------------------

/** Genera y envía un código OTP. Devuelve [ok, mensaje, codigo(dev)] */
function otp_enviar(array $ev, array $empleado): array
{
    $cfg = config('otp');
    $ultimo = db_one('SELECT created_at FROM codigos_verificacion WHERE evaluacion_id = ? AND empleado_id = ? ORDER BY id DESC LIMIT 1', [$ev['id'], $empleado['id']]);
    if ($ultimo && time() - strtotime($ultimo['created_at']) < (int)$cfg['segundos_reenvio']) {
        $espera = (int)$cfg['segundos_reenvio'] - (time() - strtotime($ultimo['created_at']));
        return [false, "Espere $espera segundos antes de solicitar un nuevo código.", null];
    }
    $enHora = (int)db_val('SELECT COUNT(*) FROM codigos_verificacion WHERE evaluacion_id = ? AND empleado_id = ? AND created_at >= ?', [$ev['id'], $empleado['id'], date('Y-m-d H:i:s', time() - 3600)]);
    if ($enHora >= (int)$cfg['max_envios_por_hora']) {
        return [false, 'Se alcanzó el máximo de códigos por hora. Intente más tarde.', null];
    }
    db_exec('UPDATE codigos_verificacion SET usado = 1 WHERE evaluacion_id = ? AND empleado_id = ? AND usado = 0', [$ev['id'], $empleado['id']]);
    $codigo = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    db_insert('codigos_verificacion', [
        'evaluacion_id' => $ev['id'],
        'empleado_id'   => $empleado['id'],
        'codigo_hash'   => password_hash($codigo, PASSWORD_DEFAULT),
        'expira_at'     => date('Y-m-d H:i:s', time() + 60 * (int)$cfg['minutos_validez']),
        'ip'            => client_ip(),
    ]);
    $html = plantilla_correo('Su código de verificación',
        '<p>Hola ' . e($empleado['nombre']) . ',</p><p>Su código para ingresar a la evaluación <strong>' . e($ev['nombre']) . '</strong> es:</p>'
        . '<p style="font-size:32px;letter-spacing:8px;font-weight:bold;text-align:center;color:#1f4e79">' . $codigo . '</p>'
        . '<p>El código vence en ' . (int)$cfg['minutos_validez'] . ' minutos. Si usted no lo solicitó, ignore este mensaje.</p>');
    correo_encolar($empleado['email'], 'Código de verificación: ' . $codigo, $html, 'codigo', (int)$ev['id'], (int)$empleado['id'], true);
    audit('otp_enviado', 'evaluacion', (int)$ev['id'], null, (int)$empleado['id']);
    return [true, 'Le enviamos un código de 6 dígitos a su correo.', $codigo];
}

function otp_verificar(array $ev, array $empleado, string $codigo): array
{
    $cfg = config('otp');
    $row = db_one('SELECT * FROM codigos_verificacion WHERE evaluacion_id = ? AND empleado_id = ? AND usado = 0 ORDER BY id DESC LIMIT 1', [$ev['id'], $empleado['id']]);
    if (!$row) {
        return [false, 'No hay un código vigente. Solicite uno nuevo.'];
    }
    if (strtotime($row['expira_at']) < time()) {
        db_exec('UPDATE codigos_verificacion SET usado = 1 WHERE id = ?', [$row['id']]);
        return [false, 'El código venció. Solicite uno nuevo.'];
    }
    if ((int)$row['intentos'] >= (int)$cfg['max_intentos']) {
        db_exec('UPDATE codigos_verificacion SET usado = 1 WHERE id = ?', [$row['id']]);
        return [false, 'Demasiados intentos. Solicite un nuevo código.'];
    }
    if (!preg_match('/^\d{6}$/', $codigo) || !password_verify($codigo, $row['codigo_hash'])) {
        db_exec('UPDATE codigos_verificacion SET intentos = intentos + 1 WHERE id = ?', [$row['id']]);
        $restantes = (int)$cfg['max_intentos'] - (int)$row['intentos'] - 1;
        return [false, $restantes > 0 ? "Código incorrecto. Le quedan $restantes intento(s)." : 'Código incorrecto. Solicite un nuevo código.'];
    }
    db_exec('UPDATE codigos_verificacion SET usado = 1 WHERE id = ?', [$row['id']]);
    audit('otp_verificado', 'evaluacion', (int)$ev['id'], null, (int)$empleado['id']);
    return [true, 'ok'];
}

// ---------------------------------------------------------------------
// Cálculos
// ---------------------------------------------------------------------

/** Puntaje (en la escala, p.ej. 1-5) de una asignación: promedio ponderado por sección y pregunta. */
function calcular_puntaje_asignacion(int $asignacionId, array $form): ?float
{
    $a = db_one('SELECT * FROM asignaciones WHERE id = ?', [$asignacionId]);
    $resp = [];
    foreach (db_all('SELECT pregunta_id, valor FROM respuestas WHERE asignacion_id = ?', [$asignacionId]) as $r) {
        $resp[(int)$r['pregunta_id']] = $r['valor'];
    }
    $sumSec = 0.0;
    $pesoSec = 0.0;
    foreach (formulario_para_relacion($form, $a['relacion']) as $s) {
        $sum = 0.0;
        $pesos = 0.0;
        foreach ($s['preguntas'] as $p) {
            if ($p['tipo'] !== 'escala' || !isset($resp[$p['id']]) || $resp[$p['id']] === null) {
                continue;
            }
            $w = $p['peso'] > 0 ? $p['peso'] : 0;
            $sum += (float)$resp[$p['id']] * $w;
            $pesos += $w;
        }
        if ($pesos > 0 && $s['peso'] > 0) {
            $sumSec += ($sum / $pesos) * $s['peso'];
            $pesoSec += $s['peso'];
        }
    }
    $p = $pesoSec > 0 ? round($sumSec / $pesoSec, 2) : null;
    db_update('asignaciones', ['puntaje' => $p], 'id = :id', ['id' => $asignacionId]);
    return $p;
}

/** Calificación final del participante ponderando por relación (sólo relaciones con respuestas). */
function calcular_participante(array $ev, int $empleadoId): ?float
{
    $rows = db_all(
        "SELECT relacion, AVG(puntaje) AS prom FROM asignaciones
          WHERE evaluacion_id = ? AND evaluado_id = ? AND estatus = 'completada' AND puntaje IS NOT NULL
          GROUP BY relacion",
        [$ev['id'], $empleadoId]
    );
    $pond = ['jefe' => (float)$ev['pond_jefe'], 'auto' => (float)$ev['pond_auto'], 'par' => (float)$ev['pond_par'], 'subordinado' => (float)$ev['pond_subordinado'], 'cliente' => 0.0];
    $sum = 0.0;
    $w = 0.0;
    foreach ($rows as $r) {
        $p = $pond[$r['relacion']] ?? 0;
        if ($p > 0) {
            $sum += (float)$r['prom'] * $p;
            $w += $p;
        }
    }
    if ($w == 0.0 && $rows) {
        $sum = array_sum(array_map(fn($r) => (float)$r['prom'], $rows));
        $w = count($rows);
    }
    $final = $w > 0 ? round($sum / $w, 2) : null;
    db_exec('UPDATE participantes SET calificacion_final = ? WHERE evaluacion_id = ? AND empleado_id = ?', [$final, $ev['id'], $empleadoId]);
    return $final;
}

function recalcular_evaluacion(int $evaluacionId): void
{
    $ev = evaluacion_get($evaluacionId);
    $form = evaluacion_formulario($ev);
    foreach (db_all("SELECT id FROM asignaciones WHERE evaluacion_id = ? AND estatus = 'completada'", [$evaluacionId]) as $a) {
        calcular_puntaje_asignacion((int)$a['id'], $form);
    }
    foreach (db_all('SELECT empleado_id FROM participantes WHERE evaluacion_id = ?', [$evaluacionId]) as $p) {
        calcular_participante($ev, (int)$p['empleado_id']);
    }
}

function etiqueta_escala(array $form, ?float $valor): string
{
    if ($valor === null) {
        return '—';
    }
    $mejor = null;
    foreach ($form['escala']['valores'] as $v) {
        if ($mejor === null || abs($v['valor'] - $valor) < abs($mejor['valor'] - $valor)) {
            $mejor = $v;
        }
    }
    return $mejor ? $mejor['etiqueta'] : '—';
}

/** Resumen de avance de una evaluación. */
function evaluacion_avance(int $evaluacionId): array
{
    $tot = db_one(
        "SELECT COUNT(*) AS total, SUM(estatus = 'completada') AS completadas, SUM(estatus = 'en_progreso') AS en_progreso
           FROM asignaciones WHERE evaluacion_id = ?",
        [$evaluacionId]
    );
    $evaluadores = db_one(
        "SELECT COUNT(*) AS total, SUM(pend = 0) AS completos FROM (
            SELECT evaluador_id, SUM(estatus <> 'completada') AS pend FROM asignaciones WHERE evaluacion_id = ? GROUP BY evaluador_id
         ) t",
        [$evaluacionId]
    );
    $total = (int)$tot['total'];
    return [
        'asignaciones'           => $total,
        'completadas'            => (int)$tot['completadas'],
        'en_progreso'            => (int)$tot['en_progreso'],
        'porcentaje'             => $total ? round(100 * (int)$tot['completadas'] / $total, 1) : 0,
        'evaluadores'            => (int)$evaluadores['total'],
        'evaluadores_completos'  => (int)$evaluadores['completos'],
        'participantes'          => (int)db_val('SELECT COUNT(*) FROM participantes WHERE evaluacion_id = ?', [$evaluacionId]),
    ];
}

/** Estadísticas para dashboard y reportes. $areaId opcional para filtrar. */
function evaluacion_estadisticas(array $ev, ?int $areaId = null): array
{
    $form = evaluacion_formulario($ev);
    $filtroArea = $areaId ? ' AND e.area_id = ' . (int)$areaId : '';
    $finales = db_all(
        "SELECT p.empleado_id, p.calificacion_final, e.nombre, e.apellido, e.email, e.puesto, e.nivel, a.nombre AS area
           FROM participantes p JOIN empleados e ON e.id = p.empleado_id LEFT JOIN areas a ON a.id = e.area_id
          WHERE p.evaluacion_id = ? $filtroArea ORDER BY e.apellido, e.nombre",
        [$ev['id']]
    );
    $vals = array_values(array_filter(array_map(fn($r) => $r['calificacion_final'] !== null ? (float)$r['calificacion_final'] : null, $finales), fn($v) => $v !== null));
    sort($vals);
    $n = count($vals);
    $prom = $n ? array_sum($vals) / $n : null;
    $desv = null;
    if ($n > 1) {
        $desv = sqrt(array_sum(array_map(fn($v) => ($v - $prom) ** 2, $vals)) / ($n - 1));
    }
    $mediana = $n ? ($n % 2 ? $vals[intdiv($n, 2)] : ($vals[$n / 2 - 1] + $vals[$n / 2]) / 2) : null;

    $distribucion = [];
    foreach ($form['escala']['valores'] as $v) {
        $distribucion[$v['etiqueta']] = 0;
    }
    foreach ($vals as $v) {
        $distribucion[etiqueta_escala($form, $v)]++;
    }

    $preguntasEscala = [];
    $abiertas = [];
    foreach ($form['secciones'] as $s) {
        foreach ($s['preguntas'] as $p) {
            if ($p['tipo'] === 'escala') {
                $preguntasEscala[$p['id']] = $p;
            } elseif ($p['tipo'] === 'abierta') {
                $abiertas[$p['id']] = $p;
            }
        }
    }
    $respRows = db_all(
        "SELECT r.pregunta_id, a.relacion, AVG(r.valor) AS prom, COUNT(r.valor) AS n
           FROM respuestas r JOIN asignaciones a ON a.id = r.asignacion_id JOIN empleados e ON e.id = a.evaluado_id
          WHERE a.evaluacion_id = ? AND a.estatus = 'completada' AND r.valor IS NOT NULL $filtroArea
          GROUP BY r.pregunta_id, a.relacion",
        [$ev['id']]
    );
    $porPregunta = [];
    $porCategoria = [];
    foreach ($respRows as $r) {
        $pid = (int)$r['pregunta_id'];
        if (!isset($preguntasEscala[$pid])) {
            continue;
        }
        $p = $preguntasEscala[$pid];
        $porPregunta[$pid]['texto'] = $p['texto'];
        $porPregunta[$pid]['categoria'] = $p['categoria'];
        $porPregunta[$pid]['rel'][$r['relacion']] = (float)$r['prom'];
        $porPregunta[$pid]['_sum'] = ($porPregunta[$pid]['_sum'] ?? 0) + (float)$r['prom'] * (int)$r['n'];
        $porPregunta[$pid]['_n'] = ($porPregunta[$pid]['_n'] ?? 0) + (int)$r['n'];
        $cat = $p['categoria'];
        $grupo = $r['relacion'] === 'auto' ? 'auto' : 'otros';
        $porCategoria[$cat][$grupo]['sum'] = ($porCategoria[$cat][$grupo]['sum'] ?? 0) + (float)$r['prom'] * (int)$r['n'];
        $porCategoria[$cat][$grupo]['n'] = ($porCategoria[$cat][$grupo]['n'] ?? 0) + (int)$r['n'];
        $porCategoria[$cat]['todos']['sum'] = ($porCategoria[$cat]['todos']['sum'] ?? 0) + (float)$r['prom'] * (int)$r['n'];
        $porCategoria[$cat]['todos']['n'] = ($porCategoria[$cat]['todos']['n'] ?? 0) + (int)$r['n'];
    }
    foreach ($porPregunta as &$pp) {
        $pp['promedio'] = $pp['_n'] ? $pp['_sum'] / $pp['_n'] : null;
        $pp['n'] = $pp['_n'];
        unset($pp['_sum'], $pp['_n']);
    }
    unset($pp);
    $cats = [];
    foreach ($porCategoria as $cat => $g) {
        $cats[$cat] = [
            'promedio' => isset($g['todos']) ? $g['todos']['sum'] / $g['todos']['n'] : null,
            'auto'     => isset($g['auto']) ? $g['auto']['sum'] / $g['auto']['n'] : null,
            'otros'    => isset($g['otros']) ? $g['otros']['sum'] / $g['otros']['n'] : null,
        ];
    }

    $porArea = db_all(
        "SELECT COALESCE(a.nombre, 'Sin área') AS area, AVG(p.calificacion_final) AS promedio, COUNT(p.calificacion_final) AS n
           FROM participantes p JOIN empleados e ON e.id = p.empleado_id LEFT JOIN areas a ON a.id = e.area_id
          WHERE p.evaluacion_id = ? $filtroArea GROUP BY a.nombre ORDER BY promedio DESC",
        [$ev['id']]
    );

    $porRelacion = db_all(
        "SELECT a.relacion, AVG(a.puntaje) AS promedio, COUNT(a.puntaje) AS n
           FROM asignaciones a JOIN empleados e ON e.id = a.evaluado_id
          WHERE a.evaluacion_id = ? AND a.estatus = 'completada' $filtroArea GROUP BY a.relacion",
        [$ev['id']]
    );

    $comentarios = [];
    if ($abiertas) {
        $in = implode(',', array_map('intval', array_keys($abiertas)));
        foreach (db_all(
            "SELECT r.pregunta_id, a.relacion, r.texto FROM respuestas r JOIN asignaciones a ON a.id = r.asignacion_id
               JOIN empleados e ON e.id = a.evaluado_id
              WHERE a.evaluacion_id = ? AND a.estatus = 'completada' AND r.pregunta_id IN ($in) AND r.texto IS NOT NULL AND r.texto <> '' $filtroArea
              ORDER BY r.pregunta_id, a.relacion",
            [$ev['id']]
        ) as $c) {
            $comentarios[$abiertas[(int)$c['pregunta_id']]['texto']][] = ['relacion' => $c['relacion'], 'texto' => $c['texto']];
        }
    }

    return [
        'form'          => $form,
        'finales'       => $finales,
        'n'             => $n,
        'promedio'      => $prom,
        'mediana'       => $mediana,
        'desviacion'    => $desv,
        'minimo'        => $n ? $vals[0] : null,
        'maximo'        => $n ? $vals[$n - 1] : null,
        'distribucion'  => $distribucion,
        'por_categoria' => $cats,
        'por_pregunta'  => $porPregunta,
        'por_area'      => $porArea,
        'por_relacion'  => $porRelacion,
        'comentarios'   => $comentarios,
    ];
}
