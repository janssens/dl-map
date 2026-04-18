<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function settings_dir_path(): string {
    return __DIR__ . '/../settings';
}

function normalize_settings_id(string $value): string {
    $value = trim($value);
    $value = preg_replace('/[^a-zA-Z0-9_.-]+/', '', $value) ?? '';
    return $value;
}

function find_settings_file(string $settingsId): ?string {
    $settingsId = normalize_settings_id($settingsId);
    if ($settingsId === ''){
        return null;
    }

    $dir = settings_dir_path();
    $primary = $dir . '/' . $settingsId . '.json';
    if (is_file($primary)){
        return $primary;
    }

    $dist = $dir . '/' . $settingsId . '.json.dist';
    if (is_file($dist)){
        return $dist;
    }

    return null;
}

function load_settings(string $settingsId): array {
    $path = find_settings_file($settingsId);
    // Prefer DB layers when available (web app), but keep file fallback (CLI/legacy).
    try {
        db_migrate();
        $settingsId = normalize_settings_id($settingsId);
        if ($settingsId !== ''){
            $stmt = db()->prepare('SELECT settings_json FROM layers WHERE slug = ? LIMIT 1');
            $stmt->execute([$settingsId]);
            $raw = $stmt->fetchColumn();
            if (is_string($raw) && trim($raw) !== ''){
                $fromDb = json_decode($raw, true);
                if (is_array($fromDb)){
                    $fromDb['_id'] = $settingsId;
                    $fromDb['_path'] = '(db)';
                    return $fromDb;
                }
            }
        }
    } catch (Throwable $e){
        // ignore DB issues and fallback to files
    }

    if ($path === null){
        throw new RuntimeException("Unknown settings: $settingsId");
    }

    $raw = file_get_contents($path);
    if ($raw === false){
        throw new RuntimeException("Cannot read settings file: $path");
    }

    $data = json_decode($raw, true);
    if (!is_array($data)){
        throw new RuntimeException("Invalid JSON in settings file: $path");
    }

    $data['_id'] = $settingsId;
    $data['_path'] = $path;
    return $data;
}

function list_settings(): array {
    $dir = settings_dir_path();
    if (!is_dir($dir)){
        return [];
    }

    $ids = [];
    foreach (glob($dir . '/*.json') ?: [] as $path){
        $ids[basename($path, '.json')] = true;
    }
    foreach (glob($dir . '/*.json.dist') ?: [] as $path){
        $ids[basename($path, '.json.dist')] = true;
    }

    $out = [];
    foreach (array_keys($ids) as $id){
        $label = ucfirst(str_replace(['-', '_'], ' ', $id));
        $out[] = [
            'id' => $id,
            'label' => $label,
            'file' => find_settings_file($id),
        ];
    }

    usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $out;
}
