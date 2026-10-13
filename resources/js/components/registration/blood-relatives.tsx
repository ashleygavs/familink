import { Loader2, Network } from 'lucide-react';
import { ResidentSummary } from '@/components/parent-selector';
import {
    bloodRelativesUrl,
    useBloodRelatives,
} from '@/hooks/use-family-suggestions';
import type { RegistrationDraft, RelativeMatch } from '@/types';

const GROUPS: {
    key: 'siblings' | 'grandparents' | 'uncles_aunts' | 'cousins';
    title: string;
}[] = [
    { key: 'siblings', title: 'Brothers and sisters' },
    { key: 'grandparents', title: 'Grandparents' },
    { key: 'uncles_aunts', title: 'Uncles and aunts' },
    { key: 'cousins', title: 'Cousins' },
];

function RelativeRow({ match }: { match: RelativeMatch }) {
    return (
        <li className="flex flex-wrap items-center gap-3 rounded-xl border-2 border-blue-200 bg-sky-50 p-3 dark:border-sidebar-border dark:bg-sidebar">
            <div className="grid min-w-0 flex-1 text-left">
                <ResidentSummary resident={match.resident} />
            </div>
            <span className="rounded-md bg-blue-600 px-2.5 py-1 text-base font-bold text-white">
                {match.relation}
            </span>
        </li>
    );
}

/**
 * The relatives that follow from the chosen parents. Nothing to do here: it
 * only shows people the records say are already part of the family.
 */
export function BloodRelatives({ draft }: { draft: RegistrationDraft }) {
    const { data, loading } = useBloodRelatives(bloodRelativesUrl(draft));

    if (bloodRelativesUrl(draft) === null) {
        return null;
    }

    const groups = GROUPS.map((group) => ({
        ...group,
        matches: data?.[group.key] ?? [],
    })).filter((group) => group.matches.length > 0);

    return (
        <section className="grid gap-4" aria-live="polite">
            <div>
                <h3 className="flex items-center gap-2 text-xl font-bold">
                    <Network className="size-6 text-blue-600" />
                    Your relatives in the records
                </h3>
                <p className="mt-1 text-base text-muted-foreground">
                    Worked out automatically from your parents. You do not need
                    to do anything here.
                </p>
            </div>

            {loading && (
                <p className="flex items-center gap-2 text-base text-muted-foreground">
                    <Loader2 className="size-5 animate-spin" />
                    Looking up your relatives…
                </p>
            )}

            {!loading && data && groups.length === 0 && (
                <p className="rounded-xl border-2 border-slate-200 p-4 text-base dark:border-border">
                    No other relatives are on record for these parents yet.
                </p>
            )}

            {groups.map((group) => (
                <div key={group.key} className="grid gap-2">
                    <h4 className="text-lg font-bold">{group.title}</h4>
                    <ul className="grid gap-2">
                        {group.matches.map((match) => (
                            <RelativeRow
                                key={`${group.key}-${match.resident.id}`}
                                match={match}
                            />
                        ))}
                    </ul>
                </div>
            ))}
        </section>
    );
}
