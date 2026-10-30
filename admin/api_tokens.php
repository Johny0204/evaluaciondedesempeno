<?php
require __DIR__ . '/../app/bootstrap.php';
$u = require_staff();
$nuevo = null;
if (is_post()) {
    csrf_check();
    if (post('accion') === 'crear') {
        $nuevo = bin2hex(random_bytes(32));
        $dias = (int)post('dias', 365);
        db_insert('api_tokens', ['usuario_id' => $u['id'], 'nombre' => post('nombre') ?: 'Token', 'token_hash' => hash('sha256', $nuevo), 'expira_at' => $dias > 0 ? date('Y-m-d H:i:s', time() + 86400 * $dias) : null]);
        audit('crear_token_api', 'usuario', (int)$u['id']);
    } elseif (post('accion') === 'revocar') {
        db_exec('DELETE FROM api_tokens WHERE id = ? AND usuario_id = ?', [(int)post('id'), $u['id']]);
        flash('success', 'Token revocado.');
        redirect('admin/api_tokens.php');
    }
}
$tokens = db_all('SELECT * FROM api_tokens WHERE usuario_id = ? ORDER BY id DESC', [$u['id']]);
render_header('Tokens API');
page_title('Tokens de API');
?>
<?php if ($nuevo): ?><div class="alert alert-success">Copie su token ahora; no se volverá a mostrar:<br><code class="fs-6"><?= e($nuevo) ?></code></div><?php endif; ?>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><table class="table table-sm mb-0"><thead><tr><th>Nombre</th><th>Creado</th><th>Último uso</th><th>Vence</th><th></th></tr></thead><tbody>
<?php foreach ($tokens as $t): ?><tr><td><?= e($t['nombre']) ?></td><td class="small"><?= fmt_fecha($t['created_at'], true) ?></td><td class="small"><?= fmt_fecha($t['ultimo_uso'], true) ?></td><td class="small"><?= fmt_fecha($t['expira_at']) ?: 'Nunca' ?></td>
  <td><form method="post"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $t['id'] ?>"><button name="accion" value="revocar" class="btn btn-sm btn-outline-danger" data-confirm="¿Revocar token?">Revocar</button></form></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<div class="col-lg-5"><div class="card"><div class="card-header">Nuevo token</div><form method="post" class="card-body"><?= csrf_field() ?><input type="hidden" name="accion" value="crear">
  <input name="nombre" class="form-control mb-2" placeholder="Ej. Integración nómina" required>
  <select name="dias" class="form-select mb-2"><option value="30">30 días</option><option value="365" selected>1 año</option><option value="0">Sin vencimiento</option></select>
  <button class="btn btn-primary">Generar</button></form>
  <div class="card-footer small">Uso: <code>Authorization: Bearer &lt;token&gt;</code> &middot; Base: <code><?= e(url('api/index.php')) ?></code>. Documentación en <code>docs/API.md</code>.</div></div></div>
</div>
<?php render_footer();
