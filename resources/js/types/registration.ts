import type { HouseholdOption, ResidentOption } from './resident';

export type FamilyPath = 'new' | 'existing';

export type Gender = 'Male' | 'Female';

/** A relative typed in by a first registrant. They become a "Listed" resident. */
export type RelativeDraft = {
    first_name: string;
    middle_name: string;
    last_name: string;
    suffix: string;
    gender: '' | Gender;
    birthdate: string;
    approximate_age: string;
    is_deceased: boolean;
    lives_with_me: boolean;
    other_parent_is_spouse: boolean;
};

export type RelativesDraft = {
    father: RelativeDraft | null;
    mother: RelativeDraft | null;
    spouse: RelativeDraft | null;
    children: RelativeDraft[];
    parents_partners: boolean;
};

export type PersonDraft = {
    first_name: string;
    middle_name: string;
    maiden_name: string;
    last_name: string;
    suffix: string;
    birthdate: string;
    gender: '' | Gender;
    civil_status: string;
};

export type HouseholdDraft = {
    barangay_id: string;
    mode: 'new' | 'existing';
    household: HouseholdOption | null;
    street_address: string;
    is_head: boolean;
    household_role: string;
};

export type MatchConfidence = 'high' | 'medium';

export type FamilyMatch = {
    resident: ResidentOption;
    score: number;
    confidence: MatchConfidence;
    reasons: string[];
};

/** A listed record the registrant may claim ("Is this you?"). */
export type ClaimedResident = ResidentOption & {
    listed_by: string | null;
    father_id: number | null;
    mother_id: number | null;
    father_name: string | null;
    mother_name: string | null;
    spouse_name: string | null;
    children_names: string[];
};

export type ClaimMatch = {
    resident: ClaimedResident;
    reasons: string[];
};

/** A relative worked out from the family links, with how they are related. */
export type RelativeMatch = {
    resident: ResidentOption;
    relation: string;
};

export type BloodRelatives = {
    siblings: RelativeMatch[];
    grandparents: RelativeMatch[];
    uncles_aunts: RelativeMatch[];
    cousins: RelativeMatch[];
};

export type RelativeKind =
    | 'sibling'
    | 'grandparent'
    | 'grandchild'
    | 'uncle_aunt'
    | 'nephew_niece'
    | 'cousin';

/** A registered resident who may be this person's brother or sister, grandparent, uncle or aunt, or cousin. */
export type RelativeSuggestion = FamilyMatch & { relation: RelativeKind };

export type FamilySuggestions = {
    claims: ClaimMatch[];
    father: FamilyMatch[];
    mother: FamilyMatch[];
    spouse: FamilyMatch[];
    children: FamilyMatch[];
    relatives: RelativeSuggestion[];
};

export type FeedbackDecision =
    | 'accepted'
    | 'rejected'
    | 'unsure'
    | 'reclassified';

/** The answer to one suggested relative, kept so it can be recorded when the registration is saved. */
export type FeedbackEntry = {
    candidate_id: number;
    role: string;
    decision: FeedbackDecision;
    reclassified_as?: RelativeKind | null;
    score: number;
    confidence: MatchConfidence;
    reasons: string[];
};

/** Everything the stepper remembers, so a reload does not lose progress. */
export type RegistrationDraft = {
    version: number;
    step: number;
    maxStep: number;
    path: FamilyPath | null;
    person: PersonDraft;
    claim: ClaimedResident | null;
    dismissedClaims: number[];
    father: ResidentOption | null;
    mother: ResidentOption | null;
    spouse: ResidentOption | null;
    dependents: ResidentOption[];
    /** Registered relatives the person knows, used to work out their parents. */
    viaRelatives: Record<RelativeKind, ResidentOption[]>;
    /** Residents the person said "no" to, by role (father, mother, spouse, children) or relative kind. */
    rejected: Record<string, number[]>;
    /** Every answer given to a suggestion (yes, no, not sure, related another way). */
    answers: FeedbackEntry[];
    /** Residents the person was not sure about: hidden for now, but not ruled out. */
    unsure: Record<string, number[]>;
    linkLater: boolean;
    relatives: RelativesDraft;
    household: HouseholdDraft;
    consent: boolean;
};
