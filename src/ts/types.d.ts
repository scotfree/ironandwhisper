/**
 * Shapes coming back from the PHP. These mirror View::forSide exactly — if you
 * change one, change the other.
 */

type Side = 'empire' | 'insurgency';

interface CardView {
    id: number;
    /** null when the viewer is not entitled to know what this card is. */
    type: string | null;
    presence: number | null;
}

/** A card drawn above a town: a value to show, or null for a face-down back. */
interface OverlayCard {
    presence: number | null;
}

/** One side's completed turn, kept so the other side can see what happened. */
interface LastTurn {
    side: Side;
    moves: StagedMove[];
    produced: Record<string, number>;
    placed: Record<string, OverlayCard[]>;
}

interface TownView {
    id: string;
    troops: number;
    /**
     * How many of this town's troops will starve at the end of the Empire's
     * next turn if its network is still short. A warning, not a reservation.
     */
    starving: number;
    resolved: boolean;
    winner: Side | null;
    resolvedCardPresence: number;
    resolvedTroopPresence: number;
    /** Cards still face down. Only the Insurgency is sent their faces. */
    pileSize: number;
    pile: CardView[];
    /** Face up beside the pile, visible to everyone, still counting at resolution. */
    revealed: CardView[];
    cardCount: number;
}

interface TownDef {
    id: string;
    label: string;
    x: number;
    y: number;
    neighbors: string[];
    /** What this town adds to the ceiling of whatever Empire network holds it. */
    supply: number;
    /** Troops it can build per turn, if the Empire stands there. */
    production: number;
}

interface ScenarioView {
    id: string;
    label: string;
    towns: Record<string, TownDef>;
    edges: [string, string][];
    unit: { id: string; label: string; presence: number; movement: number; peek: number };
    cardTypes: Record<string, { id: string; label: string; presence: number }>;
    deck: Record<string, number>;
    handSize: number;
    supplyPerTroop: number;
    productionCost: number;
    empireWinsTies: boolean;
    turns: number;
}

interface IronAndWhisperPlayer extends Player {
    side: Side;
}

/** The solo opponent. Null in a two-player game. */
interface BotView {
    id: number;
    side: Side;
    name: string;
    score: number;
}

interface IronAndWhisperGamedatas extends Gamedatas<IronAndWhisperPlayer> {
    bot: BotView | null;
    /** The side of whoever this payload was built for. Null for a spectator. */
    you: Side | null;
    sides: Record<string, Side>;
    scenario: ScenarioView;
    towns: Record<string, TownView>;
    /** The Insurgency's hand. Null for the Empire — it is not entitled to it. */
    hand: CardView[] | null;
    handCount: number;
    deckCount: number;
    round: number;
}

/** The resolution phase, which both sides pass through before their turn. */
interface ResolveArgs {
    side: Side;
    resolvable: string[];
}

interface InsurgencyTurnArgs {
    openTowns: string[];
    /** Whether this side's standing offer to end the game is up. */
    offeredEnd: boolean;
    opponentOfferedEnd: boolean;
}

interface EmpireTurnArgs {
    /** Town id => how many troops it may build this turn, ceiling included. */
    production: Record<string, number>;
    networks: { towns: string[]; ceiling: number; troops: number }[];
    /** Whether this side's standing offer to end the game is up. */
    offeredEnd: boolean;
    opponentOfferedEnd: boolean;
}

/** One of the Empire's supply networks, as the army list shows it. */
interface ArmyView {
    /** The town in it holding the most troops. */
    name: string;
    towns: string[];
    troops: number;
    supplyUsed: number;
    supplyAvailable: number;
    /** How many of its towns actually feed it: a rebel-won town feeds nothing. */
    supplyTowns: number;
}

/** A move staged in the client, before it is sent. */
interface StagedMove {
    from: string;
    to: string;
    count: number;
}
