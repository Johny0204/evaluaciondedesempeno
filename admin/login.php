<?php
require __DIR__ . '/../app/bootstrap.php';

if (current_user()) {
    redirect('admin/index.php');
}
$error = null;
$email = '';
if (is_post()) {
    csrf_check();
    $email = strtolower(post('email'));
    if (rate_limited('login', 20, 900)) {
        $error = 'Demasiados intentos desde su conexión. Espere unos minutos.';
    } else {
        rate_hit('login');
        [$ok, $res] = attempt_login($email, (string)($_POST['password'] ?? ''));
        if ($ok) {
            audit('login', 'usuario', (int)$res['id']);
            redirect('admin/index.php');
        }
        $error = $res;
    }
}
render_header('Iniciar sesión', ['public' => true]);
?>
<div class="row justify-content-center">
  <div class="col-md-6 col-lg-5">
    <h1 class="text-white h4 text-center mb-4"><?= e(config('app_nombre')) ?></h1>
    <div class="card public-card p-4">
      <h2 class="h5 mb-3">Iniciar sesión</h2>
      <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post" autocomplete="on">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label">Correo electrónico</label>
          <input type="email" name="email" class="form-control" value="<?= e($email) ?>" required autofocus></div>
        <div class="mb-3"><label class="form-label">Contraseña</label>
          <input type="password" name="password" class="form-control" required></div>
        <button class="btn btn-primary w-100">Ingresar</button>
      </form>
      <p class="small text-muted mt-3 mb-0">Acceso para personal de la consultora y usuarios de consulta de empresas cliente.
        Si usted va a responder una evaluación, utilice el enlace que recibió por correo.</p>
    </div>
  </div>
</div>
<?php render_footer();
