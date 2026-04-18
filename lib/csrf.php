<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';

function csrf_token(): string {
    app_session_start();
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token']) || strlen($_SESSION['csrf_token']) < 20){
        $_SESSION['csrf_token'] = bin2hex(random_bytes(24));
    }
    return (string)$_SESSION['csrf_token'];
}

function csrf_input(): string {
    $t = csrf_token();
    return '<input type="hidden" name="csrf_token" value="' . app_h($t) . '">';
}

function csrf_require_post(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST'){
        http_response_code(405);
        echo "Method not allowed";
        exit;
    }
    app_session_start();
    $sent = (string)($_POST['csrf_token'] ?? '');
    $expected = (string)($_SESSION['csrf_token'] ?? '');
    if ($sent === '' || $expected === '' || !hash_equals($expected, $sent)){
        http_response_code(400);
        echo "Invalid CSRF token";
        exit;
    }
}

