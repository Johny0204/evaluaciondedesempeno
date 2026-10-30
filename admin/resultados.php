<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$id = int_get('id');
$ev = evaluacion_get($id);
if (!$ev) {
    redirect('admin/index.php');
}
require_empresa_access($u, (int)$ev['empresa_id']);
if (!is_staff($u) && $ev['estatus'] === 'borrador') {
    redirect('admin/index.php');
}
$verIndividual = is_staff($u) || (int)$u['puede_ver_individual'] === 1;
$areaId = int_get('area_id') ?: null;
$st = evaluacion_estadisticas($ev, $areaId);
$av = evaluacion_avance($id);
$form = $st['form'];
$areas = db_all('SELECT id, nombre FROM areas WHERE empresa_id = ? ORDER BY nombre', [$ev['empresa_id']]);

$porRelPart = [];
if ($verIndividual) {
    foreach (db_all("SELECT evaluado_id, relacion, AVG(puntaje) AS prom, COUNT(*) AS n FROM asignaciones WHERE evaluacion_id = ? AND estatus = 'completada' AND puntaje IS NOT NULL GROUP BY evaluado_id, relacion", [$id]) as $r) {
        $porRelPart[(int)$r['evaluado_id']][$r['relacion']] = $r;
    }
}
$relVisible = function (array $r) use ($ev): bool {
    return !in_array($r['relacion'], ['par', 'subordinado'], true) || (int)$r['n'] >= (int)$ev['anonimato_min'];
};
$catLabels = array_keys($st['por_categoria']);
$chart = [
    'dist'   => ['labels' => array_keys($st['distribucion']), 'data' => array_values($st['distribucion'])],
    'cat'    => ['labels' => $catLabels, 'todos' => array_map(fn($c) => $c['promedio'] !== null ? round($c['promedio'], 2) : null, array_values($st['por_categoria'])),
                 'auto' => array_map(fn($c) => $c['auto'] !== null ? round($c['auto'], 2) : null, array_values($st['por_categoria'])),
                 'otros' => array_map(fn($c) => $c['otros'] !== null ? round($c['otros'], 2) : null, array_values($st['por_categoria']))],
    'area'   => ['labels' => array_column($st['por_area'], 'area'), 'data' => array_map(fn($r) => $r['promedio'] !== null ? round((float)$r['promedio'], 2) : null, $st['por_area'])],
    'rel'    => ['labels' => array_map(fn($r) => etiqueta_relacion($r['relacion']), $st['por_relacion']), 'data' => array_map(fn($r) => round((float)$r['promedio'], 2), $st['por_relacion'])],
    'min'    => $form['escala']['min'],
    'max'    => $form['escala']['max'],
];
$qsExport = fn($tipo) => url('admin/exportar.php?' . http_build_query(['id' => $id, 'tipo' => $tipo, 'area_id' => $areaId ?: '']));
render_header('Resultados');
page_title('Resultados: ' . $ev['empresa_nombre'] . ' · ' . $ev['nombre'],
    '<a class="btn btn-success" href="' . e($qsExport('concentrado')) . '">Exportar a Excel</a> '
    . (is_staff($u) ? '<a class="btn btn-outline-success" href="' . e($qsExport('respuestas')) . '">Respuestas detalladas</a> ' : '')
    . '<button class="btn btn-outline-secondary no-print" onclick="window.print()">Imprimir / PDF</button>');
?>
<form class="row g-2 mb-3 no-print" method="get"><input type="hidden" name="id" value="<?= $id ?>">
  <div class="col-md-4"><select name="area_id" class="form-select" onchange="this.form.submit()"><?= select_options($areas, $areaId ?: '', 'id', 'nombre', 'Todas las áreas') ?></select></div>
  <div class="col-md-8 small text-muted d-flex align-items-center">Escala: <?php foreach ($form['escala']['valores'] as $v): ?>&nbsp;<?= e(fmt_num($v['valor'], 0)) ?>=<?= e($v['etiqueta']) ?>&nbsp;·<?php endforeach; ?></div>
</form>
<div class="row g-3 mb-3">
  <?php foreach ([
      'Avance' => $av['porcentaje'] . '%',
      'Participantes evaluados' => $st['n'] . ' / ' . count($st['finales']),
      'Promedio general' => fmt_num($st['promedio'], 2),
      'Mediana' => fmt_num($st['mediana'], 2),
      'Desviación estándar' => fmt_num($st['desviacion'], 2),
      'Mín. / Máx.' => fmt_num($st['minimo'], 2) . ' / ' . fmt_num($st['maximo'], 2),
  ] as $k => $v): ?>
  <div class="col-6 col-md-4 col-xl-2"><div class="card kpi p-3"><div class="etiqueta"><?= e($k) ?></div><div class="valor" style="font-size:1.5rem"><?= e($v) ?></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card"><div class="card-header">Distribución de calificaciones</div><div class="card-body"><div class="chart-box"><canvas id="chDist"></canvas></div></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header">Resultados por competencia (autoevaluación vs otros evaluadores)</div><div class="card-body"><div class="chart-box"><canvas id="chCat"></canvas></div></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header">Promedio por área</div><div class="card-body"><div class="chart-box"><canvas id="chArea"></canvas></div></div></div></div>
  <div class="col-lg-6"><div class="card"><div class="card-header">Promedio por tipo de evaluador</div><div class="card-body"><div class="chart-box"><canvas id="chRel"></canvas></div></div></div></div>
</div>
<div class="card mb-3"><div class="card-header">Resultados por pregunta</div><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Pregunta</th><th>Categoría</th><th class="text-end">Promedio</th><?php foreach (RELACIONES as $r): ?><th class="text-end"><?= e(etiqueta_relacion($r)) ?></th><?php endforeach; ?><th class="text-end">Respuestas</th></tr></thead><tbody>
  <?php foreach ($st['por_pregunta'] as $p): ?>
    <tr><td><?= e($p['texto']) ?></td><td class="small"><?= e($p['categoria']) ?></td><td class="text-end fw-bold"><?= fmt_num($p['promedio'], 2) ?></td>
      <?php foreach (RELACIONES as $r): ?><td class="text-end"><?= fmt_num($p['rel'][$r] ?? null, 2) ?></td><?php endforeach; ?><td class="text-end"><?= (int)$p['n'] ?></td></tr>
  <?php endforeach; if (!$st['por_pregunta']): ?><tr><td colspan="9" class="text-center text-muted">Aún no hay respuestas completadas.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php if ($verIndividual): ?>
<div class="card mb-3"><div class="card-header">Resultados individuales</div><div class="table-responsive" style="max-height:70vh"><table class="table table-sm table-hover mb-0 align-middle">
  <thead><tr><th>Empleado</th><th>Área</th><th>Puesto</th><th class="text-end">Calificación</th><th>Nivel</th><?php foreach (['auto', 'jefe', 'par', 'subordinado'] as $r): ?><th class="text-end"><?= e(etiqueta_relacion($r)) ?></th><?php endforeach; ?><th></th></tr></thead><tbody>
  <?php foreach ($st['finales'] as $f): $pr = $porRelPart[(int)$f['empleado_id']] ?? []; ?>
    <tr><td><?= e($f['apellido'] . ', ' . $f['nombre']) ?></td><td><?= e($f['area']) ?></td><td class="small"><?= e($f['puesto']) ?></td>
      <td class="text-end fw-bold"><?= fmt_num($f['calificacion_final'], 2) ?></td><td class="small"><?= e(etiqueta_escala($form, $f['calificacion_final'] !== null ? (float)$f['calificacion_final'] : null)) ?></td>
      <?php foreach (['auto', 'jefe', 'par', 'subordinado'] as $r): ?><td class="text-end"><?php if (isset($pr[$r])): ?><?= $relVisible($pr[$r]) ? fmt_num($pr[$r]['prom'], 2) : '<span class="text-muted small" title="Menos respuestas que el mínimo de anonimato">(anónimo)</span>' ?><?php else: ?>—<?php endif; ?></td><?php endforeach; ?>
      <td><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/reporte_individual.php?id=' . $id . '&empleado_id=' . $f['empleado_id'])) ?>">Detalle</a></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div>
<?php endif; ?>
<?php if ($st['comentarios']): ?>
<div class="card mb-3"><div class="card-header">Comentarios (anónimos)</div><div class="card-body">
  <?php foreach ($st['comentarios'] as $preg => $lista): ?>
    <h3 class="h6"><?= e($preg) ?> <span class="badge text-bg-light"><?= count($lista) ?></span></h3>
    <ul class="small"><?php foreach (array_slice($lista, 0, 200) as $c): ?><li><?= e($c['texto']) ?> <span class="text-muted">(<?= e(etiqueta_relacion($c['relacion'])) ?>)</span></li><?php endforeach; ?></ul>
  <?php endforeach; ?>
</div></div>
<?php endif; ?>
<script>window.DATOS_GRAFICAS = <?= json_encode($chart, JSON_UNESCAPED_UNICODE) ?>;</script>
<?php render_footer(['assets/js/chart.umd.min.js', 'assets/js/graficas.js']);
