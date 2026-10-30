<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
if (is_post()) {
    csrf_check();
    $a = post('accion');
    try {
        if ($a === 'categoria_agregar' && post('nombre') !== '') {
            db_insert('categorias', ['nombre' => post('nombre'), 'descripcion' => post('descripcion') ?: null]);
        } elseif ($a === 'categoria_toggle') {
            db_exec('UPDATE categorias SET activo = 1 - activo WHERE id = ?', [(int)post('id')]);
        } elseif ($a === 'tipo_empresa_agregar' && post('nombre') !== '') {
            db_insert('tipos_empresa', ['nombre' => post('nombre')]);
        } elseif ($a === 'tipo_empresa_toggle') {
            db_exec('UPDATE tipos_empresa SET activo = 1 - activo WHERE id = ?', [(int)post('id')]);
        } elseif ($a === 'escala_agregar' && post('nombre') !== '') {
            $eid = db_insert('escalas', ['nombre' => post('nombre'), 'descripcion' => post('descripcion') ?: null]);
            $orden = 0;
            foreach (preg_split('/\r?\n/', (string)post('valores')) as $line) {
                if (preg_match('/^\s*([\d.]+)\s*[=:|-]\s*(.+)$/u', $line, $m)) {
                    db_insert('escala_valores', ['escala_id' => $eid, 'valor' => (float)$m[1], 'etiqueta' => trim($m[2]), 'orden' => ++$orden]);
                }
            }
        }
        flash('success', 'Guardado.');
    } catch (PDOException $ex) {
        flash('danger', 'No se pudo guardar (¿nombre duplicado?).');
    }
    redirect('admin/catalogos.php');
}
$cats = db_all('SELECT * FROM categorias ORDER BY nombre');
$tes = db_all('SELECT * FROM tipos_empresa ORDER BY nombre');
$escalas = db_all("SELECT es.*, (SELECT GROUP_CONCAT(CONCAT(TRIM(TRAILING '.00' FROM valor), '=', etiqueta) ORDER BY orden SEPARATOR ' · ') FROM escala_valores v WHERE v.escala_id = es.id) AS valores FROM escalas es ORDER BY nombre");
render_header('Catálogos');
page_title('Catálogos');
$toggle = fn($accion, $id, $activo) => '<form method="post" class="d-inline">' . csrf_field() . '<input type="hidden" name="accion" value="' . $accion . '"><input type="hidden" name="id" value="' . $id . '"><button class="btn btn-sm ' . ($activo ? 'btn-outline-secondary">Desactivar' : 'btn-outline-success">Activar') . '</button></form>';
?>
<div class="row g-3">
<div class="col-lg-4"><div class="card"><div class="card-header">Categorías / competencias</div>
  <ul class="list-group list-group-flush"><?php foreach ($cats as $c): ?><li class="list-group-item d-flex justify-content-between align-items-center <?= $c['activo'] ? '' : 'text-muted' ?>"><?= e($c['nombre']) ?> <?= $toggle('categoria_toggle', $c['id'], $c['activo']) ?></li><?php endforeach; ?></ul>
  <form method="post" class="card-body d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="accion" value="categoria_agregar"><input name="nombre" class="form-control form-control-sm" placeholder="Nueva categoría" required><button class="btn btn-sm btn-primary">Agregar</button></form>
</div></div>
<div class="col-lg-4"><div class="card"><div class="card-header">Escalas de calificación</div>
  <ul class="list-group list-group-flush"><?php foreach ($escalas as $s): ?><li class="list-group-item"><strong><?= e($s['nombre']) ?></strong><div class="small text-muted"><?= e($s['valores']) ?></div></li><?php endforeach; ?></ul>
  <form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="accion" value="escala_agregar">
    <input name="nombre" class="form-control form-control-sm mb-2" placeholder="Nombre de la escala" required>
    <textarea name="valores" class="form-control form-control-sm mb-2" rows="4" placeholder="1=Deficiente&#10;2=Regular&#10;3=Bueno&#10;4=Excelente" required></textarea>
    <button class="btn btn-sm btn-primary">Crear escala</button></form>
</div></div>
<div class="col-lg-4"><div class="card"><div class="card-header">Tipos de empresa</div>
  <ul class="list-group list-group-flush"><?php foreach ($tes as $t): ?><li class="list-group-item d-flex justify-content-between align-items-center <?= $t['activo'] ? '' : 'text-muted' ?>"><?= e($t['nombre']) ?> <?= $toggle('tipo_empresa_toggle', $t['id'], $t['activo']) ?></li><?php endforeach; ?></ul>
  <form method="post" class="card-body d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="accion" value="tipo_empresa_agregar"><input name="nombre" class="form-control form-control-sm" placeholder="Nuevo tipo" required><button class="btn btn-sm btn-primary">Agregar</button></form>
</div></div>
</div>
<?php render_footer();
