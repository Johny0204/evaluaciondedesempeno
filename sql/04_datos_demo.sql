-- =====================================================================
-- Datos de DEMOSTRACIÓN (opcional). Ejecutar después de 03_datos_iniciales.sql
-- Crea: empresa "Comercial Demo, S.A.", 2 contactos, 1 jefe + 5 empleados,
--       formulario "Demo - 10 preguntas" y una evaluación 180° en borrador
--       con participantes y asignaciones listas para publicar.
-- Se puede volver a ejecutar: borra y recrea los datos demo.
-- =====================================================================
USE evaluaciondesempeno;
SET NAMES utf8mb4;

-- Limpieza de una ejecución anterior
DELETE FROM empresas WHERE nit = '76543218';
DELETE p FROM preguntas p
  JOIN formulario_pregunta fp ON fp.pregunta_id = p.id
  JOIN tipos_formulario tf ON tf.id = fp.tipo_formulario_id
 WHERE tf.nombre = 'Demo - 10 preguntas';
DELETE FROM tipos_formulario WHERE nombre = 'Demo - 10 preguntas';

-- Empresa y contactos
INSERT INTO empresas (nombre, nombre_comercial, nit, tipo_empresa_id, sector, direccion)
VALUES ('Comercial Demo, S.A.', 'Comercial Demo', '76543218',
        (SELECT id FROM tipos_empresa WHERE nombre = 'Empresa privada'), 'Comercio', 'Ciudad de Guatemala');
SET @emp = LAST_INSERT_ID();

INSERT INTO empresa_contactos (empresa_id, nombre, puesto, email, telefono) VALUES
 (@emp, 'Laura Méndez', 'Gerente de RRHH', 'laura.mendez@comercialdemo.com', '5555-0001'),
 (@emp, 'Roberto Castillo', 'Gerente General', 'roberto.castillo@comercialdemo.com', '5555-0002');

INSERT INTO areas (empresa_id, nombre) VALUES (@emp, 'Ventas');
SET @area = LAST_INSERT_ID();

-- 1 jefe + 5 colaboradores
INSERT INTO empleados (empresa_id, codigo, nombre, apellido, email, area_id, puesto, nivel, sexo, fecha_ingreso)
VALUES (@emp, 'D001', 'Sofía', 'Ramírez', 'sofia.ramirez@comercialdemo.com', @area, 'Jefa de Ventas', 'Mando medio', 'F', '2017-03-01');
SET @jefe = LAST_INSERT_ID();

INSERT INTO empleados (empresa_id, codigo, nombre, apellido, email, area_id, puesto, nivel, jefe_id, sexo, fecha_ingreso) VALUES
 (@emp, 'D002', 'Diego',   'Morales',  'diego.morales@comercialdemo.com',  @area, 'Ejecutivo de ventas', 'Operativo', @jefe, 'M', '2020-02-10'),
 (@emp, 'D003', 'Andrea',  'Flores',   'andrea.flores@comercialdemo.com',  @area, 'Ejecutiva de ventas', 'Operativo', @jefe, 'F', '2021-05-17'),
 (@emp, 'D004', 'Javier',  'Herrera',  'javier.herrera@comercialdemo.com', @area, 'Ejecutivo de ventas', 'Operativo', @jefe, 'M', '2019-08-05'),
 (@emp, 'D005', 'Gabriela','Cruz',     'gabriela.cruz@comercialdemo.com',  @area, 'Asistente comercial', 'Operativo', @jefe, 'F', '2022-01-12'),
 (@emp, 'D006', 'Miguel',  'Reyes',    'miguel.reyes@comercialdemo.com',   @area, 'Ejecutivo de ventas', 'Operativo', @jefe, 'M', '2023-04-03');

-- Formulario de 10 preguntas (8 de escala + 2 abiertas)
INSERT INTO tipos_formulario (nombre, descripcion, escala_id)
VALUES ('Demo - 10 preguntas', 'Formulario de demostración', (SELECT id FROM escalas ORDER BY id LIMIT 1));
SET @tf = LAST_INSERT_ID();
INSERT INTO secciones (tipo_formulario_id, nombre, peso, orden) VALUES (@tf, 'Competencias', 100, 1);
SET @sc = LAST_INSERT_ID();
INSERT INTO secciones (tipo_formulario_id, nombre, peso, orden) VALUES (@tf, 'Comentarios', 0, 2);
SET @so = LAST_INSERT_ID();

INSERT INTO preguntas (texto, texto_auto, categoria_id, tipo_respuesta, ayuda) VALUES
 ('Alcanza las metas de ventas asignadas.', 'Alcanzo las metas de ventas asignadas.', (SELECT id FROM categorias WHERE nombre='Orientación a resultados'), 'escala', 'Considere el período evaluado completo.'),
 ('Organiza su trabajo y cumple los plazos.', 'Organizo mi trabajo y cumplo los plazos.', (SELECT id FROM categorias WHERE nombre='Orientación a resultados'), 'escala', NULL),
 ('Comparte información y apoya a sus compañeros.', 'Comparto información y apoyo a mis compañeros.', (SELECT id FROM categorias WHERE nombre='Trabajo en equipo'), 'escala', NULL),
 ('Mantiene buenas relaciones con el equipo.', 'Mantengo buenas relaciones con el equipo.', (SELECT id FROM categorias WHERE nombre='Trabajo en equipo'), 'escala', NULL),
 ('Escucha y se expresa con claridad.', 'Escucho y me expreso con claridad.', (SELECT id FROM categorias WHERE nombre='Comunicación'), 'escala', NULL),
 ('Atiende a los clientes con cortesía y rapidez.', 'Atiendo a los clientes con cortesía y rapidez.', (SELECT id FROM categorias WHERE nombre='Servicio al ciudadano / cliente'), 'escala', NULL),
 ('Cumple las políticas y normas de la empresa.', 'Cumplo las políticas y normas de la empresa.', (SELECT id FROM categorias WHERE nombre='Responsabilidad y compromiso'), 'escala', NULL),
 ('Propone mejoras en los procesos de trabajo.', 'Propongo mejoras en los procesos de trabajo.', (SELECT id FROM categorias WHERE nombre='Responsabilidad y compromiso'), 'escala', NULL),
 ('¿Qué es lo que mejor hace esta persona?', '¿Qué es lo que mejor hago?', (SELECT id FROM categorias WHERE nombre='Comentarios generales'), 'abierta', NULL),
 ('¿Qué debería mejorar en el próximo período?', '¿Qué debería mejorar en el próximo período?', (SELECT id FROM categorias WHERE nombre='Comentarios generales'), 'abierta', NULL);
SET @p1 = LAST_INSERT_ID();  -- MySQL devuelve el id de la PRIMERA fila del INSERT múltiple

INSERT INTO formulario_pregunta (tipo_formulario_id, pregunta_id, seccion_id, orden, peso, obligatoria, aplica_a)
SELECT @tf, p.id,
       IF(p.tipo_respuesta = 'abierta', @so, @sc),
       p.id - @p1 + 1,
       IF(p.tipo_respuesta = 'abierta', 0, 1),
       IF(p.tipo_respuesta = 'abierta', 0, 1),
       'auto,jefe,par,subordinado,cliente'
  FROM preguntas p WHERE p.id BETWEEN @p1 AND @p1 + 9;

-- Evaluación 180° en borrador (abre hoy, cierra en 30 días)
INSERT INTO evaluaciones (empresa_id, nombre, periodo_inicio, periodo_fin, fecha_apertura, fecha_cierre, tipo, tipo_formulario_id,
                          liga_compensacion, compensacion_tipos, pond_jefe, pond_auto, pond_par, pond_subordinado, anonimato_min, max_pares,
                          codigo_publico, mensaje_bienvenida, estatus)
VALUES (@emp, 'Evaluación de desempeño DEMO', MAKEDATE(YEAR(CURDATE()), 1), LAST_DAY(DATE_ADD(MAKEDATE(YEAR(CURDATE()), 1), INTERVAL 11 MONTH)),
        NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), '180', @tf, 1, 'bonos', 70, 30, 0, 0, 3, 3,
        'DEMO2026', 'Gracias por participar en la evaluación de desempeño de Comercial Demo.', 'borrador');
SET @ev = LAST_INSERT_ID();

INSERT INTO participantes (evaluacion_id, empleado_id) SELECT @ev, id FROM empleados WHERE empresa_id = @emp;
-- Autoevaluación de todos
INSERT INTO asignaciones (evaluacion_id, evaluador_id, evaluado_id, relacion) SELECT @ev, id, id, 'auto' FROM empleados WHERE empresa_id = @emp;
-- La jefa evalúa a cada colaborador
INSERT INTO asignaciones (evaluacion_id, evaluador_id, evaluado_id, relacion) SELECT @ev, jefe_id, id, 'jefe' FROM empleados WHERE empresa_id = @emp AND jefe_id IS NOT NULL;

SELECT 'Datos demo creados' AS resultado,
       (SELECT COUNT(*) FROM empleados WHERE empresa_id = @emp) AS empleados,
       (SELECT COUNT(*) FROM formulario_pregunta WHERE tipo_formulario_id = @tf) AS preguntas,
       (SELECT COUNT(*) FROM asignaciones WHERE evaluacion_id = @ev) AS asignaciones;
