export type FamilyEdge = {
    from: number;
    to: number;
    kind: 'parent' | 'spouse';
};

/**
 * The shortest chain of people between two people along parent and spouse lines, or null when the two are not
 * connected in the tree. Between cousins this walks up to the shared grandparent and down again.
 */
export function pathBetween(
    edges: FamilyEdge[],
    from: number,
    to: number,
): number[] | null {
    if (from === to) {
        return [from];
    }

    const neighbours = new Map<number, number[]>();

    for (const edge of edges) {
        neighbours.set(edge.from, [
            ...(neighbours.get(edge.from) ?? []),
            edge.to,
        ]);
        neighbours.set(edge.to, [
            ...(neighbours.get(edge.to) ?? []),
            edge.from,
        ]);
    }

    const cameFrom = new Map<number, number>([[from, from]]);
    const queue = [from];

    for (let index = 0; index < queue.length; index++) {
        const current = queue[index];

        for (const next of neighbours.get(current) ?? []) {
            if (cameFrom.has(next)) {
                continue;
            }

            cameFrom.set(next, current);

            if (next === to) {
                const path = [to];

                while (path[0] !== from) {
                    path.unshift(cameFrom.get(path[0]) as number);
                }

                return path;
            }

            queue.push(next);
        }
    }

    return null;
}

type Relative = {
    id: number;
    name: string;
    birth_year: number;
    relation: string | null;
    relation_group: string | null;
    relation_rank: number | null;
};

export type RelativeGroup<T extends Relative> = {
    name: string;
    /** How far away the group is, for ordering. */
    rank: number;
    people: T[];
};

/** People with no recorded link to the reference person are listed last, under this heading. */
export const UNLINKED_GROUP = 'Other people in this tree';

const MARRIAGE_GROUP = 'Related by marriage';

/** The usual order of the closest groups, which are all about the same distance away. */
const CLOSEST_FIRST = [
    'Husband or wife',
    'Parents',
    'Children',
    'Brothers and sisters',
    'Grandparents',
    'Grandchildren',
    'Aunts and uncles',
    'Nieces and nephews',
];

const order = (name: string) => {
    const index = CLOSEST_FIRST.indexOf(name);

    return index === -1 ? CLOSEST_FIRST.length : index;
};

/**
 * Groups the people of a tree under headings such as "Parents" and "Second cousins", the closest first, with the
 * people related only through marriage after the blood relatives. A search narrows the list to matching names
 * and relationships.
 */
export function groupRelatives<T extends Relative>(
    people: T[],
    referenceId: number | null,
    search = '',
): RelativeGroup<T>[] {
    const term = search.trim().toLowerCase();
    const groups = new Map<string, RelativeGroup<T>>();

    for (const person of people) {
        if (person.id === referenceId) {
            continue;
        }

        if (
            term !== '' &&
            !person.name.toLowerCase().includes(term) &&
            !(person.relation ?? '').toLowerCase().includes(term)
        ) {
            continue;
        }

        const name = person.relation_group ?? UNLINKED_GROUP;
        const rank =
            person.relation_group === null
                ? 1000
                : person.relation_group === MARRIAGE_GROUP
                  ? 500
                  : (person.relation_rank ?? 100);
        const group = groups.get(name) ?? { name, rank, people: [] };

        group.people.push(person);
        groups.set(name, group);
    }

    return [...groups.values()]
        .map((group) => ({
            ...group,
            people: [...group.people].sort(
                (a, b) =>
                    a.birth_year - b.birth_year || a.name.localeCompare(b.name),
            ),
        }))
        .sort(
            (a, b) =>
                a.rank - b.rank ||
                order(a.name) - order(b.name) ||
                a.name.localeCompare(b.name),
        );
}
