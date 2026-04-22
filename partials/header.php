<?php

declare(strict_types=1);

// Expected variables:
// - $title (string)
// - $user (array|null)
// - $extra_head_html (string, optional)

if (!isset($title) || !is_string($title)){
    $title = 'Casse dalles';
}
$extra_head_html = (isset($extra_head_html) && is_string($extra_head_html)) ? $extra_head_html : '';

$name = '';
if (is_array($user ?? null)){
    $first = trim((string)($user['first_name'] ?? ''));
    $last = trim((string)($user['last_name'] ?? ''));
    $name = trim($first . ' ' . $last);
    if ($name === ''){
        $name = (string)($user['email'] ?? '');
    }
}
$needsVerify = is_array($user ?? null) && empty($user['email_verified_at']);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= app_h($title) ?></title>
    <link rel="stylesheet" href="/assets/app.css" />
    <link rel="apple-touch-icon" sizes="180x180" href="/apple-touch-icon.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">
    <link rel="manifest" href="/site.webmanifest">
    <style>
        body{ background:#f0f0f0; margin:0; padding:0; font-family: system-ui, -apple-system, Segoe UI, Roboto, Ubuntu, Cantarell, "Noto Sans", sans-serif; }
        .topbar{ position: sticky; top: 0; z-index: 50; display:flex; justify-content:space-between; align-items:center; gap:1rem; padding:0.8rem 1rem; background:#000; color:#fff; border-bottom: 1px solid rgba(255,255,255,0.08); }
        .topbar a{ text-decoration:none; font-weight:800; color:#fff; }
        .topbar .right{ display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; justify-content:flex-end; }
        .container{ max-width: 980px; margin: 0 auto; padding: 1rem 1rem 2rem; }
        .card{ background:#fff; border:1px solid rgba(0,0,0,0.12); border-radius:12px; padding:1rem; box-shadow: 0 10px 28px rgba(0,0,0,0.10); }
        .btn{ display:inline-flex; align-items:center; gap:0.5rem; padding:0.55rem 0.85rem; border-radius:10px; background:#111; color:#fff; text-decoration:none; font-weight:800; border:0; cursor:pointer; line-height: 1; }
        .btn.secondary{ background: rgba(0,0,0,0.08); color:#111; }
        .btn.blue{ background: #1d4ed8; color:#fff; }
        .btn.orange{ background: #f59e0b; color:#111; }
        .btn.black{ background: #111; color:#fff; }
        .btn-ico{ display:inline-flex; }
        .btn-ico svg{ width:16px; height:16px; }
        .row{ display:flex; gap:0.75rem; align-items:center; flex-wrap:wrap; }
        .field{ display:flex; flex-direction:column; gap:0.35rem; margin-top:0.75rem; }
        .field input, .field select, .field textarea{ padding:0.6rem 0.7rem; border-radius:10px; border:1px solid rgba(0,0,0,0.18); font: inherit; }
        .error{ background: rgba(255,0,0,0.06); border: 1px solid rgba(255,0,0,0.18); padding:0.75rem; border-radius:12px; }
        .ok{ background: rgba(0,168,107,0.08); border: 1px solid rgba(0,168,107,0.22); padding:0.75rem; border-radius:12px; }
        table{ width:100%; border-collapse: collapse; }
        th, td{ text-align:left; padding: 0.5rem; border-bottom: 1px solid rgba(0,0,0,0.08); vertical-align: top; }
        th{ font-size: 0.9rem; opacity: 0.8; }
        .muted{ opacity:0.75; }
        .mono{ font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    </style>
    <?= $extra_head_html ?>
</head>
<body>
<div class="topbar">
    <div class="left">
        <a href="/index.php">🗺️ Casse dalles</a>
    </div>
    <div class="right">
        <?php if (!is_array($user ?? null)): ?>
            <a href="/login.php">Connexion</a>
            <a href="/register.php">Créer un compte</a>
        <?php else: ?>
            <span class="muted">
                <?= app_h($name) ?> (<?= app_h((string)($user['tier'] ?? 'free')) ?>)
                <?= $needsVerify ? ' · email non confirmé' : '' ?>
            </span>
            <a href="/jobs.php">Mes jobs</a>
            <a href="/my_layers.php">Mes layers</a>
            <?php if (auth_is_admin($user)): ?>
                <a href="/admin/index.php">Admin</a>
            <?php endif; ?>
            <?php if ($needsVerify): ?>
                <a href="/resend_verification.php">Renvoyer confirmation</a>
            <?php endif; ?>
            <a href="/logout.php">Déconnexion</a>
        <?php endif; ?>
    </div>
</div>
<div class="container">
