/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */
import { Game } from "../Game";
import { presenceHtml } from "../presence";

/** The one thing this screen works on: a card's id and its value. */
interface DeckCard {
    id: number;
    presence: number;
}

/**
 * Distribute `minor` as evenly as the arithmetic allows among `major`,
 * keeping each group's own relative order. Used for "Spread Decoys Evenly" —
 * the general form so it works whichever group happens to be larger.
 */
function interleaveEvenly<T>(major: T[], minor: T[]): T[] {
    if (minor.length === 0) {
        return [...major];
    }
    if (major.length === 0) {
        return [...minor];
    }

    const result: T[] = [];
    const ratio = major.length / minor.length;
    let minorIndex = 0;
    for (let i = 0; i < major.length; i++) {
        result.push(major[i]);
        while (minorIndex < minor.length && i + 1 >= Math.round((minorIndex + 1) * ratio)) {
            result.push(minor[minorIndex]);
            minorIndex++;
        }
    }
    while (minorIndex < minor.length) {
        result.push(minor[minorIndex]);
        minorIndex++;
    }
    return result;
}

/**
 * Advanced rules only (game option 105, issue #6): before anything else
 * happens, the Rebels may arrange their deck in any order, or shuffle it
 * again.
 *
 * A full-screen takeover rather than a side-column box, in the family of
 * `Help.showStartOverlay` and `Help.showResolution` — a one-time moment that
 * needs the whole screen, not a panel competing with the board for 300px.
 * Unlike those two it does not dismiss on a stray click: there is a real
 * decision here, so only the Play button ends it.
 *
 * The deck arrives via `gamedatas.deck`, not through this state's args:
 * args are visible to every viewer of a state and the deck is not
 * (View::forSide) — the Empire's client never even requests this file's
 * data because its own `isCurrentPlayerActive` is always false here.
 */
export class ArrangeDeck {
    private order: DeckCard[] = [];
    private dragFrom: number | null = null;
    private overlay: HTMLElement | null = null;

    constructor(
        private game: Game,
        private bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>,
    ) {
    }

    onEnteringState(_args: ArrangeDeckArgs, isCurrentPlayerActive: boolean) {
        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} is arranging the deck'));
            this.game.setStagingText(
                `<div class="iaw-hint">${_('The Rebels may arrange their deck before play begins.')}</div>`
            );
            return;
        }

        this.bga.statusBar.setTitle(_('${you} may arrange the deck before play begins'));
        const deck = this.bga.gameui.gamedatas.deck ?? [];
        this.order = deck.map(card => ({ id: card.id, presence: card.presence ?? 0 }));
        this.renderOverlay();
    }

    onLeavingState() {
        this.overlay?.remove();
        this.overlay = null;
        this.game.setStagingText('');
    }

    // -- the overlay ----------------------------------------------------------

    private renderOverlay(): void {
        this.overlay?.remove();

        const overlay = document.createElement('div');
        overlay.id = 'iaw-arrange-overlay';
        overlay.innerHTML = `
            <div id="iaw-arrange-card">
                <div class="iaw-frame-title">${_('Arrange Your Deck')}</div>
                <p class="iaw-hint">
                    ${_('The top of the list is drawn first. Drag an agent to move it, or use a button below.')}
                </p>
                <div id="iaw-arrange-presets"></div>
                <div id="iaw-arrange-list"></div>
                <div id="iaw-arrange-submit"></div>
            </div>
        `;
        document.body.appendChild(overlay);
        this.overlay = overlay;

        this.renderPresets();
        this.renderList();
        this.renderSubmit();
    }

    private renderPresets(): void {
        const el = this.overlay!.querySelector('#iaw-arrange-presets') as HTMLElement;
        el.innerHTML = '';

        const preset = (label: string, run: () => void) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'iaw-arrange-preset';
            button.textContent = label;
            button.addEventListener('click', () => {
                run();
                this.renderList();
            });
            el.appendChild(button);
        };

        preset(_('Decoys First'), () => this.partitionByValue(true));
        preset(_('Decoys Last'), () => this.partitionByValue(false));
        preset(_('Spread Decoys Evenly'), () => this.spreadEvenly());
        preset(_('Shuffle'), () => this.shuffle());
    }

    private renderList(): void {
        const el = this.overlay!.querySelector('#iaw-arrange-list') as HTMLElement;
        el.innerHTML = this.order.map((card, index) => `
            <div class="iaw-arrange-card${card.presence === 0 ? ' decoy' : ''}"
                 draggable="true" data-index="${index}">
                ${presenceHtml(`+${card.presence}`)}
            </div>
        `).join('');

        el.querySelectorAll<HTMLElement>('.iaw-arrange-card').forEach(node => {
            node.addEventListener('dragstart', () => {
                this.dragFrom = Number(node.dataset.index);
                node.classList.add('dragging');
            });
            node.addEventListener('dragend', () => node.classList.remove('dragging'));
            node.addEventListener('dragover', event => event.preventDefault());
            node.addEventListener('drop', event => {
                event.preventDefault();
                const to = Number(node.dataset.index);
                if (this.dragFrom === null || this.dragFrom === to) {
                    return;
                }
                const [moved] = this.order.splice(this.dragFrom, 1);
                this.order.splice(to, 0, moved);
                this.dragFrom = null;
                this.renderList();
            });
        });
    }

    private renderSubmit(): void {
        const el = this.overlay!.querySelector('#iaw-arrange-submit') as HTMLElement;
        el.innerHTML = `<button type="button" id="iaw-arrange-play">${_('Play')}</button>`;
        el.querySelector('#iaw-arrange-play')!.addEventListener('click', () => {
            this.bga.actions.performAction('actArrangeDeck', {
                order: JSON.stringify(this.order.map(card => card.id)),
            });
        });
    }

    // -- the presets ------------------------------------------------------------

    /** All decoys to one end, all real agents to the other, each group's own
     * relative order kept. */
    private partitionByValue(decoysFirst: boolean): void {
        const decoys = this.order.filter(card => card.presence === 0);
        const reals = this.order.filter(card => card.presence !== 0);
        this.order = decoysFirst ? [...decoys, ...reals] : [...reals, ...decoys];
    }

    private spreadEvenly(): void {
        const decoys = this.order.filter(card => card.presence === 0);
        const reals = this.order.filter(card => card.presence !== 0);
        this.order = reals.length >= decoys.length
            ? interleaveEvenly(reals, decoys)
            : interleaveEvenly(decoys, reals);
    }

    private shuffle(): void {
        for (let i = this.order.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [this.order[i], this.order[j]] = [this.order[j], this.order[i]];
        }
    }
}
