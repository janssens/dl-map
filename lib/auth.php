<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/mailer.php';

function auth_normalize_email(string $email): string {
    return strtolower(trim($email));
}

function auth_valid_tier(string $tier): bool {
    return in_array($tier, ['free', 'premium', 'admin'], true);
}

function auth_password_policy_ok(string $password): bool {
    // Simple baseline; can be hardened later.
    return strlen($password) >= 8;
}

function auth_current_user(): ?array {
    static $cached = null;
    static $loaded = false;
    if ($loaded){
        return $cached;
    }
    $loaded = true;
    $cached = null;

    app_session_start();
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0){
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!is_array($user)){
        return null;
    }
    if (!empty($user['disabled_at'])){
        return null;
    }
    $cached = $user;
    return $cached;
}

function auth_login(int $userId): void {
    app_session_start();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function auth_logout(): void {
    app_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')){
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool)($params['secure'] ?? false), (bool)($params['httponly'] ?? true));
    }
    session_destroy();
}

function auth_require_login(): array {
    $user = auth_current_user();
    if ($user){
        return $user;
    }
    app_redirect('login.php');
}

function auth_is_admin(?array $user): bool {
    return is_array($user) && (($user['tier'] ?? '') === 'admin');
}

function auth_require_admin(): array {
    $user = auth_require_login();
    if (!auth_is_admin($user)){
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
    return $user;
}

function auth_authenticate(string $email, string $password): ?array {
    $email = auth_normalize_email($email);
    if ($email === ''){
        return null;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!is_array($user)){
        return null;
    }
    if (!empty($user['disabled_at'])){
        return null;
    }
    if (!password_verify($password, (string)$user['password_hash'])){
        return null;
    }
    return $user;
}

function auth_register_user(string $email, string $password, string $firstName, string $lastName): array {
    $email = auth_normalize_email($email);
    $firstName = trim($firstName);
    $lastName = trim($lastName);

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)){
        throw new RuntimeException('Email invalide');
    }
    if (!auth_password_policy_ok($password)){
        throw new RuntimeException('Mot de passe trop court (min 8 caractères)');
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === ''){
        throw new RuntimeException('Erreur de hash mot de passe');
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $hasAdmin = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE tier = 'admin'")->fetchColumn() > 0;
        $tier = $hasAdmin ? 'free' : 'admin';
        $now = db_now();

        $stmt = $pdo->prepare('INSERT INTO users(email,password_hash,first_name,last_name,tier,email_verified_at,disabled_at,created_at,updated_at) VALUES(?,?,?,?,?,NULL,NULL,?,?)');
        $stmt->execute([$email, $hash, $firstName, $lastName, $tier, $now, $now]);
        $userId = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e){
        $pdo->rollBack();
        if (str_contains(strtolower($e->getMessage()), 'unique')){
            throw new RuntimeException('Cet email est déjà utilisé');
        }
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!is_array($user)){
        throw new RuntimeException('Cannot load created user');
    }
    return $user;
}

function auth_admin_create_user(string $email, string $password, string $firstName, string $lastName, string $tier): array {
    $email = auth_normalize_email($email);
    $firstName = trim($firstName);
    $lastName = trim($lastName);
    $tier = strtolower(trim($tier));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)){
        throw new RuntimeException('Email invalide');
    }
    if (!auth_valid_tier($tier)){
        throw new RuntimeException('Tier invalide');
    }
    if (!auth_password_policy_ok($password)){
        throw new RuntimeException('Mot de passe trop court (min 8 caractères)');
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === ''){
        throw new RuntimeException('Erreur de hash mot de passe');
    }

    $pdo = db();
    $now = db_now();
    try {
        $stmt = $pdo->prepare('INSERT INTO users(email,password_hash,first_name,last_name,tier,email_verified_at,disabled_at,created_at,updated_at) VALUES(?,?,?,?,?,NULL,NULL,?,?)');
        $stmt->execute([$email, $hash, $firstName, $lastName, $tier, $now, $now]);
        $userId = (int)$pdo->lastInsertId();
    } catch (Throwable $e){
        if (str_contains(strtolower($e->getMessage()), 'unique')){
            throw new RuntimeException('Cet email est déjà utilisé');
        }
        throw $e;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
    if (!is_array($user)){
        throw new RuntimeException('Cannot load created user');
    }
    return $user;
}

function auth_create_token(int $userId, string $type, int $ttlSeconds, array $meta = []): string {
    if (!in_array($type, ['verify_email', 'reset_password'], true)){
        throw new RuntimeException('Invalid token type');
    }
    $token = bin2hex(random_bytes(32));
    $hash = hash('sha256', $token);
    $now = db_now();
    $expires = gmdate('c', time() + max(60, $ttlSeconds));

    $stmt = db()->prepare('INSERT INTO auth_tokens(user_id,type,token_hash,expires_at,consumed_at,created_at,meta_json) VALUES(?,?,?,?,NULL,?,?)');
    $stmt->execute([$userId, $type, $hash, $expires, $now, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    return $token;
}

function auth_consume_token(int $userId, string $type, string $token): bool {
    $token = trim($token);
    if ($token === ''){
        return false;
    }
    $hash = hash('sha256', $token);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id, expires_at, consumed_at FROM auth_tokens WHERE user_id = ? AND type = ? AND token_hash = ? LIMIT 1');
        $stmt->execute([$userId, $type, $hash]);
        $row = $stmt->fetch();
        if (!is_array($row)){
            $pdo->rollBack();
            return false;
        }
        if (!empty($row['consumed_at'])){
            $pdo->rollBack();
            return false;
        }
        $expires = strtotime((string)$row['expires_at']);
        if ($expires !== false && $expires < time()){
            $pdo->rollBack();
            return false;
        }
        $stmt2 = $pdo->prepare('UPDATE auth_tokens SET consumed_at = ? WHERE id = ?');
        $stmt2->execute([db_now(), (int)$row['id']]);
        $pdo->commit();
        return true;
    } catch (Throwable $e){
        $pdo->rollBack();
        throw $e;
    }
}

function auth_send_email_verification(array $user): void {
    $userId = (int)$user['id'];
    $token = auth_create_token($userId, 'verify_email', 24 * 3600);
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $proto = app_is_https() ? 'https' : 'http';
    $link = $proto . '://' . $host . '/verify_email.php?user=' . urlencode((string)$userId) . '&token=' . urlencode($token);
    $body = "Bonjour,\n\nConfirmez votre compte en cliquant sur ce lien :\n$link\n\nSi vous n'êtes pas à l'origine de cette demande, ignorez cet email.\n";
    send_mail_message((string)$user['email'], 'Confirmez votre compte', $body);
}

function auth_verify_email(int $userId, string $token): bool {
    if (!auth_consume_token($userId, 'verify_email', $token)){
        return false;
    }
    $stmt = db()->prepare('UPDATE users SET email_verified_at = ?, updated_at = ? WHERE id = ?');
    $now = db_now();
    $stmt->execute([$now, $now, $userId]);
    return true;
}

function auth_send_password_reset(string $email): void {
    $email = auth_normalize_email($email);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)){
        return;
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!is_array($user) || !empty($user['disabled_at'])){
        return;
    }
    $userId = (int)$user['id'];
    $token = auth_create_token($userId, 'reset_password', 2 * 3600);
    $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
    $proto = app_is_https() ? 'https' : 'http';
    $link = $proto . '://' . $host . '/reset_password.php?user=' . urlencode((string)$userId) . '&token=' . urlencode($token);
    $body = "Bonjour,\n\nRéinitialisez votre mot de passe via ce lien :\n$link\n\nCe lien expire dans 2 heures.\n";
    send_mail_message((string)$user['email'], 'Réinitialisation du mot de passe', $body);
}

function auth_reset_password(int $userId, string $token, string $newPassword): bool {
    if (!auth_password_policy_ok($newPassword)){
        throw new RuntimeException('Mot de passe trop court (min 8 caractères)');
    }
    if (!auth_consume_token($userId, 'reset_password', $token)){
        return false;
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === ''){
        throw new RuntimeException('Erreur de hash mot de passe');
    }
    $now = db_now();
    $stmt = db()->prepare('UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?');
    $stmt->execute([$hash, $now, $userId]);
    return true;
}
