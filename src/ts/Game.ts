import { BoardView } from "./BoardView";
import { EmpireTurn } from "./States/EmpireTurn";
import { InsurgencyTurn } from "./States/InsurgencyTurn";
import { Resolve } from "./States/Resolve";
import { Help } from "./Help";

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

    /** The solo opponent, or null in a two-player game. */
    private bot: BotView | null = null;

    /** The cheat sheet and the side reminder. Built once the board exists. */
    private help: Help;

    /** Which step of the current side's turn we are on; -1 for none. */
    private phase = -1;

    /** Reused rather than rebuilt, so repeated opens do not leak dialogs. */
    private pileDialog: PopinDialog | null = null;

    /**
     * What happened on the last turn somebody else took.
     *
     * Deliberately persistent rather than animated: an animation plays once and
     * is gone, and in a turn-based game you often arrive after it played. These
     * markers survive a reload and can be read at your own pace. Cleared when
     * the acting side is you — you do not need a ghost of your own move.
     */
    private lastTurn: LastTurn | null = null;

    private gamedatas: IronAndWhisperGamedatas;
    private handClickHandler: (cardId: number) => void = () => {};

    constructor(bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>) {
        this.bga = bga;

        this.bga.states.register('Resolve', new Resolve(this, bga));
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
                    <div id="iaw-state">
                        <div id="iaw-clock"></div>
                        <div id="iaw-phases"></div>
                    </div>
                    <div id="iaw-zoom"></div>
                    <div id="iaw-staging">
                        <div id="iaw-staging-text"></div>
                        <div id="iaw-hand"></div>
                    </div>
                    <div id="iaw-armies"></div>
                    <div id="iaw-last-turn"></div>
                    <div id="iaw-primer"></div>
                </div>
            </div>
        `);

        this.board = new BoardView(
            document.getElementById('iaw-board-area'),
            gamedatas.scenario,
            gamedatas.towns,
            this.side,
        );
        this.board.onBoardChanged(() => this.renderArmies());
        this.board.render();
        // The silhouettes are files, so they arrive after the first paint. The
        // board is drawn and usable without them.
        this.board.loadFrames();

        // The bot has no player record, so it gets a panel of its own rather
        // than a row in gamedatas.players.
        this.bot = gamedatas.bot;
        if (this.bot) {
            this.bga.playerPanels.addAutomataPlayerPanel(this.bot.id, this.bot.name, {
                color: this.bot.side === 'empire' ? '6b3fa0' : '2e7d4f',
                score: this.bot.score,
            });
        }

        const sideLabel = (side: Side) => side === 'empire' ? _('Empire') : _('Insurgency');

        Object.entries(gamedatas.players).forEach(([playerId, player]) => {
            this.bga.playerPanels.getElement(Number(playerId)).insertAdjacentHTML('beforeend', `
                <div class="iaw-player-side">${sideLabel(player.side)}</div>
            `);
        });

        if (this.bot) {
            this.bga.playerPanels.getElement(this.bot.id).insertAdjacentHTML('beforeend', `
                <div class="iaw-player-side">${sideLabel(this.bot.side)}</div>
            `);
        }

        this.help = new Help(gamedatas.scenario, this.board);
        this.help.install();
        this.renderPrimer();
        this.renderPhases();
        this.board.onStackClick((townId, faceUp) => this.showPile(townId, faceUp));

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

    /**
     * The Empire's armies, one per supply network.
     *
     * Usually there is one. When a line is cut there are two, and the split is
     * the single most consequential thing on the board — each half now feeds
     * itself or starves — so it is worth saying in words rather than leaving
     * to be read off twelve separate supply badges.
     */
    renderArmies(): void {
        const element = document.getElementById('iaw-armies');
        if (!element) {
            return;
        }

        const armies = this.board.armies();
        if (!armies.length) {
            element.innerHTML = '';
            return;
        }

        element.innerHTML = armies.map(army => `
            <div class="iaw-army${army.supplyUsed > army.supplyAvailable ? ' over' : ''}">
                <div class="iaw-army-pawn">${this.board.pawnSvg()}</div>
                <div class="iaw-army-detail">
                    <div class="iaw-army-name">${army.name} ${_('Army')}</div>
                    <div class="iaw-army-supply">${this.supplySentence(army)}</div>
                </div>
            </div>
        `).join('');
    }

    /**
     * Kept as one translatable sentence with placeholders rather than
     * concatenated fragments, which no translator can reorder.
     */
    private supplySentence(army: ArmyView): string {
        return _('${troops} troops use ${used} supply of ${available} available.')
            .replace('${troops}', String(army.troops))
            .replace('${used}', String(army.supplyUsed))
            .replace('${available}', String(army.supplyAvailable));
    }

    onHandClick(handler: (cardId: number) => void): void {
        this.handClickHandler = handler;
    }

    /**
     * @param assigned card id => town it is staged for, drawn as already dealt with
     * @param selected the card currently picked up, drawn as picked up
     */
    renderHand(assigned: Record<number, string> = {}, selected: number | null = null): void {
        const element = document.getElementById('iaw-hand');
        if (!element) {
            return;
        }

        // If the server sent a hand, it is yours. Deciding that here would only
        // be a second opinion, and a second opinion can disagree. Anyone else
        // reads the rebels' card count off the clock, so this stays empty
        // rather than repeating it under a heading.
        if (this.gamedatas.hand === null) {
            element.innerHTML = '';
            return;
        }

        element.innerHTML = this.hand.map(card => {
            const label = String(card.presence ?? 0);
            const staged = assigned[card.id] ? ' staged' : '';
            const picked = card.id === selected ? ' selected' : '';
            const where = assigned[card.id] ? ` title="${assigned[card.id]}"` : '';
            return `<span class="iaw-card hand ${card.type}${staged}${picked}" draggable="true"
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

    /**
     * Which step of your side's turn the game is waiting on, or -1 for none.
     *
     * The steps that happen on commit — looking, starving, drawing — are never
     * current, because nobody is ever asked about them. Each state class sets
     * this on entering; `NextTurn` and the opponent's turn clear it.
     */
    setPhase(phase: number): void {
        this.phase = phase;
        this.renderPhases();
    }

    private renderPhases(): void {
        const element = document.getElementById('iaw-phases');
        if (element && this.help) {
            element.innerHTML = this.help.phaseListHtml(this.side, this.phase);
        }
    }

    /**
     * The card or troop under inspection, drawn large in the side column.
     *
     * Clicking a card in hand selects it for placement *and* shows it here —
     * selection used to change nothing on screen at all, which is why players
     * reported that click-then-click did not work.
     */
    showZoom(html: string, footer = ''): void {
        const element = document.getElementById('iaw-zoom');
        if (element) {
            element.innerHTML = html
                ? html + (footer ? `<div class="iaw-zoom-hint">${footer}</div>` : '')
                : '';
        }
    }

    clearZoom(): void {
        this.showZoom('');
    }

    zoomCard(card: CardView, footer = ''): void {
        this.showZoom(this.help.cardDetailHtml(card), footer);
    }

    showTroopZoom(): void {
        this.showZoom(this.help.troopDetailHtml());
    }

    /**
     * A town's agents, in the order they were placed, newest on top.
     *
     * Order is real information for both sides: the top of the pile is what the
     * Empire's next look reads. The rebels see the faces of their own cards —
     * remembering twenty turns of placements across twelve towns is clerical
     * rather than strategic, and the player who keeps notes should not beat the
     * player who does not.
     */
    showPile(townId: string, faceUp: boolean): void {
        const town = this.board.getTown(townId);
        const cards = faceUp ? town.revealed : town.pile;
        const label = this.gamedatas.scenario.towns[townId].label;

        if (!this.pileDialog) {
            this.pileDialog = new ebg.popindialog();
            this.pileDialog.create('iaw-pile-dialog');
            this.pileDialog.setMaxWidth(560);
        }
        this.pileDialog.setTitle(`${label} — ${faceUp
            ? _('face up') : _('face down')}`);
        this.pileDialog.setContent(cards.length
            ? `<div class="iaw-pile-view">${cards.map((card, index) => `
                   <div class="iaw-pile-row">
                       <span class="iaw-pile-position">${index === 0
                           ? _('top') : String(index + 1)}</span>
                       ${this.help.cardDetailHtml(card)}
                   </div>`).join('')}</div>`
            : `<p>${_('Nothing here.')}</p>`);
        this.pileDialog.show();
    }

    setStagingText(html: string): void {
        const element = document.getElementById('iaw-staging-text');
        if (element) {
            element.innerHTML = html;
        }
    }

    /**
     * The permanent reminder of what your side does, below everything that
     * changes. Rendered once: nothing in it depends on the state of the game.
     */
    private renderPrimer(): void {
        const element = document.getElementById('iaw-primer');
        if (!element || !this.help) {
            return;
        }
        element.innerHTML = this.help.primerHtml(this.side);
        element.querySelector('.iaw-primer-more')
            ?.addEventListener('click', () => this.help.show());
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
                town.pile.unshift(known ?? { id: cardId, type: null, presence: null });
            });
            town.pileSize = town.pile.length;
            town.cardCount += cardIds.length;
            this.board.updateTown(townId);
        });

        // Face up if they are yours, backs if they are not: the payload carries
        // real types only for the side that placed them.
        const placed: Record<string, OverlayCard[]> = {};
        Object.entries(args.cards).forEach(([townId, cardIds]) => {
            placed[townId] = cardIds.map(cardId => ({
                presence: this.board.getTown(townId).pile
                    .find(card => card.id === cardId)?.presence ?? null,
            }));
        });
        this.recordLastTurn('insurgency', {
            side: 'insurgency', moves: [], produced: {}, placed,
        });

        this.hand = [];
        if (this.gamedatas.hand !== null) {
            this.gamedatas.hand = [];
        }
        this.renderHand();
    }

    /**
     * Log-only notifications. The board is moved by the notification that
     * follows each of these; these exist so the game log reads as an account of
     * the turn — which town was built in, what marched where, how many cards
     * went into each pile — rather than a one-line summary.
     */
    async notif_built(args: unknown) {
    }

    async notif_marched(args: unknown) {
    }

    async notif_placedIn(args: unknown) {
    }

    /**
     * Someone put up or took down a standing offer to end the game. Log only —
     * the turn states read the current offers from their args, so there is
     * nothing to move on the board.
     */
    async notif_endOffered(args: unknown) {
    }

    async notif_empireMoved(args: {
        player_id: number;
        troops: Record<string, number>;
        produced: Record<string, number>;
        moves: StagedMove[];
    }) {
        Object.entries(args.troops).forEach(([townId, troops]) => {
            this.board.getTown(townId).troops = troops;
            this.board.updateTown(townId);
        });

        this.recordLastTurn('empire', {
            side: 'empire',
            moves: args.moves ?? [],
            produced: args.produced ?? {},
            placed: {},
        });
    }

    /**
     * Remember one side's turn, unless it was yours.
     *
     * `mine` is decided from the side rather than the player id, because the
     * bot has no player row and would otherwise never be recognised as the
     * opponent.
     */
    private recordLastTurn(actingSide: Side, turn: LastTurn): void {
        this.lastTurn = actingSide === this.side ? null : turn;
        this.renderLastTurn();
    }

    /**
     * Draw what the opponent just did: ghost arrows on the roads they used,
     * their cards above the towns they landed in, and the same lines their own
     * staging panel showed them, in the side column.
     */
    renderLastTurn(): void {
        const element = document.getElementById('iaw-last-turn');
        const turn = this.lastTurn;

        this.board.setGhostArrows(turn?.moves ?? []);
        this.board.setOverlay(turn?.placed ?? {}, true);

        if (!element) {
            return;
        }
        if (!turn) {
            element.innerHTML = '';
            return;
        }

        const label = (townId: string) => this.gamedatas.scenario.towns[townId].label;
        const lines: string[] = [];

        Object.entries(turn.produced).forEach(([townId, count]) => {
            lines.push(`${_('Built')} ${count} ${_('at')} <b>${label(townId)}</b>`);
        });
        turn.moves.forEach(move => {
            lines.push(`${move.count} ${_('from')} <b>${label(move.from)}</b>
                        ${_('to')} <b>${label(move.to)}</b>`);
        });
        Object.entries(turn.placed).forEach(([townId, cards]) => {
            lines.push(`${cards.length} ${cards.length === 1 ? _('agent') : _('agents')}
                        ${_('to')} <b>${label(townId)}</b>`);
        });

        element.innerHTML = `
            <div class="iaw-last-turn">
                <div class="iaw-heading">${turn.side === 'empire'
                    ? _('The Empire\'s last turn') : _('The rebels\' last turn')}</div>
                ${lines.length
                    ? lines.map(line => `<div>${line}</div>`).join('')
                    : `<div class="iaw-hint">${_('Nothing moved.')}</div>`}
            </div>
        `;
    }

    /**
     * A look turns the top card of a pile face up, where it stays. This is
     * public: the cards are on the table, and the Insurgency could work out
     * what the Empire had seen in any case.
     */
    async notif_cardsRevealed(args: { revealed: Record<string, CardView[]> }) {
        Object.entries(args.revealed).forEach(([townId, cards]) => {
            const town = this.board.getTown(townId);
            const turned = new Set(cards.map(card => card.id));
            town.pile = town.pile.filter(card => !turned.has(card.id));
            town.revealed.push(...cards);
            town.pileSize = town.pile.length;
            this.board.updateTown(townId);
        });
    }

    async notif_townResolved(args: {
        town_id: string;
        winner: Side;
        cardPresence: number;
        troopPresence: number;
        points: number;
        player_id: number;
        pile: CardView[];
        troopsLost: number;
    }) {
        // A real player's score is kept by the framework's counter, which
        // updates itself. The bot's is ours to move.
        if (this.bot && args.player_id === this.bot.id) {
            this.bot.score += args.points;
            this.bga.playerPanels.getScoreCounter(this.bot.id)?.setValue(this.bot.score);
        }

        const town = this.board.getTown(args.town_id);
        town.resolved = true;
        town.winner = args.winner;
        town.resolvedCardPresence = args.cardPresence;
        town.resolvedTroopPresence = args.troopPresence;
        // Only the loser's commitment leaves. The Empire keeps its garrison in
        // a town it wins, so zeroing troops here made them vanish until the
        // next turn's notification put them back.
        town.troops -= args.troopsLost;
        town.pile = [];
        town.pileSize = 0;
        // Captured cards are taken off the board; cards the Insurgency held
        // stay, face up.
        town.revealed = args.winner === 'empire' ? [] : args.pile;
        town.cardCount = town.revealed.length;
        this.board.updateTown(args.town_id);
    }

    /**
     * Which garrisons are under notice. Sent every Empire turn, including when
     * it is empty, because a repaired supply line has to clear the warning as
     * visibly as breaking one raised it.
     */
    async notif_starvationWarning(args: { starving: Record<string, number> }) {
        Object.keys(this.board.allTowns()).forEach(townId => {
            this.board.getTown(townId).starving = args.starving[townId] ?? 0;
            this.board.updateTown(townId);
        });
    }

    async notif_deckCount(args: { deckCount: number; handCount: number; round: number }) {
        this.gamedatas.deckCount = args.deckCount;
        this.gamedatas.handCount = args.handCount;
        // The round advances at the end of the Empire's turn. Reusing the one
        // from setup left the counter stuck at whatever it read on page load.
        this.gamedatas.round = args.round;
        this.updateClock(args.deckCount, args.handCount, args.round);
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
