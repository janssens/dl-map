<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/auth.php';
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
        if ($action === 'create'){
            $email = (string)($_POST['email'] ?? '');
            $password = (string)($_POST['password'] ?? '');
            $first = (string)($_POST['first_name'] ?? '');
            $last = (string)($_POST['last_name'] ?? '');
            $tier = (string)($_POST['tier'] ?? 'free');
            $created = auth_admin_create_user($email, $password, $first, $last, $tier);
            auth_send_email_verification($created);
            $ok = 'Utilisateur créé (email de confirmation envoyé; mode dev: voir `var/mail.log`)';
        } else {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0){
                throw new RuntimeException('Invalid user id');
            }

            if ($action === 'set_tier'){
                $tier = (string)($_POST['tier'] ?? '');
                if (!auth_valid_tier($tier)){
                    throw new RuntimeException('Tier invalide');
                }
                // Prevent removing last admin.
                if ($tier !== 'admin'){
                    $countAdmins = (int)db()->query("SELECT COUNT(*) FROM users WHERE tier = 'admin' AND disabled_at IS NULL")->fetchColumn();
                    $stmtIsAdmin = db()->prepare('SELECT tier FROM users WHERE id = ?');
                    $stmtIsAdmin->execute([$id]);
                    $wasTier = (string)$stmtIsAdmin->fetchColumn();
                    if ($wasTier === 'admin' && $countAdmins <= 1){
                        throw new RuntimeException('Impossible de retirer le dernier admin');
                    }
                }

                $stmt = db()->prepare('UPDATE users SET tier = ?, updated_at = ? WHERE id = ?');
                $stmt->execute([$tier, db_now(), $id]);
                $ok = 'Utilisateur mis à jour';
            } elseif ($action === 'disable'){
                // Prevent disabling last admin.
                $stmtIsAdmin = db()->prepare('SELECT tier FROM users WHERE id = ?');
                $stmtIsAdmin->execute([$id]);
                $wasTier = (string)$stmtIsAdmin->fetchColumn();
                if ($wasTier === 'admin'){
                    $countAdmins = (int)db()->query("SELECT COUNT(*) FROM users WHERE tier = 'admin' AND disabled_at IS NULL")->fetchColumn();
                    if ($countAdmins <= 1){
                        throw new RuntimeException('Impossible de désactiver le dernier admin');
                    }
                }
                $stmt = db()->prepare('UPDATE users SET disabled_at = ?, updated_at = ? WHERE id = ?');
                $now = db_now();
                $stmt->execute([$now, $now, $id]);
                $ok = 'Utilisateur désactivé';
            } elseif ($action === 'enable'){
                $stmt = db()->prepare('UPDATE users SET disabled_at = NULL, updated_at = ? WHERE id = ?');
                $stmt->execute([db_now(), $id]);
                $ok = 'Utilisateur réactivé';
            } elseif ($action === 'delete'){
                $stmtTier = db()->prepare('SELECT tier, disabled_at FROM users WHERE id = ?');
                $stmtTier->execute([$id]);
                $row = $stmtTier->fetch();
                if (!is_array($row)){
                    throw new RuntimeException('Utilisateur introuvable');
                }
                if (empty($row['disabled_at'])){
                    throw new RuntimeException('Désactivez le compte avant suppression');
                }
                if ((string)$row['tier'] === 'admin'){
                    $countAdmins = (int)db()->query("SELECT COUNT(*) FROM users WHERE tier = 'admin' AND disabled_at IS NULL")->fetchColumn();
                    if ($countAdmins <= 0){
                        // Should never happen, but avoid foot-guns.
                        throw new RuntimeException('Impossible de supprimer un admin actif');
                    }
                }
                $stmtDel = db()->prepare('DELETE FROM users WHERE id = ?');
                $stmtDel->execute([$id]);
                $ok = 'Utilisateur supprimé';
            }
        }
    } catch (Throwable $e){
        $error = $e->getMessage();
    }
}

$users = db()->query('SELECT id,email,first_name,last_name,tier,email_verified_at,disabled_at,created_at FROM users ORDER BY created_at DESC')->fetchAll();

layout_header('Admin - Utilisateurs', $admin);
?>
<div class="card">
    <div class="row" style="justify-content:space-between;">
        <h2 style="margin-top:0;">Utilisateurs</h2>
        <a class="btn secondary" href="index.php">Retour</a>
    </div>
    <?php if ($error): ?><div class="error"><?= app_h($error) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="ok"><?= app_h($ok) ?></div><?php endif; ?>

    <h3 style="margin-top:1rem;">Créer un utilisateur</h3>
    <form method="post" style="margin-top:0.5rem;">
        <?= csrf_input() ?>
        <input type="hidden" name="action" value="create">
        <div class="row">
            <div class="field" style="flex:2; min-width: 260px;">
                <label>Email</label>
                <input type="email" name="email" required autocomplete="off">
            </div>
            <div class="field" style="flex:1; min-width: 160px;">
                <label>Tier</label>
                <select name="tier">
                    <option value="free">free</option>
                    <option value="premium">premium</option>
                    <option value="admin">admin</option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="field" style="flex:1; min-width: 220px;">
                <label>Prénom</label>
                <input type="text" name="first_name" autocomplete="off">
            </div>
            <div class="field" style="flex:1; min-width: 220px;">
                <label>Nom</label>
                <input type="text" name="last_name" autocomplete="off">
            </div>
        </div>
        <div class="field">
            <label>Mot de passe initial (min 8 caractères)</label>
            <input type="password" name="password" required minlength="8" autocomplete="off">
        </div>
        <div class="row" style="margin-top:0.75rem;">
            <button class="btn" type="submit">Créer</button>
        </div>
    </form>

    <table style="margin-top:0.75rem;">
        <thead>
        <tr>
            <th>Email</th>
            <th>Nom</th>
            <th>Tier</th>
            <th>Vérifié</th>
            <th>Statut</th>
            <th>Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= app_h((string)$u['email']) ?></td>
                <td class="muted"><?= app_h(trim((string)$u['first_name'] . ' ' . (string)$u['last_name'])) ?></td>
                <td><?= app_h((string)$u['tier']) ?></td>
                <td class="muted"><?= $u['email_verified_at'] ? 'oui' : 'non' ?></td>
                <td class="muted"><?= $u['disabled_at'] ? 'désactivé' : 'actif' ?></td>
                <td>
                    <form method="post" style="display:inline-block;">
                        <?= csrf_input() ?>
                        <input type="hidden" name="action" value="set_tier">
                        <input type="hidden" name="id" value="<?= app_h((string)$u['id']) ?>">
                        <select name="tier">
                            <?php foreach (['free','premium','admin'] as $tier): ?>
                                <option value="<?= app_h($tier) ?>" <?= $tier === $u['tier'] ? 'selected' : '' ?>><?= app_h($tier) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn secondary" type="submit">OK</button>
                    </form>
                    <?php if ($u['disabled_at']): ?>
                        <form method="post" style="display:inline-block;">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="enable">
                            <input type="hidden" name="id" value="<?= app_h((string)$u['id']) ?>">
                            <button class="btn secondary" type="submit">Réactiver</button>
                        </form>
                        <form method="post" style="display:inline-block;" onsubmit="return confirm('Supprimer définitivement cet utilisateur ?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= app_h((string)$u['id']) ?>">
                            <button class="btn secondary" type="submit">Supprimer</button>
                        </form>
                    <?php else: ?>
                        <form method="post" style="display:inline-block;" onsubmit="return confirm('Désactiver cet utilisateur ?');">
                            <?= csrf_input() ?>
                            <input type="hidden" name="action" value="disable">
                            <input type="hidden" name="id" value="<?= app_h((string)$u['id']) ?>">
                            <button class="btn secondary" type="submit">Désactiver</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php
layout_footer();
