<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */
/**
 * The game's table statistics, recorded as the game happens.
 *
 * Every statistic in stats.jsonc is written from here and nowhere else, at five
 * moments: setup, a resolution, a placement, the end of an Empire turn, and the
 * end of the game. `sim/stats.py` records the same names at the same five
 * moments, which is what makes a number from real games comparable with one
 * from bot-against-bot runs. Change a definition in both, and in stats.jsonc.
 *
 * All table statistics, named by side: the bot has no player row, so a player
 * statistic could not record its side of a solo game.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

class Stats
{
    /** Every statistic, as named in stats.jsonc. tests/test_stats.php checks the two agree. */
    public const INTS = [
        'rounds_played', 'empire_score', 'rebels_score',
        'empire_locations', 'rebels_locations', 'open_locations',
        'rebels_points_battle', 'rebels_points_starvation',
        'troops_built', 'troops_lost_battle', 'troops_starved',
        'agents_placed', 'bluffs_placed',
        'empire_points_locations', 'rebels_points_locations',
        'empire_tie_wins', 'empire_zero_wins', 'rebels_zero_wins',
        'empire_turns', 'empire_garrisons_total', 'largest_garrison',
        'lone_garrisons_lost', 'empire_resolutions', 'rebels_resolutions',
        'first_battle_round', 'cards_looked', 'bluffs_captured',
        'starvation_warnings', 'starvation_losses', 'cards_unplayed',
        'empire_bot', 'rebels_bot',
    ];
    public const FLOATS = [
        'empire_presence_overcome_pct', 'rebel_presence_captured_pct', 'empire_garrisons_mean',
    ];
    public const BOOLS = [
        'ended_by_deck', 'ended_by_board', 'ended_by_elimination', 'ended_by_agreement',
        'location_value', 'rebels_hold_cards',
    ];

    /** How a game ended, as the codes `gameEnded` takes. */
    public const END_DECK = 'deck';
    public const END_BOARD = 'board';
    public const END_ELIMINATION = 'elimination';
    public const END_AGREEMENT = 'agreement';

    public function __construct(
        private \Bga\GameFramework\TableStats $table,
        private Scenario $scenario,
    ) {
    }

    /**
     * @param int $empireBot  0 for a person, else option 101's value + 1
     * @param int $rebelsBot  0 for a person, else option 102's value + 1
     */
    public function setup(int $empireBot, int $rebelsBot, bool $locationValue = false, bool $rebelsHold = false): void
    {
        $this->table->init(self::INTS, 0);
        $this->table->init(self::FLOATS, 0.0);
        $this->table->init(self::BOOLS, false);
        $this->table->set('empire_bot', $empireBot);
        $this->table->set('rebels_bot', $rebelsBot);
        // Only ever set a bool to true: BGA's `set` turns false into '' in SQL
        // and the table fails to create. `init` above has already made it false.
        if ($locationValue) {
            $this->table->set('location_value', true);
        }
        if ($rebelsHold) {
            $this->table->set('rebels_hold_cards', true);
        }
    }

    /**
     * One location resolved, declared or in the end-of-game sweep.
     *
     * @param array<int, array{presence: int}> $cards everything that was in the town
     */
    public function resolution(
        int $round,
        ?string $declaredBy,
        string $winner,
        int $cardPresence,
        int $troopPresence,
        int $points,
        int $troopsLost,
        array $cards,
        int $locationPoints = 0,
    ): void {
        if ($declaredBy === Rules::EMPIRE) {
            $this->table->inc('empire_resolutions', 1);
        } elseif ($declaredBy === Rules::INSURGENCY) {
            $this->table->inc('rebels_resolutions', 1);
        }
        if ($cardPresence > 0 && $troopPresence > 0 && (int) $this->table->get('first_battle_round') === 0) {
            $this->table->set('first_battle_round', $round);
        }

        if ($winner === Rules::EMPIRE) {
            $this->table->inc('empire_locations', 1);
            $this->table->inc('empire_points_locations', $locationPoints);
            if ($points === 0) {
                $this->table->inc('empire_zero_wins', 1);
            }
            if ($cardPresence > 0 && $cardPresence === $troopPresence) {
                $this->table->inc('empire_tie_wins', 1);
            }
            $decoys = count(array_filter($cards, static fn(array $card) => (int) $card['presence'] === 0));
            $this->table->inc('bluffs_captured', $decoys);
            return;
        }

        $this->table->inc('rebels_locations', 1);
        $this->table->inc('rebels_points_locations', $locationPoints);
        if ($points === 0) {
            $this->table->inc('rebels_zero_wins', 1);
        }
        $this->table->inc('rebels_points_battle', $points);
        $this->table->inc('troops_lost_battle', $troopsLost);
        if ($troopsLost === 1) {
            $this->table->inc('lone_garrisons_lost', 1);
        }
    }

    /** @param array<int, array{presence: int}> $cards the cards placed this turn */
    public function placement(array $cards): void
    {
        $this->table->inc('agents_placed', count($cards));
        $this->table->inc('bluffs_placed',
            count(array_filter($cards, static fn(array $card) => (int) $card['presence'] === 0)));
    }

    /**
     * The Empire's turn is over: the board as attrition left it.
     *
     * @param array<string, array> $towns
     */
    public function empireTurn(array $towns, int $built, int $looked, int $starved, bool $underNotice): void
    {
        $this->table->inc('troops_built', $built);
        $this->table->inc('cards_looked', $looked);
        if ($starved > 0) {
            $this->table->inc('troops_starved', $starved);
            $this->table->inc('rebels_points_starvation', $starved * $this->scenario->unitPresence());
            $this->table->inc('starvation_losses', 1);
        }
        if ($underNotice) {
            $this->table->inc('starvation_warnings', 1);
        }

        $garrisons = 0;
        $largest = (int) $this->table->get('largest_garrison');
        foreach ($towns as $town) {
            $troops = (int) $town['troops'];
            if ($troops > 0) {
                $garrisons++;
                $largest = max($largest, $troops);
            }
        }
        $this->table->inc('empire_turns', 1);
        $this->table->inc('empire_garrisons_total', $garrisons);
        $this->table->set('largest_garrison', $largest);
        $this->table->set('empire_garrisons_mean',
            round($this->table->get('empire_garrisons_total') / $this->table->get('empire_turns'), 3));
    }

    /** @param array<string, array> $towns the board after the sweep */
    public function gameEnded(string $reason, int $round, int $empireScore, int $rebelsScore, array $towns, int $cardsUnplayed = 0): void
    {
        $this->table->set('ended_by_' . $reason, true);
        $this->table->set('cards_unplayed', $cardsUnplayed);
        $this->table->set('rounds_played', $round);
        $this->table->set('empire_score', $empireScore);
        $this->table->set('rebels_score', $rebelsScore);
        $this->table->set('open_locations', count(Rules::unresolvedTownIds($towns)));

        // The two shares `sim.run` has always printed, per game rather than
        // over a batch: how much of what could change hands did.
        $troopPresence = intdiv($this->scenario->mapSupply(), $this->scenario->supplyPerTroop)
            * $this->scenario->unitPresence();
        $this->table->set('empire_presence_overcome_pct',
            $troopPresence > 0 ? round(100 * $rebelsScore / $troopPresence, 2) : 0.0);
        $cardPresence = $this->scenario->totalCardPresence();
        $this->table->set('rebel_presence_captured_pct',
            $cardPresence > 0 ? round(100 * $empireScore / $cardPresence, 2) : 0.0);
    }
}
