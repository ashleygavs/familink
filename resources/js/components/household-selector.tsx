import { Home, X } from 'lucide-react';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import HouseholdController from '@/actions/App/Http/Controllers/HouseholdController';
import type { HouseholdOption } from '@/types';

function HouseholdSummary({ household }: { household: HouseholdOption }) {
    return (
        <>
            <span className="text-lg font-bold">
                {household.household_code}
            </span>
            <span className="text-base text-muted-foreground">
                {household.street_address}
            </span>
            {household.members_count !== undefined && (
                <span className="text-base text-muted-foreground">
                    {household.head_name
                        ? `Head: ${household.head_name}`
                        : 'No head registered yet'}{' '}
                    · {household.members_count} member
                    {household.members_count === 1 ? '' : 's'}
                </span>
            )}
        </>
    );
}

type Props = {
    selected: HouseholdOption | null;
    onChange: (household: HouseholdOption | null) => void;
    barangayId: number | null;
    error?: string;
};

export function HouseholdSelector({
    selected,
    onChange,
    barangayId,
    error,
}: Props) {
    if (selected) {
        return (
            <div className="grid gap-2">
                <Label>Household to join</Label>
                <div className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-emerald-300 bg-emerald-50 p-3 dark:border-emerald-700 dark:bg-emerald-950">
                    <Home className="size-7 shrink-0 text-emerald-600" />
                    <div className="grid min-w-0 flex-1 text-left">
                        <HouseholdSummary household={selected} />
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
                <InputErrorText message={error} />
            </div>
        );
    }

    return (
        <SearchSelect<HouseholdOption>
            id="household-search"
            label="Find the household"
            icon={Home}
            placeholder="Type a household code, street or head's name"
            endpoint={HouseholdController.search.url()}
            params={{ barangay_id: barangayId }}
            disabled={barangayId === null}
            helper={
                barangayId === null
                    ? 'Choose the barangay first.'
                    : 'Only households in this barangay are shown.'
            }
            error={error}
            renderOption={(household) => (
                <HouseholdSummary household={household} />
            )}
            onSelect={onChange}
        />
    );
}

function InputErrorText({ message }: { message?: string }) {
    return message ? (
        <p className="text-base font-semibold text-red-600">{message}</p>
    ) : null;
}
