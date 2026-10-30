# Sistema de Evaluación de Desempeño (multiempresa)

Plataforma web para que una consultora evalúe el desempeño de los empleados de varias empresas cliente.
Hecha con **PHP 8 sin frameworks + MySQL/MariaDB + HTML** (Bootstrap y Chart.js incluidos localmente en `assets/`, sin CDN).

## Requisitos
- PHP **8.0 o superior** con las extensiones `pdo_mysql`, `mbstring`, `openssl`, `zip` (Excel) y `simplexml`.
- MySQL 5.7+/8 o MariaDB 10.3+.
- Apache con `.htaccess` habilitado (XAMPP lo trae así).

## Instalación en XAMPP (Windows)
1. Copie la carpeta en `C:\xampp\htdocs\evaluaciondedesempeno`.
2. Inicie **Apache** y **MySQL** desde el panel de XAMPP.
3. Ejecute los scripts SQL **en este orden**, desde phpMyAdmin (http://localhost/phpmyadmin, pestaña *Importar*) o por consola:
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\evaluaciondedesempeno\sql\01_crear_base_datos.sql
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\evaluaciondedesempeno\sql\02_crear_tablas.sql
   C:\xampp\mysql\bin\mysql.exe -u root < C:\xampp\htdocs\evaluaciondedesempeno\sql\03_datos_iniciales.sql
   ```
4. Revise `config/config.php` (base de datos, URL base, correo). Si no existe, copie `config/config.example.php` como `config/config.php`.
5. Abra http://localhost/evaluaciondedesempeno e ingrese con **admin@consultora.com / Admin123*** (el sistema pide cambiarla al primer ingreso).

## Instalación en hosting (cPanel u otro)
1. Cree la base de datos y el usuario en el panel; ejecute `02_crear_tablas.sql` y `03_datos_iniciales.sql` en phpMyAdmin
   (si el hosting no permite `CREATE DATABASE`, omita `01_...` y quite la línea `USE evaluaciondesempeno;` de los otros scripts o seleccione la base antes de importar).
2. Suba los archivos por FTP o el administrador de archivos.
3. En `config/config.php`: `base_url` con su dominio (https), datos de la base, `modo_desarrollo => false`, correo en modo `smtp`, y cambie `cron_clave`.
4. Programe el procesador de correos cada 1–5 minutos:
   - Cron por consola: `php /home/USUARIO/public_html/evaluaciondedesempeno/cron/procesar_correos.php`
   - Cron por URL: `https://su-dominio/evaluaciondedesempeno/cron_web.php?clave=SU_CLAVE`
   - Windows: Programador de tareas → `C:\xampp\php\php.exe C:\xampp\htdocs\evaluaciondedesempeno\cron\procesar_correos.php`

## Correo
- `modo => 'log'` (por defecto): los correos **no se envían**, se escriben en `storage/logs/correo.log`. Con `modo_desarrollo => true` el código de verificación también se muestra en pantalla para pruebas.
- `modo => 'smtp'`: configure host, puerto, usuario y contraseña. Para cientos o miles de correos se recomienda un servicio de envío de correos (Amazon SES, Brevo, Mailgun) con SPF/DKIM en el dominio.
- Los códigos de verificación se envían al momento; invitaciones y recordatorios van a una cola que se envía por lotes (botón *Sistema → Cola de correos* o el cron).

## Flujo de uso
1. **Formularios → Tipos de formulario / Banco de preguntas**: defina formularios (privada, pública, etc.); cada pregunta puede pertenecer a varios formularios. En cada formulario ajuste sección, orden, peso, si es obligatoria y a quién aplica (auto, jefe, par, subordinado).
2. **Empresas → Nueva empresa**: nombre, NIT (valida dígito verificador), tipo, mínimo 2 contactos; marque *Crear usuario de consulta* para dar acceso de sólo lectura a estadísticas.
3. **Empleados**: alta manual o importación CSV/Excel (plantilla descargable). Columna `email_jefe` necesaria para 90/180/270/360.
4. **Evaluaciones → Nueva**: período, fechas de apertura/cierre, tipo 90/180/270/360, formulario, ligada a bonos/aumentos/promociones, ponderaciones.
5. En la evaluación: *Participantes → Agregar todos → Generar asignaciones → Publicar y enviar invitaciones*.
6. Los empleados entran al enlace de la evaluación, escriben su correo, reciben un código de 6 dígitos (con botón *Reenviar código*) y responden. Si ya completaron todo, se les muestra un mensaje.
7. **Resultados**: gráficas, estadísticas, resultados por pregunta, área y persona, comentarios, reporte individual imprimible (PDF desde el navegador) y exportación a Excel.

## Estructura
```
admin/   Panel de administración (consultora) y consulta (cliente)
e/       Portal público de evaluación (correo + código)
api/     API REST JSON (ver docs/API.md)
app/     Núcleo: conexión, seguridad, correo, Excel, cálculos
config/  Configuración
cron/    Procesador de cola de correos
sql/     Scripts de base de datos
storage/ Logs y archivos temporales (debe tener permiso de escritura)
assets/  CSS/JS (Bootstrap, Chart.js)
```

## Cálculo de la calificación
- Sólo las preguntas de tipo *escala* suman puntaje. Puntaje de cada evaluación = promedio ponderado por pregunta dentro de cada sección y por el peso de cada sección.
- Calificación final del empleado = promedio ponderado por tipo de evaluador (jefe, auto, pares, subordinados). Si un tipo no tiene respuestas, su peso se reparte entre los demás.
- Pares y subordinados se muestran como "anónimo" si tienen menos respuestas que el *anonimato mínimo* de la evaluación.

## Seguridad incluida
Contraseñas con `password_hash`, bloqueo tras 5 intentos fallidos, protección CSRF, consultas preparadas (PDO), escape de HTML,
códigos de verificación de un solo uso con vencimiento e intentos limitados, límite de solicitudes por IP, bitácora de auditoría,
y `.htaccess` que bloquea `app/`, `config/`, `sql/`, `storage/` y `cron/`.
