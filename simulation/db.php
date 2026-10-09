<?php

// Shared by run.php and mysql-reset.php: one PDO to whatever database the app is
// pointed at. Reads connection details from the environment, then from .env.

function sim_env(string $key, ?string $default = null): ?string
{
    $v = getenv($key);
    if ($v !== false && $v !== '') {
        return $v;
    }

    static $dot = null;
    if ($dot === null) {
        $dot = [];
        foreach (@file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if ($line[0] !== '#' && str_contains($line, '=')) {
                [$k, $val] = explode('=', $line, 2);
                $dot[trim($k)] = trim($val, " \"'");
            }
        }
    }

    return $dot[$key] ?? $default;
}

function sim_driver(): string
{
    return sim_env('DB_CONNECTION', 'sqlite') === 'mysql' ? 'mysql' : 'sqlite';
}

function sim_mysql_dsn(?string $database): string
{
    return sprintf('mysql:host=%s;port=%s;charset=utf8mb4%s', sim_env('DB_HOST', '127.0.0.1'), sim_env('DB_PORT', '3306'), $database ? ';dbname=' . $database : '');
}

function sim_pdo(): PDO
{
    $pdo = sim_driver() === 'mysql'
        ? new PDO(sim_mysql_dsn(sim_env('DB_DATABASE')), sim_env('DB_USERNAME', 'root'), sim_env('DB_PASSWORD', ''))
        : new PDO('sqlite:' . sim_env('DB_DATABASE'));

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if (sim_driver() === 'sqlite') {
        $pdo->exec('PRAGMA busy_timeout = 5000');
    }

    return $pdo;
}
