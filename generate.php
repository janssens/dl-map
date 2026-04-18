<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/layers.php';
require_once __DIR__ . '/lib/layout.php';

app_boot();
$user = auth_require_login();

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
    try {
        // Enforce access (public/premium/admin/private).
        layers_load_settings_for_user($settingsId, $user);
    } catch (Throwable $e){
        http_response_code(403);
        echo "Forbidden";
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
layout_header('Génération…', $user);
?>
<div class="card" style="max-width: 860px; margin: 0 auto;">
    <div class="row">
        <h2 style="margin: 0;">Génération en cours</h2>
        <span class="muted" style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \"Liberation Mono\", \"Courier New\", monospace;" id="job-id"></span>
    </div>
    <div class="muted" id="status-msg" style="margin-top: 0.5rem;">Initialisation…</div>
    <div class="bar" style="margin-top: 0.75rem; width: 100%; height: 12px; background: rgba(0,0,0,0.08); border-radius: 999px; overflow: hidden;">
        <div id="bar-inner" style="height:100%; width:0%; background:#00a86b; transition: width 0.2s linear;"></div>
    </div>
    <div class="row muted" style="margin-top: 0.5rem;">
        <span style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \"Liberation Mono\", \"Courier New\", monospace;" id="progress-text">0/0</span>
        <span style="font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, \"Liberation Mono\", \"Courier New\", monospace;" id="percent-text">0%</span>
    </div>

    <div id="done-block" style="display:none; margin-top: 0.75rem;">
        <div class="row">
            <a class="btn" href="index.php">Nouvelle génération</a>
            <a class="btn" id="download-png-link" href="#">Télécharger PNG</a>
            <a class="btn" id="download-jpg-link" href="#">Télécharger JPG</a>
            <a class="btn" id="download-pgw-link" href="#">Télécharger PGW</a>
            <a class="btn" id="download-omap-link" href="#">Télécharger OMAP + KMZ (ZIP)</a>
            <a class="btn" id="download-gpx-link" href="#">Télécharger GPX</a>
            <a class="btn" id="download-kmz-link" href="#">Télécharger KMZ</a>
            <a class="btn" id="edit-link" href="#">Changer de layer</a>
        </div>
        <div id="meta-block" style="display:none; margin-top: 0.75rem;">
            <div class="muted">Centre (WGS84)</div>
            <div class="mono" id="center-text"></div>
            <div class="muted" style="margin-top: 0.5rem;">UTM du centre</div>
            <div class="mono" id="utm-text"></div>
            <div class="muted" style="margin-top: 0.5rem;">Déclinaison magnétique (WMM2025) au centre</div>
            <div class="mono" id="decl-text"></div>
        </div>
        <img id="result-img" alt="Résultat" style="max-width:100%; border-radius:10px; border:1px solid rgba(0,0,0,0.10); margin-top:0.75rem;"/>
    </div>

    <div id="error-block" style="display:none; margin-top: 0.75rem;">
        <h3 style="margin: 0.25rem 0;">Erreur</h3>
        <pre id="error-pre" style="background: rgba(0,0,0,0.04); padding: 0.75rem; border-radius: 10px; overflow: auto;"></pre>
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
    const downloadPngLink = document.querySelector('#download-png-link');
    const downloadJpgLink = document.querySelector('#download-jpg-link');
    const downloadPgwLink = document.querySelector('#download-pgw-link');
    const downloadOmapLink = document.querySelector('#download-omap-link');
    const downloadGpxLink = document.querySelector('#download-gpx-link');
    const downloadKmzLink = document.querySelector('#download-kmz-link');
    const editLink = document.querySelector('#edit-link');
    const metaBlock = document.querySelector('#meta-block');
    const centerText = document.querySelector('#center-text');
    const utmText = document.querySelector('#utm-text');
    const declText = document.querySelector('#decl-text');

    function fmtFloat(v, digits){
        digits = (typeof digits === 'number' ? digits : 6);
        if (typeof v !== 'number' || !isFinite(v)){
            return '';
        }
        return v.toFixed(digits).replace(/\.?0+$/, '').replace('.', ',');
    }

    function fmtDeg(v){
        if (typeof v !== 'number' || !isFinite(v)){
            return '';
        }
        const s = v >= 0 ? '+' : '';
        return `${s}${fmtFloat(v, 2)}°`;
    }

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
                const pngUrl = `result.php?job=${encodeURIComponent(jobId)}&format=png&t=${Date.now()}`;
                const pngDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=png&download=1&t=${Date.now()}`;
                const jpgDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=jpg&download=1&t=${Date.now()}`;
                const pgwDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=pgw&download=1&t=${Date.now()}`;
                const omapDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=omap&download=1&t=${Date.now()}`;
                const gpxDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=gpx&download=1&t=${Date.now()}`;
                const kmzDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=kmz&download=1&t=${Date.now()}`;
                resultImg.src = pngUrl;
                downloadPngLink.href = pngDlUrl;
                downloadJpgLink.href = jpgDlUrl;
                downloadPgwLink.href = pgwDlUrl;
                downloadOmapLink.href = omapDlUrl;
                downloadGpxLink.href = gpxDlUrl;
                downloadKmzLink.href = kmzDlUrl;

                if (json.input){
                    const q = new URLSearchParams();
                    if (json.input.settings){ q.set('settings', String(json.input.settings)); }
                    if (json.input.latTopLeft != null){ q.set('latTopLeft', String(json.input.latTopLeft)); }
                    if (json.input.lngTopLeft != null){ q.set('lngTopLeft', String(json.input.lngTopLeft)); }
                    if (json.input.latBottomRight != null){ q.set('latBottomRight', String(json.input.latBottomRight)); }
                    if (json.input.lngBottomRight != null){ q.set('lngBottomRight', String(json.input.lngBottomRight)); }
                    editLink.href = `index.php?${q.toString()}`;
                } else {
                    editLink.href = 'index.php';
                }

                if (json.meta && json.meta.center && json.meta.utm && json.meta.magnetic && typeof json.meta.magnetic.declinationDeg === 'number'){
                    metaBlock.style.display = 'block';
                    centerText.textContent = `lat ${fmtFloat(json.meta.center.lat, 6)}, lon ${fmtFloat(json.meta.center.lon, 6)}`;
                    const utm = json.meta.utm;
                    const zone = (utm.zone || '') + (utm.band || '');
                    utmText.textContent = `${zone} ${utm.hemisphere || ''}  E ${fmtFloat(utm.easting, 2)}  N ${fmtFloat(utm.northing, 2)}`;
                    declText.textContent = fmtDeg(json.meta.magnetic.declinationDeg);
                } else if (json.meta && json.meta.error){
                    metaBlock.style.display = 'block';
                    centerText.textContent = '';
                    utmText.textContent = '';
                    declText.textContent = `Indisponible (${json.meta.error})`;
                }
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
<?php layout_footer(); ?>
