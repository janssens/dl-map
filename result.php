<?php

declare(strict_types=1);

function jobs_root(): string {
    return __DIR__ . '/var/jobs';
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

$jobId = (string)($_GET['job'] ?? '');
if (!is_valid_job_id($jobId)){
    http_response_code(400);
    echo "Invalid job id";
    exit;
}

$path = jobs_root() . '/' . $jobId . '/result.png';
if (!is_file($path)){
    http_response_code(404);
    echo "Not ready";
    exit;
}

header('Content-Type: image/png');
header('Content-Disposition: inline; filename="map.png"');
header('Cache-Control: no-store');
readfile($path);

