<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/settings.php';

function jobs_root(): string {
    return __DIR__ . '/var/jobs';
}

function job_dir(string $jobId): string {
    return jobs_root() . '/' . $jobId;
}

function is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

function start_job(array $input): string {
    if (!is_dir(jobs_root())){
        mkdir(jobs_root(), 0777, true);
    }

    $jobId = bin2hex(random_bytes(12));
    $dir = job_dir($jobId);
    mkdir($dir, 0777, true);

    file_put_contents($dir . '/input.json', json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    file_put_contents($dir . '/status.json', json_encode([
        'state' => 'queued',
        'done' => 0,
        'total' => 0,
        'percent' => 0,
        'message' => 'En attente…',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

    $phpBin = PHP_BINARY;
    if (!is_string($phpBin) || trim($phpBin) === ''){
        $phpBin = '';
    }
    $phpBin = trim($phpBin);
    $phpCandidates = array_values(array_filter([
        $phpBin,
        '/usr/local/bin/php',
        'php',
    ], fn($v) => is_string($v) && trim($v) !== ''));

    $php = null;
    foreach ($phpCandidates as $candidate){
        $candidate = trim($candidate);
        if (str_starts_with($candidate, '/')){
            if (is_file($candidate) && is_executable($candidate)){
                $php = $candidate;
                break;
            }
            continue;
        }
        // Relative command (resolved via PATH).
        $php = $candidate;
        break;
    }
    if ($php === null){
        throw new RuntimeException('Cannot find a PHP binary to start worker');
    }

    $php = escapeshellarg($php);
    $worker = escapeshellarg(__DIR__ . '/worker.php');
    $arg = escapeshellarg($jobId);
    $log = escapeshellarg($dir . '/worker.log');

    // Start in background; status is tracked via status.json.
    exec("$php $worker $arg > $log 2>&1 &");

    return $jobId;
}

$jobId = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    $settingsId = normalize_settings_id((string)($_POST['settings'] ?? ''));
    if ($settingsId === '' || find_settings_file($settingsId) === null){
        http_response_code(400);
        echo "Unknown settings";
        exit;
    }

    $latTopLeft = filter_var($_POST['latTopLeft'] ?? null, FILTER_VALIDATE_FLOAT);
    $lngTopLeft = filter_var($_POST['lngTopLeft'] ?? null, FILTER_VALIDATE_FLOAT);
    $latBottomRight = filter_var($_POST['latBottomRight'] ?? null, FILTER_VALIDATE_FLOAT);
    $lngBottomRight = filter_var($_POST['lngBottomRight'] ?? null, FILTER_VALIDATE_FLOAT);

    if (!is_float($latTopLeft) || !is_float($lngTopLeft) || !is_float($latBottomRight) || !is_float($lngBottomRight)){
        http_response_code(400);
        echo "Invalid coordinates";
        exit;
    }

    $jobId = start_job([
        'latTopLeft' => $latTopLeft,
        'lngTopLeft' => $lngTopLeft,
        'latBottomRight' => $latBottomRight,
        'lngBottomRight' => $lngBottomRight,
        'settings' => $settingsId,
        'createdAt' => date('c'),
    ]);
} else {
    $jobId = (string)($_GET['job'] ?? '');
    if (!is_valid_job_id($jobId)){
        header('Location: index.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Génération…</title>
    <style>
        body{
            font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Noto Sans", sans-serif;
            background: #f0f0f0;
            padding: 1rem;
            margin: 0;
        }
        .card{
            max-width: 860px;
            margin: 0 auto;
            background: #fff;
            border: 1px solid rgba(0,0,0,0.12);
            border-radius: 12px;
            box-shadow: 0 10px 28px rgba(0,0,0,0.10);
            padding: 1rem;
        }
        .row{
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .bar{
            width: 100%;
            height: 12px;
            background: rgba(0,0,0,0.08);
            border-radius: 999px;
            overflow: hidden;
        }
        .bar > div{
            height: 100%;
            width: 0%;
            background: #00a86b;
            transition: width 0.2s linear;
        }
        .muted{ opacity: 0.75; }
        .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
        a.btn{
            display: inline-block;
            padding: 0.6rem 0.85rem;
            border-radius: 10px;
            background: #111;
            color: #fff;
            text-decoration: none;
            font-weight: 600;
        }
        img{
            max-width: 100%;
            border-radius: 10px;
            border: 1px solid rgba(0,0,0,0.10);
            margin-top: 0.75rem;
        }
        pre{
            background: rgba(0,0,0,0.04);
            padding: 0.75rem;
            border-radius: 10px;
            overflow: auto;
        }
    </style>
</head>
<body>
<div class="card">
    <div class="row">
        <h2 style="margin: 0;">Génération en cours</h2>
        <span class="muted mono" id="job-id"></span>
    </div>
    <div class="muted" id="status-msg" style="margin-top: 0.5rem;">Initialisation…</div>
    <div class="bar" style="margin-top: 0.75rem;"><div id="bar-inner"></div></div>
    <div class="row muted" style="margin-top: 0.5rem;">
        <span class="mono" id="progress-text">0/0</span>
        <span class="mono" id="percent-text">0%</span>
    </div>

    <div id="done-block" style="display:none; margin-top: 0.75rem;">
        <div class="row">
            <a class="btn" href="index.php">Nouvelle génération</a>
            <a class="btn" id="download-link" href="#">Télécharger l'image</a>
        </div>
        <img id="result-img" alt="Résultat"/>
    </div>

    <div id="error-block" style="display:none; margin-top: 0.75rem;">
        <h3 style="margin: 0.25rem 0;">Erreur</h3>
        <pre id="error-pre"></pre>
        <div class="row">
            <a class="btn" href="index.php">Retour</a>
        </div>
    </div>
</div>

<script>
    const jobId = <?= json_encode($jobId, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    document.querySelector('#job-id').textContent = `job: ${jobId}`;

    const bar = document.querySelector('#bar-inner');
    const statusMsg = document.querySelector('#status-msg');
    const progressText = document.querySelector('#progress-text');
    const percentText = document.querySelector('#percent-text');
    const doneBlock = document.querySelector('#done-block');
    const errorBlock = document.querySelector('#error-block');
    const errorPre = document.querySelector('#error-pre');
    const resultImg = document.querySelector('#result-img');
    const downloadLink = document.querySelector('#download-link');

    let lastState = null;
    async function poll(){
        try {
            const res = await fetch(`job_status.php?job=${encodeURIComponent(jobId)}`, { cache: 'no-store' });
            const json = await res.json();

            const done = json.done || 0;
            const total = json.total || 0;
            const percent = json.percent || 0;
            statusMsg.textContent = json.message || '';
            progressText.textContent = `${done}/${total}`;
            percentText.textContent = `${percent}%`;
            bar.style.width = `${Math.min(100, Math.max(0, percent))}%`;

            if (json.state === 'done'){
                doneBlock.style.display = 'block';
                const url = `result.php?job=${encodeURIComponent(jobId)}&t=${Date.now()}`;
                resultImg.src = url;
                downloadLink.href = url;
                return;
            }

            if (json.state === 'error'){
                errorBlock.style.display = 'block';
                errorPre.textContent = json.error || 'Unknown error';
                return;
            }

            lastState = json.state;
        } catch (e){
            statusMsg.textContent = 'Connexion…';
        }
        setTimeout(poll, 800);
    }

    poll();
</script>
</body>
</html>
