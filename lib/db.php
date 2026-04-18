<?php

declare(strict_types=1);

/**
 * SQLite (PDO) helper + migrations bootstrap.
 */

function app_var_dir(): string {
    return __DIR__ . '/../var';
}

function app_db_path(): string {
    return app_var_dir() . '/app.sqlite';
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO){
        return $pdo;
    }

    if (!is_dir(app_var_dir())){
        mkdir(app_var_dir(), 0777, true);
    }

    $pdo = new PDO('sqlite:' . app_db_path(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    try { $pdo->exec('PRAGMA foreign_keys = ON'); } catch (Throwable $e) {}
    try { $pdo->exec('PRAGMA busy_timeout = 5000'); } catch (Throwable $e) {}
    try { $pdo->exec('PRAGMA journal_mode = WAL'); } catch (Throwable $e) {}

    return $pdo;
}

function db_now(): string {
    return gmdate('c');
}

function db_migrate(): void {
    static $done = false;
    if ($done){
        return;
    }
    $done = true;

    require_once __DIR__ . '/migrations.php';
    $pdo = db();

    $pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)');
    $applied = [];
    foreach ($pdo->query('SELECT version FROM schema_migrations ORDER BY version') as $row){
        $applied[(int)$row['version']] = true;
    }

    foreach (app_migrations() as $migration){
        $version = (int)$migration['version'];
        if (isset($applied[$version])){
            continue;
        }
        $pdo->beginTransaction();
        try {
            ($migration['up'])($pdo);
            $stmt = $pdo->prepare('INSERT INTO schema_migrations(version, applied_at) VALUES(?, ?)');
            $stmt->execute([$version, db_now()]);
            $pdo->commit();
        } catch (Throwable $e){
            $pdo->rollBack();
            throw $e;
        }
    }
}

