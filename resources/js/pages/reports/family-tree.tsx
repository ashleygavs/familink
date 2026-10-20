import { Head, router } from '@inertiajs/react';
import {
    Home,
    List,
    LocateFixed,
    Maximize,
    Network as NetworkIcon,
    Search,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DataSet, Network } from 'vis-network/standalone';
import type { Edge, Node } from 'vis-network/standalone';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { groupRelatives, pathBetween } from '@/lib/family-tree';
import { dashboard, familyTree } from '@/routes';

type TreeNode = {
    id: number;
    name: string;
    gender: string;
    birth_year: number;
    status: string;
    barangay_name: string | null;
    household_code: string | null;
    in_household: boolean;
    is_deceased: boolean;
    /** What this person is to the reference person, like "Second cousin" or "Wife of uncle". */
    relation: string | null;
    /** The heading the person is listed under, such as "Second cousins". */
    relation_group: string | null;
    /** How far away the person is, for ordering a list. */
    relation_rank: number | null;
};

type Reach = 'close' | 'cousins' | 'wide';

const reaches: { value: Reach; label: string }[] = [
    { value: 'close', label: 'Close family' },
    { value: 'cousins', label: '+ First cousins' },
    { value: 'wide', label: '+ Second cousins' },
];

type TreeEdge = { from: number; to: number; kind: 'parent' | 'spouse' };

type Props = {
    household: {
        id: number;
        household_code: string;
        street_address: string;
        barangay_name: string;
        head_name: string | null;
    };
    tree: {
        nodes: TreeNode[];
        edges: TreeEdge[];
        truncated: boolean;
        /** Whether relatives, and not only distant husbands and wives, were left out to keep the tree readable. */
        truncated_relatives: boolean;
        /** The person the relationship labels are given from, usually the head of the household. */
        reference: { id: number; name: string } | null;
        reach: Reach;
    };
};

const colours = {
    Male: { background: '#bfdbfe', border: '#1d4ed8' },
    Female: { background: '#fbcfe8', border: '#be185d' },
    other: { background: '#e2e8f0', border: '#475569' },
};

function nodeColour(gender: string) {
    return gender === 'Male' || gender === 'Female'
        ? colours[gender]
        : colours.other;
}

/**
 * Generation of every person: a child is one row below their parents and a married pair share a row,
 * so spouses sit side by side instead of being stacked like a parent and child.
 */
function generations(
    nodes: TreeNode[],
    edges: TreeEdge[],
): Map<number, number> {
    const level = new Map(nodes.map((node) => [node.id, 0]));
    let changed = true;

    for (let pass = 0; changed && pass <= nodes.length; pass++) {
        changed = false;

        for (const edge of edges) {
            const from = level.get(edge.from) ?? 0;
            const to = level.get(edge.to) ?? 0;

            if (edge.kind === 'parent' && to < from + 1) {
                level.set(edge.to, from + 1);
                changed = true;
            } else if (edge.kind === 'spouse' && from !== to) {
                level.set(edge.from, Math.max(from, to));
                level.set(edge.to, Math.max(from, to));
                changed = true;
            }
        }
    }

    return level;
}

type Union = { id: string; parents: [number, number]; children: number[] };

/**
 * Children with two known parents hang from one junction between those parents, so each child has a single
 * line and lines from different families do not cross. A child with one known parent is joined to them directly.
 */
function unions(edges: TreeEdge[]): { unions: Union[]; single: TreeEdge[] } {
    const parentsOf = new Map<number, number[]>();

    for (const edge of edges) {
        if (edge.kind === 'parent') {
            parentsOf.set(edge.to, [
                ...(parentsOf.get(edge.to) ?? []),
                edge.from,
            ]);
        }
    }

    const byCouple = new Map<string, Union>();
    const single: TreeEdge[] = [];

    for (const [child, parents] of parentsOf) {
        if (parents.length < 2) {
            single.push({ from: parents[0], to: child, kind: 'parent' });

            continue;
        }

        const [a, b] = [...parents].sort((x, y) => x - y);
        const id = `union-${a}-${b}`;
        const union = byCouple.get(id) ?? { id, parents: [a, b], children: [] };

        union.children.push(child);
        byCouple.set(id, union);
    }

    return { unions: [...byCouple.values()], single };
}

const BOX_WIDTH = 160;
const COUPLE_GAP = 80;
const SIBLING_GAP = 40;
const ROW_HEIGHT = 150;

type Unit = {
    people: number[];
    level: number;
    parent: Unit | null;
    children: { unit: Unit; order: number }[];
    spread: number;
    width: number;
};

type Position = { x: number; y: number };

/**
 * Orders each set of partners so everyone sits next to the people they are paired with. Someone with two
 * partners, such as a widower who remarried, ends up between them, so no line runs across another person's box.
 */
function chains(
    partners: Map<number, Set<number>>,
    birth: Map<number, number>,
): number[][] {
    const seen = new Set<number>();
    const result: number[][] = [];

    for (const id of [...partners.keys()].sort((a, b) => a - b)) {
        if (seen.has(id)) {
            continue;
        }

        const component: number[] = [];
        const stack = [id];

        seen.add(id);

        while (stack.length > 0) {
            const current = stack.pop() as number;

            component.push(current);

            for (const next of partners.get(current) ?? []) {
                if (!seen.has(next)) {
                    seen.add(next);
                    stack.push(next);
                }
            }
        }

        // Start from an end of the chain (someone with one partner), the oldest first.
        const ends = component
            .filter((person) => (partners.get(person)?.size ?? 0) <= 1)
            .sort((a, b) => (birth.get(a) ?? 0) - (birth.get(b) ?? 0));
        const ordered: number[] = [];
        const placed = new Set<number>();
        const walk = (person: number): void => {
            placed.add(person);
            ordered.push(person);

            for (const next of [...(partners.get(person) ?? [])].sort(
                (a, b) => a - b,
            )) {
                if (!placed.has(next)) {
                    walk(next);
                }
            }
        };

        walk(ends[0] ?? component[0]);
        result.push(ordered);
    }

    return result;
}

/**
 * Places everyone on a grid. Partners sit side by side in a chain with a junction between each pair, the
 * children of a pair are centred underneath, and each family takes only the width it needs, so nothing
 * overlaps and lines do not cross.
 */
function arrange(
    tree: Props['tree'],
    level: Map<number, number>,
    grouped: ReturnType<typeof unions>,
): Map<number | string, Position> {
    const gender = new Map(tree.nodes.map((node) => [node.id, node.gender]));
    const birth = new Map(tree.nodes.map((node) => [node.id, node.birth_year]));
    const unitOf = new Map<number, Unit>();
    const all: Unit[] = [];

    const start = (people: number[]): void => {
        const unit: Unit = {
            people,
            level: Math.max(...people.map((id) => level.get(id) ?? 0)),
            parent: null,
            children: [],
            spread: 0,
            width: 0,
        };

        people.forEach((id) => unitOf.set(id, unit));
        all.push(unit);
    };

    const partners = new Map<number, Set<number>>();
    const pair = (a: number, b: number): void => {
        partners.set(a, (partners.get(a) ?? new Set<number>()).add(b));
        partners.set(b, (partners.get(b) ?? new Set<number>()).add(a));
    };

    tree.edges
        .filter((edge) => edge.kind === 'spouse')
        .forEach((edge) => pair(edge.from, edge.to));
    grouped.unions.forEach((union) => pair(union.parents[0], union.parents[1]));

    for (const chain of chains(partners, birth)) {
        const flip =
            chain.length === 2 &&
            gender.get(chain[0]) === 'Female' &&
            gender.get(chain[1]) !== 'Female';

        start(flip ? [chain[1], chain[0]] : chain);
    }

    for (const node of tree.nodes) {
        if (!unitOf.has(node.id)) {
            start([node.id]);
        }
    }

    const attach = (parentId: number, childId: number): void => {
        const parent = unitOf.get(parentId);
        const child = unitOf.get(childId);

        if (!parent || !child || child.parent || child === parent) {
            return;
        }

        for (let up: Unit | null = parent; up; up = up.parent) {
            if (up === child) {
                return;
            }
        }

        child.parent = parent;
        parent.children.push({
            unit: child,
            order: parent.people.indexOf(parentId),
        });
    };

    for (const union of grouped.unions) {
        const [first, second] = union.parents;
        const leftmost = Math.min(
            unitOf.get(first)?.people.indexOf(first) ?? 0,
            unitOf.get(second)?.people.indexOf(second) ?? 0,
        );
        const owner = unitOf.get(first)?.people[leftmost] ?? first;

        union.children.forEach((child) => attach(owner, child));
    }

    grouped.single.forEach((edge) => attach(edge.from, edge.to));

    // Children stay in the order of the pair they belong to, left to right along the chain.
    all.forEach((unit) => unit.children.sort((a, b) => a.order - b.order));

    const rowWidth = (unit: Unit): number =>
        unit.people.length * BOX_WIDTH + (unit.people.length - 1) * COUPLE_GAP;

    const measure = (unit: Unit): number => {
        unit.spread =
            unit.children.reduce((sum, child) => sum + measure(child.unit), 0) +
            Math.max(0, unit.children.length - 1) * SIBLING_GAP;
        unit.width = Math.max(rowWidth(unit), unit.spread);

        return unit.width;
    };

    const positions = new Map<number | string, Position>();

    const place = (unit: Unit, left: number): void => {
        const first =
            left + unit.width / 2 - rowWidth(unit) / 2 + BOX_WIDTH / 2;

        unit.people.forEach((id, index) =>
            positions.set(id, {
                x: first + index * (BOX_WIDTH + COUPLE_GAP),
                y: (level.get(id) ?? 0) * ROW_HEIGHT,
            }),
        );

        let childLeft = left + (unit.width - unit.spread) / 2;

        for (const { unit: child } of unit.children) {
            place(child, childLeft);
            childLeft += child.width + SIBLING_GAP;
        }
    };

    let cursor = 0;

    for (const root of all.filter((unit) => unit.parent === null)) {
        const width = measure(root);

        place(root, cursor);
        cursor += width + SIBLING_GAP * 2;
    }

    for (const union of grouped.unions) {
        const a = positions.get(union.parents[0]);
        const b = positions.get(union.parents[1]);

        if (a && b) {
            positions.set(union.id, {
                x: (a.x + b.x) / 2,
                y: (a.y + b.y) / 2,
            });
        }
    }

    return positions;
}

/** Below this zoom a name is too small to read, so the tree opens on the household instead of on everyone. */
const READABLE_SCALE = 0.55;

/** The drawn tree, kept so a person's family line can be lit up without drawing it again. */
type Drawing = {
    nodes: DataSet<Node>;
    edges: DataSet<Edge>;
    personIds: number[];
    junctionIds: string[];
    /** The parent and child pairs each line stands for, to tell which lines are on a path. */
    pairs: Map<number, [number, number][]>;
    colours: Map<number, string>;
    junctionOf: Map<number, string>;
};

/**
 * Lights up the line of people from the reference person to the chosen one and fades everyone else. With no path
 * everything goes back to normal.
 */
function highlight(drawing: Drawing | null, path: number[] | null) {
    if (drawing === null) {
        return;
    }

    const lit = new Set(path ?? []);
    const steps = new Set<string>();

    for (let index = 1; index < (path?.length ?? 0); index++) {
        const [a, b] = [path![index - 1], path![index]];

        steps.add(`${a}-${b}`);
        steps.add(`${b}-${a}`);
    }

    const faded = path !== null && path.length > 1;
    const litLines = new Set<number>();

    for (const [id, pairs] of drawing.pairs) {
        if (pairs.some(([a, b]) => steps.has(`${a}-${b}`))) {
            litLines.add(id);
        }
    }

    const litJunctions = new Set(
        [...litLines].map((id) => drawing.junctionOf.get(id)),
    );

    drawing.nodes.update([
        ...drawing.personIds.map((id) => ({
            id,
            opacity: !faded || lit.has(id) ? 1 : 0.22,
        })),
        ...drawing.junctionIds.map((id) => ({
            id,
            opacity: !faded || litJunctions.has(id) ? 1 : 0.22,
        })),
    ]);
    drawing.edges.update(
        [...drawing.colours].map(([id, colour]) => ({
            id,
            width: faded && litLines.has(id) ? 4 : 1,
            color: {
                color: faded && litLines.has(id) ? '#0f172a' : colour,
                opacity: !faded || litLines.has(id) ? 1 : 0.12,
            },
        })),
    );
}

/** Brings the household and the people closest to it into view at a size that can be read. */
function focusHousehold(instance: Network, tree: Props['tree']) {
    const members = tree.nodes
        .filter((node) => node.in_household)
        .map((node) => node.id);
    const ring = new Set(members);

    for (const edge of tree.edges) {
        if (members.includes(edge.from)) {
            ring.add(edge.to);
        }

        if (members.includes(edge.to)) {
            ring.add(edge.from);
        }
    }

    instance.fit({ nodes: [...ring], maxZoomLevel: 1, animation: false });

    if (instance.getScale() < READABLE_SCALE && ring.size > members.length) {
        instance.fit({ nodes: members, maxZoomLevel: 1, animation: false });
    }
}

/** The relatives of the reference person as a list, grouped from the closest to the furthest. */
function RelativesList({
    tree,
    search,
    onSearch,
    selectedId,
    onSelect,
}: {
    tree: Props['tree'];
    search: string;
    onSearch: (value: string) => void;
    selectedId: number | null;
    onSelect: (id: number) => void;
}) {
    const groups = groupRelatives(
        tree.nodes,
        tree.reference?.id ?? null,
        search,
    );
    const count = groups.reduce((sum, group) => sum + group.people.length, 0);

    return (
        <section className="grid gap-3" aria-label="Relatives list">
            <div className="relative">
                <Search className="pointer-events-none absolute top-1/2 left-3 size-5 -translate-y-1/2 text-muted-foreground" />
                <Input
                    type="search"
                    autoComplete="off"
                    className="pl-10"
                    aria-label="Find a relative"
                    placeholder="Find a name or relationship"
                    value={search}
                    onChange={(event) => onSearch(event.target.value)}
                />
            </div>
            <p className="text-base text-muted-foreground">
                {count} {count === 1 ? 'person' : 'people'}
                {tree.reference
                    ? `, grouped by how they are related to ${tree.reference.name}.`
                    : '.'}
            </p>

            {groups.length === 0 && (
                <p className="rounded-xl border-2 border-slate-200 p-4 text-lg dark:border-border">
                    No one matches.
                </p>
            )}

            {groups.map((group) => (
                <details
                    key={group.name}
                    open={
                        search.trim() !== '' ||
                        group.rank <= 2 ||
                        group.people.length <= 8
                    }
                    className="rounded-xl border-2 border-slate-200 dark:border-border"
                >
                    <summary className="flex cursor-pointer items-center justify-between gap-3 px-4 py-3 text-lg font-bold">
                        {group.name}
                        <span className="rounded-md bg-blue-600 px-2 py-0.5 text-sm font-bold text-white">
                            {group.people.length}
                        </span>
                    </summary>
                    <ul className="border-t-2 border-slate-100 dark:border-border">
                        {group.people.map((person) => (
                            <li
                                key={person.id}
                                className="border-b border-slate-100 last:border-b-0 dark:border-border"
                            >
                                <button
                                    type="button"
                                    onClick={() => onSelect(person.id)}
                                    aria-pressed={selectedId === person.id}
                                    className={`flex w-full flex-wrap items-center justify-between gap-x-3 gap-y-0.5 px-4 py-2.5 text-left hover:bg-sky-50 dark:hover:bg-sidebar ${
                                        selectedId === person.id
                                            ? 'bg-sky-50 dark:bg-sidebar'
                                            : ''
                                    }`}
                                >
                                    <span className="font-semibold">
                                        {person.name}
                                        {person.is_deceased ? ' †' : ''}
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        {person.relation !== group.name &&
                                        person.relation &&
                                        !group.name
                                            .toLowerCase()
                                            .startsWith(
                                                person.relation.toLowerCase(),
                                            )
                                            ? `${person.relation} · `
                                            : ''}
                                        born {person.birth_year}
                                        {person.in_household
                                            ? ' · lives here'
                                            : person.barangay_name
                                              ? ` · ${person.barangay_name}`
                                              : ''}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                </details>
            ))}
        </section>
    );
}

export default function FamilyTree({ household, tree }: Props) {
    const container = useRef<HTMLDivElement>(null);
    const network = useRef<Network | null>(null);
    const drawing = useRef<Drawing | null>(null);
    /** A person to bring into view as soon as the tree has been drawn, such as one chosen in the list. */
    const showNext = useRef<number | null>(null);
    const [view, setView] = useState<'tree' | 'list'>(() =>
        typeof window !== 'undefined' && window.innerWidth < 768
            ? 'list'
            : 'tree',
    );
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const [search, setSearch] = useState('');
    const selected = tree.nodes.find((node) => node.id === selectedId) ?? null;

    useEffect(() => {
        if (view !== 'tree' || container.current === null) {
            return;
        }

        const grouped = unions(tree.edges);
        const married = new Set(
            tree.edges
                .filter((edge) => edge.kind === 'spouse')
                .map((edge) => `union-${edge.from}-${edge.to}`),
        );
        const level = generations(tree.nodes, [
            ...tree.edges,
            ...grouped.unions.map((union): TreeEdge => ({
                from: union.parents[0],
                to: union.parents[1],
                kind: 'spouse',
            })),
        ]);
        const position = arrange(tree, level, grouped);
        const people: Node[] = tree.nodes.map((node) => ({
            id: node.id,
            ...position.get(node.id),
            label: `${node.name}\n${node.birth_year}${node.is_deceased ? ' †' : ''}${node.relation ? `\n${node.relation}` : ''}`,
            shape: 'box',
            widthConstraint: { minimum: BOX_WIDTH, maximum: BOX_WIDTH },
            color: nodeColour(node.gender),
            borderWidth: node.in_household ? 4 : 1,
            shapeProperties: {
                borderDashes: node.status === 'Listed' ? [6, 4] : false,
            },
            font: { size: 16, color: '#0f172a', face: 'sans-serif' },
            margin: { top: 10, right: 12, bottom: 10, left: 12 },
        }));
        const junctions: Node[] = grouped.unions.map((union) => ({
            id: union.id,
            ...position.get(union.id),
            shape: 'dot',
            size: 5,
            fixed: true,
            color: { background: '#64748b', border: '#64748b' },
            label: '',
        }));
        const nodes = new DataSet<Node>([...people, ...junctions]);
        const down = {
            enabled: true,
            type: 'cubicBezier',
            forceDirection: 'vertical',
            roundness: 0.5,
        };
        const grey = '#64748b';
        const pink = '#be185d';
        const lines: {
            line: Edge;
            colour: string;
            pairs: [number, number][];
            junction?: string;
        }[] = [
            ...grouped.unions.flatMap((union) => {
                const colour = married.has(union.id) ? pink : grey;
                const style = married.has(union.id) ? { dashes: true } : {};
                const [first, second] = union.parents;
                // A line to the junction stands for the couple, and for each child of the couple.
                const side = (parent: number) => ({
                    line: {
                        from: parent,
                        to: union.id,
                        ...style,
                        color: { color: colour },
                    },
                    colour,
                    pairs: [
                        [first, second],
                        ...union.children.map((child): [number, number] => [
                            parent,
                            child,
                        ]),
                    ] as [number, number][],
                    junction: union.id,
                });

                return [
                    side(first),
                    side(second),
                    ...union.children.map((child) => ({
                        line: {
                            from: union.id,
                            to: child,
                            arrows: 'to',
                            color: { color: grey },
                            smooth: down,
                        },
                        colour: grey,
                        pairs: [
                            [first, child],
                            [second, child],
                        ] as [number, number][],
                        junction: union.id,
                    })),
                ];
            }),
            ...grouped.single.map((edge) => ({
                line: {
                    from: edge.from,
                    to: edge.to,
                    arrows: 'to',
                    color: { color: grey },
                    smooth: down,
                },
                colour: grey,
                pairs: [[edge.from, edge.to]] as [number, number][],
            })),
            // A married pair with children is drawn through its junction, so this line is only for the others.
            ...tree.edges
                .filter(
                    (edge) =>
                        edge.kind === 'spouse' &&
                        !grouped.unions.some(
                            (union) =>
                                union.parents[0] === edge.from &&
                                union.parents[1] === edge.to,
                        ),
                )
                .map((edge) => ({
                    line: {
                        from: edge.from,
                        to: edge.to,
                        dashes: true,
                        color: { color: pink },
                    },
                    colour: pink,
                    pairs: [[edge.from, edge.to]] as [number, number][],
                })),
        ];
        const items: Edge[] = lines.map((entry, index) => ({
            id: index,
            smooth: false,
            ...entry.line,
        }));
        const edges = new DataSet<Edge>(items);
        const pairs = new Map<number, [number, number][]>();
        const colours = new Map<number, string>();
        const junctionOf = new Map<number, string>();

        lines.forEach((entry, index) => {
            pairs.set(index, entry.pairs);
            colours.set(index, entry.colour);

            if (entry.junction) {
                junctionOf.set(index, entry.junction);
            }
        });
        drawing.current = {
            nodes,
            edges,
            personIds: tree.nodes.map((node) => node.id),
            junctionIds: grouped.unions.map((union) => union.id),
            pairs,
            colours,
            junctionOf,
        };

        const instance = new Network(
            container.current,
            { nodes, edges },
            {
                physics: false,
                interaction: { hover: true, zoomView: true, dragView: true },
            },
        );

        instance.once('afterDrawing', () => {
            const target = showNext.current;

            showNext.current = null;
            instance.fit();

            if (target !== null) {
                instance.focus(target, { scale: 0.9, animation: false });
                instance.selectNodes([target]);
            } else if (instance.getScale() < READABLE_SCALE) {
                focusHousehold(instance, tree);
            }
        });

        instance.on('selectNode', (event: { nodes: (number | string)[] }) => {
            const id = event.nodes[0];

            setSelectedId(typeof id === 'number' ? id : null);
        });
        instance.on('deselectNode', () => setSelectedId(null));
        network.current = instance;

        return () => {
            instance.destroy();
            network.current = null;
            drawing.current = null;
        };
    }, [tree, view]);

    // Light up how the chosen person is related to the reference person.
    useEffect(() => {
        const path =
            tree.reference !== null &&
            selectedId !== null &&
            selectedId !== tree.reference.id
                ? pathBetween(tree.edges, tree.reference.id, selectedId)
                : null;

        highlight(drawing.current, path);
    }, [selectedId, tree, view]);

    function zoom(factor: number) {
        const instance = network.current;

        if (instance) {
            instance.moveTo({
                scale: instance.getScale() * factor,
                animation: true,
            });
        }
    }

    /** Chooses a person, and brings them into view on the tree when it is showing. */
    function choose(id: number) {
        setSelectedId(id);

        if (view === 'tree' && network.current) {
            network.current.selectNodes([id]);
            network.current.focus(id, { scale: 0.9, animation: true });
        }
    }

    function showOnTree(id: number) {
        showNext.current = id;
        setSelectedId(id);
        setView('tree');
    }

    const members = tree.nodes.filter((node) => node.in_household);

    return (
        <>
            <Head title={`Family tree ${household.household_code}`} />
            <div className="mx-auto flex w-full max-w-6xl flex-1 flex-col gap-4 p-4 md:p-6">
                <div>
                    <h1 className="flex items-center gap-3 text-3xl font-bold text-blue-900 dark:text-white">
                        <NetworkIcon className="size-8 text-blue-600" />
                        Family Tree
                    </h1>
                    <p className="mt-1 flex flex-wrap items-center gap-x-3 text-lg text-muted-foreground">
                        <span className="inline-flex items-center gap-1">
                            <Home className="size-5 text-blue-600" />
                            {household.household_code}
                        </span>
                        <span>{household.street_address}</span>
                        <span>Barangay {household.barangay_name}</span>
                        {household.head_name && (
                            <span>Head: {household.head_name}</span>
                        )}
                    </p>
                </div>

                {tree.nodes.length === 0 ? (
                    <p className="rounded-xl border-2 border-slate-200 p-6 text-lg dark:border-border">
                        This household has no residents on record yet.
                    </p>
                ) : (
                    <>
                        <div className="grid gap-2">
                            <p className="text-base font-semibold">
                                How far to show
                            </p>
                            <div
                                role="group"
                                aria-label="How far the family tree reaches"
                                className="flex flex-wrap gap-2"
                            >
                                {reaches.map((option) => (
                                    <Button
                                        key={option.value}
                                        variant={
                                            tree.reach === option.value
                                                ? 'default'
                                                : 'outline'
                                        }
                                        aria-pressed={
                                            tree.reach === option.value
                                        }
                                        onClick={() =>
                                            router.get(
                                                familyTree(
                                                    household.id,
                                                    option.value === 'close'
                                                        ? {}
                                                        : {
                                                              query: {
                                                                  reach: option.value,
                                                              },
                                                          },
                                                ).url,
                                                {},
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        {option.label}
                                    </Button>
                                ))}
                            </div>
                            <p className="text-base text-muted-foreground">
                                {tree.nodes.length} people.{' '}
                                {tree.reach === 'close'
                                    ? 'Parents, grandparents, brothers and sisters, aunts and uncles, and children.'
                                    : tree.reach === 'cousins'
                                      ? 'Adds first cousins. Beyond close family only blood relatives are followed.'
                                      : 'Adds second cousins, who share great-grandparents. Beyond close family only blood relatives are followed.'}
                                {tree.reference &&
                                    ` Each person is labelled by how they are related to ${tree.reference.name}.`}
                            </p>
                        </div>

                        <div
                            role="group"
                            aria-label="How to show the family"
                            className="flex flex-wrap gap-2"
                        >
                            <Button
                                variant={
                                    view === 'tree' ? 'default' : 'outline'
                                }
                                aria-pressed={view === 'tree'}
                                onClick={() => setView('tree')}
                            >
                                <NetworkIcon />
                                Tree
                            </Button>
                            <Button
                                variant={
                                    view === 'list' ? 'default' : 'outline'
                                }
                                aria-pressed={view === 'list'}
                                onClick={() => setView('list')}
                            >
                                <List />
                                Relatives list
                            </Button>
                        </div>

                        {tree.truncated && (
                            <p
                                role="status"
                                className="rounded-xl border-2 border-amber-300 bg-amber-50 p-3 text-base text-amber-950"
                            >
                                {tree.truncated_relatives
                                    ? `This family is large, so some relatives are not shown (${tree.nodes.length} people are).${
                                          tree.reach === 'wide'
                                              ? ''
                                              : ' Choose a shorter reach to see fewer people.'
                                      }`
                                    : 'Some husbands and wives of distant relatives are not shown, to keep the tree readable.'}
                            </p>
                        )}

                        {view === 'tree' ? (
                            <>
                                <div className="flex flex-wrap items-center gap-2">
                                    <Button
                                        variant="outline"
                                        onClick={() => zoom(1.3)}
                                    >
                                        <ZoomIn />
                                        Zoom in
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() => zoom(1 / 1.3)}
                                    >
                                        <ZoomOut />
                                        Zoom out
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            network.current &&
                                            focusHousehold(
                                                network.current,
                                                tree,
                                            )
                                        }
                                    >
                                        <LocateFixed />
                                        Focus on household
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={() =>
                                            network.current?.fit({
                                                animation: true,
                                            })
                                        }
                                    >
                                        <Maximize />
                                        Fit all
                                    </Button>
                                </div>
                                <p className="text-base text-muted-foreground">
                                    Drag to move, scroll to zoom. Click a person
                                    to see how they are related
                                    {tree.reference
                                        ? ` to ${tree.reference.name}`
                                        : ''}
                                    : their family line lights up and everyone
                                    else fades.
                                </p>

                                <div
                                    ref={container}
                                    className="h-[60vh] min-h-96 w-full rounded-xl border-2 border-blue-200 bg-white dark:border-sidebar-border"
                                    role="img"
                                    aria-label={`Family tree of household ${household.household_code} with ${tree.nodes.length} people`}
                                />

                                <ul className="flex flex-wrap gap-x-5 gap-y-2 text-base">
                                    <li className="flex items-center gap-2">
                                        <span className="size-4 rounded border-2 border-blue-700 bg-blue-200" />
                                        Male
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="size-4 rounded border-2 border-pink-700 bg-pink-200" />
                                        Female
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="size-4 rounded border-4 border-slate-700" />
                                        Lives in this household
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="h-0 w-6 border-t-2 border-dashed border-pink-700" />
                                        Married
                                    </li>
                                    <li className="flex items-center gap-2">
                                        <span className="size-4 rounded border-2 border-dashed border-slate-500" />
                                        Listed, not registered yet
                                    </li>
                                    <li>† Deceased</li>
                                </ul>
                            </>
                        ) : (
                            <RelativesList
                                tree={tree}
                                search={search}
                                onSearch={setSearch}
                                selectedId={selectedId}
                                onSelect={choose}
                            />
                        )}

                        {selected && (
                            <section className="grid gap-1 rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-base dark:border-sidebar-border dark:bg-sidebar">
                                <h2 className="text-xl font-bold">
                                    {selected.name}
                                </h2>
                                {tree.reference && (
                                    <p className="font-semibold text-blue-800 dark:text-sky-300">
                                        {selected.id === tree.reference.id
                                            ? 'The labels on this tree are given from this person.'
                                            : selected.relation
                                              ? `${selected.relation} of ${tree.reference.name}`
                                              : `No family link to ${tree.reference.name} is recorded in this tree.`}
                                    </p>
                                )}
                                <p>
                                    {selected.gender} · born{' '}
                                    {selected.birth_year} · {selected.status}
                                    {selected.is_deceased ? ' · deceased' : ''}
                                </p>
                                <p>
                                    {selected.barangay_name
                                        ? `Barangay ${selected.barangay_name}`
                                        : 'No barangay yet'}
                                    {selected.household_code
                                        ? ` · ${selected.household_code}`
                                        : ''}
                                </p>
                                {selected.household_code &&
                                    !selected.in_household && (
                                        <p className="text-muted-foreground">
                                            Lives in another household.
                                        </p>
                                    )}
                                {view === 'list' && (
                                    <Button
                                        variant="outline"
                                        className="mt-2 w-fit"
                                        onClick={() => showOnTree(selected.id)}
                                    >
                                        <NetworkIcon />
                                        Show on the tree
                                    </Button>
                                )}
                            </section>
                        )}

                        <section className="grid gap-2">
                            <h2 className="text-xl font-bold">
                                People in this household ({members.length})
                            </h2>
                            <ul className="grid gap-1 text-base sm:grid-cols-2">
                                {members.map((member) => (
                                    <li key={member.id}>
                                        <button
                                            type="button"
                                            className="text-left underline-offset-2 hover:underline"
                                            onClick={() => choose(member.id)}
                                        >
                                            {member.name}
                                        </button>{' '}
                                        <span className="text-muted-foreground">
                                            ({member.gender},{' '}
                                            {member.birth_year})
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </section>
                    </>
                )}
            </div>
        </>
    );
}

FamilyTree.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: dashboard() },
        { title: 'Family Tree', href: '#' },
    ],
};
