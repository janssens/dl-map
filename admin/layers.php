<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layers.php';
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../lib/csrf.php';

app_boot();
$admin = auth_require_admin();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'upsert'){
            $idRaw = trim((string)($_POST['id'] ?? ''));
            $id = $idRaw === '' ? null : (int)$idRaw;
            layers_admin_upsert_global(
                $id,
                (string)($_POST['slug'] ?? ''),
                (string)($_POST['label'] ?? ''),
                (string)($_POST['access'] ?? 'public'),
                (string)($_POST['settings_json'] ?? '')
            );
            $ok = 'Layer enregistré';
        } elseif ($action === 'delete'){
            layers_admin_delete_global((int)($_POST['id'] ?? 0));
            $ok = 'Layer supprimé';
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

$rows = layers_admin_list_all();

layout_header('Admin - Layers', $admin);
?>
<div class="card">
    <div class="row" style="justify-content:space-between;">
        <h2 style="margin-top:0;">Layers</h2>
        <a class="btn secondary" href="index.php">Retour</a>
    </div>
    <?php if ($error): ?><div class="error"><?= app_h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= app_h($ok) ?></div><?php endif; ?>

    <h3 style="margin-top:1rem;">Créer un layer global</h3>
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="upsert">
        <input type="hidden" name="id" value="">
        <div class="row">
            <div class="field" style="flex:1; min-width: 220px;">
                <label>Slug</label>
                <input name="slug" required>
            </div>
            <div class="field" style="flex:2; min-width: 240px;">
                <label>Label</label>
                <input name="label" required>
            </div>
            <div class="field" style="min-width: 160px;">
                <label>Accès</label>
                <select name="access">
                    <option value="public">public</option>
                    <option value="premium">premium</option>
                    <option value="admin">admin</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label>settings JSON</label>
            <textarea name="settings_json" rows="10" required></textarea>
        </div>
        <div class="row" style="margin-top:0.75rem;">
            <button class="btn" type="submit">Créer</button>
        </div>
    </form>

    <h3 style="margin-top:1.25rem;">Liste</h3>
    <table>
        <thead>
        <tr>
            <th>Type</th>
            <th>Slug</th>
            <th>Label</th>
            <th>Accès</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="muted"><?= $r['owner_user_id'] ? 'privé' : 'global' ?></td>
                <td class="muted"><?= app_h((string)$r['slug']) ?></td>
                <td><?= app_h((string)$r['label']) ?></td>
                <td><?= app_h((string)$r['access']) ?></td>
                <td>
                    <?php if (!$r['owner_user_id']): ?>
                        <details>
                            <summary class="muted" style="cursor:pointer;">Éditer</summary>
                            <form method="post" style="margin-top:0.5rem;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="upsert">
                                <input type="hidden" name="id" value="<?= app_h((string)$r['id']) ?>">
                                <div class="row">
                                    <div class="field" style="flex:1; min-width: 220px;">
                                        <label>Slug</label>
                                        <input name="slug" required value="<?= app_h((string)$r['slug']) ?>">
                                    </div>
                                    <div class="field" style="flex:2; min-width: 240px;">
                                        <label>Label</label>
                                        <input name="label" required value="<?= app_h((string)$r['label']) ?>">
                                    </div>
                                    <div class="field" style="min-width: 160px;">
                                        <label>Accès</label>
                                        <select name="access">
                                            <?php foreach (['public','premium','admin'] as $a): ?>
                                                <option value="<?= app_h($a) ?>" <?= $a === $r['access'] ? 'selected' : '' ?>><?= app_h($a) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </div>
                                <div class="field">
                                    <label>settings JSON</label>
                                    <textarea name="settings_json" rows="10" required><?= app_h((string)$r['settings_json']) ?></textarea>
                                </div>
                                <div class="row" style="margin-top:0.75rem;">
                                    <button class="btn" type="submit">Enregistrer</button>
                                </div>
                            </form>
                            <form method="post" onsubmit="return confirm('Supprimer ce layer global ?');" style="margin-top:0.5rem;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= app_h((string)$r['id']) ?>">
                                <button class="btn secondary" type="submit">Supprimer</button>
                            </form>
                        </details>
                    <?php else: ?>
                        <span class="muted">Géré par l'utilisateur</span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
layout_footer();

