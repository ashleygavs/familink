<?php

namespace App\Enums;

enum RoleName: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Stakeholder = 'stakeholder';
    case User = 'user';

    /**
     * The path a user with this role lands on after logging in.
     */
    public function homePath(): string
    {
        return match ($this) {
            self::SuperAdmin => '/admin/users',
            self::Admin => '/admin/approvals',
            self::Stakeholder => '/reports/analytics',
            self::User => '/encoder/register',
        };
    }
}
