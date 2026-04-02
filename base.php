<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MapGenerator.php';

$configPath = __DIR__ . '/config.json';
if (!is_file($configPath)){
    fwrite(STDERR, "Missing config.json (use config.json.dist as a template)\n");
    exit(1);
}

$raw = file_get_contents($configPath);
if ($raw === false){
    fwrite(STDERR, "Cannot read config.json\n");
    exit(1);
}

$config = json_decode($raw, true);
if (!is_array($config)){
    fwrite(STDERR, "Invalid JSON in config.json\n");
    exit(1);
}

$required = ['latTopLeft', 'lngTopLeft', 'latBottomRight', 'lngBottomRight', 'settings'];
foreach ($required as $key){
    if (!array_key_exists($key, $config)){
        fwrite(STDERR, "Missing '$key' in config.json\n");
        exit(1);
    }
}

$generator = new MapGenerator();
$result = $generator->generate($config, '.', function(array $p){
    // Keep CLI output simple and readable.
    $done = (int)($p['done'] ?? 0);
    $total = (int)($p['total'] ?? 0);
    $percent = (int)($p['percent'] ?? 0);
    $msg = (string)($p['message'] ?? '');
    echo sprintf("[%3d%%] %d/%d %s\n", $percent, $done, $total, $msg);
});

if ($result['finalPath']){
    echo "Fichier final généré: " . $result['finalPath'] . "\n";
}

exit($result['status'] === MapGenerator::SUCCESS ? 0 : 2);

