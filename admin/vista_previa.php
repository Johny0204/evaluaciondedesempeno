<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$form = formulario_construir(int_get('tipo_formulario_id'));
$relacion = in_array(get('relacion'), RELACIONES, true) ? get('relacion') : 'jefe';
render_header('Vista previa');
page_title('Vista previa: ' . $form['nombre']);
echo '<div class="mb-3">';
foreach (RELACIONES as $r) {
    echo '<a class="btn btn-sm ' . ($r === $relacion ? 'btn-primary' : 'btn-outline-primary') . ' me-1" href="?tipo_formulario_id=' . (int)$form['tipo_formulario_id'] . '&relacion=' . $r . '">' . e(etiqueta_relacion($r)) . '</a>';
}
echo '</div>';
foreach (formulario_para_relacion($form, $relacion) as $s) {
    echo '<h2 class="h5 mt-3">' . e($s['nombre']) . ' <small class="text-muted">(' . fmt_num($s['peso'], 0) . '%)</small></h2>';
    foreach ($s['preguntas'] as $p) {
        echo '<div class="pregunta"><div class="fw-semibold mb-2">' . e($relacion === 'auto' ? $p['texto_auto'] : $p['texto']) . ($p['obligatoria'] ? ' <span class="text-danger">*</span>' : '') . '</div>';
        echo '<div class="small text-muted">' . e($p['categoria']) . ' · ' . e($p['tipo']) . ' · peso ' . fmt_num($p['peso'], 2) . '</div></div>';
    }
}
render_footer();
