import { Head, router } from '@inertiajs/react';
import { ArrowLeftRight, GitCompareArrows, Search } from 'lucide-react';
import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { relationship } from '@/routes/reports';
import { search as searchResidents } from '@/routes/reports/relationship';

type Person = {
    id: number;
    name: string;
    birth_year: number;
    household_code: string | null;
    barangay_name: string | null;
};

type Props = {
    first: Person | null;
    second: Person | null;
    result: {
        related: boolean;
        second_is: string | null;
        first_is: string | null;
    } | null;
};

function describe(person: Person) {
    return [
        `born ${person.birth_year}`,
        person.household_code,
        person.barangay_name,
    ]
        .filter(Boolean)
        .join(' · ');
}

function PersonPicker({
    id,
    label,
    person,
    onPick,
    onClear,
}: {
    id: string;
    label: string;
    person: Person | null;
    onPick: (person: Person) => void;
    onClear: () => void;
}) {
    const [term, setTerm] = useState('');
    const [found, setFound] = useState<Person[] | null>(null);

    useEffect(() => {
        if (term.trim().length < 2) {
            // eslint-disable-next-line react-hooks/set-state-in-effect
            setFound(null);

            return;
        }

        const controller = new AbortController();
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    searchResidents.url({ query: { q: term.trim() } }),
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );

                if (response.ok) {
                    setFound((await response.json()).data as Person[]);
                }
            } catch {
                // A search that was replaced by a newer one, or lost its connection, just shows nothing new.
            }
        }, 250);

        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [term]);

    if (person !== null) {
        return (
            <div className="grid gap-2">
                <Label>{label}</Label>
                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border-2 border-blue-200 bg-blue-50 p-4 dark:border-border dark:bg-card">
                    <div>
                        <p className="text-xl font-bold">{person.name}</p>
                        <p className="text-base text-muted-foreground">
                            {describe(person)}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        className="min-h-12 text-lg"
                        onClick={onClear}
                    >
                        Change
                    </Button>
                </div>
            </div>
        );
    }

    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    id={id}
                    value={term}
                    onChange={(event) => setTerm(event.target.value)}
                    placeholder="Type a name or resident ID"
                    className="pl-10"
                    autoComplete="off"
                />
            </div>
            {found !== null && found.length === 0 && (
                <p className="text-base text-muted-foreground">
                    No one found. Check the spelling or try the resident ID.
                </p>
            )}
            {found !== null && found.length > 0 && (
                <ul className="grid gap-2">
                    {found.map((candidate) => (
                        <li key={candidate.id}>
                            <button
                                type="button"
                                onClick={() => onPick(candidate)}
                                className="min-h-12 w-full rounded-xl border-2 border-blue-100 p-3 text-left hover:bg-blue-50 dark:border-border dark:hover:bg-muted"
                            >
                                <span className="block text-lg font-semibold">
                                    {candidate.name}
                                </span>
                                <span className="block text-base text-muted-foreground">
                                    {describe(candidate)}
                                </span>
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

export default function Relationship({ first, second, result }: Props) {
    function go(next: { first?: number | null; second?: number | null }) {
        const merged = {
            first: first?.id,
            second: second?.id,
            ...next,
        };

        router.get(
            relationship().url,
            {
                first: merged.first ?? undefined,
                second: merged.second ?? undefined,
            },
            { preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Relationship Finder" />
            <div className="mx-auto flex w-full max-w-3xl flex-1 flex-col gap-6 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <GitCompareArrows className="size-8 text-blue-600" />
                        How are they related?
                    </h1>
                    <p className="mt-1 text-lg text-muted-foreground">
                        Choose two people. It follows their parents and
                        marriages, even across barangays, and says what they are
                        to each other.
                    </p>
                </div>

                <PersonPicker
                    id="relationship-first"
                    label="First person"
                    person={first}
                    onPick={(person) => go({ first: person.id })}
                    onClear={() => go({ first: null })}
                />
                <PersonPicker
                    id="relationship-second"
                    label="Second person"
                    person={second}
                    onPick={(person) => go({ second: person.id })}
                    onClear={() => go({ second: null })}
                />

                {first !== null && second !== null && (
                    <div>
                        <Button
                            variant="outline"
                            className="min-h-12 text-lg"
                            onClick={() =>
                                go({ first: second.id, second: first.id })
                            }
                        >
                            <ArrowLeftRight className="size-5" />
                            Swap the two people
                        </Button>
                    </div>
                )}

                {result !== null &&
                    (result.related ? (
                        <div className="grid gap-2 rounded-xl border-2 border-emerald-200 bg-emerald-50 p-5 text-xl text-emerald-950 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-100">
                            <p className="font-bold">{result.second_is}</p>
                            <p>{result.first_is}</p>
                        </div>
                    ) : (
                        <div className="rounded-xl border-2 border-amber-300 bg-amber-50 p-5 text-lg text-amber-950 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                            <p className="font-bold">
                                No family link was found between {first?.name}{' '}
                                and {second?.name}.
                            </p>
                            <p>
                                They may be unrelated, or a parent or marriage
                                that connects them is not recorded yet.
                            </p>
                        </div>
                    ))}
            </div>
        </>
    );
}

Relationship.layout = {
    breadcrumbs: [
        {
            title: 'Relationship Finder',
            href: relationship(),
        },
    ],
};
