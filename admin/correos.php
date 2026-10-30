<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
if (is_post()) {
    csrf_check();
    if (post('accion') === 'procesar') {
        $r = correo_procesar_cola();
        flash('success', "Procesados: {$r['enviados']} enviados, {$r['errores']} con error.");
    } elseif (post('accion') === 'reintentar') {
        $n = db_exec("UPDATE correos SET estatus = 'pendiente', intentos = 0 WHERE estatus = 'error'");
        flash('success', "$n correo(s) marcados para reintento.");
    }
    redirect('admin/correos.php');
}
$estatus = get('estatus');
$where = in_array($estatus, ['pendiente', 'enviado', 'error'], true) ? 'WHERE estatus = ' . db()->quote($estatus) : '';
$rows = db_all("SELECT id, destinatario, asunto, tipo, estatus, intentos, error, created_at, enviado_at FROM correos $where ORDER BY id DESC LIMIT 300");
$conteo = db_all('SELECT estatus, COUNT(*) AS n FROM correos GROUP BY estatus');
render_header('Cola de correos');
page_title('Cola de correos', '<form method="post" class="d-inline">' . csrf_field() . '<button name="accion" value="procesar" class="btn btn-primary">Enviar lote ahora</button> <button name="accion" value="reintentar" class="btn btn-outline-warning">Reintentar errores</button></form>');
?>
<div class="alert alert-info small">Modo de correo actual: <strong><?= e(config('correo.modo')) ?></strong>.
  <?= config('correo.modo') === 'log' ? 'Los correos se guardan en storage/logs/correo.log (no se envían). Configure SMTP en config/config.php para producción.' : '' ?>
  Programe <code>cron/procesar_correos.php</code> cada 1-5 minutos para enviar la cola automáticamente.</div>
<div class="mb-2"><?php foreach ($conteo as $c): ?><a class="btn btn-sm btn-outline-secondary me-1" href="?estatus=<?= e($c['estatus']) ?>"><?= e($c['estatus']) ?>: <?= (int)$c['n'] ?></a><?php endforeach; ?><a class="btn btn-sm btn-link" href="?">Todos</a></div>
<div class="card"><div class="table-responsive"><table class="table table-sm mb-0">
  <thead><tr><th>#</th><th>Destinatario</th><th>Asunto</th><th>Tipo</th><th>Estatus</th><th>Intentos</th><th>Creado</th><th>Enviado</th><th>Error</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= $r['id'] ?></td><td><?= e($r['destinatario']) ?></td><td class="small"><?= e(preg_replace('/\d{6}/', '******', $r['asunto'])) ?></td><td><?= e($r['tipo']) ?></td><td><?= etiqueta_estatus($r['estatus']) ?></td>
    <td><?= (int)$r['intentos'] ?></td><td class="small"><?= fmt_fecha($r['created_at'], true) ?></td><td class="small"><?= fmt_fecha($r['enviado_at'], true) ?></td><td class="small text-danger"><?= e($r['error']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div></div>
<?php render_footer();
