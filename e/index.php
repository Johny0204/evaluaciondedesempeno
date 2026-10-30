<?php
// Portal público: el empleado ingresa con su correo + código de verificación (sin contraseña).
require __DIR__ . '/../app/bootstrap.php';

$codigoEv = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)($_GET['c'] ?? $_POST['c'] ?? '')));
$ev = $codigoEv !== '' ? db_one('SELECT ev.*, em.nombre AS empresa_nombre FROM evaluaciones ev JOIN empresas em ON em.id = ev.empresa_id WHERE ev.codigo_publico = ?', [$codigoEv]) : null;
$self = 'e/index.php?c=' . urlencode($codigoEv);

function pub_estado(string $c): array
{
    return $_SESSION['pub'][$c] ?? [];
}
function pub_set(string $c, array $data): void
{
    $_SESSION['pub'][$c] = $data;
}
function pub_vista_mensaje(?array $ev = null, string $titulo = '', string $mensaje = '', string $tipo = 'info'): void
{
    render_header($titulo ?: 'Evaluación', ['public' => true]);
    echo '<h1 class="text-white h4 text-center mb-4">' . e($ev['empresa_nombre'] ?? config('app_nombre')) . '</h1>';
    echo '<div class="card public-card p-4 text-center"><h2 class="h5">' . e($titulo) . '</h2><div class="alert alert-' . e($tipo) . ' mb-0">' . $mensaje . '</div></div>';
    render_footer();
    exit;
}

if (!$ev || $ev['estatus'] === 'borrador') {
    http_response_code(404);
    pub_vista_mensaje(null, 'Evaluación no encontrada', 'Verifique el enlace que recibió por correo.', 'warning');
}
if ($ev['estatus'] === 'cerrada' || strtotime($ev['fecha_cierre']) < time()) {
    pub_vista_mensaje($ev, $ev['nombre'], 'La evaluación finalizó el ' . e(fmt_fecha($ev['fecha_cierre'], true)) . '. Gracias.', 'secondary');
}
if (strtotime($ev['fecha_apertura']) > time()) {
    pub_vista_mensaje($ev, $ev['nombre'], 'La evaluación estará disponible a partir del ' . e(fmt_fecha($ev['fecha_apertura'], true)) . '.', 'info');
}

$estado = pub_estado($codigoEv);
if (($estado['paso'] ?? '') === 'auth' && time() - ($estado['ultimo'] ?? 0) > 30 * 60) {
    $estado = [];
    pub_set($codigoEv, []);
    flash('warning', 'Su sesión expiró por inactividad. Ingrese de nuevo.');
}
$empleado = !empty($estado['empleado_id']) ? db_one('SELECT * FROM empleados WHERE id = ? AND empresa_id = ? AND activo = 1', [$estado['empleado_id'], $ev['empresa_id']]) : null;
$codigoDev = $_SESSION['otp_dev'][$codigoEv] ?? null;
unset($_SESSION['otp_dev'][$codigoEv]);

$pendientesDe = function (int $empId) use ($ev): int {
    return (int)db_val("SELECT COUNT(*) FROM asignaciones WHERE evaluacion_id = ? AND evaluador_id = ? AND estatus <> 'completada'", [$ev['id'], $empId]);
};

if (is_post()) {
    csrf_check();
    $accion = post('accion');
    if ($accion === 'salir') {
        pub_set($codigoEv, []);
        redirect($self);
    }
    if ($accion === 'email') {
        if (rate_limited('pub_email', 15, 600)) {
            flash('danger', 'Demasiados intentos. Espere unos minutos.');
            redirect($self);
        }
        rate_hit('pub_email');
        $email = strtolower(post('email'));
        $emp = valid_email($email) ? db_one('SELECT * FROM empleados WHERE empresa_id = ? AND email = ? AND activo = 1', [$ev['empresa_id'], $email]) : null;
        $total = $emp ? (int)db_val('SELECT COUNT(*) FROM asignaciones WHERE evaluacion_id = ? AND evaluador_id = ?', [$ev['id'], $emp['id']]) : 0;
        if ($emp && $total > 0 && $pendientesDe((int)$emp['id']) === 0) {
            pub_vista_mensaje($ev, 'Evaluación completada', 'Usted ya completó su evaluación. ¡Gracias por su participación!', 'success');
        }
        if ($emp && $total > 0) {
            [$ok, $msg, $cod] = otp_enviar($ev, $emp);
            if ($ok && config('mostrar_codigos', config('modo_desarrollo'))) {
                $_SESSION['otp_dev'][$codigoEv] = $cod;
            }
            flash($ok ? 'success' : 'warning', $msg);
            pub_set($codigoEv, ['paso' => 'codigo', 'empleado_id' => (int)$emp['id'], 'email' => $email]);
        } else {
            flash('info', 'Si el correo está registrado en esta evaluación, recibirá un código de verificación en su buzón.');
            pub_set($codigoEv, ['paso' => 'codigo', 'empleado_id' => null, 'email' => $email]);
        }
        redirect($self);
    }
    if ($accion === 'reenviar' && ($estado['paso'] ?? '') === 'codigo') {
        if ($empleado) {
            [$ok, $msg, $cod] = otp_enviar($ev, $empleado);
            if ($ok && config('mostrar_codigos', config('modo_desarrollo'))) {
                $_SESSION['otp_dev'][$codigoEv] = $cod;
            }
            flash($ok ? 'success' : 'warning', $ok ? 'Le enviamos un nuevo código.' : $msg);
        } else {
            flash('info', 'Si el correo está registrado, recibirá un nuevo código.');
        }
        redirect($self);
    }
    if ($accion === 'verificar' && ($estado['paso'] ?? '') === 'codigo') {
        if (rate_limited('pub_otp', 30, 600)) {
            flash('danger', 'Demasiados intentos. Espere unos minutos.');
            redirect($self);
        }
        rate_hit('pub_otp');
        if (!$empleado) {
            flash('danger', 'Código incorrecto.');
            redirect($self);
        }
        [$ok, $msg] = otp_verificar($ev, $empleado, preg_replace('/\D/', '', post('codigo')));
        if ($ok) {
            session_regenerate_id(true);
            pub_set($codigoEv, ['paso' => 'auth', 'empleado_id' => (int)$empleado['id'], 'ultimo' => time()]);
            redirect($self);
        }
        flash('danger', $msg);
        redirect($self);
    }
    if ($accion === 'enviar' && ($estado['paso'] ?? '') === 'auth' && $empleado) {
        $asig = db_one('SELECT * FROM asignaciones WHERE id = ? AND evaluacion_id = ? AND evaluador_id = ?', [(int)post('asignacion_id'), $ev['id'], $empleado['id']]);
        if (!$asig || $asig['estatus'] === 'completada' || !evaluacion_abierta($ev)) {
            flash('warning', 'Esta evaluación ya no puede modificarse.');
            redirect($self);
        }
        $form = evaluacion_formulario($ev);
        $secciones = formulario_para_relacion($form, $asig['relacion']);
        $faltan = [];
        $stmt = db()->prepare('INSERT INTO respuestas (asignacion_id, pregunta_id, valor, texto) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor), texto = VALUES(texto)');
        foreach ($secciones as $s) {
            foreach ($s['preguntas'] as $p) {
                $raw = $_POST['r'][$p['id']] ?? null;
                [$valor, $texto] = normalizar_respuesta($p, $form, $raw);
                if ($valor !== null || $texto !== null) {
                    $stmt->execute([$asig['id'], $p['id'], $valor, $texto]);
                }
                if ($p['obligatoria'] && $valor === null && ($texto === null || $texto === '')) {
                    $faltan[] = $p['id'];
                }
            }
        }
        if ($faltan) {
            db_update('asignaciones', ['estatus' => 'en_progreso', 'iniciada_at' => $asig['iniciada_at'] ?: now()], 'id = :id', ['id' => $asig['id']]);
            $_SESSION['pub_faltan'] = $faltan;
            flash('danger', 'Faltan ' . count($faltan) . ' pregunta(s) obligatoria(s) por responder (marcadas en rojo).');
            redirect($self . '&a=' . $asig['id']);
        }
        db_update('asignaciones', ['estatus' => 'completada', 'enviada_at' => now(), 'iniciada_at' => $asig['iniciada_at'] ?: now()], 'id = :id', ['id' => $asig['id']]);
        calcular_puntaje_asignacion((int)$asig['id'], $form);
        calcular_participante($ev, (int)$asig['evaluado_id']);
        audit('evaluacion_enviada', 'asignacion', (int)$asig['id'], null, (int)$empleado['id']);
        flash('success', 'Evaluación enviada. ¡Gracias!');
        redirect($self);
    }
    redirect($self);
}

/** Convierte la respuesta recibida en [valor numérico, texto] según el tipo de pregunta. */
function normalizar_respuesta(array $p, array $form, $raw): array
{
    if ($raw === null || $raw === '') {
        return [null, null];
    }
    $raw = is_string($raw) ? trim($raw) : '';
    switch ($p['tipo']) {
        case 'escala':
            $validos = array_map(fn($v) => (float)$v['valor'], $form['escala']['valores']);
            return in_array((float)$raw, $validos, true) ? [(float)$raw, null] : [null, null];
        case 'si_no':
            return in_array($raw, ['1', '0'], true) ? [(float)$raw, $raw === '1' ? 'Sí' : 'No'] : [null, null];
        case 'numerica':
            return is_numeric($raw) ? [(float)$raw, null] : [null, null];
        case 'opcion_multiple':
            return in_array($raw, $p['opciones'], true) ? [null, $raw] : [null, null];
        default:
            return [null, mb_substr($raw, 0, 5000)];
    }
}

$header = function () use ($ev) {
    render_header($ev['nombre'], ['public' => true]);
    echo '<h1 class="text-white h4 text-center mb-1">' . e($ev['empresa_nombre']) . '</h1>';
    echo '<p class="text-white-50 text-center mb-4">' . e($ev['nombre']) . ' &middot; Disponible hasta ' . e(fmt_fecha($ev['fecha_cierre'], true)) . '</p>';
};

// ------------------------------------------------------------------ Vistas
if (($estado['paso'] ?? '') === 'auth' && $empleado) {
    $estado['ultimo'] = time();
    pub_set($codigoEv, $estado);
    $asigId = int_get('a');
    if ($asigId) {
        $asig = db_one("SELECT a.*, CONCAT(d.nombre, ' ', d.apellido) AS evaluado, d.puesto FROM asignaciones a JOIN empleados d ON d.id = a.evaluado_id
                        WHERE a.id = ? AND a.evaluacion_id = ? AND a.evaluador_id = ?", [$asigId, $ev['id'], $empleado['id']]);
        if (!$asig || $asig['estatus'] === 'completada') {
            redirect($self);
        }
        if ($asig['estatus'] === 'pendiente') {
            db_update('asignaciones', ['estatus' => 'en_progreso', 'iniciada_at' => now()], 'id = :id', ['id' => $asig['id']]);
        }
        $form = evaluacion_formulario($ev);
        $secciones = formulario_para_relacion($form, $asig['relacion']);
        $resp = [];
        foreach (db_all('SELECT pregunta_id, valor, texto FROM respuestas WHERE asignacion_id = ?', [$asig['id']]) as $r) {
            $resp[(int)$r['pregunta_id']] = $r;
        }
        $faltan = $_SESSION['pub_faltan'] ?? [];
        unset($_SESSION['pub_faltan']);
        $esAuto = $asig['relacion'] === 'auto';
        $header();
        ?>
        <div class="card public-card p-4 mb-3">
          <div class="d-flex justify-content-between flex-wrap gap-2">
            <div><div class="small text-muted"><?= e(etiqueta_relacion($asig['relacion'])) ?></div>
              <h2 class="h5 mb-0"><?= $esAuto ? 'Autoevaluación' : 'Evaluando a: ' . e($asig['evaluado']) ?></h2>
              <?php if (!$esAuto && $asig['puesto']): ?><div class="small text-muted"><?= e($asig['puesto']) ?></div><?php endif; ?></div>
            <div class="text-end"><a href="<?= e(url($self)) ?>" class="btn btn-sm btn-outline-secondary">Volver a mis evaluaciones</a>
              <div class="guardado text-muted mt-1" id="estadoGuardado">Sus respuestas se guardan automáticamente.</div></div>
          </div>
          <div class="small mt-3 p-2 bg-light rounded"><strong>Escala:</strong> <?php foreach ($form['escala']['valores'] as $v): ?><?= e(fmt_num($v['valor'], 0)) ?> = <?= e($v['etiqueta']) ?> &nbsp; <?php endforeach; ?></div>
        </div>
        <form method="post" id="formEval">
          <?= csrf_field() ?><input type="hidden" name="c" value="<?= e($codigoEv) ?>"><input type="hidden" name="accion" value="enviar"><input type="hidden" name="asignacion_id" value="<?= (int)$asig['id'] ?>">
          <?php $num = 0; foreach ($secciones as $s): ?>
            <h3 class="h6 text-uppercase text-muted mt-4"><?= e($s['nombre']) ?></h3>
            <?php foreach ($s['preguntas'] as $p): $num++; $r = $resp[$p['id']] ?? null; $name = 'r[' . $p['id'] . ']'; ?>
            <div class="pregunta <?= in_array($p['id'], $faltan, true) ? 'falta' : '' ?>">
              <div class="fw-semibold mb-2"><?= $num ?>. <?= e($esAuto ? $p['texto_auto'] : $p['texto']) ?><?= $p['obligatoria'] ? ' <span class="text-danger">*</span>' : '' ?></div>
              <?php if ($p['ayuda']): ?><div class="small text-muted mb-2"><?= e($p['ayuda']) ?></div><?php endif; ?>
              <?php if ($p['tipo'] === 'escala'): ?>
                <div class="escala-opciones"><?php foreach ($form['escala']['valores'] as $v): ?>
                  <label><input type="radio" name="<?= $name ?>" value="<?= e($v['valor']) ?>" data-p="<?= $p['id'] ?>" <?= $r && $r['valor'] !== null && (float)$r['valor'] === (float)$v['valor'] ? 'checked' : '' ?>>
                    <strong><?= e(fmt_num($v['valor'], 0)) ?></strong><br><?= e($v['etiqueta']) ?></label>
                <?php endforeach; ?></div>
              <?php elseif ($p['tipo'] === 'si_no'): ?>
                <div class="escala-opciones" style="max-width:300px">
                  <label><input type="radio" name="<?= $name ?>" value="1" data-p="<?= $p['id'] ?>" <?= $r && $r['valor'] !== null && (float)$r['valor'] === 1.0 ? 'checked' : '' ?>> Sí</label>
                  <label><input type="radio" name="<?= $name ?>" value="0" data-p="<?= $p['id'] ?>" <?= $r && $r['valor'] !== null && (float)$r['valor'] === 0.0 ? 'checked' : '' ?>> No</label></div>
              <?php elseif ($p['tipo'] === 'opcion_multiple'): ?>
                <select name="<?= $name ?>" class="form-select" data-p="<?= $p['id'] ?>"><option value="">Seleccione...</option>
                  <?php foreach ($p['opciones'] as $o): ?><option <?= $r && $r['texto'] === $o ? 'selected' : '' ?>><?= e($o) ?></option><?php endforeach; ?></select>
              <?php elseif ($p['tipo'] === 'numerica'): ?>
                <input type="number" step="any" name="<?= $name ?>" class="form-control" style="max-width:200px" data-p="<?= $p['id'] ?>" value="<?= e($r['valor'] ?? '') ?>">
              <?php else: ?>
                <textarea name="<?= $name ?>" class="form-control" rows="3" maxlength="5000" data-p="<?= $p['id'] ?>"><?= e($r['texto'] ?? '') ?></textarea>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
          <?php endforeach; ?>
          <div class="card public-card p-3 mt-3 d-flex flex-row justify-content-between align-items-center">
            <span class="small text-muted">Al enviar ya no podrá modificar sus respuestas.</span>
            <button class="btn btn-primary btn-lg" onclick="return confirm('¿Enviar la evaluación? Ya no podrá modificarla.')">Enviar evaluación</button>
          </div>
        </form>
        <script>
        (function () {
          var form = document.getElementById('formEval'), estado = document.getElementById('estadoGuardado');
          var token = document.querySelector('meta[name=csrf-token]').content, timers = {};
          function guardar(el) {
            var fd = new FormData();
            fd.append('c', <?= json_encode($codigoEv) ?>); fd.append('asignacion_id', <?= (int)$asig['id'] ?>);
            fd.append('pregunta_id', el.getAttribute('data-p')); fd.append('valor', el.value); fd.append('_csrf', token);
            estado.textContent = 'Guardando...';
            fetch(<?= json_encode(url('e/guardar.php')) ?>, { method: 'POST', body: fd, credentials: 'same-origin' })
              .then(function (r) { return r.json(); })
              .then(function (j) { estado.textContent = j.ok ? 'Guardado ' + new Date().toLocaleTimeString() : (j.error || 'No se pudo guardar'); })
              .catch(function () { estado.textContent = 'Sin conexión: sus respuestas se enviarán al presionar "Enviar".'; });
            var box = el.closest('.pregunta'); if (box) { box.classList.remove('falta'); }
          }
          form.addEventListener('change', function (e) { if (e.target.hasAttribute('data-p')) { guardar(e.target); } });
          form.addEventListener('input', function (e) {
            var el = e.target; if (el.tagName !== 'TEXTAREA') { return; }
            clearTimeout(timers[el.name]); timers[el.name] = setTimeout(function () { guardar(el); }, 1200);
          });
        })();
        </script>
        <?php
        render_footer();
        exit;
    }
    $lista = db_all("SELECT a.*, CONCAT(d.nombre, ' ', d.apellido) AS evaluado, d.puesto FROM asignaciones a JOIN empleados d ON d.id = a.evaluado_id
                     WHERE a.evaluacion_id = ? AND a.evaluador_id = ? ORDER BY FIELD(a.relacion, 'auto','jefe','subordinado','par','cliente'), d.apellido", [$ev['id'], $empleado['id']]);
    $pend = count(array_filter($lista, fn($a) => $a['estatus'] !== 'completada'));
    $header();
    ?>
    <div class="card public-card p-4">
      <div class="d-flex justify-content-between align-items-start mb-3">
        <div><h2 class="h5 mb-0">Hola, <?= e($empleado['nombre']) ?></h2><div class="small text-muted"><?= e($empleado['email']) ?></div></div>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($codigoEv) ?>"><button name="accion" value="salir" class="btn btn-sm btn-outline-secondary">Salir</button></form>
      </div>
      <?php if ($ev['mensaje_bienvenida']): ?><div class="alert alert-light"><?= nl2br(e($ev['mensaje_bienvenida'])) ?></div><?php endif; ?>
      <?php if ($pend === 0): ?>
        <div class="alert alert-success mb-0">Completó todas sus evaluaciones. ¡Gracias por su participación!</div>
      <?php else: ?>
        <p>Tiene <strong><?= $pend ?></strong> evaluación(es) pendiente(s) de <?= count($lista) ?>.</p>
        <div class="list-group">
        <?php foreach ($lista as $a): ?>
          <div class="list-group-item d-flex justify-content-between align-items-center">
            <div><strong><?= $a['relacion'] === 'auto' ? 'Autoevaluación' : e($a['evaluado']) ?></strong>
              <div class="small text-muted"><?= e(etiqueta_relacion($a['relacion'])) ?><?= $a['relacion'] !== 'auto' && $a['puesto'] ? ' · ' . e($a['puesto']) : '' ?></div></div>
            <div><?php if ($a['estatus'] === 'completada'): ?><span class="badge text-bg-success">Completada</span>
              <?php else: ?><a class="btn btn-sm btn-primary" href="<?= e(url($self . '&a=' . $a['id'])) ?>"><?= $a['estatus'] === 'en_progreso' ? 'Continuar' : 'Responder' ?></a><?php endif; ?></div>
          </div>
        <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php
    render_footer();
    exit;
}

$header();
if (($estado['paso'] ?? '') === 'codigo'):
    $espera = 0;
    if ($empleado) {
        $ult = db_val('SELECT created_at FROM codigos_verificacion WHERE evaluacion_id = ? AND empleado_id = ? ORDER BY id DESC LIMIT 1', [$ev['id'], $empleado['id']]);
        $espera = $ult ? max(0, (int)config('otp.segundos_reenvio') - (time() - strtotime($ult))) : 0;
    }
?>
<div class="row justify-content-center"><div class="col-md-7"><div class="card public-card p-4">
  <h2 class="h5">Ingrese el código de verificación</h2>
  <p class="small text-muted">Enviamos un código de 6 dígitos a <strong><?= e($estado['email'] ?? '') ?></strong>. Revise también su carpeta de correo no deseado.</p>
  <?php if ($codigoDev): ?><div class="alert alert-warning small">Modo desarrollo &mdash; código: <strong><?= e($codigoDev) ?></strong></div><?php endif; ?>
  <form method="post" class="mb-3"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($codigoEv) ?>"><input type="hidden" name="accion" value="verificar">
    <input name="codigo" class="form-control otp-input mb-3" inputmode="numeric" autocomplete="one-time-code" maxlength="6" pattern="\d{6}" required autofocus placeholder="______">
    <button class="btn btn-primary w-100">Verificar e ingresar</button>
  </form>
  <div class="d-flex justify-content-between">
    <form method="post"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($codigoEv) ?>">
      <button name="accion" value="reenviar" id="btnReenviar" class="btn btn-link p-0" <?= $espera ? 'disabled' : '' ?>>Reenviar código<span id="cuenta"><?= $espera ? " ($espera s)" : '' ?></span></button></form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($codigoEv) ?>"><button name="accion" value="salir" class="btn btn-link p-0 text-muted">Usar otro correo</button></form>
  </div>
</div></div></div>
<script>
(function () { var s = <?= (int)$espera ?>, b = document.getElementById('btnReenviar'), c = document.getElementById('cuenta');
  if (!s) { return; } var t = setInterval(function () { s--; c.textContent = s > 0 ? ' (' + s + ' s)' : ''; if (s <= 0) { b.disabled = false; clearInterval(t); } }, 1000); })();
</script>
<?php else: ?>
<div class="row justify-content-center"><div class="col-md-7"><div class="card public-card p-4">
  <h2 class="h5">Bienvenido(a)</h2>
  <p>Para ingresar a la evaluación escriba su correo electrónico. Le enviaremos un código de verificación; no necesita contraseña.</p>
  <form method="post"><?= csrf_field() ?><input type="hidden" name="c" value="<?= e($codigoEv) ?>"><input type="hidden" name="accion" value="email">
    <input type="email" name="email" class="form-control form-control-lg mb-3" placeholder="nombre@empresa.com" required autofocus>
    <button class="btn btn-primary btn-lg w-100">Continuar</button>
  </form>
  <p class="small text-muted mt-3 mb-0">Sus respuestas son confidenciales y se reportan de forma agregada.</p>
</div></div></div>
<?php endif;
render_footer();
