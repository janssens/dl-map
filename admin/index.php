<?php

declare(strict_types=1);

require_once __DIR__ . '/../lib/app.php';
require_once __DIR__ . '/../lib/auth.php';
require_once __DIR__ . '/../lib/layout.php';

app_boot();
$user = auth_require_admin();

layout_header('Admin', $user);
?>
<div class="card">
    <h2 style="margin-top:0;">Admin</h2>
    <div class="row" style="margin-top:0.75rem;">
        <a class="btn" href="users.php">Utilisateurs</a>
        <a class="btn" href="layers.php">Layers</a>
    </div>
</div>
<?php
layout_footer();

