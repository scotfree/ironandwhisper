import { Game } from "../Game";
import { endOfferHtml, endOfferLabel } from "../EndOffer";

/**
 * The Empire raises a troop and marches.
 *
 * Any resolution happened in the Resolve state before this one, so the board
 * here is final — which is what lets a garrison that just won its town march
 * straight back out of it. While the resolution was staged with the rest of the
 * turn, the client could not know whether those troops would still exist, and
 * had to forbid the march for no reason it could give.
 *
 * The turn is presented as a sequence rather than as free-form modes. With
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
    private step: 'build' | 'move' = 'build';
    /** Standing offer to end the game, sent with the turn. */
    private offerEnd = false;
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
            offeredEnd: args?.offeredEnd ?? false,
            opponentOfferedEnd: args?.opponentOfferedEnd ?? false,
        };
        this.reset();

        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} must move'));
            this.game.setStagingText(this.watchingHtml());
            this.game.setPhase(-1);
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
        this.game.clearZoom();
        this.game.setPhase(-1);
        this.game.renderLastTurn();
    }

    private reset(): void {
        this.produce = {};
        this.moves = [];
        this.source = null;
        this.step = this.buildable().length > 0 ? 'build' : 'move';
        // An offer stands until it is withdrawn, so it starts where it was left.
        this.offerEnd = this.args.offeredEnd;
    }

    /** Towns that can build at least one troop this turn. */
    private buildable(): string[] {
        return Object.keys(this.args.production).filter(id => this.args.production[id] > 0);
    }

    /**
     * How many more troops this town may build, given what is already staged.
     *
     * Its own production rate is the only limit. Supply is not: a network's
     * ceiling caps what it can keep, not what it can raise, and building past
     * it is legal — the panel warns instead, because attrition gives a turn of
     * grace and the troops may well be marched out to supply before it falls.
     */
    private buildRoom(townId: string): number {
        const offered = this.args.production[townId] ?? 0;
        return Math.max(0, offered - (this.produce[townId] ?? 0));
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
        if (this.game.board.neighborsOf(this.source).includes(townId) && this.marchable(this.source) > 0) {
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

    /** Towns troops may march out of. */
    private marchableFrom(): string[] {
        return Object.keys(this.game.board.allTowns()).filter(
            townId => this.marchable(townId) > 0,
        );
    }

    /**
     * Troops that may still march out of a town this turn.
     *
     * Not the same as what will be standing there afterwards: movement is
     * simultaneous, so a troop that *arrives* this turn cannot march on, while
     * a troop *built* here can — production is applied before movement, which
     * is what lets the Empire raise troops and walk them out to the supply that
     * will feed them in one motion.
     *
     * This used to be `projected`, which counts arrivals, so the client happily
     * staged a march the server then refused on commit: a real game lost a turn
     * to "fenn has 3 troops, tried to move 4" after a troop had been walked into
     * Fenn on the same turn. The rule is `Rules::planMoves` / `engine.apply_
     * empire_turn`: departures are checked against the garrison as the turn
     * began, plus whatever was built.
     */
    private marchable(townId: string): number {
        let troops = this.game.board.getTown(townId).troops + (this.produce[townId] ?? 0);
        this.moves.forEach(move => {
            if (move.from === townId) {
                troops -= move.count;
            }
        });
        return troops;
    }

    /**
     * Troops as they will stand once this turn is committed. Used for what the
     * board shows, never for what may march — see `marchable`.
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
            // Whoever may still march is exactly whoever held still: the
            // engine counts a troop as stationary when it did not depart, and
            // one raised here this turn counts with them.
            return this.marchable(townId) > 0;
        });
    }

    // -- display ------------------------------------------------------------

    private refresh(): void {
        const title = this.title();
        this.bga.statusBar.setTitle(title.text, title.args);
        // Building is step 2 of the Empire's turn, marching step 3.
        this.game.setPhase(this.step === 'build' ? 1 : 2);

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
        this.game.board.setBuildDelta({ ...this.produce });
        this.game.board.setMoveArrows(this.moves);

        this.game.board.setSelectable(this.selectableTowns());
        this.game.board.setSelected(this.source ? [this.source] : []);

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
        if (this.source === null) {
            return { text: _('${you} must select a town to move troops from') };
        }
        return {
            text: _('${you} must select where to move troops from ${town} to'),
            args: { town: this.townLabel(this.source) },
        };
    }

    private selectableTowns(): string[] {
        if (this.step === 'build') {
            return this.buildable().filter(id => this.buildRoom(id) > 0);
        }
        if (this.source !== null) {
            return this.game.board.neighborsOf(this.source);
        }
        return this.marchableFrom();
    }

    private stagingHtml(): string {
        const lines: string[] = [];

        const built = Object.entries(this.produce).filter(([, count]) => count > 0);
        lines.push(built.length
            ? built.map(([townId, count]) =>
                `<div>${_('Building')} ${count} ${_('at')} <b>${this.townLabel(townId)}</b></div>`).join('')
            : `<div>${_('Building nothing')}</div>`);

        const network = this.game.board.networkOf(this.buildable()[0] ?? '');
        if (network) {
            lines.push(`<div class="iaw-hint">${_('Supply here')}: ${network.troops} / ${network.ceiling}</div>`);
        }

        lines.push(this.supplyWarningHtml());
        lines.push(endOfferHtml(this.offerEnd, this.args.opponentOfferedEnd));

        this.moves.forEach((move, index) => {
            // The newest march flashes until the next action, so the thing you
            // just did is distinguishable from the pile of things you staged.
            const flash = index === this.moves.length - 1 ? ' class="iaw-flash"' : '';
            lines.push(`<div${flash}>${move.count} ${_('from')} <b>${this.townLabel(move.from)}</b>
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

    /**
     * What the turn being staged does to supply, judged on the board as it will
     * stand once it is committed — massing changes which towns are occupied, so
     * it changes the networks and not merely their load.
     *
     * The distinction that matters is whether a network is already under
     * notice. One that has just gone short is only marked; one that was marked
     * last turn loses its excess at the end of this one.
     */
    private supplyWarningHtml(): string {
        const over = this.game.board.overSupplied(townId => this.projected(townId));
        if (!over.length) {
            return '';
        }

        return over.map(network => {
            const where = network.towns.map(id => this.townLabel(id)).join(', ');
            return network.warned
                ? `<div class="iaw-warning"><b>${network.over} ${_('troops starve at the end of this turn')}</b>
                   — ${where} ${_('cannot feed them. Take ground or spread out to stop it.')}</div>`
                : `<div class="iaw-warning">${network.over} ${_('troops are short of supply')}
                   — ${where}. ${_('They starve at the end of your next turn unless the line is repaired.')}</div>`;
        }).join('');
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

        this.bga.statusBar.addActionButton(endOfferLabel(this.offerEnd), () => {
            this.offerEnd = !this.offerEnd;
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
            offerEnd: this.offerEnd ? '1' : '0',
            // Attrition falls where the server decides unless told otherwise;
            // choosing which garrison starves is not yet exposed here.
            disband: JSON.stringify({}),
        });
    }
}
