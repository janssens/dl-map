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
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $email = (string)($_POST['email'] ?? '');
        $password = (string)($_POST['password'] ?? '');
        $u = auth_authenticate($email, $password);
        if (!$u){
            $error = 'Email ou mot de passe incorrect';
        } else {
            auth_login((int)$u['id']);
            app_redirect('index.php');
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

layout_header('Connexion', null);
?>
<div class="card">
    <h2 style="margin-top:0;">Connexion</h2>
    <?php if ($error): ?>
        <div class="error"><?= app_h($error) ?></div>
    <?php endif; ?>
    <form method="post" style="margin-top:0.75rem;">
        <?= csrf_input() ?>
        <div class="field">
            <label>Email</label>
            <input type="email" name="email" required autocomplete="email">
        </div>
        <div class="field">
            <label>Mot de passe</label>
            <input type="password" name="password" required autocomplete="current-password">
        </div>
        <div class="row" style="margin-top:1rem;">
            <button class="btn" type="submit">Se connecter</button>
            <a class="btn secondary" href="forgot_password.php">Mot de passe oublié</a>
        </div>
    </form>
</div>
<?php
layout_footer();

