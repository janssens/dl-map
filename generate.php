<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/settings.php';
require_once __DIR__ . '/lib/layers.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/jobs.php';

app_boot();
$user = auth_require_login();

function start_job(array $input, array $user, ?string $sourceJobId = null): string {
    if (!is_dir(jobs_root())){
        mkdir(jobs_root(), 0777, true);
    }

    $jobId = bin2hex(random_bytes(12));
    $dir = jobs_job_dir($jobId);
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

    jobs_insert($jobId, $user, (string)($input['settings'] ?? ''), $sourceJobId, (string)($input['createdAt'] ?? ''));

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
    $limit = jobs_user_job_limit($user);
    if ($limit !== null && jobs_count_active_for_user($user) >= $limit){
        header('Location: index.php?error=job_limit');
        exit;
    }

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
    ], $user);
} else {
    $reloadFrom = (string)($_GET['reload'] ?? '');
    if ($reloadFrom !== ''){
        $limit = jobs_user_job_limit($user);
        if ($limit !== null && jobs_count_active_for_user($user) >= $limit){
            header('Location: index.php?error=job_limit');
            exit;
        }
        if (!jobs_is_valid_job_id($reloadFrom)){
            header('Location: index.php');
            exit;
        }
        try {
            $row = jobs_load_row($reloadFrom);
            if (!empty($row['deleted_at'])){
                throw new RuntimeException('Not found');
            }
            if (!auth_is_admin($user) && (int)($row['user_id'] ?? 0) !== (int)($user['id'] ?? 0)){
                throw new RuntimeException('Forbidden');
            }
        } catch (Throwable $e){
            header('Location: index.php');
            exit;
        }
        $inputPath = jobs_job_dir($reloadFrom) . '/input.json';
        if (!is_file($inputPath)){
            header('Location: index.php');
            exit;
        }
        $raw = file_get_contents($inputPath);
        $input = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($input)){
            header('Location: index.php');
            exit;
        }
        $settingsId = normalize_settings_id((string)($input['settings'] ?? ''));
        try {
            layers_load_settings_for_user($settingsId, $user);
        } catch (Throwable $e){
            header('Location: index.php');
            exit;
        }
        $input['createdAt'] = date('c');
        $input['sourceJobId'] = $reloadFrom;
        $newId = start_job($input, $user, $reloadFrom);
        header('Location: generate.php?job=' . urlencode($newId));
        exit;
    }

    $jobId = (string)($_GET['job'] ?? '');
    if (!jobs_is_valid_job_id($jobId)){
        header('Location: index.php');
        exit;
    }
    try {
        $row = jobs_load_row($jobId);
        if (!empty($row['deleted_at'])){
            throw new RuntimeException('Not found');
        }
        if (!auth_is_admin($user) && (int)($row['user_id'] ?? 0) !== (int)($user['id'] ?? 0)){
            throw new RuntimeException('Forbidden');
        }
    } catch (Throwable $e){
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
            <a class="btn black" href="index.php"><span class="btn-ico"><?= layout_svg_icon('arrow-left') ?></span>Nouvelle génération</a>
            <a class="btn orange" id="reload-link" href="#"><span class="btn-ico"><?= layout_svg_icon('refresh') ?></span>Relancer</a>
            <a class="btn blue" id="download-png-link" href="#"><span class="btn-ico"><?= layout_svg_icon('download') ?></span>Télécharger PNG</a>
            <a class="btn blue" id="download-jpg-link" href="#"><span class="btn-ico"><?= layout_svg_icon('download') ?></span>Télécharger JPG</a>
            <a class="btn blue" id="download-omap-link" href="#"><span class="btn-ico"><?= layout_svg_icon('download') ?></span>Télécharger OMAP + KMZ (ZIP)</a>
            <a class="btn blue" id="download-gpx-link" href="#"><span class="btn-ico"><?= layout_svg_icon('download') ?></span>Télécharger GPX des 4 coins</a>
            <a class="btn blue" id="download-kmz-link" href="#"><span class="btn-ico"><?= layout_svg_icon('download') ?></span>Télécharger KMZ</a>
            <a class="btn orange" id="edit-link" href="#"><span class="btn-ico"><?= layout_svg_icon('swap') ?></span>Changer de layer</a>
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
            <a class="btn orange" id="error-back-link" href="#"><span class="btn-ico"><?= layout_svg_icon('swap') ?></span>Retour</a>
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
    const downloadOmapLink = document.querySelector('#download-omap-link');
    const downloadGpxLink = document.querySelector('#download-gpx-link');
    const downloadKmzLink = document.querySelector('#download-kmz-link');
    const editLink = document.querySelector('#edit-link');
    const reloadLink = document.querySelector('#reload-link');
    const errorBackLink = document.querySelector('#error-back-link');
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
                const omapDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=omap&download=1&t=${Date.now()}`;
                const gpxDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=gpx&download=1&t=${Date.now()}`;
                const kmzDlUrl = `result.php?job=${encodeURIComponent(jobId)}&format=kmz&download=1&t=${Date.now()}`;
                resultImg.src = pngUrl;
                downloadPngLink.href = pngDlUrl;
                downloadJpgLink.href = jpgDlUrl;
                downloadOmapLink.href = omapDlUrl;
                downloadGpxLink.href = gpxDlUrl;
                downloadKmzLink.href = kmzDlUrl;
                reloadLink.href = `generate.php?reload=${encodeURIComponent(jobId)}`;

                if (json.input){
                    const q = new URLSearchParams();
                    if (json.input.settings){ q.set('settings', String(json.input.settings)); }
                    if (json.input.latTopLeft != null){ q.set('latTopLeft', String(json.input.latTopLeft)); }
                    if (json.input.lngTopLeft != null){ q.set('lngTopLeft', String(json.input.lngTopLeft)); }
                    if (json.input.latBottomRight != null){ q.set('latBottomRight', String(json.input.latBottomRight)); }
                    if (json.input.lngBottomRight != null){ q.set('lngBottomRight', String(json.input.lngBottomRight)); }
                    const backHref = `index.php?${q.toString()}`;
                    editLink.href = backHref;
                    errorBackLink.href = backHref;
                } else {
                    editLink.href = 'index.php';
                    errorBackLink.href = 'index.php';
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
                if (json.input){
                    const q = new URLSearchParams();
                    if (json.input.settings){ q.set('settings', String(json.input.settings)); }
                    if (json.input.latTopLeft != null){ q.set('latTopLeft', String(json.input.latTopLeft)); }
                    if (json.input.lngTopLeft != null){ q.set('lngTopLeft', String(json.input.lngTopLeft)); }
                    if (json.input.latBottomRight != null){ q.set('latBottomRight', String(json.input.latBottomRight)); }
                    if (json.input.lngBottomRight != null){ q.set('lngBottomRight', String(json.input.lngBottomRight)); }
                    errorBackLink.href = `index.php?${q.toString()}`;
                } else {
                    errorBackLink.href = 'index.php';
                }
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
