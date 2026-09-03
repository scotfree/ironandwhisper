import { BoardView } from "./BoardView";
import { EmpireTurn } from "./States/EmpireTurn";
import { InsurgencyTurn } from "./States/InsurgencyTurn";

/**
 * Iron and Whisper — client entry point.
 *
 * The two sides see different games, so almost everything here branches on
 * `side`. Nothing in this file may show a player something the server did not
 * send them: the filtering is done in View.php, and the client simply draws
 * what it was given.
 */
export class Game {
    public bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>;
    public board: BoardView;

    /** The side the person looking at the screen is playing. Null for spectators. */
    public side: Side | null = null;

    /** The Insurgency's hand. Empty for anyone else — they are never sent it. */
    public hand: CardView[] = [];

    private gamedatas: IronAndWhisperGamedatas;
    private handClickHandler: (cardId: number) => void = () => {};

    constructor(bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>) {
        this.bga = bga;

        this.bga.states.register('InsurgencyTurn', new InsurgencyTurn(this, bga));
        this.bga.states.register('EmpireTurn', new EmpireTurn(this, bga));
    }

    setup(gamedatas: IronAndWhisperGamedatas) {
        this.gamedatas = gamedatas;
        // The server says who you are. Working it back from a player id is how
        // the hand ended up invisible: one lookup miss and the Insurgency was
        // treated as a spectator.
        this.side = gamedatas.you;
        this.hand = gamedatas.hand ?? [];

        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="iaw-table">
                <div id="iaw-board-area"></div>
                <div id="iaw-side-area">
                    <div id="iaw-clock"></div>
                    <div id="iaw-hand-area">
                        <div class="iaw-heading">${_('Hand')}</div>
                        <div id="iaw-hand"></div>
                    </div>
                    <div id="iaw-staging"></div>
                </div>
            </div>
        `);

        this.board = new BoardView(
            document.getElementById('iaw-board-area'),
            gamedatas.scenario,
            gamedatas.towns,
            this.side,
        );
        this.board.render();

        Object.entries(gamedatas.players).forEach(([playerId, player]) => {
            const label = player.side === 'empire' ? _('Empire') : _('Insurgency');
            this.bga.playerPanels.getElement(Number(playerId)).insertAdjacentHTML('beforeend', `
                <div class="iaw-player-side">${label}</div>
            `);
        });

        this.renderHand();
        this.updateClock(gamedatas.deckCount, gamedatas.handCount, gamedatas.round);
        this.setupNotifications();
    }

    // -- shared UI ----------------------------------------------------------

    /**
     * The clock is public information and worth showing plainly: the deck size
     * divided by the hand size is exactly how many turns are left.
     */
    updateClock(deckCount: number, handCount: number, round: number): void {
        const element = document.getElementById('iaw-clock');
        if (!element) {
            return;
        }
        const scenario = this.gamedatas.scenario;
        element.innerHTML = `
            <div>${_('Turn')} ${Math.min(round, scenario.turns)} / ${scenario.turns}</div>
            <div>${_('Deck')}: ${deckCount} &nbsp; ${_('Hand')}: ${handCount}</div>
        `;
    }

    onHandClick(handler: (cardId: number) => void): void {
        this.handClickHandler = handler;
    }

    /**
     * @param assigned card id => town it is staged for, drawn as already dealt with
     */
    renderHand(assigned: Record<number, string> = {}): void {
        const element = document.getElementById('iaw-hand');
        if (!element) {
            return;
        }

        // If the server sent a hand, it is yours. Deciding that here would only
        // be a second opinion, and a second opinion can disagree.
        if (this.gamedatas.hand === null) {
            element.innerHTML = `<div class="iaw-hidden-hand">${this.gamedatas.handCount} ${_('cards')}</div>`;
            return;
        }

        element.innerHTML = this.hand.map(card => {
            const label = card.influence && card.influence > 0 ? String(card.influence) : '·';
            const staged = assigned[card.id] ? ' staged' : '';
            const where = assigned[card.id] ? ` title="${assigned[card.id]}"` : '';
            return `<span class="iaw-card hand ${card.type}${staged}" draggable="true"
                          data-card-id="${card.id}"${where}>${label}</span>`;
        }).join('');

        element.querySelectorAll<HTMLElement>('.iaw-card').forEach(node => {
            const cardId = Number(node.dataset.cardId);
            node.addEventListener('click', () => this.handClickHandler(cardId));
            node.addEventListener('dragstart', event => {
                (event as DragEvent).dataTransfer?.setData('text/plain', String(cardId));
                node.classList.add('dragging');
            });
            node.addEventListener('dragend', () => node.classList.remove('dragging'));
        });
    }

    setStagingText(html: string): void {
        const element = document.getElementById('iaw-staging');
        if (element) {
            element.innerHTML = html;
        }
    }

    cardById(cardId: number): CardView | undefined {
        return this.hand.find(card => card.id === cardId);
    }

    // -- notifications ------------------------------------------------------

    setupNotifications() {
        this.bga.notifications.setupPromiseNotifications({});
    }

    /**
     * Cards land on top of their piles. This arrives with ids and no types: the
     * Insurgency fills the faces in from the hand it already holds, and for
     * everyone else they stay face down.
     */
    async notif_cardsPlaced(args: { cards: Record<string, number[]> }) {
        Object.entries(args.cards).forEach(([townId, cardIds]) => {
            const town = this.board.getTown(townId);
            // Placed one at a time, so the last one given ends up on top.
            [...cardIds].reverse().forEach(cardId => {
                const known = this.cardById(cardId);
                town.pile.unshift(known ?? { id: cardId, type: null, influence: null });
            });
            town.pileSize += cardIds.length;
            this.board.updateTown(townId);
        });

        this.hand = [];
        if (this.gamedatas.hand !== null) {
            this.gamedatas.hand = [];
        }
        this.renderHand();
    }

    async notif_empireMoved(args: { troops: Record<string, number> }) {
        Object.entries(args.troops).forEach(([townId, troops]) => {
            this.board.getTown(townId).troops = troops;
            this.board.updateTown(townId);
        });
    }

    /**
     * A look takes cards off the top and returns them to the bottom. Everyone
     * is told how many moved, because troop positions make it derivable; only
     * the Empire is told what they were.
     */
    async notif_pilesRotated(args: { counts: Record<string, number> }) {
        Object.entries(args.counts).forEach(([townId, count]) => {
            const pile = this.board.getTown(townId).pile;
            pile.push(...pile.splice(0, count));
            this.board.updateTown(townId);
        });
    }

    async notif_peekResult(args: { seen: Record<string, CardView[]> }) {
        Object.entries(args.seen).forEach(([townId, cards]) => {
            const pile = this.board.getTown(townId).pile;
            cards.forEach(seen => {
                const card = pile.find(entry => entry.id === seen.id);
                if (card) {
                    card.type = seen.type;
                    card.influence = seen.influence;
                }
            });
            this.board.updateTown(townId);
        });
    }

    async notif_townResolved(args: {
        town_id: string;
        winner: Side;
        influence: number;
        strength: number;
        pile: CardView[];
    }) {
        const town = this.board.getTown(args.town_id);
        town.resolved = true;
        town.winner = args.winner;
        town.resolvedInfluence = args.influence;
        town.resolvedStrength = args.strength;
        // Face up from here on, to both players.
        town.pile = args.pile;
        town.troops = 0;
        this.board.updateTown(args.town_id);
    }

    async notif_deckCount(args: { deckCount: number; handCount: number }) {
        this.gamedatas.deckCount = args.deckCount;
        this.gamedatas.handCount = args.handCount;
        this.updateClock(args.deckCount, args.handCount, this.gamedatas.round);
    }

    async notif_handDrawn(args: { hand: CardView[] }) {
        this.hand = args.hand;
        this.gamedatas.hand = args.hand;
        this.renderHand();
    }

    async notif_gameEnding(args: { reason: string }) {
        this.board.clearInteraction();
    }
}
