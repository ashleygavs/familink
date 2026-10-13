import { Loader2, Search, UserSearch, X } from 'lucide-react';
import ResidentController from '@/actions/App/Http/Controllers/ResidentController';
import { ResidentSummary } from '@/components/parent-selector';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import {
    relativeSuggestionUrl,
    useFamilySuggestions,
} from '@/hooks/use-family-suggestions';
import { RELATIVE_KINDS } from '@/lib/relatives';
import type {
    FamilyMatch,
    RelativeKind,
    RelativeSuggestion,
    ResidentOption,
} from '@/types';
import { MatchCard } from './match-card';
import type { StepProps, SuggestionState } from './step-types';

type Props = Pick<StepProps, 'draft' | 'update' | 'clearErrors'> & {
    suggestions: SuggestionState;
};

/**
 * Other registered relatives (brothers and sisters, grandparents, uncles and
 * aunts, cousins, nephews and nieces, grandchildren). Each kind works like the
 * father and mother fields: likely people are suggested automatically and a
 * name search is the fallback, and any number of people can be added. The
 * relatives are remembered on the record, and the person's parents are worked
 * out from them when the records allow it.
 */
export function ViaRelative({
    draft,
    update,
    clearErrors,
    suggestions,
}: Props) {
    const parents = useFamilySuggestions(relativeSuggestionUrl(draft));
    const chosenIds = Object.values(draft.viaRelatives).flatMap((residents) =>
        residents.map((resident) => resident.id),
    );
    const anyChosen = chosenIds.length > 0;

    const taken = (resident: ResidentOption) =>
        draft.father?.id === resident.id || draft.mother?.id === resident.id;
    const open = (matches: FamilyMatch[] | undefined) =>
        (matches ?? []).filter((match) => !taken(match.resident));
    const fathers = draft.father ? [] : open(parents.data?.father);
    const mothers = draft.mother ? [] : open(parents.data?.mother);

    const likely: RelativeSuggestion[] = (
        suggestions.data?.relatives ?? []
    ).filter((match) => !chosenIds.includes(match.resident.id));
    const nothingFound =
        !suggestions.loading && likely.length === 0 && !anyChosen;

    function add(kind: RelativeKind, resident: ResidentOption) {
        update((current) => ({
            viaRelatives: {
                ...current.viaRelatives,
                [kind]: [...current.viaRelatives[kind], resident],
            },
        }));
    }

    function remove(kind: RelativeKind, id: number) {
        update((current) => ({
            viaRelatives: {
                ...current.viaRelatives,
                [kind]: current.viaRelatives[kind].filter(
                    (resident) => resident.id !== id,
                ),
            },
        }));
    }

    return (
        <section className="grid gap-5 rounded-xl border-2 border-blue-200 bg-card p-4 md:p-5 dark:border-sidebar-border">
            <div>
                <h3 className="flex items-center gap-2 text-xl font-bold">
                    <UserSearch className="size-6 text-blue-600" />
                    Do you know relatives who are already registered?
                </h3>
                <p className="mt-1 text-base text-muted-foreground">
                    Confirm any relatives below, or search for them by name. You
                    can add as many as you like. We remember them on your record
                    and work out your parents from them when we can.
                </p>
            </div>

            {suggestions.loading && (
                <p className="flex items-center gap-2 text-base text-muted-foreground">
                    <Loader2 className="size-5 animate-spin" />
                    Looking for people who may be your relatives…
                </p>
            )}

            {nothingFound && (
                <p className="rounded-xl border-2 border-slate-200 p-4 text-base dark:border-border">
                    We could not find likely relatives automatically. Use the
                    search boxes below if you know one.
                </p>
            )}

            {RELATIVE_KINDS.map(({ kind, title, short, searchLabel }) => {
                const chosen = draft.viaRelatives[kind];
                const suggested = likely.filter(
                    (match) => match.relation === kind,
                );

                return (
                    <div key={kind} className="grid gap-3">
                        <h4 className="text-lg font-bold">{title}</h4>

                        {chosen.length > 0 && (
                            <ul className="grid gap-2">
                                {chosen.map((resident) => (
                                    <li
                                        key={resident.id}
                                        className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-3 dark:border-emerald-700 dark:bg-emerald-950"
                                    >
                                        <div className="grid min-w-0 flex-1 text-left">
                                            <span className="text-base font-semibold">
                                                Your {short}
                                            </span>
                                            <ResidentSummary
                                                resident={resident}
                                            />
                                        </div>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            onClick={() =>
                                                remove(kind, resident.id)
                                            }
                                        >
                                            <X />
                                            Remove
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                        )}

                        {suggested.length > 0 && (
                            <ul className="grid gap-3" aria-live="polite">
                                {suggested.map((match) => (
                                    <MatchCard
                                        key={match.resident.id}
                                        match={match}
                                        note={`May be your ${short}`}
                                        actionLabel={`Yes, my ${short}`}
                                        onLink={() => add(kind, match.resident)}
                                    />
                                ))}
                            </ul>
                        )}

                        <SearchSelect<ResidentOption>
                            id={`via-${kind}`}
                            label={
                                chosen.length > 0
                                    ? `Add another ${short}`
                                    : searchLabel
                            }
                            icon={Search}
                            placeholder="Type a name or System ID"
                            endpoint={ResidentController.search.url()}
                            excludeIds={chosenIds}
                            renderOption={(resident) => (
                                <ResidentSummary resident={resident} />
                            )}
                            onSelect={(resident) => add(kind, resident)}
                        />
                    </div>
                );
            })}

            {anyChosen && parents.loading && (
                <p className="flex items-center gap-2 text-base text-muted-foreground">
                    <Loader2 className="size-5 animate-spin" />
                    Working out your parents…
                </p>
            )}

            {anyChosen &&
                !parents.loading &&
                fathers.length + mothers.length === 0 &&
                !draft.father &&
                !draft.mother && (
                    <p className="rounded-xl border-2 border-slate-200 p-4 text-base dark:border-border">
                        We could not work out your parents from these relatives,
                        because their own parents are not recorded yet. They
                        will still be remembered as your relatives on your
                        record.
                    </p>
                )}

            {fathers.length > 0 && (
                <div className="grid gap-3">
                    <h4 className="text-lg font-bold">Possible fathers</h4>
                    <ul className="grid gap-3">
                        {fathers.map((match) => (
                            <MatchCard
                                key={match.resident.id}
                                match={match}
                                actionLabel="Link as my father"
                                onLink={() => {
                                    update({
                                        father: match.resident,
                                        linkLater: false,
                                    });
                                    clearErrors('family', 'father_id');
                                }}
                            />
                        ))}
                    </ul>
                </div>
            )}

            {mothers.length > 0 && (
                <div className="grid gap-3">
                    <h4 className="text-lg font-bold">Possible mothers</h4>
                    <ul className="grid gap-3">
                        {mothers.map((match) => (
                            <MatchCard
                                key={match.resident.id}
                                match={match}
                                actionLabel="Link as my mother"
                                onLink={() => {
                                    update({
                                        mother: match.resident,
                                        linkLater: false,
                                    });
                                    clearErrors('family', 'mother_id');
                                }}
                            />
                        ))}
                    </ul>
                </div>
            )}
        </section>
    );
}
