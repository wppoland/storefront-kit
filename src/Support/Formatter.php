<?php

declare(strict_types=1);

namespace WPPoland\StorefrontKit\Support;

final class Formatter
{
    /**
     * @param array<string, scalar|null> $values
     */
    public static function interpolate(string $template, array $values): string
    {
        foreach ($values as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }

        return $template;
    }
}
