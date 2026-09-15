/**
 * Presence, drawn.
 *
 * Presence is the one quantity in the game — both sides accumulate it in a
 * town and the higher total wins — so it is drawn the same way wherever it is
 * read: a yellow disc with the number in it, black on gold, whether the
 * presence came from cards or from troops.
 *
 * The point is the contrast with a *count*. A pile of four cards worth three
 * between them shows two numbers side by side, and before this they looked
 * alike; now only one of them is a disc. A count of troops is deliberately
 * left plain for the same reason, even though at `unit.presence` 1 it happens
 * to equal the presence those troops carry — see `BoardView.troopsHtml`.
 */
function presenceHtml(value, extraClass = '', title = '') {
    return `<span class="iaw-presence${extraClass ? ` ${extraClass}` : ''}"${title ? ` title="${title}"` : ''}>${value}</span>`;
}

/**
 * The board: towns laid out from the map's coordinates, edges drawn between
 * them, and each town showing what the viewing player is entitled to see.
 *
 * This class only draws. It never decides what is legal and never talks to the
 * server — the state classes do that and tell it what to highlight.
 */
const CELL = 150;
const PADDING = 70;
// A town box is a fixed size, whatever it is holding. It used to grow as
// troops, supply and cards arrived, so the map rearranged itself as the game
// went on and no two towns were the same shape.
const TOWN_WIDTH = 120;
const TOWN_HEIGHT = 104;
/**
 * Hide the Empire's supply arithmetic inside the town boxes.
 *
 * A build-time switch rather than a preference: it is here so the fuller
 * version can be brought back in a line, not because it is a choice anybody
 * makes often. What goes is the network badge (troops/ceiling) and the town's
 * own contribution — the two lines nobody was reading. Nothing is lost that is
 * not said elsewhere: the army list beside the board carries each network's
 * numbers and turns red over the ceiling, and troops under notice still pulse
 * with what they are about to lose.
 */
const MINIMAL_TOWNS = true;
class BoardView {
    constructor(container, scenario, towns, viewerSide) {
        this.container = container;
        this.scenario = scenario;
        this.viewerSide = viewerSide;
        /** The outlines, once img/town.svg, city.svg and pawn.svg have loaded. */
        this.frames = null;
        this.clickHandler = () => { };
        this.stackHandler = () => { };
        this.troopHandler = () => { };
        this.dropHandler = null;
        /** Called after any redraw, so the panels beside the board can follow. */
        this.changeHandler = () => { };
        /** Town id => cards the Insurgency is staging for it this turn. */
        this.cardDelta = {};
        /** Signed troop changes being staged, shown on the troop badge as 2+1. */
        this.troopDelta = {};
        /**
         * Town id => troops about to be built there.
         *
         * Kept apart from `troopDelta` because a build and a march are different
         * events that used to add up to one number: Everlan raising a troop while
         * three marched out read as "-2", and the build — the thing you had just
         * chosen — vanished into the arithmetic.
         */
        this.buildDelta = {};
        /** Cards drawn above a town: staged this turn, or placed on the last one. */
        this.overlay = {};
        this.overlayGhost = false;
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
                <div id="iaw-overlays"></div>
            </div>
        `;
        definitions.forEach(town => {
            const element = document.getElementById(this.townElementId(town.id));
            if (!element) {
                return;
            }
            element.addEventListener('click', event => {
                // A stack opens itself rather than selecting the town under it.
                const target = event.target;
                const stack = target?.closest('.iaw-stack.clickable');
                if (stack) {
                    event.stopPropagation();
                    this.stackHandler(stack.dataset.stack, stack.dataset.face === 'up');
                    return;
                }
                // Most specific target wins. Opening a card you did not want
                // costs a dismissal; taking an action you did not want can cost
                // the whole turn, so the cheap mistake is the one to prefer.
                const troops = target?.closest('.iaw-troops.clickable');
                if (troops) {
                    event.stopPropagation();
                    this.troopHandler(troops.dataset.troop);
                    return;
                }
                this.clickHandler(town.id);
            });
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
            return `<line id="${this.edgeElementId(a, b)}"
                          x1="${this.px(from.x)}" y1="${this.px(from.y)}"
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
            <g id="iaw-ghost-arrows"></g>
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
        const scale = Math.min(dx === 0 ? Infinity : (TOWN_WIDTH / 2 + 6) / Math.abs(dx), dy === 0 ? Infinity : (TOWN_HEIGHT / 2 + 6) / Math.abs(dy));
        return { x: x + dx * scale, y: y + dy * scale };
    }
    townHtml(town) {
        return `
            <div id="${this.townElementId(town.id)}" class="iaw-town"
                 style="left:${this.px(town.x)}px;top:${this.px(town.y)}px">
                <div class="iaw-town-frame">${this.frameSvg(town)}</div>
                <div class="iaw-town-name"></div>
                <div class="iaw-town-body">
                    <div class="iaw-town-rebel"></div>
                    <div class="iaw-town-empire"></div>
                </div>
            </div>
        `;
    }
    /**
     * Load the two silhouettes and redraw with them.
     *
     * They are fetched rather than built from coordinates in here so they can
     * be edited in a drawing app: img/town.svg and img/city.svg are ordinary
     * files. Until they arrive the board draws a plain box, so a failed fetch
     * costs the shape and nothing else.
     */
    async loadFrames() {
        const read = async (name) => {
            const response = await fetch(`${g_gamethemeurl}img/${name}.svg`);
            return this.frameMarkup(await response.text());
        };
        try {
            this.frames = {
                town: await read('town'),
                city: await read('city'),
                pawn: await read('pawn'),
            };
        }
        catch (error) {
            console.warn('iaw: town frames could not be loaded', error);
            return;
        }
        Object.values(this.scenario.towns).forEach(town => {
            const element = document.getElementById(this.townElementId(town.id));
            const frame = element?.querySelector('.iaw-town-frame');
            if (frame) {
                frame.innerHTML = this.frameSvg(town);
            }
        });
        // The pawns are drawn as part of the troop badge, so redraw those too.
        this.updateAll();
    }
    /**
     * The file's <svg> element, stretched to the box and wired to the town's
     * colours.
     *
     * preserveAspectRatio="none" is what lets the drawing be any size: it is
     * squashed to the town box whatever its viewBox says. The stroke would be
     * squashed with it, which is what non-scaling-stroke in the CSS prevents.
     *
     * The colours cannot be done in CSS. A drawing app writes them as inline
     * style attributes, and an inline style beats any stylesheet rule — the
     * first hand-written frames used presentation attributes, which do not, so
     * the CSS worked until a real drawing replaced them. Rewriting the markup
     * is the only thing that reaches them both.
     *
     * The convention: **white and black are the game's colours** and become the
     * fill and stroke of whoever holds the town. Anything drawn in another
     * colour — a gradient, a detail line, fill:none — is left exactly as it is.
     * So a silhouette drawn in plain black on white just works, and detail can
     * opt out by not being black or white.
     */
    frameMarkup(file) {
        const start = file.indexOf('<svg');
        const end = file.lastIndexOf('</svg>');
        if (start === -1 || end === -1) {
            return '';
        }
        const white = /(fill\s*[:=]\s*"?)(#fff(?:fff)?|white)\b/gi;
        const black = /(stroke\s*[:=]\s*"?)(#000(?:000)?|black)\b/gi;
        return file.slice(start, end + '</svg>'.length)
            .replace(/\swidth="[^"]*"/, '')
            .replace(/\sheight="[^"]*"/, '')
            .replace(white, '$1var(--art-fill)')
            .replace(black, '$1var(--art-stroke)')
            .replace('<svg', '<svg preserveAspectRatio="none"');
    }
    /** The pawn markup, or nothing if the file has not arrived yet. */
    pawnSvg() {
        return this.frames ? this.frames.pawn : '';
    }
    /**
     * The two silhouettes, for anything outside the board that needs to show
     * what a town looks like — the help legend draws the real files rather than
     * a picture of them, so it cannot drift from the board.
     */
    townSvg() {
        return this.frames ? this.frames.town : '';
    }
    citySvg() {
        return this.frames ? this.frames.city : '';
    }
    /** A production town is drawn as a skyline, everything else as a hut. */
    frameSvg(town) {
        if (!this.frames) {
            return '';
        }
        return town.production > 0 ? this.frames.city : this.frames.town;
    }
    px(coordinate) {
        return PADDING + coordinate * CELL;
    }
    townElementId(townId) {
        return `iaw-town-${townId}`;
    }
    edgeElementId(a, b) {
        return `iaw-edge-${a}-${b}`;
    }
    /**
     * The Empire's supply networks, worked out from the board rather than sent.
     * A town is in a network if the Empire stands in it; two occupied towns are
     * linked if the map links them. Computing it here keeps it correct after any
     * notification without anything having to be kept in step.
     */
    networks(troopsIn = id => this.towns[id].troops) {
        const occupied = new Set(Object.keys(this.towns).filter(id => troopsIn(id) > 0));
        const seen = new Set();
        const found = [];
        occupied.forEach(start => {
            if (seen.has(start)) {
                return;
            }
            const network = [];
            const frontier = [start];
            seen.add(start);
            while (frontier.length) {
                const current = frontier.pop();
                network.push(current);
                this.scenario.towns[current].neighbors.forEach(neighbor => {
                    if (occupied.has(neighbor) && !seen.has(neighbor)) {
                        seen.add(neighbor);
                        frontier.push(neighbor);
                    }
                });
            }
            found.push(network);
        });
        return found;
    }
    /**
     * What a town gives the Empire, which is nothing once the rebels have won
     * it. An Insurgency victory denies the ground permanently: the Empire may
     * garrison it and hold a line through it, but it will never feed one.
     */
    supplyOf(townId) {
        const town = this.towns[townId];
        if (town.resolved && town.winner === 'insurgency') {
            return 0;
        }
        return this.scenario.towns[townId].supply;
    }
    /**
     * The ceiling and load of the network a town belongs to, if any.
     *
     * `troopsIn` lets a turn being staged ask the question of the board as it
     * *will* stand rather than as it stands: massing changes which towns are
     * occupied, so it changes the networks themselves, not just their load.
     */
    networkOf(townId, troopsIn = id => this.towns[id].troops) {
        const network = this.networks(troopsIn).find(n => n.includes(townId));
        if (!network) {
            return null;
        }
        const supply = network.reduce((total, id) => total + this.supplyOf(id), 0);
        return {
            towns: network,
            ceiling: Math.floor(supply / this.scenario.supplyPerTroop),
            troops: network.reduce((total, id) => total + troopsIn(id), 0),
        };
    }
    /**
     * Every Empire network that cannot feed what is standing in it, given a
     * board that may still be being staged.
     *
     * `warned` is whether the network is already under notice: a network that
     * has only just gone short is marked at the end of this turn and starves at
     * the end of the next one, so the two say very different things to a player.
     */
    overSupplied(troopsIn = id => this.towns[id].troops) {
        return this.networks(troopsIn)
            .map(network => {
            const supply = network.reduce((total, id) => total + this.supplyOf(id), 0);
            const ceiling = Math.floor(supply / this.scenario.supplyPerTroop);
            return {
                towns: network,
                over: network.reduce((total, id) => total + troopsIn(id), 0) - ceiling,
                warned: network.some(id => this.towns[id].starving > 0),
            };
        })
            .filter(network => network.over > 0);
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
        this.updateRoads();
    }
    /**
     * Colour the roads that carry supply. An edge is live when the Empire holds
     * both ends, which is exactly when it joins two towns of one network — so
     * the red lines *are* the network, and cutting one is visible.
     */
    updateRoads() {
        this.scenario.edges.forEach(([a, b]) => {
            const line = document.getElementById(this.edgeElementId(a, b));
            const live = this.towns[a]?.troops > 0 && this.towns[b]?.troops > 0;
            line?.classList.toggle('supplied', live);
        });
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
        const definition = this.scenario.towns[townId];
        const denied = town.resolved && town.winner === 'insurgency';
        // The production mark sits beside the name rather than in the body,
        // which is now divided by side and has no room for anything neutral.
        const name = element.querySelector('.iaw-town-name');
        name.innerHTML = `${definition.production > 0 && !denied
            ? `<span class="iaw-produce" title="${_('Can build troops')}">&#128296;</span>`
            : ''}${definition.label}`;
        // Rebels down the left, Empire down the right, so which side a number
        // belongs to can be read off the board without reading the number.
        const rebel = element.querySelector('.iaw-town-rebel');
        rebel.innerHTML = this.faceDownHtml(townId, town) + this.faceUpHtml(town);
        const empire = element.querySelector('.iaw-town-empire');
        empire.innerHTML = this.troopsHtml(townId, town) + this.supplyHtml(townId, town, denied);
        this.changeHandler();
    }
    /**
     * The garrison: a pawn and a count, with what this turn would change and
     * what is about to starve.
     */
    troopsHtml(townId, town) {
        const delta = this.troopDelta[townId] ?? 0;
        const built = this.buildDelta[townId] ?? 0;
        if (town.troops === 0 && delta === 0 && built === 0) {
            return '';
        }
        const pawn = this.frames
            ? `<span class="iaw-pawn">${this.frames.pawn}</span>`
            : '';
        // The build is called out on its own, loudly: it is the one change on
        // the board the Empire *creates* rather than moves, and it is the step
        // being decided when it is shown. What is left of the delta is the
        // marching — arrivals less departures — and stays quiet.
        const raising = built === 0 ? ''
            : `<span class="iaw-build-delta" title="${_('Building here this turn')}"
                >+${built}</span>`;
        const marching = delta - built;
        const change = marching === 0 ? ''
            : `<span class="iaw-troop-delta">${marching > 0 ? '+' : '-'}${Math.abs(marching)}</span>`;
        // A garrison under notice pulses and says how many of it are going,
        // because the loss used to land between turns where nobody saw it.
        const doomed = town.starving > 0
            ? `<span class="iaw-troops-doomed" title="${_('Starving: these troops are lost at the end of the Empire\'s next turn unless the supply line is repaired')}">&minus;${town.starving}</span>`
            : '';
        return `<div class="iaw-troops clickable${town.starving > 0 ? ' starving' : ''}"
                 data-troop="${townId}"
                 >${pawn}${this.garrisonHtml(town.troops)}${change}${raising}${doomed}</div>`;
    }
    /**
     * The garrison, as the number that decides the town.
     *
     * While a troop is worth 1 presence the count *is* the presence, so it is
     * drawn as a pip and there is only one number: the pawn already says these
     * are troops. The moment a troop is worth more the two part company and
     * both are wanted — how many pieces are standing there, and what they are
     * worth — so the plain count comes back with the pip beside it.
     */
    garrisonHtml(troops) {
        const each = this.scenario.unit.presence;
        if (each === 1) {
            return presenceHtml(troops, '', _('Presence this garrison carries'));
        }
        return `<span class="iaw-troop-count">${troops}</span>${presenceHtml(troops * each, '', _('Presence this garrison carries'))}`;
    }
    /**
     * Supply, as "troops standing / what this network holds" over "what this
     * town adds". The first line is the same for every town in a network, which
     * is what makes a network visible.
     */
    supplyHtml(townId, town, denied) {
        if (MINIMAL_TOWNS) {
            return '';
        }
        const network = this.networkOf(townId);
        if (!network) {
            return '';
        }
        return `<div class="iaw-supply${network.troops > network.ceiling ? ' over' : ''}"
                     title="${_('Troops standing, and what this network supports')}"
                  >${network.troops}/${network.ceiling}</div>
                <div class="iaw-contribution${denied ? ' denied' : ''}"
                     title="${denied
            ? _('Taken by the Insurgency: supplies nothing, builds nothing')
            : _('What this town adds to the network')}"
                  >(${this.supplyOf(townId)})</div>`;
    }
    /**
     * The face-down stack: a height and nothing else.
     *
     * The Insurgency is still *sent* the faces — it placed the cards, and the
     * server has no reason to withhold them — but nobody is shown them. Once a
     * card is down it is down, for the player who put it there as much as for
     * the one who has to guess, and remembering the board is part of the game.
     */
    faceDownHtml(townId, town) {
        const delta = this.cardDelta[townId] ?? 0;
        if (town.pileSize === 0 && delta === 0) {
            return '';
        }
        // The rebels see the total of their own pile; everyone else gets a "?".
        // Showing the unknown as a symbol rather than an absence says what the
        // Empire is missing, instead of leaving a gap it has to interpret.
        const mine = this.viewerSide === 'insurgency';
        const total = mine
            ? String(town.pile.reduce((sum, card) => sum + (card.presence ?? 0), 0))
            : '?';
        // Beside the pile, not across the bottom of the box: the change reads
        // against the number it changes, exactly as the garrison's does on the
        // Empire side. A town with no pile yet still shows the marker, or the
        // first card placed anywhere would land invisibly.
        const stack = town.pileSize > 0
            ? `<span class="iaw-stack face-down clickable" data-stack="${townId}"
                     data-face="down"
                     title="${town.pileSize} ${_('face down')} — ${_('click to see the pile in order')}"
                ><span class="iaw-stack-count">${town.pileSize}</span
                >${presenceHtml(total, '', mine
                ? _('Presence in this pile')
                : _('Presence in this pile: not yours to know'))}</span>`
            : '';
        const change = delta === 0 ? ''
            : `<span class="iaw-card-delta"
                     title="${_('Agents you are placing here this turn')}">+${delta} ${delta === 1 ? _('card') : _('cards')}</span>`;
        return `<div class="iaw-pile-line">${stack}${change}</div>`;
    }
    /**
     * The face-up stack: how many, and what they add up to.
     *
     * The sum is the whole reason the Empire looks, so it is on the stack
     * rather than left to be worked out; the individual values are on the
     * tooltip for anyone who wants to check the arithmetic. Everything here is
     * public — face-up cards are on the table.
     */
    faceUpHtml(town) {
        const cards = town.revealed;
        if (cards.length === 0) {
            return '';
        }
        const values = cards.map(card => card.presence ?? 0);
        const total = values.reduce((sum, value) => sum + value, 0);
        return `<span class="iaw-stack face-up clickable" data-stack="${town.id}"
                      data-face="up"
                      title="${_('Face up')}: ${values.join(', ')} — ${_('click to see them in order')}"
                 ><span class="iaw-stack-count">${cards.length}</span
                 >${presenceHtml(total, '', _('Presence turned face up here'))}</span>`;
    }
    // -- interaction --------------------------------------------------------
    onTownClick(handler) {
        this.clickHandler = handler;
    }
    /**
     * A click on either of a town's stacks, which opens it rather than
     * selecting the town. Bound once on the board and delegated, because the
     * stacks are rewritten on every update.
     */
    onStackClick(handler) {
        this.stackHandler = handler;
    }
    /** A click on a garrison, which explains the troop rather than the town. */
    onTroopClick(handler) {
        this.troopHandler = handler;
    }
    /**
     * Anything drawn from the board but living outside it — the army list —
     * redraws through here. Called once per town update, so it runs a dozen
     * times on a full refresh; it is a couple of small innerHTML writes and
     * idempotent, which is cheaper than working out when it is really needed.
     */
    onBoardChanged(handler) {
        this.changeHandler = handler;
    }
    /**
     * The Empire's armies: one per supply network, named for the town holding
     * most of it.
     *
     * The name breaks ties by hashing the network's membership rather than by
     * picking the alphabetically first town, which would mean the same name
     * forever. Hashing keeps it stable while the army is — a name that changed
     * on every redraw would be unreadable — and shuffles it when the army does.
     */
    armies() {
        return this.networks().map(towns => {
            const most = Math.max(...towns.map(id => this.towns[id].troops));
            const tied = towns.filter(id => this.towns[id].troops === most).sort();
            const seed = [...towns].sort().join('|');
            let hash = 0;
            for (let i = 0; i < seed.length; i++) {
                hash = (hash * 31 + seed.charCodeAt(i)) | 0;
            }
            const troops = towns.reduce((total, id) => total + this.towns[id].troops, 0);
            const supply = towns.reduce((total, id) => total + this.supplyOf(id), 0);
            return {
                name: this.scenario.towns[tied[Math.abs(hash) % tied.length]].label,
                towns,
                troops,
                supplyUsed: troops * this.scenario.supplyPerTroop,
                supplyAvailable: supply,
                supplyTowns: towns.filter(id => this.supplyOf(id) > 0).length,
            };
        });
    }
    /**
     * Light up one supply network on the map: its towns in the Empire's purple
     * and the roads between them brightened.
     *
     * Hovering an entry in the army list is the only way to ask "which of the
     * twelve towns is *this* army?", and a split line is exactly when that
     * question is worth asking. Pass an empty list to clear it.
     *
     * The roads it lights are already the supplied ones — an edge is in a
     * network when the Empire holds both ends — so this brightens rather than
     * colours, and nothing else on the board is dimmed.
     */
    setArmyHighlight(townIds) {
        const inArmy = new Set(townIds);
        Object.keys(this.scenario.towns).forEach(townId => {
            document.getElementById(this.townElementId(townId))
                ?.classList.toggle('army-hover', inArmy.has(townId));
        });
        this.scenario.edges.forEach(([a, b]) => {
            document.getElementById(this.edgeElementId(a, b))
                ?.classList.toggle('army-hover', inArmy.has(a) && inArmy.has(b));
        });
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
    /** @param delta town id => cards being staged onto that town this turn */
    setCardDelta(delta) {
        this.cardDelta = delta;
        this.updateAll();
    }
    /**
     * Cards to draw above a town.
     *
     * Used twice: face up for what you are staging right now, and greyed for
     * what landed on the opponent's last turn. A card with a null presence is
     * drawn as a back, which is what the Empire sees of a rebel placement.
     */
    setOverlay(overlay, ghost = false) {
        this.overlay = overlay;
        this.overlayGhost = ghost;
        this.drawOverlay();
    }
    /**
     * Drawn on a layer of its own rather than inside the town box, because
     * `.iaw-town` clips its contents — the box is a fixed 120x104 whatever it
     * holds, and these sit above it.
     */
    drawOverlay() {
        const layer = document.getElementById('iaw-overlays');
        if (!layer) {
            return;
        }
        layer.innerHTML = Object.entries(this.overlay)
            .filter(([, cards]) => cards.length > 0)
            .map(([townId, cards]) => {
            const town = this.scenario.towns[townId];
            // Straddling the bottom edge, half in and half out: inside the
            // box it reads as part of the town, and sitting on the boundary
            // says it is being *added* — while clearing the bottom row,
            // which is the face-up stack and the supply contribution.
            // Two different things share this layer, so they say which they
            // are: what you are placing now, and what landed last turn.
            const hint = this.overlayGhost
                ? _('Played here on the last turn')
                : _('You are placing these here this turn');
            return `<div class="iaw-town-overlay${this.overlayGhost ? ' ghost' : ''}"
                             style="left:${this.px(town.x)}px;top:${this.px(town.y) + TOWN_HEIGHT / 2}px"
                        >${cards.map(card => card.presence === null
                ? `<span class="iaw-chip face-down" title="${hint}"></span>`
                : presenceHtml(`+${card.presence}`, 'iaw-chip', hint)).join('')}</div>`;
        }).join('');
    }
    /**
     * Last turn's marches, drawn faded along the roads they used.
     *
     * A separate layer from the staging arrows so the two can be on screen at
     * once: what your opponent did, and what you are about to do in reply.
     */
    setGhostArrows(moves) {
        this.drawArrows('iaw-ghost-arrows', moves);
    }
    /** @param delta town id => signed troop change being staged this turn */
    setTroopDelta(delta) {
        this.troopDelta = delta;
        this.updateAll();
    }
    /** @param build town id => troops being raised there this turn */
    setBuildDelta(build) {
        this.buildDelta = build;
        this.updateAll();
    }
    /**
     * Draw the marches being staged as arrows along the roads they follow, so
     * the plan is visible on the map rather than only in a list.
     */
    setMoveArrows(moves) {
        this.drawArrows('iaw-move-arrows', moves);
    }
    drawArrows(layerId, moves) {
        const layer = document.getElementById(layerId);
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
        this.cardDelta = {};
        this.troopDelta = {};
        this.buildDelta = {};
        this.setOverlay({});
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
 * Agreeing to end the game, which both turn states offer in the same words.
 *
 * It is deliberately not called a pass. A pass that skipped your turn would
 * stop the deck draining, and the deck is the clock — two players could stall
 * forever, which is the failure the clock was designed to prevent. This is a
 * standing offer: when both are up the game ends and every remaining town
 * resolves at once, exactly as running the deck out does.
 *
 * The offer travels with the turn rather than as an action of its own, so it
 * cannot be made or withdrawn out of turn, and it survives until withdrawn.
 */
function endOfferLabel(offered) {
    return offered ? _('Withdraw offer to end') : _('Offer to end the game');
}
function endOfferHtml(offered, opponentOffered) {
    if (offered && opponentOffered) {
        return `<div class="iaw-warning"><b>${_('Confirming ends the game.')}</b>
                ${_('Your opponent has already offered, so every remaining town resolves at once — at the presence standing in it today.')}</div>`;
    }
    if (offered) {
        return `<div class="iaw-hint"><b>${_('Offering to end.')}</b>
                ${_('The game stops when your opponent offers too, and every remaining town resolves at once.')}</div>`;
    }
    if (opponentOffered) {
        return `<div class="iaw-hint"><b>${_('Your opponent has offered to end the game.')}</b>
                ${_('Offer as well and it stops here, resolving every remaining town at once.')}</div>`;
    }
    return '';
}

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
class EmpireTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        /** Town id => troops being built there this turn. */
        this.produce = {};
        this.moves = [];
        this.source = null;
        this.step = 'build';
        /** Standing offer to end the game, sent with the turn. */
        this.offerEnd = false;
    }
    onEnteringState(args, isCurrentPlayerActive) {
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
    watchingHtml() {
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
    reset() {
        this.produce = {};
        this.moves = [];
        this.source = null;
        this.step = this.buildable().length > 0 ? 'build' : 'move';
        // An offer stands until it is withdrawn, so it starts where it was left.
        this.offerEnd = this.args.offeredEnd;
    }
    /** Towns that can build at least one troop this turn. */
    buildable() {
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
    buildRoom(townId) {
        const offered = this.args.production[townId] ?? 0;
        return Math.max(0, offered - (this.produce[townId] ?? 0));
    }
    // -- staging ------------------------------------------------------------
    onTownClick(townId) {
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
    addMove(from, to) {
        const existing = this.moves.find(move => move.from === from && move.to === to);
        if (existing) {
            existing.count += 1;
        }
        else {
            this.moves.push({ from, to, count: 1 });
        }
    }
    /** Towns troops may march out of. */
    marchableFrom() {
        return Object.keys(this.game.board.allTowns()).filter(townId => this.marchable(townId) > 0);
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
    marchable(townId) {
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
    projected(townId) {
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
    willLook() {
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
    refresh() {
        const title = this.title();
        this.bga.statusBar.setTitle(title.text, title.args);
        // Building is step 2 of the Empire's turn, marching step 3.
        this.game.setPhase(this.step === 'build' ? 1 : 2);
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
    title() {
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
    selectableTowns() {
        if (this.step === 'build') {
            return this.buildable().filter(id => this.buildRoom(id) > 0);
        }
        if (this.source !== null) {
            return this.game.board.neighborsOf(this.source);
        }
        return this.marchableFrom();
    }
    stagingHtml() {
        const lines = [];
        const built = Object.entries(this.produce).filter(([, count]) => count > 0);
        lines.push(built.length
            ? built.map(([townId, count]) => `<div>${_('Building')} ${count} ${_('at')} <b>${this.townLabel(townId)}</b></div>`).join('')
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
    supplyWarningHtml() {
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
    buttons() {
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
    townLabel(townId) {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }
    // -- sending ------------------------------------------------------------
    commit() {
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

/**
 * The Insurgency stages its whole hand, then commits.
 *
 * Placing is one simultaneous decision — every card goes out every turn — so
 * the client stages the assignment locally and sends it in a single action,
 * which is also what makes the PHP a direct port of the simulator's
 * InsurgencyTurn.
 *
 * Any resolution happened in the Resolve state before this one, so `openTowns`
 * is already final: a town resolved this turn is simply not in it.
 */
class InsurgencyTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        /** card id => town it is staged for. */
        this.assigned = {};
        this.order = [];
        this.selectedCard = null;
        /** Standing offer to end the game, sent with the turn. */
        this.offerEnd = false;
    }
    onEnteringState(args, isCurrentPlayerActive) {
        this.args = {
            openTowns: args?.openTowns ?? [],
            offeredEnd: args?.offeredEnd ?? false,
            opponentOfferedEnd: args?.opponentOfferedEnd ?? false,
        };
        this.reset();
        this.bga.statusBar.setTitle(isCurrentPlayerActive
            ? _('${you} must place the entire hand')
            : _('${actplayer} must place the whole hand'));
        this.game.setPhase(-1);
        if (!isCurrentPlayerActive) {
            this.game.setStagingText(`<div class="iaw-hint">${_('The Insurgency is placing cards. You are the Empire, so there is nothing to do until it is your turn.')}</div>`);
            return;
        }
        this.game.setPhase(1); // placing the hand
        this.game.onHandClick(cardId => this.onCardClick(cardId));
        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.game.board.onTownDrop((townId, cardId) => this.onCardDropped(townId, cardId));
        this.refresh();
    }
    onLeavingState() {
        this.reset();
        this.game.board.clearInteraction();
        this.game.setStagingText('');
        this.game.clearZoom();
        this.game.setPhase(-1);
        this.game.renderHand();
        this.game.renderLastTurn();
    }
    reset() {
        this.assigned = {};
        this.order = [];
        this.selectedCard = null;
        // An offer stands until it is withdrawn, so it starts where it was left.
        this.offerEnd = this.args.offeredEnd;
    }
    // -- staging ------------------------------------------------------------
    onCardClick(cardId) {
        this.selectedCard = this.selectedCard === cardId ? null : cardId;
        this.refresh();
    }
    /**
     * The zoom panel, which is also the only feedback that a card is selected.
     *
     * Selecting used to change nothing on screen — `selectedCard` was never
     * passed to the renderer — so players reported that click-then-click did
     * not work. It always did; it just said nothing.
     */
    refreshZoom() {
        if (this.selectedCard === null) {
            this.game.clearZoom();
            return;
        }
        const card = this.game.cardById(this.selectedCard);
        if (card) {
            this.game.zoomCard(card, _('Click a town to place it.'), () => {
                this.selectedCard = null;
                this.refresh();
            });
        }
    }
    onTownClick(townId) {
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
        if (!this.args.openTowns.includes(townId)) {
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
    // -- display ------------------------------------------------------------
    refresh() {
        const delta = {};
        Object.values(this.assigned).forEach(townId => {
            delta[townId] = (delta[townId] ?? 0) + 1;
        });
        // Your own staged cards, face up over the town they are going to. Safe
        // pre-commit — they are yours — and cleared on leaving, or they would
        // still be on screen once they are face down.
        const overlay = {};
        this.order.forEach(cardId => {
            const townId = this.assigned[cardId];
            (overlay[townId] ?? (overlay[townId] = [])).push({
                presence: this.game.cardById(cardId)?.presence ?? null,
            });
        });
        this.game.board.setOverlay(overlay);
        this.game.board.setCardDelta(delta);
        this.game.renderHand(this.assigned, this.selectedCard);
        this.refreshZoom();
        this.game.board.setSelectable(this.args.openTowns);
        this.game.board.setSelected([]);
        const remaining = this.unassigned().length;
        this.game.setStagingText((remaining > 0
            ? `<div><b>${_('Cards still to place')}: ${remaining}</b></div>`
            : `<div><b>${_('The whole hand is placed.')}</b></div>`)
            + this.placementsHtml()
            + (remaining > 0
                ? `<div class="iaw-hint">${_('Drag a card onto a town, or click a card then a town. Every card must go somewhere.')}</div>`
                : `<div class="iaw-hint">${_('Confirm when you are happy with it.')}</div>`)
            + endOfferHtml(this.offerEnd, this.args.opponentOfferedEnd));
        this.buttons(remaining);
    }
    /**
     * One line per staged card, in the order they were placed.
     *
     * The Empire's box lists its marches, and placement deserves the same: "+2"
     * on a town says how many but not which, and which is the whole decision.
     * The order is real information too — the last card onto a town is the top
     * of its pile, which is what a look reads first — so these are listed in
     * placement order rather than grouped by town.
     */
    placementsHtml() {
        return this.order.map(cardId => {
            const card = this.game.cardById(cardId);
            const value = card?.presence ?? 0;
            // "Agent", not "Influence": influence is dead vocabulary, and the
            // value it carries is presence, so it is drawn as presence.
            return `<div>${_('Agent')} ${presenceHtml(value)} ${_('to')}
                    <b>${this.townLabel(this.assigned[cardId])}</b></div>`;
        }).join('');
    }
    townLabel(townId) {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }
    buttons(remaining) {
        this.bga.statusBar.removeActionButtons();
        this.bga.statusBar.addActionButton(_('Confirm placement'), () => this.commit(), { disabled: remaining > 0 });
        this.bga.statusBar.addActionButton(_('Reset'), () => {
            this.reset();
            this.refresh();
        }, { color: 'secondary' });
        this.bga.statusBar.addActionButton(endOfferLabel(this.offerEnd), () => {
            this.offerEnd = !this.offerEnd;
            this.refresh();
        }, { color: 'secondary' });
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
            offerEnd: this.offerEnd ? '1' : '0',
        });
    }
}

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
class Resolve {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        this.target = null;
    }
    onEnteringState(args, isCurrentPlayerActive) {
        this.args = {
            side: args?.side ?? 'empire',
            resolvable: args?.resolvable ?? [],
        };
        this.target = null;
        this.game.setPhase(isCurrentPlayerActive ? 0 : -1);
        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} may resolve a town'));
            this.game.setStagingText(`<div class="iaw-hint">${_('Your opponent is deciding whether to resolve a town.')}</div>`);
            return;
        }
        this.game.board.onTownClick(townId => this.onTownClick(townId));
        this.refresh();
    }
    onLeavingState() {
        this.target = null;
        this.game.board.clearInteraction();
        this.game.setStagingText('');
        // The turn states set their own step; this matters for the hand-off
        // straight back to NextTurn, which has no client state to set one.
        this.game.setPhase(-1);
    }
    onTownClick(townId) {
        // Toggle, so a mis-click is undone by clicking the same town again.
        if (this.args.resolvable.includes(townId)) {
            this.target = this.target === townId ? null : townId;
            this.refresh();
        }
    }
    refresh() {
        this.bga.statusBar.setTitle(_('${you} may resolve one town, before anything else happens'));
        this.game.board.setSelectable(this.args.resolvable);
        this.game.board.setSelected(this.target ? [this.target] : []);
        this.game.setStagingText(this.stagingHtml());
        this.buttons();
    }
    stagingHtml() {
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
    buttons() {
        this.bga.statusBar.removeActionButtons();
        this.bga.statusBar.addActionButton(this.target
            ? _('Resolve') + ' ' + this.townLabel(this.target)
            : _('Resolve'), () => this.bga.actions.performAction('actResolve', { town: this.target }), { disabled: this.target === null });
        this.bga.statusBar.addActionButton(_('Resolve nothing this turn'), () => this.bga.actions.performAction('actSkipResolve', {}), { color: 'secondary' });
    }
    townLabel(townId) {
        return this.bga.gameui.gamedatas.scenario.towns[townId].label;
    }
}

/** Where the written rules live. The repo is the source of truth for them. */
const RULES_URL = 'https://github.com/scotfree/ironandwhisper/blob/main/ironandwhisper.md';
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
class Help {
    constructor(scenario, board) {
        this.scenario = scenario;
        this.board = board;
        this.dialog = null;
    }
    /**
     * Put a ? at the right-hand end of the status bar.
     *
     * Deliberately not a status bar action button: `removeActionButtons()` runs
     * on every state change and would take it with it. This lives in a corner of
     * the title bar of its own, added once.
     */
    install() {
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
    /**
     * "You are playing the Empire" (or the Insurgency), pinned above the state
     * description at the top of the page.
     *
     * The state description alone never says which side it is talking about —
     * "${you} must place your entire hand" reads the same for either — and the
     * asymmetry is the one thing worth never losing track of. A spectator gets
     * nothing: they are not playing a side.
     */
    installSideBanner(side) {
        const bar = document.getElementById('page-title');
        if (!bar || document.getElementById('iaw-side-banner') || side === null) {
            return;
        }
        bar.insertAdjacentHTML('afterbegin', `
            <div id="iaw-side-banner" class="${side}">${side === 'insurgency'
            ? _('You are playing the Insurgency')
            : _('You are playing the Empire')}</div>
        `);
    }
    show() {
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
    showStartOverlay(side) {
        if (document.getElementById('iaw-start-overlay')) {
            return;
        }
        const overlay = document.createElement('div');
        overlay.id = 'iaw-start-overlay';
        overlay.innerHTML = `
            <div id="iaw-start-card">
                ${this.primerHtml(side)}
                <div id="iaw-start-hint">${_('Click anywhere, or press any key, to continue')}</div>
            </div>
        `;
        document.body.appendChild(overlay);
        const dismiss = () => {
            overlay.remove();
            document.removeEventListener('keydown', dismiss);
        };
        overlay.addEventListener('click', dismiss);
        document.addEventListener('keydown', dismiss);
    }
    // -- the cheat sheet ----------------------------------------------------
    sheetHtml() {
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
    paragraphs() {
        const ties = this.scenario.empireWinsTies ? _('ties go to the Empire') : _('ties go to the Insurgency');
        return `
            <p>${_('An asymmetric game for two. The Empire moves troops everyone can see; the Insurgency plays cards nobody can. A town is settled when one side <b>resolves</b> it: the presence the rebels have there against the presence the Empire has, higher wins, and')} ${ties}. ${_('The winner scores what the loser committed — each side scores the presence it took off the other — and the loser\'s commitment leaves the board for good. A walkover scores nothing: there is no prize for a town nobody contested.')}</p>

            <p>${_('<b>Resolution comes first in a turn</b>, and it is judged on the board as your opponent left it. You may only resolve a town you are present in — the Empire needs a troop there, the rebels need a card in the pile — so you cannot march in and cash out on arrival. Whatever you commit has to survive a reply.')}</p>

            <p>${_('<b>The deck is the clock.</b>')} ${_('The rebels place their entire hand every turn, so the game runs ${turns} turns, or fewer if every town is resolved first.').replace('${turns}', String(this.scenario.turns))} ${_('Four things end it: the deck runs out, every town is resolved, the Empire is eliminated, or both sides have a standing offer to end. Everything still open then resolves at once, at whatever is standing.')}</p>

            <p>${_('<b>Supply limits an army, not production.</b> Occupied towns that touch form a network, and its supply is the most troops it can keep standing. Go over and they are marked; if the network is still short at the end of the Empire\'s next turn they starve, and the rebels score them. Building past the ceiling is legal, so the Empire may raise troops and march them out to the supply that will feed them in one motion.')}</p>
        `;
    }
    legendHtml() {
        const art = (svg) => `<span class="iaw-legend-art">${svg}</span>`;
        const stack = (kind, count, sum) => `<span class="iaw-stack ${kind}"><span class="iaw-stack-count">${count}</span>${sum === undefined ? '' : presenceHtml(sum)}</span>`;
        const rows = [
            // First, because it is the one quantity in the game and every row
            // under it is either presence or a count of something else.
            [presenceHtml(2),
                _('Presence, wherever it is shown. Cards carry it and troops carry it; a town goes to whoever has more of it. A plain number — the height of a stack, the size of a garrison — is a count of pieces, not presence.')],
            [art(this.board.townSvg()),
                _('A town. Adds its supply to whatever Empire network holds it.')],
            [art(this.board.citySvg()) + ' <span class="iaw-produce">&#128296;</span>',
                _('A city, marked with a hammer. Also builds a troop a turn for whoever holds it.')],
            [`<span class="iaw-troops">${this.board.pawnSvg()
                    ? `<span class="iaw-pawn">${this.board.pawnSvg()}</span>` : ''}${this.scenario.unit.presence === 1
                    ? presenceHtml(3)
                    : `<span class="iaw-troop-count">3</span>${presenceHtml(3 * this.scenario.unit.presence)}`}</span>`,
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
            [presenceHtml('+2', 'iaw-chip') + `<span class="iaw-chip face-down"></span>`,
                _('Agents above a town: face up while you are placing them, and greyed afterwards to show what your opponent placed on their last turn.')],
        ];
        return `<table class="iaw-legend">${rows.map(([icon, text]) => `<tr><td class="iaw-legend-icon">${icon}</td><td>${text}</td></tr>`).join('')}</table>`;
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
    phaseListHtml(side, current) {
        return `
            <ol class="iaw-phases">${this.steps(side).map((step, index) => `<li class="${index === current ? 'current' : ''}">${step}</li>`).join('')}</ol>
        `;
    }
    steps(side) {
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
    primerHtml(side) {
        const rebel = side === 'insurgency';
        const summary = rebel
            ? _('You place ${hand} hidden agents on towns each turn; some are decoys, some carry real presence. When you think a town\'s cards overpower its garrison, <b>resolve</b> it and find out: you score the presence you drive out, if you win.')
                .replace('${hand}', String(this.scenario.handSize))
            : _('You build troops in cities, march them along roads, and keep them supplied by networks of occupied towns. When you think a garrison outweighs the rebels\' presence in a town, <b>resolve</b> it and find out: you score the presence you capture, if you win.');
        return `
            <div class="iaw-primer ${rebel ? 'insurgency' : 'empire'}">
                <div class="iaw-frame-title">${_('Rules Summary')}</div>
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
    cardDetailHtml(card) {
        const known = card.presence !== null;
        const type = known ? this.scenario.cardTypes[card.type] : null;
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
    troopDetailHtml() {
        const unit = this.scenario.unit;
        return `
            <div class="iaw-detail">
                <div class="iaw-detail-art">${this.board.pawnSvg()}</div>
                <div class="iaw-detail-name">${unit.label} ${presenceHtml(unit.presence)}</div>
                <div class="iaw-detail-text">${_('Presence +${presence}. Moves ${movement} town per turn. Costs ${supply} supply to keep standing, and reads ${peek} card per turn when it holds still.')
            .replace('${presence}', String(unit.presence))
            .replace('${movement}', String(unit.movement))
            .replace('${supply}', String(this.scenario.supplyPerTroop))
            .replace('${peek}', String(unit.peek))}</div>
            </div>
        `;
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
        /** The solo opponent, or null in a two-player game. */
        this.bot = null;
        /** Which step of the current side's turn we are on; -1 for none. */
        this.phase = -1;
        /**
         * The army list entry the pointer is over, by name, or null.
         *
         * Kept by name rather than by element because the list is rebuilt on every
         * board change — a dozen times on a full refresh — and the highlight has to
         * survive that. If the army it names has gone (a line was cut while the
         * pointer sat there) the highlight goes with it.
         */
        this.hoveredArmy = null;
        /** Reused rather than rebuilt, so repeated opens do not leak dialogs. */
        this.pileDialog = null;
        /**
         * What happened on the last turn somebody else took.
         *
         * Deliberately persistent rather than animated: an animation plays once and
         * is gone, and in a turn-based game you often arrive after it played. These
         * markers survive a reload and can be read at your own pace. Cleared when
         * the acting side is you — you do not need a ghost of your own move.
         */
        this.lastTurn = null;
        this.handClickHandler = () => { };
        this.bga = bga;
        this.bga.states.register('Resolve', new Resolve(this, bga));
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
                    <div id="iaw-state">
                        <div class="iaw-frame-title">${_('Game Status')}</div>
                        <div id="iaw-clock"></div>
                        <div id="iaw-phases"></div>
                    </div>
                    <div id="iaw-zoom"></div>
                    <div id="iaw-staging">
                        <div class="iaw-frame-title">${_('Turn Status')}</div>
                        <div id="iaw-staging-text"></div>
                        <div id="iaw-hand"></div>
                    </div>
                    <div id="iaw-armies-frame" hidden>
                        <div class="iaw-frame-title">${_('Armies')}</div>
                        <div id="iaw-armies"></div>
                    </div>
                    <div id="iaw-last-turn"></div>
                    <div id="iaw-primer"></div>
                </div>
            </div>
        `);
        this.board = new BoardView(document.getElementById('iaw-board-area'), gamedatas.scenario, gamedatas.towns, this.side);
        this.board.onBoardChanged(() => this.renderArmies());
        this.board.render();
        // The silhouettes are files, so they arrive after the first paint. The
        // board is drawn and usable without them.
        this.board.loadFrames();
        // The bot has no player record, so it gets a panel of its own rather
        // than a row in gamedatas.players.
        this.bot = gamedatas.bot;
        if (this.bot) {
            this.bga.playerPanels.addAutomataPlayerPanel(this.bot.id, this.bot.name, {
                color: this.bot.side === 'empire' ? '6b3fa0' : '2e7d4f',
                score: this.bot.score,
            });
        }
        const sideLabel = (side) => side === 'empire' ? _('Empire') : _('Insurgency');
        Object.entries(gamedatas.players).forEach(([playerId, player]) => {
            this.bga.playerPanels.getElement(Number(playerId)).insertAdjacentHTML('beforeend', `
                <div class="iaw-player-side">${sideLabel(player.side)}</div>
            `);
        });
        if (this.bot) {
            this.bga.playerPanels.getElement(this.bot.id).insertAdjacentHTML('beforeend', `
                <div class="iaw-player-side">${sideLabel(this.bot.side)}</div>
            `);
        }
        this.help = new Help(gamedatas.scenario, this.board);
        this.help.install();
        this.help.installSideBanner(this.side);
        this.renderPrimer();
        // The same reminder, blown up and shown once at the start of the game:
        // round 1 is the closest thing to "just sat down" that a page load can
        // tell, since every reload re-runs setup() with no other signal for it.
        if (gamedatas.round <= 1) {
            this.help.showStartOverlay(this.side);
        }
        this.renderPhases();
        this.wireArmyHover();
        this.board.onStackClick((townId, faceUp) => this.showPile(townId, faceUp));
        this.board.onTroopClick(() => this.showTroopZoom());
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                this.dismissZoom();
            }
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
    /**
     * The Empire's armies, one per supply network.
     *
     * Usually there is one. When a line is cut there are two, and the split is
     * the single most consequential thing on the board — each half now feeds
     * itself or starves — so it is worth saying in words rather than leaving
     * to be read off twelve separate supply badges.
     */
    renderArmies() {
        const element = document.getElementById('iaw-armies');
        if (!element) {
            return;
        }
        // The frame's title has nothing to say about an Empire with no troops
        // left standing, so the whole thing goes rather than leaving a heading
        // over an empty box.
        const frame = document.getElementById('iaw-armies-frame');
        const armies = this.board.armies();
        if (frame) {
            frame.hidden = armies.length === 0;
        }
        if (!armies.length) {
            element.innerHTML = '';
            return;
        }
        element.innerHTML = armies.map(army => `
            <div class="iaw-army${army.supplyUsed > army.supplyAvailable ? ' over' : ''}"
                 data-army="${army.name}"
                 title="${_('Hover to find this army on the map')}">
                <div class="iaw-army-pawn">${this.board.pawnSvg()}</div>
                <div class="iaw-army-detail">
                    <div class="iaw-army-name">${army.name} ${_('Army')}
                        <span class="iaw-army-load"
                              title="${_('Supply used, of supply available')}"
                            >(${army.supplyUsed}/${army.supplyAvailable})</span></div>
                    <div class="iaw-army-supply">${this.supplySentence(army)}</div>
                </div>
            </div>
        `).join('');
        // The list was just rebuilt under the pointer, so the highlight has to
        // be put back — and dropped if that army no longer exists.
        const hovered = armies.find(army => army.name === this.hoveredArmy);
        if (this.hoveredArmy && !hovered) {
            this.hoveredArmy = null;
        }
        this.board.setArmyHighlight(hovered ? hovered.towns : []);
        if (hovered) {
            element.querySelector(`[data-army="${hovered.name}"]`)?.classList.add('hovered');
        }
    }
    /**
     * Hovering an army lights its network on the map.
     *
     * Delegated from the container and bound once, because the entries
     * themselves are thrown away and rebuilt on every board change — listeners
     * attached to them would not survive a single notification.
     */
    wireArmyHover() {
        const element = document.getElementById('iaw-armies');
        if (!element) {
            return;
        }
        element.addEventListener('mouseover', event => {
            const entry = event.target?.closest('.iaw-army');
            this.highlightArmy(entry?.dataset.army ?? null);
        });
        element.addEventListener('mouseleave', () => this.highlightArmy(null));
    }
    highlightArmy(name) {
        if (name === this.hoveredArmy) {
            return;
        }
        this.hoveredArmy = name;
        document.querySelectorAll('.iaw-army.hovered')
            .forEach(node => node.classList.remove('hovered'));
        const army = this.board.armies().find(candidate => candidate.name === name);
        this.board.setArmyHighlight(army ? army.towns : []);
        if (army) {
            document.querySelector(`.iaw-army[data-army="${name}"]`)?.classList.add('hovered');
        }
    }
    /**
     * Kept as one translatable sentence with placeholders rather than
     * concatenated fragments, which no translator can reorder.
     */
    supplySentence(army) {
        // Where the supply comes from is the other half of a cut line: an army
        // of four drawing on three towns loses a third of its ceiling with the
        // first town it gives up. Only towns that actually contribute are
        // counted — a town the rebels have won stays in the network, and feeds
        // nothing, for ever.
        const sentence = army.supplyTowns === 1
            ? _('${troops} troops using ${used} supply of ${available} available from one town.')
            : _('${troops} troops using ${used} supply of ${available} available from ${towns} towns.');
        return sentence
            .replace('${troops}', presenceHtml(army.troops, '', _('Presence this army carries')))
            .replace('${used}', String(army.supplyUsed))
            .replace('${available}', String(army.supplyAvailable))
            .replace('${towns}', String(army.supplyTowns));
    }
    onHandClick(handler) {
        this.handClickHandler = handler;
    }
    /**
     * @param assigned card id => town it is staged for, drawn as already dealt with
     * @param selected the card currently picked up, drawn as picked up
     */
    renderHand(assigned = {}, selected = null) {
        const element = document.getElementById('iaw-hand');
        if (!element) {
            return;
        }
        // If the server sent a hand, it is yours. Deciding that here would only
        // be a second opinion, and a second opinion can disagree. Anyone else
        // reads the rebels' card count off the clock, so this stays empty
        // rather than repeating it under a heading.
        if (this.gamedatas.hand === null) {
            element.innerHTML = '';
            return;
        }
        element.innerHTML = this.hand.map(card => {
            const label = String(card.presence ?? 0);
            const staged = assigned[card.id] ? ' staged' : '';
            const picked = card.id === selected ? ' selected' : '';
            const where = assigned[card.id] ? ` title="${assigned[card.id]}"` : '';
            return `<span class="iaw-card hand ${card.type}${staged}${picked}" draggable="true"
                          data-card-id="${card.id}"${where}>${presenceHtml(label)}</span>`;
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
    /**
     * Which step of your side's turn the game is waiting on, or -1 for none.
     *
     * The steps that happen on commit — looking, starving, drawing — are never
     * current, because nobody is ever asked about them. Each state class sets
     * this on entering; `NextTurn` and the opponent's turn clear it.
     */
    setPhase(phase) {
        this.phase = phase;
        this.renderPhases();
    }
    renderPhases() {
        const element = document.getElementById('iaw-phases');
        if (element && this.help) {
            element.innerHTML = this.help.phaseListHtml(this.side, this.phase);
        }
    }
    /**
     * The card or troop under inspection, drawn large in the side column.
     *
     * Clicking a card in hand selects it for placement *and* shows it here —
     * selection used to change nothing on screen at all, which is why players
     * reported that click-then-click did not work.
     */
    showZoom(html, footer = '', onDismiss) {
        const element = document.getElementById('iaw-zoom');
        if (!element) {
            return;
        }
        this.onZoomDismiss = onDismiss;
        if (!html) {
            element.innerHTML = '';
            element.classList.remove('open');
            document.getElementById('iaw-side-area')?.classList.remove('zoomed');
            return;
        }
        element.innerHTML = `
            <button type="button" class="iaw-zoom-close"
                    title="${_('Close')}" aria-label="${_('Close')}">&times;</button>
            ${html}
            ${footer ? `<div class="iaw-zoom-hint">${footer}</div>` : ''}
        `;
        element.classList.add('open');
        document.getElementById('iaw-side-area')?.classList.add('zoomed');
        element.querySelector('.iaw-zoom-close')
            ?.addEventListener('click', () => this.dismissZoom());
    }
    /**
     * Close the panel, and tell whoever opened it.
     *
     * A card opened by selecting it has to put the card down as well as close
     * the panel, which is what `onDismiss` is for; a card opened to be looked at
     * has nothing to undo.
     */
    dismissZoom() {
        const dismiss = this.onZoomDismiss;
        this.clearZoom();
        dismiss?.();
    }
    clearZoom() {
        this.onZoomDismiss = undefined;
        const element = document.getElementById('iaw-zoom');
        if (element) {
            element.innerHTML = '';
            element.classList.remove('open');
        }
        document.getElementById('iaw-side-area')?.classList.remove('zoomed');
    }
    zoomCard(card, footer = '', onDismiss) {
        this.showZoom(this.help.cardDetailHtml(card), footer, onDismiss);
    }
    showTroopZoom() {
        this.showZoom(this.help.troopDetailHtml());
    }
    /**
     * A town's agents, in the order they were placed, newest on top.
     *
     * Order is real information for both sides: the top of the pile is what the
     * Empire's next look reads. The rebels see the faces of their own cards —
     * remembering twenty turns of placements across twelve towns is clerical
     * rather than strategic, and the player who keeps notes should not beat the
     * player who does not.
     */
    showPile(townId, faceUp) {
        const town = this.board.getTown(townId);
        const cards = faceUp ? town.revealed : town.pile;
        const label = this.gamedatas.scenario.towns[townId].label;
        // Rebuilt every time rather than reused. A BGA popin's close button
        // destroys its DOM — that is what replaceCloseCallback exists for — so a
        // kept instance opens exactly once and then silently does nothing.
        this.pileDialog?.destroy();
        this.pileDialog = new ebg.popindialog();
        this.pileDialog.create('iaw-pile-dialog');
        this.pileDialog.setMaxWidth(560);
        this.pileDialog.setTitle(`${label} — ${faceUp
            ? _('face up') : _('face down')}`);
        this.pileDialog.setContent(cards.length
            ? `<div class="iaw-pile-view">${cards.map((card, index) => `
                   <div class="iaw-pile-row">
                       <span class="iaw-pile-position">${index === 0
                ? _('top') : String(index + 1)}</span>
                       ${this.help.cardDetailHtml(card)}
                   </div>`).join('')}</div>`
            : `<p>${_('Nothing here.')}</p>`);
        this.pileDialog.show();
    }
    setStagingText(html) {
        const element = document.getElementById('iaw-staging-text');
        if (element) {
            element.innerHTML = html;
        }
    }
    /**
     * The permanent reminder of what your side does, below everything that
     * changes. Rendered once: nothing in it depends on the state of the game.
     */
    renderPrimer() {
        const element = document.getElementById('iaw-primer');
        if (!element || !this.help) {
            return;
        }
        element.innerHTML = this.help.primerHtml(this.side);
        element.querySelector('.iaw-primer-more')
            ?.addEventListener('click', () => this.help.show());
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
                town.pile.unshift(known ?? { id: cardId, type: null, presence: null });
            });
            town.pileSize = town.pile.length;
            town.cardCount += cardIds.length;
            this.board.updateTown(townId);
        });
        // Face up if they are yours, backs if they are not: the payload carries
        // real types only for the side that placed them.
        const placed = {};
        Object.entries(args.cards).forEach(([townId, cardIds]) => {
            placed[townId] = cardIds.map(cardId => ({
                presence: this.board.getTown(townId).pile
                    .find(card => card.id === cardId)?.presence ?? null,
            }));
        });
        this.recordLastTurn('insurgency', {
            side: 'insurgency', moves: [], produced: {}, placed,
        });
        this.hand = [];
        if (this.gamedatas.hand !== null) {
            this.gamedatas.hand = [];
        }
        this.renderHand();
    }
    /**
     * Log-only notifications. The board is moved by the notification that
     * follows each of these; these exist so the game log reads as an account of
     * the turn — which town was built in, what marched where, how many cards
     * went into each pile — rather than a one-line summary.
     */
    async notif_built(args) {
    }
    async notif_marched(args) {
    }
    async notif_placedIn(args) {
    }
    /**
     * Someone put up or took down a standing offer to end the game. Log only —
     * the turn states read the current offers from their args, so there is
     * nothing to move on the board.
     */
    async notif_endOffered(args) {
    }
    async notif_empireMoved(args) {
        Object.entries(args.troops).forEach(([townId, troops]) => {
            this.board.getTown(townId).troops = troops;
            this.board.updateTown(townId);
        });
        this.recordLastTurn('empire', {
            side: 'empire',
            moves: args.moves ?? [],
            produced: args.produced ?? {},
            placed: {},
        });
    }
    /**
     * Remember one side's turn, unless it was yours.
     *
     * `mine` is decided from the side rather than the player id, because the
     * bot has no player row and would otherwise never be recognised as the
     * opponent.
     */
    recordLastTurn(actingSide, turn) {
        this.lastTurn = actingSide === this.side ? null : turn;
        this.renderLastTurn();
    }
    /**
     * Draw what the opponent just did: ghost arrows on the roads they used,
     * their cards above the towns they landed in, and the same lines their own
     * staging panel showed them, in the side column.
     */
    renderLastTurn() {
        const element = document.getElementById('iaw-last-turn');
        const turn = this.lastTurn;
        this.board.setGhostArrows(turn?.moves ?? []);
        this.board.setOverlay(turn?.placed ?? {}, true);
        if (!element) {
            return;
        }
        if (!turn) {
            element.innerHTML = '';
            return;
        }
        const label = (townId) => this.gamedatas.scenario.towns[townId].label;
        const lines = [];
        Object.entries(turn.produced).forEach(([townId, count]) => {
            lines.push(`${_('Built')} ${count} ${_('at')} <b>${label(townId)}</b>`);
        });
        turn.moves.forEach(move => {
            lines.push(`${move.count} ${_('from')} <b>${label(move.from)}</b>
                        ${_('to')} <b>${label(move.to)}</b>`);
        });
        Object.entries(turn.placed).forEach(([townId, cards]) => {
            lines.push(`${cards.length} ${cards.length === 1 ? _('agent') : _('agents')}
                        ${_('to')} <b>${label(townId)}</b>`);
        });
        element.innerHTML = `
            <div class="iaw-last-turn">
                <div class="iaw-frame-title">${_('Last Turn')}</div>
                ${lines.length
            ? lines.map(line => `<div>${line}</div>`).join('')
            : `<div class="iaw-hint">${_('Nothing moved.')}</div>`}
            </div>
        `;
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
        // A real player's score is kept by the framework's counter, which
        // updates itself. The bot's is ours to move.
        if (this.bot && args.player_id === this.bot.id) {
            this.bot.score += args.points;
            this.bga.playerPanels.getScoreCounter(this.bot.id)?.setValue(this.bot.score);
        }
        const town = this.board.getTown(args.town_id);
        town.resolved = true;
        town.winner = args.winner;
        town.resolvedCardPresence = args.cardPresence;
        town.resolvedTroopPresence = args.troopPresence;
        // Only the loser's commitment leaves. The Empire keeps its garrison in
        // a town it wins, so zeroing troops here made them vanish until the
        // next turn's notification put them back.
        town.troops -= args.troopsLost;
        town.pile = [];
        town.pileSize = 0;
        // Captured cards are taken off the board; cards the Insurgency held
        // stay, face up.
        town.revealed = args.winner === 'empire' ? [] : args.pile;
        town.cardCount = town.revealed.length;
        this.board.updateTown(args.town_id);
    }
    /**
     * Which garrisons are under notice. Sent every Empire turn, including when
     * it is empty, because a repaired supply line has to clear the warning as
     * visibly as breaking one raised it.
     */
    async notif_starvationWarning(args) {
        Object.keys(this.board.allTowns()).forEach(townId => {
            this.board.getTown(townId).starving = args.starving[townId] ?? 0;
            this.board.updateTown(townId);
        });
    }
    async notif_deckCount(args) {
        this.gamedatas.deckCount = args.deckCount;
        this.gamedatas.handCount = args.handCount;
        // The round advances at the end of the Empire's turn. Reusing the one
        // from setup left the counter stuck at whatever it read on page load.
        this.gamedatas.round = args.round;
        this.updateClock(args.deckCount, args.handCount, args.round);
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
