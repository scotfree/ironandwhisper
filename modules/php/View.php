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
 *   full, face down or not.
 *
 *   Everyone sees the face-up cards beside a town, and the height of the
 *   face-down pile. Nobody but the Insurgency sees what is still face down.
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
     * @param array<int, array> $deck        the deck, in draw order
     * @param bool $deckArrangementOpen      whether the deck is still the Rebels'
     *                                       to arrange (advanced rules, issue #6)
     * @param array<int, string> $sides      player id => side
     * @param string|null $viewerSide        null for a spectator
     * @return array<string, mixed>
     */
    public static function forSide(
        Scenario $scenario,
        array $towns,
        array $hand,
        array $deck,
        bool $deckArrangementOpen,
        int $deckCount,
        int $round,
        array $sides,
        ?string $viewerSide,
    ): array {
        return [
            // Who the recipient is, decided here rather than left for the
            // client to work out from a player id. The client must never
            // re-derive entitlement: it can only draw what it was sent.
            'you' => $viewerSide,
            'sides' => $sides,
            'scenario' => self::scenarioView($scenario),
            'towns' => self::townsView($towns, $viewerSide),
            // Hand contents are the Insurgency's alone. Its size is public and
            // always known anyway: the whole hand is placed every turn, so it
            // refills to hand_size until the deck runs dry.
            'hand' => $viewerSide === Rules::INSURGENCY ? array_values($hand) : null,
            // The deck itself is never public, and not even the Insurgency's to
            // see once arrangement has closed — a draw afterwards is a surprise
            // exactly as it always was. The Empire gets null either way: it
            // never sees the deck, arranged or not.
            'deck' => ($viewerSide === Rules::INSURGENCY && $deckArrangementOpen)
                ? array_values($deck) : null,
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
                // Public: the networks are computed from the board by anyone
                // who can see it, so what a network cannot feed is public too.
                // The Insurgency being able to see that the Empire has
                // overextended is the tell that makes waiting it out a choice.
                'starving' => $town['starving'],
                'resolved' => $town['resolved'],
                'winner' => $town['winner'],
                'resolvedCardPresence' => $town['resolvedCardPresence'],
                'resolvedTroopPresence' => $town['resolvedTroopPresence'],
                'pileSize' => count($town['pile']),
                'cardCount' => Rules::townCardCount($town),
                'pile' => self::pileView($town, $viewerSide),
                'revealed' => self::cards($town['revealed']),
            ];
        }
        return $view;
    }

    /**
     * The face-down pile as the given side sees it, index 0 on top.
     *
     * Only the Insurgency sees faces here. For everyone else an entry carries
     * its card id and a null type: enough to draw a face-down card in the right
     * place, and nothing more. Card ids say nothing about what a card is, and
     * the Empire needs them to match a card it turns over to one on screen.
     *
     * @param array{pile: array} $town
     */
    public static function pileView(array $town, ?string $viewerSide): array
    {
        if ($viewerSide === Rules::INSURGENCY) {
            return self::cards($town['pile']);
        }

        return array_map(
            static fn(array $card) => ['id' => $card['id'], 'type' => null, 'presence' => null],
            array_values($town['pile']),
        );
    }

    /** @param array<int, array> $cards */
    private static function cards(array $cards): array
    {
        return array_map(
            static fn(array $card) => [
                'id' => $card['id'],
                'type' => $card['type'],
                'presence' => $card['presence'],
            ],
            array_values($cards),
        );
    }

    /**
     * Static configuration the client needs to draw the board. Public to both
     * sides: the map, the unit, and the deck composition are all common
     * knowledge — the Empire's whole estimate of hidden presence depends on
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
                // Static, public, and enough for the client to work out the
                // Empire's networks itself rather than have them synced.
                'supply' => $town['supply'],
                'production' => $town['production'],
                'value' => $town['value'],
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
            'supplyPerTroop' => $scenario->supplyPerTroop,
            'productionCost' => $scenario->productionCost,
            'empireWinsTies' => $scenario->empireWinsTies,
            'turns' => $scenario->turns(),
        ];
    }
}
