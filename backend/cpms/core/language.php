<?php
declare(strict_types=1);

function cpmsLanguage(): string
{
    return (string)(
        $_SESSION['language']
        ?? cpmsConfig('default_language', 'ms')
    );
}

function cpmsSetLanguage(string $language): void
{
    if (!in_array($language, ['ms', 'en'], true)) {
        $language = 'ms';
    }

    $_SESSION['language'] = $language;
}
