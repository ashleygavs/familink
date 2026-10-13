import { Eye, RotateCcw } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { RELATIVE_KINDS } from '@/lib/relatives';
import {
    feedbackEntry,
    MAX_REJECTIONS,
    pickVisible,
    upsertAnswer,
} from '@/lib/suggestions';
import type {
    FamilyMatch,
    FeedbackDecision,
    RegistrationDraft,
    RelativeKind,
} from '@/types';
import { MatchCard } from './match-card';
import type { DraftUpdate } from './step-types';

type Props<T extends FamilyMatch> = {
    /** The decision list this belongs to: father, mother, spouse, children or a relative kind. */
    slot: string;
    title?: string;
    matches: T[];
    /** Residents already linked elsewhere, never shown here. */
    hiddenIds?: number[];
    draft: RegistrationDraft;
    update: DraftUpdate;
    actionLabel: (match: T) => string;
    onLink: (match: T) => void;
    note?: (match: T) => string;
};

const without = (record: Record<string, number[]>, key: string) =>
    Object.fromEntries(Object.entries(record).filter(([name]) => name !== key));

/**
 * A short list of suggested people for one role. Each can be accepted (check),
 * rejected (X, remembered so they are not offered again, and the next best
 * candidate takes their place), set aside as "not sure", or moved to another
 * kind of relative ("Related another way?"). Every answer is recorded. After a
 * few rejections the list stops guessing and points to the name search.
 */
export function SuggestionList<T extends FamilyMatch>({
    slot,
    title,
    matches,
    hiddenIds = [],
    draft,
    update,
    actionLabel,
    onLink,
    note,
}: Props<T>) {
    const { visible, unsureCount, stopped } = pickVisible(
        draft,
        slot,
        matches,
        hiddenIds,
    );

    if (visible.length === 0 && unsureCount === 0 && !stopped) {
        return null;
    }

    const relatedOptions = RELATIVE_KINDS.filter(
        ({ kind }) => kind !== slot,
    ).map(({ kind, title: label }) => ({ kind, label }));

    const answer = (
        key: 'rejected' | 'unsure',
        match: T,
        decision: FeedbackDecision,
    ) =>
        update((current) => ({
            [key]: {
                ...current[key],
                [slot]: [...(current[key][slot] ?? []), match.resident.id],
            },
            answers: upsertAnswer(
                current.answers,
                feedbackEntry(match, slot, decision),
            ),
        }));

    const accept = (match: T) => {
        update((current) => ({
            answers: upsertAnswer(
                current.answers,
                feedbackEntry(match, slot, 'accepted'),
            ),
        }));
        onLink(match);
    };

    const relateAnotherWay = (match: T, kind: RelativeKind) =>
        update((current) => ({
            // Remember the person as the other kind of relative, and stop offering them here.
            viaRelatives: current.viaRelatives[kind].some(
                (resident) => resident.id === match.resident.id,
            )
                ? current.viaRelatives
                : {
                      ...current.viaRelatives,
                      [kind]: [...current.viaRelatives[kind], match.resident],
                  },
            rejected: {
                ...current.rejected,
                [slot]: [...(current.rejected[slot] ?? []), match.resident.id],
            },
            answers: upsertAnswer(
                current.answers,
                feedbackEntry(match, slot, 'reclassified', kind),
            ),
        }));

    return (
        <div className="grid gap-3">
            {title && <h4 className="text-lg font-bold">{title}</h4>}

            {visible.length > 0 && (
                <ul className="grid gap-3" aria-live="polite">
                    {visible.map((match) => (
                        <MatchCard
                            key={match.resident.id}
                            match={match}
                            note={note?.(match)}
                            actionLabel={actionLabel(match)}
                            onLink={() => accept(match)}
                            onReject={() =>
                                answer('rejected', match, 'rejected')
                            }
                            onUnsure={() => answer('unsure', match, 'unsure')}
                            relatedOptions={relatedOptions}
                            onRelated={(kind) => relateAnotherWay(match, kind)}
                        />
                    ))}
                </ul>
            )}

            {stopped && (
                <div className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-slate-200 p-4 dark:border-border">
                    <p className="min-w-0 flex-1 text-base">
                        You said no {MAX_REJECTIONS} times, so we will stop
                        guessing here. Search by name if you know the person.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() =>
                            update((current) => ({
                                rejected: without(current.rejected, slot),
                            }))
                        }
                    >
                        <RotateCcw />
                        Show suggestions again
                    </Button>
                </div>
            )}

            {unsureCount > 0 && !stopped && (
                <Button
                    type="button"
                    variant="ghost"
                    className="justify-start"
                    onClick={() =>
                        update((current) => ({
                            unsure: without(current.unsure, slot),
                        }))
                    }
                >
                    <Eye />
                    Show the {unsureCount} I was not sure about
                </Button>
            )}
        </div>
    );
}
