import { Game } from "../Game";

/**
 * The Empire stages a raise and any number of marches, then commits.
 *
 * Looking is not staged and never appears here: every troop that did not move
 * peeks, there is no decision in it, and the server does it at the end of the
 * move step.
 */
export class EmpireTurn {
    private generateAt: string | null = null;
    private moves: StagedMove[] = [];
    private source: string | null = null;
    private resolveTarget: string | null = null;
    private mode: 'move' | 'generate' | 'resolve' = 'move';
    private args: EmpireTurnArgs;

    constructor(
        private game: Game,
        private bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>,
    ) {
    }

    onEnteringState(args: EmpireTurnArgs, isCurrentPlayerActive: boolean) {
        this.args = args;
        this.reset();

        this.bga.statusBar.setTitle(isCurrentPlayerActive
            ? _('${you} may raise a troop and move, and may then resolve one town')
            : _('${actplayer} must move'));

        if (!isCurrentPlayerActive) {
            return;
        }

        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.refresh();
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
        this.mode = 'move';
    }

    // -- staging ------------------------------------------------------------

    private onTownClick(townId: string): void {
        if (this.mode === 'generate') {
            if (this.args.generationTowns.includes(townId)) {
                this.generateAt = townId;
                this.mode = 'move';
            }
            this.refresh();
            return;
        }

        if (this.mode === 'resolve') {
            if (this.projected(townId) > 0 && !this.game.board.getTown(townId).resolved) {
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

        // Clicking a neighbour marches one more troop into it, so a stack is
        // moved by clicking the same town repeatedly.
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
     * Troops as they will stand after this turn's raise and marches. Every
     * decision — what may move, what may resolve — is judged against this rather
     * than against the board as it looks now (Decision 4).
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

    // -- display ------------------------------------------------------------

    private refresh(): void {
        const pending: Record<string, string> = {};
        Object.keys(this.game.board.allTowns()).forEach(townId => {
            const projected = this.projected(townId);
            if (projected !== this.game.board.getTown(townId).troops) {
                pending[townId] = `→ ${projected}`;
            }
        });
        if (this.generateAt) {
            pending[this.generateAt] = (pending[this.generateAt] ?? '') + ' ⚑';
        }
        this.game.board.setPending(pending);

        this.game.board.setSelectable(this.selectableTowns());
        this.game.board.setSelected(
            this.mode === 'resolve' && this.resolveTarget ? [this.resolveTarget]
                : this.source ? [this.source] : [],
        );

        this.game.setStagingText(this.stagingHtml());
        this.buttons();
    }

    private selectableTowns(): string[] {
        const all = Object.keys(this.game.board.allTowns());

        if (this.mode === 'generate') {
            return this.args.generationTowns;
        }
        if (this.mode === 'resolve') {
            return all.filter(townId =>
                this.projected(townId) > 0 && !this.game.board.getTown(townId).resolved);
        }
        if (this.source !== null) {
            return this.game.board.neighborsOf(this.source);
        }
        return all.filter(townId => this.projected(townId) > 0);
    }

    private stagingHtml(): string {
        const lines: string[] = [];
        if (this.generateAt) {
            lines.push(`<div>${_('Raising at')} ${this.townLabel(this.generateAt)}</div>`);
        }
        this.moves.forEach(move => {
            lines.push(`<div>${move.count} → ${this.townLabel(move.from)} ⇒ ${this.townLabel(move.to)}</div>`);
        });
        if (this.source) {
            lines.push(`<div>${_('Marching from')} ${this.townLabel(this.source)}</div>`);
        }
        return lines.join('') || `<div>${_('Standing fast.')}</div>`;
    }

    private buttons(): void {
        this.bga.statusBar.removeActionButtons();

        if (this.mode === 'resolve') {
            this.bga.statusBar.addActionButton(
                this.resolveTarget
                    ? _('Confirm and resolve') + ' ' + this.townLabel(this.resolveTarget)
                    : _('Pick a town to resolve'),
                () => this.commit(),
                { disabled: this.resolveTarget === null },
            );
            this.bga.statusBar.addActionButton(_('Back'), () => {
                this.mode = 'move';
                this.resolveTarget = null;
                this.refresh();
            }, { color: 'secondary' });
            return;
        }

        this.bga.statusBar.addActionButton(_('Confirm'), () => this.commit());

        if (this.mode === 'generate') {
            this.bga.statusBar.addActionButton(_('Cancel raise'), () => {
                this.mode = 'move';
                this.refresh();
            }, { color: 'secondary' });
        } else {
            this.bga.statusBar.addActionButton(
                this.generateAt ? _('Raise somewhere else…') : _('Raise a troop…'),
                () => {
                    this.mode = 'generate';
                    this.source = null;
                    this.refresh();
                },
                { color: 'secondary' },
            );
        }

        this.bga.statusBar.addActionButton(_('Resolve a town…'), () => {
            this.mode = 'resolve';
            this.source = null;
            this.refresh();
        }, { color: 'secondary' });

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
