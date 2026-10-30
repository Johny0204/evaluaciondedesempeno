<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$empresaId = int_get('empresa_id');
$id = int_get('id');
$empresa = db_one('SELECT * FROM empresas WHERE id = ?', [$empresaId]);
if (!$empresa) {
    redirect('admin/empresas.php');
}
$emp = $id ? db_one('SELECT * FROM empleados WHERE id = ? AND empresa_id = ?', [$id, $empresaId]) : null;
if ($id && !$emp) {
    redirect('admin/empleados.php?empresa_id=' . $empresaId);
}
$errores = [];

function area_id_por_nombre(int $empresaId, string $nombre): ?int
{
    $nombre = trim($nombre);
    if ($nombre === '') {
        return null;
    }
    $id = db_val('SELECT id FROM areas WHERE empresa_id = ? AND nombre = ?', [$empresaId, $nombre]);
    return $id ? (int)$id : db_insert('areas', ['empresa_id' => $empresaId, 'nombre' => $nombre]);
}

if (is_post()) {
    csrf_check();
    $data = [
        'empresa_id'    => $empresaId,
        'codigo'        => post('codigo') ?: null,
        'nombre'        => post('nombre'),
        'apellido'      => post('apellido'),
        'email'         => strtolower(post('email')),
        'puesto'        => post('puesto') ?: null,
        'nivel'         => post('nivel') ?: null,
        'jefe_id'       => post('jefe_id') ?: null,
        'sexo'          => in_array(post('sexo'), ['M', 'F', 'O'], true) ? post('sexo') : null,
        'fecha_ingreso' => post('fecha_ingreso') ?: null,
        'activo'        => isset($_POST['activo']) ? 1 : 0,
    ];
    if ($data['nombre'] === '' || $data['apellido'] === '') {
        $errores[] = 'Nombre y apellido son obligatorios.';
    }
    if (!valid_email($data['email'])) {
        $errores[] = 'Correo electrónico no válido.';
    } elseif (db_val('SELECT id FROM empleados WHERE empresa_id = ? AND email = ? AND id <> ?', [$empresaId, $data['email'], $id])) {
        $errores[] = 'Ya existe un empleado con ese correo en esta empresa.';
    }
    if ($id && (int)$data['jefe_id'] === $id) {
        $errores[] = 'Un empleado no puede ser su propio jefe.';
    }
    if (!$errores) {
        $data['area_id'] = area_id_por_nombre($empresaId, post('area'));
        if ($id) {
            db_update('empleados', $data, 'id = :id', ['id' => $id]);
            audit('editar_empleado', 'empleado', $id);
        } else {
            $id = db_insert('empleados', $data);
            audit('crear_empleado', 'empleado', $id);
        }
        flash('success', 'Empleado guardado.');
        redirect('admin/empleados.php?empresa_id=' . $empresaId);
    }
    $emp = $data;
}
$areaNombre = !empty($emp['area_id']) ? db_val('SELECT nombre FROM areas WHERE id = ?', [$emp['area_id']]) : post('area');
$jefes = db_all("SELECT id, CONCAT(apellido, ', ', nombre, ' (', email, ')') AS nombre FROM empleados WHERE empresa_id = ? AND activo = 1 AND id <> ? ORDER BY apellido, nombre", [$empresaId, $id]);
$areas = db_all('SELECT nombre FROM areas WHERE empresa_id = ? ORDER BY nombre', [$empresaId]);
render_header('Empleado');
page_title(($id ? 'Editar' : 'Nuevo') . ' empleado: ' . $empresa['nombre']);
?>
<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post" class="card"><div class="card-body row g-3">
  <?= csrf_field() ?>
  <div class="col-md-2"><label class="form-label">Código</label><input name="codigo" class="form-control" value="<?= e($emp['codigo'] ?? '') ?>"></div>
  <div class="col-md-5"><label class="form-label">Nombre *</label><input name="nombre" class="form-control" required value="<?= e($emp['nombre'] ?? '') ?>"></div>
  <div class="col-md-5"><label class="form-label">Apellido *</label><input name="apellido" class="form-control" required value="<?= e($emp['apellido'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">Correo electrónico *</label><input type="email" name="email" class="form-control" required value="<?= e($emp['email'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">Jefe inmediato</label><select name="jefe_id" class="form-select"><?= select_options($jefes, $emp['jefe_id'] ?? '', 'id', 'nombre', 'Sin jefe (nivel más alto)') ?></select></div>
  <div class="col-md-4"><label class="form-label">Área</label><input name="area" class="form-control" list="areasList" value="<?= e($areaNombre) ?>">
    <datalist id="areasList"><?php foreach ($areas as $a): ?><option value="<?= e($a['nombre']) ?>"><?php endforeach; ?></datalist></div>
  <div class="col-md-4"><label class="form-label">Puesto</label><input name="puesto" class="form-control" value="<?= e($emp['puesto'] ?? '') ?>"></div>
  <div class="col-md-4"><label class="form-label">Nivel</label><input name="nivel" class="form-control" value="<?= e($emp['nivel'] ?? '') ?>" placeholder="Operativo, Mando medio, Directivo"></div>
  <div class="col-md-3"><label class="form-label">Sexo</label><select name="sexo" class="form-select">
    <option value="">—</option><?php foreach (['M' => 'Masculino', 'F' => 'Femenino', 'O' => 'Otro'] as $k => $v): ?><option value="<?= $k ?>" <?= ($emp['sexo'] ?? '') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label">Fecha de ingreso</label><input type="date" name="fecha_ingreso" class="form-control" value="<?= e($emp['fecha_ingreso'] ?? '') ?>"></div>
  <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="activo" id="activo" <?= ($emp['activo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="activo">Activo</label></div></div>
</div>
<div class="card-footer"><button class="btn btn-primary">Guardar</button> <a class="btn btn-link" href="<?= e(url('admin/empleados.php?empresa_id=' . $empresaId)) ?>">Volver</a></div>
</form>
<?php render_footer();
