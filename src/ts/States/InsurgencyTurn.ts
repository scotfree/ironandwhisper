import { Game } from "../Game";

/**
 * The Insurgency stages its whole hand, then commits.
 *
 * Placing is one simultaneous decision — every card goes out every turn — so
 * the client stages the assignment locally and sends it in a single action,
 * which is also what makes the PHP a direct port of the simulator's
 * InsurgencyTurn.
 */
export class InsurgencyTurn {
    /** card id => town it is staged for. */
    private assigned: Record<number, string> = {};
    private order: number[] = [];
    private selectedCard: number | null = null;
    private resolveTarget: string | null = null;
    private choosingResolution = false;
    private args: InsurgencyTurnArgs;

    constructor(
        private game: Game,
        private bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>,
    ) {
    }

    onEnteringState(args: InsurgencyTurnArgs, isCurrentPlayerActive: boolean) {
        this.args = {
            openTowns: args?.openTowns ?? [],
            resolvable: args?.resolvable ?? [],
        };
        this.reset();

        this.bga.statusBar.setTitle(isCurrentPlayerActive
            ? _('${you} must place your entire hand, and may then resolve one town')
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
        this.resolveTarget = null;
        this.choosingResolution = false;
    }

    // -- staging ------------------------------------------------------------

    private onCardClick(cardId: number): void {
        if (this.choosingResolution) {
            return;
        }
        this.selectedCard = this.selectedCard === cardId ? null : cardId;
        this.refresh();
    }

    private onTownClick(townId: string): void {
        if (this.choosingResolution) {
            if (this.pileAfterStaging(townId) > 0 && !this.game.board.getTown(townId).resolved) {
                this.resolveTarget = townId;
                this.refresh();
            }
            return;
        }

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
        if (this.choosingResolution || !this.args.openTowns.includes(townId)) {
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

    /** How many cards a town's pile will hold once this turn is committed. */
    private pileAfterStaging(townId: string): number {
        const staged = Object.values(this.assigned).filter(target => target === townId).length;
        return this.game.board.getTown(townId).pileSize + staged;
    }

    // -- display ------------------------------------------------------------

    private refresh(): void {
        const pending: Record<string, string> = {};
        Object.values(this.assigned).forEach(townId => {
            const count = Object.values(this.assigned).filter(target => target === townId).length;
            pending[townId] = `+${count}`;
        });

        this.game.board.setPending(pending);
        this.game.renderHand(this.assigned);

        if (this.choosingResolution) {
            const targets = this.args.openTowns.filter(townId => this.pileAfterStaging(townId) > 0);
            this.game.board.setSelectable(targets);
            this.game.board.setSelected(this.resolveTarget ? [this.resolveTarget] : []);
        } else {
            this.game.board.setSelectable(this.args.openTowns);
            this.game.board.setSelected([]);
        }

        const remaining = this.unassigned().length;
        this.game.setStagingText(remaining > 0
            ? `<div><b>${_('Cards still to place')}: ${remaining}</b></div>
               <div class="iaw-hint">${_('Drag a card onto a town, or click a card then a town. Every card must go somewhere.')}</div>`
            : `<div><b>${_('The whole hand is placed.')}</b></div>
               <div class="iaw-hint">${_('Confirm, or choose a town to resolve first.')}</div>`);

        this.buttons(remaining);
    }

    private buttons(remaining: number): void {
        this.bga.statusBar.removeActionButtons();

        if (this.choosingResolution) {
            this.bga.statusBar.addActionButton(
                this.resolveTarget
                    ? _('Confirm and resolve') + ' ' + this.townLabel(this.resolveTarget)
                    : _('Pick a town to resolve'),
                () => this.commit(),
                { disabled: this.resolveTarget === null },
            );
            this.bga.statusBar.addActionButton(_('Back'), () => {
                this.choosingResolution = false;
                this.resolveTarget = null;
                this.refresh();
            }, { color: 'secondary' });
            return;
        }

        this.bga.statusBar.addActionButton(
            _('Confirm placement'),
            () => this.commit(),
            { disabled: remaining > 0 },
        );
        this.bga.statusBar.addActionButton(
            _('Resolve a town…'),
            () => {
                this.choosingResolution = true;
                this.refresh();
            },
            { color: 'secondary', disabled: remaining > 0 },
        );
        this.bga.statusBar.addActionButton(_('Reset'), () => {
            this.reset();
            this.refresh();
        }, { color: 'secondary' });
    }

    private townLabel(townId: string): string {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
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
            resolve: this.resolveTarget ?? '',
        });
    }
}
