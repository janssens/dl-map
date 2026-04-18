<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

app_boot();
$user = auth_current_user();
if ($user){
    // Allow reset even when logged in? Keep it simple: log out first.
}

$ok = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $email = (string)($_POST['email'] ?? '');
        auth_send_password_reset($email);
        $ok = "Si un compte existe avec cet email, un lien a été envoyé (mode dev: voir `var/mail.log`).";
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

layout_header('Mot de passe oublié', $user);
?>
<div class="card">
    <h2 style="margin-top:0;">Mot de passe oublié</h2>
    <?php if ($error): ?>
        <div class="error"><?= app_h($error) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
        <div class="ok"><?= app_h($ok) ?></div>
    <?php endif; ?>
    <form method="post" style="margin-top:0.75rem;">
        <?= csrf_input() ?>
        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">
        </div>
        <div class="row" style="margin-top:1rem;">
            <button class="btn" type="submit">Envoyer le lien</button>
            <a class="btn secondary" href="login.php">Retour</a>
        </div>
    </form>
</div>
<?php
layout_footer();

