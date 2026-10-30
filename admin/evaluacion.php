<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$id = int_get('id');
$ev = $id ? db_one('SELECT * FROM evaluaciones WHERE id = ?', [$id]) : null;
if ($id && !$ev) {
    redirect('admin/evaluaciones.php');
}
$errores = [];
if (is_post()) {
    csrf_check();
    $comp = array_values(array_intersect((array)($_POST['compensacion_tipos'] ?? []), ['bonos', 'aumentos', 'promociones']));
    $data = [
        'empresa_id'         => (int)post('empresa_id'),
        'nombre'             => post('nombre'),
        'periodo_inicio'     => post('periodo_inicio'),
        'periodo_fin'        => post('periodo_fin'),
        'fecha_apertura'     => str_replace('T', ' ', post('fecha_apertura')),
        'fecha_cierre'       => str_replace('T', ' ', post('fecha_cierre')),
        'tipo'               => in_array(post('tipo'), ['90', '180', '270', '360'], true) ? post('tipo') : '180',
        'tipo_formulario_id' => (int)post('tipo_formulario_id'),
        'liga_compensacion'  => post('liga_compensacion') === '1' ? 1 : 0,
        'compensacion_tipos' => post('liga_compensacion') === '1' && $comp ? implode(',', $comp) : null,
        'pond_jefe'          => (float)post('pond_jefe'),
        'pond_auto'          => (float)post('pond_auto'),
        'pond_par'           => (float)post('pond_par'),
        'pond_subordinado'   => (float)post('pond_subordinado'),
        'anonimato_min'      => max(1, (int)post('anonimato_min', 3)),
        'max_pares'          => max(0, (int)post('max_pares', 3)),
        'mensaje_bienvenida' => post('mensaje_bienvenida') ?: null,
    ];
    if (!$data['empresa_id'] || !db_val('SELECT id FROM empresas WHERE id = ?', [$data['empresa_id']])) {
        $errores[] = 'Seleccione la empresa.';
    }
    if ($data['nombre'] === '') {
        $errores[] = 'El nombre es obligatorio.';
    }
    if (!$data['periodo_inicio'] || !$data['periodo_fin'] || $data['periodo_inicio'] > $data['periodo_fin']) {
        $errores[] = 'Período evaluado inválido.';
    }
    if (!$data['fecha_apertura'] || !$data['fecha_cierre'] || strtotime($data['fecha_apertura']) >= strtotime($data['fecha_cierre'])) {
        $errores[] = 'Fechas de apertura y cierre inválidas.';
    }
    if (!$data['tipo_formulario_id']) {
        $errores[] = 'Seleccione el tipo de formulario.';
    }
    if ($ev && $ev['estatus'] !== 'borrador' && ((int)$ev['empresa_id'] !== $data['empresa_id'] || (int)$ev['tipo_formulario_id'] !== $data['tipo_formulario_id'] || $ev['tipo'] !== $data['tipo'])) {
        $errores[] = 'La evaluación ya fue publicada: no se puede cambiar empresa, tipo ni formulario.';
    }
    if (!$errores) {
        if ($id) {
            db_update('evaluaciones', $data, 'id = :id', ['id' => $id]);
            audit('editar_evaluacion', 'evaluacion', $id);
            if ($ev['estatus'] !== 'borrador') {
                recalcular_evaluacion($id);
            }
        } else {
            do {
                $codigo = codigo_aleatorio(8);
            } while (db_val('SELECT id FROM evaluaciones WHERE codigo_publico = ?', [$codigo]));
            $data['codigo_publico'] = $codigo;
            $data['created_by'] = $u['id'];
            $id = db_insert('evaluaciones', $data);
            audit('crear_evaluacion', 'evaluacion', $id);
        }
        flash('success', 'Evaluación guardada.');
        redirect('admin/evaluacion_ver.php?id=' . $id);
    }
    $ev = array_merge($ev ?? [], $data, ['compensacion_tipos' => implode(',', $comp)]);
}
$ev = $ev ?? ['empresa_id' => int_get('empresa_id'), 'tipo' => '180', 'pond_jefe' => 60, 'pond_auto' => 40, 'pond_par' => 0, 'pond_subordinado' => 0, 'anonimato_min' => 3, 'max_pares' => 3,
    'periodo_inicio' => date('Y') . '-01-01', 'periodo_fin' => date('Y') . '-12-31', 'fecha_apertura' => date('Y-m-d 08:00'), 'fecha_cierre' => date('Y-m-d 18:00', strtotime('+15 days')), 'liga_compensacion' => 0];
if (empty($ev['tipo_formulario_id']) && !empty($ev['empresa_id'])) {
    $te = db_val('SELECT te.nombre FROM empresas em JOIN tipos_empresa te ON te.id = em.tipo_empresa_id WHERE em.id = ?', [$ev['empresa_id']]);
    $ev['tipo_formulario_id'] = $te ? db_val('SELECT id FROM tipos_formulario WHERE nombre = ? AND activo = 1', [$te]) : null;
}
$empresas = db_all('SELECT id, CONCAT(nombre, " (", nit, ")") AS nombre FROM empresas WHERE activo = 1 ORDER BY nombre');
$formularios = db_all('SELECT id, nombre FROM tipos_formulario WHERE activo = 1 ORDER BY nombre');
$comp = explode(',', (string)($ev['compensacion_tipos'] ?? ''));
$dt = fn($v) => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
render_header('Evaluación');
page_title($id ? 'Editar evaluación' : 'Nueva evaluación');
?>
<?php if ($errores): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errores as $er): ?><li><?= e($er) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<form method="post"><?= csrf_field() ?>
<div class="card mb-3"><div class="card-header">Datos generales</div><div class="card-body row g-3">
  <div class="col-md-6"><label class="form-label">Empresa *</label><select name="empresa_id" class="form-select" required><?= select_options($empresas, $ev['empresa_id'] ?? '', 'id', 'nombre', 'Seleccione') ?></select></div>
  <div class="col-md-6"><label class="form-label">Nombre de la evaluación *</label><input name="nombre" class="form-control" required value="<?= e($ev['nombre'] ?? 'Evaluación de desempeño ' . date('Y')) ?>"></div>
  <div class="col-md-3"><label class="form-label">Período evaluado: desde *</label><input type="date" name="periodo_inicio" class="form-control" required value="<?= e($ev['periodo_inicio']) ?>"></div>
  <div class="col-md-3"><label class="form-label">Período evaluado: hasta *</label><input type="date" name="periodo_fin" class="form-control" required value="<?= e($ev['periodo_fin']) ?>"></div>
  <div class="col-md-3"><label class="form-label">Apertura de respuestas *</label><input type="datetime-local" name="fecha_apertura" class="form-control" required value="<?= e($dt($ev['fecha_apertura'])) ?>"></div>
  <div class="col-md-3"><label class="form-label">Cierre de respuestas *</label><input type="datetime-local" name="fecha_cierre" class="form-control" required value="<?= e($dt($ev['fecha_cierre'])) ?>"></div>
  <div class="col-md-3"><label class="form-label">Tipo de evaluación *</label><select name="tipo" class="form-select" id="tipo">
    <?php foreach (['90' => '90° (jefe)', '180' => '180° (jefe + autoevaluación)', '270' => '270° (+ subordinados)', '360' => '360° (+ pares)'] as $k => $v): ?><option value="<?= $k ?>" <?= (string)$ev['tipo'] === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-md-5"><label class="form-label">Tipo de formulario *</label><select name="tipo_formulario_id" class="form-select" required><?= select_options($formularios, $ev['tipo_formulario_id'] ?? '', 'id', 'nombre', 'Seleccione') ?></select></div>
  <div class="col-md-4"><label class="form-label">¿Los resultados se ligan a bonos, aumentos o promociones?</label>
    <div><label class="me-3"><input type="radio" class="form-check-input" name="liga_compensacion" value="1" <?= $ev['liga_compensacion'] ? 'checked' : '' ?>> Sí</label>
    <label><input type="radio" class="form-check-input" name="liga_compensacion" value="0" <?= !$ev['liga_compensacion'] ? 'checked' : '' ?>> No</label></div>
    <div class="small"><?php foreach (['bonos' => 'Bonos', 'aumentos' => 'Aumentos', 'promociones' => 'Promociones'] as $k => $v): ?>
      <label class="me-2"><input type="checkbox" class="form-check-input" name="compensacion_tipos[]" value="<?= $k ?>" <?= in_array($k, $comp, true) ? 'checked' : '' ?>> <?= $v ?></label><?php endforeach; ?></div></div>
</div></div>
<div class="card mb-3"><div class="card-header">Ponderación por tipo de evaluador (%)</div><div class="card-body row g-3">
  <div class="col-md-2"><label class="form-label">Jefe</label><input type="number" step="0.01" name="pond_jefe" class="form-control" value="<?= e($ev['pond_jefe']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Autoevaluación</label><input type="number" step="0.01" name="pond_auto" class="form-control" value="<?= e($ev['pond_auto']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Pares</label><input type="number" step="0.01" name="pond_par" class="form-control" value="<?= e($ev['pond_par']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Subordinados</label><input type="number" step="0.01" name="pond_subordinado" class="form-control" value="<?= e($ev['pond_subordinado']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Máx. pares por persona</label><input type="number" name="max_pares" class="form-control" value="<?= e($ev['max_pares']) ?>"></div>
  <div class="col-md-2"><label class="form-label">Anonimato mínimo</label><input type="number" name="anonimato_min" class="form-control" value="<?= e($ev['anonimato_min']) ?>"></div>
  <div class="col-12 small text-muted">Si un tipo de evaluador no tiene respuestas, su peso se reparte entre los demás. "Anonimato mínimo": número mínimo de respuestas de pares/subordinados para mostrar su promedio en resultados individuales.</div>
</div></div>
<div class="card mb-3"><div class="card-header">Mensaje de bienvenida (opcional)</div><div class="card-body">
  <textarea name="mensaje_bienvenida" class="form-control" rows="3" placeholder="Texto que verá el colaborador al entrar a la evaluación"><?= e($ev['mensaje_bienvenida'] ?? '') ?></textarea></div></div>
<button class="btn btn-primary">Guardar y continuar</button> <a class="btn btn-link" href="<?= e(url($id ? 'admin/evaluacion_ver.php?id=' . $id : 'admin/evaluaciones.php')) ?>">Cancelar</a>
</form>
<?php render_footer();
