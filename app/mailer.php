<?php

/** Agrega un correo a la cola. Si $inmediato, intenta enviarlo de inmediato. */
function correo_encolar(string $para, string $asunto, string $html, string $tipo = 'otro', ?int $evaluacionId = null, ?int $empleadoId = null, bool $inmediato = false): int
{
    $id = db_insert('correos', [
        'evaluacion_id' => $evaluacionId,
        'empleado_id'   => $empleadoId,
        'destinatario'  => $para,
        'asunto'        => $asunto,
        'cuerpo'        => $html,
        'tipo'          => $tipo,
    ]);
    if ($inmediato) {
        correo_enviar_id($id);
    }
    return $id;
}

function correo_enviar_id(int $id): bool
{
    $c = db_one('SELECT * FROM correos WHERE id = ?', [$id]);
    if (!$c || $c['estatus'] === 'enviado') {
        return true;
    }
    try {
        correo_enviar($c['destinatario'], $c['asunto'], $c['cuerpo']);
        db_update('correos', ['estatus' => 'enviado', 'enviado_at' => now(), 'error' => null, 'intentos' => $c['intentos'] + 1], 'id = :id', ['id' => $id]);
        return true;
    } catch (Throwable $ex) {
        $intentos = $c['intentos'] + 1;
        db_update('correos', [
            'estatus'  => $intentos >= 3 ? 'error' : 'pendiente',
            'error'    => mb_substr($ex->getMessage(), 0, 500),
            'intentos' => $intentos,
        ], 'id = :id', ['id' => $id]);
        error_log('Correo ' . $id . ': ' . $ex->getMessage());
        return false;
    }
}

/** Procesa un lote de la cola de correos pendientes. */
function correo_procesar_cola(?int $limite = null): array
{
    $limite = $limite ?? (int)config('correo.lote_por_ejecucion', 50);
    $ids = db_all("SELECT id FROM correos WHERE estatus = 'pendiente' ORDER BY id LIMIT " . max(1, $limite));
    $ok = 0;
    $err = 0;
    foreach ($ids as $r) {
        correo_enviar_id((int)$r['id']) ? $ok++ : $err++;
    }
    return ['enviados' => $ok, 'errores' => $err];
}

function correo_enviar(string $para, string $asunto, string $html): void
{
    $cfg = config('correo');
    if (($cfg['modo'] ?? 'log') !== 'smtp') {
        $line = sprintf("==== %s | Para: %s | Asunto: %s\n%s\n\n", now(), $para, $asunto, trim(strip_tags(str_replace(['<br>', '</p>'], "\n", $html))));
        file_put_contents(APP_ROOT . '/storage/logs/correo.log', $line, FILE_APPEND | LOCK_EX);
        return;
    }
    $smtp = new SmtpClient($cfg['host'], (int)$cfg['puerto'], $cfg['seguridad'] ?? 'tls', $cfg['usuario'] ?? '', $cfg['password'] ?? '');
    $smtp->send($cfg['remitente'], $cfg['remitente_nombre'] ?? '', $para, $asunto, $html);
}

/** Cliente SMTP mínimo (AUTH LOGIN, STARTTLS/SSL). Sin dependencias externas. */
class SmtpClient
{
    private $sock;

    public function __construct(private string $host, private int $port, private string $security, private string $user, private string $pass)
    {
    }

    public function send(string $from, string $fromName, string $to, string $subject, string $html): void
    {
        $remote = ($this->security === 'ssl' ? 'ssl://' : 'tcp://') . $this->host . ':' . $this->port;
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
        $this->sock = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $ctx);
        if (!$this->sock) {
            throw new RuntimeException("No se pudo conectar a SMTP: $errstr ($errno)");
        }
        stream_set_timeout($this->sock, 20);
        $this->expect(220);
        $ehlo = gethostname() ?: 'localhost';
        $this->cmd("EHLO $ehlo", 250);
        if ($this->security === 'tls') {
            $this->cmd('STARTTLS', 220);
            if (!stream_socket_enable_crypto($this->sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT)) {
                throw new RuntimeException('No se pudo iniciar TLS');
            }
            $this->cmd("EHLO $ehlo", 250);
        }
        if ($this->user !== '') {
            $this->cmd('AUTH LOGIN', 334);
            $this->cmd(base64_encode($this->user), 334);
            $this->cmd(base64_encode($this->pass), 235);
        }
        $this->cmd("MAIL FROM:<$from>", 250);
        $this->cmd("RCPT TO:<$to>", [250, 251]);
        $this->cmd('DATA', 354);
        $enc = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
        $headers = [
            'Date: ' . date('r'),
            'From: ' . ($fromName !== '' ? $enc($fromName) . " <$from>" : $from),
            "To: <$to>",
            'Subject: ' . $enc($subject),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $from)[1] ?? 'localhost') . '>',
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n.";
        $this->cmd($body, 250);
        $this->cmd('QUIT', 221);
        fclose($this->sock);
    }

    private function cmd(string $line, $expected): void
    {
        fwrite($this->sock, $line . "\r\n");
        $this->expect($expected);
    }

    private function expect($expected): void
    {
        $expected = (array)$expected;
        $resp = '';
        while (($line = fgets($this->sock, 515)) !== false) {
            $resp .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }
        $code = (int)substr($resp, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('SMTP: respuesta inesperada: ' . trim($resp));
        }
    }
}

function plantilla_correo(string $titulo, string $contenidoHtml): string
{
    $app = e(config('app_nombre'));
    $cons = e(config('consultora'));
    return <<<HTML
<div style="font-family:Arial,Helvetica,sans-serif;max-width:600px;margin:auto;border:1px solid #ddd;border-radius:8px;overflow:hidden">
  <div style="background:#1f4e79;color:#fff;padding:16px 20px;font-size:18px">{$app}</div>
  <div style="padding:20px;color:#222;line-height:1.5">
    <h2 style="margin-top:0;color:#1f4e79;font-size:18px">{$titulo}</h2>
    {$contenidoHtml}
  </div>
  <div style="background:#f3f3f3;padding:12px 20px;font-size:12px;color:#666">{$cons}. Este es un mensaje automático, no responda a este correo.</div>
</div>
HTML;
}
