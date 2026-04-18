<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/layers.php';
require_once __DIR__ . '/lib/layout.php';
require_once __DIR__ . '/lib/csrf.php';

app_boot();
$user = auth_require_login();

$error = '';
$ok = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST'){
    try {
        csrf_require_post();
        $action = (string)($_POST['action'] ?? '');
        if ($action === 'create'){
            layers_user_create(
                (int)$user['id'],
                (string)($_POST['slug'] ?? ''),
                (string)($_POST['label'] ?? ''),
                (string)($_POST['settings_json'] ?? '')
            );
            $ok = 'Layer créé';
        } elseif ($action === 'update'){
            layers_user_update(
                (int)$user['id'],
                (int)($_POST['id'] ?? 0),
                (string)($_POST['label'] ?? ''),
                (string)($_POST['settings_json'] ?? '')
            );
            $ok = 'Layer mis à jour';
        } elseif ($action === 'delete'){
            layers_user_delete((int)$user['id'], (int)($_POST['id'] ?? 0));
            $ok = 'Layer supprimé';
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

$stmt = db()->prepare("SELECT * FROM layers WHERE owner_user_id = ? AND access = 'private' ORDER BY label COLLATE NOCASE");
$stmt->execute([(int)$user['id']]);
$my = $stmt->fetchAll();

layout_header('Mes layers', $user);
?>
<div class="card">
    <h2 style="margin-top:0;">Mes layers (privés)</h2>
    <?php if ($error): ?><div class="error"><?= app_h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= app_h($ok) ?></div><?php endif; ?>

    <h3 style="margin-top:1rem;">Créer</h3>
    <form method="post">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <div class="row">
            <div class="field" style="flex:1; min-width: 220px;">
                <label>Slug (unique)</label>
                <input type="text" name="slug" required placeholder="mon-layer">
            </div>
            <div class="field" style="flex:2; min-width: 260px;">
                <label>Label</label>
                <input type="text" name="label" required placeholder="Mon layer">
            </div>
        </div>
        <div class="field">
            <label>settings JSON</label>
            <textarea name="settings_json" rows="10" required placeholder='{"url":"...","layer":"...","zoom":16,"tile_size":[256,256],"file_ext":"png"}'></textarea>
        </div>
        <div class="row" style="margin-top:0.75rem;">
            <button class="btn" type="submit">Créer</button>
        </div>
        <div class="muted" style="margin-top:0.5rem;">
            Astuce: partez d'un layer global existant et modifiez le JSON.
        </div>
    </form>

    <h3 style="margin-top:1.25rem;">Liste</h3>
    <?php if (count($my) === 0): ?>
        <div class="muted">Aucun layer privé.</div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Slug</th>
                <th>Label</th>
                <th>Actions</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($my as $row): ?>
                <tr>
                    <td class="muted"><?= app_h((string)$row['slug']) ?></td>
                    <td><?= app_h((string)$row['label']) ?></td>
                    <td>
                        <details>
                            <summary class="muted" style="cursor:pointer;">Éditer</summary>
                            <form method="post" style="margin-top:0.5rem;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="id" value="<?= app_h((string)$row['id']) ?>">
                                <div class="field">
                                    <label>Label</label>
                                    <input type="text" name="label" value="<?= app_h((string)$row['label']) ?>" required>
                                </div>
                                <div class="field">
                                    <label>settings JSON</label>
                                    <textarea name="settings_json" rows="10" required><?= app_h((string)$row['settings_json']) ?></textarea>
                                </div>
                                <div class="row" style="margin-top:0.75rem;">
                                    <button class="btn" type="submit">Enregistrer</button>
                                </div>
                            </form>
                            <form method="post" onsubmit="return confirm('Supprimer ce layer ?');" style="margin-top:0.5rem;">
                                <?= csrf_input() ?>
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= app_h((string)$row['id']) ?>">
                                <button class="btn secondary" type="submit">Supprimer</button>
                            </form>
                        </details>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php
layout_footer();

