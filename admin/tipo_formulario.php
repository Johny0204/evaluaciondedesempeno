<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$id = int_get('id');
$tf = db_one('SELECT * FROM tipos_formulario WHERE id = ?', [$id]);
if (!$tf) {
    redirect('admin/tipos_formulario.php');
}
$back = 'admin/tipo_formulario.php?id=' . $id;
if (is_post()) {
    csrf_check();
    $accion = post('accion');
    if ($accion === 'datos') {
        db_update('tipos_formulario', ['nombre' => post('nombre'), 'descripcion' => post('descripcion') ?: null, 'escala_id' => (int)post('escala_id'), 'activo' => isset($_POST['activo']) ? 1 : 0], 'id = :id', ['id' => $id]);
        flash('success', 'Datos guardados.');
    } elseif ($accion === 'seccion_agregar') {
        db_insert('secciones', ['tipo_formulario_id' => $id, 'nombre' => post('nombre') ?: 'Nueva sección', 'peso' => (float)post('peso', 0), 'orden' => (int)post('orden', 0)]);
    } elseif ($accion === 'secciones_guardar') {
        foreach ((array)($_POST['sec'] ?? []) as $sid => $s) {
            db_update('secciones', ['nombre' => trim($s['nombre']), 'peso' => (float)$s['peso'], 'orden' => (int)$s['orden']], 'id = :id AND tipo_formulario_id = :tf', ['id' => (int)$sid, 'tf' => $id]);
        }
        flash('success', 'Secciones guardadas.');
    } elseif ($accion === 'seccion_eliminar') {
        db_exec('DELETE FROM secciones WHERE id = ? AND tipo_formulario_id = ?', [(int)post('seccion_id'), $id]);
    } elseif ($accion === 'preguntas_guardar') {
        foreach ((array)($_POST['fp'] ?? []) as $fpid => $f) {
            $aplica = array_values(array_intersect((array)($f['aplica_a'] ?? []), RELACIONES));
            db_update('formulario_pregunta', [
                'seccion_id' => ($f['seccion_id'] ?? '') !== '' ? (int)$f['seccion_id'] : null, 'orden' => (int)$f['orden'], 'peso' => (float)$f['peso'],
                'obligatoria' => isset($f['obligatoria']) ? 1 : 0, 'aplica_a' => implode(',', $aplica ?: RELACIONES),
            ], 'id = :id AND tipo_formulario_id = :tf', ['id' => (int)$fpid, 'tf' => $id]);
        }
        flash('success', 'Preguntas actualizadas.');
    } elseif ($accion === 'pregunta_quitar') {
        db_exec('DELETE FROM formulario_pregunta WHERE id = ? AND tipo_formulario_id = ?', [(int)post('fp_id'), $id]);
    } elseif ($accion === 'preguntas_agregar') {
        $sec = post('seccion_id') ?: null;
        $orden = (int)db_val('SELECT COALESCE(MAX(orden), 0) FROM formulario_pregunta WHERE tipo_formulario_id = ?', [$id]);
        foreach ((array)($_POST['pregunta_ids'] ?? []) as $pid) {
            db_exec('INSERT IGNORE INTO formulario_pregunta (tipo_formulario_id, pregunta_id, seccion_id, orden) VALUES (?, ?, ?, ?)', [$id, (int)$pid, $sec, ++$orden]);
        }
        flash('success', 'Preguntas agregadas.');
    }
    audit('editar_tipo_formulario', 'tipo_formulario', $id, $accion);
    redirect($back);
}
$secciones = db_all('SELECT * FROM secciones WHERE tipo_formulario_id = ? ORDER BY orden, id', [$id]);
$fps = db_all('SELECT fp.*, p.texto, p.tipo_respuesta, c.nombre AS categoria FROM formulario_pregunta fp JOIN preguntas p ON p.id = fp.pregunta_id
               LEFT JOIN categorias c ON c.id = p.categoria_id WHERE fp.tipo_formulario_id = ? ORDER BY fp.orden, fp.id', [$id]);
$disponibles = db_all('SELECT p.id, p.texto, c.nombre AS categoria, p.tipo_respuesta FROM preguntas p LEFT JOIN categorias c ON c.id = p.categoria_id
                       WHERE p.activo = 1 AND p.id NOT IN (SELECT pregunta_id FROM formulario_pregunta WHERE tipo_formulario_id = ?) ORDER BY c.nombre, p.texto', [$id]);
$escalas = db_all('SELECT id, nombre FROM escalas WHERE activo = 1 ORDER BY nombre');
$sumaPesos = array_sum(array_column($secciones, 'peso'));
render_header('Formulario ' . $tf['nombre']);
page_title('Tipo de formulario: ' . $tf['nombre'], '<a class="btn btn-outline-secondary" target="_blank" href="' . e(url('admin/vista_previa.php?tipo_formulario_id=' . $id)) . '">Vista previa</a> <a class="btn btn-link" href="' . e(url('admin/tipos_formulario.php')) . '">Volver</a>');
?>
<div class="alert alert-info small">Los cambios aquí no afectan evaluaciones ya publicadas: al publicar, el sistema guarda una copia fija del formulario.</div>
<div class="row g-3 mb-3">
<div class="col-lg-5"><div class="card h-100"><div class="card-header">Datos</div>
  <form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="accion" value="datos">
    <div class="mb-2"><label class="form-label">Nombre</label><input name="nombre" class="form-control" value="<?= e($tf['nombre']) ?>" required></div>
    <div class="mb-2"><label class="form-label">Descripción</label><input name="descripcion" class="form-control" value="<?= e($tf['descripcion']) ?>"></div>
    <div class="mb-2"><label class="form-label">Escala</label><select name="escala_id" class="form-select"><?= select_options($escalas, $tf['escala_id']) ?></select></div>
    <div class="form-check mb-3"><input class="form-check-input" type="checkbox" name="activo" id="ac" <?= $tf['activo'] ? 'checked' : '' ?>><label class="form-check-label" for="ac">Activo</label></div>
    <button class="btn btn-primary btn-sm">Guardar</button></form></div></div>
<div class="col-lg-7"><div class="card h-100"><div class="card-header">Secciones y ponderación <?php if ($sumaPesos != 100): ?><span class="badge text-bg-warning">Suma de pesos: <?= fmt_num($sumaPesos, 0) ?></span><?php endif; ?></div>
  <div class="card-body">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="secciones_guardar">
    <table class="table table-sm"><thead><tr><th>Sección</th><th style="width:90px">Peso %</th><th style="width:80px">Orden</th><th></th></tr></thead><tbody>
    <?php foreach ($secciones as $s): ?>
      <tr><td><input name="sec[<?= $s['id'] ?>][nombre]" class="form-control form-control-sm" value="<?= e($s['nombre']) ?>"></td>
        <td><input name="sec[<?= $s['id'] ?>][peso]" type="number" step="0.01" class="form-control form-control-sm" value="<?= e($s['peso']) ?>"></td>
        <td><input name="sec[<?= $s['id'] ?>][orden]" type="number" class="form-control form-control-sm" value="<?= e($s['orden']) ?>"></td>
        <td><button form="del<?= $s['id'] ?>" class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar la sección? Las preguntas quedarán sin sección.">×</button></td></tr>
    <?php endforeach; ?></tbody></table>
    <button class="btn btn-sm btn-primary">Guardar secciones</button>
  </form>
  <?php foreach ($secciones as $s): ?><form method="post" id="del<?= $s['id'] ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="seccion_eliminar"><input type="hidden" name="seccion_id" value="<?= $s['id'] ?>"></form><?php endforeach; ?>
  <form method="post" class="row g-2 mt-2"><?= csrf_field() ?><input type="hidden" name="accion" value="seccion_agregar">
    <div class="col-6"><input name="nombre" class="form-control form-control-sm" placeholder="Nueva sección"></div>
    <div class="col-2"><input name="peso" type="number" step="0.01" class="form-control form-control-sm" placeholder="Peso"></div>
    <div class="col-2"><input name="orden" type="number" class="form-control form-control-sm" placeholder="Orden"></div>
    <div class="col-2"><button class="btn btn-sm btn-outline-primary w-100">Agregar</button></div></form>
  <p class="small text-muted mt-2 mb-0">Las secciones con peso 0 (p.ej. comentarios) no cuentan en la calificación. Sólo las preguntas de tipo escala suman puntaje.</p>
  </div></div></div>
</div>

<div class="card mb-3"><div class="card-header">Preguntas del formulario (<?= count($fps) ?>)</div>
<form method="post"><?= csrf_field() ?><input type="hidden" name="accion" value="preguntas_guardar">
<div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th style="width:70px">Orden</th><th>Pregunta</th><th>Sección</th><th style="width:80px">Peso</th><th>Oblig.</th><th>Aplica a</th><th></th></tr></thead><tbody>
  <?php foreach ($fps as $f): $ap = explode(',', $f['aplica_a']); ?>
  <tr>
    <td><input name="fp[<?= $f['id'] ?>][orden]" type="number" class="form-control form-control-sm" value="<?= (int)$f['orden'] ?>"></td>
    <td><?= e($f['texto']) ?><div class="small text-muted"><?= e($f['categoria']) ?> · <?= e($f['tipo_respuesta']) ?></div></td>
    <td><select name="fp[<?= $f['id'] ?>][seccion_id]" class="form-select form-select-sm"><?= select_options($secciones, $f['seccion_id'] ?? '', 'id', 'nombre', 'Sin sección') ?></select></td>
    <td><input name="fp[<?= $f['id'] ?>][peso]" type="number" step="0.01" class="form-control form-control-sm" value="<?= e($f['peso']) ?>"></td>
    <td class="text-center"><input type="checkbox" class="form-check-input" name="fp[<?= $f['id'] ?>][obligatoria]" <?= $f['obligatoria'] ? 'checked' : '' ?>></td>
    <td class="small text-nowrap"><?php foreach (RELACIONES as $r): ?>
      <label class="me-1"><input type="checkbox" class="form-check-input" name="fp[<?= $f['id'] ?>][aplica_a][]" value="<?= $r ?>" <?= in_array($r, $ap, true) ? 'checked' : '' ?>> <?= e(etiqueta_relacion($r)) ?></label>
    <?php endforeach; ?></td>
    <td><button form="q<?= $f['id'] ?>" class="btn btn-sm btn-outline-danger" title="Quitar del formulario">×</button></td>
  </tr>
  <?php endforeach; if (!$fps): ?><tr><td colspan="7" class="text-muted text-center">Sin preguntas. Agréguelas abajo o desde el banco de preguntas.</td></tr><?php endif; ?>
  </tbody></table></div>
<?php if ($fps): ?><div class="card-footer"><button class="btn btn-primary btn-sm">Guardar cambios de preguntas</button></div><?php endif; ?>
</form>
<?php foreach ($fps as $f): ?><form method="post" id="q<?= $f['id'] ?>"><?= csrf_field() ?><input type="hidden" name="accion" value="pregunta_quitar"><input type="hidden" name="fp_id" value="<?= $f['id'] ?>"></form><?php endforeach; ?>
</div>

<div class="card"><div class="card-header">Agregar preguntas del banco (<?= count($disponibles) ?> disponibles)</div>
<form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="accion" value="preguntas_agregar">
  <div style="max-height:300px;overflow:auto" class="border rounded p-2 mb-2">
  <?php foreach ($disponibles as $p): ?>
    <div class="form-check"><input class="form-check-input" type="checkbox" name="pregunta_ids[]" value="<?= $p['id'] ?>" id="p<?= $p['id'] ?>">
      <label class="form-check-label" for="p<?= $p['id'] ?>"><?= e($p['texto']) ?> <span class="small text-muted">(<?= e($p['categoria']) ?> · <?= e($p['tipo_respuesta']) ?>)</span></label></div>
  <?php endforeach; if (!$disponibles): ?><span class="text-muted">Todas las preguntas activas ya están en este formulario.</span><?php endif; ?>
  </div>
  <div class="row g-2"><div class="col-md-4"><select name="seccion_id" class="form-select form-select-sm"><?= select_options($secciones, '', 'id', 'nombre', 'Sin sección') ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-primary">Agregar seleccionadas</button></div>
  <div class="col-auto"><a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/preguntas.php')) ?>">Ir al banco de preguntas</a></div></div>
</form></div>
<?php render_footer();
