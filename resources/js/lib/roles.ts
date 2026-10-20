import {
    BarChart3,
    Building2,
    CheckCircle2,
    Contact,
    GitCompareArrows,
    LayoutDashboard,
    Lightbulb,
    ListChecks,
    PackageCheck,
    QrCode,
    ShieldAlert,
    SplitSquareHorizontal,
    UserPlus,
    Users,
} from 'lucide-react';
import { dashboard } from '@/routes';
import { index as residentsIndex } from '@/routes/residents';
import {
    approvals,
    events,
    municipalities,
    suggestionReport,
    users,
} from '@/routes/admin';
import { register, scanner } from '@/routes/encoder';
import {
    analytics,
    dataCheck,
    distributionList,
    householdReview,
    relationship,
} from '@/routes/reports';
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
        title: 'Residents',
        href: residentsIndex(),
        icon: Contact,
        description: 'Find a resident and print their QR card.',
        roles: ['user', 'admin'],
    },
    {
        title: 'Scan QR Code',
        href: scanner(),
        icon: QrCode,
        description: 'Verify a resident and record a relief claim.',
        roles: ['user'],
    },
    {
        title: 'Distribution List',
        href: distributionList(),
        icon: ListChecks,
        description:
            'Print who can claim in a barangay, with a signature column.',
        roles: ['user', 'admin'],
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
        description:
            'See how relief reached each barangay, and open family trees.',
        roles: ['stakeholder', 'admin'],
    },
    {
        title: 'Household Review',
        href: householdReview(),
        icon: SplitSquareHorizontal,
        description:
            'Check families recorded in two households, so none claims twice.',
        roles: ['stakeholder', 'admin'],
    },
    {
        title: 'Data Check',
        href: dataCheck(),
        icon: ShieldAlert,
        description: 'Find family records that cannot be right.',
        roles: ['stakeholder', 'admin'],
    },
    {
        title: 'Relationship Finder',
        href: relationship(),
        icon: GitCompareArrows,
        description: 'See how two residents are related.',
        roles: ['stakeholder', 'admin'],
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
