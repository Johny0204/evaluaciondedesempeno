<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
if (is_post()) {
    csrf_check();
    $accion = post('accion');
    if ($accion === 'crear') {
        $nombre = post('nombre');
        if ($nombre === '' || db_val('SELECT id FROM tipos_formulario WHERE nombre = ?', [$nombre])) {
            flash('danger', 'Nombre vacío o duplicado.');
        } else {
            $id = db_insert('tipos_formulario', ['nombre' => $nombre, 'descripcion' => post('descripcion') ?: null, 'escala_id' => (int)post('escala_id')]);
            db_insert('secciones', ['tipo_formulario_id' => $id, 'nombre' => 'Competencias', 'peso' => 100, 'orden' => 1]);
            audit('crear_tipo_formulario', 'tipo_formulario', $id);
            redirect('admin/tipo_formulario.php?id=' . $id);
        }
    } elseif ($accion === 'duplicar') {
        $orig = db_one('SELECT * FROM tipos_formulario WHERE id = ?', [(int)post('id')]);
        if ($orig) {
            db()->beginTransaction();
            $nombre = $orig['nombre'] . ' (copia ' . date('His') . ')';
            $id = db_insert('tipos_formulario', ['nombre' => $nombre, 'descripcion' => $orig['descripcion'], 'escala_id' => $orig['escala_id']]);
            $map = [];
            foreach (db_all('SELECT * FROM secciones WHERE tipo_formulario_id = ?', [$orig['id']]) as $s) {
                $map[$s['id']] = db_insert('secciones', ['tipo_formulario_id' => $id, 'nombre' => $s['nombre'], 'peso' => $s['peso'], 'orden' => $s['orden']]);
            }
            foreach (db_all('SELECT * FROM formulario_pregunta WHERE tipo_formulario_id = ?', [$orig['id']]) as $fp) {
                db_insert('formulario_pregunta', ['tipo_formulario_id' => $id, 'pregunta_id' => $fp['pregunta_id'], 'seccion_id' => $map[$fp['seccion_id']] ?? null,
                    'orden' => $fp['orden'], 'peso' => $fp['peso'], 'obligatoria' => $fp['obligatoria'], 'aplica_a' => $fp['aplica_a']]);
            }
            db()->commit();
            flash('success', 'Formulario duplicado.');
            redirect('admin/tipo_formulario.php?id=' . $id);
        }
    }
    redirect('admin/tipos_formulario.php');
}
$rows = db_all('SELECT tf.*, es.nombre AS escala, (SELECT COUNT(*) FROM formulario_pregunta fp WHERE fp.tipo_formulario_id = tf.id) AS preguntas,
                (SELECT COUNT(*) FROM evaluaciones ev WHERE ev.tipo_formulario_id = tf.id) AS evaluaciones
                FROM tipos_formulario tf JOIN escalas es ON es.id = tf.escala_id ORDER BY tf.activo DESC, tf.nombre');
$escalas = db_all('SELECT id, nombre FROM escalas WHERE activo = 1 ORDER BY nombre');
render_header('Tipos de formulario');
page_title('Tipos de formulario');
?>
<div class="row g-3">
<div class="col-lg-8"><div class="card"><div class="table-responsive"><table class="table mb-0 align-middle">
  <thead><tr><th>Nombre</th><th>Escala</th><th class="text-end">Preguntas</th><th class="text-end">Evaluaciones</th><th>Estatus</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?>
  <tr><td><strong><?= e($r['nombre']) ?></strong><div class="small text-muted"><?= e($r['descripcion']) ?></div></td><td><?= e($r['escala']) ?></td>
    <td class="text-end"><?= (int)$r['preguntas'] ?></td><td class="text-end"><?= (int)$r['evaluaciones'] ?></td>
    <td><?= $r['activo'] ? '<span class="badge text-bg-success">Activo</span>' : '<span class="badge text-bg-secondary">Inactivo</span>' ?></td>
    <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('admin/tipo_formulario.php?id=' . $r['id'])) ?>">Configurar</a>
      <a class="btn btn-sm btn-outline-secondary" href="<?= e(url('admin/vista_previa.php?tipo_formulario_id=' . $r['id'])) ?>" target="_blank">Vista previa</a>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>"><button name="accion" value="duplicar" class="btn btn-sm btn-outline-secondary">Duplicar</button></form></td></tr>
  <?php endforeach; ?>
  </tbody></table></div></div></div>
<div class="col-lg-4"><div class="card"><div class="card-header">Nuevo tipo de formulario</div>
  <form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
    <div class="mb-2"><label class="form-label">Nombre</label><input name="nombre" class="form-control" required placeholder="Ej. Sector público"></div>
    <div class="mb-2"><label class="form-label">Descripción</label><input name="descripcion" class="form-control"></div>
    <div class="mb-3"><label class="form-label">Escala de calificación</label><select name="escala_id" class="form-select"><?= select_options($escalas, '') ?></select></div>
    <button class="btn btn-primary">Crear</button>
  </form></div></div>
</div>
<?php render_footer();
