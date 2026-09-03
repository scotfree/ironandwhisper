<?php
/**
 * Test bootstrap: the framework stub, the game under test, and the helpers for
 * driving a game without a BGA server.
 */
declare(strict_types=1);

require_once __DIR__ . '/framework.php';

$root = dirname(__DIR__, 2);
require_once $root . '/modules/php/IllegalMove.php';
require_once $root . '/modules/php/Scenario.php';
require_once $root . '/modules/php/Rules.php';
require_once $root . '/modules/php/Board.php';
require_once $root . '/modules/php/View.php';
require_once $root . '/modules/php/Game.php';
require_once $root . '/modules/php/States/EndScore.php';
require_once $root . '/modules/php/States/NextTurn.php';
require_once $root . '/modules/php/States/InsurgencyTurn.php';
require_once $root . '/modules/php/States/EmpireTurn.php';

use Bga\GameFramework\Db;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;
use Bga\Games\IronAndWhisper\States\EmpireTurn;
use Bga\Games\IronAndWhisper\States\InsurgencyTurn;
use Bga\Games\IronAndWhisper\States\NextTurn;

const P_ONE = 2345001;
const P_TWO = 2345002;

/**
 * A fresh game with a fresh database.
 *
 * The seed makes the deck shuffle reproducible, so a failing test fails the
 * same way twice.
 */
function newGame(int $sideOption = Game::SIDES_FIRST_IS_EMPIRE, int $seed = 1): Game
{
    Db::reset();
    Db::loadSchema(dirname(__DIR__, 2) . '/dbmodel.sql');
    mt_srand($seed);

    $game = new Game();
    $game->bga->tableOptions->values[Game::OPT_SIDE_ASSIGNMENT] = $sideOption;

    $players = [
        P_ONE => ['player_name' => 'One'],
        P_TWO => ['player_name' => 'Two'],
    ];

    $setup = new ReflectionMethod($game, 'setupNewGame');
    $setup->setAccessible(true);
    $setup->invoke($game, $players, []);

    return $game;
}

/** getAllDatas as one player sees it. */
function datasFor(Game $game, int $playerId): array
{
    $method = new ReflectionMethod($game, 'getAllDatas');
    $method->setAccessible(true);
    return $method->invoke($game, $playerId);
}

/** Run the between-turns state; returns the class of the state it hands off to. */
function enterNextTurn(Game $game): string
{
    return (new NextTurn($game))->onEnteringState();
}

function insurgencyTurn(Game $game): InsurgencyTurn
{
    return new InsurgencyTurn($game);
}

function empireTurn(Game $game): EmpireTurn
{
    return new EmpireTurn($game);
}

function playerFor(Game $game, string $side): int
{
    return $game->playerIdForSide($side);
}

// -- assertions -------------------------------------------------------------

final class AssertionFailed extends \RuntimeException
{
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new AssertionFailed(sprintf(
            "%s\n  expected: %s\n  actual:   %s",
            $message ?: 'values differ',
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function assertTrue(bool $condition, string $message = ''): void
{
    if (!$condition) {
        throw new AssertionFailed($message ?: 'expected true');
    }
}

function assertFalse(bool $condition, string $message = ''): void
{
    assertTrue(!$condition, $message ?: 'expected false');
}

/** Assert that $fn throws $class, and return the exception for inspection. */
function assertThrows(string $class, callable $fn, string $message = ''): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $class)) {
            throw new AssertionFailed(sprintf(
                "%s\n  expected %s, got %s: %s",
                $message ?: 'wrong exception',
                $class,
                get_class($e),
                $e->getMessage(),
            ));
        }
        return $e;
    }
    throw new AssertionFailed($message ?: "expected {$class}, nothing thrown");
}
