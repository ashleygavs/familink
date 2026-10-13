<?php

namespace App\Enums;

enum ResidentStatus: string
{
    /** A relative declared by a family member who has not registered yet. Cannot claim relief. */
    case Listed = 'Listed';
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Archived = 'Archived';
}
