<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Game.php
 *
 * Setup, shared services, and the per-player data feed. The rules themselves
 * live in Rules.php (pure), persistence in Board.php, and what each side is
 * allowed to see in View.php. The turn structure is in States/.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

use Bga\GameFramework\UserException;
use Bga\Games\IronAndWhisper\States\ArrangeDeck;
use Bga\Games\IronAndWhisper\States\NextTurn;

class Game extends \Bga\GameFramework\Table
{
    /**
     * Which scenario the game runs. Fixed for now; the parameters that matter
     * live in scenarios/baseline.json and are shared with the simulator, so
     * retuning does not mean touching PHP.
     */
    public const SCENARIO_ID = 'baseline';

    /** Game option ids, matching gameoptions.jsonc. */
    public const OPT_SIDE_ASSIGNMENT = 100;
    public const SIDES_RANDOM = 0;
    public const SIDES_FIRST_IS_EMPIRE = 1;
    public const SIDES_FIRST_IS_INSURGENCY = 2;

    /**
     * Which Empire bot a solo game plays against.
     *
     * Not a difficulty slider: the three play differently. Glob plays for the
     * supply network and only takes fights it is certain of; Heuristic marches
     * at the tallest pile it can see. Glob beats the Insurgency bot about 64%
     * of the time and Heuristic about 0.5%, so Glob is the default and
     * Heuristic is kept as the port's reference implementation. Glob2 is Glob
     * with a much tighter leash on any garrison outside its main army — see
     * issue #18 and Bots::glob2EmpireTurn — and does not yet beat Mist2 either,
     * but suppresses how much it loses by. Monolith keeps one army and never
     * stands where a visible pile could beat it (Bots::monolithEmpireTurn); it
     * is the first Empire bot to take games off Mist2, about a third of them.
     * MonoLoc is the Monolith playing for ground (Bots::monolocEmpireTurn),
     * for location scoring (option 103), where the Monolith wins almost
     * nothing: it claims every open location a single troop can hold.
     */
    public const OPT_BOT = 101;
    public const BOT_GLOB = 0;
    public const BOT_HEURISTIC = 1;
    public const BOT_GLOB2 = 2;
    public const BOT_MONOLITH = 3;
    public const BOT_MONOLOC = 4;

    /**
     * Which Insurgency bot a solo game plays against.
     *
     * The same choice on the other side. Mist reads the geography — it cashes
     * every town it has already won, takes leads where the Empire has no
     * troops in reach, and spends its bluffs on empty ground beside a
     * garrison. Heuristic piles presence onto the richest garrison it can see
     * and scatters the rest at random. Mist takes every game off GlobEmpire in
     * self-play, where Heuristic loses about 63% of them, so Mist is the
     * default and Heuristic is kept as the port's reference implementation.
     * Mist2 leads with the richest garrison in reach rather than the safest
     * one — see issue #18 and Bots::mist2LeadTarget.
     */
    public const OPT_REBEL_BOT = 102;
    public const REBEL_MIST = 0;
    public const REBEL_HEURISTIC = 1;
    public const REBEL_MIST2 = 2;

    /**
     * Whether winning a resolution also scores the town's value (issue #1).
     *
     * Off is capture-only scoring, the game as it has always been; on, the
     * winner of a resolution banks the town's value as well as the presence it
     * took. An option rather than a rule change so both can be played side by
     * side while the experiment runs. Absent, the scenario decides.
     */
    public const OPT_LOCATION_VALUE = 103;
    public const LOCATION_VALUE_OFF = 0;
    public const LOCATION_VALUE_ON = 1;

    /**
     * Whether the rebels may keep cards in hand (Decision 6 relaxed). When they
     * may, they draw a constant hand-size every turn whatever they placed, so
     * the deck stays an exact clock, and place any part of the hand or none of
     * it. The game ends when there is nothing left to draw. Absent, the
     * scenario decides.
     */
    public const OPT_REBELS_HOLD = 104;
    public const REBELS_HOLD_OFF = 0;
    public const REBELS_HOLD_ON = 1;

    /**
     * Advanced rules (issue #6): currently the one thing this adds is that a
     * human Rebel player may arrange the deck, or shuffle it again, before
     * play begins. Does nothing when a bot plays the Rebels — it has no
     * reason to want anything but the shuffle it was already dealt — and the
     * Empire is never told whether this ran: it never sees the deck either
     * way. A bucket rather than its own option per rule, so whatever advanced
     * rule comes next belongs beside this one rather than as option 106.
     */
    public const OPT_ADVANCED_RULES = 105;
    public const ADVANCED_RULES_OFF = 0;
    public const ADVANCED_RULES_ON = 1;

    /** Global variable names. */
    public const G_TO_MOVE = 'to_move';
    public const G_ROUND = 'round';
    public const G_BOT_SCORE = 'bot_score';
    public const G_END_OFFERED_EMPIRE = 'end_offered_empire';
    public const G_END_OFFERED_INSURGENCY = 'end_offered_insurgency';
    /**
     * Whether the deck is still the Rebels' to arrange (advanced rules,
     * option 105). Set once, at setup, only when ArrangeDeck is the starting
     * state, and cleared for good the moment they submit an order — a draw
     * after that is a surprise exactly as it always was. Absent (0) means
     * either advanced rules were off or a bot plays the Rebels.
     */
    public const G_DECK_ARRANGEMENT_OPEN = 'deck_arrangement_open';
    /**
     * The town resolved most recently, or '' before any has been. The board
     * keeps the result of that one resolution on screen, for whoever missed the
     * announcement; it has to be stored because missing it usually means a
     * page load, and a page load remembers nothing the server does not.
     */
    public const G_LAST_RESOLVED = 'last_resolved';

    /**
     * The bot's stand-in player id in a solo game.
     *
     * It is not a row in the `player` table — the framework creates those, and
     * only for real people. It exists so that everything keyed by player id
     * (sides, scoring, notifications) keeps working with one code path instead
     * of two. BGA recommends 0 or negative for an automata, to avoid colliding
     * with real ids.
     */
    public const BOT_PLAYER_ID = 0;

    public readonly Scenario $scenario;
    public readonly Board $board;

    /** @var array<int, string>|null player id => side, cached per request */
    private ?array $sides = null;

    public function __construct()
    {
        parent::__construct();

        $this->scenario = Scenario::load(self::SCENARIO_ID);
        $this->board = new Board($this, $this->scenario);
    }

    // -- sides --------------------------------------------------------------

    /** @return array<int, string> player id => 'empire' | 'insurgency' */
    public function sides(): array
    {
        if ($this->sides === null) {
            $rows = static::getCollectionFromDB(
                'SELECT `player_id`, `player_side` FROM `player`',
                true
            );
            $this->sides = [];
            foreach ($rows as $playerId => $side) {
                $this->sides[(int) $playerId] = (string) $side;
            }

            // Solo: the bot takes whichever side the human did not.
            if (count($this->sides) === 1) {
                $this->sides[self::BOT_PLAYER_ID] = Rules::otherSide(reset($this->sides));
            }
        }
        return $this->sides;
    }

    public function sideForPlayer(int $playerId): string
    {
        $sides = $this->sides();
        if (!isset($sides[$playerId])) {
            throw new \RuntimeException("no side recorded for player {$playerId}");
        }
        return $sides[$playerId];
    }

    /**
     * The side a viewer is playing, or null if they are not in the game.
     *
     * Spectators reach getAllDatas too, and they are entitled to less than
     * either player: no hand, and no pile that has not been resolved.
     */
    public function sideForViewer(int $playerId): ?string
    {
        return $this->sides()[$playerId] ?? null;
    }

    public function isSolo(): bool
    {
        return isset($this->sides()[self::BOT_PLAYER_ID]);
    }

    public function isBot(int $playerId): bool
    {
        return $playerId === self::BOT_PLAYER_ID;
    }

    // -- agreeing to end ----------------------------------------------------
    //
    // Not a pass in the turn-skipping sense: skipping a turn would stop the
    // deck draining, and the deck is the clock (Decision 1), so two players
    // could stall forever — the exact failure the clock was designed around.
    // This is a standing offer to end. When both offers are up the game ends
    // and every remaining town resolves at once, which is what deck exhaustion
    // does anyway.

    private function endOfferGlobal(string $side): string
    {
        return $side === Rules::EMPIRE
            ? self::G_END_OFFERED_EMPIRE
            : self::G_END_OFFERED_INSURGENCY;
    }

    public function hasOfferedEnd(string $side): bool
    {
        return (int) $this->bga->globals->get($this->endOfferGlobal($side), 0) === 1;
    }

    /**
     * Put an offer up or take it down. Public, and announced: the other side
     * has to know an offer is standing or it can never be met.
     */
    public function setOfferEnd(string $side, bool $offered, int $actorId): void
    {
        if ($this->hasOfferedEnd($side) === $offered) {
            return;
        }

        $this->bga->globals->set($this->endOfferGlobal($side), $offered ? 1 : 0);

        $this->bga->notify->all(
            'endOffered',
            $offered
                ? clienttranslate('T${turn}: ${player_name} offers to end the game and resolve every town')
                : clienttranslate('T${turn}: ${player_name} withdraws the offer to end the game'),
            [
                'turn' => $this->round(),
                'player_id' => $actorId,
                'player_name' => $this->playerNameFor($actorId),
                'side' => $side,
                'offered' => $offered,
            ],
        );
    }

    /**
     * Whether the game may stop now.
     *
     * Solo is one offer, not two: the bot has no opinion to give, so asking it
     * to agree would mean the person could never end a game they had lost
     * interest in.
     */
    public function endAgreed(): bool
    {
        if ($this->isSolo()) {
            foreach ($this->sides() as $playerId => $side) {
                if (!$this->isBot($playerId) && $this->hasOfferedEnd($side)) {
                    return true;
                }
            }
            return false;
        }

        return $this->hasOfferedEnd(Rules::EMPIRE)
            && $this->hasOfferedEnd(Rules::INSURGENCY);
    }

    /**
     * The bot has no row in the `player` table, so its score lives in a global
     * and its name comes from the side it is playing.
     */
    public function botScore(): int
    {
        return (int) $this->bga->globals->get(self::G_BOT_SCORE, 0);
    }

    /** The table statistics recorder. See Stats and stats.jsonc. */
    public function stats(): Stats
    {
        return new Stats($this->bga->tableStats, $this->scenario);
    }

    public function scoreForSide(string $side): int
    {
        $playerId = $this->playerIdForSide($side);
        return $this->isBot($playerId) ? $this->botScore() : (int) $this->bga->playerScore->get($playerId);
    }

    /** The last thing a game does: record how it ended. See NextTurn::endGame. */
    public function recordGameEnd(string $reason): void
    {
        $this->stats()->gameEnded(
            $reason,
            $this->round(),
            $this->scoreForSide(Rules::EMPIRE),
            $this->scoreForSide(Rules::INSURGENCY),
            $this->board->towns(),
            count($this->board->hand()),
        );
    }

    /** The tie-breaker for one side: see Rules::tiebreak. */
    public function tiebreakFor(string $side): array
    {
        return Rules::tiebreak($this->board->towns(), $side, $this->scenario->unitPresence());
    }

    public function levelOnPoints(): bool
    {
        return $this->scoreForSide(Rules::EMPIRE) === $this->scoreForSide(Rules::INSURGENCY);
    }

    /**
     * Who won: on points, then on the tie-breaker. Never a draw — the
     * tie-breaker's last step always decides.
     *
     * Needed because BGA ranks a game from the players' scores alone, and in a solo
     * game only the person has a row — the bot's score is a global. Left to
     * the framework, every solo game ended as a win for the person, whatever
     * the bot had scored (table 974986: the Rebels won 14-12 and BGA reported
     * the Empire). EndScore uses this to tell BGA the truth.
     */
    public function winningSide(): string
    {
        $key = fn(string $side) => [$this->scoreForSide($side), ...$this->tiebreakFor($side)];
        return $key(Rules::EMPIRE) > $key(Rules::INSURGENCY) ? Rules::EMPIRE : Rules::INSURGENCY;
    }

    /**
     * Say who won, with both scores, as the last line of the log. In a solo
     * game this is also the only authoritative statement of the bot's score.
     */
    public function announceResult(): void
    {
        $winner = $this->winningSide();
        $args = [
            'empire_score' => $this->scoreForSide(Rules::EMPIRE),
            'rebels_score' => $this->scoreForSide(Rules::INSURGENCY),
            'winner' => $winner,
            'bot_score' => $this->isSolo() ? $this->botScore() : null,
        ];
        if ($this->levelOnPoints()) {
            $this->bga->notify->all('finalScore',
                $winner === Rules::EMPIRE
                    ? clienttranslate('Final score: the Empire ${empire_score}, the Rebels ${rebels_score} — level, and the Empire wins on the tie-breaker')
                    : clienttranslate('Final score: the Empire ${empire_score}, the Rebels ${rebels_score} — level, and the Rebels win on the tie-breaker'),
                $args);
            return;
        }
        $this->bga->notify->all('finalScore',
            $winner === Rules::EMPIRE
                ? clienttranslate('Final score: the Empire ${empire_score}, the Rebels ${rebels_score} — the Empire wins')
                : clienttranslate('Final score: the Empire ${empire_score}, the Rebels ${rebels_score} — the Rebels win'),
            $args);
    }

    public function addScore(int $playerId, int $points): void
    {
        if ($this->isBot($playerId)) {
            $this->bga->globals->inc(self::G_BOT_SCORE, $points);
            return;
        }
        $this->bga->playerScore->inc($playerId, $points);
    }

    public function playerNameFor(int $playerId): string
    {
        if (!$this->isBot($playerId)) {
            return $this->getPlayerNameById($playerId);
        }
        return $this->sides()[$playerId] === Rules::EMPIRE
            ? clienttranslate('The Empire')
            : clienttranslate('The Rebels');
    }

    public function playerIdForSide(string $side): int
    {
        foreach ($this->sides() as $playerId => $playerSide) {
            if ($playerSide === $side) {
                return $playerId;
            }
        }
        throw new \RuntimeException("no player holds the {$side}");
    }

    // -- turn bookkeeping ---------------------------------------------------

    /** The side whose turn it is about to be, mirroring GameState.to_move. */
    public function toMove(): string
    {
        return (string) $this->bga->globals->get(self::G_TO_MOVE, Rules::INSURGENCY);
    }

    public function setToMove(string $side): void
    {
        $this->bga->globals->set(self::G_TO_MOVE, $side);
    }

    public function round(): int
    {
        return (int) $this->bga->globals->get(self::G_ROUND, 1);
    }

    public function incRound(): void
    {
        $this->bga->globals->inc(self::G_ROUND, 1);
    }

    /**
     * Two clocks run, and the game ends on whichever runs out first: the deck,
     * which lasts deck_size / hand_size Insurgency turns whether or not the
     * rebels hold cards (Decision 6), and the board, which ends the game when
     * every location is resolved. Logged games end on the board around turn 10
     * of 20, so counting rounds alone left the bar at half way when the game
     * finished. Progression is whichever clock is further along.
     */
    public function getGameProgression(): int
    {
        $turns = $this->scenario->turns();
        $deck = $turns > 0 ? ($this->round() - 1) / $turns : 0;

        $towns = $this->board->towns();
        $resolved = count($towns) - count(Rules::unresolvedTownIds($towns));
        $board = $towns ? $resolved / count($towns) : 0;

        return (int) min(100, max(0, round(100 * max($deck, $board))));
    }

    public function upgradeTableDb($from_version)
    {
    }

    // -- resolution ---------------------------------------------------------

    /**
     * Take the resolution a player has declared.
     *
     * The one gate for a declared resolution: presence is checked here and the
     * town is resolved here, whether the declaration came from the Resolve
     * state, from a bot, or from a test. Nothing else may call resolveTown for
     * a *declared* resolution — end-of-game sweeps are the only exception, and
     * they pass a null declarer.
     */
    public function declareResolution(string $townId, string $side): void
    {
        if (!Rules::canDeclare($this->board->towns(), $townId, $side)) {
            throw new UserException($side === Rules::EMPIRE
                ? clienttranslate('You have no troops in that town, or it is already resolved')
                : clienttranslate('You have no cards in that town, or it is already resolved'));
        }

        $this->resolveTown($townId, $side);
    }

    /**
     * Flip a pile, score it, and freeze the town.
     *
     * Shared by all three callers: the Insurgency declaring, the Empire
     * declaring, and the simultaneous resolution triggered when the deck runs
     * out — which is why `$declaredBy` may be null.
     *
     * The whole pile becomes public here, so the notification carries it in
     * full to both players. That is the one moment hidden cards are legitimately
     * revealed.
     *
     * @return array{winner: string, cardPresence: int, troopPresence: int, points: int}
     */
    public function resolveTown(string $townId, ?string $declaredBy): array
    {
        $towns = $this->board->towns();
        $town = $towns[$townId];

        $outcome = Rules::resolveTown(
            $towns,
            $townId,
            $this->scenario->unitPresence(),
            $this->scenario->empireWinsTies,
            $this->scoresLocations() ? (int) $this->scenario->towns[$townId]['value'] : 0,
        );

        $this->board->markResolved($townId, $outcome);
        $this->bga->globals->set(self::G_LAST_RESOLVED, $townId);

        $winnerPlayerId = $this->playerIdForSide($outcome['winner']);
        $this->addScore($winnerPlayerId, $outcome['points'] + $outcome['locationPoints']);

        $this->stats()->resolution(
            $this->round(),
            $declaredBy,
            $outcome['winner'],
            $outcome['cardPresence'],
            $outcome['troopPresence'],
            $outcome['points'],
            $outcome['winner'] === Rules::EMPIRE ? 0 : (int) $town['troops'],
            array_merge($town['revealed'], $town['pile']),
            $outcome['locationPoints'],
        );

        $this->bga->notify->all(
            'townResolved',
            $outcome['locationPoints'] > 0
                ? clienttranslate('T${turn}: ${town_label} resolves: Rebels ${cardPresence} presence against the Empire\'s ${troopPresence} — ${player_name} takes it for ${points}, and ${locationPoints} for the location')
                : clienttranslate('T${turn}: ${town_label} resolves: Rebels ${cardPresence} presence against the Empire\'s ${troopPresence} — ${player_name} takes it for ${points}'),
            [
                'turn' => $this->round(),
                'town_id' => $townId,
                'town_label' => $this->townLabel($townId),
                'i18n' => ['town_label'],
                'cardPresence' => $outcome['cardPresence'],
                'troopPresence' => $outcome['troopPresence'],
                'points' => $outcome['points'],
                'locationPoints' => $outcome['locationPoints'],
                'winner' => $outcome['winner'],
                'declaredBy' => $declaredBy,
                'player_id' => $winnerPlayerId,
                'player_name' => $this->playerNameFor($winnerPlayerId),
                // Resolution turns whatever was still face down face up, so
                // this carries the town's whole contents to both players.
                'pile' => array_map(
                    fn(array $card) => ['id' => $card['id'], 'type' => $card['type'], 'presence' => $card['presence']],
                    array_merge($town['revealed'], $town['pile']),
                ),
                // The loser's commitment leaves the board; the winner's stays.
                'troopsLost' => $outcome['winner'] === Rules::EMPIRE ? 0 : $town['troops'],
                'cardsTaken' => $outcome['winner'] === Rules::EMPIRE
                    ? Rules::townCardCount($town) : 0,
            ],
        );

        return $outcome;
    }

    // -- playing a turn -----------------------------------------------------
    //
    // Both turns are applied here rather than inside their state classes, so a
    // bot can take a turn through exactly the same code a person does. A state
    // class is a thin adapter over these: it validates that the framework is
    // in the right state and hands off.

    /**
     * The Insurgency resolves, then seeds its whole hand.
     *
     * Ported from apply_insurgency_turn() in sim/engine.py, which is why the
     * resolution is still a parameter here: a bot's whole turn arrives in one
     * call. A person's resolution has already been taken in the Resolve state
     * and this is reached with $resolve null.
     *
     * @param array<string, int[]> $placements town id => card ids, in the order
     *                                         they go onto the pile (last on top)
     */
    public function applyInsurgencyTurn(array $placements, ?string $resolve, int $actorId): void
    {
        // Resolution happens first, against the board as the Empire left it
        // (Decision 4). A person has already taken theirs in the Resolve state
        // before this is reached; a bot hands both halves over at once.
        if ($resolve !== null && $resolve !== '') {
            $this->declareResolution($resolve, Rules::INSURGENCY);
        }

        $towns = $this->board->towns();
        $hand = $this->board->handCardIds();
        $handCards = $this->board->hand();

        // Resolving may have closed the last open town, in which case there is
        // nowhere legal to place and the game is about to end anyway.
        if (!Rules::unresolvedTownIds($towns)) {
            $this->setToMove(Rules::EMPIRE);
            return;
        }

        try {
            Rules::validatePlacements($towns, $hand, $placements, $this->rebelsHoldCards());
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }

        $placed = [];
        foreach ($placements as $townId => $cardIds) {
            $cardIds = array_map('intval', $cardIds);
            if (!$cardIds) {
                continue;
            }
            $this->board->placeOnPile($townId, $cardIds);
            $placed[$townId] = $cardIds;
        }

        $placedIds = array_merge([], ...array_values($placed));
        $this->stats()->placement(array_values(array_filter(
            $handCards,
            static fn(array $card) => in_array((int) $card['id'], $placedIds, true),
        )));

        // Pile heights are public — that is the whole point of forcing the
        // entire hand out every turn — and so is which card sits where, since
        // the Empire watches the heights change. What the cards *are* is not,
        // so this carries ids and no types.
        // One line per town, so the log reads as an account of the turn rather
        // than a total. The state-carrying notification comes after, silent.
        foreach ($placed as $townId => $cardIds) {
            $this->bga->notify->all(
                'placedIn',
                clienttranslate('T${turn}: ${player_name} places ${count} in ${town_label}'),
                [
                    'turn' => $this->round(),
                    'player_id' => $actorId,
                    'player_name' => $this->playerNameFor($actorId),
                    'count' => count($cardIds),
                    'town_label' => $this->townLabel($townId),
                    'i18n' => ['town_label'],
                ],
            );
        }

        $this->bga->notify->all('cardsPlaced', '', [
            'player_id' => $actorId,
            'count' => count($hand),
            'cards' => $placed,
        ]);

        $this->setToMove(Rules::EMPIRE);
    }

    /**
     * The Empire resolves, then raises, marches and looks — in that order,
     * because a resolution is judged on the board as the Insurgency left it
     * (Decision 4).
     *
     * Ported from apply_empire_turn() in sim/engine.py, which is why the
     * resolution is still a parameter here: a bot's whole turn arrives in one
     * call. A person's resolution has already been taken in the Resolve state
     * and this is reached with $resolve null.
     *
     * @param array<int, array{from: string, to: string, count: int}> $moves
     */
    public function applyEmpireTurn(
        array $produce,
        array $moves,
        ?string $resolve,
        array $disband,
        int $actorId,
        bool $chooseLosses = false,
    ): void {
        // Resolution happens first (Decision 4): no marching in and cashing
        // out on arrival. What you commit has to survive a reply. A person has
        // already taken theirs in the Resolve state; a bot hands both halves
        // over at once.
        if ($resolve !== null && $resolve !== '') {
            $this->declareResolution($resolve, Rules::EMPIRE);
        }

        $towns = $this->board->towns();
        $moves = $this->normalizeMoves($moves);
        $produce = array_map('intval', $produce);

        // Troop changes accumulate here and are written once, so a troop that
        // is raised and then marched in the same turn costs one update.
        $delta = [];

        try {
            Rules::validateProduction($towns, $produce, $this->scenario->productionCost);
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }
        foreach ($produce as $townId => $count) {
            $delta[$townId] = ($delta[$townId] ?? 0) + $count;
            $towns[$townId]['troops'] += $count;
        }

        try {
            $plan = Rules::planMoves($towns, $moves);
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }
        foreach ($plan['departures'] as $townId => $count) {
            $delta[$townId] = ($delta[$townId] ?? 0) - $count;
            $towns[$townId]['troops'] -= $count;
        }
        foreach ($plan['arrivals'] as $townId => $count) {
            $delta[$townId] = ($delta[$townId] ?? 0) + $count;
            $towns[$townId]['troops'] += $count;
        }

        // A person chooses every troop a starving army loses, judged on the
        // board as this turn leaves it. Checked before anything is written, so
        // a refused turn changes nothing. A bot's disband is only a preference.
        $disband = array_map('intval', $disband);
        if ($chooseLosses) {
            try {
                Rules::validateDisband($towns, $this->scenario->supplyPerTroop, $disband);
            } catch (IllegalMove $e) {
                throw new UserException($e->getMessage());
            }
        }

        $this->board->adjustTroops($delta);

        foreach ($produce as $townId => $count) {
            $this->bga->notify->all(
                'built',
                clienttranslate('T${turn}: ${player_name} builds ${count} in ${town_label}'),
                [
                    'turn' => $this->round(),
                    'player_id' => $actorId,
                    'player_name' => $this->playerNameFor($actorId),
                    'count' => $count,
                    'town_label' => $this->townLabel($townId),
                    'i18n' => ['town_label'],
                ],
            );
        }

        foreach ($moves as [$from, $to, $count]) {
            $this->bga->notify->all(
                'marched',
                clienttranslate('T${turn}: ${player_name} marches ${count} from ${from_label} to ${to_label}'),
                [
                    'turn' => $this->round(),
                    'player_id' => $actorId,
                    'player_name' => $this->playerNameFor($actorId),
                    'count' => $count,
                    'from_label' => $this->townLabel($from),
                    'to_label' => $this->townLabel($to),
                    'i18n' => ['from_label', 'to_label'],
                ],
            );
        }

        $this->bga->notify->all('empireMoved', '', [
            'player_id' => $actorId,
            'produced' => $produce,
            'moves' => array_map(
                fn(array $move) => ['from' => $move[0], 'to' => $move[1], 'count' => $move[2]],
                $moves,
            ),
            'troops' => array_map(fn(array $town) => $town['troops'], $towns),
        ]);

        $looked = $this->empireLooks($towns, $plan['arrivals'], $actorId);

        // Starve anything the networks can no longer supply. End of turn, not
        // start, so a line cut by the Insurgency can be answered: the Empire
        // gets one turn to march it back together or accept the loss.
        [$starved, $underNotice] = $this->applyAttrition($disband);

        $this->stats()->empireTurn($this->board->towns(), array_sum($produce), $looked, $starved, $underNotice);

        $this->incRound();
        $this->setToMove(Rules::INSURGENCY);
    }

    /**
     * Starve what was already warned about, then warn about the rest.
     *
     * A network that cannot feed its troops does not starve them at once. It is
     * marked, the Empire gets its next turn to do something about it, and only
     * if it is still short at the end of *that* turn does anybody die.
     * Immediate attrition made massing self-defeating in a way nobody could see
     * coming: the troops you march in are fed by the towns you marched them out
     * of, so concentrating destroys the supply that would have fed the
     * concentration — and the loss landed between turns, after the player had
     * stopped looking at the board.
     *
     * With a turn of grace the same move becomes a decision. Mass this turn,
     * resolve at full presence next turn — resolution comes first (Decision 4) —
     * then either spread back out onto the supply or accept the loss.
     *
     * The mark is a forecast, never a reservation: what actually falls is
     * recomputed here from the board as it stands, so repairing the line
     * cancels it and moving troops moves where it lands.
     *
     * Starved troops score for the Insurgency. That is not a special scoring
     * rule, it is the general one: the Insurgency scores every Empire troop
     * that leaves the board, whether it was beaten off or starved off. Cutting
     * a supply line is a way of taking troops, so it pays like one.
     *
     * @param array<string, int> $disband the Empire's choice of where to lose from
     * @return array{0: int, 1: bool} troops starved, and whether an army is now under notice
     */
    private function applyAttrition(array $disband): array
    {
        $towns = $this->board->towns();
        $plan = Rules::attritionPlan($towns, $this->scenario->supplyPerTroop, $disband);

        // Only networks already carrying a warning lose anybody. One that has
        // just gone short is marked below instead.
        $losses = [];
        foreach (Rules::components($towns) as $component) {
            $warned = false;
            foreach ($component as $townId) {
                if ($towns[$townId]['starving'] > 0) {
                    $warned = true;
                    break;
                }
            }
            if (!$warned) {
                continue;
            }
            foreach ($component as $townId) {
                if (($plan[$townId] ?? 0) > 0) {
                    $losses[$townId] = $plan[$townId];
                }
            }
        }

        if ($losses) {
            $this->board->adjustTroops(array_map(fn(int $count) => -$count, $losses));
            $this->reportStarvation($losses);
        }

        // Whatever is still short is next turn's warning; anything that came
        // good is cleared, which is what makes repairing the line pay.
        $forecast = Rules::attritionPlan(
            $this->board->towns(),
            $this->scenario->supplyPerTroop,
            $disband,
        );
        $this->board->setStarving($forecast);

        $this->bga->notify->all('starvationWarning', '', ['starving' => $forecast]);

        return [array_sum($losses), array_sum($forecast) > 0];
    }

    /**
     * @param array<string, int> $losses town id => troops that starved
     */
    private function reportStarvation(array $losses): void
    {
        $starved = array_sum($losses);
        $points = $starved * $this->scenario->unitPresence();
        $insurgencyId = $this->playerIdForSide(Rules::INSURGENCY);
        $this->addScore($insurgencyId, $points);

        $this->bga->notify->all(
            'troopsStarved',
            clienttranslate('T${turn}: ${count} Empire troops starve for want of supply in ${town_labels} — ${player_name} scores ${points}'),
            [
                'turn' => $this->round(),
                'count' => $starved,
                'points' => $points,
                'losses' => $losses,
                'town_labels' => $this->townLabels(array_keys($losses)),
                'i18n' => ['town_labels'],
                'player_id' => $insurgencyId,
                'player_name' => $this->playerNameFor($insurgencyId),
                'troops' => array_map(
                    fn(array $town) => $town['troops'],
                    $this->board->towns(),
                ),
            ],
        );
    }

    /**
     * Turn the top card of each watched pile face up.
     *
     * This is public. The cards physically sit face up beside their town, and
     * the Insurgency could always work out exactly what the Empire had seen —
     * it knows what it placed and troop positions are visible — so putting them
     * on the table costs it nothing and spares both players the bookkeeping.
     *
     * @param array<string, array> $towns    board after generation and movement
     * @param array<string, int> $arrivals
     */
    private function empireLooks(array $towns, array $arrivals, int $actorId): int
    {
        $looks = Rules::peekPlan($towns, $arrivals, $this->scenario->unitPeek());
        if (!$looks) {
            return 0;
        }

        $revealed = [];
        foreach ($looks as $townId => $lookCount) {
            $cardIds = Rules::revealFromPile($towns[$townId]['pile'], $lookCount);
            if (!$cardIds) {
                continue;
            }

            $this->board->reveal($cardIds);

            $byId = [];
            foreach ($towns[$townId]['pile'] as $card) {
                $byId[$card['id']] = $card;
            }
            $revealed[$townId] = array_map(
                fn(int $cardId) => [
                    'id' => $cardId,
                    'type' => $byId[$cardId]['type'],
                    'presence' => $byId[$cardId]['presence'],
                ],
                $cardIds,
            );
        }

        if (!$revealed) {
            return 0;
        }

        $this->bga->notify->all(
            'cardsRevealed',
            clienttranslate('T${turn}: ${player_name} turns ${count} cards face up in ${town_labels}'),
            [
                'turn' => $this->round(),
                'player_id' => $actorId,
                'player_name' => $this->playerNameFor($actorId),
                'count' => array_sum(array_map('count', $revealed)),
                'town_labels' => $this->townLabels(array_keys($revealed)),
                'i18n' => ['town_labels'],
                'revealed' => $revealed,
            ],
        );

        return array_sum(array_map('count', $revealed));
    }

    public function townLabel(string $townId): string
    {
        return $this->scenario->towns[$townId]['label'] ?? $townId;
    }

    /** @param string[] $townIds */
    public function townLabels(array $townIds): string
    {
        return implode(', ', array_map(fn(string $id) => $this->townLabel($id), $townIds));
    }

    /**
     * @param array<int, mixed> $moves
     * @return array<int, array{0: string, 1: string, 2: int}>
     */
    private function normalizeMoves(array $moves): array
    {
        $normalized = [];
        foreach ($moves as $move) {
            if (!is_array($move) || !isset($move['from'], $move['to'], $move['count'])) {
                throw new UserException(clienttranslate('Malformed move'));
            }
            $normalized[] = [(string) $move['from'], (string) $move['to'], (int) $move['count']];
        }
        return $normalized;
    }

    /**
     * Take the bot's turn for whichever side it is playing.
     *
     * It goes through the same apply* methods a person's turn does, so it is
     * held to the same validation and produces the same notifications. The
     * Empire bot reads only Empire-legal information — see Bots::belief().
     */
    public function playBotTurn(string $side): void
    {
        $this->applyBotTurn(
            $side,
            $this->botTurn($side, $this->empireBot(), $this->insurgencyBot()),
            $this->playerIdForSide($side),
        );
    }

    /**
     * A zombie's turn: a player who has left is replaced by the strongest
     * bots, MonoLoc and Mist2, whatever the solo options say (in a two-player
     * game they are not shown). That is BGA's zombie level 2 — the best move
     * on what that side may see, without planning ahead. A zombie that only
     * stood still left the person still playing with nothing to play against.
     *
     * `$mayResolve` is false once the turn's Resolve phase is over: the
     * player may already have resolved, and one resolution is all a turn
     * allows, so the bot's own choice is dropped. The Empire's plan holds
     * without it — it only resolves a certain win, which leaves the garrison
     * where it was either way. The Rebels' needs one repair: Mist plans its
     * placements on the board after its resolution, so when that resolution
     * would close the last open location it places nothing at all. With the
     * location left open, the whole hand must still go down, and the cards it
     * kept back go onto the location it meant to resolve — still open, and one
     * the Rebels are winning.
     */
    public function playZombieTurn(string $side, int $playerId, bool $mayResolve): void
    {
        $turn = $this->botTurn($side, self::BOT_MONOLOC, self::REBEL_MIST2);
        if (!$mayResolve) {
            $intended = $turn['resolve'];
            $turn['resolve'] = null;

            if ($side === Rules::INSURGENCY && $intended !== null) {
                $placed = array_merge([], ...array_values($turn['placements']));
                $left = array_values(array_diff($this->board->handCardIds(), $placed));
                if ($left) {
                    $turn['placements'][$intended] = array_merge($turn['placements'][$intended] ?? [], $left);
                }
            }
        }
        $this->applyBotTurn($side, $turn, $playerId);
    }

    /** The named bot's whole turn for this side, as plain data. */
    private function botTurn(string $side, int $empireBot, int $insurgencyBot): array
    {
        $towns = $this->board->towns();

        if ($side === Rules::INSURGENCY) {
            $hand = $this->board->hand();
            return match ($insurgencyBot) {
                self::REBEL_HEURISTIC => Bots::insurgencyTurn($this->scenario, $towns, $hand),
                self::REBEL_MIST2 => Bots::mist2InsurgencyTurn($this->scenario, $towns, $hand),
                default => Bots::mistInsurgencyTurn($this->scenario, $towns, $hand),
            };
        }

        return match ($empireBot) {
            self::BOT_HEURISTIC => Bots::empireTurn($this->scenario, $towns),
            self::BOT_GLOB2 => Bots::glob2EmpireTurn($this->scenario, $towns),
            self::BOT_MONOLITH => Bots::monolithEmpireTurn($this->scenario, $towns),
            self::BOT_MONOLOC => Bots::monolocEmpireTurn($this->scenario, $towns),
            default => Bots::globEmpireTurn($this->scenario, $towns),
        };
    }

    /** Apply a bot's turn through the same methods a person's goes through. */
    private function applyBotTurn(string $side, array $turn, int $actorId): void
    {
        if ($side === Rules::INSURGENCY) {
            $this->applyInsurgencyTurn($turn['placements'], $turn['resolve'], $actorId);
            return;
        }

        $this->applyEmpireTurn(
            $turn['produce'],
            $turn['moves'],
            $turn['resolve'],
            $turn['disband'] ?? [],
            $actorId,
        );
    }

    /** Whether this table scores location value, from game option 103 or else the scenario. */
    public function scoresLocations(): bool
    {
        $option = $this->bga->tableOptions->get(self::OPT_LOCATION_VALUE);
        if ($option === null) {
            return $this->scenario->locationValue;
        }
        return (int) $option === self::LOCATION_VALUE_ON;
    }

    /** Whether the rebels may hold cards at this table, from game option 104 or else the scenario. */
    public function rebelsHoldCards(): bool
    {
        $option = $this->bga->tableOptions->get(self::OPT_REBELS_HOLD);
        if ($option === null) {
            return $this->scenario->rebelsHoldCards;
        }
        return (int) $option === self::REBELS_HOLD_ON;
    }

    /** Which Empire bot this table chose, from game option 101. */
    private function empireBot(): int
    {
        return (int) ($this->bga->tableOptions->get(self::OPT_BOT) ?? self::BOT_GLOB);
    }

    /** Which Insurgency bot this table chose, from game option 102. */
    private function insurgencyBot(): int
    {
        return (int) ($this->bga->tableOptions->get(self::OPT_REBEL_BOT) ?? self::REBEL_MIST);
    }

    // -- data feed ----------------------------------------------------------

    /**
     * Everything the current player may see.
     *
     * The filtering all happens in View::forSide — see the warning there about
     * which side sees what. Nothing in this method may reach around it.
     */
    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = View::forSide(
            scenario: $this->scenario,
            towns: $this->board->towns(),
            hand: $this->board->hand(),
            deck: $this->board->deckCards(),
            deckArrangementOpen: $this->deckArrangementOpen(),
            deckCount: $this->board->deckCount(),
            round: $this->round(),
            sides: $this->sides(),
            viewerSide: $this->sideForViewer($currentPlayerId),
        );

        $result['players'] = static::getCollectionFromDB(
            'SELECT `player_id` AS `id`, `player_score` AS `score`, `player_side` AS `side` FROM `player`'
        );

        // Deliberately not added to `players`: that array is the framework's,
        // and a row in it for somebody with no player record invites trouble.
        // The client draws the bot with addAutomataPlayerPanel instead.
        $result['bot'] = $this->isSolo() ? [
            'id' => self::BOT_PLAYER_ID,
            'side' => $this->sides()[self::BOT_PLAYER_ID],
            'name' => $this->playerNameFor(self::BOT_PLAYER_ID),
            'score' => $this->botScore(),
        ] : null;

        $result['lastResolved'] = (string) $this->bga->globals->get(self::G_LAST_RESOLVED, '');
        // Per table rather than per scenario, so it rides beside the scenario
        // rather than inside it.
        $result['locationValue'] = $this->scoresLocations();
        $result['rebelsHold'] = $this->rebelsHoldCards();

        return $result;
    }

    // -- setup --------------------------------------------------------------

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $defaultColors = $gameinfos['player_colors'];

        $playerIds = array_map('intval', array_keys($players));
        $assignment = $this->assignSides($playerIds);

        $queryValues = [];
        foreach ($players as $playerId => $player) {
            $queryValues[] = vsprintf("(%s, '%s', '%s', '%s')", [
                $playerId,
                array_shift($defaultColors),
                addslashes($player['player_name']),
                $assignment[(int) $playerId],
            ]);
        }

        static::DbQuery(sprintf(
            'INSERT INTO `player` (`player_id`, `player_color`, `player_name`, `player_side`) VALUES %s',
            implode(',', $queryValues)
        ));

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos['player_colors']);
        $this->reloadPlayersBasicInfos();
        $this->sides = null;

        $this->board->setup();

        $this->setToMove($this->scenario->firstPlayer);
        $this->bga->globals->set(self::G_ROUND, 1);
        $this->bga->globals->set(self::G_END_OFFERED_EMPIRE, 0);
        $this->bga->globals->set(self::G_END_OFFERED_INSURGENCY, 0);
        $this->bga->globals->set(self::G_LAST_RESOLVED, '');
        // Must exist before anything increments it: BGA refuses to inc a global
        // that was never set, rather than treating it as zero.
        $this->bga->globals->set(self::G_BOT_SCORE, 0);

        // Which bot, if any, plays each side: 0 for a person, else the game
        // option's value + 1, so a bot game can be told from a human one.
        $botSide = $this->isSolo() ? ($this->sides()[self::BOT_PLAYER_ID] ?? null) : null;
        $this->stats()->setup(
            $botSide === Rules::EMPIRE ? $this->empireBot() + 1 : 0,
            $botSide === Rules::INSURGENCY ? $this->insurgencyBot() + 1 : 0,
            $this->scoresLocations(),
            $this->rebelsHoldCards(),
        );

        // Seat order does not decide who starts: the scenario does, and NextTurn
        // activates whoever is to move. BGA still wants an active player to
        // exist before the first state is entered.
        $this->activeNextPlayer();

        // Advanced rules (option 105, issue #6): a human Rebel may arrange the
        // deck before anything else happens. A bot has no reason to want
        // anything but the shuffle it was already dealt, so this never
        // applies to one. ArrangeDeck activates the Rebel player itself.
        $insurgencyId = $this->playerIdForSide(Rules::INSURGENCY);
        if ($this->advancedRules() && !$this->isBot($insurgencyId)) {
            $this->bga->globals->set(self::G_DECK_ARRANGEMENT_OPEN, 1);
            return ArrangeDeck::class;
        }

        return NextTurn::class;
    }

    /** Whether advanced rules are on at this table, from game option 105. */
    public function advancedRules(): bool
    {
        return (int) ($this->bga->tableOptions->get(self::OPT_ADVANCED_RULES) ?? self::ADVANCED_RULES_OFF)
            === self::ADVANCED_RULES_ON;
    }

    /** Whether the deck is still the Rebels' to arrange. See G_DECK_ARRANGEMENT_OPEN. */
    public function deckArrangementOpen(): bool
    {
        return (int) $this->bga->globals->get(self::G_DECK_ARRANGEMENT_OPEN, 0) === 1;
    }

    /**
     * Set the deck's order from the Rebels' arrangement and close
     * arrangement for the rest of the game (advanced rules, option 105,
     * issue #6).
     *
     * @param int[] $order the chosen draw order, index 0 drawn next
     */
    public function arrangeDeck(array $order): void
    {
        $order = array_map('intval', $order);

        try {
            Rules::validateDeckOrder($this->board->deckCardIds(), $order);
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }

        $this->board->reorderDeck($order);
        $this->bga->globals->set(self::G_DECK_ARRANGEMENT_OPEN, 0);
    }

    /**
     * Sides come from game option 100.
     *
     * The two sides play completely different games, so this is a real choice
     * rather than a colour. Seat order is the tie: "first player" means the
     * first seat at the table.
     *
     * @param int[] $playerIds in seat order
     * @return array<int, string>
     */
    private function assignSides(array $playerIds): array
    {
        $option = $this->bga->tableOptions->get(self::OPT_SIDE_ASSIGNMENT) ?? self::SIDES_RANDOM;

        // Solo: only the human gets a row, and the bot takes what is left over
        // (see sides()). "First player" is the human.
        if (count($playerIds) === 1) {
            $side = match ($option) {
                self::SIDES_FIRST_IS_EMPIRE => Rules::EMPIRE,
                self::SIDES_FIRST_IS_INSURGENCY => Rules::INSURGENCY,
                default => mt_rand(0, 1) === 0 ? Rules::EMPIRE : Rules::INSURGENCY,
            };
            return [$playerIds[0] => $side];
        }

        $order = $playerIds;
        if ($option === self::SIDES_FIRST_IS_INSURGENCY) {
            $order = array_reverse($order);
        } elseif ($option === self::SIDES_RANDOM) {
            shuffle($order);
        }

        // $order[0] takes the Empire.
        return [
            $order[0] => Rules::EMPIRE,
            $order[1] => Rules::INSURGENCY,
        ];
    }

    /**
     * Debug helper: jump to a state you want to test, from the Studio's Debug
     * button.
     */
    public function debug_goToState(int $state = 3)
    {
        $this->gamestate->jumpToState($state);
    }
}
