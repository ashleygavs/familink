import { useCallback, useEffect, useState } from 'react';
import { clearDraft, loadDraft, saveDraft } from '@/lib/registration-draft';
import type { RegistrationDraft } from '@/types';

type Updater =
    | Partial<RegistrationDraft>
    | ((draft: RegistrationDraft) => Partial<RegistrationDraft>);

/**
 * Holds the stepper's draft and mirrors every change into sessionStorage, so
 * the registration picks up where it left off after a reload.
 */
export function useRegistrationDraft(
    userId: number,
    lockedBarangayId: number | null,
) {
    const [draft, setDraft] = useState<RegistrationDraft>(() =>
        loadDraft(userId, lockedBarangayId),
    );

    useEffect(() => {
        saveDraft(userId, draft);
    }, [userId, draft]);

    const update = useCallback((patch: Updater) => {
        setDraft((current) => ({
            ...current,
            ...(typeof patch === 'function' ? patch(current) : patch),
        }));
    }, []);

    const discard = useCallback(() => clearDraft(userId), [userId]);

    return { draft, update, discard };
}
