<?php
$env = parse_ini_file(__DIR__ . '/../.env');


$dsn = sprintf(
    'pgsql:host=%s;port=%s;dbname=%s;%s',
    $env['DB_HOST'],
    $env['DB_PORT'],
    $env['DB_NAME'],
    ($env['DB_SSLMODE'] === 'require')
        ? 'sslmode=require'
        : 'sslmode=verify-ca;sslrootcert=' . $env['DB_SSLROOTCERT']
);

try {
    $pdo = new PDO($dsn, $env['DB_USER'], $env['DB_PASS'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    // short English comment: show connection error and stop
    echo "Greska pri konekciji: " . $e->getMessage();
    exit;
}

// short English comment: return PDO to caller
return $pdo;
