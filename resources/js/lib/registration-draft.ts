import type { RegistrationDraft, RelativeDraft } from '@/types';

/**
 * The stepper remembers its progress in sessionStorage: it survives a reload,
 * but is wiped when the tab is closed or the user logs out, which keeps
 * personal details off shared barangay computers.
 */
const DRAFT_VERSION = 8;
const STORAGE_PREFIX = 'familink.registration.draft.';

export const STEP_PATH = 0;
export const STEP_PERSON = 1;
export const STEP_FAMILY = 2;
export const STEP_HOUSEHOLD = 3;
export const STEP_REVIEW = 4;

export const STEPS = [
    { id: 'path', title: 'Start' },
    { id: 'person', title: 'About you' },
    { id: 'family', title: 'Family' },
    { id: 'household', title: 'Household' },
    { id: 'review', title: 'Review' },
] as const;

export function emptyRelative(
    overrides: Partial<RelativeDraft> = {},
): RelativeDraft {
    return {
        first_name: '',
        middle_name: '',
        last_name: '',
        gender: '',
        birthdate: '',
        approximate_age: '',
        is_deceased: false,
        lives_with_me: false,
        other_parent_is_spouse: true,
        ...overrides,
    };
}

export function emptyDraft(lockedBarangayId: number | null): RegistrationDraft {
    return {
        version: DRAFT_VERSION,
        step: STEP_PATH,
        maxStep: STEP_PATH,
        path: null,
        person: {
            first_name: '',
            middle_name: '',
            maiden_name: '',
            last_name: '',
            birthdate: '',
            gender: '',
            civil_status: '',
        },
        claim: null,
        dismissedClaims: [],
        father: null,
        mother: null,
        spouse: null,
        dependents: [],
        viaRelatives: {
            sibling: [],
            grandparent: [],
            grandchild: [],
            uncle_aunt: [],
            nephew_niece: [],
            cousin: [],
        },
        rejected: {},
        answers: [],
        unsure: {},
        linkLater: false,
        relatives: {
            father: null,
            mother: null,
            spouse: null,
            children: [],
            parents_partners: true,
        },
        household: {
            barangay_id: lockedBarangayId ? String(lockedBarangayId) : '',
            mode: 'new',
            household: null,
            street_address: '',
            is_head: false,
            household_role: '',
        },
        consent: false,
    };
}

function storageKey(userId: number): string {
    return `${STORAGE_PREFIX}${userId}`;
}

export function loadDraft(
    userId: number,
    lockedBarangayId: number | null,
): RegistrationDraft {
    try {
        const raw = window.sessionStorage.getItem(storageKey(userId));
        const parsed = raw ? (JSON.parse(raw) as RegistrationDraft) : null;

        if (parsed && parsed.version === DRAFT_VERSION) {
            if (lockedBarangayId) {
                parsed.household.barangay_id = String(lockedBarangayId);
            }

            return parsed;
        }
    } catch {
        // Storage can be blocked or hold invalid data; start fresh.
    }

    return emptyDraft(lockedBarangayId);
}

export function saveDraft(userId: number, draft: RegistrationDraft): void {
    try {
        window.sessionStorage.setItem(
            storageKey(userId),
            JSON.stringify(draft),
        );
    } catch {
        // Storage can be blocked or full; the form still works without memory.
    }
}

export function clearDraft(userId: number): void {
    try {
        window.sessionStorage.removeItem(storageKey(userId));
    } catch {
        // Nothing to clear.
    }
}

/**
 * Remove every saved draft. Called on logout so the next person on a shared
 * computer never sees someone else's half-filled form.
 */
export function clearRegistrationDrafts(): void {
    try {
        Object.keys(window.sessionStorage)
            .filter((key) => key.startsWith(STORAGE_PREFIX))
            .forEach((key) => window.sessionStorage.removeItem(key));
    } catch {
        // Nothing to clear.
    }
}
