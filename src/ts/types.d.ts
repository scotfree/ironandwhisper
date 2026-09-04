/**
 * Shapes coming back from the PHP. These mirror View::forSide exactly — if you
 * change one, change the other.
 */

type Side = 'empire' | 'insurgency';

interface CardView {
    id: number;
    /** null when the viewer is not entitled to know what this card is. */
    type: string | null;
    influence: number | null;
}

interface TownView {
    id: string;
    troops: number;
    resolved: boolean;
    winner: Side | null;
    resolvedInfluence: number;
    resolvedStrength: number;
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
    unit: { id: string; label: string; strength: number; movement: number; peek: number };
    cardTypes: Record<string, { id: string; label: string; influence: number }>;
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

interface InsurgencyTurnArgs {
    openTowns: string[];
    resolvable: string[];
}

interface EmpireTurnArgs {
    /** Town id => how many troops it may build this turn, ceiling included. */
    production: Record<string, number>;
    networks: { towns: string[]; ceiling: number; troops: number }[];
    resolvable: string[];
}

/** A move staged in the client, before it is sent. */
interface StagedMove {
    from: string;
    to: string;
    count: number;
}
