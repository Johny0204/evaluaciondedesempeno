<?php
require __DIR__ . '/../app/bootstrap.php';
$me = require_superadmin();
$editId = int_get('id');
$errores = [];
$passTemporal = null;

if (is_post()) {
    csrf_check();
    $accion = post('accion');
    if ($accion === 'guardar') {
        $id = (int)post('id');
        $data = [
            'nombre'               => post('nombre'),
            'email'                => strtolower(post('email')),
            'rol'                  => in_array(post('rol'), ['superadmin', 'consultor', 'consulta'], true) ? post('rol') : 'consultor',
            'empresa_id'           => post('empresa_id') ?: null,
            'puede_ver_individual' => isset($_POST['puede_ver_individual']) ? 1 : 0,
            'activo'               => isset($_POST['activo']) ? 1 : 0,
        ];
        if ($data['rol'] !== 'consulta') {
            $data['empresa_id'] = null;
            $data['puede_ver_individual'] = 1;
        }
        if ($data['nombre'] === '' || !valid_email($data['email'])) {
            $errores[] = 'Nombre y correo válido son obligatorios.';
        }
        if ($data['rol'] === 'consulta' && !$data['empresa_id']) {
            $errores[] = 'El usuario de consulta debe tener una empresa asignada.';
        }
        if (db_val('SELECT id FROM usuarios WHERE email = ? AND id <> ?', [$data['email'], $id])) {
            $errores[] = 'Ya existe un usuario con ese correo.';
        }
        if ($id === (int)$me['id'] && ($data['rol'] !== 'superadmin' || !$data['activo'])) {
            $errores[] = 'No puede quitarse a sí mismo el rol de Super Administrador ni desactivarse.';
        }
        if (!$errores) {
            if ($id) {
                db_update('usuarios', $data, 'id = :id', ['id' => $id]);
                audit('editar_usuario', 'usuario', $id);
                flash('success', 'Usuario actualizado.');
                redirect('admin/usuarios.php');
            }
            $passTemporal = codigo_aleatorio(4) . '-' . codigo_aleatorio(4) . random_int(10, 99);
            $data['password_hash'] = password_hash($passTemporal, PASSWORD_DEFAULT);
            $data['debe_cambiar_password'] = 1;
            $id = db_insert('usuarios', $data);
            audit('crear_usuario', 'usuario', $id);
            $html = plantilla_correo('Su acceso a la plataforma', '<p>Hola ' . e($data['nombre']) . ',</p><p>Se creó su usuario.</p><p><strong>Enlace:</strong> ' . e(url('admin/login.php'))
                . '<br><strong>Usuario:</strong> ' . e($data['email']) . '<br><strong>Contraseña temporal:</strong> ' . e($passTemporal) . '</p>');
            correo_encolar($data['email'], 'Su acceso a la plataforma de evaluación', $html, 'usuario', null, null, true);
            flash('success', 'Usuario creado. Se envió la contraseña temporal por correo.');
        }
    } elseif ($accion === 'reset') {
        $id = (int)post('id');
        $usr = db_one('SELECT * FROM usuarios WHERE id = ?', [$id]);
        if ($usr) {
            $passTemporal = codigo_aleatorio(4) . '-' . codigo_aleatorio(4) . random_int(10, 99);
            db_update('usuarios', ['password_hash' => password_hash($passTemporal, PASSWORD_DEFAULT), 'debe_cambiar_password' => 1, 'intentos_fallidos' => 0, 'bloqueado_hasta' => null], 'id = :id', ['id' => $id]);
            $html = plantilla_correo('Restablecimiento de contraseña', '<p>Hola ' . e($usr['nombre']) . ',</p><p>Su nueva contraseña temporal es: <strong>' . e($passTemporal) . '</strong></p><p>' . e(url('admin/login.php')) . '</p>');
            correo_encolar($usr['email'], 'Restablecimiento de contraseña', $html, 'usuario', null, null, true);
            audit('reset_password', 'usuario', $id);
            flash('success', 'Contraseña restablecida y enviada por correo a ' . $usr['email'] . '.');
        }
    }
}

$usuarios = db_all('SELECT us.*, em.nombre AS empresa FROM usuarios us LEFT JOIN empresas em ON em.id = us.empresa_id ORDER BY us.rol, us.nombre');
$edit = $editId ? db_one('SELECT * FROM usuarios WHERE id = ?', [$editId]) : null;
if ($errores) {
    $edit = array_merge($edit ?? [], $_POST);
}
$empresas = db_all('SELECT id, nombre FROM empresas WHERE activo = 1 ORDER BY nombre');
$roles = ['superadmin' => 'Super Administrador', 'consultor' => 'Consultor', 'consulta' => 'Consulta (cliente)'];
render_header('Usuarios');
page_title('Usuarios con acceso');
?>
<?php if ($errores): ?><div class="alert alert-danger"><?= e(implode(' ', $errores)) ?></div><?php endif; ?>
<?php if ($passTemporal && config('mostrar_codigos', config('modo_desarrollo'))): ?><div class="alert alert-info">Modo desarrollo &mdash; contraseña temporal: <code><?= e($passTemporal) ?></code></div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th>Nombre</th><th>Correo</th><th>Rol</th><th>Empresa</th><th>Último acceso</th><th>Estatus</th><th></th></tr></thead><tbody>
  <?php foreach ($usuarios as $r): ?>
  <tr><td><?= e($r['nombre']) ?></td><td><?= e($r['email']) ?></td><td><?= e($roles[$r['rol']]) ?></td><td><?= e($r['empresa']) ?></td>
    <td><?= fmt_fecha($r['ultimo_acceso'], true) ?></td><td><?= $r['activo'] ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>' ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="?id=<?= $r['id'] ?>">Editar</a>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
      <button name="accion" value="reset" class="btn btn-sm btn-outline-warning" data-confirm="¿Restablecer la contraseña de este usuario?">Restablecer clave</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-header"><?= $edit && !empty($edit['id']) ? 'Editar usuario' : 'Nuevo usuario' ?></div>
<form method="post" class="card-body">
  <?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
  <div class="mb-2"><label class="form-label">Nombre</label><input name="nombre" class="form-control" required value="<?= e($edit['nombre'] ?? '') ?>"></div>
  <div class="mb-2"><label class="form-label">Correo</label><input type="email" name="email" class="form-control" required value="<?= e($edit['email'] ?? '') ?>"></div>
  <div class="mb-2"><label class="form-label">Rol</label><select name="rol" class="form-select">
    <?php foreach ($roles as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['rol'] ?? 'consultor') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="mb-2"><label class="form-label">Empresa (sólo rol consulta)</label><select name="empresa_id" class="form-select"><?= select_options($empresas, $edit['empresa_id'] ?? '', 'id', 'nombre', '—') ?></select></div>
  <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="puede_ver_individual" id="pvi" <?= !empty($edit['puede_ver_individual']) ? 'checked' : '' ?>><label class="form-check-label" for="pvi">Consulta: puede ver resultados individuales</label></div>
  <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="activo" id="act" <?= ($edit['activo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="act">Activo</label></div>
  <button class="btn btn-primary">Guardar</button> <a href="usuarios.php" class="btn btn-link">Nuevo</a>
</form></div></div>
</div>
<?php render_footer();
