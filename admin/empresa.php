<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$id = int_get('id');
$empresa = $id ? db_one('SELECT * FROM empresas WHERE id = ?', [$id]) : null;
if ($id && !$empresa) {
    flash('danger', 'Empresa no encontrada.');
    redirect('admin/empresas.php');
}
$contactos = $id ? db_all('SELECT c.*, us.email AS usuario_email, us.activo AS usuario_activo FROM empresa_contactos c LEFT JOIN usuarios us ON us.id = c.usuario_id WHERE c.empresa_id = ? ORDER BY c.id', [$id]) : [];
$errores = [];

function crear_usuario_consulta(int $empresaId, string $nombre, string $email): ?int
{
    if (db_val('SELECT id FROM usuarios WHERE email = ?', [$email])) {
        flash('warning', "Ya existe un usuario con el correo $email; no se creó otro.");
        return (int)db_val('SELECT id FROM usuarios WHERE email = ?', [$email]);
    }
    $pass = codigo_aleatorio(4) . '-' . codigo_aleatorio(4) . random_int(10, 99);
    $uid = db_insert('usuarios', [
        'nombre' => $nombre, 'email' => $email, 'password_hash' => password_hash($pass, PASSWORD_DEFAULT),
        'rol' => 'consulta', 'empresa_id' => $empresaId, 'debe_cambiar_password' => 1,
    ]);
    $html = plantilla_correo('Acceso a la plataforma de evaluación de desempeño',
        '<p>Hola ' . e($nombre) . ',</p><p>Se creó su usuario de consulta para revisar el avance y los resultados de las evaluaciones de su organización.</p>'
        . '<p><strong>Enlace:</strong> <a href="' . e(url('admin/login.php')) . '">' . e(url('admin/login.php')) . '</a><br>'
        . '<strong>Usuario:</strong> ' . e($email) . '<br><strong>Contraseña temporal:</strong> ' . e($pass) . '</p>'
        . '<p>Deberá cambiar la contraseña en su primer ingreso.</p>');
    correo_encolar($email, 'Su acceso a la plataforma de evaluación', $html, 'usuario', null, null, true);
    audit('crear_usuario_consulta', 'usuario', $uid, ['email' => $email]);
    return $uid;
}

if (is_post()) {
    csrf_check();
    $data = [
        'nombre'           => post('nombre'),
        'nombre_comercial' => post('nombre_comercial') ?: null,
        'nit'              => normalizar_nit(post('nit')),
        'tipo_empresa_id'  => post('tipo_empresa_id') ?: null,
        'sector'           => post('sector') ?: null,
        'direccion'        => post('direccion') ?: null,
        'consultor_id'     => post('consultor_id') ?: null,
        'activo'           => isset($_POST['activo']) ? 1 : 0,
    ];
    if ($data['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if ($data['nit'] === '') {
        $errores[] = 'El NIT es obligatorio.';
    } elseif (config('validar_nit') && !validar_nit($data['nit'])) {
        $errores[] = 'El NIT no es válido (dígito verificador incorrecto).';
    } elseif (db_val('SELECT id FROM empresas WHERE nit = ? AND id <> ?', [$data['nit'], $id])) {
        $errores[] = 'Ya existe otra empresa con ese NIT.';
    }
    $cs = [];
    foreach ((array)($_POST['contacto'] ?? []) as $c) {
        $c = array_map(fn($v) => is_string($v) ? trim($v) : $v, (array)$c);
        if (($c['nombre'] ?? '') === '' && ($c['email'] ?? '') === '') {
            continue;
        }
        if (($c['nombre'] ?? '') === '' || !valid_email($c['email'] ?? '')) {
            $errores[] = 'Cada contacto debe tener nombre y un correo válido.';
            continue;
        }
        $cs[] = $c;
    }
    if (count($cs) < 2) {
        $errores[] = 'Debe registrar al menos 2 contactos.';
    }
    if (!$errores) {
        db()->beginTransaction();
        if ($id) {
            db_update('empresas', $data, 'id = :id', ['id' => $id]);
            audit('editar_empresa', 'empresa', $id);
        } else {
            $id = db_insert('empresas', $data);
            audit('crear_empresa', 'empresa', $id);
        }
        $existentes = array_column(db_all('SELECT id FROM empresa_contactos WHERE empresa_id = ?', [$id]), 'id');
        $conservados = [];
        foreach ($cs as $c) {
            $row = ['empresa_id' => $id, 'nombre' => $c['nombre'], 'puesto' => $c['puesto'] ?? null, 'email' => strtolower($c['email']), 'telefono' => $c['telefono'] ?? null];
            $cid = (int)($c['id'] ?? 0);
            if ($cid && in_array($cid, array_map('intval', $existentes), true)) {
                db_update('empresa_contactos', $row, 'id = :id', ['id' => $cid]);
            } else {
                $cid = db_insert('empresa_contactos', $row);
            }
            $conservados[] = $cid;
            if (!empty($c['crear_usuario'])) {
                $tiene = db_val('SELECT usuario_id FROM empresa_contactos WHERE id = ?', [$cid]);
                if (!$tiene) {
                    $uid = crear_usuario_consulta($id, $c['nombre'], strtolower($c['email']));
                    db_update('empresa_contactos', ['usuario_id' => $uid], 'id = :id', ['id' => $cid]);
                }
            }
        }
        foreach ($existentes as $old) {
            if (!in_array((int)$old, $conservados, true)) {
                db_exec('DELETE FROM empresa_contactos WHERE id = ?', [$old]);
            }
        }
        db()->commit();
        flash('success', 'Empresa guardada.');
        redirect('admin/empresa.php?id=' . $id);
    }
    $empresa = array_merge($empresa ?? [], $data);
    $contactos = $cs;
}
while (count($contactos) < 2) {
    $contactos[] = [];
}
$tipos = db_all('SELECT id, nombre FROM tipos_empresa WHERE activo = 1 ORDER BY nombre');
$consultores = db_all("SELECT id, nombre FROM usuarios WHERE rol IN ('superadmin','consultor') AND activo = 1 ORDER BY nombre");
render_header($id ? 'Editar empresa' : 'Nueva empresa');
page_title($id ? 'Empresa: ' . $empresa['nombre'] : 'Nueva empresa',
    $id ? '<a class="btn btn-outline-secondary" href="' . e(url('admin/empleados.php?empresa_id=' . $id)) . '">Empleados</a> <a class="btn btn-outline-success" href="' . e(url('admin/evaluacion.php?empresa_id=' . $id)) . '">+ Nueva evaluación</a>' : '');
?>
<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post">
<?= csrf_field() ?>
<div class="card mb-3"><div class="card-header">Datos generales</div><div class="card-body row g-3">
  <div class="col-md-6"><label class="form-label">Nombre de la empresa *</label><input name="nombre" class="form-control" required value="<?= e($empresa['nombre'] ?? '') ?>"></div>
  <div class="col-md-6"><label class="form-label">Nombre comercial</label><input name="nombre_comercial" class="form-control" value="<?= e($empresa['nombre_comercial'] ?? '') ?>"></div>
  <div class="col-md-3"><label class="form-label">NIT *</label><input name="nit" class="form-control" required value="<?= e($empresa['nit'] ?? '') ?>" placeholder="1234567-8"></div>
  <div class="col-md-3"><label class="form-label">Tipo de empresa</label><select name="tipo_empresa_id" class="form-select"><?= select_options($tipos, $empresa['tipo_empresa_id'] ?? '', 'id', 'nombre', 'Seleccione') ?></select></div>
  <div class="col-md-3"><label class="form-label">Sector / industria</label><input name="sector" class="form-control" value="<?= e($empresa['sector'] ?? '') ?>"></div>
  <div class="col-md-3"><label class="form-label">Consultor responsable</label><select name="consultor_id" class="form-select"><?= select_options($consultores, $empresa['consultor_id'] ?? '', 'id', 'nombre', 'Sin asignar') ?></select></div>
  <div class="col-md-9"><label class="form-label">Dirección</label><input name="direccion" class="form-control" value="<?= e($empresa['direccion'] ?? '') ?>"></div>
  <div class="col-md-3 d-flex align-items-end"><div class="form-check"><input class="form-check-input" type="checkbox" name="activo" id="activo" <?= ($empresa['activo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="activo">Activa</label></div></div>
</div></div>

<div class="card mb-3"><div class="card-header d-flex justify-content-between align-items-center">Contactos (mínimo 2)
  <button type="button" class="btn btn-sm btn-outline-primary" id="agregarContacto">+ Agregar contacto</button></div>
<div class="card-body" id="contactos">
<?php foreach ($contactos as $i => $c): ?>
  <div class="row g-2 mb-2 contacto">
    <input type="hidden" name="contacto[<?= $i ?>][id]" value="<?= e($c['id'] ?? '') ?>">
    <div class="col-md-3"><input name="contacto[<?= $i ?>][nombre]" class="form-control" placeholder="Nombre" value="<?= e($c['nombre'] ?? '') ?>"></div>
    <div class="col-md-2"><input name="contacto[<?= $i ?>][puesto]" class="form-control" placeholder="Puesto" value="<?= e($c['puesto'] ?? '') ?>"></div>
    <div class="col-md-3"><input type="email" name="contacto[<?= $i ?>][email]" class="form-control" placeholder="Correo electrónico" value="<?= e($c['email'] ?? '') ?>"></div>
    <div class="col-md-2"><input name="contacto[<?= $i ?>][telefono]" class="form-control" placeholder="Teléfono" value="<?= e($c['telefono'] ?? '') ?>"></div>
    <div class="col-md-2 d-flex align-items-center">
      <?php if (!empty($c['usuario_id'])): ?>
        <span class="badge text-bg-info">Usuario de consulta</span>
      <?php else: ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="contacto[<?= $i ?>][crear_usuario]" value="1" id="cu<?= $i ?>">
        <label class="form-check-label small" for="cu<?= $i ?>">Crear usuario de consulta</label></div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<div class="card-footer small text-muted">El usuario de consulta recibe por correo una contraseña temporal y sólo puede ver estadísticas y reportes de esta empresa.</div>
</div>
<button class="btn btn-primary">Guardar empresa</button>
<a class="btn btn-link" href="<?= e(url('admin/empresas.php')) ?>">Volver</a>
</form>
<script>
document.getElementById('agregarContacto').addEventListener('click', function () {
  var cont = document.getElementById('contactos');
  var rows = cont.querySelectorAll('.contacto');
  var clone = rows[rows.length - 1].cloneNode(true);
  var idx = Date.now();
  clone.querySelectorAll('input').forEach(function (inp) {
    inp.name = inp.name.replace(/contacto\[\d+\]/, 'contacto[' + idx + ']');
    if (inp.type === 'checkbox') { inp.checked = false; inp.id = 'cu' + idx; } else { inp.value = ''; }
  });
  var badge = clone.querySelector('.badge');
  if (badge) {
    badge.parentNode.innerHTML = '<div class="form-check"><input class="form-check-input" type="checkbox" name="contacto[' + idx + '][crear_usuario]" value="1" id="cu' + idx + '"><label class="form-check-label small" for="cu' + idx + '">Crear usuario de consulta</label></div>';
  }
  var lbl = clone.querySelector('label.form-check-label'); if (lbl) { lbl.setAttribute('for', 'cu' + idx); }
  cont.appendChild(clone);
});
</script>
<?php render_footer();
