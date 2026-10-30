<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$empresaId = int_get('empresa_id');
$estatus = get('estatus');
$where = '1=1';
$params = [];
if ($empresaId) {
    $where .= ' AND ev.empresa_id = ?';
    $params[] = $empresaId;
}
if (in_array($estatus, ['borrador', 'publicada', 'cerrada'], true)) {
    $where .= ' AND ev.estatus = ?';
    $params[] = $estatus;
}
$rows = db_all("SELECT ev.*, em.nombre AS empresa, tf.nombre AS formulario FROM evaluaciones ev JOIN empresas em ON em.id = ev.empresa_id
                JOIN tipos_formulario tf ON tf.id = ev.tipo_formulario_id WHERE $where ORDER BY ev.created_at DESC", $params);
$empresas = db_all('SELECT id, nombre FROM empresas ORDER BY nombre');
render_header('Evaluaciones');
page_title('Evaluaciones', '<a class="btn btn-primary" href="' . e(url('admin/evaluacion.php' . ($empresaId ? '?empresa_id=' . $empresaId : ''))) . '">+ Nueva evaluación</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-md-4"><select name="empresa_id" class="form-select"><?= select_options($empresas, $empresaId ?: '', 'id', 'nombre', 'Todas las empresas') ?></select></div>
  <div class="col-md-3"><select name="estatus" class="form-select"><option value="">Todos los estatus</option>
    <?php foreach (['borrador' => 'Borrador', 'publicada' => 'Publicada', 'cerrada' => 'Cerrada'] as $k => $v): ?><option value="<?= $k ?>" <?= $estatus === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Filtrar</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
  <thead><tr><th>Empresa</th><th>Evaluación</th><th>Período</th><th>Tipo</th><th>Formulario</th><th>Respuestas</th><th>Estatus</th><th>Avance</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $av = evaluacion_avance((int)$r['id']); ?>
  <tr><td><?= e($r['empresa']) ?></td><td><strong><?= e($r['nombre']) ?></strong></td>
    <td class="small"><?= fmt_fecha($r['periodo_inicio']) ?> - <?= fmt_fecha($r['periodo_fin']) ?></td><td><?= e($r['tipo']) ?>°</td><td class="small"><?= e($r['formulario']) ?></td>
    <td class="small"><?= fmt_fecha($r['fecha_apertura'], true) ?><br><?= fmt_fecha($r['fecha_cierre'], true) ?></td><td><?= etiqueta_estatus($r['estatus']) ?></td>
    <td style="min-width:140px"><div class="progress"><div class="progress-bar" style="width:<?= $av['porcentaje'] ?>%"><?= $av['porcentaje'] ?>%</div></div></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/evaluacion_ver.php?id=' . $r['id'])) ?>">Abrir</a>
      <a class="btn btn-sm btn-outline-success" href="<?= e(url('admin/resultados.php?id=' . $r['id'])) ?>">Resultados</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="9" class="text-center text-muted">No hay evaluaciones.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php render_footer();
