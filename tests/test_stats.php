<?php
/**
 * The table statistics: stats.jsonc, the PHP recorder, and whole games.
 *
 * stats.jsonc is the list, Stats.php records it here and sim/stats.py records
 * it in the simulator. This pins the PHP half: every name the recorder knows is
 * defined with the right type, ids are unique, and whole games satisfy the
 * identities the definitions imply — the same ones sim/test_stats.py checks.
 * The framework stub throws on a statistic that is undefined or never
 * initialised, so every other test is also a check that nothing misspells one.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\Stats;
use Bga\Games\IronAndWhisper\States\EndScore;

function statsDefinitions(): array
{
    $json = preg_replace('#^\s*//.*$#m', '', (string) file_get_contents(dirname(__DIR__) . '/stats.jsonc'));
    return json_decode((string) $json, true, flags: JSON_THROW_ON_ERROR)['table'];
}

function test_the_recorder_and_stats_jsonc_name_the_same_statistics(): void
{
    $defined = statsDefinitions();
    $recorded = array_merge(
        array_fill_keys(Stats::INTS, 'int'),
        array_fill_keys(Stats::FLOATS, 'float'),
        array_fill_keys(Stats::BOOLS, 'bool'),
    );
    ksort($recorded);
    $types = array_map(static fn(array $stat) => $stat['type'], $defined);
    ksort($types);

    assertSame($types, $recorded);
}

function test_every_statistic_has_its_own_id(): void
{
    $ids = array_column(statsDefinitions(), 'id');
    assertSame(count($ids), count(array_unique($ids)));
}

function test_whole_games_satisfy_the_identities_the_definitions_imply(): void
{
    foreach ([Game::BOT_MONOLITH, Game::BOT_GLOB] as $bot) {
        for ($seed = 1; $seed <= 5; $seed++) {
            $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: $seed, botOption: $bot, rebelOption: Game::REBEL_MIST2);
            for ($guard = 0; $guard < 200; $guard++) {
                if (enterNextTurn($game) === EndScore::class) {
                    break;
                }
                $game->playBotTurn($game->toMove());
            }
            $s = $game->bga->tableStats->values;
            $label = "bot {$bot} seed {$seed}";

            assertSame($game->scoreForSide(Rules::EMPIRE), $s['empire_score'], $label);
            assertSame($game->scoreForSide(Rules::INSURGENCY), $s['rebels_score'], $label);
            assertSame($s['rebels_score'], $s['rebels_points_battle'] + $s['rebels_points_starvation'], $label);
            assertSame(count($game->board->towns()),
                $s['empire_locations'] + $s['rebels_locations'] + $s['open_locations'], $label);
            assertSame(1, (int) $s['ended_by_deck'] + (int) $s['ended_by_board']
                + (int) $s['ended_by_elimination'] + (int) $s['ended_by_agreement'], $label);
            assertTrue($s['bluffs_placed'] <= $s['agents_placed'], $label);
            assertTrue($s['starvation_losses'] <= $s['starvation_warnings'], $label);
            assertSame($game->round(), $s['rounds_played'], $label);
        }
    }
}

function test_a_solo_game_records_which_bot_played(): void
{
    $game = newSoloGame(Game::SIDES_FIRST_IS_INSURGENCY, botOption: Game::BOT_MONOLITH);
    assertSame(Game::BOT_MONOLITH + 1, $game->bga->tableStats->values['empire_bot']);
    assertSame(0, $game->bga->tableStats->values['rebels_bot'], 'a person plays the rebels');
}
