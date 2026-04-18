<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

/**
 * @return array<int, array{version:int, up: callable(PDO): void}>
 */
function app_migrations(): array {
    return [
        [
            'version' => 1,
            'up' => function(PDO $pdo): void {
                $pdo->exec(<<<'SQL'
CREATE TABLE users (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  email TEXT NOT NULL UNIQUE,
  password_hash TEXT NOT NULL,
  first_name TEXT,
  last_name TEXT,
  tier TEXT NOT NULL DEFAULT 'free',
  email_verified_at TEXT,
  disabled_at TEXT,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL
);
SQL);

                $pdo->exec(<<<'SQL'
CREATE TABLE auth_tokens (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  user_id INTEGER NOT NULL,
  type TEXT NOT NULL,
  token_hash TEXT NOT NULL UNIQUE,
  expires_at TEXT NOT NULL,
  consumed_at TEXT,
  created_at TEXT NOT NULL,
  meta_json TEXT,
  FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
);
SQL);

                $pdo->exec(<<<'SQL'
CREATE TABLE layers (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  slug TEXT NOT NULL UNIQUE,
  label TEXT NOT NULL,
  settings_json TEXT NOT NULL,
  access TEXT NOT NULL DEFAULT 'public', -- public|premium|admin|private
  owner_user_id INTEGER,
  created_at TEXT NOT NULL,
  updated_at TEXT NOT NULL,
  FOREIGN KEY(owner_user_id) REFERENCES users(id) ON DELETE CASCADE
);
SQL);

                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_auth_tokens_user_type ON auth_tokens(user_id, type)');
                $pdo->exec('CREATE INDEX IF NOT EXISTS idx_layers_owner ON layers(owner_user_id)');
            },
        ],
        [
            'version' => 2,
            'up' => function(PDO $pdo): void {
                // Import existing ./settings/*.json(.dist) as global layers (public by default).
                $dir = settings_dir_path();
                if (!is_dir($dir)){
                    return;
                }

                $paths = array_merge(glob($dir . '/*.json') ?: [], glob($dir . '/*.json.dist') ?: []);
                $bySlug = [];
                foreach ($paths as $path){
                    $base = basename($path);
                    $slug = str_ends_with($base, '.json.dist')
                        ? substr($base, 0, -strlen('.json.dist'))
                        : substr($base, 0, -strlen('.json'));
                    $slug = normalize_settings_id((string)$slug);
                    if ($slug === ''){
                        continue;
                    }
                    // Prefer non-.dist if both exist.
                    $score = str_ends_with($base, '.json') ? 2 : 1;
                    if (!isset($bySlug[$slug]) || $score > $bySlug[$slug]['score']){
                        $bySlug[$slug] = ['path' => $path, 'score' => $score];
                    }
                }

                $stmtSel = $pdo->prepare('SELECT id FROM layers WHERE slug = ?');
                $stmtIns = $pdo->prepare('INSERT INTO layers(slug,label,settings_json,access,owner_user_id,created_at,updated_at) VALUES(?,?,?,?,NULL,?,?)');

                $now = gmdate('c');
                foreach ($bySlug as $slug => $info){
                    $stmtSel->execute([$slug]);
                    $exists = $stmtSel->fetchColumn();
                    if ($exists !== false){
                        continue;
                    }

                    $raw = @file_get_contents($info['path']);
                    if (!is_string($raw) || trim($raw) === ''){
                        continue;
                    }
                    $json = json_decode($raw, true);
                    if (!is_array($json)){
                        continue;
                    }

                    $label = ucfirst(str_replace(['-', '_'], ' ', $slug));
                    $stmtIns->execute([$slug, $label, json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'public', $now, $now]);
                }
            },
        ],
    ];
}

