import {
    CheckCircle2,
    Loader2,
    Search,
    SkipForward,
    Sparkles,
    UsersRound,
} from 'lucide-react';
import { DependentsSelector } from '@/components/dependents-selector';
import InputError from '@/components/input-error';
import { ParentSelector } from '@/components/parent-selector';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { pickVisible } from '@/lib/suggestions';
import type { FamilyMatch, RegistrationDraft, ResidentOption } from '@/types';
import { BloodRelatives } from './blood-relatives';
import { SuggestionList } from './suggestion-list';
import type { StepProps, SuggestionState } from './step-types';
import { ViaRelative } from './via-relative';

type Props = StepProps & {
    suggestions: SuggestionState;
    barangayId: number | null;
    onStartNew: () => void;
};

type Slot = 'father' | 'mother' | 'spouse';

export function StepFamilyExisting({
    draft,
    update,
    errors,
    clearErrors,
    suggestions,
    barangayId,
    onStartNew,
}: Props) {
    const { person } = draft;
    const taken = (resident: ResidentOption) =>
        [draft.father, draft.mother, draft.spouse, ...draft.dependents].some(
            (linked) => linked?.id === resident.id,
        );

    function link(slot: Slot, resident: ResidentOption) {
        update({
            [slot]: resident,
            linkLater: false,
        } as Partial<RegistrationDraft>);
        clearErrors('family', `${slot}_id`);
    }

    function addChild(resident: ResidentOption) {
        update((current) => ({
            dependents: [...current.dependents, resident],
            linkLater: false,
        }));
        clearErrors('family', 'dependent_ids');
    }

    const open = (matches: FamilyMatch[] | undefined) =>
        (matches ?? []).filter((match) => !taken(match.resident));
    const data = suggestions.data;
    // A claimed record already has its parents and spouse, so they are not suggested again.
    const fatherMatches =
        draft.father || draft.claim?.father_id ? [] : open(data?.father);
    const motherMatches =
        draft.mother || draft.claim?.mother_id ? [] : open(data?.mother);
    const spouseMatches =
        draft.spouse || draft.claim?.spouse_name ? [] : open(data?.spouse);
    const childMatches = open(data?.children);
    const knownLinks = draft.claim
        ? [
              draft.claim.father_name && `Father: ${draft.claim.father_name}`,
              draft.claim.mother_name && `Mother: ${draft.claim.mother_name}`,
              draft.claim.spouse_name && `Spouse: ${draft.claim.spouse_name}`,
              ...draft.claim.children_names.map((name) => `Child: ${name}`),
          ].filter((link): link is string => Boolean(link))
        : [];
    const hasSuggestions =
        fatherMatches.length +
            motherMatches.length +
            spouseMatches.length +
            childMatches.length >
        0;
    const answeredAny =
        Object.values(draft.rejected).some((ids) => ids.length > 0) ||
        Object.values(draft.unsure).some((ids) => ids.length > 0);

    const strongPicks: { slot: Slot | 'child'; match: FamilyMatch }[] = [
        ...(
            [
                ['father', fatherMatches],
                ['mother', motherMatches],
                ['spouse', spouseMatches],
            ] as const
        ).flatMap(([slot, matches]) => {
            const best = pickVisible(draft, slot, matches).visible.find(
                (m) => m.confidence === 'high',
            );

            return best ? [{ slot, match: best }] : [];
        }),
        ...pickVisible(draft, 'children', childMatches)
            .visible.filter((match) => match.confidence === 'high')
            .map((match) => ({ slot: 'child' as const, match })),
    ];

    function linkAllStrong() {
        update((current) => {
            const patch: Partial<RegistrationDraft> = { linkLater: false };
            const children = [...current.dependents];

            strongPicks.forEach(({ slot, match }) => {
                if (slot === 'child') {
                    children.push(match.resident);
                } else {
                    patch[slot] = match.resident;
                }
            });

            return { ...patch, dependents: children };
        });
        clearErrors('family');
    }

    const dependentsReason =
        !person.gender || !person.birthdate || barangayId === null
            ? 'Fill in your gender, birthdate and barangay first.'
            : null;

    return (
        <div className="grid gap-6">
            {draft.claim && knownLinks.length > 0 && (
                <section className="grid gap-2 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-4 dark:border-emerald-700 dark:bg-emerald-950">
                    <h3 className="flex items-center gap-2 text-xl font-bold">
                        <CheckCircle2 className="size-6 text-emerald-600" />
                        Already linked in your family record
                    </h3>
                    <p className="text-base">
                        Your family already linked these people to you, so you
                        do not need to find them again:
                    </p>
                    <ul className="grid gap-1 text-lg">
                        {knownLinks.map((link) => (
                            <li key={link} className="font-semibold">
                                {link}
                            </li>
                        ))}
                    </ul>
                    <p className="text-base text-muted-foreground">
                        You can still link anyone who is missing below.
                    </p>
                </section>
            )}

            <section className="grid gap-4" aria-live="polite">
                <h3 className="flex items-center gap-2 text-xl font-bold">
                    <Sparkles className="size-6 text-blue-600" />
                    Is this your family?
                </h3>

                {suggestions.loading && (
                    <p className="flex items-center gap-2 text-base text-muted-foreground">
                        <Loader2 className="size-5 animate-spin" />
                        Looking for your family in the records…
                    </p>
                )}
                {suggestions.failed && (
                    <p className="text-base font-semibold text-red-600">
                        The automatic search did not work. You can still search
                        by name below.
                    </p>
                )}
                {!suggestions.loading &&
                    !suggestions.failed &&
                    !hasSuggestions && (
                        <p className="rounded-xl border-2 border-slate-200 p-4 text-base dark:border-border">
                            {knownLinks.length > 0 || answeredAny
                                ? 'No other likely relatives were found. Search by name below if someone is missing.'
                                : 'We could not find likely relatives automatically. Search by name below, or start a new family record.'}
                        </p>
                    )}

                <SuggestionList
                    slot="father"
                    title="Possible fathers"
                    matches={fatherMatches}
                    draft={draft}
                    update={update}
                    actionLabel={() => 'Yes, my father'}
                    onLink={(match) => link('father', match.resident)}
                />
                <SuggestionList
                    slot="mother"
                    title="Possible mothers"
                    matches={motherMatches}
                    draft={draft}
                    update={update}
                    actionLabel={() => 'Yes, my mother'}
                    onLink={(match) => link('mother', match.resident)}
                />
                <SuggestionList
                    slot="spouse"
                    title="Possible spouses"
                    matches={spouseMatches}
                    draft={draft}
                    update={update}
                    actionLabel={() => 'Yes, my spouse'}
                    onLink={(match) => link('spouse', match.resident)}
                />
                <SuggestionList
                    slot="children"
                    title="Possible children"
                    matches={childMatches}
                    draft={draft}
                    update={update}
                    actionLabel={() => 'Yes, my child'}
                    onLink={(match) => addChild(match.resident)}
                />

                {strongPicks.length > 1 && (
                    <Button type="button" size="lg" onClick={linkAllStrong}>
                        <UsersRound />
                        Link all {strongPicks.length} strong matches
                    </Button>
                )}
            </section>

            {!(draft.father && draft.mother) && (
                <ViaRelative
                    draft={draft}
                    update={update}
                    clearErrors={clearErrors}
                    suggestions={suggestions}
                />
            )}

            <BloodRelatives draft={draft} />

            <details
                className="rounded-xl border-2 border-slate-200 p-4 dark:border-border"
                open={Boolean(
                    draft.father ||
                    draft.mother ||
                    draft.spouse ||
                    draft.dependents.length > 0,
                )}
            >
                <summary className="cursor-pointer text-lg font-bold">
                    Search by name instead
                </summary>
                <div className="mt-4 grid gap-5">
                    <div className="grid gap-5 md:grid-cols-2">
                        <ParentSelector
                            id="father"
                            label="Father"
                            icon={Search}
                            selected={draft.father}
                            onChange={(resident) =>
                                update({ father: resident })
                            }
                            params={{
                                gender: 'Male',
                                older_than: person.birthdate,
                            }}
                            error={errors.father_id}
                        />
                        <ParentSelector
                            id="mother"
                            label="Mother"
                            icon={Search}
                            selected={draft.mother}
                            onChange={(resident) =>
                                update({ mother: resident })
                            }
                            params={{
                                gender: 'Female',
                                older_than: person.birthdate,
                            }}
                            error={errors.mother_id}
                        />
                    </div>
                    <ParentSelector
                        id="spouse"
                        label="Spouse (optional)"
                        icon={Search}
                        selected={draft.spouse}
                        onChange={(resident) => update({ spouse: resident })}
                        params={{ without_spouse: true }}
                        error={errors.spouse_id}
                    />
                    <DependentsSelector
                        selected={draft.dependents}
                        onChange={(dependents) => update({ dependents })}
                        excludeIds={[
                            draft.father?.id,
                            draft.mother?.id,
                            draft.spouse?.id,
                        ].filter((id): id is number => id !== undefined)}
                        disabledReason={dependentsReason}
                        params={{
                            born_after: person.birthdate,
                            unlinked_parent:
                                person.gender === 'Male' ? 'father' : 'mother',
                            barangay_id: barangayId,
                        }}
                        error={errors.dependent_ids}
                    />
                </div>
            </details>

            <div className="grid gap-3 rounded-xl border-2 border-slate-200 p-4 dark:border-border">
                <div className="flex items-start gap-3">
                    <Checkbox
                        id="link-later"
                        checked={draft.linkLater}
                        onCheckedChange={(checked) => {
                            update({ linkLater: checked === true });
                            clearErrors('family');
                        }}
                        className="mt-1"
                    />
                    <div className="grid gap-1">
                        <Label htmlFor="link-later" className="text-lg">
                            <SkipForward className="size-5" />
                            Skip for now, I will link my family later
                        </Label>
                        <p className="text-base text-muted-foreground">
                            An admin can add your family links when approving
                            your registration.
                        </p>
                    </div>
                </div>
            </div>
            <InputError message={errors.family} />

            <div className="flex flex-wrap items-center gap-3">
                <p className="text-base">
                    Nobody in your family is registered yet?
                </p>
                <Button type="button" variant="outline" onClick={onStartNew}>
                    <UsersRound />
                    Start a new family record
                </Button>
            </div>
        </div>
    );
}
