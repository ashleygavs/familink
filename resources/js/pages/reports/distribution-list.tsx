import { Head, Link, router } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Download,
    Home,
    ListChecks,
    MapPin,
    Printer,
    Search,
    Undo2,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import type { ReactNode } from 'react';
import { ChoiceSelect, Field } from '@/components/registration/field';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { formatDate } from '@/lib/format';
import { distributionList } from '@/routes/reports';
import { csv as distributionListCsv } from '@/routes/reports/distribution-list';
import type { Paginated } from '@/types';

type Member = {
    system_id_no: string;
    name: string;
    age: number;
    claimed: boolean;
    claimed_at: string | null;
    collected_by: string | null;
};

type HouseholdEntry = {
    code: string;
    street_address: string;
    head_name: string | null;
    claimed: boolean;
    claimed_at: string | null;
    limit_reached: boolean;
    members: Member[];
};

type Props = {
    filters: {
        event: number | null;
        barangay: number | null;
        only_unclaimed: boolean;
        /** What is typed in the search box. */
        q: string;
        /** Every household on one sheet, with no pages and no search. */
        all: boolean;
    };
    /** Why a request could not be done as asked, such as a barangay too big to print on one sheet. */
    notice: string | null;
    events: {
        id: number;
        title: string;
        status: string;
        municipality_name: string | null;
    }[];
    barangays: { id: number; name: string }[];
    event: {
        title: string;
        allocation_type: string;
        limit: number | null;
        start_date: string;
        end_date: string;
        municipality_name: string | null;
    } | null;
    barangay: string | null;
    list: {
        households: Paginated<HouseholdEntry>;
        /** The whole barangay, whatever the search or the page. */
        totals: { households: number; members: number; claimed: number };
    } | null;
    generated: { at: string; by: string };
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

function claimDetail(member: Member): string | null {
    if (!member.claimed || !member.claimed_at) {
        return null;
    }

    return member.collected_by
        ? `${member.claimed_at} · by ${member.collected_by}`
        : member.claimed_at;
}

/**
 * Whether a person has claimed, with when and who collected, or that the household has used its limit.
 */
function ClaimStatus({
    member,
    limitReached,
    className = '',
    showDetail = true,
}: {
    member: Member;
    limitReached: boolean;
    className?: string;
    /** Whether the time and the person who collected are shown under the badge. */
    showDetail?: boolean;
}) {
    if (member.claimed) {
        const detail = claimDetail(member);

        return (
            <span className={`grid text-base ${className}`}>
                <span className="flex items-center gap-1 font-semibold text-emerald-700 dark:text-emerald-400">
                    <CheckCircle2 className="size-4" />
                    Yes
                </span>
                {showDetail && detail && (
                    <span className="text-base text-muted-foreground">
                        {detail}
                    </span>
                )}
            </span>
        );
    }

    return limitReached ? (
        <span
            className={`text-base font-semibold text-amber-700 dark:text-amber-300 ${className}`}
        >
            Limit reached
        </span>
    ) : null;
}

function shareRule(event: NonNullable<Props['event']>): string {
    if (event.allocation_type === 'Per Household') {
        return 'One share for each household.';
    }

    return event.limit === null
        ? 'One share for each person.'
        : `One share for each person, up to ${event.limit} per household.`;
}

type Query = {
    event: number | null;
    barangay: number | null;
    only_unclaimed: boolean;
    q: string;
    all: boolean;
};

/** Loads the list again with these options. A new search or filter always starts on the first page. */
function visit(query: Query, then?: () => void): void {
    router.get(
        distributionList().url,
        {
            event: query.event ?? undefined,
            barangay: query.barangay ?? undefined,
            only_unclaimed: query.only_unclaimed ? 1 : undefined,
            q: query.q === '' ? undefined : query.q,
            all: query.all ? 1 : undefined,
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onSuccess: then,
        },
    );
}

export default function DistributionList({
    filters,
    notice,
    events,
    barangays,
    event,
    barangay,
    list,
    generated,
}: Props) {
    const [search, setSearch] = useState(filters.q);
    const people = list?.households.data ?? [];
    const hasPeople = people.length > 0;
    const pages = list?.households;

    function apply(next: Partial<Query>) {
        visit({ ...filters, ...next });
    }

    // Typing searches by itself after a short pause, so the encoder does not need to press a button.
    useEffect(() => {
        setSearch(filters.q);
    }, [filters.q]);

    useEffect(() => {
        if (search === filters.q) {
            return;
        }

        const timer = window.setTimeout(
            () => visit({ ...filters, q: search, all: false }),
            350,
        );

        return () => window.clearTimeout(timer);
    }, [search, filters]);

    // Prints the whole barangay: loads the full list, then opens the print window.
    function printAll() {
        visit({ ...filters, q: '', all: true }, () =>
            window.setTimeout(() => window.print(), 600),
        );
    }

    const csvUrl = distributionListCsv({
        query: {
            event: filters.event ?? undefined,
            barangay: filters.barangay ?? undefined,
            only_unclaimed: filters.only_unclaimed ? 1 : undefined,
        },
    }).url;
    return (
        <>
            <Head title="Distribution List" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-3 print:hidden">
                    <div>
                        <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                            <ListChecks className="size-8 text-blue-600" />
                            Distribution List
                        </h1>
                        <p className="mt-1 text-lg text-muted-foreground">
                            Print who can claim in a barangay, with a column to
                            sign, to use at the distribution table.
                        </p>
                    </div>
                    {hasPeople && (
                        <div className="flex flex-wrap gap-2">
                            <Button asChild variant="outline">
                                <a href={csvUrl}>
                                    <Download />
                                    Download CSV
                                </a>
                            </Button>
                            {filters.all ? (
                                <Button
                                    variant="outline"
                                    onClick={() => apply({ all: false })}
                                >
                                    <Undo2 />
                                    Back to pages
                                </Button>
                            ) : (
                                <Button variant="outline" onClick={printAll}>
                                    <Printer />
                                    Print all
                                </Button>
                            )}
                            <Button onClick={() => window.print()}>
                                <Printer />
                                {filters.all ? 'Print' : 'Print this page'}
                            </Button>
                        </div>
                    )}
                </div>

                {notice && (
                    <p
                        role="status"
                        className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-lg text-amber-950 print:hidden"
                    >
                        {notice}
                    </p>
                )}

                {filters.all && pages && (
                    <p
                        role="status"
                        className="rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-lg dark:border-sidebar-border dark:bg-sidebar print:hidden"
                    >
                        This is the whole list: {pages.total}{' '}
                        {pages.total === 1 ? 'household' : 'households'} on one
                        sheet, ready to print. Search and pages are turned off
                        until you go back.
                    </p>
                )}

                {events.length === 0 ? (
                    <p className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-lg text-amber-950 print:hidden">
                        No relief event has been started yet, so there is
                        nothing to list.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 print:hidden">
                        {!filters.all && (
                            <div className="min-w-0 sm:col-span-2">
                                <Field
                                    id="list-search"
                                    label="Find a person or household"
                                    hint="Search by name, resident ID, household code or street. It searches as you type."
                                >
                                    <div className="relative">
                                        <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" />
                                        <Input
                                            id="list-search"
                                            type="search"
                                            autoComplete="off"
                                            className="pl-10"
                                            placeholder="Name, ID, household or street"
                                            value={search}
                                            onChange={(inputEvent) =>
                                                setSearch(
                                                    inputEvent.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </Field>
                            </div>
                        )}
                        <div className="min-w-0 sm:col-span-2">
                            <Field id="list-event" label="Relief event">
                                <ChoiceSelect
                                    id="list-event"
                                    className="w-full min-w-0"
                                    value={filters.event?.toString() ?? ''}
                                    placeholder="Choose an event"
                                    values={events.map((candidate) => ({
                                        value: candidate.id.toString(),
                                        label: `${candidate.title} · ${candidate.municipality_name ?? 'No municipality'} (${candidate.status})`,
                                    }))}
                                    onChange={(value) =>
                                        apply({
                                            event: Number(value),
                                            barangay: null,
                                        })
                                    }
                                />
                            </Field>
                        </div>
                        <div className="min-w-0">
                            <Field id="list-barangay" label="Barangay">
                                <ChoiceSelect
                                    id="list-barangay"
                                    className="w-full min-w-0"
                                    value={filters.barangay?.toString() ?? ''}
                                    placeholder="Choose a barangay"
                                    values={barangays.map((candidate) => ({
                                        value: candidate.id.toString(),
                                        label: candidate.name,
                                    }))}
                                    onChange={(value) =>
                                        apply({ barangay: Number(value) })
                                    }
                                />
                            </Field>
                        </div>
                        <label className="flex min-h-12 min-w-0 items-center gap-3 self-end rounded-lg border-2 border-slate-200 px-3 text-base dark:border-border">
                            <Checkbox
                                id="only-unclaimed"
                                checked={filters.only_unclaimed}
                                onCheckedChange={(checked) =>
                                    apply({ only_unclaimed: checked === true })
                                }
                            />
                            Only people who have not claimed yet
                        </label>
                    </div>
                )}

                {list && (
                    <div className="grid grid-cols-2 gap-4 lg:grid-cols-4 print:hidden">
                        <Stat
                            label="Households"
                            value={list.totals.households}
                            tone="text-blue-700 dark:text-blue-300"
                        />
                        <Stat label="People" value={list.totals.members} />
                        <Stat
                            label="Already claimed"
                            value={list.totals.claimed}
                            tone="text-emerald-700 dark:text-emerald-400"
                        />
                        <Stat
                            label="Still to claim"
                            value={list.totals.members - list.totals.claimed}
                            tone="text-amber-700 dark:text-amber-300"
                        />
                    </div>
                )}

                {event && barangay && (
                    <Card
                        id="print-list"
                        className="print:border-0 print:shadow-none"
                    >
                        <CardHeader>
                            <CardTitle className="text-2xl">
                                {event.title}
                            </CardTitle>
                            <CardDescription className="grid gap-1 text-base">
                                <span className="flex flex-wrap items-center gap-x-4 gap-y-1">
                                    <span className="flex items-center gap-1.5">
                                        <MapPin className="size-4" />
                                        Barangay {barangay}
                                        {event.municipality_name
                                            ? `, ${event.municipality_name}`
                                            : ''}
                                    </span>
                                    <span className="flex items-center gap-1.5">
                                        <CalendarDays className="size-4" />
                                        {formatDate(event.start_date)} to{' '}
                                        {formatDate(event.end_date)}
                                    </span>
                                </span>
                                <span>{shareRule(event)}</span>
                                <span className="text-base">
                                    Printed {generated.at} by {generated.by}
                                    {filters.only_unclaimed
                                        ? ' · only people who have not claimed'
                                        : ''}
                                    {filters.q !== ''
                                        ? ` · matching "${filters.q}"`
                                        : ''}
                                    {pages && filters.all
                                        ? ` · full list, ${pages.total} households`
                                        : ''}
                                    {pages &&
                                    !filters.all &&
                                    pages.last_page > 1
                                        ? ` · page ${pages.current_page} of ${pages.last_page}`
                                        : ''}
                                </span>
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {pages && !filters.all && (
                                <p className="text-base text-muted-foreground print:hidden">
                                    {filters.q !== '' || filters.only_unclaimed
                                        ? `${pages.total} ${pages.total === 1 ? 'household matches' : 'households match'}.`
                                        : `${pages.total} ${pages.total === 1 ? 'household' : 'households'}.`}
                                    {pages.last_page > 1
                                        ? ` Showing page ${pages.current_page} of ${pages.last_page}.`
                                        : ''}
                                </p>
                            )}

                            {list && people.length === 0 && (
                                <p className="rounded-xl border-2 border-slate-200 p-6 text-lg dark:border-border">
                                    {filters.q !== ''
                                        ? `No household matches "${filters.q}".`
                                        : filters.only_unclaimed
                                          ? 'Everyone in this barangay has already claimed.'
                                          : 'No approved residents in this barangay yet.'}
                                </p>
                            )}

                            {people.map((household) => (
                                <section
                                    key={household.code}
                                    className="break-inside-avoid overflow-hidden rounded-xl border-2 border-blue-200 dark:border-sidebar-border"
                                >
                                    <div
                                        data-print-shade
                                        className="flex flex-wrap items-center gap-x-3 gap-y-1 bg-sky-50 px-4 py-2 dark:bg-sidebar"
                                    >
                                        <Home className="size-5 text-blue-600" />
                                        <span className="text-lg font-bold">
                                            {household.code}
                                        </span>
                                        <span className="text-base">
                                            {household.street_address}
                                        </span>
                                        {household.head_name && (
                                            <span className="text-base text-muted-foreground">
                                                Head: {household.head_name}
                                            </span>
                                        )}
                                        {household.limit_reached && (
                                            <span className="rounded-md bg-amber-500 px-2 py-0.5 text-base font-bold text-black">
                                                Household limit reached
                                            </span>
                                        )}
                                    </div>
                                    <ul className="lg:hidden print:hidden">
                                        {household.members.map((member) => (
                                            <li
                                                key={member.system_id_no}
                                                className="grid gap-0.5 border-b border-slate-100 px-4 py-3 last:border-b-0 dark:border-border"
                                            >
                                                <div className="flex items-center justify-between gap-3">
                                                    <p className="min-w-0 font-semibold">
                                                        {member.name}
                                                    </p>
                                                    <ClaimStatus
                                                        member={member}
                                                        limitReached={
                                                            household.limit_reached
                                                        }
                                                        showDetail={false}
                                                        className="shrink-0"
                                                    />
                                                </div>
                                                <p className="font-mono text-base text-muted-foreground">
                                                    {member.system_id_no} ·{' '}
                                                    {member.age} yrs
                                                </p>
                                                {claimDetail(member) && (
                                                    <p className="text-base text-muted-foreground">
                                                        Claimed{' '}
                                                        {claimDetail(member)}
                                                    </p>
                                                )}
                                            </li>
                                        ))}
                                    </ul>
                                    <table className="hidden w-full table-fixed text-left text-base lg:table print:table">
                                        <colgroup>
                                            <col className="w-40" />
                                            <col />
                                            <col className="w-14" />
                                            <col className="w-44" />
                                            <col className="w-48" />
                                        </colgroup>
                                        <thead>
                                            <tr className="border-b-2 border-slate-200 text-base text-muted-foreground dark:border-border">
                                                <th className="px-4 py-2 font-semibold">
                                                    Resident ID
                                                </th>
                                                <th className="px-2 py-2 font-semibold">
                                                    Name
                                                </th>
                                                <th className="px-2 py-2 font-semibold">
                                                    Age
                                                </th>
                                                <th className="px-2 py-2 font-semibold">
                                                    Claimed
                                                </th>
                                                <th className="px-4 py-2 font-semibold">
                                                    Signature
                                                </th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {household.members.map((member) => (
                                                <tr
                                                    key={member.system_id_no}
                                                    className="border-b border-slate-100 last:border-b-0 dark:border-border"
                                                >
                                                    <td className="px-4 py-2 font-mono text-base">
                                                        {member.system_id_no}
                                                    </td>
                                                    <td className="px-2 py-2 font-semibold">
                                                        {member.name}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {member.age}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        <ClaimStatus
                                                            member={member}
                                                            limitReached={
                                                                household.limit_reached
                                                            }
                                                        />
                                                    </td>
                                                    <td className="px-4 py-2">
                                                        <div className="h-7 border-b border-slate-400" />
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </section>
                            ))}
                        </CardContent>
                    </Card>
                )}

                {pages && !filters.all && pages.last_page > 1 && (
                    <nav
                        className="flex items-center justify-between gap-3 print:hidden"
                        aria-label="Pagination"
                    >
                        {pages.prev_page_url ? (
                            <Button asChild variant="outline">
                                <Link href={pages.prev_page_url} preserveScroll>
                                    <ChevronLeft />
                                    Previous
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                        <span className="text-base font-medium">
                            Page {pages.current_page} of {pages.last_page}
                        </span>
                        {pages.next_page_url ? (
                            <Button asChild variant="outline">
                                <Link href={pages.next_page_url} preserveScroll>
                                    Next
                                    <ChevronRight />
                                </Link>
                            </Button>
                        ) : (
                            <span />
                        )}
                    </nav>
                )}

                {hasPeople && (
                    <p className="text-base text-muted-foreground print:hidden">
                        <strong>Print this page</strong> prints what you see.{' '}
                        <strong>Print all</strong> prints every household in the
                        barangay on one sheet, without the search. To get a PDF
                        file, choose &quot;Save as PDF&quot; as the printer in
                        the print window. The CSV always holds the whole list,
                        to sort or edit in Excel.
                    </p>
                )}
            </div>
        </>
    );
}

DistributionList.layout = {
    breadcrumbs: [{ title: 'Distribution List', href: distributionList() }],
};
