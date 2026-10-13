import {
    BarChart3,
    Building2,
    CheckCircle2,
    LayoutDashboard,
    Lightbulb,
    PackageCheck,
    QrCode,
    UserPlus,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import {
    approvals,
    events,
    municipalities,
    suggestionReport,
    users,
} from '@/routes/admin';
import { register, scanner } from '@/routes/encoder';
import { analytics } from '@/routes/reports';
import type { NavItem, RoleName } from '@/types';

export const roleLabels: Record<RoleName, string> = {
    super_admin: 'Super Admin',
    admin: 'Admin',
    stakeholder: 'Stakeholder',
    user: 'Encoder',
};

export const roleBadgeClasses: Record<RoleName, string> = {
    super_admin: 'bg-blue-950 text-white',
    admin: 'bg-blue-600 text-white',
    stakeholder: 'bg-sky-700 text-white',
    user: 'bg-indigo-600 text-white',
};

export type RoleNavItem = NavItem & {
    description: string;
    roles: RoleName[];
};

const allRoles: RoleName[] = ['super_admin', 'admin', 'stakeholder', 'user'];

const roleNavItems: RoleNavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutDashboard,
        description: 'See the latest numbers at a glance.',
        roles: allRoles,
    },
    {
        title: 'Register Resident',
        href: register(),
        icon: UserPlus,
        description: 'Add a new resident and link their family.',
        roles: ['user'],
    },
    {
        title: 'Scan QR Code',
        href: scanner(),
        icon: QrCode,
        description: 'Verify a resident and record a relief claim.',
        roles: ['user'],
    },
    {
        title: 'Approvals',
        href: approvals(),
        icon: CheckCircle2,
        description: 'Review residents waiting for approval.',
        roles: ['admin'],
    },
    {
        title: 'Relief Events',
        href: events(),
        icon: PackageCheck,
        description: 'Create and manage relief distributions.',
        roles: ['admin'],
    },
    {
        title: 'Suggestion Report',
        href: suggestionReport(),
        icon: Lightbulb,
        description: 'See how often suggested relatives are confirmed.',
        roles: ['admin'],
    },
    {
        title: 'Analytics',
        href: analytics(),
        icon: BarChart3,
        description: 'View reports and family trees.',
        roles: ['stakeholder'],
    },
    {
        title: 'Users',
        href: users(),
        icon: Users,
        description: 'Create encoders and assign them to barangays.',
        roles: ['admin'],
    },
    {
        title: 'Municipalities',
        href: municipalities(),
        icon: Building2,
        description: 'Manage municipalities and their barangays.',
        roles: ['super_admin'],
    },
];

/**
 * Navigation entries a role can open. The super admin sees every page.
 */
export function navItemsFor(role: RoleName | null): RoleNavItem[] {
    if (role === null) {
        return roleNavItems.filter((item) => item.title === 'Dashboard');
    }

    return roleNavItems.filter(
        (item) => role === 'super_admin' || item.roles.includes(role),
    );
}
