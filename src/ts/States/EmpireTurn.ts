import { Game } from "../Game";

/**
 * The Empire raises a troop, marches, and may resolve — in that order, because
 * that is the order the rules apply them and a resolution is judged against
 * where troops end up, not where they started (Decision 4).
 *
 * The turn is presented as that sequence rather than as free-form modes. With
 * modes, a player looking at the board on turn one sees a single faintly
 * highlighted town and no indication that raising is a thing they may do.
 *
 * Looking never appears here. Every troop that did not move peeks, there is no
 * decision in it, and the server does it when the turn is committed. The panel
 * says so, because a player who does not know that will go hunting for a button.
 */
export class EmpireTurn {
    private generateAt: string | null = null;
    private moves: StagedMove[] = [];
    private source: string | null = null;
    private resolveTarget: string | null = null;
    private step: 'raise' | 'move' | 'resolve' = 'raise';
    private args: EmpireTurnArgs;

    constructor(
        private game: Game,
        private bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>,
    ) {
    }

    onEnteringState(args: EmpireTurnArgs, isCurrentPlayerActive: boolean) {
        // Defensive: a throw in here takes the whole handler with it, and the
        // symptom is a board where nothing is clickable and no buttons appear.
        this.args = {
            generationTowns: args?.generationTowns ?? [],
            resolvable: args?.resolvable ?? [],
        };
        this.reset();

        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} must move'));
            this.game.setStagingText(this.watchingHtml());
            return;
        }

        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.refresh();
    }

    /**
     * Shown when it is the Empire's turn and you are not the Empire. Without
     * this the screen is indistinguishable from a broken one.
     */
    private watchingHtml(): string {
        return `<div class="iaw-hint">${_('The Empire is moving. You are the Insurgency, so there is nothing to do until it is your turn.')}</div>`;
    }

    onLeavingState() {
        this.reset();
        this.game.board.clearInteraction();
        this.game.setStagingText('');
    }

    private reset(): void {
        this.generateAt = null;
        this.moves = [];
        this.source = null;
        this.resolveTarget = null;
        // Skip straight to marching if there is nowhere legal to raise.
        this.step = this.args.generationTowns.length > 0 ? 'raise' : 'move';
    }

    // -- staging ------------------------------------------------------------

    private onTownClick(townId: string): void {
        if (this.step === 'raise') {
            if (this.args.generationTowns.includes(townId)) {
                this.generateAt = townId;
                this.step = 'move';
            }
            this.refresh();
            return;
        }

        if (this.step === 'resolve') {
            if (this.canResolve(townId)) {
                this.resolveTarget = townId;
            }
            this.refresh();
            return;
        }

        if (this.source === null) {
            if (this.projected(townId) > 0) {
                this.source = townId;
            }
            this.refresh();
            return;
        }

        if (townId === this.source) {
            this.source = null;
            this.refresh();
            return;
        }

        // Clicking a neighbour marches one more troop into it, so a stack moves
        // by clicking the same town repeatedly.
        if (this.game.board.neighborsOf(this.source).includes(townId) && this.projected(this.source) > 0) {
            this.addMove(this.source, townId);
        }
        this.refresh();
    }

    private addMove(from: string, to: string): void {
        const existing = this.moves.find(move => move.from === from && move.to === to);
        if (existing) {
            existing.count += 1;
        } else {
            this.moves.push({ from, to, count: 1 });
        }
    }

    private canResolve(townId: string): boolean {
        return this.projected(townId) > 0 && !this.game.board.getTown(townId).resolved;
    }

    /**
     * Troops as they will stand once this turn is committed.
     */
    private projected(townId: string): number {
        let troops = this.game.board.getTown(townId).troops;
        if (this.generateAt === townId) {
            troops += this.bga.gameui.gamedatas.scenario.generationRate;
        }
        this.moves.forEach(move => {
            if (move.from === townId) {
                troops -= move.count;
            }
            if (move.to === townId) {
                troops += move.count;
            }
        });
        return troops;
    }

    /** Towns that will hold a stationary troop, and so will read a card. */
    private willLook(): string[] {
        return Object.keys(this.game.board.allTowns()).filter(townId => {
            const town = this.game.board.getTown(townId);
            if (town.resolved || town.pileSize === 0) {
                return false;
            }
            const arriving = this.moves
                .filter(move => move.to === townId)
                .reduce((total, move) => total + move.count, 0);
            return this.projected(townId) - arriving > 0;
        });
    }

    // -- display ------------------------------------------------------------

    private refresh(): void {
        this.bga.statusBar.setTitle(this.title());

        // Show the change, not the result: a town with two troops that is
        // raising reads "2+1", and the marches are drawn on the roads.
        const delta: Record<string, number> = {};
        Object.keys(this.game.board.allTowns()).forEach(townId => {
            const change = this.projected(townId) - this.game.board.getTown(townId).troops;
            if (change !== 0) {
                delta[townId] = change;
            }
        });
        this.game.board.setTroopDelta(delta);
        this.game.board.setMoveArrows(this.moves);

        this.game.board.setSelectable(this.selectableTowns());
        this.game.board.setSelected(
            this.step === 'resolve' && this.resolveTarget ? [this.resolveTarget]
                : this.source ? [this.source] : [],
        );

        this.game.setStagingText(this.stagingHtml());
        this.buttons();
    }

    private title(): string {
        if (this.step === 'raise') {
            return _('${you} may raise a troop: click a highlighted town');
        }
        if (this.step === 'resolve') {
            return _('${you} must choose a town to resolve');
        }
        return this.source === null
            ? _('${you} may march: click a town with troops')
            : _('${you} may march: click a neighbouring town');
    }

    private selectableTowns(): string[] {
        const all = Object.keys(this.game.board.allTowns());

        if (this.step === 'raise') {
            return this.args.generationTowns;
        }
        if (this.step === 'resolve') {
            return all.filter(townId => this.canResolve(townId));
        }
        if (this.source !== null) {
            return this.game.board.neighborsOf(this.source);
        }
        return all.filter(townId => this.projected(townId) > 0);
    }

    private stagingHtml(): string {
        const lines: string[] = [];

        lines.push(this.generateAt
            ? `<div>${_('Raising at')} <b>${this.townLabel(this.generateAt)}</b></div>`
            : `<div>${_('No troop raised')}</div>`);

        this.moves.forEach(move => {
            lines.push(`<div>${move.count} ${_('from')} <b>${this.townLabel(move.from)}</b>
                        ${_('to')} <b>${this.townLabel(move.to)}</b></div>`);
        });
        if (!this.moves.length) {
            lines.push(`<div>${_('No marches')}</div>`);
        }

        const looking = this.willLook();
        lines.push(`<div class="iaw-hint">${_('Troops that do not march read one card each, automatically, when you confirm.')}
                    ${looking.length
                        ? _('This turn they will read in') + ': ' + looking.map(id => this.townLabel(id)).join(', ')
                        : _('None of them are standing over a pile this turn.')}</div>`);

        if (this.step === 'move') {
            lines.push(`<div class="iaw-hint">${_('Click a town with troops, then a neighbour. Click the same neighbour again to send another troop.')}</div>`);
        }

        return lines.join('');
    }

    private buttons(): void {
        this.bga.statusBar.removeActionButtons();

        if (this.step === 'raise') {
            this.bga.statusBar.addActionButton(_('Raise nothing this turn'), () => {
                this.step = 'move';
                this.refresh();
            }, { color: 'secondary' });
            return;
        }

        if (this.step === 'resolve') {
            this.bga.statusBar.addActionButton(
                this.resolveTarget
                    ? _('Confirm and resolve') + ' ' + this.townLabel(this.resolveTarget)
                    : _('Pick a town to resolve'),
                () => this.commit(),
                { disabled: this.resolveTarget === null },
            );
            this.bga.statusBar.addActionButton(_('Back'), () => {
                this.step = 'move';
                this.resolveTarget = null;
                this.refresh();
            }, { color: 'secondary' });
            return;
        }

        this.bga.statusBar.addActionButton(_('Confirm turn'), () => this.commit());
        this.bga.statusBar.addActionButton(_('Resolve a town…'), () => {
            this.step = 'resolve';
            this.source = null;
            this.refresh();
        }, { color: 'secondary' });

        if (this.args.generationTowns.length) {
            this.bga.statusBar.addActionButton(
                this.generateAt ? _('Raise somewhere else…') : _('Raise a troop…'),
                () => {
                    this.step = 'raise';
                    this.source = null;
                    this.refresh();
                },
                { color: 'secondary' },
            );
        }

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
        this.bga.actions.performAction('actCommitTurn', {
            generateAt: this.generateAt ?? '',
            moves: JSON.stringify(this.moves),
            resolve: this.resolveTarget ?? '',
        });
    }
}
