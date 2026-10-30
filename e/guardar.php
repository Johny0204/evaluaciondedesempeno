<?php
// Guardado automático de una respuesta (AJAX) desde el portal público.
require __DIR__ . '/../app/bootstrap.php';

if (!is_post()) {
    json_response(['ok' => false, 'error' => 'Método no permitido'], 405);
}
$token = $_POST['_csrf'] ?? '';
if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
    json_response(['ok' => false, 'error' => 'Sesión expirada; recargue la página.'], 419);
}
$c = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_POST['c'] ?? '')));
$estado = $_SESSION['pub'][$c] ?? [];
if (($estado['paso'] ?? '') !== 'auth' || time() - ($estado['ultimo'] ?? 0) > 30 * 60) {
    json_response(['ok' => false, 'error' => 'Sesión expirada; ingrese de nuevo.'], 401);
}
$_SESSION['pub'][$c]['ultimo'] = time();
$ev = db_one('SELECT * FROM evaluaciones WHERE codigo_publico = ?', [$c]);
if (!$ev || !evaluacion_abierta($ev)) {
    json_response(['ok' => false, 'error' => 'La evaluación no está abierta.'], 403);
}
$asig = db_one('SELECT * FROM asignaciones WHERE id = ? AND evaluacion_id = ? AND evaluador_id = ?', [(int)($_POST['asignacion_id'] ?? 0), $ev['id'], (int)$estado['empleado_id']]);
if (!$asig || $asig['estatus'] === 'completada') {
    json_response(['ok' => false, 'error' => 'No disponible.'], 403);
}
$form = evaluacion_formulario($ev);
$pid = (int)($_POST['pregunta_id'] ?? 0);
$pregunta = null;
foreach (formulario_para_relacion($form, $asig['relacion']) as $s) {
    foreach ($s['preguntas'] as $p) {
        if ($p['id'] === $pid) {
            $pregunta = $p;
        }
    }
}
if (!$pregunta) {
    json_response(['ok' => false, 'error' => 'Pregunta no válida.'], 422);
}
$raw = trim((string)($_POST['valor'] ?? ''));
$valor = null;
$texto = null;
switch ($pregunta['tipo']) {
    case 'escala':
        $validos = array_map(fn($v) => (float)$v['valor'], $form['escala']['valores']);
        $valor = in_array((float)$raw, $validos, true) ? (float)$raw : null;
        break;
    case 'si_no':
        $valor = in_array($raw, ['0', '1'], true) ? (float)$raw : null;
        $texto = $valor === null ? null : ($raw === '1' ? 'Sí' : 'No');
        break;
    case 'numerica':
        $valor = is_numeric($raw) ? (float)$raw : null;
        break;
    case 'opcion_multiple':
        $texto = in_array($raw, $pregunta['opciones'], true) ? $raw : null;
        break;
    default:
        $texto = $raw === '' ? null : mb_substr($raw, 0, 5000);
}
if ($valor === null && $texto === null) {
    db_exec('DELETE FROM respuestas WHERE asignacion_id = ? AND pregunta_id = ?', [$asig['id'], $pid]);
} else {
    db_exec('INSERT INTO respuestas (asignacion_id, pregunta_id, valor, texto) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), texto = VALUES(texto)', [$asig['id'], $pid, $valor, $texto]);
}
if ($asig['estatus'] === 'pendiente') {
    db_update('asignaciones', ['estatus' => 'en_progreso', 'iniciada_at' => now()], 'id = :id', ['id' => $asig['id']]);
}
json_response(['ok' => true]);
