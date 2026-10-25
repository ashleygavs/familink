import { Head, Link, router } from '@inertiajs/react';
import {
    BarChart3,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Download,
    ListChecks,
    MapPin,
    Network,
    Search,
} from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import { ChoiceSelect, Field } from '@/components/registration/field';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { familyTree } from '@/routes';
import {
    analytics,
    claimsCsv,
    coverageCsv,
    distributionList,
} from '@/routes/reports';
import type { Paginated } from '@/types';

type EventOption = {
    id: number;
    title: string;
    municipality_name: string | null;
    province: string | null;
    status: string;
    allocation_type: string;
    max_claims_per_household: number | null;
    start_date: string;
    end_date: string;
};

type BarangayRow = {
    id: number;
    name: string;
    municipality_name: string | null;
    approved_residents: number;
    households: number;
    eligible: number;
    reached: number;
    claims: number;
    households_reached: number;
    coverage: number | null;
};

type HouseholdRow = {
    id: number;
    household_code: string;
    street_address: string;
    head_name: string | null;
    barangay_name: string;
    members: number | null;
    claimed: boolean | null;
};

type Props = {
    filters: { event: number | null; barangay: number | null; q: string };
    events: EventOption[];
    totals: {
        approved_residents: number;
        pending_residents: number;
        households: number;
        eligible: number;
        claims: number;
        households_reached: number;
        coverage: number | null;
    };
    /** Whether the viewer may open the printable distribution list. */
    canPrintLists: boolean;
    /** Where the chosen event is happening. */
    location: {
        municipality: string | null;
        province: string | null;
        barangays: string[];
    } | null;
    barangays: BarangayRow[];
    households: Paginated<HouseholdRow>;
};

function Stat({
    label,
    value,
    tone = '',
}: {
    label: string;
    value: ReactNode;
    tone?: string;
}) {
    return (
        <div className="rounded-xl border-2 border-slate-200 p-4 dark:border-border">
            <p className="text-base font-semibold text-muted-foreground">
                {label}
            </p>
            <p className={`text-4xl font-bold ${tone}`}>{value}</p>
        </div>
    );
}

function CoverageBar({ value }: { value: number | null }) {
    if (value === null) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <div className="flex items-center gap-2">
            <div
                className="h-3 w-24 overflow-hidden rounded-full bg-slate-200 dark:bg-muted"
                role="img"
                aria-label={`${value} percent reached`}
            >
                <div
                    className="h-full rounded-full bg-emerald-600"
                    style={{ width: `${value}%` }}
                />
            </div>
            <span className="text-base font-bold">{value}%</span>
        </div>
    );
}

export default function Analytics({
    filters,
    events,
    totals,
    canPrintLists,
    location,
    barangays,
    households,
}: Props) {
    const [search, setSearch] = useState(filters.q);
    const event = events.find((candidate) => candidate.id === filters.event);

    function apply(
        next: Partial<{
            event: number | null;
            barangay: number | null;
            q: string;
        }>,
    ) {
        const merged = { ...filters, q: search, ...next };

        router.get(
            analytics().url,
            {
                event: merged.event ?? undefined,
                barangay: merged.barangay ?? undefined,
                q: merged.q === '' ? undefined : merged.q,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    function submitSearch(submitted: FormEvent) {
        submitted.preventDefault();
        apply({});
    }

    const reachedLabel =
        event?.allocation_type === 'Per Household'
            ? 'Households reached'
            : 'Residents reached';
    const reachedValue =
        event?.allocation_type === 'Per Household'
            ? totals.households_reached
            : totals.claims;

    return (
        <>
            <Head title="Analytics" />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <BarChart3 className="size-8 text-blue-600" />
                        Analytics
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        How relief reached each barangay you can see, and every
                        household&apos;s family tree.
                    </p>
                </div>

                {events.length === 0 ? (
                    <p className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-lg text-amber-950">
                        No relief event has been started yet, so only resident
                        and household counts are shown.
                    </p>
                ) : (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <Field id="analytics-event" label="Relief event">
                            <ChoiceSelect
                                id="analytics-event"
                                value={filters.event?.toString() ?? ''}
                                placeholder="Choose an event"
                                values={events.map((candidate) => ({
                                    value: candidate.id.toString(),
                                    label: `${candidate.title} · ${candidate.municipality_name ?? 'No municipality'} (${candidate.status})`,
                                }))}
                                onChange={(value) =>
                                    apply({ event: Number(value) })
                                }
                            />
                        </Field>
                        {event && (
                            <p className="self-end rounded-xl border-2 border-blue-200 bg-sky-50 p-3 text-base dark:border-sidebar-border dark:bg-sidebar">
                                {event.allocation_type} ·{' '}
                                {formatDate(event.start_date)} to{' '}
                                {formatDate(event.end_date)}
                            </p>
                        )}
                        {event && location && (
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-xl border-2 border-blue-200 bg-sky-50 p-3 text-base sm:col-span-2 dark:border-sidebar-border dark:bg-sidebar">
                                <MapPin className="size-5 text-blue-600" />
                                <span className="font-semibold">
                                    Happening in {location.municipality}
                                    {location.province
                                        ? `, ${location.province}`
                                        : ''}
                                </span>
                                <span className="text-muted-foreground">
                                    {location.barangays.length}{' '}
                                    {location.barangays.length === 1
                                        ? 'barangay'
                                        : 'barangays'}
                                    :
                                </span>
                                {location.barangays.map((name) => (
                                    <span
                                        key={name}
                                        className="rounded-md bg-blue-600 px-2 py-0.5 text-base font-bold text-white"
                                    >
                                        {name}
                                    </span>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Approved residents"
                        value={totals.approved_residents}
                        tone="text-blue-700 dark:text-blue-300"
                    />
                    <Stat label="Households" value={totals.households} />
                    <Stat
                        label="Waiting for approval"
                        value={totals.pending_residents}
                        tone="text-amber-700 dark:text-amber-300"
                    />
                    {event ? (
                        <Stat
                            label={reachedLabel}
                            value={
                                <>
                                    {reachedValue}
                                    <span className="text-xl font-semibold text-muted-foreground">
                                        {' '}
                                        of {totals.eligible}
                                    </span>
                                </>
                            }
                            tone="text-emerald-700 dark:text-emerald-400"
                        />
                    ) : (
                        <Stat label="Relief reached" value="-" />
                    )}
                </div>

                {event && (
                    <div className="flex flex-wrap items-center gap-2">
                        <Button asChild variant="outline">
                            <a href={claimsCsv(event.id).url}>
                                <Download />
                                Claims (CSV)
                            </a>
                        </Button>
                        <Button asChild variant="outline">
                            <a href={coverageCsv(event.id).url}>
                                <Download />
                                Barangay summary (CSV)
                            </a>
                        </Button>
                        {canPrintLists && (
                            <Button asChild variant="outline">
                                <Link
                                    href={distributionList({
                                        query: { event: event.id },
                                    })}
                                >
                                    <ListChecks />
                                    Distribution list
                                </Link>
                            </Button>
                        )}
                        <span className="text-base text-muted-foreground">
                            Spreadsheets open in Excel. They only include the
                            barangays you can see.
                        </span>
                    </div>
                )}

                {event && (
                    <div className="grid gap-2 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4 text-emerald-950 dark:border-emerald-700 dark:bg-emerald-950 dark:text-emerald-50">
                        <p className="flex items-center gap-2 text-xl font-bold">
                            <CheckCircle2 className="size-6" />
                            {totals.coverage === null
                                ? 'No one is eligible yet'
                                : `${totals.coverage}% reached`}
                        </p>
                        <p className="text-base">
                            {event.title}: {totals.claims}{' '}
                            {totals.claims === 1 ? 'claim' : 'claims'} recorded.
                            Coverage counts{' '}
                            {event.allocation_type === 'Per Household'
                                ? 'households with at least one approved resident'
                                : event.max_claims_per_household !== null
                                  ? `approved residents, up to ${event.max_claims_per_household} per household`
                                  : 'approved residents'}
                            .
                        </p>
                    </div>
                )}

                <Card>
                    <CardHeader>
                        <CardTitle className="text-2xl">By barangay</CardTitle>
                        <CardDescription className="text-base">
                            Pick a barangay to see only its households below.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {barangays.length === 0 ? (
                            <p className="text-base text-muted-foreground">
                                No barangays to show.
                            </p>
                        ) : (
                            <table className="w-full text-left text-base">
                                <thead>
                                    <tr className="border-b-2 border-slate-200 dark:border-border">
                                        <th className="py-2 pr-4 font-bold">
                                            Barangay
                                        </th>
                                        <th className="px-2 py-2 font-bold">
                                            Residents
                                        </th>
                                        <th className="px-2 py-2 font-bold">
                                            Households
                                        </th>
                                        <th className="px-2 py-2 font-bold">
                                            Claims
                                        </th>
                                        <th className="py-2 pl-2 font-bold">
                                            Reached
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {barangays.map((row) => (
                                        <tr
                                            key={row.id}
                                            className="border-b border-slate-100 dark:border-border"
                                        >
                                            <td className="py-2 pr-4 font-semibold">
                                                <button
                                                    type="button"
                                                    className="min-h-11 text-left underline-offset-2 hover:underline"
                                                    onClick={() =>
                                                        apply({
                                                            barangay:
                                                                filters.barangay ===
                                                                row.id
                                                                    ? null
                                                                    : row.id,
                                                        })
                                                    }
                                                >
                                                    {row.name}
                                                </button>
                                                {row.municipality_name && (
                                                    <span className="ml-2 text-base font-normal text-muted-foreground">
                                                        {row.municipality_name}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.approved_residents}
                                            </td>
                                            <td className="px-2 py-2">
                                                {row.households}
                                            </td>
                                            <td className="px-2 py-2">
                                                {event ? row.claims : '-'}
                                            </td>
                                            <td className="py-2 pl-2">
                                                <CoverageBar
                                                    value={
                                                        event
                                                            ? row.coverage
                                                            : null
                                                    }
                                                />
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-2xl">Households</CardTitle>
                        <CardDescription className="text-base">
                            Open a household&apos;s family tree. Search by code,
                            street or head of household.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-4">
                        <form
                            onSubmit={submitSearch}
                            className="grid gap-3 sm:grid-cols-[1fr_16rem_auto] sm:items-end"
                        >
                            <Field id="household-search" label="Search">
                                <Input
                                    id="household-search"
                                    value={search}
                                    placeholder="HH-SJ-0001, Rizal Street or a name"
                                    onChange={(changed) =>
                                        setSearch(changed.target.value)
                                    }
                                />
                            </Field>
                            <Field id="household-barangay" label="Barangay">
                                <ChoiceSelect
                                    id="household-barangay"
                                    value={
                                        filters.barangay?.toString() ?? 'all'
                                    }
                                    placeholder="All barangays"
                                    values={[
                                        {
                                            value: 'all',
                                            label: 'All barangays',
                                        },
                                        ...barangays.map((row) => ({
                                            value: row.id.toString(),
                                            label: row.name,
                                        })),
                                    ]}
                                    onChange={(value) =>
                                        apply({
                                            barangay:
                                                value === 'all'
                                                    ? null
                                                    : Number(value),
                                        })
                                    }
                                />
                            </Field>
                            <Button type="submit">
                                <Search />
                                Search
                            </Button>
                        </form>

                        {households.data.length === 0 ? (
                            <p className="text-base text-muted-foreground">
                                No households match.
                            </p>
                        ) : (
                            <ul className="grid gap-2">
                                {households.data.map((household) => (
                                    <li
                                        key={household.id}
                                        className="flex flex-wrap items-center gap-3 rounded-lg border-2 border-slate-200 p-3 dark:border-border"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <p className="text-lg font-semibold">
                                                {household.household_code}
                                                <span className="ml-2 text-base font-normal text-muted-foreground">
                                                    {household.head_name ??
                                                        'No head yet'}
                                                </span>
                                            </p>
                                            <p className="text-base text-muted-foreground">
                                                {household.street_address} ·
                                                Barangay{' '}
                                                {household.barangay_name} ·{' '}
                                                {household.members}{' '}
                                                {household.members === 1
                                                    ? 'member'
                                                    : 'members'}
                                            </p>
                                        </div>
                                        {household.claimed !== null && (
                                            <span
                                                className={`rounded-md px-2 py-0.5 text-base font-bold ${
                                                    household.claimed
                                                        ? 'bg-emerald-600 text-white'
                                                        : 'bg-slate-200 text-slate-900'
                                                }`}
                                            >
                                                {household.claimed
                                                    ? 'Claimed'
                                                    : 'Not yet'}
                                            </span>
                                        )}
                                        <Button asChild variant="outline">
                                            <Link
                                                href={familyTree(household.id)}
                                            >
                                                <Network />
                                                Family tree
                                            </Link>
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {households.last_page > 1 && (
                            <nav
                                className="flex items-center justify-between gap-3"
                                aria-label="Pagination"
                            >
                                {households.prev_page_url ? (
                                    <Button asChild variant="outline">
                                        <Link
                                            href={households.prev_page_url}
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
                                    Page {households.current_page} of{' '}
                                    {households.last_page}
                                </span>
                                {households.next_page_url ? (
                                    <Button asChild variant="outline">
                                        <Link
                                            href={households.next_page_url}
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
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Analytics.layout = {
    breadcrumbs: [
        {
            title: 'Analytics',
            href: analytics(),
        },
    ],
};
