-- =====================================================================
-- Datos iniciales (catálogos, formularios de ejemplo y usuario admin)
-- Ejecutar después de 02_crear_tablas.sql
-- Usuario inicial:  admin@consultora.com  /  Admin123*
-- (el sistema obliga a cambiar la contraseña en el primer ingreso)
-- =====================================================================
USE evaluaciondesempeno;
SET NAMES utf8mb4;

INSERT INTO usuarios (nombre, email, password_hash, rol, debe_cambiar_password)
VALUES ('Administrador', 'admin@consultora.com', '$2y$10$PO1imHWlae3bw0UQPaNzpur1W6eaUzRiqW.5.4do9N3AXgUKthjYG', 'superadmin', 1);

INSERT INTO tipos_empresa (nombre) VALUES ('Empresa privada'), ('Sector público'), ('ONG / Sin fines de lucro'), ('Otra');

INSERT INTO escalas (nombre, descripcion) VALUES ('Escala 1 a 5', 'Escala estándar de desempeño');
SET @esc = LAST_INSERT_ID();
INSERT INTO escala_valores (escala_id, valor, etiqueta, orden) VALUES
 (@esc, 1, 'No cumple', 1),
 (@esc, 2, 'Cumple parcialmente', 2),
 (@esc, 3, 'Cumple', 3),
 (@esc, 4, 'Supera', 4),
 (@esc, 5, 'Excepcional', 5);

INSERT INTO categorias (nombre) VALUES
 ('Orientación a resultados'), ('Trabajo en equipo'), ('Comunicación'),
 ('Responsabilidad y compromiso'), ('Liderazgo'), ('Servicio al ciudadano / cliente'),
 ('Ética y transparencia'), ('Comentarios generales');

-- Formularios de ejemplo
INSERT INTO tipos_formulario (nombre, descripcion, escala_id) VALUES
 ('Empresa privada', 'Formulario base para empresas privadas', @esc),
 ('Sector público', 'Formulario base para instituciones públicas', @esc);
SET @fpriv = (SELECT id FROM tipos_formulario WHERE nombre='Empresa privada');
SET @fpub  = (SELECT id FROM tipos_formulario WHERE nombre='Sector público');

INSERT INTO secciones (tipo_formulario_id, nombre, peso, orden) VALUES
 (@fpriv, 'Competencias', 100, 1), (@fpriv, 'Comentarios', 0, 2),
 (@fpub,  'Competencias', 100, 1), (@fpub,  'Comentarios', 0, 2);
SET @spriv_c = (SELECT id FROM secciones WHERE tipo_formulario_id=@fpriv AND orden=1);
SET @spriv_o = (SELECT id FROM secciones WHERE tipo_formulario_id=@fpriv AND orden=2);
SET @spub_c  = (SELECT id FROM secciones WHERE tipo_formulario_id=@fpub AND orden=1);
SET @spub_o  = (SELECT id FROM secciones WHERE tipo_formulario_id=@fpub AND orden=2);

INSERT INTO preguntas (texto, texto_auto, categoria_id, tipo_respuesta) VALUES
 ('Cumple con las metas y objetivos asignados en el tiempo establecido.', 'Cumplo con las metas y objetivos asignados en el tiempo establecido.', (SELECT id FROM categorias WHERE nombre='Orientación a resultados'), 'escala'),
 ('Colabora con sus compañeros para lograr los objetivos del equipo.', 'Colaboro con mis compañeros para lograr los objetivos del equipo.', (SELECT id FROM categorias WHERE nombre='Trabajo en equipo'), 'escala'),
 ('Se comunica de forma clara y oportuna.', 'Me comunico de forma clara y oportuna.', (SELECT id FROM categorias WHERE nombre='Comunicación'), 'escala'),
 ('Es puntual y responsable con sus compromisos.', 'Soy puntual y responsable con mis compromisos.', (SELECT id FROM categorias WHERE nombre='Responsabilidad y compromiso'), 'escala'),
 ('Orienta y motiva a su equipo de trabajo.', 'Oriento y motivo a mi equipo de trabajo.', (SELECT id FROM categorias WHERE nombre='Liderazgo'), 'escala'),
 ('Atiende a los ciudadanos con respeto, empatía y eficiencia.', 'Atiendo a los ciudadanos con respeto, empatía y eficiencia.', (SELECT id FROM categorias WHERE nombre='Servicio al ciudadano / cliente'), 'escala'),
 ('Actúa con apego a la ley, la ética y la transparencia.', 'Actúo con apego a la ley, la ética y la transparencia.', (SELECT id FROM categorias WHERE nombre='Ética y transparencia'), 'escala'),
 ('Busca la satisfacción del cliente y la rentabilidad del negocio.', 'Busco la satisfacción del cliente y la rentabilidad del negocio.', (SELECT id FROM categorias WHERE nombre='Servicio al ciudadano / cliente'), 'escala'),
 ('Principales fortalezas observadas.', 'Mis principales fortalezas.', (SELECT id FROM categorias WHERE nombre='Comentarios generales'), 'abierta'),
 ('Áreas de mejora sugeridas.', 'Áreas en las que puedo mejorar.', (SELECT id FROM categorias WHERE nombre='Comentarios generales'), 'abierta');

-- Asignación de preguntas a formularios (reutilizables)
INSERT INTO formulario_pregunta (tipo_formulario_id, pregunta_id, seccion_id, orden, peso, obligatoria, aplica_a)
SELECT @fpriv, id, @spriv_c, id, 1, 1, 'auto,jefe,par,subordinado,cliente' FROM preguntas WHERE texto IN (
 'Cumple con las metas y objetivos asignados en el tiempo establecido.',
 'Colabora con sus compañeros para lograr los objetivos del equipo.',
 'Se comunica de forma clara y oportuna.',
 'Es puntual y responsable con sus compromisos.',
 'Busca la satisfacción del cliente y la rentabilidad del negocio.');
INSERT INTO formulario_pregunta (tipo_formulario_id, pregunta_id, seccion_id, orden, peso, obligatoria, aplica_a)
SELECT @fpriv, id, @spriv_c, id, 1, 1, 'jefe,subordinado' FROM preguntas WHERE texto = 'Orienta y motiva a su equipo de trabajo.';
INSERT INTO formulario_pregunta (tipo_formulario_id, pregunta_id, seccion_id, orden, peso, obligatoria, aplica_a)
SELECT @fpub, id, @spub_c, id, 1, 1, 'auto,jefe,par,subordinado,cliente' FROM preguntas WHERE texto IN (
 'Cumple con las metas y objetivos asignados en el tiempo establecido.',
 'Colabora con sus compañeros para lograr los objetivos del equipo.',
 'Se comunica de forma clara y oportuna.',
 'Es puntual y responsable con sus compromisos.',
 'Atiende a los ciudadanos con respeto, empatía y eficiencia.',
 'Actúa con apego a la ley, la ética y la transparencia.');
INSERT INTO formulario_pregunta (tipo_formulario_id, pregunta_id, seccion_id, orden, peso, obligatoria, aplica_a)
SELECT f.id, p.id, s.id, 100 + p.id, 0, 0, 'auto,jefe,par,subordinado,cliente'
FROM preguntas p
JOIN tipos_formulario f ON f.id IN (@fpriv, @fpub)
JOIN secciones s ON s.tipo_formulario_id = f.id AND s.orden = 2
WHERE p.tipo_respuesta = 'abierta';
