import { Baby, X } from 'lucide-react';
import { ResidentSummary } from '@/components/parent-selector';
import { SearchSelect } from '@/components/search-select';
import { Button } from '@/components/ui/button';
import ResidentController from '@/actions/App/Http/Controllers/ResidentController';
import type { ResidentOption } from '@/types';

type Props = {
    selected: ResidentOption[];
    onChange: (residents: ResidentOption[]) => void;
    params: Record<string, string | number | boolean | null | undefined>;
    excludeIds: number[];
    disabledReason: string | null;
    error?: string;
};

/**
 * Link already registered residents as this person's children. They are moved
 * into this person's household when the form is saved.
 */
export function DependentsSelector({
    selected,
    onChange,
    params,
    excludeIds,
    disabledReason,
    error,
}: Props) {
    return (
        <div className="grid gap-3">
            <SearchSelect<ResidentOption>
                id="dependents"
                label="Children already registered (optional)"
                icon={Baby}
                placeholder="Type a child's name or System ID"
                endpoint={ResidentController.search.url()}
                params={params}
                disabled={disabledReason !== null}
                excludeIds={[
                    ...excludeIds,
                    ...selected.map((child) => child.id),
                ]}
                helper={
                    disabledReason ??
                    'Use this if the children registered before their parent. They will be linked to this person and moved into the same household.'
                }
                error={error}
                emptyText="No matching child without this parent on record."
                renderOption={(child) => <ResidentSummary resident={child} />}
                onSelect={(child) => onChange([...selected, child])}
            />

            {selected.length > 0 && (
                <ul className="grid gap-2">
                    {selected.map((child) => (
                        <li
                            key={child.id}
                            className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-blue-200 bg-sky-50 p-3 dark:border-sidebar-border dark:bg-sidebar"
                        >
                            <Baby className="size-7 shrink-0 text-blue-600" />
                            <div className="grid min-w-0 flex-1 text-left">
                                <ResidentSummary resident={child} />
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    onChange(
                                        selected.filter(
                                            (item) => item.id !== child.id,
                                        ),
                                    )
                                }
                            >
                                <X />
                                Remove
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
