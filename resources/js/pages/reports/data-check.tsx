import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, Network, ShieldAlert, UserRound } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { familyTree } from '@/routes';
import { show } from '@/routes/admin/residents';
import { dataCheck } from '@/routes/reports';

type Problem = {
    resident_id: number;
    resident_name: string;
    household_id: number | null;
    household_code: string | null;
    barangay_name: string | null;
    can_open: boolean;
    text: string;
};

type Group = {
    type: string;
    title: string;
    explanation: string;
    total: number;
    problems: Problem[];
};

type Props = {
    scanned: number;
    total: number;
    groups: Group[];
    canFix: boolean;
};

export default function DataCheck({ scanned, total, groups, canFix }: Props) {
    return (
        <>
            <Head title="Data Check" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <ShieldAlert className="size-8 text-blue-600" />
                        Data Check
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Family records that cannot be right, such as a mother
                        born after her child. Wrong links make the family tree,
                        the relationship labels and the household review wrong
                        too.
                        {canFix
                            ? ' Open the person to correct the record.'
                            : ' Admins correct the records.'}
                    </p>
                </div>

                {total === 0 ? (
                    <p className="flex items-center gap-3 rounded-xl border-2 border-emerald-200 bg-emerald-50 p-4 text-lg text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                        <CheckCircle2 className="size-6 shrink-0" />
                        No problems found in {scanned.toLocaleString()}{' '}
                        {scanned === 1 ? 'record' : 'records'}.
                    </p>
                ) : (
                    <p className="rounded-xl border-2 border-amber-300 bg-amber-50 p-4 text-lg text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                        <strong>
                            {total} {total === 1 ? 'problem' : 'problems'}
                        </strong>{' '}
                        found in {scanned.toLocaleString()}{' '}
                        {scanned === 1 ? 'record' : 'records'}.
                    </p>
                )}

                {groups.map((group) => (
                    <Card key={group.type}>
                        <CardHeader>
                            <CardTitle className="text-xl">
                                {group.title} ({group.total})
                            </CardTitle>
                            <CardDescription className="text-base">
                                {group.explanation}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-3">
                            {group.problems.map((problem) => (
                                <div
                                    key={`${group.type}-${problem.resident_id}-${problem.text}`}
                                    className="grid gap-2 rounded-xl border-2 border-blue-100 p-4 dark:border-border"
                                >
                                    <p className="text-lg">{problem.text}</p>
                                    {problem.household_code && (
                                        <p className="text-base text-muted-foreground">
                                            {problem.household_code}
                                            {problem.barangay_name
                                                ? ` · ${problem.barangay_name}`
                                                : ''}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {canFix && problem.can_open && (
                                            <Button
                                                asChild
                                                variant="outline"
                                                className="min-h-12 text-lg"
                                            >
                                                <Link
                                                    href={show(
                                                        problem.resident_id,
                                                    )}
                                                >
                                                    <UserRound className="size-5" />
                                                    Open {problem.resident_name}
                                                </Link>
                                            </Button>
                                        )}
                                        {problem.household_id !== null &&
                                            problem.can_open && (
                                                <Button
                                                    asChild
                                                    variant="outline"
                                                    className="min-h-12 text-lg"
                                                >
                                                    <Link
                                                        href={familyTree(
                                                            problem.household_id,
                                                        )}
                                                    >
                                                        <Network className="size-5" />
                                                        Family tree
                                                    </Link>
                                                </Button>
                                            )}
                                    </div>
                                </div>
                            ))}
                            {group.total > group.problems.length && (
                                <p className="text-base text-muted-foreground">
                                    Showing the first {group.problems.length} of{' '}
                                    {group.total}. Fix some and reload to see
                                    the rest.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

DataCheck.layout = {
    breadcrumbs: [
        {
            title: 'Data Check',
            href: dataCheck(),
        },
    ],
};
