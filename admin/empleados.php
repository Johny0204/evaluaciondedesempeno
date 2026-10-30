<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$empresaId = int_get('empresa_id');
$empresa = db_one('SELECT * FROM empresas WHERE id = ?', [$empresaId]);
if (!$empresa) {
    flash('danger', 'Seleccione una empresa.');
    redirect('admin/empresas.php');
}

if (is_post()) {
    csrf_check();
    $accion = post('accion');
    $empId = (int)post('empleado_id');
    if ($accion === 'baja' || $accion === 'alta') {
        db_exec('UPDATE empleados SET activo = ? WHERE id = ? AND empresa_id = ?', [$accion === 'alta' ? 1 : 0, $empId, $empresaId]);
        audit($accion . '_empleado', 'empleado', $empId);
        flash('success', $accion === 'alta' ? 'Empleado reactivado.' : 'Empleado dado de baja.');
    } elseif ($accion === 'eliminar') {
        if ((int)db_val('SELECT COUNT(*) FROM asignaciones WHERE evaluador_id = ? OR evaluado_id = ?', [$empId, $empId]) > 0) {
            flash('warning', 'El empleado tiene evaluaciones asociadas; sólo puede darse de baja.');
        } else {
            db_exec('DELETE FROM empleados WHERE id = ? AND empresa_id = ?', [$empId, $empresaId]);
            audit('eliminar_empleado', 'empleado', $empId);
            flash('success', 'Empleado eliminado.');
        }
    }
    redirect('admin/empleados.php?empresa_id=' . $empresaId);
}

$q = get('q');
$areaId = int_get('area_id');
$estado = get('estado', 'activos');
$where = 'e.empresa_id = ?';
$params = [$empresaId];
if ($q !== '') {
    $where .= ' AND (e.nombre LIKE ? OR e.apellido LIKE ? OR e.email LIKE ? OR e.codigo LIKE ?)';
    array_push($params, "%$q%", "%$q%", "%$q%", "%$q%");
}
if ($areaId) {
    $where .= ' AND e.area_id = ?';
    $params[] = $areaId;
}
if ($estado === 'activos') {
    $where .= ' AND e.activo = 1';
} elseif ($estado === 'bajas') {
    $where .= ' AND e.activo = 0';
}
$sql = "SELECT e.*, a.nombre AS area, CONCAT(j.nombre, ' ', j.apellido) AS jefe, j.email AS jefe_email
          FROM empleados e LEFT JOIN areas a ON a.id = e.area_id LEFT JOIN empleados j ON j.id = e.jefe_id
         WHERE $where ORDER BY e.apellido, e.nombre";

if (get('exportar') === '1') {
    $rows = [['codigo', 'nombre', 'apellido', 'email', 'area', 'puesto', 'nivel', 'email_jefe', 'sexo', 'fecha_ingreso', 'activo']];
    foreach (db_all($sql, $params) as $r) {
        $rows[] = [$r['codigo'], $r['nombre'], $r['apellido'], $r['email'], $r['area'], $r['puesto'], $r['nivel'], $r['jefe_email'], $r['sexo'], $r['fecha_ingreso'], $r['activo'] ? 'Sí' : 'No'];
    }
    exportar_excel('empleados_' . $empresa['nombre'], ['Empleados' => $rows]);
}

$pagina = max(1, int_get('p'));
$porPagina = 50;
$total = (int)db_val("SELECT COUNT(*) FROM empleados e WHERE $where", $params);
$rows = db_all($sql . ' LIMIT ' . $porPagina . ' OFFSET ' . (($pagina - 1) * $porPagina), $params);
$areas = db_all('SELECT id, nombre FROM areas WHERE empresa_id = ? ORDER BY nombre', [$empresaId]);
$qs = fn(array $extra) => http_build_query(array_merge(['empresa_id' => $empresaId, 'q' => $q, 'area_id' => $areaId ?: '', 'estado' => $estado], $extra));

render_header('Empleados');
page_title('Empleados: ' . $empresa['nombre'],
    '<a class="btn btn-primary" href="' . e(url('admin/empleado.php?empresa_id=' . $empresaId)) . '">+ Nuevo empleado</a> '
    . '<a class="btn btn-outline-primary" href="' . e(url('admin/importar_empleados.php?empresa_id=' . $empresaId)) . '">Importar CSV/Excel</a> '
    . '<a class="btn btn-outline-success" href="?' . e($qs(['exportar' => 1])) . '">Exportar Excel</a>');
?>
<form class="row g-2 mb-3" method="get">
  <input type="hidden" name="empresa_id" value="<?= $empresaId ?>">
  <div class="col-md-4"><input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Nombre, correo o código"></div>
  <div class="col-md-3"><select name="area_id" class="form-select"><?= select_options($areas, $areaId ?: '', 'id', 'nombre', 'Todas las áreas') ?></select></div>
  <div class="col-md-2"><select name="estado" class="form-select">
    <option value="activos" <?= $estado === 'activos' ? 'selected' : '' ?>>Activos</option>
    <option value="bajas" <?= $estado === 'bajas' ? 'selected' : '' ?>>Bajas</option>
    <option value="todos" <?= $estado === 'todos' ? 'selected' : '' ?>>Todos</option></select></div>
  <div class="col-auto"><button class="btn btn-outline-secondary">Filtrar</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm table-hover mb-0 align-middle">
  <thead><tr><th>Código</th><th>Nombre</th><th>Correo</th><th>Área</th><th>Puesto</th><th>Jefe inmediato</th><th>Estatus</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr>
    <td><?= e($r['codigo']) ?></td><td><?= e($r['apellido'] . ', ' . $r['nombre']) ?></td><td><?= e($r['email']) ?></td>
    <td><?= e($r['area']) ?></td><td><?= e($r['puesto']) ?></td><td><?= e($r['jefe']) ?></td>
    <td><?= $r['activo'] ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Baja</span>' ?></td>
    <td class="text-end text-nowrap">
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/empleado.php?empresa_id=' . $empresaId . '&id=' . $r['id'])) ?>">Editar</a>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="empleado_id" value="<?= $r['id'] ?>">
        <?php if ($r['activo']): ?><button name="accion" value="baja" class="btn btn-sm btn-outline-warning">Baja</button>
        <?php else: ?><button name="accion" value="alta" class="btn btn-sm btn-outline-success">Reactivar</button><?php endif; ?>
        <button name="accion" value="eliminar" class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar definitivamente este empleado?">Eliminar</button>
      </form>
    </td>
  </tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-center text-muted">Sin empleados. Agréguelos o impórtelos desde CSV/Excel.</td></tr><?php endif; ?>
  </tbody></table></div>
  <div class="card-footer d-flex justify-content-between align-items-center small">
    <span><?= $total ?> empleado(s)</span>
    <span>
    <?php $paginas = (int)ceil($total / $porPagina); for ($i = 1; $i <= $paginas; $i++): ?>
      <a class="btn btn-sm <?= $i === $pagina ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?<?= e($qs(['p' => $i])) ?>"><?= $i ?></a>
    <?php endfor; ?>
    </span>
  </div>
</div>
<?php render_footer();
