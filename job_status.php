<?php

declare(strict_types=1);

function jobs_root(): string {
    return __DIR__ . '/var/jobs';
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

$jobId = (string)($_GET['job'] ?? '');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!is_valid_job_id($jobId)){
    http_response_code(400);
    echo json_encode(['state' => 'error', 'error' => 'Invalid job id']);
    exit;
}

$statusPath = jobs_root() . '/' . $jobId . '/status.json';
if (!is_file($statusPath)){
    echo json_encode([
        'state' => 'starting',
        'done' => 0,
        'total' => 0,
        'percent' => 0,
        'message' => 'Initialisation…',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents($statusPath);
if ($raw === false){
    echo json_encode(['state' => 'error', 'error' => 'Cannot read status']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)){
    echo json_encode(['state' => 'error', 'error' => 'Invalid status json']);
    exit;
}

echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

