<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();

if (!is_staff($u)) {
    $evs = db_all("SELECT * FROM evaluaciones WHERE empresa_id = ? AND estatus <> 'borrador' ORDER BY fecha_apertura DESC", [$u['empresa_id']]);
    $empresa = db_one('SELECT * FROM empresas WHERE id = ?', [$u['empresa_id']]);
    render_header('Inicio');
    page_title('Evaluaciones de ' . ($empresa['nombre'] ?? ''));
    ?>
    <div class="card"><div class="table-responsive"><table class="table mb-0 align-middle">
      <thead><tr><th>Evaluación</th><th>Período</th><th>Tipo</th><th>Estatus</th><th>Avance</th><th></th></tr></thead><tbody>
      <?php foreach ($evs as $ev): $av = evaluacion_avance((int)$ev['id']); ?>
        <tr><td><?= e($ev['nombre']) ?></td><td><?= fmt_fecha($ev['periodo_inicio']) ?> - <?= fmt_fecha($ev['periodo_fin']) ?></td>
          <td><?= e($ev['tipo']) ?>°</td><td><?= etiqueta_estatus($ev['estatus']) ?></td>
          <td style="min-width:160px"><div class="progress"><div class="progress-bar" style="width:<?= $av['porcentaje'] ?>%"><?= $av['porcentaje'] ?>%</div></div></td>
          <td><a class="btn btn-sm btn-primary" href="<?= e(url('admin/resultados.php?id=' . $ev['id'])) ?>">Ver resultados</a></td></tr>
      <?php endforeach; if (!$evs): ?><tr><td colspan="6" class="text-muted text-center">Aún no hay evaluaciones publicadas.</td></tr><?php endif; ?>
      </tbody></table></div></div>
    <?php
    render_footer();
    exit;
}

$kpi = [
    'Empresas activas'        => db_val('SELECT COUNT(*) FROM empresas WHERE activo = 1'),
    'Evaluaciones en curso'   => db_val("SELECT COUNT(*) FROM evaluaciones WHERE estatus = 'publicada'"),
    'Evaluaciones pendientes' => db_val("SELECT COUNT(*) FROM asignaciones a JOIN evaluaciones ev ON ev.id = a.evaluacion_id WHERE ev.estatus = 'publicada' AND a.estatus <> 'completada'"),
    'Correos en cola'         => db_val("SELECT COUNT(*) FROM correos WHERE estatus = 'pendiente'"),
];
$evs = db_all(
    "SELECT ev.*, em.nombre AS empresa FROM evaluaciones ev JOIN empresas em ON em.id = ev.empresa_id
      WHERE ev.estatus IN ('publicada','borrador') ORDER BY ev.estatus DESC, ev.fecha_cierre LIMIT 20"
);
render_header('Inicio');
page_title('Panel de control', '<a class="btn btn-primary" href="' . e(url('admin/evaluacion.php')) . '">+ Nueva evaluación</a>');
?>
<div class="row g-3 mb-4">
<?php foreach ($kpi as $k => $v): ?>
  <div class="col-6 col-lg-3"><div class="card kpi p-3"><div class="etiqueta"><?= e($k) ?></div><div class="valor"><?= (int)$v ?></div></div></div>
<?php endforeach; ?>
</div>
<div class="card">
  <div class="card-header">Evaluaciones activas y en borrador</div>
  <div class="table-responsive"><table class="table mb-0 align-middle">
    <thead><tr><th>Empresa</th><th>Evaluación</th><th>Tipo</th><th>Cierre</th><th>Estatus</th><th>Avance</th><th></th></tr></thead><tbody>
    <?php foreach ($evs as $ev): $av = evaluacion_avance((int)$ev['id']); ?>
      <tr><td><?= e($ev['empresa']) ?></td><td><?= e($ev['nombre']) ?></td><td><?= e($ev['tipo']) ?>°</td>
        <td><?= fmt_fecha($ev['fecha_cierre'], true) ?></td><td><?= etiqueta_estatus($ev['estatus']) ?></td>
        <td style="min-width:160px"><div class="progress" title="<?= $av['completadas'] ?>/<?= $av['asignaciones'] ?>"><div class="progress-bar" style="width:<?= $av['porcentaje'] ?>%"><?= $av['porcentaje'] ?>%</div></div></td>
        <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/evaluacion_ver.php?id=' . $ev['id'])) ?>">Abrir</a></td></tr>
    <?php endforeach; if (!$evs): ?><tr><td colspan="7" class="text-center text-muted">No hay evaluaciones activas.</td></tr><?php endif; ?>
    </tbody></table></div>
</div>
<?php render_footer();
