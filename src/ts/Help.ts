import { BoardView } from "./BoardView";
import { presenceHtml } from "./presence";
import { primerEmpire, primerRebels } from "./primers.generated";

/**
 * The player's manual, served from the game's own folder.
 *
 * It used to link out to `ironandwhisper.md` on GitHub, because `*.md` is
 * excluded from the deploy and the rules were not on the BGA server. `rules.html`
 * is not excluded, so the manual now ships with the game: no external site, no
 * GitHub Pages to configure, and it works for a player who has never heard of
 * the repository. `g_gamethemeurl` is the game folder BGA serves the artwork
 * from, so the file sits beside `img/`.
 */
const rulesUrl = (): string => `${g_gamethemeurl}rules.html`;

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
/**
 * The icons that may appear in running text, by placeholder name.
 *
 * Every piece of prose that names a piece of the game draws that piece beside
 * the word: the text writes `${troop} troops`, and `withIcons` swaps the
 * placeholder for the real mark. Placeholders rather than markup, so a
 * translator sees `${troop}` instead of a wall of SVG, and so the `_()`
 * literal survives into the bundle where BGA's extractor can find it.
 */
export const ICON_NAMES = ['troop', 'town', 'city', 'agent', 'presence'] as const;
export type IconName = typeof ICON_NAMES[number];

export class Help {
    private dialog: PopinDialog | null = null;

    constructor(
        private scenario: ScenarioView,
        private board: BoardView,
    ) {
    }

    /**
     * One mark, at the size of a letter, for use inside a sentence.
     *
     * The pawn and the two silhouettes are the files from `img/`, the same
     * markup the board draws; the agent and the presence disc are the board's
     * own CSS, made small. None is a copy, so none can drift. A word joiner
     * follows each, so a line never breaks between an icon and its word.
     *
     * The SVGs arrive after the first paint (`BoardView.loadFrames`); until
     * then those three are empty boxes of the right size, and whatever drew
     * them is redrawn when the files land — see `Game.setup`.
     */
    icon(name: IconName): string {
        const svg = name === 'troop' ? this.board.pawnSvg()
            : name === 'town' ? this.board.townSvg()
            : name === 'city' ? this.board.citySvg()
            : '';
        return `<span class="iaw-icon iaw-icon-${name}" aria-hidden="true">${svg}</span>\u2060`;
    }

    /**
     * Replace every `${troop}`-style placeholder in `text` with its icon.
     *
     * The source writes `${troop} troops`, with a space, because that is how a
     * translator will read it. The space is absorbed here so the icon sits
     * against its word, as it does in the manual.
     */
    withIcons(text: string): string {
        return ICON_NAMES.reduce(
            (result, name) => result
                .split('${' + name + '} ').join(this.icon(name))
                .split('${' + name + '}').join(this.icon(name)),
            text,
        );
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
        // Rebuilt every time: a popin's close button destroys its DOM, so a
        // kept instance opens once and then does nothing at all.
        this.dialog?.destroy();
        this.dialog = new ebg.popindialog();
        this.dialog.create('iaw-help-dialog');
        this.dialog.setTitle(_('Iron and Whispers — how to play'));
        this.dialog.setMaxWidth(760);
        // Set every time: the silhouettes arrive after the first paint, so a
        // dialog built at setup would have an empty legend for ever.
        this.dialog.setContent(this.sheetHtml());
        this.dialog.show();
    }

    /**
     * The side reminder, blown up and shown full-screen at the start of the
     * game — the same frame `primerHtml` renders beside the board, not the
     * cheat sheet, so a player learns which side they are and what it does
     * without being handed the whole rulebook. Not a BGA popin: this needs to
     * disappear at the first click or keypress *anywhere*, and a popin only
     * closes from its own chrome.
     */
    showStartOverlay(side: Side | null): void {
        if (document.getElementById('iaw-start-overlay')) {
            return;
        }

        const overlay = document.createElement('div');
        overlay.id = 'iaw-start-overlay';
        overlay.innerHTML = `
            <div id="iaw-start-card">
                <div id="iaw-start-primer">${this.primerHtml(side)}</div>
                <div id="iaw-start-hint">${_('Click anywhere, or press any key, to continue')}</div>
            </div>
        `;
        document.body.appendChild(overlay);

        const dismiss = () => {
            overlay.remove();
            document.removeEventListener('keydown', dismiss);
        };
        // Everything dismisses this except the one control on it. Removing the
        // card mid-click can cancel the link's own navigation, and a player who
        // asked for the manual should not have to find it twice.
        overlay.addEventListener('click', event => {
            if ((event.target as HTMLElement)?.closest('.iaw-primer-more')) {
                return;
            }
            dismiss();
        });
        document.addEventListener('keydown', dismiss);
    }

    /**
     * Redraw the start card's text in place, if it is still up. The icons in
     * it are the SVG files, which can land after the card has opened.
     */
    refreshStartOverlay(side: Side | null): void {
        const primer = document.getElementById('iaw-start-primer');
        if (primer) {
            primer.innerHTML = this.primerHtml(side);
        }
    }

    /**
     * What just happened in a town, full-screen, until it is dismissed.
     *
     * A resolution is the only thing in the game that settles a location for
     * good, it happens at most twice a round, and before this the only sign of
     * one was a line in the log and a tint on a distant box. It is built like
     * the start card — a plain fixed backdrop, not a BGA popin, because it has
     * to close on the first click or keypress *anywhere*.
     *
     * The board's own mark for the town stays afterwards, drawn by
     * `BoardView.resultHtml`: this says it once, loudly, and the box keeps the
     * record for whoever arrives later.
     */
    showResolution(result: {
        townId: string;
        winner: Side;
        cardPresence: number;
        troopPresence: number;
        points: number;
    }, onDismiss: () => void): void {
        document.getElementById('iaw-resolution')?.remove();

        const rebels = result.winner === 'insurgency';
        const label = this.scenario.towns[result.townId]?.label ?? result.townId;
        const won = rebels
            ? _('The Rebels won ${town}').replace('${town}', label)
            : _('The Empire won ${town}').replace('${town}', label);
        const scored = this.withIcons(result.points > 0
            ? _('and scored ${points} ${presence} presence').replace('${points}', String(result.points))
            : _('and scored nothing: there is no prize for a ${town} location nobody contested'));

        const overlay = document.createElement('div');
        overlay.id = 'iaw-resolution';
        overlay.innerHTML = `
            <div id="iaw-resolution-card" class="${rebels ? 'insurgency' : 'empire'}">
                <div class="iaw-resolution-town">${label}</div>
                <div class="iaw-resolution-score">
                    <span class="iaw-resolution-side">
                        <span class="iaw-resolution-who">${_('Rebels')}</span>
                        ${presenceHtml(result.cardPresence, 'large')}
                    </span>
                    <span class="iaw-resolution-dash">&ndash;</span>
                    <span class="iaw-resolution-side">
                        <span class="iaw-resolution-who">${_('Empire')}</span>
                        ${presenceHtml(result.troopPresence, 'large')}
                    </span>
                </div>
                <div class="iaw-resolution-verdict">${won}</div>
                <div class="iaw-resolution-points">${scored}</div>
                <div id="iaw-start-hint">${_('Click anywhere, or press any key, to continue')}</div>
            </div>
        `;
        document.body.appendChild(overlay);

        const dismiss = () => {
            overlay.remove();
            document.removeEventListener('keydown', dismiss);
            onDismiss();
        };
        overlay.addEventListener('click', dismiss);
        document.addEventListener('keydown', dismiss);
    }

    // -- the cheat sheet ----------------------------------------------------

    /**
     * The quick reference: what every mark on the board means, and a way to the
     * manual for everything else.
     *
     * It used to open with four paragraphs of rules as well. Those said the same
     * things `rules.html` says at more length, so there were two places to keep
     * current and one of them was always going to lose. What is left is the one
     * thing this does *better* than the manual: the legend draws the **real**
     * components — the silhouettes fetched from `img/`, the same pip and stack
     * markup the board itself uses — so it cannot drift from what is on screen,
     * where the manual's picture is hand-built and can.
     */
    private sheetHtml(): string {
        return `
            <div class="iaw-help">
                <p class="iaw-help-more">
                    <a href="${rulesUrl()}" target="_blank" rel="noopener">
                        ${_('Read the full rules — how to play, with a picture of every symbol')}</a>
                </p>
                <div class="iaw-help-heading">${_('What you are looking at')}</div>
                ${this.legendHtml()}
            </div>
        `;
    }

    private legendHtml(): string {
        const art = (svg: string) => `<span class="iaw-legend-art">${svg}</span>`;
        const stack = (kind: string, count: number, sum?: number | string) =>
            `<span class="iaw-stack ${kind}"><span class="iaw-stack-count">${count}</span>${
                sum === undefined ? '' : presenceHtml(sum)}</span>`;

        const rows: [string, string][] = [
            // First, because it is the one quantity in the game and every row
            // under it is either presence or a count of something else.
            [presenceHtml(2),
             _('${presence} Presence, wherever it is shown. ${agent} Cards carry it and ${troop} troops carry it; a ${town} town goes to whoever has more of it. A plain number — the height of a stack, the size of a ${troop} garrison — is a count of pieces, not ${presence} presence.')],
            [art(this.board.townSvg()),
             _('A ${town} town. Adds its supply to whatever Empire network holds it.')],
            [art(this.board.citySvg()) + ' <span class="iaw-produce">&#128296;</span>',
             _('A ${city} city, marked with a hammer. Also builds a ${troop} troop a turn for whoever holds it.')],
            [`<span class="iaw-troops">${this.board.pawnSvg()
                ? `<span class="iaw-pawn">${this.board.pawnSvg()}</span>` : ''
             }${this.scenario.unit.presence === 1
                ? presenceHtml(3)
                : `<span class="iaw-troop-count">3</span>${
                    presenceHtml(3 * this.scenario.unit.presence)}`}</span>`,
             _('Empire ${troop} troops standing here. Each is worth ${n} ${presence} presence at a resolution.')
                .replace('${n}', String(this.scenario.unit.presence))],
            [stack('face-down', 4, '?'),
             _('Face-down ${agent} agents: how many, and what they total. The Rebels see their own total; the Empire sees a question mark. Click either stack to see the pile in order.')],
            [stack('face-up', 2, 3),
             _('Face-up ${agent} agents and their ${presence} presence. A ${troop} troop that does not march turns one ${agent} card over each turn.')],
            [`<span class="iaw-supply">2/4</span>`,
             _('${troop} Troops standing in this network, and the most it can supply.')],
            [`<span class="iaw-contribution">(2)</span>`,
             _('What this ${town} location adds to that. A ${town} location the Rebels have won adds nothing, for ever.')],
            [`<span class="iaw-troops-doomed">&minus;1</span>`,
             _('Starving ${troop} troops. Lost at the end of the Empire\'s next turn unless the supply line is repaired first.')],
            [`<span class="iaw-troop-delta">+1</span>
              <span class="iaw-card-delta">+2 ${_('cards')}</span>`,
             _('What you are staging this turn, shown beside what is already there.')],
            [presenceHtml('+2', 'iaw-chip') + `<span class="iaw-chip face-down"></span>`,
             _('${agent} Agents above a ${town} town: face up while you are placing them, and greyed afterwards to show what your opponent placed on their last turn.')],
        ];

        return `<table class="iaw-legend">${rows.map(([icon, text]) =>
            `<tr><td class="iaw-legend-icon">${icon}</td><td>${this.withIcons(text)}</td></tr>`).join('')}</table>`;
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
        // Which half of the round is yours, so the other side's steps can be
        // drawn quieter without being hidden: what the opponent will do next is
        // the reason this list shows both.
        const mine = (index: number) => side === null
            || (side === 'insurgency' ? index < 3 : index >= 3);

        return `
            <ol class="iaw-phases">${this.steps().map((step, index) => {
                const classes = [
                    index === current ? 'current' : '',
                    mine(index) ? 'mine' : 'theirs',
                ].filter(Boolean).join(' ');
                return `<li class="${classes}">${this.withIcons(step)}</li>`;
            }).join('')}</ol>
        `;
    }

    /**
     * The whole round, both sides, in the order it actually happens.
     *
     * It used to list only the viewer's own three or five steps, which answered
     * "what do I do now" and never "what happens after I do it" — and the thing
     * players most need to see is that **resolution comes first on both sides**,
     * so anything you commit is exposed to your opponent's reply before it pays.
     * Two logged games were lost to not having that in front of somebody.
     *
     * The indices are absolute and the state classes pass them directly, so a
     * step belonging to the side that is *not* you can be lit as well.
     */
    private steps(): string[] {
        return [
            _('Rebels: resolve a ${town} location they have an ${agent} agent in'),
            _('Rebels: place their entire hand of ${agent} agents'),
            _('Rebels: draw back up to ${hand}').replace('${hand}', String(this.scenario.handSize)),
            _('Empire: resolve a ${town} location it has ${troop} troops in'),
            _('Empire: build ${troop} troops in ${city} cities it holds'),
            _('Empire: march ${troop} troops along roads'),
            _('Empire: ${troop} troops that stayed put each read an ${agent} agent'),
            _('Empire: ${troop} troops over supply starve'),
        ];
    }

    /**
     * A permanent few lines saying what your side does, and a way into the manual.
     *
     * "How to play" is a *link to `rules.html`*, not a button that opens the
     * cheat sheet: the `?` in the title bar already opens that, and two
     * differently-named controls doing one thing left the document that
     * actually teaches the game as a text link at the bottom of a dialog. Being
     * an anchor rather than a button is also what makes it work inside the
     * full-screen start card, which renders this same markup and never had a
     * click handler attached to its copy — see `showStartOverlay`.
     *
     * Spectators get the Empire's, arbitrarily: something is more use than an
     * empty box. No turn order here any more — that is `phaseListHtml`, which
     * lives with the rest of the game state.
     *
     * Its title names the side rather than the frame. Every other frame in the
     * column is titled for what it is, and this one is the exception on
     * purpose: the asymmetry is the thing worth never losing track of, and this
     * is the only box on screen that is about *you*. It reads the same blown up
     * full-screen at the start of the game, which is the other place it is
     * drawn and the one place a player is certain to look.
     */
    primerHtml(side: Side | null): string {
        const rebel = side === 'insurgency';

        // A spectator is playing no side, so the frame goes back to being
        // named for what it is.
        const title = side === null
            ? _('Rules Summary')
            : (rebel ? _('You Are Playing the Rebels') : _('You Are Playing the Empire'));

        // Written as Markdown in `src/text/`, compiled into
        // `primers.generated.ts` by `tools/build-text.mjs`. Prose belongs in a
        // prose file; `*.md` is excluded from the deploy, so it is compiled in
        // rather than fetched, and the generated form keeps the literal inside
        // `_()` so BGA can still translate it.
        const summary = this.withIcons(rebel
            ? primerRebels().replace('${hand}', String(this.scenario.handSize))
            : primerEmpire());

        return `
            <div class="iaw-primer ${rebel ? 'insurgency' : 'empire'}">
                <div class="iaw-frame-title">${title}</div>
                ${summary}
                <a class="iaw-primer-more" href="${rulesUrl()}"
                   target="_blank" rel="noopener">${_('How to play')}</a>
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
                    >${presenceHtml(known ? `+${value}` : '?', 'large')}</div>
                <div class="iaw-detail-name">${known
                    ? (type?.label ?? `${_('Agent')} +${value}`)
                    : _('A face-down agent')}</div>
                <div class="iaw-detail-text">${known
                    ? (value > 0
                        ? this.withIcons(_('Adds ${n} ${presence} presence to the Rebels in the ${town} location it is placed in.')
                            .replace('${n}', String(value)))
                        : this.withIcons(_('Adds no ${presence} presence. Use it as a decoy: face down it is indistinguishable from any other ${agent} agent, and it makes a pile look dangerous.')))
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
                <div class="iaw-detail-name">${unit.label} ${presenceHtml(unit.presence)}</div>
                <div class="iaw-detail-text">${
                    this.withIcons(_('${presence} Presence +${n}. Moves ${movement} ${town} town per turn. Costs ${supply} supply to keep standing, and reads ${peek} ${agent} card per turn when it holds still.')
                        .replace('${n}', String(unit.presence))
                        .replace('${movement}', String(unit.movement))
                        .replace('${supply}', String(this.scenario.supplyPerTroop))
                        .replace('${peek}', String(unit.peek)))}</div>
            </div>
        `;
    }
}
