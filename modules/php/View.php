<?php
/**
 * Iron and Whisper — the hidden-information boundary.
 *
 * This is the ONE place that decides what a player is allowed to see. Every
 * payload sent to a client — getAllDatas and any notification carrying pile
 * contents — is built here, so there is a single function to get right and a
 * single function to test.
 *
 * The asymmetry is unusual and easy to get backwards:
 *
 *   The Insurgency placed every card, so it legitimately sees every pile in
 *   full. Peeking rotates piles, but rotation is determined by troop positions,
 *   which are public, so showing true order leaks nothing.
 *
 *   The Empire sees pile *heights*, the cards it has peeked at, and resolved
 *   piles, which are face up to everyone. It also sees where unknown cards sit
 *   in a pile, which it could derive anyway: placements change visible heights
 *   and rotations are its own doing.
 *
 * sim/bots.py::EmpireBelief is the reference for exactly what the Empire is
 * entitled to know — it was written to read only Empire-legal information.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

final class View
{
    /**
     * @param array<string, array> $towns    board in Rules shape
     * @param array<int, array> $hand        the Insurgency's hand
     * @param array<int, string> $sides      player id => side
     * @param string|null $viewerSide        null for a spectator
     * @return array<string, mixed>
     */
    public static function forSide(
        Scenario $scenario,
        array $towns,
        array $hand,
        int $deckCount,
        int $round,
        array $sides,
        ?string $viewerSide,
    ): array {
        return [
            'sides' => $sides,
            'scenario' => self::scenarioView($scenario),
            'towns' => self::townsView($towns, $viewerSide),
            // Hand contents are the Insurgency's alone. Its size is public and
            // always known anyway: the whole hand is placed every turn, so it
            // refills to hand_size until the deck runs dry.
            'hand' => $viewerSide === Rules::INSURGENCY ? array_values($hand) : null,
            'handCount' => count($hand),
            'deckCount' => $deckCount,
            'round' => $round,
        ];
    }

    /**
     * One town as the given side sees it.
     *
     * @param array<string, array> $towns
     * @return array<string, array>
     */
    public static function townsView(array $towns, ?string $viewerSide): array
    {
        $view = [];
        foreach ($towns as $townId => $town) {
            $view[$townId] = [
                'id' => $townId,
                'troops' => $town['troops'],
                'resolved' => $town['resolved'],
                'winner' => $town['winner'],
                'resolvedInfluence' => $town['resolvedInfluence'],
                'resolvedStrength' => $town['resolvedStrength'],
                'pileSize' => count($town['pile']),
                'pile' => self::pileView($town, $viewerSide),
            ];
        }
        return $view;
    }

    /**
     * A pile as the given side sees it, in true order with index 0 on top.
     *
     * An entry the viewer may not identify carries its card id and a null type:
     * enough to draw a face-down card in the right place, and nothing more.
     *
     * @param array{resolved: bool, pile: array} $town
     */
    public static function pileView(array $town, ?string $viewerSide): array
    {
        $revealAll = $viewerSide === Rules::INSURGENCY || $town['resolved'];

        $pile = [];
        foreach ($town['pile'] as $card) {
            // Peeked cards are the Empire's own knowledge, not public record.
            $known = $revealAll
                || ($viewerSide === Rules::EMPIRE && ($card['seen'] ?? false));
            $pile[] = $known
                ? ['id' => $card['id'], 'type' => $card['type'], 'influence' => $card['influence']]
                : ['id' => $card['id'], 'type' => null, 'influence' => null];
        }
        return $pile;
    }

    /**
     * Static configuration the client needs to draw the board. Public to both
     * sides: the map, the unit, and the deck composition are all common
     * knowledge — the Empire's whole estimate of hidden influence depends on
     * knowing what is in the deck.
     *
     * @return array<string, mixed>
     */
    public static function scenarioView(Scenario $scenario): array
    {
        $towns = [];
        foreach ($scenario->towns as $townId => $town) {
            $towns[$townId] = [
                'id' => $townId,
                'label' => $town['label'],
                'x' => $town['x'],
                'y' => $town['y'],
                'neighbors' => $town['neighbors'],
            ];
        }

        return [
            'id' => $scenario->id,
            'label' => $scenario->label,
            'towns' => $towns,
            'edges' => $scenario->edges,
            'unit' => $scenario->unit,
            'cardTypes' => $scenario->cardTypes,
            'deck' => $scenario->deck,
            'handSize' => $scenario->handSize,
            'generationRate' => $scenario->generationRate,
            'empireWinsTies' => $scenario->empireWinsTies,
            'turns' => $scenario->turns(),
        ];
    }
}
