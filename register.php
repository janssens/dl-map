<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

app_boot();
$user = auth_current_user();
if ($user){
    app_redirect('index.php');
}

$error = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $email = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $first = (string)($_POST['first_name'] ?? '');
        $last = (string)($_POST['last_name'] ?? '');

        $created = auth_register_user($email, $password, $first, $last);
        auth_login((int)$created['id']);
        auth_send_email_verification($created);
        $ok = "Compte créé. Un email de confirmation a été envoyé (mode dev: voir `var/mail.log`).";
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

layout_header('Créer un compte', null);
?>
<div class="card">
    <h2 style="margin-top:0;">Créer un compte</h2>
    <?php if ($error): ?>
        <div class="error"><?= app_h($error) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
        <div class="ok"><?= app_h($ok) ?></div>
    <?php endif; ?>
    <form method="post" style="margin-top:0.75rem;">
        <?= csrf_input() ?>
        <div class="field">
            <label>Prénom</label>
            <input type="text" name="first_name" autocomplete="given-name">
        </div>
        <div class="field">
            <label>Nom</label>
            <input type="text" name="last_name" autocomplete="family-name">
        </div>
        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">
        </div>
        <div class="field">
            <label>Mot de passe (min 8 caractères)</label>
            <input type="password" name="password" required autocomplete="new-password" minlength="8">
        </div>
        <div class="row" style="margin-top:1rem;">
            <button class="btn" type="submit">Créer</button>
            <a class="btn secondary" href="login.php">J'ai déjà un compte</a>
        </div>
    </form>
    <div class="muted" style="margin-top:0.75rem;">
        S'il n'existe aucun admin, le premier compte créé devient admin automatiquement.
    </div>
</div>
<?php
layout_footer();

