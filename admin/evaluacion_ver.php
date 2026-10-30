<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$id = int_get('id');
$ev = evaluacion_get($id);
if (!$ev) {
    flash('danger', 'Evaluación no encontrada.');
    redirect('admin/evaluaciones.php');
}
$tab = get('tab', 'resumen');
$back = 'admin/evaluacion_ver.php?id=' . $id . '&tab=' . urlencode(post('tab', $tab));

if (is_post()) {
    csrf_check();
    $accion = post('accion');
    try {
        switch ($accion) {
            case 'agregar_todos':
                $n = db_exec('INSERT IGNORE INTO participantes (evaluacion_id, empleado_id) SELECT ?, id FROM empleados WHERE empresa_id = ? AND activo = 1', [$id, $ev['empresa_id']]);
                flash('success', "$n participante(s) agregado(s).");
                break;
            case 'agregar_area':
                $n = db_exec('INSERT IGNORE INTO participantes (evaluacion_id, empleado_id) SELECT ?, id FROM empleados WHERE empresa_id = ? AND activo = 1 AND area_id = ?', [$id, $ev['empresa_id'], (int)post('area_id')]);
                flash('success', "$n participante(s) agregado(s).");
                break;
            case 'agregar_uno':
                db_exec('INSERT IGNORE INTO participantes (evaluacion_id, empleado_id) SELECT ?, id FROM empleados WHERE id = ? AND empresa_id = ?', [$id, (int)post('empleado_id'), $ev['empresa_id']]);
                flash('success', 'Participante agregado.');
                break;
            case 'quitar_participante':
                $emp = (int)post('empleado_id');
                if ((int)db_val("SELECT COUNT(*) FROM asignaciones WHERE evaluacion_id = ? AND evaluado_id = ? AND estatus = 'completada'", [$id, $emp]) > 0) {
                    throw new RuntimeException('El participante ya tiene evaluaciones completadas; no puede quitarse.');
                }
                db_exec('DELETE FROM asignaciones WHERE evaluacion_id = ? AND evaluado_id = ?', [$id, $emp]);
                db_exec('DELETE FROM participantes WHERE evaluacion_id = ? AND empleado_id = ?', [$id, $emp]);
                flash('success', 'Participante quitado.');
                break;
            case 'generar':
                $n = generar_asignaciones($id);
                flash('success', "$n asignación(es) nueva(s) generada(s).");
                break;
            case 'asignacion_agregar':
                $evaluador = (int)post('evaluador_id');
                $evaluado = (int)post('evaluado_id');
                $rel = in_array(post('relacion'), RELACIONES, true) ? post('relacion') : 'par';
                $ok = (int)db_val('SELECT COUNT(*) FROM empleados WHERE id IN (?, ?) AND empresa_id = ?', [$evaluador, $evaluado, $ev['empresa_id']]);
                if (!$evaluador || !$evaluado || $ok < ($evaluador === $evaluado ? 1 : 2)) {
                    throw new RuntimeException('Seleccione evaluador y evaluado válidos.');
                }
                db_exec('INSERT IGNORE INTO participantes (evaluacion_id, empleado_id) VALUES (?, ?)', [$id, $evaluado]);
                db_exec('INSERT IGNORE INTO asignaciones (evaluacion_id, evaluador_id, evaluado_id, relacion) VALUES (?, ?, ?, ?)', [$id, $evaluador, $evaluado, $rel]);
                flash('success', 'Asignación agregada.');
                break;
            case 'asignacion_eliminar':
                $n = db_exec("DELETE FROM asignaciones WHERE id = ? AND evaluacion_id = ? AND estatus <> 'completada'", [(int)post('asignacion_id'), $id]);
                flash($n ? 'success' : 'warning', $n ? 'Asignación eliminada.' : 'No se puede eliminar una asignación completada.');
                break;
            case 'asignacion_reabrir':
                $a = db_one('SELECT * FROM asignaciones WHERE id = ? AND evaluacion_id = ?', [(int)post('asignacion_id'), $id]);
                if ($a) {
                    db_update('asignaciones', ['estatus' => 'en_progreso', 'enviada_at' => null, 'puntaje' => null], 'id = :id', ['id' => $a['id']]);
                    calcular_participante($ev, (int)$a['evaluado_id']);
                    audit('reabrir_asignacion', 'asignacion', (int)$a['id']);
                    flash('success', 'Asignación reabierta; el evaluador puede modificarla.');
                }
                break;
            case 'publicar':
                publicar_evaluacion($id);
                $n = enviar_invitaciones($id, 'invitacion');
                $r = correo_procesar_cola();
                audit('publicar_evaluacion', 'evaluacion', $id, ['invitaciones' => $n]);
                flash('success', "Evaluación publicada. $n invitación(es) en cola; {$r['enviados']} enviada(s) ahora. El resto se envía con el procesador de correos.");
                break;
            case 'recordatorios':
                $n = enviar_invitaciones($id, 'recordatorio');
                $r = correo_procesar_cola();
                audit('enviar_recordatorios', 'evaluacion', $id, ['n' => $n]);
                flash('success', "$n recordatorio(s) en cola; {$r['enviados']} enviado(s) ahora.");
                break;
            case 'invitar_uno':
                $emp = db_one('SELECT * FROM empleados WHERE id = ? AND empresa_id = ?', [(int)post('empleado_id'), $ev['empresa_id']]);
                if ($emp) {
                    $html = plantilla_correo('Evaluación de desempeño pendiente', '<p>Hola ' . e($emp['nombre']) . ',</p><p>Tiene evaluaciones pendientes en <strong>' . e($ev['nombre']) . '</strong>.</p><p><a href="' . e(enlace_evaluacion($ev)) . '">' . e(enlace_evaluacion($ev)) . '</a></p><p>Fecha límite: ' . e(fmt_fecha($ev['fecha_cierre'], true)) . '</p>');
                    correo_encolar($emp['email'], 'Evaluación de desempeño pendiente - ' . $ev['empresa_nombre'], $html, 'recordatorio', $id, (int)$emp['id'], true);
                    flash('success', 'Invitación enviada a ' . $emp['email']);
                }
                break;
            case 'cerrar':
                db_update('evaluaciones', ['estatus' => 'cerrada'], 'id = :id', ['id' => $id]);
                recalcular_evaluacion($id);
                audit('cerrar_evaluacion', 'evaluacion', $id);
                flash('success', 'Evaluación cerrada.');
                break;
            case 'reabrir':
                db_update('evaluaciones', ['estatus' => 'publicada'], 'id = :id', ['id' => $id]);
                audit('reabrir_evaluacion', 'evaluacion', $id);
                flash('success', 'Evaluación reabierta. Verifique la fecha de cierre.');
                break;
            case 'recalcular':
                recalcular_evaluacion($id);
                flash('success', 'Calificaciones recalculadas.');
                break;
            case 'eliminar':
                if ($ev['estatus'] !== 'borrador') {
                    throw new RuntimeException('Sólo se pueden eliminar evaluaciones en borrador.');
                }
                db_exec('DELETE FROM evaluaciones WHERE id = ?', [$id]);
                audit('eliminar_evaluacion', 'evaluacion', $id);
                flash('success', 'Evaluación eliminada.');
                redirect('admin/evaluaciones.php');
        }
    } catch (Throwable $ex) {
        flash('danger', $ex->getMessage());
    }
    redirect($back);
}

$av = evaluacion_avance($id);
$link = enlace_evaluacion($ev);
$areas = db_all('SELECT id, nombre FROM areas WHERE empresa_id = ? ORDER BY nombre', [$ev['empresa_id']]);
render_header($ev['nombre']);
page_title($ev['empresa_nombre'] . ' · ' . $ev['nombre'],
    '<a class="btn btn-outline-primary" href="' . e(url('admin/evaluacion.php?id=' . $id)) . '">Editar datos</a> '
    . '<a class="btn btn-success" href="' . e(url('admin/resultados.php?id=' . $id)) . '">Resultados</a>');
$tabs = ['resumen' => 'Resumen', 'participantes' => 'Participantes (' . $av['participantes'] . ')', 'asignaciones' => 'Asignaciones (' . $av['asignaciones'] . ')', 'avance' => 'Avance por evaluador'];
?>
<ul class="nav nav-tabs mb-3">
<?php foreach ($tabs as $k => $v): ?><li class="nav-item"><a class="nav-link <?= $tab === $k ? 'active' : '' ?>" href="?id=<?= $id ?>&tab=<?= $k ?>"><?= e($v) ?></a></li><?php endforeach; ?>
</ul>
<?php if ($tab === 'resumen'): ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card h-100"><div class="card-body">
    <table class="table table-sm mb-0">
      <tr><th>Estatus</th><td><?= etiqueta_estatus($ev['estatus']) ?> <?= evaluacion_abierta($ev) ? '<span class="badge text-bg-success">Recibiendo respuestas</span>' : '' ?></td></tr>
      <tr><th>Tipo</th><td><?= e($ev['tipo']) ?>° (<?= e(implode(', ', array_map('etiqueta_relacion', relaciones_por_tipo($ev['tipo'])))) ?>)</td></tr>
      <tr><th>Formulario</th><td><?= e($ev['tipo_formulario_nombre']) ?> <a class="small" target="_blank" href="<?= e(url('admin/vista_previa.php?tipo_formulario_id=' . $ev['tipo_formulario_id'])) ?>">vista previa</a></td></tr>
      <tr><th>Período evaluado</th><td><?= fmt_fecha($ev['periodo_inicio']) ?> - <?= fmt_fecha($ev['periodo_fin']) ?></td></tr>
      <tr><th>Recepción de respuestas</th><td><?= fmt_fecha($ev['fecha_apertura'], true) ?> - <?= fmt_fecha($ev['fecha_cierre'], true) ?></td></tr>
      <tr><th>Ligada a compensación</th><td><?= $ev['liga_compensacion'] ? 'Sí (' . e(str_replace(',', ', ', (string)$ev['compensacion_tipos'])) . ')' : 'No' ?></td></tr>
      <tr><th>Ponderación</th><td>Jefe <?= fmt_num($ev['pond_jefe'], 0) ?>% · Auto <?= fmt_num($ev['pond_auto'], 0) ?>% · Pares <?= fmt_num($ev['pond_par'], 0) ?>% · Subordinados <?= fmt_num($ev['pond_subordinado'], 0) ?>%</td></tr>
      <tr><th>Enlace para empleados</th><td><input class="form-control form-control-sm" readonly value="<?= e($link) ?>" onclick="this.select()"></td></tr>
    </table>
  </div></div></div>
  <div class="col-lg-5"><div class="card h-100"><div class="card-header">Avance</div><div class="card-body">
    <div class="progress mb-2" style="height:24px"><div class="progress-bar" style="width:<?= $av['porcentaje'] ?>%"><?= $av['porcentaje'] ?>%</div></div>
    <p class="mb-1"><?= $av['completadas'] ?> de <?= $av['asignaciones'] ?> evaluaciones completadas (<?= $av['en_progreso'] ?> en progreso).</p>
    <p><?= $av['evaluadores_completos'] ?> de <?= $av['evaluadores'] ?> evaluadores terminaron todas sus evaluaciones.</p>
    <form method="post" class="d-flex flex-wrap gap-2"><?= csrf_field() ?><input type="hidden" name="tab" value="resumen">
      <?php if ($ev['estatus'] === 'borrador'): ?>
        <button name="accion" value="publicar" class="btn btn-success" data-confirm="Se guardará una copia fija del formulario y se enviarán las invitaciones. ¿Publicar?">Publicar y enviar invitaciones</button>
        <button name="accion" value="eliminar" class="btn btn-outline-danger" data-confirm="¿Eliminar esta evaluación?">Eliminar</button>
      <?php elseif ($ev['estatus'] === 'publicada'): ?>
        <button name="accion" value="recordatorios" class="btn btn-primary" data-confirm="¿Enviar recordatorio a todos los evaluadores con pendientes?">Enviar recordatorios</button>
        <button name="accion" value="cerrar" class="btn btn-dark" data-confirm="¿Cerrar la evaluación? Ya no se recibirán respuestas.">Cerrar evaluación</button>
      <?php else: ?>
        <button name="accion" value="reabrir" class="btn btn-outline-primary">Reabrir</button>
      <?php endif; ?>
      <?php if ($ev['estatus'] !== 'borrador'): ?><button name="accion" value="recalcular" class="btn btn-outline-secondary">Recalcular</button><?php endif; ?>
    </form>
    <?php if ($ev['estatus'] === 'borrador'): ?>
      <div class="alert alert-light small mt-3 mb-0">Pasos: 1) Agregue participantes · 2) Genere asignaciones · 3) Revise y publique.</div>
    <?php endif; ?>
  </div></div></div>
</div>
<?php elseif ($tab === 'participantes'):
    $part = db_all("SELECT e.id, e.nombre, e.apellido, e.email, e.puesto, a.nombre AS area, CONCAT(j.nombre, ' ', j.apellido) AS jefe, p.calificacion_final,
                   (SELECT COUNT(*) FROM asignaciones x WHERE x.evaluacion_id = p.evaluacion_id AND x.evaluado_id = e.id) AS recibidas,
                   (SELECT COUNT(*) FROM asignaciones x WHERE x.evaluacion_id = p.evaluacion_id AND x.evaluado_id = e.id AND x.estatus = 'completada') AS recibidas_ok
                   FROM participantes p JOIN empleados e ON e.id = p.empleado_id LEFT JOIN areas a ON a.id = e.area_id LEFT JOIN empleados j ON j.id = e.jefe_id
                   WHERE p.evaluacion_id = ? ORDER BY e.apellido, e.nombre", [$id]);
    $fuera = db_all("SELECT id, CONCAT(apellido, ', ', nombre, ' (', email, ')') AS nombre FROM empleados WHERE empresa_id = ? AND activo = 1
                     AND id NOT IN (SELECT empleado_id FROM participantes WHERE evaluacion_id = ?) ORDER BY apellido, nombre", [$ev['empresa_id'], $id]);
?>
<div class="card mb-3"><div class="card-body d-flex flex-wrap gap-2 align-items-end">
  <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="participantes"><button name="accion" value="agregar_todos" class="btn btn-primary">Agregar todos los empleados activos</button></form>
  <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="tab" value="participantes"><select name="area_id" class="form-select"><?= select_options($areas, '') ?></select><button name="accion" value="agregar_area" class="btn btn-outline-primary text-nowrap">Agregar área</button></form>
  <form method="post" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="tab" value="participantes"><select name="empleado_id" class="form-select"><?= select_options($fuera, '', 'id', 'nombre', 'Empleado...') ?></select><button name="accion" value="agregar_uno" class="btn btn-outline-primary">Agregar</button></form>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="tab" value="participantes"><button name="accion" value="generar" class="btn btn-success">Generar asignaciones (<?= e($ev['tipo']) ?>°)</button></form>
  <a class="btn btn-outline-secondary" href="<?= e(url('admin/empleados.php?empresa_id=' . $ev['empresa_id'])) ?>">Padrón de empleados</a>
</div></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th>Empleado</th><th>Correo</th><th>Área</th><th>Puesto</th><th>Jefe</th><th>Evaluaciones recibidas</th><th>Calificación</th><th></th></tr></thead><tbody>
  <?php foreach ($part as $p): ?>
  <tr><td><?= e($p['apellido'] . ', ' . $p['nombre']) ?></td><td class="small"><?= e($p['email']) ?></td><td><?= e($p['area']) ?></td><td class="small"><?= e($p['puesto']) ?></td><td class="small"><?= e($p['jefe']) ?></td>
    <td><?= (int)$p['recibidas_ok'] ?> / <?= (int)$p['recibidas'] ?></td><td><?= fmt_num($p['calificacion_final'], 2) ?></td>
    <td class="text-end text-nowrap">
      <?php if ($ev['estatus'] !== 'borrador'): ?><a class="btn btn-sm btn-outline-success" href="<?= e(url('admin/reporte_individual.php?id=' . $id . '&empleado_id=' . $p['id'])) ?>">Reporte</a><?php endif; ?>
      <form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tab" value="participantes"><input type="hidden" name="empleado_id" value="<?= $p['id'] ?>">
      <button name="accion" value="quitar_participante" class="btn btn-sm btn-outline-danger" data-confirm="¿Quitar a este participante y sus asignaciones pendientes?">Quitar</button></form></td></tr>
  <?php endforeach; if (!$part): ?><tr><td colspan="8" class="text-center text-muted">Sin participantes.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php elseif ($tab === 'asignaciones'):
    $rel = get('relacion');
    $st = get('estatus');
    $w = 'a.evaluacion_id = ?';
    $pp = [$id];
    if (in_array($rel, RELACIONES, true)) { $w .= ' AND a.relacion = ?'; $pp[] = $rel; }
    if (in_array($st, ['pendiente', 'en_progreso', 'completada'], true)) { $w .= ' AND a.estatus = ?'; $pp[] = $st; }
    $asig = db_all("SELECT a.*, CONCAT(r.apellido, ', ', r.nombre) AS evaluador, r.email AS evaluador_email, CONCAT(d.apellido, ', ', d.nombre) AS evaluado
                    FROM asignaciones a JOIN empleados r ON r.id = a.evaluador_id JOIN empleados d ON d.id = a.evaluado_id
                    WHERE $w ORDER BY d.apellido, d.nombre, FIELD(a.relacion, 'auto','jefe','par','subordinado','cliente') LIMIT 2000", $pp);
    $emps = db_all("SELECT id, CONCAT(apellido, ', ', nombre) AS nombre FROM empleados WHERE empresa_id = ? AND activo = 1 ORDER BY apellido, nombre", [$ev['empresa_id']]);
?>
<div class="card mb-3"><div class="card-body">
  <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="tab" value="asignaciones"><input type="hidden" name="accion" value="asignacion_agregar">
    <div class="col-md-4"><label class="form-label small">Evaluador</label><select name="evaluador_id" class="form-select form-select-sm"><?= select_options($emps, '', 'id', 'nombre', 'Seleccione') ?></select></div>
    <div class="col-md-4"><label class="form-label small">Evaluado</label><select name="evaluado_id" class="form-select form-select-sm"><?= select_options($emps, '', 'id', 'nombre', 'Seleccione') ?></select></div>
    <div class="col-md-2"><label class="form-label small">Relación</label><select name="relacion" class="form-select form-select-sm"><?php foreach (RELACIONES as $r): ?><option value="<?= $r ?>"><?= e(etiqueta_relacion($r)) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Agregar asignación</button></div>
  </form>
</div></div>
<form class="row g-2 mb-2" method="get"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="asignaciones">
  <div class="col-md-3"><select name="relacion" class="form-select form-select-sm"><option value="">Todas las relaciones</option><?php foreach (RELACIONES as $r): ?><option value="<?= $r ?>" <?= $rel === $r ? 'selected' : '' ?>><?= e(etiqueta_relacion($r)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><select name="estatus" class="form-select form-select-sm"><option value="">Todos los estatus</option><?php foreach (['pendiente' => 'Pendiente', 'en_progreso' => 'En progreso', 'completada' => 'Completada'] as $k => $v): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Filtrar</button></div>
</form>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th>Evaluado</th><th>Evaluador</th><th>Relación</th><th>Estatus</th><th>Enviada</th><th>Puntaje</th><th></th></tr></thead><tbody>
  <?php foreach ($asig as $a): ?>
  <tr><td><?= e($a['evaluado']) ?></td><td><?= e($a['evaluador']) ?><div class="small text-muted"><?= e($a['evaluador_email']) ?></div></td><td><?= e(etiqueta_relacion($a['relacion'])) ?></td>
    <td><?= etiqueta_estatus($a['estatus']) ?></td><td class="small"><?= fmt_fecha($a['enviada_at'], true) ?></td><td><?= fmt_num($a['puntaje'], 2) ?></td>
    <td class="text-end"><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tab" value="asignaciones"><input type="hidden" name="asignacion_id" value="<?= $a['id'] ?>">
      <?php if ($a['estatus'] === 'completada'): ?><button name="accion" value="asignacion_reabrir" class="btn btn-sm btn-outline-warning" data-confirm="¿Reabrir para que el evaluador la modifique?">Reabrir</button>
      <?php else: ?><button name="accion" value="asignacion_eliminar" class="btn btn-sm btn-outline-danger" data-confirm="¿Eliminar asignación?">×</button><?php endif; ?></form></td></tr>
  <?php endforeach; if (!$asig): ?><tr><td colspan="7" class="text-center text-muted">Sin asignaciones. Agregue participantes y presione "Generar asignaciones".</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php else:
    $solo = get('solo', 'pendientes');
    $rows = db_all("SELECT e.id, e.nombre, e.apellido, e.email, ar.nombre AS area, COUNT(*) AS total, SUM(a.estatus = 'completada') AS completadas,
                    MAX(a.enviada_at) AS ultima FROM asignaciones a JOIN empleados e ON e.id = a.evaluador_id LEFT JOIN areas ar ON ar.id = e.area_id
                    WHERE a.evaluacion_id = ? GROUP BY e.id, e.nombre, e.apellido, e.email, ar.nombre " . ($solo === 'pendientes' ? 'HAVING completadas < total ' : '') . 'ORDER BY e.apellido, e.nombre', [$id]);
?>
<div class="mb-2"><a class="btn btn-sm <?= $solo === 'pendientes' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?id=<?= $id ?>&tab=avance&solo=pendientes">Sólo con pendientes</a>
  <a class="btn btn-sm <?= $solo !== 'pendientes' ? 'btn-primary' : 'btn-outline-primary' ?>" href="?id=<?= $id ?>&tab=avance&solo=todos">Todos</a>
  <a class="btn btn-sm btn-outline-success" href="<?= e(url('admin/exportar.php?id=' . $id . '&tipo=avance')) ?>">Exportar avance a Excel</a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0 align-middle">
  <thead><tr><th>Evaluador</th><th>Correo</th><th>Área</th><th>Completadas</th><th>Completado</th><th>Última respuesta</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r): $ok = (int)$r['completadas'] === (int)$r['total']; ?>
  <tr><td><?= e($r['apellido'] . ', ' . $r['nombre']) ?></td><td class="small"><?= e($r['email']) ?></td><td><?= e($r['area']) ?></td>
    <td><?= (int)$r['completadas'] ?> de <?= (int)$r['total'] ?></td><td><?= $ok ? '<span class="badge text-bg-success">Sí</span>' : '<span class="badge text-bg-warning">No</span>' ?></td>
    <td class="small"><?= fmt_fecha($r['ultima'], true) ?></td>
    <td class="text-end"><?php if (!$ok && $ev['estatus'] === 'publicada'): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="tab" value="avance"><input type="hidden" name="empleado_id" value="<?= $r['id'] ?>"><button name="accion" value="invitar_uno" class="btn btn-sm btn-outline-primary">Reenviar invitación</button></form><?php endif; ?></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="7" class="text-center text-muted">Sin registros.</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php endif;
render_footer();
