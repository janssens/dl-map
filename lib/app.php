<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function app_is_https(): bool {
    if (!isset($_SERVER['HTTPS'])){
        return false;
    }
    $v = strtolower((string)$_SERVER['HTTPS']);
    return $v !== '' && $v !== 'off' && $v !== '0';
}

function app_session_start(): void {
    if (session_status() === PHP_SESSION_ACTIVE){
        return;
    }

    session_name('dlmap_sess');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => app_is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function app_boot(): void {
    app_session_start();
    db_migrate();

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function app_boot_no_migrate(): void {
    app_session_start();
    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

function app_redirect(string $path): void {
    header('Location: ' . $path);
    exit;
}

function app_h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES);
}
