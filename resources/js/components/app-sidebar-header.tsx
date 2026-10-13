import { Link, router, usePage } from '@inertiajs/react';
import { LogOut, MapPin, ShieldCheck } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { RoleBadge } from '@/components/role-badge';
import { buttonVariants } from '@/components/ui/button';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useMobileNavigation } from '@/hooks/use-mobile-navigation';
import { clearRegistrationDrafts } from '@/lib/registration-draft';
import { logout } from '@/routes';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { auth, name } = usePage().props;
    const cleanup = useMobileNavigation();

    const handleLogout = () => {
        cleanup();
        clearRegistrationDrafts();
        router.flushAll();
    };

    return (
        <header className="sticky top-0 z-20 flex min-h-16 shrink-0 items-center justify-between gap-3 border-b-2 border-blue-200 bg-white/95 px-4 backdrop-blur dark:border-sidebar-border dark:bg-background/95">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1 size-10" />
                <div className="flex items-center gap-2 md:hidden">
                    <span className="flex size-9 items-center justify-center rounded-lg bg-blue-600 text-white">
                        <ShieldCheck className="size-5" />
                    </span>
                    <span className="text-lg font-bold text-blue-900 dark:text-white">
                        {name}
                    </span>
                </div>
                <div className="hidden min-w-0 md:block">
                    <Breadcrumbs breadcrumbs={breadcrumbs} />
                </div>
            </div>

            <div className="flex items-center gap-3">
                <span
                    className="hidden items-center gap-1.5 text-base font-medium text-slate-700 lg:flex dark:text-slate-200"
                    data-test="barangay-indicator"
                >
                    <MapPin className="size-5 text-blue-600" />
                    {auth.scope_label}
                </span>
                <RoleBadge role={auth.role_name} />
                <span className="hidden max-w-48 truncate text-base font-semibold sm:block">
                    {auth.user.name}
                </span>
                <Link
                    href={logout()}
                    as="button"
                    onClick={handleLogout}
                    className={buttonVariants({ variant: 'outline' })}
                    data-test="header-logout-button"
                >
                    <LogOut />
                    <span className="hidden sm:inline">Log Out</span>
                </Link>
            </div>
        </header>
    );
}
