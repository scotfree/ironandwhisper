<?php
/**
 * The inline icons in the prose, checked from the source.
 *
 * Prose names a piece of the game with a placeholder — `${troop} troops` — and
 * `Help.withIcons` swaps it for the mark. A misspelt placeholder is not an
 * error anywhere: it is printed to the player as the literal text `${trop}`.
 * The manual has the same failure in a different shape, an icon class with no
 * rule behind it, which draws as nothing at all. Like test_notifications, this
 * is a lint over the source of both sides, since the client is TypeScript.
 */
declare(strict_types=1);

/** The names `Help.ts` knows, read from its ICON_NAMES constant. */
function clientIconNames(): array
{
    $source = file_get_contents(dirname(__DIR__) . '/src/ts/Help.ts');
    preg_match('/ICON_NAMES\s*=\s*\[([^\]]*)\]/', $source, $match);
    preg_match_all("/'([a-z]+)'/", $match[1] ?? '', $names);
    return $names[1];
}

function test_every_placeholder_in_the_primers_is_an_icon_or_a_number(): void
{
    $known = array_merge(clientIconNames(), ['hand']);
    assertTrue(count($known) > 1, 'could not read ICON_NAMES from Help.ts');

    foreach (glob(dirname(__DIR__) . '/src/text/*.md') as $file) {
        preg_match_all('/\$\{([A-Za-z]+)\}/', file_get_contents($file), $found);
        foreach ($found[1] as $name) {
            assertTrue(in_array($name, $known, true),
                basename($file) . " uses \${{$name}}, which nothing replaces");
        }
    }
}

function test_every_icon_the_manual_uses_is_styled(): void
{
    $html = file_get_contents(dirname(__DIR__) . '/rules.html');
    preg_match_all('/class="ic ic-([a-z]+)"/', $html, $used);
    assertTrue(count($used[1]) > 0, 'the manual uses no icons at all');

    foreach (array_unique($used[1]) as $name) {
        assertTrue(str_contains($html, ".ic-{$name}"),
            "rules.html uses ic-{$name} and has no rule for it");
    }
}

function test_the_manual_carries_every_drawing_in_img(): void
{
    // tools/sync-rules-art.mjs regenerates these on every build; this catches a
    // new drawing added to img/ and not to the script's list.
    $html = file_get_contents(dirname(__DIR__) . '/rules.html');
    foreach (glob(dirname(__DIR__) . '/img/*.svg') as $file) {
        $name = basename($file, '.svg');
        assertTrue(str_contains($html, "<symbol id=\"art-{$name}\""),
            "img/{$name}.svg is not inlined in rules.html");
    }
}
