<?php

namespace App\Enums;

enum CardReplacementReason: string
{
    case Lost = 'Lost';
    case Stolen = 'Stolen';
    case Damaged = 'Damaged';

    /**
     * Whether the card may be in someone else's hands, so the resident ID must not be typed in its place.
     */
    public function isMisplaced(): bool
    {
        return $this !== self::Damaged;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
