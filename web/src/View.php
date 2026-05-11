<?php

declare(strict_types=1);

namespace App;

final class View
{
    public static function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        include dirname(__DIR__) . '/views/' . $template . '.php';
    }
}
