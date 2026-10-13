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

export default function FamilyTree({ household, tree }: Props) {
    const container = useRef<HTMLDivElement>(null);
    const network = useRef<Network | null>(null);
    const [selectedId, setSelectedId] = useState<number | null>(null);
    const selected = tree.nodes.find((node) => node.id === selectedId) ?? null;

    useEffect(() => {
        if (container.current === null) {
            return;
        }

        const level = generations(tree.nodes, tree.edges);
        const nodes = new DataSet(
            tree.nodes.map((node) => ({
                id: node.id,
                level: level.get(node.id) ?? 0,
                label: `${node.name}\n${node.birth_year}${node.is_deceased ? ' †' : ''}`,
                shape: 'box',
                color: nodeColour(node.gender),
                borderWidth: node.in_household ? 4 : 1,
                shapeProperties: {
                    borderDashes: node.status === 'Listed' ? [6, 4] : false,
                },
                font: { size: 16, color: '#0f172a', face: 'sans-serif' },
                margin: { top: 10, right: 12, bottom: 10, left: 12 },
            })),
        );
        const edges = new DataSet(
            tree.edges.map((edge, index) =>
                edge.kind === 'parent'
                    ? {
                          id: index,
                          from: edge.from,
                          to: edge.to,
                          arrows: 'to',
                          color: { color: '#64748b' },
                      }
                    : {
                          id: index,
                          from: edge.from,
                          to: edge.to,
                          dashes: true,
                          color: { color: '#be185d' },
                          smooth: false,
                      },
            ),
        );

        const instance = new Network(
            container.current,
            { nodes, edges },
            {
                layout: {
                    hierarchical: {
                        direction: 'UD',
                        sortMethod: 'directed',
                        levelSeparation: 130,
                        nodeSpacing: 270,
                    },
                },
                physics: false,
                interaction: { hover: true, zoomView: true, dragView: true },
            },
        );

        instance.on('selectNode', (event: { nodes: number[] }) =>
            setSelectedId(event.nodes[0] ?? null),
        );
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
