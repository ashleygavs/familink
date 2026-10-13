<?php

namespace App\Enums;

enum EventStatus: string
{
    /** Being prepared. Cannot be scanned yet. */
    case Draft = 'Draft';
    /** Open for scanning while today is inside its dates. */
    case Active = 'Active';
    /** Finished for good. Its claims are kept but nothing more can be scanned. */
    case Closed = 'Closed';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The statuses an event may move to next. A closed event never reopens, so claims stay trustworthy.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::Draft => [self::Active, self::Closed],
            self::Active => [self::Closed],
            self::Closed => [],
        };
    }
}
