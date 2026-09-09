import { Game } from "../Game";

/**
 * The resolution phase: the first thing either side does on its turn, and a
 * phase of its own rather than a step staged with the rest.
 *
 * That is the whole point. A resolution is judged on the board as your opponent
 * left it (Decision 4), and when it was staged alongside the turn the client
 * could not know how it would come out — so it had to forbid placing into the
 * town, and forbid marching out of it, with no way to explain why. Sending it
 * on its own means the board the player then plans against is the real one.
 *
 * The price is that a resolution cannot be taken back, which is why it takes a
 * click to choose and a button to commit.
 */
export class Resolve {
    private target: string | null = null;
    private args: ResolveArgs;

    constructor(
        private game: Game,
        private bga: Bga<IronAndWhisperPlayer, IronAndWhisperGamedatas>,
    ) {
    }

    onEnteringState(args: ResolveArgs, isCurrentPlayerActive: boolean) {
        this.args = {
            side: args?.side ?? 'empire',
            resolvable: args?.resolvable ?? [],
        };
        this.target = null;

        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} may resolve a town'));
            this.game.setStagingText(
                `<div class="iaw-hint">${_('Your opponent is deciding whether to resolve a town.')}</div>`
            );
            return;
        }

        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.refresh();
    }

    onLeavingState() {
        this.target = null;
        this.game.board.clearInteraction();
        this.game.setStagingText('');
    }

    private onTownClick(townId: string): void {
        // Toggle, so a mis-click is undone by clicking the same town again.
        if (this.args.resolvable.includes(townId)) {
            this.target = this.target === townId ? null : townId;
            this.refresh();
        }
    }

    private refresh(): void {
        this.bga.statusBar.setTitle(_('${you} may resolve one town, before anything else happens'));

        this.game.board.setSelectable(this.args.resolvable);
        this.game.board.setSelected(this.target ? [this.target] : []);
        this.game.setStagingText(this.stagingHtml());
        this.buttons();
    }

    private stagingHtml(): string {
        const explanation = this.args.side === 'empire'
            ? _('A town you garrison is judged now, against the cards standing in it. Win and you keep the garrison and the town; lose and the troops are gone.')
            : _('A town you already have cards in is judged now, against the garrison standing in it. This turn\'s cards are not down yet and do not count.');

        if (!this.target) {
            return `<div><b>${_('Resolve a town, or none')}</b></div>
                    <div class="iaw-hint">${explanation}</div>
                    <div class="iaw-hint">${_('It happens at once, and cannot be taken back.')}</div>`;
        }

        return `<div><b>${_('Resolving')} ${this.townLabel(this.target)}</b></div>
                <div class="iaw-hint">${explanation}</div>
                <div class="iaw-hint">${_('It happens at once, and cannot be taken back.')}</div>`;
    }

    private buttons(): void {
        this.bga.statusBar.removeActionButtons();

        this.bga.statusBar.addActionButton(
            this.target
                ? _('Resolve') + ' ' + this.townLabel(this.target)
                : _('Resolve'),
            () => this.bga.actions.performAction('actResolve', { town: this.target }),
            { disabled: this.target === null },
        );

        this.bga.statusBar.addActionButton(
            _('Resolve nothing this turn'),
            () => this.bga.actions.performAction('actSkipResolve', {}),
            { color: 'secondary' },
        );
    }

    private townLabel(townId: string): string {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }
}
