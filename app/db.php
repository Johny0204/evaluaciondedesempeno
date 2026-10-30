<?php

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = config('db');
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'], (int)$c['port'], $c['nombre']);
        try {
            $pdo = new PDO($dsn, $c['usuario'], $c['password'], [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        } catch (PDOException $ex) {
            error_log('Conexión a base de datos fallida (' . $c['host'] . '/' . $c['nombre'] . '): ' . $ex->getMessage());
            db_error_conexion($ex);
        }
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function db_query(string $sql, array $params = []): PDOStatement
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

function db_all(string $sql, array $params = []): array
{
    return db_query($sql, $params)->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
    $row = db_query($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function db_val(string $sql, array $params = [])
{
    $v = db_query($sql, $params)->fetchColumn();
    return $v === false ? null : $v;
}

function db_exec(string $sql, array $params = []): int
{
    return db_query($sql, $params)->rowCount();
}

function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(',', $cols),
        implode(',', array_map(fn($c) => ':' . $c, $cols))
    );
    db_query($sql, $data);
    return (int)db()->lastInsertId();
}

function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $sets = [];
    $params = [];
    foreach ($data as $col => $val) {
        $sets[] = "$col = :set_$col";
        $params["set_$col"] = $val;
    }
    $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, implode(', ', $sets), $where);
    return db_exec($sql, array_merge($params, $whereParams));
}

/** Muestra un mensaje claro cuando no hay conexión a la base de datos (en lugar de un error 500 vacío). */
function db_error_conexion(PDOException $ex): void
{
    if (PHP_SAPI === 'cli') {
        throw $ex;
    }
    $detalle = config('modo_desarrollo') ? $ex->getMessage() : 'Revise host, nombre, usuario y contraseña en config/config.php. Detalle en storage/logs/php_errors.log';
    http_response_code(503);
    if (strpos($_SERVER['SCRIPT_NAME'] ?? '', '/api/') !== false) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['error' => 'No se pudo conectar a la base de datos'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8"><title>Error de conexión</title></head>'
        . '<body style="font-family:Arial,sans-serif;max-width:640px;margin:60px auto;padding:0 16px">'
        . '<h1 style="color:#1f4e79;font-size:22px">No se pudo conectar a la base de datos</h1>'
        . '<p>' . htmlspecialchars($detalle, ENT_QUOTES, 'UTF-8') . '</p></body></html>';
    exit;
}
