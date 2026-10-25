import type { FamilySuggestions } from './registration';

export type HouseholdSummary = {
    id: number;
    household_code: string;
    street_address: string;
    barangay_id: number;
    head_name?: string | null;
};

export type ResidentOption = {
    id: number;
    full_name: string;
    /** Only given for residents of the viewer's own barangays. */
    system_id_no: string | null;
    birthdate: string | null;
    birth_year: number;
    gender: 'Male' | 'Female';
    status: string;
    birthdate_estimated: boolean;
    is_deceased: boolean;
    barangay_id: number | null;
    barangay_name: string | null;
    household: HouseholdSummary | null;
};

export type HouseholdOption = HouseholdSummary & {
    head_name?: string | null;
    members_count?: number;
};

export type RegisteredResident = {
    id: number;
    full_name: string;
    system_id_no: string;
    household_code: string;
    qr_code_hash: string;
};

export type PendingResident = {
    id: number;
    full_name: string;
    system_id_no: string;
    birthdate: string;
    gender: string;
    civil_status: string;
    household_role: string | null;
    barangay_name: string | null;
    household_id: number | null;
    household_code: string | null;
    father_name: string | null;
    mother_name: string | null;
    spouse_name: string | null;
    children: { id: number; full_name: string }[];
    registered_at: string | null;
    registered_by: string | null;
    declared_relatives: {
        id: number;
        relation: string;
        name: string;
        system_id_no: string;
    }[];
    suggestions: FamilySuggestions;
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};
