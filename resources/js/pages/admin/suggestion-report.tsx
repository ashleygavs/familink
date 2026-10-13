import { Head } from '@inertiajs/react';
import { Lightbulb } from 'lucide-react';
import type { ReactNode } from 'react';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { suggestionReport } from '@/routes/admin';

type Row = {
    key: string;
    label: string;
    accepted: number;
    rejected: number;
    unsure: number;
    reclassified: number;
    total: number;
    rate: number | null;
};

type Props = {
    totals: {
        accepted: number;
        rejected: number;
        unsure: number;
        reclassified: number;
        all: number;
    };
    byRole: Row[];
    byConfidence: Row[];
    byReason: Row[];
    reclassified: { from: string; to: string; count: number }[];
};

function RateBar({ rate }: { rate: number | null }) {
    if (rate === null) {
        return <span className="text-muted-foreground">-</span>;
    }

    return (
        <div className="flex items-center gap-2">
            <div
                className="h-3 w-24 overflow-hidden rounded-full bg-slate-200 dark:bg-muted"
                role="img"
                aria-label={`${rate} percent confirmed`}
            >
                <div
                    className="h-full rounded-full bg-emerald-600"
                    style={{ width: `${rate}%` }}
                />
            </div>
            <span className="text-base font-bold">{rate}%</span>
        </div>
    );
}

function Table({
    title,
    description,
    firstColumn,
    rows,
}: {
    title: string;
    description: string;
    firstColumn: string;
    rows: Row[];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-2xl">{title}</CardTitle>
                <CardDescription className="text-base">
                    {description}
                </CardDescription>
            </CardHeader>
            <CardContent className="overflow-x-auto">
                {rows.length === 0 ? (
                    <p className="text-base text-muted-foreground">
                        No answers recorded yet.
                    </p>
                ) : (
                    <table className="w-full text-left text-base">
                        <thead>
                            <tr className="border-b-2 border-slate-200 dark:border-border">
                                <th className="py-2 pr-4 font-bold">
                                    {firstColumn}
                                </th>
                                <th className="px-2 py-2 font-bold">Yes</th>
                                <th className="px-2 py-2 font-bold">No</th>
                                <th className="px-2 py-2 font-bold">
                                    Another way
                                </th>
                                <th className="px-2 py-2 font-bold">
                                    Not sure
                                </th>
                                <th className="py-2 pl-2 font-bold">
                                    Confirmed
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((row) => (
                                <tr
                                    key={row.key}
                                    className="border-b border-slate-100 dark:border-border"
                                >
                                    <td className="py-2 pr-4 font-semibold">
                                        {row.label}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.accepted}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.rejected}
                                    </td>
                                    <td className="px-2 py-2">
                                        {row.reclassified}
                                    </td>
                                    <td className="px-2 py-2">{row.unsure}</td>
                                    <td className="py-2 pl-2">
                                        <RateBar rate={row.rate} />
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </CardContent>
        </Card>
    );
}

function Stat({
    label,
    value,
    tone,
}: {
    label: string;
    value: ReactNode;
    tone: string;
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

export default function SuggestionReport({
    totals,
    byRole,
    byConfidence,
    byReason,
    reclassified,
}: Props) {
    return (
        <>
            <Head title="Suggestion Report" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <Lightbulb className="size-8 text-blue-600" />
                        Suggestion Report
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        How often the relatives the system suggests are
                        confirmed. "Confirmed" is the share of Yes answers among
                        Yes, No and "Another way" answers. Use it to see which
                        kinds of suggestion and which reasons can be trusted.
                    </p>
                </div>

                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Stat
                        label="Said yes"
                        value={totals.accepted}
                        tone="text-emerald-700 dark:text-emerald-400"
                    />
                    <Stat
                        label="Said no"
                        value={totals.rejected}
                        tone="text-red-700 dark:text-red-400"
                    />
                    <Stat
                        label="Related another way"
                        value={totals.reclassified}
                        tone="text-blue-700 dark:text-blue-400"
                    />
                    <Stat
                        label="Not sure"
                        value={totals.unsure}
                        tone="text-slate-700 dark:text-slate-300"
                    />
                </div>

                <Table
                    title="By kind of suggestion"
                    description="Father, mother, spouse, children and each kind of relative."
                    firstColumn="Suggested as"
                    rows={byRole}
                />
                <Table
                    title="By confidence"
                    description="Strong matches should be confirmed far more often than possible ones."
                    firstColumn="Confidence"
                    rows={byConfidence}
                />
                <Table
                    title="By reason"
                    description="The reasons shown on the cards. A reason that is often rejected deserves less weight."
                    firstColumn="Reason"
                    rows={byReason}
                />

                <Card>
                    <CardHeader>
                        <CardTitle className="text-2xl">
                            Moved to another kind of relative
                        </CardTitle>
                        <CardDescription className="text-base">
                            People rejected for one role but related another
                            way, for example suggested as a father and actually
                            an uncle.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {reclassified.length === 0 ? (
                            <p className="text-base text-muted-foreground">
                                None yet.
                            </p>
                        ) : (
                            <ul className="grid gap-2 text-base">
                                {reclassified.map((move) => (
                                    <li key={`${move.from}-${move.to}`}>
                                        <strong>{move.count}</strong> ×
                                        suggested as {move.from}, actually{' '}
                                        {move.to}
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

SuggestionReport.layout = {
    breadcrumbs: [
        {
            title: 'Suggestion Report',
            href: suggestionReport(),
        },
    ],
};
