<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$id = int_get('id');
$tipo = get('tipo', 'concentrado');
$ev = evaluacion_get($id);
if (!$ev) {
    redirect('admin/index.php');
}
require_empresa_access($u, (int)$ev['empresa_id']);
$verIndividual = is_staff($u) || (int)$u['puede_ver_individual'] === 1;
$nombreBase = $ev['empresa_nombre'] . '_' . $ev['nombre'];
audit('exportar', 'evaluacion', $id, $tipo);

if ($tipo === 'avance') {
    if (!is_staff($u) && !$verIndividual) {
        http_response_code(403);
        exit('Sin permiso.');
    }
    $rows = [['Código', 'Nombre', 'Apellido', 'Correo', 'Área', 'Asignadas', 'Completadas', 'Pendientes', 'Completó todo', 'Última respuesta']];
    foreach (db_all("SELECT e.codigo, e.nombre, e.apellido, e.email, ar.nombre AS area, COUNT(*) AS total, SUM(a.estatus = 'completada') AS ok, MAX(a.enviada_at) AS ultima
                     FROM asignaciones a JOIN empleados e ON e.id = a.evaluador_id LEFT JOIN areas ar ON ar.id = e.area_id
                     WHERE a.evaluacion_id = ? GROUP BY e.id, e.codigo, e.nombre, e.apellido, e.email, ar.nombre ORDER BY e.apellido", [$id]) as $r) {
        $rows[] = [$r['codigo'], $r['nombre'], $r['apellido'], $r['email'], $r['area'], (int)$r['total'], (int)$r['ok'], (int)$r['total'] - (int)$r['ok'], (int)$r['ok'] === (int)$r['total'] ? 'Sí' : 'No', $r['ultima']];
    }
    exportar_excel('avance_' . $nombreBase, ['Avance' => $rows]);
}

if ($tipo === 'respuestas') {
    if (!is_staff($u)) {
        http_response_code(403);
        exit('Sin permiso.');
    }
    $form = evaluacion_formulario($ev);
    $info = [];
    foreach ($form['secciones'] as $s) {
        foreach ($s['preguntas'] as $p) {
            $info[$p['id']] = $p + ['seccion' => $s['nombre']];
        }
    }
    $rows = [['Evaluado', 'Correo evaluado', 'Área', 'Evaluador', 'Correo evaluador', 'Relación', 'Sección', 'Categoría', 'Pregunta', 'Valor', 'Texto', 'Fecha envío']];
    foreach (db_all("SELECT r.pregunta_id, r.valor, r.texto, a.relacion, a.enviada_at, CONCAT(d.nombre, ' ', d.apellido) AS evaluado, d.email AS evaluado_email, ar.nombre AS area,
                     CONCAT(x.nombre, ' ', x.apellido) AS evaluador, x.email AS evaluador_email
                     FROM respuestas r JOIN asignaciones a ON a.id = r.asignacion_id JOIN empleados d ON d.id = a.evaluado_id JOIN empleados x ON x.id = a.evaluador_id
                     LEFT JOIN areas ar ON ar.id = d.area_id WHERE a.evaluacion_id = ? AND a.estatus = 'completada' ORDER BY d.apellido, a.relacion, r.pregunta_id", [$id]) as $r) {
        $p = $info[(int)$r['pregunta_id']] ?? ['texto' => '#' . $r['pregunta_id'], 'categoria' => '', 'seccion' => ''];
        $rows[] = [$r['evaluado'], $r['evaluado_email'], $r['area'], $r['evaluador'], $r['evaluador_email'], etiqueta_relacion($r['relacion']), $p['seccion'], $p['categoria'], $p['texto'],
            $r['valor'] !== null ? (float)$r['valor'] : null, $r['texto'], $r['enviada_at']];
    }
    exportar_excel('respuestas_' . $nombreBase, ['Respuestas' => $rows]);
}

// Concentrado (varias hojas)
$areaId = int_get('area_id') ?: null;
$st = evaluacion_estadisticas($ev, $areaId);
$form = $st['form'];
$hojas = [];
$hojas['Resumen'] = [
    ['Indicador', 'Valor'],
    ['Empresa', $ev['empresa_nombre']], ['Evaluación', $ev['nombre']], ['Tipo', $ev['tipo'] . '°'],
    ['Período', fmt_fecha($ev['periodo_inicio']) . ' - ' . fmt_fecha($ev['periodo_fin'])],
    ['Participantes', count($st['finales'])], ['Con calificación', $st['n']],
    ['Promedio', $st['promedio'] !== null ? round($st['promedio'], 2) : null], ['Mediana', $st['mediana'] !== null ? round($st['mediana'], 2) : null],
    ['Desviación estándar', $st['desviacion'] !== null ? round($st['desviacion'], 2) : null],
    ['Mínimo', $st['minimo']], ['Máximo', $st['maximo']],
];
foreach ($st['distribucion'] as $k => $v) {
    $hojas['Resumen'][] = ['Personas en "' . $k . '"', $v];
}
if ($verIndividual) {
    $porRel = [];
    foreach (db_all("SELECT evaluado_id, relacion, AVG(puntaje) AS prom, COUNT(*) AS n FROM asignaciones WHERE evaluacion_id = ? AND estatus = 'completada' AND puntaje IS NOT NULL GROUP BY evaluado_id, relacion", [$id]) as $r) {
        $porRel[(int)$r['evaluado_id']][$r['relacion']] = $r;
    }
    $rows = [['Nombre', 'Apellido', 'Correo', 'Área', 'Puesto', 'Nivel', 'Calificación final', 'Clasificación', 'Autoevaluación', 'Jefe', 'Pares', 'Subordinados']];
    foreach ($st['finales'] as $f) {
        $pr = $porRel[(int)$f['empleado_id']] ?? [];
        $val = function ($rel) use ($pr, $ev) {
            if (!isset($pr[$rel])) {
                return null;
            }
            if (in_array($rel, ['par', 'subordinado'], true) && (int)$pr[$rel]['n'] < (int)$ev['anonimato_min']) {
                return 'anónimo';
            }
            return round((float)$pr[$rel]['prom'], 2);
        };
        $rows[] = [$f['nombre'], $f['apellido'], $f['email'], $f['area'], $f['puesto'], $f['nivel'],
            $f['calificacion_final'] !== null ? (float)$f['calificacion_final'] : null, etiqueta_escala($form, $f['calificacion_final'] !== null ? (float)$f['calificacion_final'] : null),
            $val('auto'), $val('jefe'), $val('par'), $val('subordinado')];
    }
    $hojas['Concentrado'] = $rows;
}
$rows = [['Categoría', 'Promedio', 'Autoevaluación', 'Otros evaluadores']];
foreach ($st['por_categoria'] as $c => $v) {
    $rows[] = [$c, $v['promedio'] !== null ? round($v['promedio'], 2) : null, $v['auto'] !== null ? round($v['auto'], 2) : null, $v['otros'] !== null ? round($v['otros'], 2) : null];
}
$hojas['Por categoría'] = $rows;
$rows = [['Pregunta', 'Categoría', 'Promedio', 'Auto', 'Jefe', 'Pares', 'Subordinados', 'Respuestas']];
foreach ($st['por_pregunta'] as $p) {
    $r = fn($k) => isset($p['rel'][$k]) ? round($p['rel'][$k], 2) : null;
    $rows[] = [$p['texto'], $p['categoria'], $p['promedio'] !== null ? round($p['promedio'], 2) : null, $r('auto'), $r('jefe'), $r('par'), $r('subordinado'), (int)$p['n']];
}
$hojas['Por pregunta'] = $rows;
$rows = [['Área', 'Promedio', 'Personas con calificación']];
foreach ($st['por_area'] as $a) {
    $rows[] = [$a['area'], $a['promedio'] !== null ? round((float)$a['promedio'], 2) : null, (int)$a['n']];
}
$hojas['Por área'] = $rows;
$rows = [['Pregunta', 'Relación', 'Comentario']];
foreach ($st['comentarios'] as $q => $lista) {
    foreach ($lista as $c) {
        $rows[] = [$q, etiqueta_relacion($c['relacion']), $c['texto']];
    }
}
$hojas['Comentarios'] = $rows;
exportar_excel('resultados_' . $nombreBase, $hojas);
