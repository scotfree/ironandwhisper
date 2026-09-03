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
            return `<line x1="${this.px(from.x)}" y1="${this.px(from.y)}"
                          x2="${this.px(to.x)}" y2="${this.px(to.y)}" />`;
        }).join('');

        return `<svg id="iaw-roads" width="${width}" height="${height}">${lines}</svg>`;
    }

    private townHtml(town: TownDef): string {
        return `
            <div id="${this.townElementId(town.id)}" class="iaw-town"
                 style="left:${this.px(town.x)}px;top:${this.px(town.y)}px">
                <div class="iaw-town-name">${town.label}</div>
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

        const troops = element.querySelector('.iaw-town-troops') as HTMLElement;
        troops.innerHTML = town.troops > 0
            ? `<span class="iaw-troops">${town.troops}</span>`
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
        const label = card.influence && card.influence > 0 ? String(card.influence) : '·';
        return `<span class="iaw-card ${card.type}">${label}</span>`;
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

    clearInteraction(): void {
        this.dropHandler = null;
        this.pending = {};
        this.setSelectable([]);
        this.setSelected([]);
        this.updateAll();
    }

    neighborsOf(townId: string): string[] {
        return this.scenario.towns[townId].neighbors;
    }
}
