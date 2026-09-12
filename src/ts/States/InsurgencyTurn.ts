import { Game } from "../Game";
import { endOfferHtml, endOfferLabel } from "../EndOffer";

/**
 * The Insurgency stages its whole hand, then commits.
 *
 * Placing is one simultaneous decision — every card goes out every turn — so
 * the client stages the assignment locally and sends it in a single action,
 * which is also what makes the PHP a direct port of the simulator's
 * InsurgencyTurn.
 *
 * Any resolution happened in the Resolve state before this one, so `openTowns`
 * is already final: a town resolved this turn is simply not in it.
 */
export class InsurgencyTurn {
    /** card id => town it is staged for. */
    private assigned: Record<number, string> = {};
    private order: number[] = [];
    private selectedCard: number | null = null;
    /** Standing offer to end the game, sent with the turn. */
    private offerEnd = false;
    private args: InsurgencyTurnArgs;

    constructor(
        private game: Game,
        private bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>,
    ) {
    }

    onEnteringState(args: InsurgencyTurnArgs, isCurrentPlayerActive: boolean) {
        this.args = {
            openTowns: args?.openTowns ?? [],
            offeredEnd: args?.offeredEnd ?? false,
            opponentOfferedEnd: args?.opponentOfferedEnd ?? false,
        };
        this.reset();

        this.bga.statusBar.setTitle(isCurrentPlayerActive
            ? _('${you} must place the entire hand')
            : _('${actplayer} must place the whole hand'));

        if (!isCurrentPlayerActive) {
            this.game.setStagingText(
                `<div class="iaw-hint">${_('The Insurgency is placing cards. You are the Empire, so there is nothing to do until it is your turn.')}</div>`
            );
            return;
        }

        this.game.onHandClick(cardId => this.onCardClick(cardId));
        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.game.board.onTownDrop((townId, cardId) => this.onCardDropped(townId, cardId));
        this.refresh();
    }

    onLeavingState() {
        this.reset();
        this.game.board.clearInteraction();
        this.game.setStagingText('');
        this.game.renderHand();
    }

    private reset(): void {
        this.assigned = {};
        this.order = [];
        this.selectedCard = null;
        // An offer stands until it is withdrawn, so it starts where it was left.
        this.offerEnd = this.args.offeredEnd;
    }

    // -- staging ------------------------------------------------------------

    private onCardClick(cardId: number): void {
        this.selectedCard = this.selectedCard === cardId ? null : cardId;
        this.refresh();
    }

    private onTownClick(townId: string): void {
        if (!this.args.openTowns.includes(townId)) {
            return;
        }

        // Clicking a town with no card picked places the next one waiting, which
        // makes dealing a hand out quickly a matter of clicking towns.
        const cardId = this.selectedCard ?? this.unassigned()[0]?.id;
        if (cardId === undefined) {
            return;
        }

        this.assign(cardId, townId);
    }

    private onCardDropped(townId: string, cardId: number): void {
        if (!this.args.openTowns.includes(townId)) {
            return;
        }
        this.assign(cardId, townId);
    }

    private assign(cardId: number, townId: string): void {
        this.assigned[cardId] = townId;
        this.order = this.order.filter(id => id !== cardId).concat(cardId);
        this.selectedCard = null;
        this.refresh();
    }

    private unassigned(): CardView[] {
        return this.game.hand.filter(card => this.assigned[card.id] === undefined);
    }

    // -- display ------------------------------------------------------------

    private refresh(): void {
        const delta: Record<string, number> = {};
        Object.values(this.assigned).forEach(townId => {
            delta[townId] = (delta[townId] ?? 0) + 1;
        });

        this.game.board.setCardDelta(delta);
        this.game.renderHand(this.assigned);
        this.game.board.setSelectable(this.args.openTowns);
        this.game.board.setSelected([]);

        const remaining = this.unassigned().length;
        this.game.setStagingText((remaining > 0
            ? `<div><b>${_('Cards still to place')}: ${remaining}</b></div>`
            : `<div><b>${_('The whole hand is placed.')}</b></div>`)
            + this.placementsHtml()
            + (remaining > 0
                ? `<div class="iaw-hint">${_('Drag a card onto a town, or click a card then a town. Every card must go somewhere.')}</div>`
                : `<div class="iaw-hint">${_('Confirm when you are happy with it.')}</div>`)
            + endOfferHtml(this.offerEnd, this.args.opponentOfferedEnd));

        this.buttons(remaining);
    }

    /**
     * One line per staged card, in the order they were placed.
     *
     * The Empire's box lists its marches, and placement deserves the same: "+2"
     * on a town says how many but not which, and which is the whole decision.
     * The order is real information too — the last card onto a town is the top
     * of its pile, which is what a look reads first — so these are listed in
     * placement order rather than grouped by town.
     */
    private placementsHtml(): string {
        return this.order.map(cardId => {
            const card = this.game.cardById(cardId);
            const value = card?.influence ?? 0;
            return `<div>${_('Influence')} ${value} ${_('to')}
                    <b>${this.townLabel(this.assigned[cardId])}</b></div>`;
        }).join('');
    }

    private townLabel(townId: string): string {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }

    private buttons(remaining: number): void {
        this.bga.statusBar.removeActionButtons();

        this.bga.statusBar.addActionButton(
            _('Confirm placement'),
            () => this.commit(),
            { disabled: remaining > 0 },
        );
        this.bga.statusBar.addActionButton(_('Reset'), () => {
            this.reset();
            this.refresh();
        }, { color: 'secondary' });

        this.bga.statusBar.addActionButton(endOfferLabel(this.offerEnd), () => {
            this.offerEnd = !this.offerEnd;
            this.refresh();
        }, { color: 'secondary' });
    }

    // -- sending ------------------------------------------------------------

    private commit(): void {
        const placements: Record<string, number[]> = {};
        // Order matters: cards go on one at a time, so the last one listed for a
        // town ends up on top of its pile.
        this.order.forEach(cardId => {
            const townId = this.assigned[cardId];
            (placements[townId] ??= []).push(cardId);
        });

        this.bga.actions.performAction('actCommitTurn', {
            placements: JSON.stringify(placements),
            offerEnd: this.offerEnd ? '1' : '0',
        });
    }
}
