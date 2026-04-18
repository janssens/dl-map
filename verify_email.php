<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

app_boot();
$user = auth_current_user();

$uid = (int)($_GET['user'] ?? 0);
$token = (string)($_GET['token'] ?? '');
$ok = false;
$error = '';

if ($uid > 0 && $token !== ''){
    try {
        $ok = auth_verify_email($uid, $token);
        if (!$ok){
            $error = "Lien invalide ou expiré.";
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
} else {
    $error = 'Lien incomplet.';
}

layout_header('Confirmation email', $user);
?>
<div class="card">
    <h2 style="margin-top:0;">Confirmation email</h2>
    <?php if ($ok): ?>
        <div class="ok">Votre email est confirmé.</div>
    <?php else: ?>
        <div class="error"><?= app_h($error) ?></div>
    <?php endif; ?>
    <div class="row" style="margin-top:1rem;">
        <a class="btn" href="index.php">Retour</a>
    </div>
</div>
<?php
layout_footer();

