<?php

// Drops and recreates the simulation database. Refuses any name not ending in
// _sim, because this runs on a shared server.

require __DIR__ . '/db.php';

$name = sim_env('DB_DATABASE', '');

if (! preg_match('/^[a-z0-9_]+_sim$/', $name)) {
    fwrite(STDERR, "Refusing to reset '{$name}': the simulation database name must end in _sim.\n");
    exit(1);
}

$pdo = new PDO(sim_mysql_dsn(null), sim_env('DB_USERNAME', 'root'), sim_env('DB_PASSWORD', ''));
$pdo->exec("DROP DATABASE IF EXISTS `{$name}`");
$pdo->exec("CREATE DATABASE `{$name}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
echo "reset {$name}\n";
