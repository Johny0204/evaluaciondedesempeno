<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$editId = int_get('id');
$errores = [];
$tipos = ['escala' => 'Escala', 'abierta' => 'Abierta (texto)', 'si_no' => 'Sí / No', 'opcion_multiple' => 'Opción múltiple', 'numerica' => 'Numérica'];

if (is_post()) {
    csrf_check();
    if (post('accion') === 'guardar') {
        $id = (int)post('id');
        $data = [
            'texto'          => post('texto'),
            'texto_auto'     => post('texto_auto') ?: null,
            'categoria_id'   => post('categoria_id') ?: null,
            'tipo_respuesta' => array_key_exists(post('tipo_respuesta'), $tipos) ? post('tipo_respuesta') : 'escala',
            'opciones'       => post('opciones') ?: null,
            'ayuda'          => post('ayuda') ?: null,
            'activo'         => isset($_POST['activo']) ? 1 : 0,
        ];
        if ($data['texto'] === '') {
            $errores[] = 'El texto es obligatorio.';
        }
        if ($data['tipo_respuesta'] === 'opcion_multiple' && !$data['opciones']) {
            $errores[] = 'Indique las opciones (una por línea).';
        }
        if (!$errores) {
            db()->beginTransaction();
            if ($id) {
                db_update('preguntas', $data, 'id = :id', ['id' => $id]);
            } else {
                $id = db_insert('preguntas', $data);
            }
            $sel = array_map('intval', (array)($_POST['tipos_formulario'] ?? []));
            $actuales = array_map('intval', array_column(db_all('SELECT tipo_formulario_id FROM formulario_pregunta WHERE pregunta_id = ?', [$id]), 'tipo_formulario_id'));
            foreach (array_diff($actuales, $sel) as $tf) {
                db_exec('DELETE FROM formulario_pregunta WHERE pregunta_id = ? AND tipo_formulario_id = ?', [$id, $tf]);
            }
            foreach (array_diff($sel, $actuales) as $tf) {
                $sec = db_val('SELECT id FROM secciones WHERE tipo_formulario_id = ? ORDER BY orden, id LIMIT 1', [$tf]);
                $orden = (int)db_val('SELECT COALESCE(MAX(orden), 0) + 1 FROM formulario_pregunta WHERE tipo_formulario_id = ?', [$tf]);
                db_insert('formulario_pregunta', ['tipo_formulario_id' => $tf, 'pregunta_id' => $id, 'seccion_id' => $sec ?: null, 'orden' => $orden,
                    'peso' => $data['tipo_respuesta'] === 'escala' ? 1 : 0, 'obligatoria' => $data['tipo_respuesta'] === 'abierta' ? 0 : 1]);
            }
            db()->commit();
            audit('guardar_pregunta', 'pregunta', $id);
            flash('success', 'Pregunta guardada.');
            redirect('admin/preguntas.php');
        }
    }
}
$q = get('q');
$catF = int_get('categoria_id');
$tfF = int_get('tipo_formulario_id');
$where = '1=1';
$params = [];
if ($q !== '') {
    $where .= ' AND p.texto LIKE ?';
    $params[] = "%$q%";
}
if ($catF) {
    $where .= ' AND p.categoria_id = ?';
    $params[] = $catF;
}
if ($tfF) {
    $where .= ' AND p.id IN (SELECT pregunta_id FROM formulario_pregunta WHERE tipo_formulario_id = ?)';
    $params[] = $tfF;
}
$rows = db_all("SELECT p.*, c.nombre AS categoria,
                (SELECT GROUP_CONCAT(tf.nombre ORDER BY tf.nombre SEPARATOR ', ') FROM formulario_pregunta fp JOIN tipos_formulario tf ON tf.id = fp.tipo_formulario_id WHERE fp.pregunta_id = p.id) AS formularios
                FROM preguntas p LEFT JOIN categorias c ON c.id = p.categoria_id WHERE $where ORDER BY p.activo DESC, c.nombre, p.id", $params);
$edit = $editId ? db_one('SELECT * FROM preguntas WHERE id = ?', [$editId]) : null;
if ($errores) {
    $edit = $_POST;
}
$editTipos = $editId ? array_map('intval', array_column(db_all('SELECT tipo_formulario_id FROM formulario_pregunta WHERE pregunta_id = ?', [$editId]), 'tipo_formulario_id')) : array_map('intval', (array)($_POST['tipos_formulario'] ?? []));
$categorias = db_all('SELECT id, nombre FROM categorias WHERE activo = 1 ORDER BY nombre');
$formularios = db_all('SELECT id, nombre FROM tipos_formulario ORDER BY nombre');
render_header('Banco de preguntas');
page_title('Banco de preguntas');
?>
<?php if ($errores): ?><div class="alert alert-danger"><?= e(implode(' ', $errores)) ?></div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-7">
  <form class="row g-2 mb-2" method="get">
    <div class="col-md-5"><input class="form-control form-control-sm" name="q" value="<?= e($q) ?>" placeholder="Buscar texto"></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="categoria_id"><?= select_options($categorias, $catF ?: '', 'id', 'nombre', 'Todas las categorías') ?></select></div>
    <div class="col-md-3"><select class="form-select form-select-sm" name="tipo_formulario_id"><?= select_options($formularios, $tfF ?: '', 'id', 'nombre', 'Todos los formularios') ?></select></div>
    <div class="col-md-1"><button class="btn btn-sm btn-outline-secondary w-100">Ir</button></div>
  </form>
  <div class="card"><div class="table-responsive" style="max-height:70vh"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>Pregunta</th><th>Categoría</th><th>Tipo</th><th>Formularios</th><th></th></tr></thead><tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['activo'] ? '' : 'text-muted' ?>"><td><?= e($r['texto']) ?></td><td class="small"><?= e($r['categoria']) ?></td><td class="small"><?= e($tipos[$r['tipo_respuesta']]) ?></td>
        <td class="small"><?= e($r['formularios']) ?></td><td><a class="btn btn-sm btn-outline-primary" href="?id=<?= $r['id'] ?>">Editar</a></td></tr>
    <?php endforeach; ?>
    </tbody></table></div></div>
</div>
<div class="col-lg-5"><div class="card"><div class="card-header"><?= !empty($edit['id']) ? 'Editar pregunta' : 'Nueva pregunta' ?></div>
<form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?= e($edit['id'] ?? '') ?>">
  <div class="mb-2"><label class="form-label">Texto (para evaluar a otra persona) *</label><textarea name="texto" class="form-control" rows="2" required><?= e($edit['texto'] ?? '') ?></textarea></div>
  <div class="mb-2"><label class="form-label">Texto para autoevaluación</label><textarea name="texto_auto" class="form-control" rows="2" placeholder="Opcional. Ej: Cumplo con..."><?= e($edit['texto_auto'] ?? '') ?></textarea></div>
  <div class="row g-2 mb-2">
    <div class="col-6"><label class="form-label">Categoría</label><select name="categoria_id" class="form-select"><?= select_options($categorias, $edit['categoria_id'] ?? '', 'id', 'nombre', 'Sin categoría') ?></select></div>
    <div class="col-6"><label class="form-label">Tipo de respuesta</label><select name="tipo_respuesta" class="form-select">
      <?php foreach ($tipos as $k => $v): ?><option value="<?= $k ?>" <?= ($edit['tipo_respuesta'] ?? 'escala') === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  </div>
  <div class="mb-2"><label class="form-label">Opciones (opción múltiple, una por línea)</label><textarea name="opciones" class="form-control" rows="2"><?= e($edit['opciones'] ?? '') ?></textarea></div>
  <div class="mb-2"><label class="form-label">Ayuda para el evaluador</label><input name="ayuda" class="form-control" value="<?= e($edit['ayuda'] ?? '') ?>"></div>
  <div class="mb-2"><label class="form-label">Tipos de formulario a los que pertenece</label>
    <?php foreach ($formularios as $f): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="tipos_formulario[]" value="<?= $f['id'] ?>" id="tf<?= $f['id'] ?>" <?= in_array((int)$f['id'], $editTipos, true) ? 'checked' : '' ?>>
      <label class="form-check-label" for="tf<?= $f['id'] ?>"><?= e($f['nombre']) ?></label></div><?php endforeach; ?>
    <div class="form-text">Sección, orden, peso y a quién aplica se ajustan en cada tipo de formulario.</div></div>
  <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="activo" id="act" <?= ($edit['activo'] ?? 1) ? 'checked' : '' ?>><label class="form-check-label" for="act">Activa</label></div>
  <button class="btn btn-primary">Guardar</button> <a class="btn btn-link" href="preguntas.php">Nueva</a>
</form></div></div>
</div>
<?php render_footer();
