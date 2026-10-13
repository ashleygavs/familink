import { Link2 } from 'lucide-react';
import { ResidentSummary } from '@/components/parent-selector';
import { Button } from '@/components/ui/button';
import type { FamilyMatch } from '@/types';

/**
 * A suggested relative with the reasons the system thinks they are related.
 */
export function MatchCard({
    match,
    actionLabel,
    onLink,
    note,
}: {
    match: FamilyMatch;
    actionLabel: string;
    onLink: () => void;
    note?: string;
}) {
    const strong = match.confidence === 'high';

    return (
        <li
            className={`flex flex-wrap items-center gap-3 rounded-xl border-2 p-4 ${
                strong
                    ? 'border-emerald-300 bg-emerald-50 dark:border-emerald-700 dark:bg-emerald-950'
                    : 'border-blue-200 bg-sky-50 dark:border-sidebar-border dark:bg-sidebar'
            }`}
        >
            <div className="grid min-w-0 flex-1 gap-1 text-left">
                <span
                    className={`w-fit rounded-md px-2 py-0.5 text-sm font-bold ${
                        strong
                            ? 'bg-emerald-600 text-white'
                            : 'bg-blue-600 text-white'
                    }`}
                >
                    {strong ? 'Strong match' : 'Possible match'}
                </span>
                {note && <span className="text-lg font-bold">{note}</span>}
                <ResidentSummary resident={match.resident} />
                <span className="text-base">
                    <span className="font-semibold">Why: </span>
                    {match.reasons.join(' · ')}
                </span>
            </div>
            <Button type="button" onClick={onLink}>
                <Link2 />
                {actionLabel}
            </Button>
        </li>
    );
}
