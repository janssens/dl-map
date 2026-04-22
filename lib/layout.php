<?php

declare(strict_types=1);

require_once __DIR__ . '/app.php';
require_once __DIR__ . '/auth.php';

function layout_svg_icon(string $name): string {
    $name = strtolower(trim($name));
    $commonAttrs = 'width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"';
    if ($name === 'download'){
        return '<svg ' . $commonAttrs . '><path d="M12 3v10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M7 11l5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M5 21h14" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    if ($name === 'swap'){
        return '<svg ' . $commonAttrs . '><path d="M7 7h11l-3-3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M17 17H6l3 3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M18 7v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M6 17v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
    }
    if ($name === 'refresh'){
        return '<svg ' . $commonAttrs . '><path d="M20 12a8 8 0 0 1-14.7 4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M4 12A8 8 0 0 1 18.7 8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><path d="M20 4v6h-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M4 20v-6h6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    }
    // arrow-left
    return '<svg ' . $commonAttrs . '><path d="M15 18l-6-6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M9 12h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>';
}

function layout_header(string $title, ?array $user, string $extraHeadHtml = ''): void {
    $extra_head_html = $extraHeadHtml;
    require __DIR__ . '/../partials/header.php';
}

function layout_footer(): void {
    require __DIR__ . '/../partials/footer.php';
}
