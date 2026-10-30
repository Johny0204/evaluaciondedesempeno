<?php
// Copie este archivo como config.php y ajuste los valores.
return [
    'app_nombre'   => 'Evaluación de Desempeño',
    'consultora'   => 'Consultora',
    // URL pública base, sin "/" final. Ej: https://evaluaciones.miconsultora.com
    'base_url'     => 'http://localhost/evaluaciondedesempeno',
    'zona_horaria' => 'America/Guatemala',
    // true: muestra errores y el código OTP en pantalla (sólo para pruebas)
    'modo_desarrollo' => true,
    // true: muestra en pantalla el código de verificación y contraseñas temporales (sólo pruebas, sin SMTP)
    'mostrar_codigos' => true,

    'db' => [
        'host'     => '127.0.0.1',
        'port'     => 3306,
        'nombre'   => 'evaluaciondesempeno',
        'usuario'  => 'root',
        'password' => '',
    ],

    // Correo: 'log' guarda los correos en storage/logs/correo.log (pruebas)
    //         'smtp' los envía por el servidor SMTP configurado
    'correo' => [
        'modo'            => 'log',
        'host'            => 'smtp.ejemplo.com',
        'puerto'          => 587,
        'seguridad'       => 'tls',   // 'tls' (STARTTLS), 'ssl' o ''
        'usuario'         => '',
        'password'        => '',
        'remitente'       => 'no-responder@ejemplo.com',
        'remitente_nombre'=> 'Evaluación de Desempeño',
        'lote_por_ejecucion' => 50,  // correos por ejecución del procesador de cola
    ],

    'otp' => [
        'minutos_validez'     => 15,
        'max_intentos'        => 5,
        'segundos_reenvio'    => 60,
        'max_envios_por_hora' => 5,
    ],

    'validar_nit'  => true,   // valida dígito verificador del NIT (Guatemala)
    'cron_clave'   => 'cambie-esta-clave-larga', // para ejecutar cron/procesar_correos.php vía URL
];
