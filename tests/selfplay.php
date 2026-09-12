<?php
/**
 * Bot self-play, for checking the PHP against the simulator statistically.
 *
 *   php tests/selfplay.php 200
 *   php tests/selfplay.php 200 heuristic
 *
 * The unit tests prove the PHP does what it was written to do. This asks a
 * different question: does it do what the *specification* does? Run the same
 * heuristics over the same scenario in both, and the win rates should land in
 * the same place. If the PHP drifts away from sim/engine.py in some way no unit
 * test happens to cover, this is what notices.
 *
 * Compare against:  sim/.venv/bin/python -m sim.run --games 500 --bots glob
 *
 * Different random number generators mean comparing distributions rather than
 * games, and a few hundred games only pins a rate to a couple of points — so
 * this catches real drift, not subtle drift.
 */
declare(strict_types=1);

require_once __DIR__ . '/support/bootstrap.php';

use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\States\EndScore;

$games = (int) ($argv[1] ?? 100);
$botName = (string) ($argv[2] ?? 'glob');
$bot = $botName === 'heuristic' ? Game::BOT_HEURISTIC : Game::BOT_GLOB;

$wins = [Rules::EMPIRE => 0, Rules::INSURGENCY => 0, 'draw' => 0];
$towns = [Rules::EMPIRE => 0, Rules::INSURGENCY => 0];
$rounds = 0;
$scores = [Rules::EMPIRE => 0, Rules::INSURGENCY => 0];
$started = microtime(true);

for ($seed = 1; $seed <= $games; $seed++) {
    $game = newGame(Game::SIDES_FIRST_IS_EMPIRE, seed: $seed, botOption: $bot);

    for ($guard = 0; $guard < 200; $guard++) {
        if (enterNextTurn($game) === EndScore::class) {
            break;
        }
        $game->playBotTurn($game->toMove());
    }

    $rounds += $game->round();
    foreach ($game->board->towns() as $town) {
        if ($town['winner'] !== null) {
            $towns[$town['winner']]++;
        }
    }

    $empire = $game->bga->playerScore->get($game->playerIdForSide(Rules::EMPIRE));
    $insurgency = $game->bga->playerScore->get($game->playerIdForSide(Rules::INSURGENCY));

    $scores[Rules::EMPIRE] += $empire;
    $scores[Rules::INSURGENCY] += $insurgency;

    if ($empire > $insurgency) {
        $wins[Rules::EMPIRE]++;
    } elseif ($insurgency > $empire) {
        $wins[Rules::INSURGENCY]++;
    } else {
        $wins['draw']++;
    }

    if ($seed % 25 === 0) {
        fwrite(STDERR, ".");
    }
}

fwrite(STDERR, "\n");

printf("bots: Empire %s vs Insurgency heuristic (PHP)\n", $botName);
printf("  games             %d\n", $games);
printf("  Empire wins       %4d  (%.1f%%)\n", $wins[Rules::EMPIRE], 100 * $wins[Rules::EMPIRE] / $games);
printf("  Insurgency wins   %4d  (%.1f%%)\n", $wins[Rules::INSURGENCY], 100 * $wins[Rules::INSURGENCY] / $games);
printf("  draws             %4d  (%.1f%%)\n", $wins['draw'], 100 * $wins['draw'] / $games);
printf("\n");
printf("  Empire score      mean %.3f\n", $scores[Rules::EMPIRE] / $games);
printf("  Insurgency score  mean %.3f\n", $scores[Rules::INSURGENCY] / $games);
printf("\n");
printf("  rounds            mean %.2f\n", $rounds / $games);
printf("  towns to Empire   mean %.2f\n", $towns[Rules::EMPIRE] / $games);
printf("  towns to rebels   mean %.2f\n", $towns[Rules::INSURGENCY] / $games);
printf("\n  %.1fs\n", microtime(true) - $started);
