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

    /** Global variable names. */
    public const G_TO_MOVE = 'to_move';
    public const G_ROUND = 'round';
    public const G_BOT_SCORE = 'bot_score';
    public const G_END_OFFERED_EMPIRE = 'end_offered_empire';
    public const G_END_OFFERED_INSURGENCY = 'end_offered_insurgency';

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
            : clienttranslate('The Insurgency');
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
     * The game is an exact clock: the whole hand is placed every turn, so it
     * lasts deck_size / hand_size Insurgency turns (Decision 6). That makes
     * progression a straight count of rounds rather than a guess.
     */
    public function getGameProgression(): int
    {
        $turns = $this->scenario->turns();
        if ($turns <= 0) {
            return 0;
        }
        return (int) min(100, max(0, round(100 * ($this->round() - 1) / $turns)));
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
     * @return array{winner: string, influence: int, strength: int, points: int}
     */
    public function resolveTown(string $townId, ?string $declaredBy): array
    {
        $towns = $this->board->towns();
        $town = $towns[$townId];

        $outcome = Rules::resolveTown(
            $towns,
            $townId,
            $this->scenario->unitStrength(),
            $this->scenario->empireWinsTies,
        );

        $this->board->markResolved($townId, $outcome);

        $winnerPlayerId = $this->playerIdForSide($outcome['winner']);
        $this->addScore($winnerPlayerId, $outcome['points']);

        $this->bga->notify->all(
            'townResolved',
            clienttranslate('T${turn}: ${town_label} resolves: ${influence} influence against ${strength} strength — ${player_name} takes it for ${points}'),
            [
                'turn' => $this->round(),
                'town_id' => $townId,
                'town_label' => $this->townLabel($townId),
                'i18n' => ['town_label'],
                'influence' => $outcome['influence'],
                'strength' => $outcome['strength'],
                'points' => $outcome['points'],
                'winner' => $outcome['winner'],
                'declaredBy' => $declaredBy,
                'player_id' => $winnerPlayerId,
                'player_name' => $this->playerNameFor($winnerPlayerId),
                // Resolution turns whatever was still face down face up, so
                // this carries the town's whole contents to both players.
                'pile' => array_map(
                    fn(array $card) => ['id' => $card['id'], 'type' => $card['type'], 'influence' => $card['influence']],
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

        // Resolving may have closed the last open town, in which case there is
        // nowhere legal to place and the game is about to end anyway.
        if (!Rules::unresolvedTownIds($towns)) {
            $this->setToMove(Rules::EMPIRE);
            return;
        }

        try {
            Rules::validatePlacements($towns, $hand, $placements);
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

        $this->empireLooks($towns, $plan['arrivals'], $actorId);

        // Starve anything the networks can no longer supply. End of turn, not
        // start, so a line cut by the Insurgency can be answered: the Empire
        // gets one turn to march it back together or accept the loss.
        $this->applyAttrition($disband);

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
     * resolve at full strength next turn — resolution comes first (Decision 4) —
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
     */
    private function applyAttrition(array $disband): void
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
    }

    /**
     * @param array<string, int> $losses town id => troops that starved
     */
    private function reportStarvation(array $losses): void
    {
        $starved = array_sum($losses);
        $points = $starved * $this->scenario->unitStrength();
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
    private function empireLooks(array $towns, array $arrivals, int $actorId): void
    {
        $looks = Rules::peekPlan($towns, $arrivals, $this->scenario->unitPeek());
        if (!$looks) {
            return;
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
                    'influence' => $byId[$cardId]['influence'],
                ],
                $cardIds,
            );
        }

        if (!$revealed) {
            return;
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
        $botId = $this->playerIdForSide($side);

        if ($side === Rules::INSURGENCY) {
            $turn = Bots::insurgencyTurn($this->scenario, $this->board->towns(), $this->board->hand());
            $this->applyInsurgencyTurn($turn['placements'], $turn['resolve'], $botId);
            return;
        }

        $turn = Bots::empireTurn($this->scenario, $this->board->towns());
        $this->applyEmpireTurn($turn['produce'], $turn['moves'], $turn['resolve'], [], $botId);
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
        // Must exist before anything increments it: BGA refuses to inc a global
        // that was never set, rather than treating it as zero.
        $this->bga->globals->set(self::G_BOT_SCORE, 0);

        // Seat order does not decide who starts: the scenario does, and NextTurn
        // activates whoever is to move. BGA still wants an active player to
        // exist before the first state is entered.
        $this->activeNextPlayer();

        return NextTurn::class;
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
