import type {
    FamilyMatch,
    FeedbackDecision,
    FeedbackEntry,
    RegistrationDraft,
    RelativeKind,
    ResidentOption,
} from '@/types';

/** How many suggestions are shown at once for each role. */
export const VISIBLE_MATCHES = 3;

/** How many suggestions are asked from the server per role, so a "no" can be refilled instantly. */
export const FETCH_LIMIT = 8;

/** After this many "no" answers for one role the system stops guessing and points to the name search. */
export const MAX_REJECTIONS = 5;

const RELATIVE_KIND_VALUES: string[] = [
    'sibling',
    'grandparent',
    'grandchild',
    'uncle_aunt',
    'nephew_niece',
    'cousin',
];

export const isRelativeKind = (slot: string): slot is RelativeKind =>
    RELATIVE_KIND_VALUES.includes(slot);

/**
 * The query-string parts that tell the server who the person already said no to.
 */
export function rejectedParams(draft: RegistrationDraft): [string, string][] {
    return Object.entries(draft.rejected).flatMap(([slot, ids]) =>
        ids
            .slice(0, 100)
            .map((id): [string, string] => [
                isRelativeKind(slot)
                    ? `rejected[relatives][${slot}][]`
                    : `rejected[${slot}][]`,
                String(id),
            ]),
    );
}

export type VisibleMatches<T> = {
    /** The suggestions to show now. */
    visible: T[];
    /** How many were set aside as "not sure". */
    unsureCount: number;
    /** True once the person has said no too many times for this role. */
    stopped: boolean;
};

/**
 * Pick the suggestions to show for a role: not rejected, not set aside, not
 * already linked elsewhere, and no more than a few at a time.
 */
export function pickVisible<T extends { resident: ResidentOption }>(
    draft: RegistrationDraft,
    slot: string,
    matches: T[],
    hiddenIds: number[] = [],
): VisibleMatches<T> {
    const rejected = draft.rejected[slot] ?? [];
    const unsure = draft.unsure[slot] ?? [];
    const stopped = rejected.length >= MAX_REJECTIONS;
    const open = matches.filter(
        ({ resident }) =>
            !hiddenIds.includes(resident.id) && !rejected.includes(resident.id),
    );

    return {
        visible: stopped
            ? []
            : open
                  .filter(({ resident }) => !unsure.includes(resident.id))
                  .slice(0, VISIBLE_MATCHES),
        unsureCount: open.filter(({ resident }) => unsure.includes(resident.id))
            .length,
        stopped,
    };
}

/**
 * Turn the answer to a suggestion into the record kept for it.
 */
export function feedbackEntry(
    match: FamilyMatch,
    role: string,
    decision: FeedbackDecision,
    reclassifiedAs: RelativeKind | null = null,
): FeedbackEntry {
    return {
        candidate_id: match.resident.id,
        role,
        decision,
        reclassified_as: reclassifiedAs,
        score: match.score,
        confidence: match.confidence,
        reasons: match.reasons,
    };
}

/**
 * Add an answer, replacing any earlier answer about the same person and role.
 */
export function upsertAnswer(
    answers: FeedbackEntry[],
    entry: FeedbackEntry,
): FeedbackEntry[] {
    return [
        ...answers.filter(
            (answer) =>
                !(
                    answer.candidate_id === entry.candidate_id &&
                    answer.role === entry.role
                ),
        ),
        entry,
    ];
}
