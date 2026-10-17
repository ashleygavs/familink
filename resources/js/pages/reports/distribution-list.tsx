import { Head, router } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    Download,
    Home,
    ListChecks,
    MapPin,
    Printer,
} from 'lucide-react';
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
import { formatDate } from '@/lib/format';
import { distributionList } from '@/routes/reports';
import { csv as distributionListCsv } from '@/routes/reports/distribution-list';

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
    };
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
        households: HouseholdEntry[];
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

function shareRule(event: NonNullable<Props['event']>): string {
    if (event.allocation_type === 'Per Household') {
        return 'One share for each household.';
    }

    return event.limit === null
        ? 'One share for each person.'
        : `One share for each person, up to ${event.limit} per household.`;
}

export default function DistributionList({
    filters,
    events,
    barangays,
    event,
    barangay,
    list,
    generated,
}: Props) {
    function apply(
        next: Partial<{
            event: number | null;
            barangay: number | null;
            only_unclaimed: boolean;
        }>,
    ) {
        const merged = { ...filters, ...next };

        router.get(
            distributionList().url,
            {
                event: merged.event ?? undefined,
                barangay: merged.barangay ?? undefined,
                only_unclaimed: merged.only_unclaimed ? 1 : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    const csvUrl = distributionListCsv({
        query: {
            event: filters.event ?? undefined,
            barangay: filters.barangay ?? undefined,
            only_unclaimed: filters.only_unclaimed ? 1 : undefined,
        },
    }).url;
    const hasPeople = list !== null && list.households.length > 0;

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
                            <Button onClick={() => window.print()}>
                                <Printer />
                                Print / Save as PDF
                            </Button>
                        </div>
                    )}
                </div>

                {events.length === 0 ? (
                    <p className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-lg text-amber-950 print:hidden">
                        No relief event has been started yet, so there is
                        nothing to list.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 print:hidden">
                        <div className="sm:col-span-2">
                            <Field id="list-event" label="Relief event">
                                <ChoiceSelect
                                    id="list-event"
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
                        <Field id="list-barangay" label="Barangay">
                            <ChoiceSelect
                                id="list-barangay"
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
                        <label className="flex min-h-12 items-center gap-3 self-end rounded-lg border-2 border-slate-200 px-3 text-base dark:border-border">
                            <Checkbox
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
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4 print:hidden">
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
                                <span className="text-sm">
                                    Printed {generated.at} by {generated.by}
                                    {filters.only_unclaimed
                                        ? ' · only people who have not claimed'
                                        : ''}
                                </span>
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4">
                            {list && list.households.length === 0 && (
                                <p className="rounded-xl border-2 border-slate-200 p-6 text-lg dark:border-border">
                                    {filters.only_unclaimed
                                        ? 'Everyone in this barangay has already claimed.'
                                        : 'No approved residents in this barangay yet.'}
                                </p>
                            )}

                            {list?.households.map((household) => (
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
                                            <span className="rounded-md bg-amber-500 px-2 py-0.5 text-sm font-bold text-black">
                                                Household limit reached
                                            </span>
                                        )}
                                    </div>
                                    <table className="w-full table-fixed text-left text-base">
                                        <colgroup>
                                            <col className="w-40" />
                                            <col />
                                            <col className="w-14" />
                                            <col className="w-36" />
                                            <col className="hidden w-48 sm:table-column" />
                                        </colgroup>
                                        <thead>
                                            <tr className="border-b-2 border-slate-200 text-sm text-muted-foreground dark:border-border">
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
                                                <th className="hidden w-48 px-4 py-2 font-semibold sm:table-cell">
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
                                                    <td className="px-4 py-2 font-mono text-sm">
                                                        {member.system_id_no}
                                                    </td>
                                                    <td className="px-2 py-2 font-semibold">
                                                        {member.name}
                                                    </td>
                                                    <td className="px-2 py-2">
                                                        {member.age}
                                                    </td>
                                                    <td className="px-2 py-2 text-sm">
                                                        {member.claimed ? (
                                                            <span className="grid">
                                                                <span className="flex items-center gap-1 font-semibold text-emerald-700 dark:text-emerald-400">
                                                                    <CheckCircle2 className="size-4" />
                                                                    Yes
                                                                </span>
                                                                {member.claimed_at && (
                                                                    <span className="text-xs text-muted-foreground">
                                                                        {
                                                                            member.claimed_at
                                                                        }
                                                                        {member.collected_by
                                                                            ? ` · by ${member.collected_by}`
                                                                            : ''}
                                                                    </span>
                                                                )}
                                                            </span>
                                                        ) : household.limit_reached ? (
                                                            <span className="font-semibold text-amber-700 dark:text-amber-300">
                                                                Limit reached
                                                            </span>
                                                        ) : null}
                                                    </td>
                                                    <td className="hidden px-4 py-2 sm:table-cell">
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

                {hasPeople && (
                    <p className="text-base text-muted-foreground print:hidden">
                        To get a PDF file, choose &quot;Save as PDF&quot; as the
                        printer in the print window. Use the CSV to sort or edit
                        the list in Excel.
                    </p>
                )}
            </div>
        </>
    );
}

DistributionList.layout = {
    breadcrumbs: [{ title: 'Distribution List', href: distributionList() }],
};
