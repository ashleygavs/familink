import { Calendar, CheckCircle2, HelpCircle, Loader2, X } from 'lucide-react';
import { ResidentSummary } from '@/components/parent-selector';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import type { ClaimedResident, HouseholdOption, PersonDraft } from '@/types';
import { asChoices, ChoiceSelect, Field } from './field';
import type { StepProps, SuggestionState } from './step-types';

type Props = StepProps & {
    suggestions: SuggestionState;
    lockedBarangayId: number | null;
    options: { genders: string[]; civilStatuses: string[] };
};

function relativesKnown(claim: ClaimedResident): string {
    const known = [
        claim.father_name && `father ${claim.father_name}`,
        claim.mother_name && `mother ${claim.mother_name}`,
        claim.spouse_name && `spouse ${claim.spouse_name}`,
        ...claim.children_names.map((name) => `child ${name}`),
    ].filter(Boolean);

    return known.length > 0 ? `Already linked to: ${known.join(', ')}.` : '';
}
export function StepPerson({
    draft,
    update,
    errors,
    clearErrors,
    suggestions,
    lockedBarangayId,
    options,
}: Props) {
    const { person } = draft;
    const today = new Date().toISOString().slice(0, 10);

    function set(patch: Partial<PersonDraft>) {
        update((current) => ({ person: { ...current.person, ...patch } }));
        clearErrors(...Object.keys(patch));
    }

    function chooseClaim(claim: ClaimedResident) {
        const livesInReach =
            claim.household &&
            (lockedBarangayId === null ||
                claim.household.barangay_id === lockedBarangayId);

        update((current) => ({
            claim,
            path: 'existing',
            household: livesInReach
                ? {
                      ...current.household,
                      barangay_id: String(claim.household?.barangay_id),
                      mode: 'existing',
                      household: claim.household as HouseholdOption,
                  }
                : current.household,
        }));
    }

    const claims = (suggestions.data?.claims ?? []).filter(
        (match) =>
            !draft.dismissedClaims.includes(match.resident.id) &&
            match.resident.id !== draft.claim?.id,
    );

    return (
        <div className="grid gap-6">
            <div className="grid gap-5 md:grid-cols-2">
                <Field
                    id="first_name"
                    label="First name"
                    error={errors.first_name}
                >
                    <Input
                        id="first_name"
                        value={person.first_name}
                        onChange={(e) => set({ first_name: e.target.value })}
                        placeholder="e.g. Maria"
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id="middle_name"
                    label="Middle name (optional)"
                    error={errors.middle_name}
                >
                    <Input
                        id="middle_name"
                        value={person.middle_name}
                        onChange={(e) => set({ middle_name: e.target.value })}
                        placeholder="e.g. Santos"
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id="last_name"
                    label="Last name"
                    error={errors.last_name}
                >
                    <Input
                        id="last_name"
                        value={person.last_name}
                        onChange={(e) => set({ last_name: e.target.value })}
                        placeholder="e.g. Dela Cruz"
                        autoComplete="off"
                    />
                </Field>
                <Field
                    id="birthdate"
                    label="Birthdate"
                    error={errors.birthdate}
                >
                    <div className="relative">
                        <Calendar className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-blue-600" />
                        <Input
                            id="birthdate"
                            type="date"
                            max={today}
                            value={person.birthdate}
                            onChange={(e) => set({ birthdate: e.target.value })}
                            className="pl-11"
                        />
                    </div>
                </Field>
                <Field id="gender" label="Gender" error={errors.gender}>
                    <ChoiceSelect
                        id="gender"
                        value={person.gender}
                        placeholder="Choose gender"
                        values={asChoices(options.genders)}
                        onChange={(value) =>
                            set({ gender: value as PersonDraft['gender'] })
                        }
                    />
                </Field>
                <Field
                    id="civil_status"
                    label="Civil status"
                    error={errors.civil_status}
                >
                    <ChoiceSelect
                        id="civil_status"
                        value={person.civil_status}
                        placeholder="Choose civil status"
                        values={asChoices(options.civilStatuses)}
                        onChange={(value) => set({ civil_status: value })}
                    />
                </Field>
                {person.gender === 'Female' && (
                    <Field
                        id="maiden_name"
                        label="Maiden last name (optional)"
                        hint="Your last name before marriage. It helps us find your children."
                        error={errors.maiden_name}
                    >
                        <Input
                            id="maiden_name"
                            value={person.maiden_name}
                            onChange={(e) =>
                                set({ maiden_name: e.target.value })
                            }
                            placeholder="e.g. Santos"
                            autoComplete="off"
                        />
                    </Field>
                )}
            </div>

            {draft.claim && (
                <div
                    role="status"
                    className="flex flex-wrap items-start gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-700 dark:bg-emerald-950"
                >
                    <CheckCircle2 className="size-8 shrink-0 text-emerald-600" />
                    <div className="grid min-w-0 flex-1 text-left">
                        <p className="text-lg font-bold">
                            You are using the record your family listed
                        </p>
                        <ResidentSummary resident={draft.claim} />
                        <span className="text-base">
                            {draft.claim.listed_by &&
                                `Listed by ${draft.claim.listed_by}. `}
                            {relativesKnown(draft.claim)}
                        </span>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => update({ claim: null })}
                    >
                        <X />
                        This is not me
                    </Button>
                </div>
            )}
            {errors.claim_resident_id && (
                <p className="text-base font-semibold text-red-600">
                    {errors.claim_resident_id}
                </p>
            )}

            {suggestions.loading && !draft.claim && (
                <p className="flex items-center gap-2 text-base text-muted-foreground">
                    <Loader2 className="size-5 animate-spin" />
                    Checking whether your family already listed you…
                </p>
            )}

            {claims.length > 0 && !draft.claim && (
                <section aria-live="polite" className="grid gap-3">
                    <h3 className="flex items-center gap-2 text-xl font-bold">
                        <HelpCircle className="size-6 text-blue-600" />
                        Is this you?
                    </h3>
                    <p className="text-base text-muted-foreground">
                        A family member already listed someone with your name.
                        If it is you, use that record so you are not registered
                        twice.
                    </p>
                    <ul className="grid gap-3">
                        {claims.map((claim) => (
                            <li
                                key={claim.resident.id}
                                className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-blue-300 bg-sky-50 p-4 dark:border-sidebar-border dark:bg-sidebar"
                            >
                                <div className="grid min-w-0 flex-1 text-left">
                                    <ResidentSummary
                                        resident={claim.resident}
                                    />
                                    <span className="text-base">
                                        {claim.resident.listed_by &&
                                            `Listed by ${claim.resident.listed_by}. `}
                                        {relativesKnown(claim.resident)}
                                    </span>
                                    <span className="text-base">
                                        <span className="font-semibold">
                                            Why:{' '}
                                        </span>
                                        {claim.reasons.join(' · ')}
                                    </span>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        type="button"
                                        onClick={() =>
                                            chooseClaim(claim.resident)
                                        }
                                    >
                                        <CheckCircle2 />
                                        Yes, this is me
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            update((current) => ({
                                                dismissedClaims: [
                                                    ...current.dismissedClaims,
                                                    claim.resident.id,
                                                ],
                                            }))
                                        }
                                    >
                                        No, not me
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                </section>
            )}
        </div>
    );
}
