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
        $this->bga->playerScore->inc($winnerPlayerId, $outcome['points']);

        $this->bga->notify->all(
            'townResolved',
            clienttranslate('${town_label} resolves: ${influence} influence against ${strength} strength — ${player_name} takes it for ${points}'),
            [
                'town_id' => $townId,
                'town_label' => $town['label'],
                'i18n' => ['town_label'],
                'influence' => $outcome['influence'],
                'strength' => $outcome['strength'],
                'points' => $outcome['points'],
                'winner' => $outcome['winner'],
                'declaredBy' => $declaredBy,
                'player_id' => $winnerPlayerId,
                'player_name' => $this->getPlayerNameById($winnerPlayerId),
                // Face up from now on: everyone sees the pile.
                'pile' => array_map(
                    fn(array $card) => ['id' => $card['id'], 'type' => $card['type'], 'influence' => $card['influence']],
                    $town['pile'],
                ),
                // Troops committed here are spent, not returned (Decision 3).
                'troopsSpent' => $town['troops'],
            ],
        );

        return $outcome;
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

        // Seat order does not decide who starts: the scenario does. NextTurn
        // draws the opening hand and activates whoever is to move.
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
