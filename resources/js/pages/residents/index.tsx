import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Contact,
    Home,
    Printer,
    Search,
    UserCog,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import ResidentManagementController from '@/actions/App/Http/Controllers/ResidentManagementController';
import { ChoiceSelect, Field } from '@/components/registration/field';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cards as householdCards } from '@/routes/households';
import {
    card as residentCard,
    index as residentsRoute,
} from '@/routes/residents';
import type { Paginated } from '@/types';

type ResidentRow = {
    id: number;
    full_name: string;
    system_id_no: string;
    status: string;
    household_id: number | null;
    household_code: string | null;
    barangay_name: string | null;
    is_deceased: boolean;
};

type Props = {
    residents: Paginated<ResidentRow>;
    filters: { q: string; status: string };
};

const statusChoices = [
    { value: 'Approved', label: 'Approved' },
    { value: 'Pending', label: 'Waiting for approval' },
    { value: 'all', label: 'Everyone registered' },
];

export default function Residents({ residents, filters }: Props) {
    const { auth } = usePage().props;
    // Only admins can change a resident's marriage or household after approval.
    const canManage =
        auth.role_name === 'admin' || auth.role_name === 'super_admin';
    const [search, setSearch] = useState(filters.q);

    function apply(next: Partial<Props['filters']>) {
        const merged = { q: search, status: filters.status, ...next };

        router.get(
            residentsRoute().url,
            {
                q: merged.q === '' ? undefined : merged.q,
                status:
                    merged.status === 'Approved' ? undefined : merged.status,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submit(event: FormEvent) {
        event.preventDefault();
        apply({});
    }

    return (
        <>
            <Head title="Residents" />
            <div className="mx-auto flex w-full max-w-4xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <Contact className="size-8 text-blue-600" />
                        Residents
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Find a resident to print their QR card again. Only
                        approved residents have a card.
                    </p>
                </div>

                <form
                    onSubmit={submit}
                    className="grid gap-3 sm:grid-cols-[1fr_15rem_auto] sm:items-end"
                >
                    <Field id="resident-search" label="Search">
                        <Input
                            id="resident-search"
                            value={search}
                            placeholder="Name, resident ID or household code"
                            onChange={(event) => setSearch(event.target.value)}
                        />
                    </Field>
                    <Field id="resident-status" label="Show">
                        <ChoiceSelect
                            id="resident-status"
                            value={filters.status}
                            placeholder="Show"
                            values={statusChoices}
                            onChange={(status) => apply({ status })}
                        />
                    </Field>
                    <Button type="submit">
                        <Search />
                        Search
                    </Button>
                </form>

                {residents.data.length === 0 ? (
                    <p className="rounded-xl border-2 border-slate-200 p-6 text-lg dark:border-border">
                        No residents match.
                    </p>
                ) : (
                    <ul className="grid gap-3">
                        {residents.data.map((resident) => (
                            <li
                                key={resident.id}
                                className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-blue-200 bg-card p-4 dark:border-sidebar-border"
                            >
                                <div className="grid min-w-0 flex-1 gap-1">
                                    <span className="flex flex-wrap items-center gap-2 text-xl font-bold">
                                        {resident.full_name}
                                        {resident.status === 'Pending' && (
                                            <span className="rounded-md bg-amber-500 px-2 py-0.5 text-sm font-bold text-black">
                                                Waiting for approval
                                            </span>
                                        )}
                                        {resident.is_deceased && (
                                            <span className="rounded-md bg-slate-700 px-2 py-0.5 text-sm font-bold text-white">
                                                Deceased
                                            </span>
                                        )}
                                    </span>
                                    <span className="text-base text-muted-foreground">
                                        ID {resident.system_id_no}
                                        {resident.barangay_name
                                            ? ` · Barangay ${resident.barangay_name}`
                                            : ''}
                                    </span>
                                    {resident.household_code && (
                                        <span className="inline-flex items-center gap-1 text-base">
                                            <Home className="size-4 text-blue-600" />
                                            {resident.household_code}
                                        </span>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    {canManage && (
                                        <Button asChild variant="outline">
                                            <Link
                                                href={ResidentManagementController.show.url(
                                                    resident.id,
                                                )}
                                            >
                                                <UserCog />
                                                Update
                                            </Link>
                                        </Button>
                                    )}
                                    {resident.status === 'Approved' && (
                                        <Button asChild>
                                            <Link
                                                href={residentCard(resident.id)}
                                            >
                                                <Printer />
                                                QR card
                                            </Link>
                                        </Button>
                                    )}
                                    {resident.household_id && (
                                        <Button asChild variant="outline">
                                            <Link
                                                href={householdCards(
                                                    resident.household_id,
                                                )}
                                            >
                                                <Printer />
                                                Whole household
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}

                {residents.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between gap-3"
                        aria-label="Pagination"
                    >
                        {residents.prev_page_url ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={residents.prev_page_url}
                                    preserveScroll
                                >
                                    <ChevronLeft />
                                    Previous
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="text-base font-medium">
                            Page {residents.current_page} of{' '}
                            {residents.last_page}
                        </span>
                        {residents.next_page_url ? (
                            <Button asChild variant="outline">
                                <Link
                                    href={residents.next_page_url}
                                    preserveScroll
                                >
                                    Next
                                    <ChevronRight />
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}

Residents.layout = {
    breadcrumbs: [
        {
            title: 'Residents',
            href: residentsRoute(),
        },
    ],
};
