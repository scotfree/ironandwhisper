/**
 * The board: towns laid out from the map's coordinates, edges drawn between
 * them, and each town showing what the viewing player is entitled to see.
 *
 * This class only draws. It never decides what is legal and never talks to the
 * server — the state classes do that and tell it what to highlight.
 */
const CELL = 150;
const PADDING = 70;
class BoardView {
    constructor(container, scenario, towns, viewerSide) {
        this.container = container;
        this.scenario = scenario;
        this.viewerSide = viewerSide;
        this.clickHandler = () => { };
        /** Extra text shown on a town while a turn is being staged. */
        this.pending = {};
        this.towns = towns;
    }
    // -- building -----------------------------------------------------------
    render() {
        const definitions = Object.values(this.scenario.towns);
        const width = Math.max(...definitions.map(t => t.x)) * CELL + PADDING * 2;
        const height = Math.max(...definitions.map(t => t.y)) * CELL + PADDING * 2;
        this.container.innerHTML = `
            <div id="iaw-board" style="width:${width}px;height:${height}px">
                ${this.edgesSvg(width, height)}
                ${definitions.map(town => this.townHtml(town)).join('')}
            </div>
        `;
        definitions.forEach(town => {
            const element = document.getElementById(this.townElementId(town.id));
            element?.addEventListener('click', () => this.clickHandler(town.id));
        });
        this.updateAll();
    }
    edgesSvg(width, height) {
        const lines = this.scenario.edges.map(([a, b]) => {
            const from = this.scenario.towns[a];
            const to = this.scenario.towns[b];
            return `<line x1="${this.px(from.x)}" y1="${this.px(from.y)}"
                          x2="${this.px(to.x)}" y2="${this.px(to.y)}" />`;
        }).join('');
        return `<svg id="iaw-roads" width="${width}" height="${height}">${lines}</svg>`;
    }
    townHtml(town) {
        return `
            <div id="${this.townElementId(town.id)}" class="iaw-town"
                 style="left:${this.px(town.x)}px;top:${this.px(town.y)}px">
                <div class="iaw-town-name">${town.label}</div>
                <div class="iaw-town-troops"></div>
                <div class="iaw-town-pile"></div>
                <div class="iaw-town-result"></div>
                <div class="iaw-town-pending"></div>
            </div>
        `;
    }
    px(coordinate) {
        return PADDING + coordinate * CELL;
    }
    townElementId(townId) {
        return `iaw-town-${townId}`;
    }
    // -- updating -----------------------------------------------------------
    setTowns(towns) {
        this.towns = towns;
        this.updateAll();
    }
    getTown(townId) {
        return this.towns[townId];
    }
    allTowns() {
        return this.towns;
    }
    updateAll() {
        Object.keys(this.towns).forEach(townId => this.updateTown(townId));
    }
    updateTown(townId) {
        const town = this.towns[townId];
        const element = document.getElementById(this.townElementId(townId));
        if (!town || !element) {
            return;
        }
        element.classList.toggle('resolved', town.resolved);
        element.classList.toggle('empire-held', town.resolved && town.winner === 'empire');
        element.classList.toggle('insurgency-held', town.resolved && town.winner === 'insurgency');
        const troops = element.querySelector('.iaw-town-troops');
        troops.innerHTML = town.troops > 0
            ? `<span class="iaw-troops">${town.troops}</span>`
            : '';
        const pile = element.querySelector('.iaw-town-pile');
        pile.innerHTML = town.pile.map(card => this.cardHtml(card)).join('');
        const result = element.querySelector('.iaw-town-result');
        result.textContent = town.resolved
            ? `${town.resolvedInfluence} : ${town.resolvedStrength}`
            : '';
        const pending = element.querySelector('.iaw-town-pending');
        pending.textContent = this.pending[townId] ?? '';
        element.classList.toggle('pending', Boolean(this.pending[townId]));
    }
    /**
     * A face-down card is drawn as a blank: the Empire knows a card is there and
     * where it sits in the pile, which it could work out anyway from the pile
     * heights it watches change.
     */
    cardHtml(card) {
        if (card.type === null) {
            return '<span class="iaw-card face-down"></span>';
        }
        const label = card.influence && card.influence > 0 ? String(card.influence) : '·';
        return `<span class="iaw-card ${card.type}">${label}</span>`;
    }
    // -- interaction --------------------------------------------------------
    onTownClick(handler) {
        this.clickHandler = handler;
    }
    /** Highlight the towns a player may click right now. */
    setSelectable(townIds) {
        Object.keys(this.scenario.towns).forEach(townId => {
            const element = document.getElementById(this.townElementId(townId));
            element?.classList.toggle('selectable', townIds.includes(townId));
        });
    }
    setSelected(townIds) {
        Object.keys(this.scenario.towns).forEach(townId => {
            const element = document.getElementById(this.townElementId(townId));
            element?.classList.toggle('selected', townIds.includes(townId));
        });
    }
    setPending(pending) {
        this.pending = pending;
        this.updateAll();
    }
    clearInteraction() {
        this.pending = {};
        this.setSelectable([]);
        this.setSelected([]);
        this.updateAll();
    }
    neighborsOf(townId) {
        return this.scenario.towns[townId].neighbors;
    }
}

/**
 * The Empire stages a raise and any number of marches, then commits.
 *
 * Looking is not staged and never appears here: every troop that did not move
 * peeks, there is no decision in it, and the server does it at the end of the
 * move step.
 */
class EmpireTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        this.generateAt = null;
        this.moves = [];
        this.source = null;
        this.resolveTarget = null;
        this.mode = 'move';
    }
    onEnteringState(args, isCurrentPlayerActive) {
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
    reset() {
        this.generateAt = null;
        this.moves = [];
        this.source = null;
        this.resolveTarget = null;
        this.mode = 'move';
    }
    // -- staging ------------------------------------------------------------
    onTownClick(townId) {
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
    addMove(from, to) {
        const existing = this.moves.find(move => move.from === from && move.to === to);
        if (existing) {
            existing.count += 1;
        }
        else {
            this.moves.push({ from, to, count: 1 });
        }
    }
    /**
     * Troops as they will stand after this turn's raise and marches. Every
     * decision — what may move, what may resolve — is judged against this rather
     * than against the board as it looks now (Decision 4).
     */
    projected(townId) {
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
    refresh() {
        const pending = {};
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
        this.game.board.setSelected(this.mode === 'resolve' && this.resolveTarget ? [this.resolveTarget]
            : this.source ? [this.source] : []);
        this.game.setStagingText(this.stagingHtml());
        this.buttons();
    }
    selectableTowns() {
        const all = Object.keys(this.game.board.allTowns());
        if (this.mode === 'generate') {
            return this.args.generationTowns;
        }
        if (this.mode === 'resolve') {
            return all.filter(townId => this.projected(townId) > 0 && !this.game.board.getTown(townId).resolved);
        }
        if (this.source !== null) {
            return this.game.board.neighborsOf(this.source);
        }
        return all.filter(townId => this.projected(townId) > 0);
    }
    stagingHtml() {
        const lines = [];
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
    buttons() {
        this.bga.statusBar.removeActionButtons();
        if (this.mode === 'resolve') {
            this.bga.statusBar.addActionButton(this.resolveTarget
                ? _('Confirm and resolve') + ' ' + this.townLabel(this.resolveTarget)
                : _('Pick a town to resolve'), () => this.commit(), { disabled: this.resolveTarget === null });
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
        }
        else {
            this.bga.statusBar.addActionButton(this.generateAt ? _('Raise somewhere else…') : _('Raise a troop…'), () => {
                this.mode = 'generate';
                this.source = null;
                this.refresh();
            }, { color: 'secondary' });
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
    townLabel(townId) {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }
    // -- sending ------------------------------------------------------------
    commit() {
        this.bga.actions.performAction('actCommitTurn', {
            generateAt: this.generateAt ?? '',
            moves: JSON.stringify(this.moves),
            resolve: this.resolveTarget ?? '',
        });
    }
}

/**
 * The Insurgency stages its whole hand, then commits.
 *
 * Placing is one simultaneous decision — every card goes out every turn — so
 * the client stages the assignment locally and sends it in a single action,
 * which is also what makes the PHP a direct port of the simulator's
 * InsurgencyTurn.
 */
class InsurgencyTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        /** card id => town it is staged for. */
        this.assigned = {};
        this.order = [];
        this.selectedCard = null;
        this.resolveTarget = null;
        this.choosingResolution = false;
    }
    onEnteringState(args, isCurrentPlayerActive) {
        this.args = args;
        this.reset();
        this.bga.statusBar.setTitle(isCurrentPlayerActive
            ? _('${you} must place your entire hand, and may then resolve one town')
            : _('${actplayer} must place the whole hand'));
        if (!isCurrentPlayerActive) {
            return;
        }
        this.game.onHandClick(cardId => this.onCardClick(cardId));
        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.refresh();
    }
    onLeavingState() {
        this.reset();
        this.game.board.clearInteraction();
        this.game.setStagingText('');
        this.game.renderHand();
    }
    reset() {
        this.assigned = {};
        this.order = [];
        this.selectedCard = null;
        this.resolveTarget = null;
        this.choosingResolution = false;
    }
    // -- staging ------------------------------------------------------------
    onCardClick(cardId) {
        if (this.choosingResolution) {
            return;
        }
        this.selectedCard = this.selectedCard === cardId ? null : cardId;
        this.refresh();
    }
    onTownClick(townId) {
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
        this.assigned[cardId] = townId;
        this.order = this.order.filter(id => id !== cardId).concat(cardId);
        this.selectedCard = null;
        this.refresh();
    }
    unassigned() {
        return this.game.hand.filter(card => this.assigned[card.id] === undefined);
    }
    /** How many cards a town's pile will hold once this turn is committed. */
    pileAfterStaging(townId) {
        const staged = Object.values(this.assigned).filter(target => target === townId).length;
        return this.game.board.getTown(townId).pileSize + staged;
    }
    // -- display ------------------------------------------------------------
    refresh() {
        const pending = {};
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
        }
        else {
            this.game.board.setSelectable(this.args.openTowns);
            this.game.board.setSelected([]);
        }
        const remaining = this.unassigned().length;
        this.game.setStagingText(remaining > 0
            ? `<div>${_('Cards still to place')}: ${remaining}</div>`
            : `<div>${_('The whole hand is placed.')}</div>`);
        this.buttons(remaining);
    }
    buttons(remaining) {
        this.bga.statusBar.removeActionButtons();
        if (this.choosingResolution) {
            this.bga.statusBar.addActionButton(this.resolveTarget
                ? _('Confirm and resolve') + ' ' + this.townLabel(this.resolveTarget)
                : _('Pick a town to resolve'), () => this.commit(), { disabled: this.resolveTarget === null });
            this.bga.statusBar.addActionButton(_('Back'), () => {
                this.choosingResolution = false;
                this.resolveTarget = null;
                this.refresh();
            }, { color: 'secondary' });
            return;
        }
        this.bga.statusBar.addActionButton(_('Confirm placement'), () => this.commit(), { disabled: remaining > 0 });
        this.bga.statusBar.addActionButton(_('Resolve a town…'), () => {
            this.choosingResolution = true;
            this.refresh();
        }, { color: 'secondary', disabled: remaining > 0 });
        this.bga.statusBar.addActionButton(_('Reset'), () => {
            this.reset();
            this.refresh();
        }, { color: 'secondary' });
    }
    townLabel(townId) {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }
    // -- sending ------------------------------------------------------------
    commit() {
        const placements = {};
        // Order matters: cards go on one at a time, so the last one listed for a
        // town ends up on top of its pile.
        this.order.forEach(cardId => {
            const townId = this.assigned[cardId];
            (placements[townId] ?? (placements[townId] = [])).push(cardId);
        });
        this.bga.actions.performAction('actCommitTurn', {
            placements: JSON.stringify(placements),
            resolve: this.resolveTarget ?? '',
        });
    }
}

/**
 * Iron and Whisper — client entry point.
 *
 * The two sides see different games, so almost everything here branches on
 * `side`. Nothing in this file may show a player something the server did not
 * send them: the filtering is done in View.php, and the client simply draws
 * what it was given.
 */
class Game {
    constructor(bga) {
        /** The side the person looking at the screen is playing. Null for spectators. */
        this.side = null;
        /** The Insurgency's hand. Empty for anyone else — they are never sent it. */
        this.hand = [];
        this.handClickHandler = () => { };
        this.bga = bga;
        this.bga.states.register('InsurgencyTurn', new InsurgencyTurn(this, bga));
        this.bga.states.register('EmpireTurn', new EmpireTurn(this, bga));
    }
    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.side = gamedatas.sides[String(this.bga.gameui.player_id)] ?? null;
        this.hand = gamedatas.hand ?? [];
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="iaw-table">
                <div id="iaw-board-area"></div>
                <div id="iaw-side-area">
                    <div id="iaw-clock"></div>
                    <div id="iaw-hand-area">
                        <div class="iaw-heading">${_('Hand')}</div>
                        <div id="iaw-hand"></div>
                    </div>
                    <div id="iaw-staging"></div>
                </div>
            </div>
        `);
        this.board = new BoardView(document.getElementById('iaw-board-area'), gamedatas.scenario, gamedatas.towns, this.side);
        this.board.render();
        Object.entries(gamedatas.players).forEach(([playerId, player]) => {
            const label = player.side === 'empire' ? _('Empire') : _('Insurgency');
            this.bga.playerPanels.getElement(Number(playerId)).insertAdjacentHTML('beforeend', `
                <div class="iaw-player-side">${label}</div>
            `);
        });
        this.renderHand();
        this.updateClock(gamedatas.deckCount, gamedatas.handCount, gamedatas.round);
        this.setupNotifications();
    }
    // -- shared UI ----------------------------------------------------------
    /**
     * The clock is public information and worth showing plainly: the deck size
     * divided by the hand size is exactly how many turns are left.
     */
    updateClock(deckCount, handCount, round) {
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
    onHandClick(handler) {
        this.handClickHandler = handler;
    }
    /**
     * @param assigned card id => town it is staged for, drawn as already dealt with
     */
    renderHand(assigned = {}) {
        const element = document.getElementById('iaw-hand');
        if (!element) {
            return;
        }
        if (this.side !== 'insurgency') {
            element.innerHTML = `<div class="iaw-hidden-hand">${this.gamedatas.handCount} ${_('cards')}</div>`;
            return;
        }
        element.innerHTML = this.hand.map(card => {
            const label = card.influence && card.influence > 0 ? String(card.influence) : '·';
            const staged = assigned[card.id] ? ' staged' : '';
            return `<span class="iaw-card hand ${card.type}${staged}" data-card-id="${card.id}">${label}</span>`;
        }).join('');
        element.querySelectorAll('.iaw-card').forEach(node => {
            node.addEventListener('click', () => {
                this.handClickHandler(Number(node.dataset.cardId));
            });
        });
    }
    setStagingText(html) {
        const element = document.getElementById('iaw-staging');
        if (element) {
            element.innerHTML = html;
        }
    }
    cardById(cardId) {
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
    async notif_cardsPlaced(args) {
        Object.entries(args.cards).forEach(([townId, cardIds]) => {
            const town = this.board.getTown(townId);
            // Placed one at a time, so the last one given ends up on top.
            [...cardIds].reverse().forEach(cardId => {
                const known = this.cardById(cardId);
                town.pile.unshift(known ?? { id: cardId, type: null, influence: null });
            });
            town.pileSize += cardIds.length;
            this.board.updateTown(townId);
        });
        this.hand = [];
        this.renderHand();
    }
    async notif_empireMoved(args) {
        Object.entries(args.troops).forEach(([townId, troops]) => {
            this.board.getTown(townId).troops = troops;
            this.board.updateTown(townId);
        });
    }
    /**
     * A look takes cards off the top and returns them to the bottom. Everyone
     * is told how many moved, because troop positions make it derivable; only
     * the Empire is told what they were.
     */
    async notif_pilesRotated(args) {
        Object.entries(args.counts).forEach(([townId, count]) => {
            const pile = this.board.getTown(townId).pile;
            pile.push(...pile.splice(0, count));
            this.board.updateTown(townId);
        });
    }
    async notif_peekResult(args) {
        Object.entries(args.seen).forEach(([townId, cards]) => {
            const pile = this.board.getTown(townId).pile;
            cards.forEach(seen => {
                const card = pile.find(entry => entry.id === seen.id);
                if (card) {
                    card.type = seen.type;
                    card.influence = seen.influence;
                }
            });
            this.board.updateTown(townId);
        });
    }
    async notif_townResolved(args) {
        const town = this.board.getTown(args.town_id);
        town.resolved = true;
        town.winner = args.winner;
        town.resolvedInfluence = args.influence;
        town.resolvedStrength = args.strength;
        // Face up from here on, to both players.
        town.pile = args.pile;
        town.troops = 0;
        this.board.updateTown(args.town_id);
    }
    async notif_deckCount(args) {
        this.gamedatas.deckCount = args.deckCount;
        this.gamedatas.handCount = args.handCount;
        this.updateClock(args.deckCount, args.handCount, this.gamedatas.round);
    }
    async notif_handDrawn(args) {
        this.hand = args.hand;
        this.renderHand();
    }
    async notif_gameEnding(args) {
        this.board.clearInteraction();
    }
}

export { Game };
