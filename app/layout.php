<?php

function render_header(string $title, array $opts = []): void
{
    $u = current_user();
    $public = $opts['public'] ?? false;
    $flashes = take_flashes();
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    $active = function (string $needle) use ($script): string {
        return strpos($script, $needle) !== false ? ' active' : '';
    };
    ?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<title><?= e($title) ?> | <?= e(config('app_nombre')) ?></title>
<link rel="stylesheet" href="<?= e(url('assets/css/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
</head>
<body class="<?= $public ? 'public-body' : '' ?>">
<?php if (!$public && $u): ?>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary-dark mb-4">
  <div class="container-fluid">
    <a class="navbar-brand" href="<?= e(url('admin/index.php')) ?>"><?= e(config('app_nombre')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link<?= $active('/admin/index.php') ?>" href="<?= e(url('admin/index.php')) ?>">Inicio</a></li>
        <?php if (is_staff($u)): ?>
        <li class="nav-item"><a class="nav-link<?= $active('empresa') ?>" href="<?= e(url('admin/empresas.php')) ?>">Empresas</a></li>
        <li class="nav-item"><a class="nav-link<?= $active('evaluacion') ?>" href="<?= e(url('admin/evaluaciones.php')) ?>">Evaluaciones</a></li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle<?= $active('formulario') . $active('pregunta') . $active('catalogos') ?>" href="#" data-bs-toggle="dropdown">Formularios</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item" href="<?= e(url('admin/tipos_formulario.php')) ?>">Tipos de formulario</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/preguntas.php')) ?>">Banco de preguntas</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/catalogos.php')) ?>">Categorías, escalas y tipos de empresa</a></li>
          </ul>
        </li>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown">Sistema</a>
          <ul class="dropdown-menu">
            <?php if ($u['rol'] === 'superadmin'): ?>
            <li><a class="dropdown-item" href="<?= e(url('admin/usuarios.php')) ?>">Usuarios</a></li>
            <?php endif; ?>
            <li><a class="dropdown-item" href="<?= e(url('admin/correos.php')) ?>">Cola de correos</a></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/api_tokens.php')) ?>">Tokens API</a></li>
            <?php if ($u['rol'] === 'superadmin'): ?>
            <li><a class="dropdown-item" href="<?= e(url('admin/auditoria.php')) ?>">Bitácora</a></li>
            <?php endif; ?>
          </ul>
        </li>
        <?php endif; ?>
      </ul>
      <ul class="navbar-nav">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><?= e($u['nombre']) ?></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted"><?= e($u['email']) ?></span></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/cambiar_password.php')) ?>">Cambiar contraseña</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="<?= e(url('admin/logout.php')) ?>">Cerrar sesión</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>
<?php endif; ?>
<main class="<?= $public ? 'container py-4 public-container' : 'container-fluid px-4' ?>">
<?php foreach ($flashes as $f): ?>
  <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show"><?= e($f['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endforeach; ?>
<?php
}

function render_footer(array $scripts = []): void
{
    ?>
</main>
<footer class="text-center text-muted small py-4"><?= e(config('consultora')) ?> &middot; <?= date('Y') ?></footer>
<script src="<?= e(url('assets/js/bootstrap.bundle.min.js')) ?>"></script>
<?php foreach ($scripts as $s): ?>
<script src="<?= e(url($s)) ?>"></script>
<?php endforeach; ?>
<script src="<?= e(url('assets/js/app.js')) ?>"></script>
</body>
</html>
<?php
}

function page_title(string $title, string $actions = ''): void
{
    echo '<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2"><h1 class="h3 mb-0">'
        . e($title) . '</h1><div>' . $actions . '</div></div>';
}

function select_options(array $rows, $selected, string $valueKey = 'id', string $labelKey = 'nombre', string $empty = ''): string
{
    $html = $empty !== '' ? '<option value="">' . e($empty) . '</option>' : '';
    foreach ($rows as $r) {
        $v = (string)$r[$valueKey];
        $sel = ((string)$selected === $v) ? ' selected' : '';
        $html .= '<option value="' . e($v) . '"' . $sel . '>' . e($r[$labelKey]) . '</option>';
    }
    return $html;
}
