import { Home, MapPin } from 'lucide-react';
import { HouseholdSelector } from '@/components/household-selector';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { HouseholdDraft, HouseholdOption } from '@/types';
import { asChoices, ChoiceSelect, Field } from './field';
import type { StepProps } from './step-types';

type Props = StepProps & {
    barangays: { id: number; name: string }[];
    lockedBarangayId: number | null;
    householdRoles: string[];
};

const MODES = [
    {
        mode: 'new',
        title: 'Create a new household',
        hint: 'This person starts a new household.',
    },
    {
        mode: 'existing',
        title: 'Join an existing household',
        hint: 'Add this person to a household already on record.',
    },
] as const;

export function StepHousehold({
    draft,
    update,
    errors,
    clearErrors,
    barangays,
    lockedBarangayId,
    householdRoles,
}: Props) {
    const { household } = draft;
    const barangayId = household.barangay_id
        ? Number(household.barangay_id)
        : null;

    function set(patch: Partial<HouseholdDraft>) {
        update((current) => ({
            household: { ...current.household, ...patch },
        }));
        clearErrors(
            ...Object.keys(patch),
            ...(patch.household !== undefined ? ['household_id'] : []),
        );
    }

    const parentHousehold = [draft.father, draft.mother]
        .map((parent) => parent?.household)
        .find((candidate) => candidate && candidate.barangay_id === barangayId);
    const canSuggest =
        parentHousehold &&
        !(
            household.mode === 'existing' &&
            household.household?.id === parentHousehold.id
        );

    return (
        <div className="grid gap-5">
            {lockedBarangayId === null ? (
                <Field
                    id="barangay_id"
                    label="Barangay"
                    error={errors.barangay_id}
                >
                    <ChoiceSelect
                        id="barangay_id"
                        value={household.barangay_id}
                        placeholder="Choose barangay"
                        values={barangays.map((barangay) => ({
                            value: String(barangay.id),
                            label: barangay.name,
                        }))}
                        onChange={(value) =>
                            set({ barangay_id: value, household: null })
                        }
                    />
                </Field>
            ) : (
                <div className="flex flex-wrap items-center gap-2 text-lg font-semibold">
                    <MapPin className="size-6 text-blue-600" />
                    Barangay {barangays[0]?.name}
                    <span className="text-base font-normal text-muted-foreground">
                        (your assigned barangay)
                    </span>
                </div>
            )}

            <div
                role="radiogroup"
                aria-label="Household"
                className="grid gap-3 md:grid-cols-2"
            >
                {MODES.map(({ mode, title, hint }) => (
                    <button
                        key={mode}
                        type="button"
                        role="radio"
                        aria-checked={household.mode === mode}
                        onClick={() => set({ mode })}
                        className={`min-h-20 rounded-xl border-2 p-4 text-left focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none ${
                            household.mode === mode
                                ? 'border-blue-600 bg-blue-50 dark:bg-sidebar-accent'
                                : 'border-slate-200 hover:border-blue-300 dark:border-border'
                        }`}
                    >
                        <span className="block text-lg font-bold">{title}</span>
                        <span className="block text-base text-muted-foreground">
                            {hint}
                        </span>
                    </button>
                ))}
            </div>

            {canSuggest && parentHousehold && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-blue-200 bg-sky-50 p-4 dark:border-sidebar-border dark:bg-sidebar">
                    <Home className="size-7 shrink-0 text-blue-600" />
                    <p className="min-w-0 flex-1 text-base">
                        A parent lives in household{' '}
                        <strong>{parentHousehold.household_code}</strong> (
                        {parentHousehold.street_address}).
                    </p>
                    <Button
                        type="button"
                        onClick={() =>
                            set({
                                mode: 'existing',
                                household: parentHousehold as HouseholdOption,
                            })
                        }
                    >
                        Join this household
                    </Button>
                </div>
            )}

            {household.mode === 'new' ? (
                <Field
                    id="street_address"
                    label="Street address"
                    error={errors.street_address}
                >
                    <Input
                        id="street_address"
                        value={household.street_address}
                        onChange={(e) =>
                            set({ street_address: e.target.value })
                        }
                        placeholder="e.g. 12 Rizal Street, Purok 3"
                        autoComplete="off"
                    />
                </Field>
            ) : (
                <HouseholdSelector
                    selected={household.household}
                    onChange={(selected) => set({ household: selected })}
                    barangayId={barangayId}
                    error={errors.household_id}
                />
            )}

            {draft.path === 'new' && !draft.claim && (
                <p className="rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-base dark:border-sidebar-border dark:bg-sidebar">
                    Relatives you marked as{' '}
                    <strong>living in your household</strong> will be placed in
                    this household.
                </p>
            )}

            <div className="grid gap-3 rounded-xl border-2 border-slate-200 p-4 dark:border-border">
                <div className="flex items-start gap-3">
                    <Checkbox
                        id="is_head"
                        checked={household.is_head}
                        onCheckedChange={(checked) =>
                            set({ is_head: checked === true })
                        }
                        className="mt-1"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="is_head" className="text-lg">
                            This person is the head of the household
                        </Label>
                        <p className="text-base text-muted-foreground">
                            Tick this only if they are the head. If the head has
                            not registered yet, leave it unticked: the head can
                            be registered later and join this household.
                        </p>
                    </div>
                </div>
                <InputError message={errors.is_head} />

                {!household.is_head && (
                    <Field
                        id="household_role"
                        label="Relationship to the household head"
                        error={errors.household_role}
                    >
                        <ChoiceSelect
                            id="household_role"
                            className="w-full md:w-80"
                            value={household.household_role}
                            placeholder="Choose relationship"
                            values={asChoices(householdRoles)}
                            onChange={(value) => set({ household_role: value })}
                        />
                    </Field>
                )}
            </div>
        </div>
    );
}
