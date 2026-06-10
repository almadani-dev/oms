<?php

namespace App\Helpers;

class NumberHelper
{
    public static function bigComma(mixed $value, int $decimals = 2): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $formatted = number_format((float) $value, $decimals);
        return str_replace(',', '<span style="font-size:1.3em">,</span>', $formatted);
    }
}
