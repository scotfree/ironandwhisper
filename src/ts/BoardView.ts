/**
 * The board: towns laid out from the map's coordinates, edges drawn between
 * them, and each town showing what the viewing player is entitled to see.
 *
 * This class only draws. It never decides what is legal and never talks to the
 * server — the state classes do that and tell it what to highlight.
 */

const CELL = 150;
const PADDING = 70;

export class BoardView {
    private towns: Record<string, TownView>;
    private clickHandler: (townId: string) => void = () => {};
    private dropHandler: ((townId: string, cardId: number) => void) | null = null;

    /** Extra text shown on a town while a turn is being staged. */
    private pending: Record<string, string> = {};

    /** Signed troop changes being staged, shown on the troop badge as 2+1. */
    private troopDelta: Record<string, number> = {};

    constructor(
        private container: HTMLElement,
        private scenario: ScenarioView,
        towns: Record<string, TownView>,
        private viewerSide: Side | null,
    ) {
        this.towns = towns;
    }

    // -- building -----------------------------------------------------------

    render(): void {
        const definitions = Object.values(this.scenario.towns);
        const width = Math.max(...definitions.map(t => t.x)) * CELL + PADDING * 2;
        const height = Math.max(...definitions.map(t => t.y)) * CELL + PADDING * 2;

        this.container.innerHTML = `
            <div id="iaw-board" style="width:${width}px;height:${height}px">
                ${this.edgesSvg(width, height)}
                ${definitions.map(town => this.townHtml(town)).join('')}
            </div>
        `;

        definitions.forEach(town => {
            const element = document.getElementById(this.townElementId(town.id));
            if (!element) {
                return;
            }

            element.addEventListener('click', () => this.clickHandler(town.id));

            // Cards can be dragged onto a town as well as clicked into one.
            // Dragging is what people expect of a hand; clicking is what works
            // on a touchscreen, so both are supported.
            element.addEventListener('dragover', event => {
                if (this.dropHandler && element.classList.contains('selectable')) {
                    event.preventDefault();
                    element.classList.add('drag-over');
                }
            });
            element.addEventListener('dragleave', () => element.classList.remove('drag-over'));
            element.addEventListener('drop', event => {
                element.classList.remove('drag-over');
                if (!this.dropHandler || !element.classList.contains('selectable')) {
                    return;
                }
                event.preventDefault();
                const cardId = Number((event as DragEvent).dataTransfer?.getData('text/plain'));
                if (!Number.isNaN(cardId)) {
                    this.dropHandler(town.id, cardId);
                }
            });
        });

        this.updateAll();
    }

    private edgesSvg(width: number, height: number): string {
        const lines = this.scenario.edges.map(([a, b]) => {
            const from = this.scenario.towns[a];
            const to = this.scenario.towns[b];
            return `<line id="${this.edgeElementId(a, b)}"
                          x1="${this.px(from.x)}" y1="${this.px(from.y)}"
                          x2="${this.px(to.x)}" y2="${this.px(to.y)}" />`;
        }).join('');

        return `<svg id="iaw-roads" width="${width}" height="${height}">
            <defs>
                <marker id="iaw-arrowhead" viewBox="0 0 10 10" refX="9" refY="5"
                        markerWidth="4" markerHeight="4" orient="auto-start-reverse">
                    <path d="M 0 0 L 10 5 L 0 10 z" />
                </marker>
            </defs>
            <g id="iaw-roads-edges">${lines}</g>
            <g id="iaw-move-arrows"></g>
        </svg>`;
    }

    /**
     * Where a line from one town to another should start, so it emerges from
     * the edge of the town's box rather than from under it.
     */
    private boxExit(from: TownDef, to: TownDef): { x: number; y: number } {
        const x = this.px(from.x);
        const y = this.px(from.y);
        const dx = this.px(to.x) - x;
        const dy = this.px(to.y) - y;

        // Half the town box, plus a little air.
        const scale = Math.min(
            dx === 0 ? Infinity : 60 / Math.abs(dx),
            dy === 0 ? Infinity : 44 / Math.abs(dy),
        );
        return { x: x + dx * scale, y: y + dy * scale };
    }

    private townHtml(town: TownDef): string {
        return `
            <div id="${this.townElementId(town.id)}" class="iaw-town"
                 style="left:${this.px(town.x)}px;top:${this.px(town.y)}px">
                <div class="iaw-town-name">${town.label}</div>
                <div class="iaw-town-supply"></div>
                <div class="iaw-town-troops"></div>
                <div class="iaw-town-pile"></div>
                <div class="iaw-town-revealed"></div>
                <div class="iaw-town-result"></div>
                <div class="iaw-town-pending"></div>
            </div>
        `;
    }

    private px(coordinate: number): number {
        return PADDING + coordinate * CELL;
    }

    private townElementId(townId: string): string {
        return `iaw-town-${townId}`;
    }

    private edgeElementId(a: string, b: string): string {
        return `iaw-edge-${a}-${b}`;
    }

    /**
     * The Empire's supply networks, worked out from the board rather than sent.
     * A town is in a network if the Empire stands in it; two occupied towns are
     * linked if the map links them. Computing it here keeps it correct after any
     * notification without anything having to be kept in step.
     */
    networks(): string[][] {
        const occupied = new Set(
            Object.keys(this.towns).filter(id => this.towns[id].troops > 0),
        );

        const seen = new Set<string>();
        const found: string[][] = [];

        occupied.forEach(start => {
            if (seen.has(start)) {
                return;
            }
            const network: string[] = [];
            const frontier = [start];
            seen.add(start);

            while (frontier.length) {
                const current = frontier.pop();
                network.push(current);
                this.scenario.towns[current].neighbors.forEach(neighbor => {
                    if (occupied.has(neighbor) && !seen.has(neighbor)) {
                        seen.add(neighbor);
                        frontier.push(neighbor);
                    }
                });
            }
            found.push(network);
        });

        return found;
    }

    /**
     * What a town gives the Empire, which is nothing once the rebels have won
     * it. An Insurgency victory denies the ground permanently: the Empire may
     * garrison it and hold a line through it, but it will never feed one.
     */
    supplyOf(townId: string): number {
        const town = this.towns[townId];
        if (town.resolved && town.winner === 'insurgency') {
            return 0;
        }
        return this.scenario.towns[townId].supply;
    }

    /** The ceiling and load of the network a town belongs to, if any. */
    networkOf(townId: string): { towns: string[]; ceiling: number; troops: number } | null {
        const network = this.networks().find(n => n.includes(townId));
        if (!network) {
            return null;
        }
        const supply = network.reduce((total, id) => total + this.supplyOf(id), 0);
        return {
            towns: network,
            ceiling: Math.floor(supply / this.scenario.supplyPerTroop),
            troops: network.reduce((total, id) => total + this.towns[id].troops, 0),
        };
    }

    // -- updating -----------------------------------------------------------

    setTowns(towns: Record<string, TownView>): void {
        this.towns = towns;
        this.updateAll();
    }

    getTown(townId: string): TownView {
        return this.towns[townId];
    }

    allTowns(): Record<string, TownView> {
        return this.towns;
    }

    updateAll(): void {
        Object.keys(this.towns).forEach(townId => this.updateTown(townId));
        this.updateRoads();
    }

    /**
     * Colour the roads that carry supply. An edge is live when the Empire holds
     * both ends, which is exactly when it joins two towns of one network — so
     * the red lines *are* the network, and cutting one is visible.
     */
    private updateRoads(): void {
        this.scenario.edges.forEach(([a, b]) => {
            const line = document.getElementById(this.edgeElementId(a, b));
            const live = this.towns[a]?.troops > 0 && this.towns[b]?.troops > 0;
            line?.classList.toggle('supplied', live);
        });
    }

    updateTown(townId: string): void {
        const town = this.towns[townId];
        const element = document.getElementById(this.townElementId(townId));
        if (!town || !element) {
            return;
        }

        element.classList.toggle('resolved', town.resolved);
        element.classList.toggle('empire-held', town.resolved && town.winner === 'empire');
        element.classList.toggle('insurgency-held', town.resolved && town.winner === 'insurgency');

        // The badge shows what is there and what this turn would add or take
        // away, as "2+1", rather than quietly showing the result.
        const delta = this.troopDelta[townId] ?? 0;
        const troops = element.querySelector('.iaw-town-troops') as HTMLElement;
        troops.innerHTML = (town.troops > 0 || delta !== 0)
            ? `<span class="iaw-troops">${town.troops}${delta === 0 ? ''
                : `<span class="iaw-troop-delta">${delta > 0 ? '+' : '-'}${Math.abs(delta)}</span>`}</span>`
            : '';

        // Two areas, as on a table: the face-down stack, and the cards a
        // garrison has turned over lying face up beside it.
        const pile = element.querySelector('.iaw-town-pile') as HTMLElement;
        pile.innerHTML = town.pile.map(card => this.cardHtml(card)).join('');

        const revealed = element.querySelector('.iaw-town-revealed') as HTMLElement;
        revealed.innerHTML = town.revealed.map(card => this.cardHtml(card)).join('');

        const result = element.querySelector('.iaw-town-result') as HTMLElement;
        result.textContent = town.resolved
            ? `${town.resolvedInfluence} : ${town.resolvedStrength}`
            : '';

        // Supply reads as "what this town adds / what its network can hold".
        const network = this.networkOf(townId);
        const definition = this.scenario.towns[townId];
        const denied = town.resolved && town.winner === 'insurgency';
        const supply = element.querySelector('.iaw-town-supply') as HTMLElement;
        supply.innerHTML = `
            <span class="iaw-supply${denied ? ' denied' : ''}"
                  title="${denied
                      ? _('Taken by the Insurgency: supplies nothing, builds nothing')
                      : _('Supply: this town, and its network')}"
                >${this.supplyOf(townId)}${network ? `/${network.ceiling}` : ''}</span>
            ${definition.production > 0 && !denied
                ? `<span class="iaw-produce" title="${_('Can build troops')}">&#128296;</span>`
                : ''}
        `;

        const pending = element.querySelector('.iaw-town-pending') as HTMLElement;
        pending.textContent = this.pending[townId] ?? '';
        element.classList.toggle('pending', Boolean(this.pending[townId]));
    }

    /**
     * A face-down card is drawn as a blank. Everything in the revealed row is
     * face up by definition, so it always arrives with a face.
     */
    private cardHtml(card: CardView): string {
        if (card.type === null) {
            return '<span class="iaw-card face-down"></span>';
        }
        // Show the value, including zero: with graded cards, "worth nothing"
        // is information rather than an absence of it.
        return `<span class="iaw-card ${card.type}">${card.influence ?? 0}</span>`;
    }

    // -- interaction --------------------------------------------------------

    onTownClick(handler: (townId: string) => void): void {
        this.clickHandler = handler;
    }

    /** Accept cards dragged from the hand. Pass null to stop accepting them. */
    onTownDrop(handler: ((townId: string, cardId: number) => void) | null): void {
        this.dropHandler = handler;
    }

    /** Highlight the towns a player may click right now. */
    setSelectable(townIds: string[]): void {
        Object.keys(this.scenario.towns).forEach(townId => {
            const element = document.getElementById(this.townElementId(townId));
            element?.classList.toggle('selectable', townIds.includes(townId));
        });
    }

    setSelected(townIds: string[]): void {
        Object.keys(this.scenario.towns).forEach(townId => {
            const element = document.getElementById(this.townElementId(townId));
            element?.classList.toggle('selected', townIds.includes(townId));
        });
    }

    setPending(pending: Record<string, string>): void {
        this.pending = pending;
        this.updateAll();
    }

    /** @param delta town id => signed troop change being staged this turn */
    setTroopDelta(delta: Record<string, number>): void {
        this.troopDelta = delta;
        this.updateAll();
    }

    /**
     * Draw the marches being staged as arrows along the roads they follow, so
     * the plan is visible on the map rather than only in a list.
     */
    setMoveArrows(moves: StagedMove[]): void {
        const layer = document.getElementById('iaw-move-arrows');
        if (!layer) {
            return;
        }

        layer.innerHTML = moves.map(move => {
            const from = this.scenario.towns[move.from];
            const to = this.scenario.towns[move.to];
            const start = this.boxExit(from, to);
            const end = this.boxExit(to, from);
            const label = move.count > 1
                ? `<text class="iaw-move-count" x="${(start.x + end.x) / 2}"
                         y="${(start.y + end.y) / 2 - 4}">${move.count}</text>`
                : '';
            return `<line class="iaw-move-arrow" x1="${start.x}" y1="${start.y}"
                          x2="${end.x}" y2="${end.y}"
                          marker-end="url(#iaw-arrowhead)" />${label}`;
        }).join('');
    }

    clearInteraction(): void {
        this.dropHandler = null;
        this.pending = {};
        this.troopDelta = {};
        this.setMoveArrows([]);
        this.setSelectable([]);
        this.setSelected([]);
        this.updateAll();
    }

    neighborsOf(townId: string): string[] {
        return this.scenario.towns[townId].neighbors;
    }
}
