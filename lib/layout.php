<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/auth.php';

function layout_header(string $title, ?array $user, string $extraHeadHtml = ''): void {
    $extra_head_html = $extraHeadHtml;
    require __DIR__ . '/../partials/header.php';
}

function layout_footer(): void {
    require __DIR__ . '/../partials/footer.php';
}
