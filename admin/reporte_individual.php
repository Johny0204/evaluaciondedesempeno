<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$id = int_get('id');
$empId = int_get('empleado_id');
$ev = evaluacion_get($id);
if (!$ev) {
    redirect('admin/index.php');
}
require_empresa_access($u, (int)$ev['empresa_id']);
if (!is_staff($u) && !(int)$u['puede_ver_individual']) {
    http_response_code(403);
    exit('No tiene permiso para ver resultados individuales.');
}
$emp = db_one("SELECT e.*, a.nombre AS area, CONCAT(j.nombre, ' ', j.apellido) AS jefe, p.calificacion_final FROM participantes p JOIN empleados e ON e.id = p.empleado_id
               LEFT JOIN areas a ON a.id = e.area_id LEFT JOIN empleados j ON j.id = e.jefe_id WHERE p.evaluacion_id = ? AND e.id = ?", [$id, $empId]);
if (!$emp) {
    redirect('admin/resultados.php?id=' . $id);
}
$form = evaluacion_formulario($ev);
$porRel = db_all("SELECT relacion, AVG(puntaje) AS prom, COUNT(*) AS n FROM asignaciones WHERE evaluacion_id = ? AND evaluado_id = ? AND estatus = 'completada' GROUP BY relacion", [$id, $empId]);
$nRel = [];
foreach ($porRel as $r) {
    $nRel[$r['relacion']] = (int)$r['n'];
}
$visible = fn($rel) => !in_array($rel, ['par', 'subordinado'], true) || ($nRel[$rel] ?? 0) >= (int)$ev['anonimato_min'];
$resp = db_all("SELECT r.pregunta_id, a.relacion, r.valor, r.texto FROM respuestas r JOIN asignaciones a ON a.id = r.asignacion_id
                WHERE a.evaluacion_id = ? AND a.evaluado_id = ? AND a.estatus = 'completada'", [$id, $empId]);
$preg = [];
$cat = [];
$coment = [];
$info = [];
foreach ($form['secciones'] as $s) {
    foreach ($s['preguntas'] as $p) {
        $info[$p['id']] = $p;
    }
}
foreach ($resp as $r) {
    $p = $info[(int)$r['pregunta_id']] ?? null;
    if (!$p) {
        continue;
    }
    $grp = in_array($r['relacion'], ['par', 'subordinado'], true) && !$visible($r['relacion']) ? 'otros_anon' : $r['relacion'];
    if ($p['tipo'] === 'escala' && $r['valor'] !== null) {
        $preg[$p['id']][$grp][] = (float)$r['valor'];
        $k = $r['relacion'] === 'auto' ? 'auto' : 'otros';
        $cat[$p['categoria']][$k][] = (float)$r['valor'];
    } elseif ($r['texto'] !== null && $r['texto'] !== '') {
        $coment[$p['texto']][] = ['rel' => $grp, 'texto' => $r['texto']];
    }
}
$avg = fn(?array $a) => $a ? array_sum($a) / count($a) : null;
$cols = ['auto', 'jefe', 'par', 'subordinado', 'otros_anon'];
$colLabel = ['auto' => 'Auto', 'jefe' => 'Jefe', 'par' => 'Pares', 'subordinado' => 'Subordinados', 'otros_anon' => 'Pares/Sub. (agrupado)'];
$labels = array_keys($cat);
$chart = ['max' => $form['escala']['max'], 'min' => $form['escala']['min'], 'dist' => null, 'cat' => ['labels' => []], 'area' => ['labels' => [], 'data' => []], 'rel' => ['labels' => [], 'data' => []],
    'indiv' => ['labels' => $labels, 'datasets' => [
        ['label' => 'Otros evaluadores', 'data' => array_map(fn($c) => $avg($c['otros'] ?? null), array_values($cat)), 'borderColor' => '#1f4e79', 'backgroundColor' => 'rgba(31,78,121,.25)'],
        ['label' => 'Autoevaluación', 'data' => array_map(fn($c) => $avg($c['auto'] ?? null), array_values($cat)), 'borderColor' => '#ed7d31', 'backgroundColor' => 'rgba(237,125,49,.2)'],
    ]]];
render_header('Reporte individual');
?>
<div class="d-flex justify-content-between align-items-center mb-3 no-print">
  <a class="btn btn-link" href="<?= e(url('admin/resultados.php?id=' . $id)) ?>">&larr; Volver a resultados</a>
  <button class="btn btn-outline-secondary" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>
<div class="card mb-3"><div class="card-body">
  <div class="d-flex justify-content-between flex-wrap">
    <div><div class="text-muted small"><?= e($ev['empresa_nombre']) ?> · <?= e($ev['nombre']) ?> · Período <?= fmt_fecha($ev['periodo_inicio']) ?> - <?= fmt_fecha($ev['periodo_fin']) ?></div>
      <h1 class="h4 mb-1"><?= e($emp['nombre'] . ' ' . $emp['apellido']) ?></h1>
      <div class="small"><?= e($emp['puesto']) ?> · <?= e($emp['area']) ?> · Jefe: <?= e($emp['jefe']) ?></div></div>
    <div class="text-end"><div class="kpi"><div class="etiqueta">Calificación final</div><div class="valor"><?= fmt_num($emp['calificacion_final'], 2) ?></div>
      <div class="fw-semibold"><?= e(etiqueta_escala($form, $emp['calificacion_final'] !== null ? (float)$emp['calificacion_final'] : null)) ?></div></div></div>
  </div>
  <div class="mt-3 small"><?php foreach ($porRel as $r): ?><span class="badge text-bg-light border me-1"><?= e(etiqueta_relacion($r['relacion'])) ?>: <?= $visible($r['relacion']) ? fmt_num($r['prom'], 2) : 'anónimo' ?> (<?= (int)$r['n'] ?>)</span><?php endforeach; ?></div>
</div></div>
<div class="row g-3 mb-3">
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Perfil por competencia</div><div class="card-body"><div class="chart-box"><canvas id="chIndiv"></canvas></div></div></div></div>
  <div class="col-lg-7"><div class="card h-100"><div class="card-header">Detalle por pregunta</div><div class="table-responsive"><table class="table table-sm mb-0">
    <thead><tr><th>Pregunta</th><?php foreach ($cols as $c): ?><th class="text-end"><?= e($colLabel[$c]) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php foreach ($preg as $pid => $g): ?><tr><td class="small"><?= e($info[$pid]['texto']) ?></td><?php foreach ($cols as $c): ?><td class="text-end"><?= fmt_num($avg($g[$c] ?? null), 2) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table></div></div></div>
</div>
<?php if ($coment): ?><div class="card"><div class="card-header">Comentarios</div><div class="card-body">
  <?php foreach ($coment as $q => $lista): ?><h3 class="h6"><?= e($q) ?></h3><ul class="small"><?php foreach ($lista as $c): ?><li><?= e($c['texto']) ?> <span class="text-muted">(<?= e($colLabel[$c['rel']] ?? $c['rel']) ?>)</span></li><?php endforeach; ?></ul><?php endforeach; ?>
</div></div><?php endif; ?>
<script>window.DATOS_GRAFICAS = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php render_footer(['assets/js/chart.umd.min.js', 'assets/js/graficas.js']);
