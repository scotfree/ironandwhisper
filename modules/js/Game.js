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
        this.dropHandler = null;
        /** Extra text shown on a town while a turn is being staged. */
        this.pending = {};
        /** Signed troop changes being staged, shown on the troop badge as 2+1. */
        this.troopDelta = {};
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
            if (!element) {
                return;
            }
            element.addEventListener('click', () => this.clickHandler(town.id));
            // Cards can be dragged onto a town as well as clicked into one.
            // Dragging is what people expect of a hand; clicking is what works
            // on a touchscreen, so both are supported.
            element.addEventListener('dragover', event => {
                if (this.dropHandler && element.classList.contains('selectable')) {
                    event.preventDefault();
                    element.classList.add('drag-over');
                }
            });
            element.addEventListener('dragleave', () => element.classList.remove('drag-over'));
            element.addEventListener('drop', event => {
                element.classList.remove('drag-over');
                if (!this.dropHandler || !element.classList.contains('selectable')) {
                    return;
                }
                event.preventDefault();
                const cardId = Number(event.dataTransfer?.getData('text/plain'));
                if (!Number.isNaN(cardId)) {
                    this.dropHandler(town.id, cardId);
                }
            });
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
        return `<svg id="iaw-roads" width="${width}" height="${height}">
            <defs>
                <marker id="iaw-arrowhead" viewBox="0 0 10 10" refX="9" refY="5"
                        markerWidth="4" markerHeight="4" orient="auto-start-reverse">
                    <path d="M 0 0 L 10 5 L 0 10 z" />
                </marker>
            </defs>
            <g id="iaw-roads-edges">${lines}</g>
            <g id="iaw-move-arrows"></g>
        </svg>`;
    }
    /**
     * Where a line from one town to another should start, so it emerges from
     * the edge of the town's box rather than from under it.
     */
    boxExit(from, to) {
        const x = this.px(from.x);
        const y = this.px(from.y);
        const dx = this.px(to.x) - x;
        const dy = this.px(to.y) - y;
        // Half the town box, plus a little air.
        const scale = Math.min(dx === 0 ? Infinity : 60 / Math.abs(dx), dy === 0 ? Infinity : 44 / Math.abs(dy));
        return { x: x + dx * scale, y: y + dy * scale };
    }
    townHtml(town) {
        return `
            <div id="${this.townElementId(town.id)}" class="iaw-town"
                 style="left:${this.px(town.x)}px;top:${this.px(town.y)}px">
                <div class="iaw-town-name">${town.label}</div>
                <div class="iaw-town-troops"></div>
                <div class="iaw-town-pile"></div>
                <div class="iaw-town-revealed"></div>
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
        // The badge shows what is there and what this turn would add or take
        // away, as "2+1", rather than quietly showing the result.
        const delta = this.troopDelta[townId] ?? 0;
        const troops = element.querySelector('.iaw-town-troops');
        troops.innerHTML = (town.troops > 0 || delta !== 0)
            ? `<span class="iaw-troops">${town.troops}${delta === 0 ? ''
                : `<span class="iaw-troop-delta">${delta > 0 ? '+' : '-'}${Math.abs(delta)}</span>`}</span>`
            : '';
        // Two areas, as on a table: the face-down stack, and the cards a
        // garrison has turned over lying face up beside it.
        const pile = element.querySelector('.iaw-town-pile');
        pile.innerHTML = town.pile.map(card => this.cardHtml(card)).join('');
        const revealed = element.querySelector('.iaw-town-revealed');
        revealed.innerHTML = town.revealed.map(card => this.cardHtml(card)).join('');
        const result = element.querySelector('.iaw-town-result');
        result.textContent = town.resolved
            ? `${town.resolvedInfluence} : ${town.resolvedStrength}`
            : '';
        const pending = element.querySelector('.iaw-town-pending');
        pending.textContent = this.pending[townId] ?? '';
        element.classList.toggle('pending', Boolean(this.pending[townId]));
    }
    /**
     * A face-down card is drawn as a blank. Everything in the revealed row is
     * face up by definition, so it always arrives with a face.
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
    /** Accept cards dragged from the hand. Pass null to stop accepting them. */
    onTownDrop(handler) {
        this.dropHandler = handler;
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
    /** @param delta town id => signed troop change being staged this turn */
    setTroopDelta(delta) {
        this.troopDelta = delta;
        this.updateAll();
    }
    /**
     * Draw the marches being staged as arrows along the roads they follow, so
     * the plan is visible on the map rather than only in a list.
     */
    setMoveArrows(moves) {
        const layer = document.getElementById('iaw-move-arrows');
        if (!layer) {
            return;
        }
        layer.innerHTML = moves.map(move => {
            const from = this.scenario.towns[move.from];
            const to = this.scenario.towns[move.to];
            const start = this.boxExit(from, to);
            const end = this.boxExit(to, from);
            const label = move.count > 1
                ? `<text class="iaw-move-count" x="${(start.x + end.x) / 2}"
                         y="${(start.y + end.y) / 2 - 4}">${move.count}</text>`
                : '';
            return `<line class="iaw-move-arrow" x1="${start.x}" y1="${start.y}"
                          x2="${end.x}" y2="${end.y}"
                          marker-end="url(#iaw-arrowhead)" />${label}`;
        }).join('');
    }
    clearInteraction() {
        this.dropHandler = null;
        this.pending = {};
        this.troopDelta = {};
        this.setMoveArrows([]);
        this.setSelectable([]);
        this.setSelected([]);
        this.updateAll();
    }
    neighborsOf(townId) {
        return this.scenario.towns[townId].neighbors;
    }
}

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
class EmpireTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        this.generateAt = null;
        this.moves = [];
        this.source = null;
        this.resolveTarget = null;
        this.step = 'raise';
    }
    onEnteringState(args, isCurrentPlayerActive) {
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
    watchingHtml() {
        return `<div class="iaw-hint">${_('The Empire is moving. You are the Insurgency, so there is nothing to do until it is your turn.')}</div>`;
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
        // Skip straight to marching if there is nowhere legal to raise.
        this.step = this.args.generationTowns.length > 0 ? 'raise' : 'move';
    }
    // -- staging ------------------------------------------------------------
    onTownClick(townId) {
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
    addMove(from, to) {
        const existing = this.moves.find(move => move.from === from && move.to === to);
        if (existing) {
            existing.count += 1;
        }
        else {
            this.moves.push({ from, to, count: 1 });
        }
    }
    canResolve(townId) {
        return this.projected(townId) > 0 && !this.game.board.getTown(townId).resolved;
    }
    /**
     * Troops as they will stand once this turn is committed.
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
    /** Towns that will hold a stationary troop, and so will read a card. */
    willLook() {
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
    refresh() {
        this.bga.statusBar.setTitle(this.title());
        // Show the change, not the result: a town with two troops that is
        // raising reads "2+1", and the marches are drawn on the roads.
        const delta = {};
        Object.keys(this.game.board.allTowns()).forEach(townId => {
            const change = this.projected(townId) - this.game.board.getTown(townId).troops;
            if (change !== 0) {
                delta[townId] = change;
            }
        });
        this.game.board.setTroopDelta(delta);
        this.game.board.setMoveArrows(this.moves);
        this.game.board.setSelectable(this.selectableTowns());
        this.game.board.setSelected(this.step === 'resolve' && this.resolveTarget ? [this.resolveTarget]
            : this.source ? [this.source] : []);
        this.game.setStagingText(this.stagingHtml());
        this.buttons();
    }
    title() {
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
    selectableTowns() {
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
    stagingHtml() {
        const lines = [];
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
    buttons() {
        this.bga.statusBar.removeActionButtons();
        if (this.step === 'raise') {
            this.bga.statusBar.addActionButton(_('Raise nothing this turn'), () => {
                this.step = 'move';
                this.refresh();
            }, { color: 'secondary' });
            return;
        }
        if (this.step === 'resolve') {
            this.bga.statusBar.addActionButton(this.resolveTarget
                ? _('Confirm and resolve') + ' ' + this.townLabel(this.resolveTarget)
                : _('Pick a town to resolve'), () => this.commit(), { disabled: this.resolveTarget === null });
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
            this.bga.statusBar.addActionButton(this.generateAt ? _('Raise somewhere else…') : _('Raise a troop…'), () => {
                this.step = 'raise';
                this.source = null;
                this.refresh();
            }, { color: 'secondary' });
        }
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
        this.args = {
            openTowns: args?.openTowns ?? [],
            resolvable: args?.resolvable ?? [],
        };
        this.reset();
        this.bga.statusBar.setTitle(isCurrentPlayerActive
            ? _('${you} must place your entire hand, and may then resolve one town')
            : _('${actplayer} must place the whole hand'));
        if (!isCurrentPlayerActive) {
            this.game.setStagingText(`<div class="iaw-hint">${_('The Insurgency is placing cards. You are the Empire, so there is nothing to do until it is your turn.')}</div>`);
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
        this.assign(cardId, townId);
    }
    onCardDropped(townId, cardId) {
        if (this.choosingResolution || !this.args.openTowns.includes(townId)) {
            return;
        }
        this.assign(cardId, townId);
    }
    assign(cardId, townId) {
        this.assigned[cardId] = townId;
        this.order = this.order.filter(id => id !== cardId).concat(cardId);
        this.selectedCard = null;
        this.refresh();
    }
    unassigned() {
        return this.game.hand.filter(card => this.assigned[card.id] === undefined);
    }
    /**
     * How many cards a town will hold once this turn is committed, face down
     * and face up alike — a town the Empire has read to the bottom is still a
     * town the Insurgency has presence in.
     */
    pileAfterStaging(townId) {
        const staged = Object.values(this.assigned).filter(target => target === townId).length;
        return this.game.board.getTown(townId).cardCount + staged;
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
            ? `<div><b>${_('Cards still to place')}: ${remaining}</b></div>
               <div class="iaw-hint">${_('Drag a card onto a town, or click a card then a town. Every card must go somewhere.')}</div>`
            : `<div><b>${_('The whole hand is placed.')}</b></div>
               <div class="iaw-hint">${_('Confirm, or choose a town to resolve first.')}</div>`);
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
        // The server says who you are. Working it back from a player id is how
        // the hand ended up invisible: one lookup miss and the Insurgency was
        // treated as a spectator.
        this.side = gamedatas.you;
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
        // If the server sent a hand, it is yours. Deciding that here would only
        // be a second opinion, and a second opinion can disagree.
        if (this.gamedatas.hand === null) {
            element.innerHTML = `<div class="iaw-hidden-hand">${this.gamedatas.handCount} ${_('cards')}</div>`;
            return;
        }
        element.innerHTML = this.hand.map(card => {
            const label = card.influence && card.influence > 0 ? String(card.influence) : '·';
            const staged = assigned[card.id] ? ' staged' : '';
            const where = assigned[card.id] ? ` title="${assigned[card.id]}"` : '';
            return `<span class="iaw-card hand ${card.type}${staged}" draggable="true"
                          data-card-id="${card.id}"${where}>${label}</span>`;
        }).join('');
        element.querySelectorAll('.iaw-card').forEach(node => {
            const cardId = Number(node.dataset.cardId);
            node.addEventListener('click', () => this.handClickHandler(cardId));
            node.addEventListener('dragstart', event => {
                event.dataTransfer?.setData('text/plain', String(cardId));
                node.classList.add('dragging');
            });
            node.addEventListener('dragend', () => node.classList.remove('dragging'));
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
            town.pileSize = town.pile.length;
            town.cardCount += cardIds.length;
            this.board.updateTown(townId);
        });
        this.hand = [];
        if (this.gamedatas.hand !== null) {
            this.gamedatas.hand = [];
        }
        this.renderHand();
    }
    async notif_empireMoved(args) {
        Object.entries(args.troops).forEach(([townId, troops]) => {
            this.board.getTown(townId).troops = troops;
            this.board.updateTown(townId);
        });
    }
    /**
     * A look turns the top card of a pile face up, where it stays. This is
     * public: the cards are on the table, and the Insurgency could work out
     * what the Empire had seen in any case.
     */
    async notif_cardsRevealed(args) {
        Object.entries(args.revealed).forEach(([townId, cards]) => {
            const town = this.board.getTown(townId);
            const turned = new Set(cards.map(card => card.id));
            town.pile = town.pile.filter(card => !turned.has(card.id));
            town.revealed.push(...cards);
            town.pileSize = town.pile.length;
            this.board.updateTown(townId);
        });
    }
    async notif_townResolved(args) {
        const town = this.board.getTown(args.town_id);
        town.resolved = true;
        town.winner = args.winner;
        town.resolvedInfluence = args.influence;
        town.resolvedStrength = args.strength;
        // Resolution turns the whole town face up.
        town.revealed = args.pile;
        town.pile = [];
        town.pileSize = 0;
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
        this.gamedatas.hand = args.hand;
        this.renderHand();
    }
    async notif_gameEnding(args) {
        this.board.clearInteraction();
    }
}

export { Game };
