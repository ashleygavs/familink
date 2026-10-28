import { DataSet, Network } from 'vis-network/standalone';
import type { Edge, Node } from 'vis-network/standalone';

/** The words printed above and below the drawing, so a picture of the tree can be understood on its own. */
export type TreeImageText = {
    title: string;
    details: string[];
    legend: string[];
};

const MARGIN = 40;
const MIN_WIDTH = 900;
/** The longest a side of the picture may be, so a very large family still makes a picture the browser can handle. */
const LONGEST_SIDE = 4000;
const MAX_PIXELS = 40_000_000;

/**
 * Draws the whole tree at once, however large, onto a white page with a title above it and a key below it.
 * The tree is drawn a second time out of sight, so the picture does not depend on how far the person has
 * zoomed or moved the one on the screen.
 */
export function renderTreeImage(
    nodes: Node[],
    edges: Edge[],
    boxWidth: number,
    text: TreeImageText,
): HTMLCanvasElement {
    const xs = nodes.map((node) => node.x ?? 0);
    const ys = nodes.map((node) => node.y ?? 0);
    const left = Math.min(...xs) - boxWidth / 2 - MARGIN;
    const top = Math.min(...ys) - 60 - MARGIN;
    const width = Math.max(...xs) + boxWidth / 2 + MARGIN - left;
    const height = Math.max(...ys) + 60 + MARGIN - top;

    const ratio = Math.min(window.devicePixelRatio || 1, 2);
    const scale = Math.min(
        1,
        LONGEST_SIDE / Math.max(width, height),
        Math.sqrt(MAX_PIXELS / (width * height * ratio * ratio)),
    );
    const treeWidth = Math.ceil(width * scale);
    const treeHeight = Math.ceil(height * scale);

    const stage = document.createElement('div');

    stage.style.cssText = `position:fixed;left:-100000px;top:0;width:${treeWidth}px;height:${treeHeight}px;`;
    document.body.appendChild(stage);

    const network = new Network(
        stage,
        { nodes: new DataSet(nodes), edges: new DataSet(edges) },
        {
            physics: false,
            interaction: { dragView: false, zoomView: false },
        },
    );

    try {
        network.moveTo({
            position: { x: left + width / 2, y: top + height / 2 },
            scale,
            animation: false,
        });
        network.redraw();

        const source = stage.querySelector('canvas');

        if (source === null) {
            throw new Error('The tree could not be drawn.');
        }

        const pageWidth = Math.max(treeWidth, MIN_WIDTH);
        const above = 24 + 34 + text.details.length * 24 + 16;
        const below = 16 + text.legend.length * 22 + 16;
        const page = document.createElement('canvas');

        page.width = Math.ceil(pageWidth * ratio);
        page.height = Math.ceil((above + treeHeight + below) * ratio);

        const context = page.getContext('2d');

        if (context === null) {
            throw new Error('The picture could not be made.');
        }

        context.scale(ratio, ratio);
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, pageWidth, above + treeHeight + below);
        context.fillStyle = '#0f172a';
        context.textBaseline = 'top';
        context.font = 'bold 28px sans-serif';
        context.fillText(text.title, MARGIN, 24);
        context.font = '16px sans-serif';
        context.fillStyle = '#334155';
        text.details.forEach((line, index) =>
            context.fillText(line, MARGIN, 24 + 34 + index * 24),
        );
        context.drawImage(
            source,
            (pageWidth - treeWidth) / 2,
            above,
            treeWidth,
            treeHeight,
        );
        context.font = '14px sans-serif';
        context.fillStyle = '#475569';
        text.legend.forEach((line, index) =>
            context.fillText(
                line,
                MARGIN,
                above + treeHeight + 16 + index * 22,
            ),
        );

        return page;
    } finally {
        network.destroy();
        stage.remove();
    }
}
