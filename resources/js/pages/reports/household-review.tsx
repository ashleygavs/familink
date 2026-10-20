import { Head, Link, router, useForm } from '@inertiajs/react';
import { CheckCircle2, Network, SplitSquareHorizontal } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';
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
import { destroy, store } from '@/routes/admin/household-reviews';
import { householdReview } from '@/routes/reports';

type Side = {
    id: number;
    household_code: string;
    barangay_name: string | null;
    head_name: string | null;
    members: number;
    can_open: boolean;
    claims: number | null;
};

type Review = {
    id: number;
    reason: string;
    reviewed_by: string | null;
    reviewed_on: string;
};

type SplitCase = {
    key: string;
    households: [Side, Side];
    reasons: string[];
    both_claimed: boolean | null;
    review: Review | null;
};

type Props = {
    filters: { event: number | null; reviewed: boolean };
    events: {
        id: number;
        title: string;
        municipality_name: string | null;
        status: string;
    }[];
    counts: { open: number; reviewed: number };
    cases: SplitCase[];
    canReview: boolean;
};

function HouseholdBlock({ side }: { side: Side }) {
    return (
        <div className="rounded-xl border-2 border-blue-100 bg-white p-4 dark:border-border dark:bg-card">
            <p className="text-xl font-bold text-blue-900 dark:text-white">
                {side.household_code}
            </p>
            <p className="text-base text-muted-foreground">
                {side.barangay_name ?? 'No barangay'} · {side.members}{' '}
                {side.members === 1 ? 'person' : 'people'}
            </p>
            <p className="mt-1 text-lg">
                {side.head_name ?? (
                    <span className="text-muted-foreground">
                        {side.can_open ? 'No head yet' : 'Another barangay'}
                    </span>
                )}
            </p>
            {side.claims !== null && (
                <p
                    className={
                        side.claims > 0
                            ? 'mt-2 text-base font-semibold text-red-700 dark:text-red-400'
                            : 'mt-2 text-base text-muted-foreground'
                    }
                >
                    {side.claims > 0
                        ? `Claimed ${side.claims} time${side.claims === 1 ? '' : 's'} in this event`
                        : 'No claim in this event'}
                </p>
            )}
            {side.can_open && (
                <Link
                    href={familyTree(side.id)}
                    className="mt-3 inline-flex min-h-11 items-center gap-2 text-lg font-semibold text-blue-700 underline-offset-4 hover:underline dark:text-sky-300"
                >
                    <Network className="size-5" />
                    Family tree
                </Link>
            )}
        </div>
    );
}

function ReviewForm({
    splitCase,
    onDone,
}: {
    splitCase: SplitCase;
    onDone: () => void;
}) {
    const form = useForm({
        first_household_id: splitCase.households[0].id,
        second_household_id: splitCase.households[1].id,
        reason: '',
    });

    function submit(event: FormEvent) {
        event.preventDefault();
        form.post(store.url(), { preserveScroll: true, onSuccess: onDone });
    }

    return (
        <form onSubmit={submit} className="grid gap-3">
            <Field
                id={`reason-${splitCase.key}`}
                label="Why are these two households fine as they are?"
                hint="For example: visited both homes, the couple work in different towns."
                error={form.errors.reason}
            >
                <Input
                    id={`reason-${splitCase.key}`}
                    value={form.data.reason}
                    maxLength={500}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                />
            </Field>
            <div className="flex flex-wrap gap-2">
                <Button
                    type="submit"
                    className="min-h-12 text-lg"
                    disabled={form.processing}
                >
                    <CheckCircle2 className="size-5" />
                    Save as reviewed
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="min-h-12 text-lg"
                    onClick={onDone}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}

function CaseCard({
    splitCase,
    canReview,
}: {
    splitCase: SplitCase;
    canReview: boolean;
}) {
    const [reviewing, setReviewing] = useState(false);
    const { review } = splitCase;

    return (
        <Card
            className={
                splitCase.both_claimed
                    ? 'border-2 border-red-300 dark:border-red-800'
                    : undefined
            }
        >
            <CardHeader>
                <CardTitle className="text-xl">
                    {splitCase.households[0].household_code} and{' '}
                    {splitCase.households[1].household_code}
                </CardTitle>
                {splitCase.both_claimed && (
                    <CardDescription className="text-base font-semibold text-red-700 dark:text-red-400">
                        Both households claimed in this event.
                    </CardDescription>
                )}
            </CardHeader>
            <CardContent className="grid gap-4">
                <ul className="grid list-disc gap-1 pl-6 text-lg">
                    {splitCase.reasons.map((reason) => (
                        <li key={reason}>{reason}</li>
                    ))}
                </ul>
                <div className="grid gap-3 md:grid-cols-2">
                    <HouseholdBlock side={splitCase.households[0]} />
                    <HouseholdBlock side={splitCase.households[1]} />
                </div>

                {review !== null && (
                    <div className="rounded-xl border-2 border-emerald-200 bg-emerald-50 p-4 text-lg text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                        <p className="font-semibold">
                            Reviewed
                            {review.reviewed_by
                                ? ` by ${review.reviewed_by}`
                                : ''}{' '}
                            on {formatDate(review.reviewed_on)}
                        </p>
                        <p>{review.reason}</p>
                    </div>
                )}

                {canReview && review === null && !reviewing && (
                    <div>
                        <Button
                            variant="outline"
                            className="min-h-12 text-lg"
                            onClick={() => setReviewing(true)}
                        >
                            <CheckCircle2 className="size-5" />
                            Mark as reviewed
                        </Button>
                    </div>
                )}
                {canReview && review === null && reviewing && (
                    <ReviewForm
                        splitCase={splitCase}
                        onDone={() => setReviewing(false)}
                    />
                )}
                {canReview && review !== null && (
                    <div>
                        <Button
                            variant="outline"
                            className="min-h-12 text-lg"
                            onClick={() =>
                                router.delete(destroy.url(review.id), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            Put back on the list
                        </Button>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function HouseholdReview({
    filters,
    events,
    counts,
    cases,
    canReview,
}: Props) {
    function apply(next: Partial<{ event: number; reviewed: boolean }>) {
        const merged = { ...filters, ...next };

        router.get(
            householdReview().url,
            {
                event: merged.event ?? undefined,
                reviewed: merged.reviewed ? 1 : undefined,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    }

    return (
        <>
            <Head title="Household Review" />
            <div className="mx-auto flex w-full max-w-5xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <SplitSquareHorizontal className="size-8 text-blue-600" />
                        Household Review
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Households whose members are family, so one family may
                        be claiming relief twice. This only points them out. You
                        decide whether it is a real split.
                    </p>
                </div>

                {events.length > 0 && (
                    <div className="max-w-xl">
                        <Field
                            id="review-event"
                            label="Show who claimed in this event"
                        >
                            <ChoiceSelect
                                id="review-event"
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
                    </div>
                )}

                <div className="flex flex-wrap gap-2">
                    <Button
                        variant={filters.reviewed ? 'outline' : 'default'}
                        className="min-h-12 text-lg"
                        onClick={() => apply({ reviewed: false })}
                    >
                        To check ({counts.open})
                    </Button>
                    <Button
                        variant={filters.reviewed ? 'default' : 'outline'}
                        className="min-h-12 text-lg"
                        onClick={() => apply({ reviewed: true })}
                    >
                        Reviewed ({counts.reviewed})
                    </Button>
                </div>

                {cases.length === 0 ? (
                    <p className="rounded-xl border-2 border-emerald-200 bg-emerald-50 p-4 text-lg text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                        {filters.reviewed
                            ? 'No pair has been reviewed yet.'
                            : 'Nothing to check. No married couple or young child is recorded in two different households.'}
                    </p>
                ) : (
                    <div className="grid gap-4">
                        {cases.map((splitCase) => (
                            <CaseCard
                                key={splitCase.key}
                                splitCase={splitCase}
                                canReview={canReview}
                            />
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

HouseholdReview.layout = {
    breadcrumbs: [
        {
            title: 'Household Review',
            href: householdReview(),
        },
    ],
};
