import type { FamilySuggestions, RegistrationDraft } from '@/types';

export type DraftUpdate = (
    patch:
        | Partial<RegistrationDraft>
        | ((draft: RegistrationDraft) => Partial<RegistrationDraft>),
) => void;

export type StepErrors = Record<string, string>;

export type StepProps = {
    draft: RegistrationDraft;
    update: DraftUpdate;
    errors: StepErrors;
    /** Forget errors for fields that were just edited (matches exact keys or prefixes). */
    clearErrors: (...keys: string[]) => void;
};

export type SuggestionState = {
    data: FamilySuggestions | null;
    loading: boolean;
    failed: boolean;
};
