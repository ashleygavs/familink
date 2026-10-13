import { Check, HelpCircle, Network, X } from 'lucide-react';
import { ResidentSummary } from '@/components/parent-selector';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { FamilyMatch, RelativeKind } from '@/types';

/**
 * A suggested relative with the reasons the system thinks they are related.
 * The person answers with a check (yes, link them), an X (no, not them) or
 * "Not sure". Someone who is not the suggested relative may still be related
 * another way. Without the answer handlers only the check is shown.
 */
export function MatchCard({
    match,
    actionLabel,
    onLink,
    onReject,
    onUnsure,
    relatedOptions,
    onRelated,
    note,
}: {
    match: FamilyMatch;
    actionLabel: string;
    onLink: () => void;
    onReject?: () => void;
    onUnsure?: () => void;
    relatedOptions?: { kind: RelativeKind; label: string }[];
    onRelated?: (kind: RelativeKind) => void;
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
            <div className="flex w-full flex-col gap-2 sm:w-60">
                <Button type="button" onClick={onLink}>
                    <Check />
                    {actionLabel}
                </Button>
                {(onReject || onUnsure) && (
                    <div className="grid grid-cols-2 gap-2">
                        {onReject && (
                            <Button
                                type="button"
                                variant="outline"
                                className="border-red-300 text-red-700 hover:bg-red-50 hover:text-red-800 dark:text-red-300"
                                onClick={onReject}
                            >
                                <X />
                                No
                            </Button>
                        )}
                        {onUnsure && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={onUnsure}
                            >
                                <HelpCircle />
                                Not sure
                            </Button>
                        )}
                    </div>
                )}
                {relatedOptions && onRelated && (
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <Button
                                type="button"
                                variant="ghost"
                                className="justify-start"
                            >
                                <Network />
                                Related another way?
                            </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="start" className="w-64">
                            <DropdownMenuLabel className="text-base">
                                This person is my…
                            </DropdownMenuLabel>
                            {relatedOptions.map((option) => (
                                <DropdownMenuItem
                                    key={option.kind}
                                    className="min-h-12 text-base"
                                    onSelect={() => onRelated(option.kind)}
                                >
                                    {option.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuContent>
                    </DropdownMenu>
                )}
            </div>
        </li>
    );
}
