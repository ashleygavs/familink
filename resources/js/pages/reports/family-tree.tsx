import { Head } from '@inertiajs/react';
import {
    Home,
    Maximize,
    Network as NetworkIcon,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { DataSet, Network } from 'vis-network/standalone';
import { Button } from '@/components/ui/button';
import { dashboard } from '@/routes';

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
};

type TreeEdge = { from: number; to: number; kind: 'parent' | 'spouse' };

type Props = {
    household: {
        id: number;
        household_code: string;
        street_address: string;
        barangay_name: string;
        head_name: string | null;
    };
    tree: { nodes: TreeNode[]; edges: TreeEdge[]; truncated: boolean };
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
    children: Unit[];
    spread: number;
    width: number;
};

type Position = { x: number; y: number };

/**
 * Places everyone on a grid. A couple sits side by side with their junction between them, their children are
 * centred underneath, and each family takes only the width it needs, so nothing overlaps or crosses.
 */
function arrange(
    tree: Props['tree'],
    level: Map<number, number>,
    grouped: ReturnType<typeof unions>,
): Map<number | string, Position> {
    const gender = new Map(tree.nodes.map((node) => [node.id, node.gender]));
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

    const couples: [number, number][] = [
        ...tree.edges
            .filter((edge) => edge.kind === 'spouse')
            .map((edge): [number, number] => [edge.from, edge.to]),
        ...grouped.unions.map((union) => union.parents),
    ];

    for (const [a, b] of couples) {
        if (!unitOf.has(a) && !unitOf.has(b)) {
            start(
                gender.get(a) === 'Female' && gender.get(b) !== 'Female'
                    ? [b, a]
                    : [a, b],
            );
        }
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
        parent.children.push(child);
    };

    for (const union of grouped.unions) {
        union.children.forEach((child) => attach(union.parents[0], child));
    }

    grouped.single.forEach((edge) => attach(edge.from, edge.to));

    const rowWidth = (unit: Unit): number =>
        unit.people.length * BOX_WIDTH + (unit.people.length - 1) * COUPLE_GAP;

    const measure = (unit: Unit): number => {
        unit.spread =
            unit.children.reduce((sum, child) => sum + measure(child), 0) +
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

        for (const child of unit.children) {
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

export default function FamilyTree({ household, tree }: Props) {
    const container = useRef<HTMLDivElement>(null);
    const network = useRef<Network | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = tree.nodes.find((node) => node.id === selectedId) ?? null;

    useEffect(() => {
        if (container.current === null) {
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
        const people = tree.nodes.map((node) => ({
            id: node.id,
            ...position.get(node.id),
            label: `${node.name}\n${node.birth_year}${node.is_deceased ? ' †' : ''}`,
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
        const junctions = grouped.unions.map((union) => ({
            id: union.id,
            ...position.get(union.id),
            shape: 'dot',
            size: 5,
            fixed: true,
            color: { background: '#64748b', border: '#64748b' },
            label: '',
        }));
        const nodes = new DataSet([...people, ...junctions]);
        const down = {
            enabled: true,
            type: 'cubicBezier',
            forceDirection: 'vertical',
            roundness: 0.5,
        };
        const links = [
            ...grouped.unions.flatMap((union) => {
                const look = married.has(union.id)
                    ? { dashes: true, color: { color: '#be185d' } }
                    : { color: { color: '#64748b' } };

                return [
                    { from: union.parents[0], to: union.id, ...look },
                    { from: union.parents[1], to: union.id, ...look },
                    ...union.children.map((child) => ({
                        from: union.id,
                        to: child,
                        arrows: 'to',
                        color: { color: '#64748b' },
                        smooth: down,
                    })),
                ];
            }),
            ...grouped.single.map((edge) => ({
                from: edge.from,
                to: edge.to,
                arrows: 'to',
                color: { color: '#64748b' },
                smooth: down,
            })),
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
                    from: edge.from,
                    to: edge.to,
                    dashes: true,
                    color: { color: '#be185d' },
                })),
        ].map((link, index) => ({ id: index, smooth: false, ...link }));
        const edges = new DataSet(links);

        const instance = new Network(
            container.current,
            { nodes, edges },
            {
                physics: false,
                interaction: { hover: true, zoomView: true, dragView: true },
            },
        );

        instance.once('afterDrawing', () => instance.fit());

        instance.on('selectNode', (event: { nodes: (number | string)[] }) => {
            const id = event.nodes[0];

            setSelectedId(typeof id === 'number' ? id : null);
        });
        instance.on('deselectNode', () => setSelectedId(null));
        network.current = instance;

        return () => {
            instance.destroy();
            network.current = null;
        };
    }, [tree]);

    function zoom(factor: number) {
        const instance = network.current;

        if (instance) {
            instance.moveTo({
                scale: instance.getScale() * factor,
                animation: true,
            });
        }
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
                        <div className="flex flex-wrap items-center gap-2">
                            <Button variant="outline" onClick={() => zoom(1.3)}>
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
                                    network.current?.fit({ animation: true })
                                }
                            >
                                <Maximize />
                                Fit all
                            </Button>
                            <span className="text-base text-muted-foreground">
                                Drag to move, scroll to zoom, click a person for
                                details.
                            </span>
                        </div>

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

                        {tree.truncated && (
                            <p className="rounded-xl border-2 border-amber-300 bg-amber-50 p-3 text-base text-amber-950">
                                This family is large, so only the closest
                                relatives are shown.
                            </p>
                        )}

                        {selected && (
                            <section className="grid gap-1 rounded-xl border-2 border-blue-200 bg-sky-50 p-4 text-base dark:border-sidebar-border dark:bg-sidebar">
                                <h2 className="text-xl font-bold">
                                    {selected.name}
                                </h2>
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
                                            onClick={() => {
                                                setSelectedId(member.id);
                                                network.current?.selectNodes([
                                                    member.id,
                                                ]);
                                                network.current?.focus(
                                                    member.id,
                                                    {
                                                        scale: 1,
                                                        animation: true,
                                                    },
                                                );
                                            }}
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
