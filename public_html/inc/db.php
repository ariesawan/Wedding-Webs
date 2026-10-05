<?php
/**
 * Koneksi PDO tunggal.
 * EMULATE_PREPARES=false -> prepared statement benar-benar dikirim ke server MySQL,
 * jadi parameter tidak pernah digabung ke string SQL (anti SQL injection yang sesungguhnya).
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET);
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    } catch (PDOException $e) {
        if (APP_ENV === 'development') die('DB error: ' . $e->getMessage());
        error_log('DB connect failed: ' . $e->getMessage());
        http_response_code(503);
        die('Layanan sedang tidak tersedia. Coba lagi beberapa saat lagi.');
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function one(string $sql, array $params = []): ?array
{
    $row = q($sql, $params)->fetch();
    return $row === false ? null : $row;
}

function all(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}

function insertId(): int
{
    return (int) db()->lastInsertId();
}
