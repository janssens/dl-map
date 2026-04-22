<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/csrf.php';
require_once __DIR__ . '/lib/jobs.php';

app_boot();
$user = auth_require_login();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'delete'){
            $jobId = (string)($_POST['job'] ?? '');
            jobs_delete_job($jobId, $user);
            $ok = 'Job supprimé';
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

$jobs = jobs_list_for_user($user, 300);
$limit = jobs_user_job_limit($user);
$count = jobs_count_active_for_user($user);

layout_header('Mes jobs', $user);
?>
<div class="card">
    <div class="row" style="justify-content:space-between;">
        <h2 style="margin-top:0;">Mes jobs</h2>
        <a class="btn secondary" href="/index.php">Retour</a>
    </div>
    <?php if ($error): ?><div class="error"><?= app_h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= app_h($ok) ?></div><?php endif; ?>

    <?php if ($limit !== null): ?>
        <div class="muted" style="margin-top:0.5rem;">
            Compte gratuit: <?= app_h((string)$count) ?>/<?= app_h((string)$limit) ?> jobs.
        </div>
    <?php else: ?>
        <div class="muted" style="margin-top:0.5rem;">Compte premium: pas de limite.</div>
    <?php endif; ?>

    <?php if (count($jobs) === 0): ?>
        <div class="muted" style="margin-top:0.75rem;">Aucun job pour le moment.</div>
    <?php else: ?>
        <table style="margin-top:0.75rem;">
            <thead>
            <tr>
                <th>Date</th>
                <th>Layer</th>
                <th>État</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($jobs as $j): ?>
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
                        <a class="btn secondary" href="/generate.php?job=<?= urlencode($jid) ?>">Ouvrir</a>
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

