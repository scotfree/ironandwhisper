import { BoardView } from "./BoardView";

/** Where the written rules live. The repo is the source of truth for them. */
const RULES_URL =
    'https://github.com/scotfree/ironandwhisper/blob/main/ironandwhisper.md';

/**
 * The cheat sheet, and the side-specific reminder beside the board.
 *
 * Both draw the *real* components — the silhouettes fetched from img/, the
 * pawn, the stack and badge markup the board itself uses — rather than pictures
 * of them, so the legend cannot drift from what is actually on screen. That is
 * also why this needs the BoardView: it owns the SVG files.
 *
 * Numbers come from the scenario for the same reason. Hand size and game length
 * are tuning knobs that live in `scenarios/`, and a help page with last month's
 * numbers written into it is worse than no help page.
 */
export class Help {
    private dialog: PopinDialog | null = null;

    constructor(
        private scenario: ScenarioView,
        private board: BoardView,
    ) {
    }

    /**
     * Put a ? at the right-hand end of the status bar.
     *
     * Deliberately not a status bar action button: `removeActionButtons()` runs
     * on every state change and would take it with it. This lives in a corner of
     * the title bar of its own, added once.
     */
    install(): void {
        const bar = document.getElementById('page-title');
        if (!bar || document.getElementById('iaw-help-corner')) {
            return;
        }

        bar.insertAdjacentHTML('beforeend', `
            <div id="iaw-help-corner">
                <button id="iaw-help-button" type="button"
                        title="${_('How to play')}"
                        aria-label="${_('How to play')}">?</button>
            </div>
        `);
        document.getElementById('iaw-help-button')
            ?.addEventListener('click', () => this.show());
    }

    show(): void {
        if (!this.dialog) {
            this.dialog = new ebg.popindialog();
            this.dialog.create('iaw-help-dialog');
            this.dialog.setTitle(_('Iron and Whispers — how to play'));
            this.dialog.setMaxWidth(760);
        }
        // Set every time: the silhouettes arrive after the first paint, so a
        // dialog built at setup would have an empty legend for ever.
        this.dialog.setContent(this.sheetHtml());
        this.dialog.show();
    }

    // -- the cheat sheet ----------------------------------------------------

    private sheetHtml(): string {
        return `
            <div class="iaw-help">
                ${this.paragraphs()}
                <div class="iaw-help-heading">${_('What you are looking at')}</div>
                ${this.legendHtml()}
                <p class="iaw-help-more">
                    <a href="${RULES_URL}" target="_blank" rel="noopener">
                        ${_('The full rules, and the reasoning behind every decision')}</a>
                </p>
            </div>
        `;
    }

    private paragraphs(): string {
        const ties = this.scenario.empireWinsTies ? _('ties go to the Empire') : _('ties go to the Insurgency');

        return `
            <p>${_('An asymmetric game for two. The Empire moves troops everyone can see; the Insurgency plays cards nobody can. A town is settled when one side <b>resolves</b> it: the presence the rebels have there against the presence the Empire has, higher wins, and')} ${ties}. ${_('The winner scores what the loser committed — each side scores the presence it took off the other — and the loser\'s commitment leaves the board for good. A walkover scores nothing: there is no prize for a town nobody contested.')}</p>

            <p>${_('<b>Resolution comes first in a turn</b>, and it is judged on the board as your opponent left it. You may only resolve a town you are present in — the Empire needs a troop there, the rebels need a card in the pile — so you cannot march in and cash out on arrival. Whatever you commit has to survive a reply.')}</p>

            <p>${_('<b>The deck is the clock.</b>')} ${_('The rebels place their entire hand every turn, so the game runs ${turns} turns, or fewer if every town is resolved first.').replace('${turns}', String(this.scenario.turns))} ${_('Four things end it: the deck runs out, every town is resolved, the Empire is eliminated, or both sides have a standing offer to end. Everything still open then resolves at once, at whatever is standing.')}</p>

            <p>${_('<b>Supply limits an army, not production.</b> Occupied towns that touch form a network, and its supply is the most troops it can keep standing. Go over and they are marked; if the network is still short at the end of the Empire\'s next turn they starve, and the rebels score them. Building past the ceiling is legal, so the Empire may raise troops and march them out to the supply that will feed them in one motion.')}</p>
        `;
    }

    private legendHtml(): string {
        const art = (svg: string) => `<span class="iaw-legend-art">${svg}</span>`;
        const stack = (kind: string, count: number, sum?: number | string) =>
            `<span class="iaw-stack ${kind}"><span class="iaw-stack-count">${count}</span>${
                sum === undefined ? '' : `<span class="iaw-stack-sum${
                    sum === '?' ? ' unknown' : ''}">${sum}</span>`}</span>`;

        const rows: [string, string][] = [
            [art(this.board.townSvg()),
             _('A town. Adds its supply to whatever Empire network holds it.')],
            [art(this.board.citySvg()) + ' <span class="iaw-produce">&#128296;</span>',
             _('A city, marked with a hammer. Also builds a troop a turn for whoever holds it.')],
            [`<span class="iaw-troops">${this.board.pawnSvg()
                ? `<span class="iaw-pawn">${this.board.pawnSvg()}</span>` : ''
             }<span class="iaw-troop-count">3</span></span>`,
             _('Empire troops standing here. Each is worth ${presence} presence at a resolution.')
                .replace('${presence}', String(this.scenario.unit.presence))],
            [stack('face-down', 4, '?'),
             _('Face-down agents: how many, and what they total. The rebels see their own total; the Empire sees a question mark. Click either stack to see the pile in order.')],
            [stack('face-up', 2, 3),
             _('Face-up agents and their presence. A troop that does not march turns one card over each turn.')],
            [`<span class="iaw-supply">2/4</span>`,
             _('Troops standing in this network, and the most it can supply.')],
            [`<span class="iaw-contribution">(2)</span>`,
             _('What this town adds to that. A town the rebels have won adds nothing, for ever.')],
            [`<span class="iaw-troops-doomed">&minus;1</span>`,
             _('Starving. Lost at the end of the Empire\'s next turn unless the supply line is repaired first.')],
            [`<span class="iaw-troop-delta">+1</span>
              <span class="iaw-card-delta">+2 ${_('cards')}</span>`,
             _('What you are staging this turn, shown beside what is already there.')],
            [`<span class="iaw-chip">+2</span><span class="iaw-chip face-down"></span>`,
             _('Agents above a town: face up while you are placing them, and greyed afterwards to show what your opponent placed on their last turn.')],
        ];

        return `<table class="iaw-legend">${rows.map(([icon, text]) =>
            `<tr><td class="iaw-legend-icon">${icon}</td><td>${text}</td></tr>`).join('')}</table>`;
    }

    // -- the reminder beside the board --------------------------------------

    /**
     * The numbered turn order for a side, and which step is live.
     *
     * Split out of the primer so the two can be shown separately: the phase
     * list changes constantly and belongs with the turn and deck counts, while
     * the primer never changes and is the part an experienced player wants to
     * hide. `current` is a step index, or -1 for none of them — the steps that
     * happen on commit are never "current", because nobody is ever asked about
     * them.
     */
    phaseListHtml(side: Side | null, current: number): string {
        return `
            <ol class="iaw-phases">${this.steps(side).map((step, index) =>
                `<li class="${index === current ? 'current' : ''}">${step}</li>`).join('')}</ol>
        `;
    }

    private steps(side: Side | null): string[] {
        return side === 'insurgency'
            ? [
                _('Resolve a town you have a card in'),
                _('Place your entire hand'),
                _('Draw back up to ${hand}').replace('${hand}', String(this.scenario.handSize)),
            ]
            : [
                _('Resolve a town you have troops in'),
                _('Build in cities you hold'),
                _('March along roads'),
                _('Troops that stayed put each read a card'),
                _('Troops over supply starve'),
            ];
    }

    /**
     * A permanent few lines saying what your side does.
     *
     * Spectators get the Empire's, arbitrarily: something is more use than an
     * empty box. No turn order here any more — that is `phaseListHtml`, which
     * lives with the rest of the game state.
     */
    primerHtml(side: Side | null): string {
        const rebel = side === 'insurgency';

        const summary = rebel
            ? _('You place ${hand} hidden agents on towns each turn; some are decoys, some carry real presence. When you think a town\'s cards overpower its garrison, <b>resolve</b> it and find out: you score the presence you drive out, if you win.')
                .replace('${hand}', String(this.scenario.handSize))
            : _('You build troops in cities, march them along roads, and keep them supplied by networks of occupied towns. When you think a garrison outweighs the rebels\' presence in a town, <b>resolve</b> it and find out: you score the presence you capture, if you win.');

        return `
            <div class="iaw-primer ${rebel ? 'insurgency' : 'empire'}">
                <div class="iaw-heading">${rebel
                    ? _('You play the Rebels')
                    : _('You play the Empire')}</div>
                <p>${summary}</p>
                <button type="button" class="iaw-primer-more">${_('How to play')}</button>
            </div>
        `;
    }

    /**
     * One card, big, with what it does spelled out.
     *
     * Built from `data/cards.json` rather than written here, so a card that
     * gains a rule gains it in one place. The decoy line is the whole reason
     * this exists: a zero is not a broken card, it is a card with a use.
     */
    cardDetailHtml(card: CardView): string {
        const known = card.presence !== null;
        const type = known ? this.scenario.cardTypes[card.type as string] : null;
        const value = card.presence ?? 0;

        return `
            <div class="iaw-detail">
                <div class="iaw-detail-card ${known ? card.type : 'unknown'}"
                    >${known ? `+${value}` : '?'}</div>
                <div class="iaw-detail-name">${known
                    ? (type?.label ?? `${_('Agent')} +${value}`)
                    : _('A face-down agent')}</div>
                <div class="iaw-detail-text">${known
                    ? (value > 0
                        ? _('Adds ${n} presence to the rebels in the town it is placed in.')
                            .replace('${n}', String(value))
                        : _('Adds no presence. Use it as a decoy: face down it is indistinguishable from any other agent, and it makes a pile look dangerous.'))
                    : _('The Empire knows it is there and how deep in the pile it sits, but not what it is worth.')}</div>
            </div>
        `;
    }

    /**
     * One troop, in the same shape as a card, so the two read as comparable
     * things — which is the point of both carrying presence.
     */
    troopDetailHtml(): string {
        const unit = this.scenario.unit;
        return `
            <div class="iaw-detail">
                <div class="iaw-detail-art">${this.board.pawnSvg()}</div>
                <div class="iaw-detail-name">${unit.label}</div>
                <div class="iaw-detail-text">${
                    _('Presence +${presence}. Moves ${movement} town per turn. Costs ${supply} supply to keep standing, and reads ${peek} card per turn when it holds still.')
                        .replace('${presence}', String(unit.presence))
                        .replace('${movement}', String(unit.movement))
                        .replace('${supply}', String(this.scenario.supplyPerTroop))
                        .replace('${peek}', String(unit.peek))}</div>
            </div>
        `;
    }
}
