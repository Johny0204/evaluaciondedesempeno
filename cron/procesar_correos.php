<?php
// Procesa la cola de correos. Programar cada 1-5 minutos:
//   CLI (cPanel/Linux):  php /ruta/evaluaciondedesempeno/cron/procesar_correos.php
//   Windows (Programador de tareas): C:\xampp\php\php.exe C:\xampp\htdocs\evaluaciondedesempeno\cron\procesar_correos.php
//   URL (si el hosting sólo permite cron por URL): copie cron/procesar_correos.php como procesar_correos_web.php en la raíz o use
//   https://su-dominio/evaluaciondedesempeno/cron_web.php?clave=SU_CLAVE
require __DIR__ . '/../app/bootstrap.php';
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Sólo CLI');
}
$r = correo_procesar_cola();
db_exec('DELETE FROM intentos_acceso WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 86400)]);
echo date('c') . " enviados={$r['enviados']} errores={$r['errores']}\n";
