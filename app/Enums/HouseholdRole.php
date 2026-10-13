<?php

namespace App\Enums;

enum HouseholdRole: string
{
    case Head = 'Head';
    case Spouse = 'Spouse';
    case Child = 'Child';
    case Parent = 'Parent';
    case Sibling = 'Sibling';
    case Relative = 'Relative';
    case Other = 'Other';
}
