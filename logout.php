<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/app.php';
require_once __DIR__ . '/lib/auth.php';

app_boot();
auth_logout();
app_redirect('index.php');

