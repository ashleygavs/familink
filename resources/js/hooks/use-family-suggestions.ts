import FamilyRelativesController from '@/actions/App/Http/Controllers/FamilyRelativesController';
import FamilySuggestionController from '@/actions/App/Http/Controllers/FamilySuggestionController';
import RelativeSuggestionController from '@/actions/App/Http/Controllers/RelativeSuggestionController';
import { useJsonQuery } from '@/hooks/use-json-query';
import { RELATIVE_KINDS } from '@/lib/relatives';
import { FETCH_LIMIT, rejectedParams } from '@/lib/suggestions';
import type {
    BloodRelatives,
    FamilySuggestions,
    RegistrationDraft,
} from '@/types';

/**
 * The query for the suggestions endpoint, or null until enough of the form is
 * filled in (a name and a complete birthdate).
 */
export function suggestionUrl(draft: RegistrationDraft): string | null {
    const { person, household } = draft;
    const firstName = person.first_name.trim();
    const lastName = person.last_name.trim();

    if (
        firstName.length < 2 ||
        lastName.length < 2 ||
        !/^\d{4}-\d{2}-\d{2}$/.test(person.birthdate) ||
        person.birthdate < '1900-01-01'
    ) {
        return null;
    }

    const query: Record<string, string> = {
        first_name: firstName,
        last_name: lastName,
        birthdate: person.birthdate,
    };

    if (person.middle_name.trim()) {
        query.middle_name = person.middle_name.trim();
    }

    if (person.maiden_name.trim()) {
        query.maiden_name = person.maiden_name.trim();
    }

    if (person.gender) {
        query.gender = person.gender;
    }

    if (person.civil_status) {
        query.civil_status = person.civil_status;
    }

    if (household.barangay_id) {
        query.barangay_id = household.barangay_id;
    }

    if (household.mode === 'existing' && household.household) {
        query.household_id = String(household.household.id);
    }

    query.limit = String(FETCH_LIMIT);

    const params = new URLSearchParams(query);

    rejectedParams(draft).forEach(([key, value]) => params.append(key, value));

    return `${FamilySuggestionController.url()}?${params.toString()}`;
}

/**
 * The query for "my parents are the parents of these relatives", or null until
 * at least one relative has been chosen.
 */
export function relativeSuggestionUrl(draft: RegistrationDraft): string | null {
    const chosen = RELATIVE_KINDS.flatMap(({ kind }) =>
        draft.viaRelatives[kind].map((resident) => ({ kind, resident })),
    ).slice(0, 30);

    if (chosen.length === 0) {
        return null;
    }

    const query = new URLSearchParams();

    chosen.forEach(({ kind, resident }, index) => {
        query.set(`relatives[${index}][id]`, String(resident.id));
        query.set(`relatives[${index}][relation]`, kind);
    });

    return `${RelativeSuggestionController.url()}?${query.toString()}`;
}
/**
 * The query for the relatives that follow from the chosen parents, or null
 * when no parent is known yet.
 */
export function bloodRelativesUrl(draft: RegistrationDraft): string | null {
    const fatherId = draft.father?.id ?? draft.claim?.father_id ?? null;
    const motherId = draft.mother?.id ?? draft.claim?.mother_id ?? null;

    if (fatherId === null && motherId === null) {
        return null;
    }

    const query: Record<string, string> = {};

    if (fatherId !== null) {
        query.father_id = String(fatherId);
    }

    if (motherId !== null) {
        query.mother_id = String(motherId);
    }

    if (draft.claim) {
        query.self_id = String(draft.claim.id);
    }

    return FamilyRelativesController.url({ query });
}

/**
 * Looks up likely relatives and claimable listed records for the person being
 * registered. Requests are debounced and cancelled when the details change.
 */
export function useFamilySuggestions(url: string | null) {
    return useJsonQuery<FamilySuggestions>(url, 450, true);
}

export function useBloodRelatives(url: string | null) {
    return useJsonQuery<BloodRelatives>(url, 250);
}
