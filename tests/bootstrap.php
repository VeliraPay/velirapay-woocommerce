<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

// The pure classes translate their order notes with WordPress's __(), which is all they need from it.
if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}
