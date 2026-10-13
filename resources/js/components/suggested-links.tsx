import { router } from '@inertiajs/react';
import { Link2, Sparkles } from 'lucide-react';
import { useState } from 'react';
import ApprovalController from '@/actions/App/Http/Controllers/ApprovalController';
import { MatchCard } from '@/components/registration/match-card';
import type { FamilyMatch, PendingResident } from '@/types';

type Group = {
    label: string;
    field: 'father_id' | 'mother_id' | 'spouse_id' | 'dependent_ids';
    matches: FamilyMatch[];
};

/**
 * Suggested family links for a pending resident. An admin confirms each one
 * with a single click while reviewing the registration.
 */
export function SuggestedLinks({ resident }: { resident: PendingResident }) {
    const [busy, setBusy] = useState(false);
    const { suggestions } = resident;

    const allGroups: Group[] = [
        {
            label: 'Possible father',
            field: 'father_id',
            matches: suggestions.father,
        },
        {
            label: 'Possible mother',
            field: 'mother_id',
            matches: suggestions.mother,
        },
        {
            label: 'Possible spouse',
            field: 'spouse_id',
            matches: suggestions.spouse,
        },
        {
            label: 'Possible children',
            field: 'dependent_ids',
            matches: suggestions.children,
        },
    ];
    const groups = allGroups.filter((group) => group.matches.length > 0);

    if (groups.length === 0) {
        return null;
    }

    function link(field: Group['field'], id: number) {
        router.post(
            ApprovalController.links.url(resident.id),
            { [field]: field === 'dependent_ids' ? [id] : id },
            {
                preserveScroll: true,
                onStart: () => setBusy(true),
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <section
            className="grid gap-3 rounded-xl border-2 border-blue-200 bg-sky-50 p-4 dark:border-sidebar-border dark:bg-sidebar"
            aria-busy={busy}
        >
            <h3 className="flex items-center gap-2 text-lg font-bold">
                <Sparkles className="size-5 text-blue-600" />
                Suggested family links
            </h3>
            {groups.map((group) => (
                <div key={group.field} className="grid gap-2">
                    <h4 className="text-base font-semibold">{group.label}</h4>
                    <ul className="grid gap-2">
                        {group.matches.map((match) => (
                            <MatchCard
                                key={match.resident.id}
                                match={match}
                                actionLabel={busy ? 'Saving…' : 'Confirm link'}
                                onLink={() =>
                                    link(group.field, match.resident.id)
                                }
                            />
                        ))}
                    </ul>
                </div>
            ))}
            <p className="flex items-center gap-2 text-base text-muted-foreground">
                <Link2 className="size-5" />
                Only confirm a link when you are sure these two people are
                related.
            </p>
        </section>
    );
}
