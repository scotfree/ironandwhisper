<?php
/**
 * Every notification the server sends must have a handler on the client.
 *
 * `troopsStarved` did not, for as long as attrition has existed. It carries an
 * authoritative troop count for every town, and nothing read it — so after any
 * starvation the board went on showing troops that were gone, through the
 * rebels' whole turn and the Empire's next resolve phase, until the next
 * `empireMoved` re-synced everything. A player deciding what to resolve in that
 * window was reading a garrison that was not there.
 *
 * Nothing catches this at runtime: BGA logs an unregistered notification to the
 * browser console and carries on, and the notification's *message* still
 * appears in the game log, so the game looks like it is working. The only sign
 * is a board that quietly disagrees with the server.
 *
 * This is a lint rather than a behavioural test — it reads the source of both
 * sides and compares two lists of names. That is the only place the two halves
 * meet, since the client is TypeScript and cannot be exercised from here.
 */
declare(strict_types=1);

use Bga\Games\IronAndWhisper\Game;

/** Notification names the PHP sends, from every `notify->all`/`notify->player`. */
function serverNotifications(): array
{
    $names = [];
    // States/ as well as the top level: the turn states send the clock, the
    // hand refill and the end of the game, and a glob that missed them made
    // this test report three of its own blind spots as dead client code.
    $files = array_merge(
        glob(dirname(__DIR__) . '/modules/php/*.php'),
        glob(dirname(__DIR__) . '/modules/php/States/*.php'),
    );
    foreach ($files as $file) {
        $source = file_get_contents($file);
        // notify->all('name', … ) and notify->player($id, 'name', … ). The name
        // is the first string literal after the opening parenthesis.
        preg_match_all(
            '/notify->(?:all|player)\(\s*(?:\$[A-Za-z_][A-Za-z0-9_]*\s*,\s*)?\'([A-Za-z][A-Za-z0-9_]*)\'/',
            $source,
            $matches,
        );
        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }
    }
    ksort($names);
    return array_keys($names);
}

/** Handler names the client declares, from `notif_<name>` in src/ts. */
function clientHandlers(): array
{
    $names = [];
    foreach (glob(dirname(__DIR__) . '/src/ts/*.ts') as $file) {
        preg_match_all(
            '/notif_([A-Za-z][A-Za-z0-9_]*)\s*\(/',
            file_get_contents($file),
            $matches,
        );
        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }
    }
    ksort($names);
    return array_keys($names);
}

function test_every_notification_has_a_client_handler(): void
{
    $sent = serverNotifications();
    $handled = clientHandlers();

    assertTrue(count($sent) > 0, 'found no notifications in modules/php — the pattern must have rotted');

    $missing = array_diff($sent, $handled);
    assertSame(
        [],
        array_values($missing),
        'notifications the server sends that no notif_ handler on the client reads: '
            . implode(', ', $missing),
    );
}

function test_no_handler_listens_for_a_notification_nobody_sends(): void
{
    // The other direction is not a bug, but it is always either a typo or dead
    // code, and both are worth knowing about.
    $sent = serverNotifications();
    $handled = clientHandlers();

    $orphans = array_diff($handled, $sent);
    assertSame(
        [],
        array_values($orphans),
        'client handlers for notifications the server never sends: ' . implode(', ', $orphans),
    );
}

/** The body of one client handler, from `notif_<name>(` to the end of its method. */
function clientHandlerBody(string $name): string
{
    foreach (glob(dirname(__DIR__) . '/src/ts/*.ts') as $file) {
        if (preg_match('/notif_' . $name . '\s*\(.*?\n    }\n/s', file_get_contents($file), $match)) {
            return $match[0];
        }
    }
    return '';
}

function test_every_notification_that_scores_for_the_bot_moves_its_panel(): void
{
    // A person's score counter moves by itself; the bot's lives in a global the
    // client keeps by hand. `troopsStarved` scored for the bot and forgot to
    // move its panel, so a solo Empire saw the rebels' score stand still when
    // it starved. Play solo games and collect every notification that actually
    // scored for the bot, then require each handler to move the panel.
    $scoring = [];
    foreach ([Game::SIDES_FIRST_IS_EMPIRE, Game::SIDES_FIRST_IS_INSURGENCY] as $sides) {
        for ($seed = 1; $seed <= 6; $seed++) {
            $game = newSoloGame($sides, seed: $seed, botOption: Game::BOT_GLOB);
            for ($guard = 0; $guard < 200; $guard++) {
                if (enterNextTurn($game) === Bga\Games\IronAndWhisper\States\EndScore::class) {
                    break;
                }
                $game->playBotTurn($game->toMove());
            }
            foreach ($game->bga->notify->sent as $sent) {
                if (($sent['args']['player_id'] ?? null) === Game::BOT_PLAYER_ID
                    && ($sent['args']['points'] ?? 0) > 0) {
                    $scoring[$sent['name']] = true;
                }
            }
        }
    }

    assertTrue(isset($scoring['townResolved']), 'no solo game scored a resolution for the bot — the search has rotted');
    foreach (array_keys($scoring) as $name) {
        assertTrue(
            str_contains(clientHandlerBody($name), 'getScoreCounter(this.bot.id)'),
            "notif_{$name} scores for the bot but never moves its score panel",
        );
    }
}
