<?php

namespace App\Enums;

enum NameSuffix: string
{
    case Junior = 'Jr.';
    case Senior = 'Sr.';
    case Second = 'II';
    case Third = 'III';
    case Fourth = 'IV';
    case Fifth = 'V';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
