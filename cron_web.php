<?php
// Procesador de cola de correos invocable por URL (para hostings sin cron por línea de comandos).
// Uso: https://su-dominio/evaluaciondedesempeno/cron_web.php?clave=CLAVE_DE_config.php
require __DIR__ . '/app/bootstrap.php';
if (!hash_equals((string)config('cron_clave'), (string)($_GET['clave'] ?? '')) || config('cron_clave') === 'cambie-esta-clave-larga') {
    http_response_code(403);
    exit('Clave inválida o no configurada.');
}
$r = correo_procesar_cola();
header('Content-Type: text/plain; charset=utf-8');
echo date('c') . " enviados={$r['enviados']} errores={$r['errores']}\n";
