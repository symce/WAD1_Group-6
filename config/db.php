<?php
/**
 * Database configuration + PDO connection (single shared connection per request).
 * The SQLite file lives in /database/app.sqlite and is blocked from direct web access.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('DB_PATH', APP_ROOT . '/database/app.sqlite');
define('SCHEMA_PATH', APP_ROOT . '/database/schema.sql');
define('UPLOADS_PATH', APP_ROOT . '/uploads');
define('STORAGE_PATH', APP_ROOT . '/storage');
define('DEFAULT_IMAGE', 'Tnalak.png');

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // SQLite only enforces foreign keys when this is switched on for EVERY connection.
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}
