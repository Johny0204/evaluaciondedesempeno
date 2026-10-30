-- =====================================================================
-- Sistema de Evaluación de Desempeño - Creación de base de datos
-- Ejecutar primero. Compatible con MySQL 5.7+/8 y MariaDB 10.3+ (XAMPP)
-- =====================================================================
CREATE DATABASE IF NOT EXISTS evaluaciondesempeno
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

-- (Opcional) usuario dedicado para la aplicación. Cambie la contraseña.
-- CREATE USER IF NOT EXISTS 'evaluacion_app'@'localhost' IDENTIFIED BY 'CambieEstaClave2026!';
-- GRANT SELECT, INSERT, UPDATE, DELETE ON evaluaciondesempeno.* TO 'evaluacion_app'@'localhost';
-- FLUSH PRIVILEGES;
