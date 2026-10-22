<?php
/**
 * Zombie against zombie, across seeds and options 103/104, with a person
 * resolving (or skipping) every other Resolve phase before the zombie takes
 * the rest of the turn. Run by hand, like selfplay.php:
 *
 *   php tests/zombie_sweep.php
 *
 * Found the dropped-resolution bug in Game::playZombieTurn (2026-10-22).
 */
declare(strict_types=1);
require __DIR__ . '/support/bootstrap.php';
use Bga\Games\IronAndWhisper\{Game, Rules};
use Bga\Games\IronAndWhisper\States\{EndScore, Resolve, InsurgencyTurn, EmpireTurn};
$runs = 0; $errors = []; $rounds = [];
foreach ([[0,0],[1,0],[0,1],[1,1]] as [$lv, $hold]) {
    for ($seed = 1; $seed <= 30; $seed++) {
        try {
            $game = newGame(seed: $seed, locationValue: $lv, rebelsHold: $hold);
            for ($g = 0; $g < 300; $g++) {
                $state = enterNextTurn($game);
                if ($state === EndScore::class) break;
                $p = $game->playerIdForSide($game->toMove());
                match ($state) {
                    Resolve::class => (function () use ($game, $p, $g) {
                        // A person resolves (or skips), then leaves: the zombie takes the turn state.
                        $legal = Rules::legalResolutions($game->board->towns(), $game->toMove());
                        $next = ($g % 2 === 0 && $legal) ? resolvePhase($game)->actResolve($legal[0]) : resolvePhase($game)->actSkipResolve();
                        if ($next === InsurgencyTurn::class) insurgencyTurn($game)->zombie($p);
                        elseif ($next === EmpireTurn::class) empireTurn($game)->zombie($p);
                    })(),
                    InsurgencyTurn::class => insurgencyTurn($game)->zombie($p),
                    EmpireTurn::class => empireTurn($game)->zombie($p),
                };
            }
            if ($state !== EndScore::class) $errors[] = "lv$lv hold$hold seed$seed: did not end";
            $rounds[] = $game->round();
            $runs++;
        } catch (\Throwable $e) {
            $errors[] = "lv$lv hold$hold seed$seed: " . get_class($e) . ': ' . $e->getMessage();
        }
    }
}
printf("%d games, mean %.1f rounds, %d errors\n", $runs, array_sum($rounds) / max(1, count($rounds)), count($errors));
foreach (array_slice($errors, 0, 8) as $e) echo "  $e\n";
