import { router } from '@inertiajs/react';
import {
    AlertCircle,
    ArrowLeft,
    ArrowRight,
    RotateCcw,
    UserPlus,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import ResidentController from '@/actions/App/Http/Controllers/ResidentController';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import {
    suggestionUrl,
    useFamilySuggestions,
} from '@/hooks/use-family-suggestions';
import { useRegistrationDraft } from '@/hooks/use-registration-draft';
import {
    emptyDraft,
    STEP_FAMILY,
    STEP_HOUSEHOLD,
    STEP_PERSON,
    STEP_REVIEW,
    STEPS,
} from '@/lib/registration-draft';
import {
    buildPayload,
    stepForErrorKey,
    validateStep,
} from '@/lib/registration-validation';
import { StepFamilyExisting } from './step-family-existing';
import { StepFamilyNew } from './step-family-new';
import { StepHousehold } from './step-household';
import { StepIndicator } from './step-indicator';
import { StepPath } from './step-path';
import { StepPerson } from './step-person';
import { StepReview } from './step-review';
import type { DraftUpdate, StepErrors } from './step-types';

type Props = {
    userId: number;
    barangays: { id: number; name: string }[];
    lockedBarangayId: number | null;
    options: {
        genders: string[];
        civilStatuses: string[];
        householdRoles: string[];
    };
    onSubmitted: () => void;
};

const STEP_INTRO = [
    {
        title: 'How are you joining FamiLink?',
        description: 'Choose the option that fits your family.',
    },
    {
        title: 'Tell us about the person being registered',
        description:
            "Names as written on the resident's ID or birth certificate.",
    },
    {
        title: 'Family',
        description: 'Link or list the people this resident is related to.',
    },
    {
        title: 'Household & address',
        description:
            'Where this person lives. Being the household head is your choice, never automatic.',
    },
    {
        title: 'Review and save',
        description: 'Check the details one last time.',
    },
];

/**
 * Step-by-step resident registration. Progress is remembered in the browser
 * tab, so a reload returns to the same step with everything still filled in.
 */
export function RegistrationWizard({
    userId,
    barangays,
    lockedBarangayId,
    options,
    onSubmitted,
}: Props) {
    const { draft, update, discard } = useRegistrationDraft(
        userId,
        lockedBarangayId,
    );
    const [errors, setErrors] = useState<StepErrors>({});
    const [processing, setProcessing] = useState(false);
    const [confirmReset, setConfirmReset] = useState(false);
    const headingRef = useRef<HTMLHeadingElement>(null);

    const step = draft.step;
    const barangayId = draft.household.barangay_id
        ? Number(draft.household.barangay_id)
        : null;
    const barangayName =
        barangays.find((barangay) => barangay.id === barangayId)?.name ?? null;

    const suggestions = useFamilySuggestions(
        step === STEP_PERSON || step === STEP_FAMILY
            ? suggestionUrl(draft)
            : null,
    );

    useEffect(() => {
        headingRef.current?.focus();
        headingRef.current?.scrollIntoView({ block: 'start' });
    }, [step]);

    const clearErrors = (...keys: string[]) =>
        setErrors((current) => {
            const next = Object.fromEntries(
                Object.entries(current).filter(
                    ([key]) =>
                        !keys.some(
                            (prefix) =>
                                key === prefix || key.startsWith(`${prefix}.`),
                        ),
                ),
            );

            return Object.keys(next).length === Object.keys(current).length
                ? current
                : next;
        });

    const goTo = (target: number) =>
        update((current) => ({
            step: target,
            maxStep: Math.max(current.maxStep, target),
        }));

    function next() {
        const found = validateStep(step, draft);

        setErrors(found);

        if (Object.keys(found).length === 0) {
            goTo(step + 1);
        }
    }

    function back() {
        setErrors({});
        update({ step: Math.max(0, step - 1) });
    }

    function submit() {
        const found = validateStep(STEP_REVIEW, draft);

        setErrors(found);

        if (Object.keys(found).length > 0) {
            return;
        }

        router.post(ResidentController.store.url(), buildPayload(draft), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => setProcessing(false),
            onError: (serverErrors) => {
                setErrors(serverErrors);
                update({
                    step: Math.min(
                        ...Object.keys(serverErrors).map(stepForErrorKey),
                    ),
                });
            },
            onSuccess: () => {
                discard();
                onSubmitted();
            },
        });
    }

    function startOver() {
        discard();
        setErrors({});
        setConfirmReset(false);
        update(() => emptyDraft(lockedBarangayId));
    }

    const stepProps = {
        draft,
        update: update as DraftUpdate,
        errors,
        clearErrors,
    };
    const errorCount = Object.keys(errors).length;
    const intro = STEP_INTRO[step];
    const isLast = step === STEP_REVIEW;

    return (
        <div className="grid gap-6">
            <div className="grid gap-3">
                <StepIndicator
                    steps={STEPS}
                    current={step}
                    maxStep={draft.maxStep}
                    onSelect={(index) => {
                        setErrors({});
                        update({ step: index });
                    }}
                />
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    className="justify-self-end"
                    onClick={() => setConfirmReset(true)}
                >
                    <RotateCcw />
                    Start over
                </Button>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="text-2xl">
                        <h2
                            ref={headingRef}
                            tabIndex={-1}
                            className="outline-none"
                        >
                            {intro.title}
                        </h2>
                    </CardTitle>
                    <CardDescription className="text-base">
                        {intro.description}
                    </CardDescription>
                </CardHeader>
                <CardContent className="grid gap-5">
                    {errorCount > 0 && (
                        <div
                            role="alert"
                            className="flex items-start gap-3 rounded-xl border-2 border-red-300 bg-red-50 p-4 text-red-900 dark:border-red-700 dark:bg-red-950 dark:text-red-100"
                        >
                            <AlertCircle className="size-7 shrink-0" />
                            <p className="text-lg font-semibold">
                                Please check the {errorCount} highlighted{' '}
                                {errorCount === 1 ? 'item' : 'items'} and try
                                again.
                            </p>
                        </div>
                    )}

                    {step === 0 && <StepPath {...stepProps} />}
                    {step === STEP_PERSON && (
                        <StepPerson
                            {...stepProps}
                            suggestions={suggestions}
                            lockedBarangayId={lockedBarangayId}
                            options={options}
                        />
                    )}
                    {step === STEP_FAMILY &&
                        (draft.path === 'new' && !draft.claim ? (
                            <StepFamilyNew {...stepProps} />
                        ) : (
                            <StepFamilyExisting
                                {...stepProps}
                                suggestions={suggestions}
                                barangayId={barangayId}
                                onStartNew={() => {
                                    update({ path: 'new' });
                                    setErrors({});
                                }}
                            />
                        ))}
                    {step === STEP_HOUSEHOLD && (
                        <StepHousehold
                            {...stepProps}
                            barangays={barangays}
                            lockedBarangayId={lockedBarangayId}
                            householdRoles={options.householdRoles}
                        />
                    )}
                    {step === STEP_REVIEW && (
                        <StepReview
                            {...stepProps}
                            barangayName={barangayName}
                            onEdit={(target) => {
                                setErrors({});
                                update({ step: target });
                            }}
                        />
                    )}
                </CardContent>
            </Card>

            <div className="flex flex-wrap items-center justify-between gap-3">
                <Button
                    type="button"
                    variant="outline"
                    size="lg"
                    onClick={back}
                    disabled={step === 0 || processing}
                    className={step === 0 ? 'invisible' : ''}
                >
                    <ArrowLeft />
                    Back
                </Button>
                {isLast ? (
                    <Button
                        type="button"
                        size="lg"
                        onClick={submit}
                        disabled={processing}
                    >
                        {processing ? <Spinner /> : <UserPlus />}
                        Save Resident
                    </Button>
                ) : (
                    <Button type="button" size="lg" onClick={next}>
                        Next
                        <ArrowRight />
                    </Button>
                )}
            </div>

            <Dialog open={confirmReset} onOpenChange={setConfirmReset}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="text-2xl">
                            Start over?
                        </DialogTitle>
                        <DialogDescription className="text-base">
                            Everything you typed so far will be cleared.
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter className="gap-2">
                        <Button
                            variant="outline"
                            onClick={() => setConfirmReset(false)}
                        >
                            Keep my answers
                        </Button>
                        <Button variant="destructive" onClick={startOver}>
                            <RotateCcw />
                            Yes, start over
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
