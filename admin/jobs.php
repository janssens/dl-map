<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/csrf.php';
require_once __DIR__ . '/../lib/jobs.php';

app_boot();
$admin = auth_require_admin();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete'){
            $jobId = (string)($_POST['job'] ?? '');
            jobs_delete_job($jobId, $admin);
            $ok = 'Job supprimé';
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

$filter = trim((string)($_GET['user'] ?? ''));
$myJobs = jobs_list_for_user($admin, 200);
$allJobs = jobs_admin_list_all($filter !== '' ? $filter : null, 800);

layout_header('Admin - Jobs', $admin);
?>
<div class="card">
    <div class="row" style="justify-content:space-between;">
        <h2 style="margin-top:0;">Jobs</h2>
        <a class="btn secondary" href="index.php">Retour</a>
    </div>
    <?php if ($error): ?><div class="error"><?= app_h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= app_h($ok) ?></div><?php endif; ?>

    <h3 style="margin-top:1rem;">Mes jobs</h3>
    <?php if (count($myJobs) === 0): ?>
        <div class="muted">Aucun job.</div>
    <?php else: ?>
        <table style="margin-top:0.5rem;">
            <thead>
            <tr>
                <th>Date</th>
                <th>Layer</th>
                <th>État</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($myJobs as $j): ?>
                <?php
                $jid = (string)($j['id'] ?? '');
                $status = $jid !== '' ? jobs_read_status($jid) : ['state' => 'error'];
                $state = (string)($status['state'] ?? 'unknown');
                $msg = (string)($status['message'] ?? ($status['error'] ?? ''));
                ?>
                <tr>
                    <td class="muted"><?= app_h((string)($j['created_at'] ?? '')) ?></td>
                    <td><?= app_h((string)($j['settings_slug'] ?? '')) ?></td>
                    <td class="muted"><?= app_h($state . ($msg !== '' ? ' · ' . $msg : '')) ?></td>
                    <td>
                        <a class="btn secondary" href="/generate.php?job=<?= urlencode($jid) ?>">Voir</a>
                        <form method="post" style="display:inline-block;" onsubmit="return confirm('Supprimer ce job ?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="job" value="<?= app_h($jid) ?>">
                            <button class="btn secondary" type="submit">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <h3 style="margin-top:1.25rem;">Tous les jobs</h3>
    <form method="get" class="row" style="margin-top:0.5rem;">
        <div class="field" style="margin-top:0; min-width: 260px; flex: 1;">
            <label>Filtrer (email ou user_id)</label>
            <input type="text" name="user" value="<?= app_h($filter) ?>" placeholder="ex: user@example.com ou 12">
        </div>
        <div style="align-self:flex-end;">
            <button class="btn secondary" type="submit">Filtrer</button>
        </div>
    </form>

    <?php if (count($allJobs) === 0): ?>
        <div class="muted" style="margin-top:0.75rem;">Aucun job.</div>
    <?php else: ?>
        <table style="margin-top:0.75rem;">
            <thead>
            <tr>
                <th>Date</th>
                <th>Utilisateur</th>
                <th>Tier</th>
                <th>Layer</th>
                <th>État</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($allJobs as $j): ?>
                <?php
                $jid = (string)($j['id'] ?? '');
                $status = $jid !== '' ? jobs_read_status($jid) : ['state' => 'error'];
                $state = (string)($status['state'] ?? 'unknown');
                $msg = (string)($status['message'] ?? ($status['error'] ?? ''));
                $uName = trim((string)($j['user_first_name'] ?? '') . ' ' . (string)($j['user_last_name'] ?? ''));
                $uEmail = (string)($j['user_email'] ?? '');
                $uDisplay = $uName !== '' ? ($uName . ' · ' . $uEmail) : $uEmail;
                ?>
                <tr>
                    <td class="muted"><?= app_h((string)($j['created_at'] ?? '')) ?></td>
                    <td><?= app_h($uDisplay) ?></td>
                    <td class="muted"><?= app_h((string)($j['user_tier'] ?? '')) ?></td>
                    <td><?= app_h((string)($j['settings_slug'] ?? '')) ?></td>
                    <td class="muted"><?= app_h($state . ($msg !== '' ? ' · ' . $msg : '')) ?></td>
                    <td>
                        <a class="btn secondary" href="/generate.php?job=<?= urlencode($jid) ?>">Voir</a>
                        <form method="post" style="display:inline-block;" onsubmit="return confirm('Supprimer ce job ?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="job" value="<?= app_h($jid) ?>">
                            <button class="btn secondary" type="submit">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
layout_footer();

