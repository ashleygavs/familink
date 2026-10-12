<?php

namespace App\Enums;

enum AllocationType: string
{
    case PerHousehold = 'Per Household';
    case PerResident = 'Per Resident';
}
