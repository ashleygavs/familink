import { Search, UserCheck, X } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import ResidentController from '@/actions/App/Http/Controllers/ResidentController';
import type { ResidentOption } from '@/types';

type Params = Record<string, string | number | boolean | null | undefined>;

export function residentStatusLabel(resident: ResidentOption): string | null {
    if (resident.is_deceased) {
        return 'Deceased';
    }

    if (resident.status === 'Listed') {
        return 'Listed by a relative';
    }

    return resident.status === 'Pending' ? 'Pending approval' : null;
}

export function ResidentSummary({ resident }: { resident: ResidentOption }) {
    const details = [
        resident.barangay_name ? `Barangay ${resident.barangay_name}` : null,
        residentStatusLabel(resident),
    ].filter(Boolean);

    return (
        <>
            <span className="text-lg font-bold">{resident.full_name}</span>
            <span className="text-base text-muted-foreground">
                Born {resident.birthdate_estimated ? 'about ' : ''}
                {resident.birthdate !== null
                    ? formatDate(resident.birthdate)
                    : resident.birth_year}
                {resident.system_id_no !== null &&
                    ` · ID ${resident.system_id_no}`}
            </span>
            {details.length > 0 && (
                <span className="text-base text-muted-foreground">
                    {details.join(' · ')}
                </span>
            )}
        </>
    );
}

type Props = {
    id: string;
    label: string;
    selected: ResidentOption | null;
    onChange: (resident: ResidentOption | null) => void;
    params?: Params;
    icon?: LucideIcon;
    helper?: string;
    error?: string;
    disabled?: boolean;
};

/**
 * Pick one existing resident (father, mother, spouse). Shows the full name,
 * birthdate and System ID so the wrong person is not chosen by mistake.
 */
export function ParentSelector({
    id,
    label,
    selected,
    onChange,
    params,
    icon = Search,
    helper,
    error,
    disabled,
}: Props) {
    if (selected) {
        return (
            <div className="grid gap-2">
                <Label>{label}</Label>
                <div className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-3 dark:border-emerald-700 dark:bg-emerald-950">
                    <UserCheck className="size-7 shrink-0 text-emerald-600" />
                    <div className="grid min-w-0 flex-1 text-left">
                        <ResidentSummary resident={selected} />
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onChange(null)}
                    >
                        <X />
                        Change
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <SearchSelect<ResidentOption>
            id={id}
            label={label}
            icon={icon}
            helper={helper}
            error={error}
            disabled={disabled}
            placeholder="Type a name or System ID"
            endpoint={ResidentController.search.url()}
            params={params}
            renderOption={(resident) => <ResidentSummary resident={resident} />}
            onSelect={onChange}
        />
    );
}
