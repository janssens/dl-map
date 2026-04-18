<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';

function mailer_mode(): string {
    $mode = strtolower(trim((string)getenv('DL_MAP_MAILER')));
    return $mode !== '' ? $mode : 'log';
}

function mailer_log_path(): string {
    return app_var_dir() . '/mail.log';
}

function send_mail_message(string $to, string $subject, string $body): bool {
    $mode = mailer_mode();
    if ($mode === 'mail'){
        $headers = [];
        $headers[] = 'Content-Type: text/plain; charset=UTF-8';
        $from = trim((string)getenv('DL_MAP_MAIL_FROM'));
        if ($from !== ''){
            $headers[] = 'From: ' . $from;
        }
        return @mail($to, $subject, $body, implode("\r\n", $headers));
    }

    // Default: write to var/mail.log (dev-friendly, works in Docker).
    if (!is_dir(app_var_dir())){
        mkdir(app_var_dir(), 0777, true);
    }
    $entry = [];
    $entry[] = '=== ' . gmdate('c') . ' ===';
    $entry[] = 'TO: ' . $to;
    $entry[] = 'SUBJECT: ' . $subject;
    $entry[] = $body;
    $entry[] = '';
    @file_put_contents(mailer_log_path(), implode("\n", $entry), FILE_APPEND);
    return true;
}

