<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layout.php';

app_boot();
$user = auth_current_user();

$uid = (int)($_GET['user'] ?? ($_POST['user'] ?? 0));
$token = (string)($_GET['token'] ?? ($_POST['token'] ?? ''));

$error = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $pass = (string)($_POST['password'] ?? '');
        if (!auth_reset_password($uid, $token, $pass)){
            $error = 'Lien invalide ou expiré.';
        } else {
            $ok = 'Mot de passe mis à jour. Vous pouvez vous connecter.';
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

layout_header('Réinitialisation', $user);
?>
<div class="card">
    <h2 style="margin-top:0;">Réinitialiser le mot de passe</h2>
    <?php if ($error): ?>
        <div class="error"><?= app_h($error) ?></div>
    <?php endif; ?>
    <?php if ($ok): ?>
        <div class="ok"><?= app_h($ok) ?></div>
        <div class="row" style="margin-top:1rem;">
            <a class="btn" href="login.php">Connexion</a>
        </div>
    <?php else: ?>
        <form method="post" style="margin-top:0.75rem;">
            <?= csrf_input() ?>
            <input type="hidden" name="user" value="<?= app_h((string)$uid) ?>">
            <input type="hidden" name="token" value="<?= app_h($token) ?>">
            <div class="field">
                <label>Nouveau mot de passe (min 8 caractères)</label>
                <input type="password" name="password" required autocomplete="new-password" minlength="8">
            </div>
            <div class="row" style="margin-top:1rem;">
                <button class="btn" type="submit">Mettre à jour</button>
                <a class="btn secondary" href="login.php">Retour</a>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php
layout_footer();

