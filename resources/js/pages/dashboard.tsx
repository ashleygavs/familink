import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowRight, Clock, Home, PackageCheck, Users } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { navItemsFor } from '@/lib/roles';
import { dashboard } from '@/routes';

type Stats = {
    approvedResidents: number;
    pendingResidents: number;
    households: number;
    reliefClaims: number;
};

function MetricCard({
    label,
    value,
    helper,
    icon: Icon,
}: {
    label: string;
    value: number;
    helper: string;
    icon: LucideIcon;
}) {
    return (
        <Card className="border-slate-200 dark:border-border">
            <CardHeader className="flex flex-row items-center justify-between gap-3">
                <CardTitle className="text-base font-semibold text-slate-700 dark:text-slate-200">
                    {label}
                </CardTitle>
                <span className="flex size-12 shrink-0 items-center justify-center rounded-full bg-sky-100 text-blue-600">
                    <Icon className="size-6" />
                </span>
            </CardHeader>
            <CardContent>
                <p className="text-4xl font-bold text-blue-700 dark:text-blue-400">
                    {value.toLocaleString()}
                </p>
                <p className="mt-1 text-base text-muted-foreground">{helper}</p>
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ stats }: { stats: Stats }) {
    const { auth } = usePage().props;
    const actions = navItemsFor(auth.role_name).filter(
        (item) => item.title !== 'Dashboard',
    );

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex flex-1 flex-col gap-8 bg-sky-50/50 p-4 md:p-6 dark:bg-transparent">
                <div>
                    <h1 className="text-3xl font-bold text-blue-900 dark:text-white">
                        Welcome, {auth.user.name}
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Here is a quick summary of FamiLink
                        {auth.barangay_name
                            ? ` for Barangay ${auth.barangay_name}`
                            : ''}
                        .
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <MetricCard
                        label="Approved Residents"
                        value={stats.approvedResidents}
                        helper="Registered and ready for relief."
                        icon={Users}
                    />
                    <MetricCard
                        label="Pending Approvals"
                        value={stats.pendingResidents}
                        helper="Waiting for admin review."
                        icon={Clock}
                    />
                    <MetricCard
                        label="Households"
                        value={stats.households}
                        helper="Families on record."
                        icon={Home}
                    />
                    <MetricCard
                        label="Relief Claims"
                        value={stats.reliefClaims}
                        helper="Packages given out so far."
                        icon={PackageCheck}
                    />
                </div>

                <section className="flex flex-col gap-4">
                    <div>
                        <h2 className="text-2xl font-bold">
                            What would you like to do next?
                        </h2>
                        <p className="text-base text-muted-foreground">
                            Choose one of the options below to get started.
                        </p>
                    </div>

                    {actions.length > 0 ? (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {actions.map((action) => (
                                <Link
                                    key={action.title}
                                    href={action.href}
                                    className="group flex min-h-24 items-center gap-4 rounded-xl border-2 border-blue-200 bg-card p-5 transition-colors hover:border-blue-600 hover:bg-sky-50 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none dark:border-sidebar-border dark:hover:bg-sidebar-accent"
                                >
                                    {action.icon && (
                                        <span className="flex size-14 shrink-0 items-center justify-center rounded-xl bg-blue-600 text-white">
                                            <action.icon className="size-7" />
                                        </span>
                                    )}
                                    <span className="flex-1">
                                        <span className="block text-xl font-bold">
                                            {action.title}
                                        </span>
                                        <span className="block text-base text-muted-foreground">
                                            {action.description}
                                        </span>
                                    </span>
                                    <ArrowRight className="size-6 text-blue-600 transition-transform group-hover:translate-x-1" />
                                </Link>
                            ))}
                        </div>
                    ) : (
                        <p className="rounded-xl border-2 border-amber-300 bg-amber-50 p-5 text-lg text-amber-900">
                            Your account does not have a role yet. Please ask
                            the Super Admin to assign one.
                        </p>
                    )}
                </section>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
