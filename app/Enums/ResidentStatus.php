<?php

namespace App\Enums;

enum ResidentStatus: string
{
    case Pending = 'Pending';
    case Approved = 'Approved';
    case Archived = 'Archived';
}
