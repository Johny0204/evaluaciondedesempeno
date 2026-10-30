<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$q = get('q');
$params = [];
$where = '1=1';
if ($q !== '') {
    $where .= ' AND (em.nombre LIKE ? OR em.nit LIKE ? OR em.nombre_comercial LIKE ?)';
    $params = ["%$q%", "%$q%", "%$q%"];
}
$rows = db_all(
    "SELECT em.*, te.nombre AS tipo, us.nombre AS consultor,
            (SELECT COUNT(*) FROM empleados x WHERE x.empresa_id = em.id AND x.activo = 1) AS empleados,
            (SELECT COUNT(*) FROM evaluaciones x WHERE x.empresa_id = em.id) AS evaluaciones
       FROM empresas em LEFT JOIN tipos_empresa te ON te.id = em.tipo_empresa_id LEFT JOIN usuarios us ON us.id = em.consultor_id
      WHERE $where ORDER BY em.activo DESC, em.nombre",
    $params
);
render_header('Empresas');
page_title('Empresas cliente', '<a class="btn btn-primary" href="' . e(url('admin/empresa.php')) . '">+ Nueva empresa</a>');
?>
<form class="row g-2 mb-3" method="get">
  <div class="col-md-4"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Buscar por nombre o NIT"></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Buscar</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
  <thead><tr><th>Empresa</th><th>NIT</th><th>Tipo</th><th>Consultor</th><th class="text-end">Empleados</th><th class="text-end">Evaluaciones</th><th>Estatus</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
    <tr>
      <td><strong><?= e($r['nombre']) ?></strong><?php if ($r['nombre_comercial']): ?><div class="small text-muted"><?= e($r['nombre_comercial']) ?></div><?php endif; ?></td>
      <td><?= e($r['nit']) ?></td><td><?= e($r['tipo']) ?></td><td><?= e($r['consultor']) ?></td>
      <td class="text-end"><?= (int)$r['empleados'] ?></td><td class="text-end"><?= (int)$r['evaluaciones'] ?></td>
      <td><?= $r['activo'] ? '<span class="badge text-bg-success">Activa</span>' : '<span class="badge text-bg-secondary">Inactiva</span>' ?></td>
      <td class="text-end text-nowrap">
        <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/empresa.php?id=' . $r['id'])) ?>">Editar</a>
        <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/empleados.php?empresa_id=' . $r['id'])) ?>">Empleados</a>
        <a class="btn btn-sm btn-outline-success" href="<?= e(url('admin/evaluaciones.php?empresa_id=' . $r['id'])) ?>">Evaluaciones</a>
      </td>
    </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">No hay empresas registradas.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php render_footer();
