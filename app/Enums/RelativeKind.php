<?php

namespace App\Enums;

/**
 * How one resident is related to another when the relationship is not a parent, child or spouse link.
 */
enum RelativeKind: string
{
    case Sibling = 'sibling';
    case Grandparent = 'grandparent';
    case Grandchild = 'grandchild';
    case UncleAunt = 'uncle_aunt';
    case NephewNiece = 'nephew_niece';
    case Cousin = 'cousin';

    /**
     * The same relationship seen from the other person's side.
     */
    public function inverse(): self
    {
        return match ($this) {
            self::Sibling => self::Sibling,
            self::Cousin => self::Cousin,
            self::Grandparent => self::Grandchild,
            self::Grandchild => self::Grandparent,
            self::UncleAunt => self::NephewNiece,
            self::NephewNiece => self::UncleAunt,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Sibling => 'Brother or sister',
            self::Grandparent => 'Grandfather or grandmother',
            self::Grandchild => 'Grandchild',
            self::UncleAunt => 'Uncle or aunt',
            self::NephewNiece => 'Nephew or niece',
            self::Cousin => 'Cousin',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
