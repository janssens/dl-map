<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/csrf.php';

app_boot();
$user = auth_require_login();

$error = '';
$ok = '';

if (!empty($user['email_verified_at'])){
    app_redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        auth_send_email_verification($user);
        $ok = "Email envoyé (mode dev: voir `var/mail.log`).";
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

layout_header('Renvoyer confirmation', $user);
?>
<div class="card">
    <h2 style="margin-top:0;">Renvoyer l'email de confirmation</h2>
    <?php if ($error): ?><div class="error"><?= app_h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= app_h($ok) ?></div><?php endif; ?>
    <form method="post" style="margin-top:0.75rem;">
        <?= csrf_input() ?>
        <button class="btn" type="submit">Envoyer</button>
        <a class="btn secondary" href="index.php">Retour</a>
    </form>
</div>
<?php
layout_footer();

