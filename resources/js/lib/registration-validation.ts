import type { FormDataConvertible } from '@inertiajs/core';
import {
    STEP_FAMILY,
    STEP_HOUSEHOLD,
    STEP_PATH,
    STEP_PERSON,
    STEP_REVIEW,
} from '@/lib/registration-draft';
import type { RegistrationDraft, RelativeDraft } from '@/types';

export type StepErrors = Record<string, string>;

const REQUIRED = 'This is required.';

function isValidDate(value: string): boolean {
    return (
        /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(Date.parse(value))
    );
}

function validateRelative(
    prefix: string,
    relative: RelativeDraft,
    needsGender: boolean,
    errors: StepErrors,
): void {
    if (!relative.first_name.trim()) {
        errors[`${prefix}.first_name`] = 'Enter the first name.';
    }

    if (!relative.last_name.trim()) {
        errors[`${prefix}.last_name`] = 'Enter the last name.';
    }

    if (needsGender && !relative.gender) {
        errors[`${prefix}.gender`] = 'Choose a gender.';
    }

    if (!relative.birthdate && relative.approximate_age.trim() === '') {
        errors[`${prefix}.birthdate`] =
            'Enter the birthdate, or the approximate age if you are not sure.';
    }
}

/**
 * Quick checks for one step so people are told about gaps before moving on.
 * The server repeats every rule when the form is saved.
 */
export function validateStep(
    step: number,
    draft: RegistrationDraft,
): StepErrors {
    const errors: StepErrors = {};
    const { person, household, relatives } = draft;

    if (step === STEP_PATH && draft.path === null) {
        errors.path = 'Choose one of the two options to continue.';
    }

    if (step === STEP_PERSON) {
        if (!person.first_name.trim()) {
            errors.first_name = REQUIRED;
        }

        if (!person.last_name.trim()) {
            errors.last_name = REQUIRED;
        }

        if (!person.birthdate) {
            errors.birthdate = REQUIRED;
        } else if (!isValidDate(person.birthdate)) {
            errors.birthdate = 'Enter a valid date.';
        } else if (person.birthdate > new Date().toISOString().slice(0, 10)) {
            errors.birthdate = 'The birthdate cannot be in the future.';
        }

        if (!person.gender) {
            errors.gender = REQUIRED;
        }

        if (!person.civil_status) {
            errors.civil_status = REQUIRED;
        }
    }

    if (step === STEP_FAMILY) {
        if (draft.path === 'new' && !draft.claim) {
            (['father', 'mother', 'spouse'] as const).forEach((slot) => {
                const relative = relatives[slot];

                if (relative) {
                    validateRelative(
                        `relatives.${slot}`,
                        relative,
                        slot === 'spouse',
                        errors,
                    );
                }
            });
            relatives.children.forEach((child, index) =>
                validateRelative(
                    `relatives.children.${index}`,
                    child,
                    true,
                    errors,
                ),
            );
        } else if (
            !draft.claim &&
            !draft.father &&
            !draft.mother &&
            !draft.spouse &&
            draft.dependents.length === 0 &&
            !Object.values(draft.viaRelatives).some(
                (residents) => residents.length > 0,
            ) &&
            !draft.linkLater
        ) {
            errors.family =
                'Link at least one family member or relative, tick "Skip for now", or start a new family record.';
        }
    }

    if (step === STEP_HOUSEHOLD) {
        if (!household.barangay_id) {
            errors.barangay_id = REQUIRED;
        }

        if (household.mode === 'new' && !household.street_address.trim()) {
            errors.street_address = 'Enter the street address.';
        }

        if (household.mode === 'existing' && !household.household) {
            errors.household_id = 'Find and choose the household to join.';
        }

        if (!household.is_head && !household.household_role) {
            errors.household_role =
                'Choose the relationship to the household head.';
        }
    }

    if (step === STEP_REVIEW && hasListedRelatives(draft) && !draft.consent) {
        errors.consent =
            "Please confirm that you have permission to share your relatives' details.";
    }

    return errors;
}

export function hasListedRelatives(draft: RegistrationDraft): boolean {
    const { relatives } = draft;

    return (
        draft.path === 'new' &&
        !draft.claim &&
        Boolean(
            relatives.father ||
            relatives.mother ||
            relatives.spouse ||
            relatives.children.length > 0,
        )
    );
}

/**
 * The step that contains the field an error message belongs to.
 */
export function stepForErrorKey(key: string): number {
    if (key === 'path') {
        return STEP_PATH;
    }

    if (
        [
            'first_name',
            'middle_name',
            'maiden_name',
            'last_name',
            'suffix',
            'birthdate',
            'gender',
            'civil_status',
            'claim_resident_id',
        ].includes(key)
    ) {
        return STEP_PERSON;
    }

    if (
        key.startsWith('relatives') ||
        ['father_id', 'mother_id', 'spouse_id', 'link_later'].includes(key) ||
        key.startsWith('dependent_ids')
    ) {
        return STEP_FAMILY;
    }

    if (key === 'consent') {
        return STEP_REVIEW;
    }

    return STEP_HOUSEHOLD;
}

function relativePayload(
    relative: RelativeDraft,
): Record<string, FormDataConvertible> {
    return {
        first_name: relative.first_name,
        middle_name: relative.middle_name || null,
        last_name: relative.last_name,
        suffix: relative.suffix || null,
        gender: relative.gender || null,
        birthdate: relative.birthdate || null,
        approximate_age:
            relative.approximate_age === ''
                ? null
                : Number(relative.approximate_age),
        is_deceased: relative.is_deceased,
        lives_with_me: relative.lives_with_me,
        other_parent_is_spouse: relative.other_parent_is_spouse,
    };
}

/**
 * Turn the stepper's draft into the request the server expects.
 */
export function buildPayload(
    draft: RegistrationDraft,
): Record<string, FormDataConvertible> {
    const { person, household, relatives } = draft;

    const payload: Record<string, FormDataConvertible> = {
        path: draft.path,
        claim_resident_id: draft.claim?.id ?? null,
        first_name: person.first_name,
        middle_name: person.middle_name || null,
        maiden_name: person.maiden_name || null,
        last_name: person.last_name,
        suffix: person.suffix || null,
        birthdate: person.birthdate,
        gender: person.gender,
        civil_status: person.civil_status,
        barangay_id: household.barangay_id || null,
        household_mode: household.mode,
        household_id:
            household.mode === 'existing'
                ? (household.household?.id ?? null)
                : null,
        street_address:
            household.mode === 'new' ? household.street_address : null,
        is_head: household.is_head,
        household_role: household.is_head ? null : household.household_role,
        consent: draft.consent,
    };

    if (draft.path === 'new' && !draft.claim) {
        payload.relatives = {
            father: relatives.father ? relativePayload(relatives.father) : null,
            mother: relatives.mother ? relativePayload(relatives.mother) : null,
            spouse: relatives.spouse ? relativePayload(relatives.spouse) : null,
            children: relatives.children.map(relativePayload),
            parents_partners: relatives.parents_partners,
        };

        return payload;
    }

    return {
        ...payload,
        link_later: draft.linkLater,
        suggestion_feedback: draft.answers.slice(
            -200,
        ) as unknown as FormDataConvertible,
        via_relatives: Object.fromEntries(
            Object.entries(draft.viaRelatives)
                .filter(([, residents]) => residents.length > 0)
                .map(([kind, residents]) => [
                    kind,
                    residents.map((resident) => resident.id),
                ]),
        ),
        father_id: draft.father?.id ?? null,
        mother_id: draft.mother?.id ?? null,
        spouse_id: draft.spouse?.id ?? null,
        dependent_ids: draft.dependents.map((child) => child.id),
    };
}
