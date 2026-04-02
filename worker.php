<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/MapGenerator.php';

function jobs_root(): string {
    return __DIR__ . '/var/jobs';
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

function job_dir(string $jobId): string {
    return jobs_root() . '/' . $jobId;
}

function write_status(string $jobId, array $data): void {
    $path = job_dir($jobId) . '/status.json';
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}

$jobId = (string)($argv[1] ?? '');
if (!is_valid_job_id($jobId)){
    fwrite(STDERR, "Invalid job id\n");
    exit(2);
}

$dir = job_dir($jobId);
$inputPath = $dir . '/input.json';
if (!is_file($inputPath)){
    write_status($jobId, ['state' => 'error', 'error' => 'Missing input.json']);
    exit(2);
}

$raw = file_get_contents($inputPath);
if ($raw === false){
    write_status($jobId, ['state' => 'error', 'error' => 'Cannot read input.json']);
    exit(2);
}

$input = json_decode($raw, true);
if (!is_array($input)){
    write_status($jobId, ['state' => 'error', 'error' => 'Invalid input.json']);
    exit(2);
}

try {
    write_status($jobId, [
        'state' => 'running',
        'done' => 0,
        'total' => 0,
        'percent' => 0,
        'message' => 'Démarrage…',
    ]);

    $generator = new MapGenerator();
    $result = $generator->generate($input, $dir, function(array $p) use ($jobId){
        write_status($jobId, [
            'state' => 'running',
            'done' => (int)($p['done'] ?? 0),
            'total' => (int)($p['total'] ?? 0),
            'percent' => (int)($p['percent'] ?? 0),
            'message' => (string)($p['message'] ?? ''),
        ]);
    });

    $finalPath = $result['finalPath'] ?? null;
    if (!$finalPath || !is_file($finalPath)){
        throw new RuntimeException('Final image not found');
    }

    $resultPath = $dir . '/result.png';
    copy($finalPath, $resultPath);

    write_status($jobId, [
        'state' => 'done',
        'done' => (int)($result['tilesTotal'] ?? 0),
        'total' => (int)($result['tilesTotal'] ?? 0),
        'percent' => 100,
        'message' => 'Terminé',
    ]);
    exit(0);
} catch (Throwable $e){
    write_status($jobId, [
        'state' => 'error',
        'error' => $e->getMessage(),
    ]);
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

