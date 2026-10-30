<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_login();
$error = null;
if (is_post()) {
    csrf_check();
    $actual = (string)($_POST['actual'] ?? '');
    $nueva = (string)($_POST['nueva'] ?? '');
    $conf = (string)($_POST['confirmar'] ?? '');
    if (!password_verify($actual, $u['password_hash'])) {
        $error = 'La contraseña actual no es correcta.';
    } elseif ($nueva !== $conf) {
        $error = 'La confirmación no coincide.';
    } elseif ($err = password_policy_error($nueva)) {
        $error = $err;
    } elseif (password_verify($nueva, $u['password_hash'])) {
        $error = 'La nueva contraseña debe ser distinta a la actual.';
    } else {
        db_update('usuarios', ['password_hash' => password_hash($nueva, PASSWORD_DEFAULT), 'debe_cambiar_password' => 0], 'id = :id', ['id' => $u['id']]);
        audit('cambio_password', 'usuario', (int)$u['id']);
        flash('success', 'Contraseña actualizada.');
        redirect('admin/index.php');
    }
}
render_header('Cambiar contraseña');
?>
<div class="row justify-content-center"><div class="col-md-6 col-lg-4">
  <div class="card p-4">
    <h1 class="h5">Cambiar contraseña</h1>
    <?php if ($u['debe_cambiar_password']): ?><div class="alert alert-info">Por seguridad, debe cambiar su contraseña antes de continuar.</div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
    <form method="post">
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label">Contraseña actual</label><input type="password" name="actual" class="form-control" required></div>
      <div class="mb-3"><label class="form-label">Nueva contraseña</label><input type="password" name="nueva" class="form-control" required minlength="8">
        <div class="form-text">Mínimo 8 caracteres, con mayúsculas, minúsculas y números.</div></div>
      <div class="mb-3"><label class="form-label">Confirmar nueva contraseña</label><input type="password" name="confirmar" class="form-control" required></div>
      <button class="btn btn-primary w-100">Guardar</button>
    </form>
  </div>
</div></div>
<?php render_footer();
