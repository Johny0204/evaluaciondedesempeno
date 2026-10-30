<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_superadmin();
$q = get('q');
$params = [];
$where = '1=1';
if ($q !== '') {
    $where = '(a.accion LIKE ? OR a.entidad LIKE ? OR us.email LIKE ? OR em.email LIKE ?)';
    $params = ["%$q%", "%$q%", "%$q%", "%$q%"];
}
$rows = db_all("SELECT a.*, us.email AS usuario, em.email AS empleado FROM auditoria a LEFT JOIN usuarios us ON us.id = a.usuario_id LEFT JOIN empleados em ON em.id = a.empleado_id
                WHERE $where ORDER BY a.id DESC LIMIT 500", $params);
render_header('Bitácora');
page_title('Bitácora de auditoría');
?>
<form class="row g-2 mb-3"><div class="col-md-4"><input name="q" class="form-control" value="<?= e($q) ?>" placeholder="Acción, entidad o correo"></div><div class="col-auto"><button class="btn btn-outline-secondary">Buscar</button></div></form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>Fecha</th><th>Usuario / Empleado</th><th>Acción</th><th>Entidad</th><th>Detalle</th><th>IP</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td class="small text-nowrap"><?= fmt_fecha($r['created_at'], true) ?></td><td class="small"><?= e($r['usuario'] ?: $r['empleado']) ?></td><td><?= e($r['accion']) ?></td>
    <td class="small"><?= e($r['entidad']) ?> <?= $r['entidad_id'] ? '#' . (int)$r['entidad_id'] : '' ?></td><td class="small"><?= e(mb_substr((string)$r['detalle'], 0, 150)) ?></td><td class="small"><?= e($r['ip']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php render_footer();
