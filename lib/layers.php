<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/auth.php';

function layer_access_allows(string $access, ?array $user): bool {
    $access = strtolower(trim($access));
    $tier = is_array($user) ? (string)($user['tier'] ?? 'free') : 'free';
    if ($tier === 'admin'){
        return true;
    }
    if ($access === 'public'){
        return true;
    }
    if ($access === 'premium'){
        return $tier === 'premium';
    }
    return false;
}

/**
 * @return array<int, array{id:int,slug:string,label:string,settings:array,access:string,owner_user_id:int|null}>
 */
function layers_list_for_user(?array $user): array {
    $pdo = db();
    $params = [];
    $where = [];

    // Global layers.
    if (!is_array($user) || ($user['tier'] ?? 'free') === 'free'){
        $where[] = '(owner_user_id IS NULL AND access = \'public\')';
    } elseif (($user['tier'] ?? '') === 'premium'){
        $where[] = '(owner_user_id IS NULL AND access IN (\'public\', \'premium\'))';
    } else {
        // admin
        $where[] = '(owner_user_id IS NULL)';
    }

    // Private layers for user (if logged in).
    if (is_array($user) && (int)($user['id'] ?? 0) > 0){
        $where[] = '(owner_user_id = ? AND access = \'private\')';
        $params[] = (int)$user['id'];
    }

    $sql = 'SELECT id, slug, label, settings_json, access, owner_user_id FROM layers WHERE ' . implode(' OR ', $where) . ' ORDER BY owner_user_id IS NOT NULL, label COLLATE NOCASE';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $out = [];
    while ($row = $stmt->fetch()){
        $settings = json_decode((string)$row['settings_json'], true);
        if (!is_array($settings)){
            continue;
        }
        $out[] = [
            'id' => (int)$row['id'],
            'slug' => (string)$row['slug'],
            'label' => (string)$row['label'],
            'settings' => $settings,
            'access' => (string)$row['access'],
            'owner_user_id' => $row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null,
        ];
    }
    return $out;
}

/**
 * Load settings by slug, enforcing access for the current user.
 */
function layers_load_settings_for_user(string $slug, ?array $user): array {
    $slug = normalize_settings_id($slug);
    if ($slug === ''){
        throw new RuntimeException('Invalid layer');
    }

    $stmt = db()->prepare('SELECT * FROM layers WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $row = $stmt->fetch();
    if (!is_array($row)){
        throw new RuntimeException('Unknown layer');
    }

    $owner = $row['owner_user_id'] !== null ? (int)$row['owner_user_id'] : null;
    $access = (string)$row['access'];

    if ($owner !== null){
        if (!is_array($user) || (int)($user['id'] ?? 0) !== $owner){
            throw new RuntimeException('Forbidden');
        }
        if ($access !== 'private' && !auth_is_admin($user)){
            throw new RuntimeException('Forbidden');
        }
    } else {
        if (!layer_access_allows($access, $user)){
            throw new RuntimeException('Forbidden');
        }
    }

    $settings = json_decode((string)$row['settings_json'], true);
    if (!is_array($settings)){
        throw new RuntimeException('Invalid layer settings');
    }
    $settings['_id'] = $slug;
    return $settings;
}

/**
 * Load settings by slug without access checks (used by CLI/worker).
 */
function layers_load_settings_unchecked(string $slug): ?array {
    $slug = normalize_settings_id($slug);
    if ($slug === ''){
        return null;
    }
    $stmt = db()->prepare('SELECT settings_json FROM layers WHERE slug = ? LIMIT 1');
    $stmt->execute([$slug]);
    $raw = $stmt->fetchColumn();
    if (!is_string($raw) || trim($raw) === ''){
        return null;
    }
    $settings = json_decode($raw, true);
    if (!is_array($settings)){
        return null;
    }
    $settings['_id'] = $slug;
    return $settings;
}

function layers_user_create(int $userId, string $slug, string $label, string $settingsJson): void {
    $slug = normalize_settings_id($slug);
    $label = trim($label);
    if ($slug === '' || $label === ''){
        throw new RuntimeException('Slug/label requis');
    }
    $json = json_decode($settingsJson, true);
    if (!is_array($json)){
        throw new RuntimeException('JSON invalide');
    }
    $now = db_now();
    $stmt = db()->prepare('INSERT INTO layers(slug,label,settings_json,access,owner_user_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?)');
    $stmt->execute([$slug, $label, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'private', $userId, $now, $now]);
}

function layers_user_update(int $userId, int $layerId, string $label, string $settingsJson): void {
    $label = trim($label);
    $json = json_decode($settingsJson, true);
    if ($label === '' || !is_array($json)){
        throw new RuntimeException('Label/JSON invalides');
    }
    $now = db_now();
    $stmt = db()->prepare('UPDATE layers SET label = ?, settings_json = ?, updated_at = ? WHERE id = ? AND owner_user_id = ? AND access = \'private\'');
    $stmt->execute([$label, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $now, $layerId, $userId]);
}

function layers_user_delete(int $userId, int $layerId): void {
    $stmt = db()->prepare('DELETE FROM layers WHERE id = ? AND owner_user_id = ? AND access = \'private\'');
    $stmt->execute([$layerId, $userId]);
}

/**
 * @return array<int, array>
 */
function layers_admin_list_all(): array {
    $stmt = db()->query('SELECT * FROM layers ORDER BY owner_user_id IS NOT NULL, label COLLATE NOCASE');
    return $stmt->fetchAll();
}

function layers_admin_upsert_global(?int $id, string $slug, string $label, string $access, string $settingsJson): void {
    $slug = normalize_settings_id($slug);
    $label = trim($label);
    $access = strtolower(trim($access));
    if (!in_array($access, ['public', 'premium', 'admin'], true)){
        throw new RuntimeException('Access invalide');
    }
    if ($slug === '' || $label === ''){
        throw new RuntimeException('Slug/label requis');
    }
    $json = json_decode($settingsJson, true);
    if (!is_array($json)){
        throw new RuntimeException('JSON invalide');
    }
    $now = db_now();

    $pdo = db();
    if ($id === null){
        $stmt = $pdo->prepare('INSERT INTO layers(slug,label,settings_json,access,owner_user_id,created_at,updated_at) VALUES(?,?,?,?,NULL,?,?)');
        $stmt->execute([$slug, $label, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $access, $now, $now]);
    } else {
        $stmt = $pdo->prepare('UPDATE layers SET slug = ?, label = ?, settings_json = ?, access = ?, updated_at = ? WHERE id = ? AND owner_user_id IS NULL');
        $stmt->execute([$slug, $label, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $access, $now, $id]);
    }
}

function layers_admin_delete_global(int $id): void {
    $stmt = db()->prepare('DELETE FROM layers WHERE id = ? AND owner_user_id IS NULL');
    $stmt->execute([$id]);
}
