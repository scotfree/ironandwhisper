/**
 * The board: towns laid out from the map's coordinates, edges drawn between
 * them, and each town showing what the viewing player is entitled to see.
 *
 * This class only draws. It never decides what is legal and never talks to the
 * server — the state classes do that and tell it what to highlight.
 */

const CELL = 150;
const PADDING = 70;

// A town box is a fixed size, whatever it is holding. It used to grow as
// troops, supply and cards arrived, so the map rearranged itself as the game
// went on and no two towns were the same shape.
const TOWN_WIDTH = 120;
const TOWN_HEIGHT = 104;

export class BoardView {
    private towns: Record<string, TownView>;

    /** The outlines, once img/town.svg, city.svg and pawn.svg have loaded. */
    private frames: { town: string; city: string; pawn: string } | null = null;
    private clickHandler: (townId: string) => void = () => {};
    private dropHandler: ((townId: string, cardId: number) => void) | null = null;

    /** Called after any redraw, so the panels beside the board can follow. */
    private changeHandler: () => void = () => {};

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
            dx === 0 ? Infinity : (TOWN_WIDTH / 2 + 6) / Math.abs(dx),
            dy === 0 ? Infinity : (TOWN_HEIGHT / 2 + 6) / Math.abs(dy),
        );
        return { x: x + dx * scale, y: y + dy * scale };
    }

    private townHtml(town: TownDef): string {
        return `
            <div id="${this.townElementId(town.id)}" class="iaw-town"
                 style="left:${this.px(town.x)}px;top:${this.px(town.y)}px">
                <div class="iaw-town-frame">${this.frameSvg(town)}</div>
                <div class="iaw-town-name"></div>
                <div class="iaw-town-body">
                    <div class="iaw-town-rebel"></div>
                    <div class="iaw-town-empire"></div>
                </div>
                <div class="iaw-town-pending"></div>
            </div>
        `;
    }

    /**
     * Load the two silhouettes and redraw with them.
     *
     * They are fetched rather than built from coordinates in here so they can
     * be edited in a drawing app: img/town.svg and img/city.svg are ordinary
     * files. Until they arrive the board draws a plain box, so a failed fetch
     * costs the shape and nothing else.
     */
    async loadFrames(): Promise<void> {
        const read = async (name: string): Promise<string> => {
            const response = await fetch(`${g_gamethemeurl}img/${name}.svg`);
            return this.frameMarkup(await response.text());
        };

        try {
            this.frames = {
                town: await read('town'),
                city: await read('city'),
                pawn: await read('pawn'),
            };
        } catch (error) {
            console.warn('iaw: town frames could not be loaded', error);
            return;
        }

        Object.values(this.scenario.towns).forEach(town => {
            const element = document.getElementById(this.townElementId(town.id));
            const frame = element?.querySelector('.iaw-town-frame') as HTMLElement;
            if (frame) {
                frame.innerHTML = this.frameSvg(town);
            }
        });
        // The pawns are drawn as part of the troop badge, so redraw those too.
        this.updateAll();
    }

    /**
     * The file's <svg> element, stretched to the box and wired to the town's
     * colours.
     *
     * preserveAspectRatio="none" is what lets the drawing be any size: it is
     * squashed to the town box whatever its viewBox says. The stroke would be
     * squashed with it, which is what non-scaling-stroke in the CSS prevents.
     *
     * The colours cannot be done in CSS. A drawing app writes them as inline
     * style attributes, and an inline style beats any stylesheet rule — the
     * first hand-written frames used presentation attributes, which do not, so
     * the CSS worked until a real drawing replaced them. Rewriting the markup
     * is the only thing that reaches them both.
     *
     * The convention: **white and black are the game's colours** and become the
     * fill and stroke of whoever holds the town. Anything drawn in another
     * colour — a gradient, a detail line, fill:none — is left exactly as it is.
     * So a silhouette drawn in plain black on white just works, and detail can
     * opt out by not being black or white.
     */
    private frameMarkup(file: string): string {
        const start = file.indexOf('<svg');
        const end = file.lastIndexOf('</svg>');
        if (start === -1 || end === -1) {
            return '';
        }

        const white = /(fill\s*[:=]\s*"?)(#fff(?:fff)?|white)\b/gi;
        const black = /(stroke\s*[:=]\s*"?)(#000(?:000)?|black)\b/gi;

        return file.slice(start, end + '</svg>'.length)
            .replace(/\swidth="[^"]*"/, '')
            .replace(/\sheight="[^"]*"/, '')
            .replace(white, '$1var(--art-fill)')
            .replace(black, '$1var(--art-stroke)')
            .replace('<svg', '<svg preserveAspectRatio="none"');
    }

    /** The pawn markup, or nothing if the file has not arrived yet. */
    pawnSvg(): string {
        return this.frames ? this.frames.pawn : '';
    }

    /** A production town is drawn as a skyline, everything else as a hut. */
    private frameSvg(town: TownDef): string {
        if (!this.frames) {
            return '';
        }
        return town.production > 0 ? this.frames.city : this.frames.town;
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
    networks(troopsIn: (townId: string) => number = id => this.towns[id].troops): string[][] {
        const occupied = new Set(
            Object.keys(this.towns).filter(id => troopsIn(id) > 0),
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

    /**
     * The ceiling and load of the network a town belongs to, if any.
     *
     * `troopsIn` lets a turn being staged ask the question of the board as it
     * *will* stand rather than as it stands: massing changes which towns are
     * occupied, so it changes the networks themselves, not just their load.
     */
    networkOf(
        townId: string,
        troopsIn: (id: string) => number = id => this.towns[id].troops,
    ): { towns: string[]; ceiling: number; troops: number } | null {
        const network = this.networks(troopsIn).find(n => n.includes(townId));
        if (!network) {
            return null;
        }
        const supply = network.reduce((total, id) => total + this.supplyOf(id), 0);
        return {
            towns: network,
            ceiling: Math.floor(supply / this.scenario.supplyPerTroop),
            troops: network.reduce((total, id) => total + troopsIn(id), 0),
        };
    }

    /**
     * Every Empire network that cannot feed what is standing in it, given a
     * board that may still be being staged.
     *
     * `warned` is whether the network is already under notice: a network that
     * has only just gone short is marked at the end of this turn and starves at
     * the end of the next one, so the two say very different things to a player.
     */
    overSupplied(
        troopsIn: (id: string) => number = id => this.towns[id].troops,
    ): { towns: string[]; over: number; warned: boolean }[] {
        return this.networks(troopsIn)
            .map(network => {
                const supply = network.reduce((total, id) => total + this.supplyOf(id), 0);
                const ceiling = Math.floor(supply / this.scenario.supplyPerTroop);
                return {
                    towns: network,
                    over: network.reduce((total, id) => total + troopsIn(id), 0) - ceiling,
                    warned: network.some(id => this.towns[id].starving > 0),
                };
            })
            .filter(network => network.over > 0);
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

        const definition = this.scenario.towns[townId];
        const denied = town.resolved && town.winner === 'insurgency';

        // The production mark sits beside the name rather than in the body,
        // which is now divided by side and has no room for anything neutral.
        const name = element.querySelector('.iaw-town-name') as HTMLElement;
        name.innerHTML = `${definition.production > 0 && !denied
            ? `<span class="iaw-produce" title="${_('Can build troops')}">&#128296;</span>`
            : ''}${definition.label}`;

        // Rebels down the left, Empire down the right, so which side a number
        // belongs to can be read off the board without reading the number.
        const rebel = element.querySelector('.iaw-town-rebel') as HTMLElement;
        rebel.innerHTML = this.faceDownHtml(town) + this.faceUpHtml(town);

        const empire = element.querySelector('.iaw-town-empire') as HTMLElement;
        empire.innerHTML = this.troopsHtml(townId, town) + this.supplyHtml(townId, town, denied);

        const pending = element.querySelector('.iaw-town-pending') as HTMLElement;
        pending.textContent = this.pending[townId] ?? '';
        element.classList.toggle('pending', Boolean(this.pending[townId]));

        this.changeHandler();
    }

    /**
     * The garrison: a pawn and a count, with what this turn would change and
     * what is about to starve.
     */
    private troopsHtml(townId: string, town: TownView): string {
        const delta = this.troopDelta[townId] ?? 0;
        if (town.troops === 0 && delta === 0) {
            return '';
        }

        const pawn = this.frames
            ? `<span class="iaw-pawn">${this.frames.pawn}</span>`
            : '';
        const change = delta === 0 ? ''
            : `<span class="iaw-troop-delta">${delta > 0 ? '+' : '-'}${Math.abs(delta)}</span>`;
        // A garrison under notice pulses and says how many of it are going,
        // because the loss used to land between turns where nobody saw it.
        const doomed = town.starving > 0
            ? `<span class="iaw-troops-doomed" title="${_('Starving: these troops are lost at the end of the Empire\'s next turn unless the supply line is repaired')}">&minus;${town.starving}</span>`
            : '';

        return `<div class="iaw-troops${town.starving > 0 ? ' starving' : ''}"
                 >${pawn}<span class="iaw-troop-count">${town.troops}</span>${change}${doomed}</div>`;
    }

    /**
     * Supply, as "troops standing / what this network holds" over "what this
     * town adds". The first line is the same for every town in a network, which
     * is what makes a network visible.
     */
    private supplyHtml(townId: string, town: TownView, denied: boolean): string {
        const network = this.networkOf(townId);
        if (!network) {
            return '';
        }

        return `<div class="iaw-supply${network.troops > network.ceiling ? ' over' : ''}"
                     title="${_('Troops standing, and what this network supports')}"
                  >${network.troops}/${network.ceiling}</div>
                <div class="iaw-contribution${denied ? ' denied' : ''}"
                     title="${denied
                         ? _('Taken by the Insurgency: supplies nothing, builds nothing')
                         : _('What this town adds to the network')}"
                  >(${this.supplyOf(townId)})</div>`;
    }

    /**
     * The face-down stack: a height and nothing else.
     *
     * The Insurgency is still *sent* the faces — it placed the cards, and the
     * server has no reason to withhold them — but nobody is shown them. Once a
     * card is down it is down, for the player who put it there as much as for
     * the one who has to guess, and remembering the board is part of the game.
     */
    private faceDownHtml(town: TownView): string {
        if (town.pileSize === 0) {
            return '';
        }
        return `<span class="iaw-stack face-down"
                      title="${town.pileSize} ${_('face down')}"
                 ><span class="iaw-stack-count">${town.pileSize}</span></span>`;
    }

    /**
     * The face-up stack: how many, and what they add up to.
     *
     * The sum is the whole reason the Empire looks, so it is on the stack
     * rather than left to be worked out; the individual values are on the
     * tooltip for anyone who wants to check the arithmetic. Everything here is
     * public — face-up cards are on the table.
     */
    private faceUpHtml(town: TownView): string {
        const cards = town.revealed;
        if (cards.length === 0) {
            return '';
        }

        const values = cards.map(card => card.influence ?? 0);
        const total = values.reduce((sum, value) => sum + value, 0);

        return `<span class="iaw-stack face-up"
                      title="${_('Face up')}: ${values.join(', ')}"
                 ><span class="iaw-stack-count">${cards.length}</span
                 ><span class="iaw-stack-sum">${total}</span></span>`;
    }

    // -- interaction --------------------------------------------------------

    onTownClick(handler: (townId: string) => void): void {
        this.clickHandler = handler;
    }

    /**
     * Anything drawn from the board but living outside it — the army list —
     * redraws through here. Called once per town update, so it runs a dozen
     * times on a full refresh; it is a couple of small innerHTML writes and
     * idempotent, which is cheaper than working out when it is really needed.
     */
    onBoardChanged(handler: () => void): void {
        this.changeHandler = handler;
    }

    /**
     * The Empire's armies: one per supply network, named for the town holding
     * most of it.
     *
     * The name breaks ties by hashing the network's membership rather than by
     * picking the alphabetically first town, which would mean the same name
     * forever. Hashing keeps it stable while the army is — a name that changed
     * on every redraw would be unreadable — and shuffles it when the army does.
     */
    armies(): ArmyView[] {
        return this.networks().map(towns => {
            const most = Math.max(...towns.map(id => this.towns[id].troops));
            const tied = towns.filter(id => this.towns[id].troops === most).sort();
            const seed = [...towns].sort().join('|');
            let hash = 0;
            for (let i = 0; i < seed.length; i++) {
                hash = (hash * 31 + seed.charCodeAt(i)) | 0;
            }

            const troops = towns.reduce((total, id) => total + this.towns[id].troops, 0);
            const supply = towns.reduce((total, id) => total + this.supplyOf(id), 0);

            return {
                name: this.scenario.towns[tied[Math.abs(hash) % tied.length]].label,
                towns,
                troops,
                supplyUsed: troops * this.scenario.supplyPerTroop,
                supplyAvailable: supply,
            };
        });
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
