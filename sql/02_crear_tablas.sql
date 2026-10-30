-- =====================================================================
-- Sistema de Evaluación de Desempeño - Tablas
-- Ejecutar después de 01_crear_base_datos.sql
-- =====================================================================
USE evaluaciondesempeno;
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Catálogos generales
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipos_empresa (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_tipos_empresa_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS escalas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS escala_valores (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  escala_id INT UNSIGNED NOT NULL,
  valor DECIMAL(6,2) NOT NULL,
  etiqueta VARCHAR(100) NOT NULL,
  descripcion VARCHAR(255) NULL,
  orden INT NOT NULL DEFAULT 0,
  KEY idx_escala_valores_escala (escala_id),
  CONSTRAINT fk_escala_valores_escala FOREIGN KEY (escala_id) REFERENCES escalas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS categorias (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(255) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uk_categorias_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Usuarios con credenciales (consultora y usuarios de consulta cliente)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS usuarios (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol ENUM('superadmin','consultor','consulta') NOT NULL DEFAULT 'consultor',
  empresa_id INT UNSIGNED NULL COMMENT 'Sólo para rol consulta',
  puede_ver_individual TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'Rol consulta: ver resultados por persona',
  debe_cambiar_password TINYINT(1) NOT NULL DEFAULT 1,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  intentos_fallidos INT NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultimo_acceso DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuarios_email (email),
  KEY idx_usuarios_empresa (empresa_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS api_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(100) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  ultimo_uso DATETIME NULL,
  expira_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_api_tokens_hash (token_hash),
  CONSTRAINT fk_api_tokens_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Empresas cliente
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS empresas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(200) NOT NULL,
  nombre_comercial VARCHAR(200) NULL,
  nit VARCHAR(20) NOT NULL,
  tipo_empresa_id INT UNSIGNED NULL,
  sector VARCHAR(120) NULL,
  direccion VARCHAR(255) NULL,
  consultor_id INT UNSIGNED NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_empresas_nit (nit),
  KEY idx_empresas_tipo (tipo_empresa_id),
  CONSTRAINT fk_empresas_tipo FOREIGN KEY (tipo_empresa_id) REFERENCES tipos_empresa(id) ON DELETE SET NULL,
  CONSTRAINT fk_empresas_consultor FOREIGN KEY (consultor_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE usuarios
  ADD CONSTRAINT fk_usuarios_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE;

CREATE TABLE IF NOT EXISTS empresa_contactos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  puesto VARCHAR(150) NULL,
  email VARCHAR(190) NOT NULL,
  telefono VARCHAR(40) NULL,
  usuario_id INT UNSIGNED NULL COMMENT 'Usuario de consulta creado para este contacto',
  KEY idx_contactos_empresa (empresa_id),
  CONSTRAINT fk_contactos_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  CONSTRAINT fk_contactos_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS areas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  UNIQUE KEY uk_areas_empresa_nombre (empresa_id, nombre),
  CONSTRAINT fk_areas_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Padrón de empleados (sin usuario/contraseña)
CREATE TABLE IF NOT EXISTS empleados (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  codigo VARCHAR(50) NULL,
  nombre VARCHAR(120) NOT NULL,
  apellido VARCHAR(120) NOT NULL,
  email VARCHAR(190) NOT NULL,
  area_id INT UNSIGNED NULL,
  puesto VARCHAR(150) NULL,
  nivel VARCHAR(80) NULL,
  jefe_id INT UNSIGNED NULL,
  sexo ENUM('M','F','O') NULL,
  fecha_ingreso DATE NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_empleados_empresa_email (empresa_id, email),
  KEY idx_empleados_jefe (jefe_id),
  KEY idx_empleados_area (area_id),
  CONSTRAINT fk_empleados_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  CONSTRAINT fk_empleados_area FOREIGN KEY (area_id) REFERENCES areas(id) ON DELETE SET NULL,
  CONSTRAINT fk_empleados_jefe FOREIGN KEY (jefe_id) REFERENCES empleados(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Formularios y banco de preguntas
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tipos_formulario (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre VARCHAR(150) NOT NULL,
  descripcion VARCHAR(255) NULL,
  escala_id INT UNSIGNED NOT NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_tipos_formulario_nombre (nombre),
  CONSTRAINT fk_tipos_formulario_escala FOREIGN KEY (escala_id) REFERENCES escalas(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS secciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_formulario_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(150) NOT NULL,
  peso DECIMAL(6,2) NOT NULL DEFAULT 100,
  orden INT NOT NULL DEFAULT 0,
  KEY idx_secciones_tipo (tipo_formulario_id),
  CONSTRAINT fk_secciones_tipo FOREIGN KEY (tipo_formulario_id) REFERENCES tipos_formulario(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS preguntas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  texto VARCHAR(500) NOT NULL COMMENT 'Redacción para evaluar a otra persona',
  texto_auto VARCHAR(500) NULL COMMENT 'Redacción alterna para autoevaluación',
  categoria_id INT UNSIGNED NULL,
  tipo_respuesta ENUM('escala','abierta','si_no','opcion_multiple','numerica') NOT NULL DEFAULT 'escala',
  opciones TEXT NULL COMMENT 'Opción múltiple: una opción por línea',
  ayuda VARCHAR(500) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_preguntas_categoria (categoria_id),
  CONSTRAINT fk_preguntas_categoria FOREIGN KEY (categoria_id) REFERENCES categorias(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Relación muchos a muchos pregunta <-> tipo de formulario
CREATE TABLE IF NOT EXISTS formulario_pregunta (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  tipo_formulario_id INT UNSIGNED NOT NULL,
  pregunta_id INT UNSIGNED NOT NULL,
  seccion_id INT UNSIGNED NULL,
  orden INT NOT NULL DEFAULT 0,
  peso DECIMAL(6,2) NOT NULL DEFAULT 1,
  obligatoria TINYINT(1) NOT NULL DEFAULT 1,
  aplica_a VARCHAR(60) NOT NULL DEFAULT 'auto,jefe,par,subordinado,cliente',
  UNIQUE KEY uk_formulario_pregunta (tipo_formulario_id, pregunta_id),
  KEY idx_fp_pregunta (pregunta_id),
  CONSTRAINT fk_fp_tipo FOREIGN KEY (tipo_formulario_id) REFERENCES tipos_formulario(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_pregunta FOREIGN KEY (pregunta_id) REFERENCES preguntas(id) ON DELETE CASCADE,
  CONSTRAINT fk_fp_seccion FOREIGN KEY (seccion_id) REFERENCES secciones(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Evaluaciones (proyecto por empresa y período)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS evaluaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  empresa_id INT UNSIGNED NOT NULL,
  nombre VARCHAR(200) NOT NULL,
  periodo_inicio DATE NOT NULL,
  periodo_fin DATE NOT NULL,
  fecha_apertura DATETIME NOT NULL,
  fecha_cierre DATETIME NOT NULL,
  tipo ENUM('90','180','270','360') NOT NULL DEFAULT '180',
  tipo_formulario_id INT UNSIGNED NOT NULL,
  liga_compensacion TINYINT(1) NOT NULL DEFAULT 0,
  compensacion_tipos VARCHAR(100) NULL COMMENT 'bonos,aumentos,promociones',
  pond_jefe DECIMAL(5,2) NOT NULL DEFAULT 60,
  pond_auto DECIMAL(5,2) NOT NULL DEFAULT 40,
  pond_par DECIMAL(5,2) NOT NULL DEFAULT 0,
  pond_subordinado DECIMAL(5,2) NOT NULL DEFAULT 0,
  anonimato_min INT NOT NULL DEFAULT 3,
  max_pares INT NOT NULL DEFAULT 3,
  codigo_publico VARCHAR(16) NOT NULL,
  mensaje_bienvenida TEXT NULL,
  snapshot_formulario LONGTEXT NULL COMMENT 'Copia JSON del formulario al publicar',
  estatus ENUM('borrador','publicada','cerrada') NOT NULL DEFAULT 'borrador',
  publicada_at DATETIME NULL,
  created_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_evaluaciones_codigo (codigo_publico),
  KEY idx_evaluaciones_empresa (empresa_id),
  CONSTRAINT fk_evaluaciones_empresa FOREIGN KEY (empresa_id) REFERENCES empresas(id) ON DELETE CASCADE,
  CONSTRAINT fk_evaluaciones_tipo FOREIGN KEY (tipo_formulario_id) REFERENCES tipos_formulario(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS participantes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluacion_id INT UNSIGNED NOT NULL,
  empleado_id INT UNSIGNED NOT NULL,
  calificacion_final DECIMAL(6,2) NULL,
  UNIQUE KEY uk_participantes (evaluacion_id, empleado_id),
  KEY idx_participantes_empleado (empleado_id),
  CONSTRAINT fk_participantes_evaluacion FOREIGN KEY (evaluacion_id) REFERENCES evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_participantes_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quién evalúa a quién
CREATE TABLE IF NOT EXISTS asignaciones (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluacion_id INT UNSIGNED NOT NULL,
  evaluador_id INT UNSIGNED NOT NULL,
  evaluado_id INT UNSIGNED NOT NULL,
  relacion ENUM('auto','jefe','par','subordinado','cliente') NOT NULL,
  estatus ENUM('pendiente','en_progreso','completada') NOT NULL DEFAULT 'pendiente',
  puntaje DECIMAL(6,2) NULL,
  iniciada_at DATETIME NULL,
  enviada_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uk_asignaciones (evaluacion_id, evaluador_id, evaluado_id, relacion),
  KEY idx_asignaciones_evaluador (evaluacion_id, evaluador_id),
  KEY idx_asignaciones_evaluado (evaluacion_id, evaluado_id),
  CONSTRAINT fk_asig_evaluacion FOREIGN KEY (evaluacion_id) REFERENCES evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_asig_evaluador FOREIGN KEY (evaluador_id) REFERENCES empleados(id) ON DELETE CASCADE,
  CONSTRAINT fk_asig_evaluado FOREIGN KEY (evaluado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS respuestas (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  asignacion_id INT UNSIGNED NOT NULL,
  pregunta_id INT UNSIGNED NOT NULL,
  valor DECIMAL(10,2) NULL,
  texto TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_respuestas (asignacion_id, pregunta_id),
  CONSTRAINT fk_respuestas_asignacion FOREIGN KEY (asignacion_id) REFERENCES asignaciones(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Códigos de verificación (OTP) para acceso sin contraseña
CREATE TABLE IF NOT EXISTS codigos_verificacion (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluacion_id INT UNSIGNED NOT NULL,
  empleado_id INT UNSIGNED NOT NULL,
  codigo_hash VARCHAR(255) NOT NULL,
  expira_at DATETIME NOT NULL,
  intentos INT NOT NULL DEFAULT 0,
  usado TINYINT(1) NOT NULL DEFAULT 0,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_codigos_lookup (evaluacion_id, empleado_id, usado),
  CONSTRAINT fk_codigos_evaluacion FOREIGN KEY (evaluacion_id) REFERENCES evaluaciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_codigos_empleado FOREIGN KEY (empleado_id) REFERENCES empleados(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Control de intentos por IP (anti abuso)
CREATE TABLE IF NOT EXISTS intentos_acceso (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave VARCHAR(100) NOT NULL,
  ip VARCHAR(45) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_intentos (clave, ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cola y registro de correos
CREATE TABLE IF NOT EXISTS correos (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  evaluacion_id INT UNSIGNED NULL,
  empleado_id INT UNSIGNED NULL,
  destinatario VARCHAR(190) NOT NULL,
  asunto VARCHAR(255) NOT NULL,
  cuerpo MEDIUMTEXT NOT NULL,
  tipo ENUM('invitacion','codigo','recordatorio','usuario','otro') NOT NULL DEFAULT 'otro',
  estatus ENUM('pendiente','enviado','error') NOT NULL DEFAULT 'pendiente',
  intentos INT NOT NULL DEFAULT 0,
  error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  enviado_at DATETIME NULL,
  KEY idx_correos_estatus (estatus),
  KEY idx_correos_evaluacion (evaluacion_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora de auditoría
CREATE TABLE IF NOT EXISTS auditoria (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  empleado_id INT UNSIGNED NULL,
  accion VARCHAR(80) NOT NULL,
  entidad VARCHAR(80) NULL,
  entidad_id INT UNSIGNED NULL,
  detalle TEXT NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_auditoria_fecha (created_at),
  KEY idx_auditoria_entidad (entidad, entidad_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
