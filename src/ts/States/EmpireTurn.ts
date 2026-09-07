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
    /** Town id => troops being built there this turn. */
    private produce: Record<string, number> = {};
    private moves: StagedMove[] = [];
    private source: string | null = null;
    private resolveTarget: string | null = null;
    private step: 'resolve' | 'build' | 'move' = 'resolve';
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
            production: args?.production ?? {},
            networks: args?.networks ?? [],
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
        this.produce = {};
        this.moves = [];
        this.source = null;
        this.resolveTarget = null;
        // Resolution is the first thing in the turn (Decision 4): it is judged
        // on the board as the Insurgency left it, so it has to be settled
        // before anything moves.
        this.step = this.args.resolvable.length > 0
            ? 'resolve'
            : (this.buildable().length > 0 ? 'build' : 'move');
    }

    /** Towns that can build at least one troop this turn. */
    private buildable(): string[] {
        return Object.keys(this.args.production).filter(id => this.args.production[id] > 0);
    }

    /**
     * How many more troops this town may build, given what is already staged.
     *
     * Two production towns in one network draw on the same ceiling, so the
     * spare has to be counted per network rather than per town.
     */
    private buildRoom(townId: string): number {
        const offered = this.args.production[townId] ?? 0;
        const network = this.game.board.networkOf(townId);
        if (!network) {
            return 0;
        }

        const staged = network.towns.reduce((total, id) => total + (this.produce[id] ?? 0), 0);
        const spare = network.ceiling - network.troops - staged;

        return Math.max(0, Math.min(offered - (this.produce[townId] ?? 0), spare));
    }

    // -- staging ------------------------------------------------------------

    private onTownClick(townId: string): void {
        if (this.step === 'build') {
            // Click again to build another, while supply and the town allow it.
            if (this.buildRoom(townId) > 0) {
                this.produce[townId] = (this.produce[townId] ?? 0) + 1;
            }
            this.refresh();
            return;
        }

        if (this.step === 'resolve') {
            // Toggle, so a mis-click is undone by clicking the same town again.
            if (this.args.resolvable.includes(townId)) {
                this.resolveTarget = this.resolveTarget === townId ? null : townId;
            }
            this.refresh();
            return;
        }

        if (this.source === null) {
            if (this.marchableFrom().includes(townId)) {
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

    /**
     * Towns troops may march out of.
     *
     * A town staged for resolution is excluded: the server resolves first, and
     * if the Insurgency wins there the garrison is gone before the march would
     * happen, which would make the whole turn illegal.
     */
    private marchableFrom(): string[] {
        return Object.keys(this.game.board.allTowns()).filter(
            townId => this.projected(townId) > 0 && townId !== this.resolveTarget,
        );
    }

    /**
     * Troops as they will stand once this turn is committed.
     */
    private projected(townId: string): number {
        let troops = this.game.board.getTown(townId).troops + (this.produce[townId] ?? 0);
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
        const title = this.title();
        this.bga.statusBar.setTitle(title.text, title.args);

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

    /**
     * The title carries the whole state of the marching interaction, because
     * the highlights alone were too easy to miss: which click the game is
     * waiting for, and from where.
     */
    private title(): { text: string; args?: any } {
        if (this.step === 'build') {
            return { text: _('${you} may build: click a highlighted town, again for another troop') };
        }
        if (this.step === 'resolve') {
            return { text: _('${you} may resolve a town held since the start of the turn') };
        }
        if (this.source === null) {
            return { text: _('${you} must select a town to move troops from') };
        }
        return {
            text: _('${you} must select where to move troops from ${town} to'),
            args: { town: this.townLabel(this.source) },
        };
    }

    private selectableTowns(): string[] {
        const all = Object.keys(this.game.board.allTowns());

        if (this.step === 'build') {
            return this.buildable().filter(id => this.buildRoom(id) > 0);
        }
        if (this.step === 'resolve') {
            return this.args.resolvable;
        }
        if (this.source !== null) {
            return this.game.board.neighborsOf(this.source);
        }
        return this.marchableFrom();
    }

    private stagingHtml(): string {
        const lines: string[] = [];

        const built = Object.entries(this.produce).filter(([, count]) => count > 0);
        lines.push(this.resolveTarget
            ? `<div>${_('Resolving')} <b>${this.townLabel(this.resolveTarget)}</b> ${_('first')}</div>`
            : `<div>${_('Resolving nothing')}</div>`);

        lines.push(built.length
            ? built.map(([townId, count]) =>
                `<div>${_('Building')} ${count} ${_('at')} <b>${this.townLabel(townId)}</b></div>`).join('')
            : `<div>${_('Building nothing')}</div>`);

        const network = this.game.board.networkOf(this.buildable()[0] ?? '');
        if (network) {
            lines.push(`<div class="iaw-hint">${_('Supply here')}: ${network.troops} / ${network.ceiling}</div>`);
        }

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
            lines.push(this.source === null
                ? `<div class="iaw-hint">${_('Click a town with troops to march from. Highlighted towns are the ones that have any.')}</div>`
                : `<div class="iaw-hint">${_('Click a neighbour to send a troop there, again to send another.')}
                   ${_('Click')} <b>${this.townLabel(this.source)}</b> ${_('again to march from somewhere else instead.')}</div>`);
        }

        return lines.join('');
    }

    private buttons(): void {
        this.bga.statusBar.removeActionButtons();

        if (this.step === 'build') {
            this.bga.statusBar.addActionButton(_('Done building'), () => {
                this.step = 'move';
                this.refresh();
            });
            this.bga.statusBar.addActionButton(_('Reset'), () => {
                this.reset();
                this.refresh();
            }, { color: 'secondary' });
            return;
        }

        if (this.step === 'resolve') {
            this.bga.statusBar.addActionButton(
                this.resolveTarget
                    ? _('Resolve') + ' ' + this.townLabel(this.resolveTarget)
                    : _('Resolve nothing this turn'),
                () => {
                    this.step = this.buildable().length > 0 ? 'build' : 'move';
                    this.refresh();
                },
            );
            return;
        }

        this.bga.statusBar.addActionButton(_('Confirm turn'), () => this.commit());

        if (this.buildable().length) {
            this.bga.statusBar.addActionButton(_('Build…'), () => {
                this.step = 'build';
                this.source = null;
                this.refresh();
            }, { color: 'secondary' });
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
            produce: JSON.stringify(this.produce),
            moves: JSON.stringify(this.moves),
            resolve: this.resolveTarget ?? '',
            // Attrition falls where the server decides unless told otherwise;
            // choosing which garrison starves is not yet exposed here.
            disband: JSON.stringify({}),
        });
    }
}
