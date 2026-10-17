<?php

namespace App\Enums;

enum RepresentativeRelationship: string
{
    case Spouse = 'Spouse';
    case Child = 'Child';
    case Parent = 'Parent';
    case Sibling = 'Sibling';
    case OtherRelative = 'Other relative';
    case Neighbor = 'Neighbor';
    case Other = 'Other';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
