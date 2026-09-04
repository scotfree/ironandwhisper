<?php
/**
 * Iron and Whisper — persistence.
 *
 * Everything that reads or writes the `iaw_town` and `iaw_card` tables lives here, and
 * nothing else does. Board hands the rest of the game plain arrays in the shape
 * Rules expects, and takes plans back from Rules to write down. Keeping the two
 * apart is what lets the rules be tested without a database.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

final class Board
{
    public const DECK = 'deck';
    public const HAND = 'hand';

    public function __construct(
        private readonly Game $game,
        private readonly Scenario $scenario,
    ) {
    }

    public static function pileLocation(string $townId): string
    {
        return "town:{$townId}";
    }

    // -- setup --------------------------------------------------------------

    /**
     * Create the towns and the shuffled deck, and garrison the Empire's
     * starting towns. Town geography is not stored: only the mutable state is.
     */
    public function setup(): void
    {
        $townValues = [];
        foreach ($this->scenario->towns as $townId => $town) {
            $troops = $this->scenario->empireStart[$townId] ?? 0;
            $townValues[] = sprintf("('%s', %d)", $townId, $troops);
        }
        Game::DbQuery(
            'INSERT INTO `iaw_town` (`town_id`, `troops`) VALUES ' . implode(',', $townValues)
        );

        $types = [];
        foreach ($this->scenario->deck as $typeId => $quantity) {
            for ($i = 0; $i < $quantity; $i++) {
                $types[] = $typeId;
            }
        }
        shuffle($types);

        $cardValues = [];
        foreach ($types as $order => $typeId) {
            $cardValues[] = sprintf("('%s', '%s', %d)", $typeId, self::DECK, $order);
        }
        Game::DbQuery(
            'INSERT INTO `iaw_card` (`card_type`, `card_location`, `location_order`) VALUES '
            . implode(',', $cardValues)
        );
    }

    // -- reading ------------------------------------------------------------

    /**
     * The whole board in the shape Rules works on, keyed by town id and in map
     * order.
     *
     * A town's cards come back in two parts: `pile` is what is still face down,
     * index 0 on top, and `revealed` is what has been turned face up beside it.
     * `empire_seen` is what separates them — turning a card face up is the only
     * thing that sets it, and resolution sets it for everything at once.
     *
     * @return array<string, array>
     */
    public function towns(): array
    {
        $rows = Game::getCollectionFromDB(
            'SELECT `town_id`, `troops`, `resolved`, `winner`, `resolved_influence`, `resolved_strength`
             FROM `iaw_town`'
        );

        $piles = [];
        $revealed = [];
        foreach ($this->pileCards() as $card) {
            if ($card['seen']) {
                $revealed[$card['townId']][] = $card;
            } else {
                $piles[$card['townId']][] = $card;
            }
        }

        $towns = [];
        foreach ($this->scenario->towns as $townId => $definition) {
            $row = $rows[$townId];
            $towns[$townId] = [
                'id' => $townId,
                'label' => $definition['label'],
                'x' => $definition['x'],
                'y' => $definition['y'],
                'neighbors' => $definition['neighbors'],
                'supply' => $definition['supply'],
                'production' => $definition['production'],
                'troops' => (int) $row['troops'],
                'resolved' => (bool) (int) $row['resolved'],
                'winner' => $row['winner'],
                'resolvedInfluence' => (int) $row['resolved_influence'],
                'resolvedStrength' => (int) $row['resolved_strength'],
                'pile' => $piles[$townId] ?? [],
                'revealed' => $revealed[$townId] ?? [],
            ];
        }
        return $towns;
    }

    /**
     * Every card sitting in a town pile, in pile order.
     *
     * @return array<int, array{id: int, type: string, influence: int, seen: bool, townId: string}>
     */
    private function pileCards(): array
    {
        $rows = Game::getObjectListFromDB(
            "SELECT `card_id`, `card_type`, `card_location`, `empire_seen`
             FROM `iaw_card` WHERE `card_location` LIKE 'town:%'
             ORDER BY `card_location`, `location_order`"
        );

        return array_map(fn(array $row) => [
            'id' => (int) $row['card_id'],
            'type' => $row['card_type'],
            'influence' => $this->scenario->influenceOf($row['card_type']),
            'seen' => (bool) (int) $row['empire_seen'],
            'townId' => substr($row['card_location'], strlen('town:')),
        ], $rows);
    }

    /**
     * The Insurgency's hand, in draw order.
     *
     * @return array<int, array{id: int, type: string, influence: int}>
     */
    public function hand(): array
    {
        $rows = Game::getObjectListFromDB(
            "SELECT `card_id`, `card_type` FROM `iaw_card`
             WHERE `card_location` = '" . self::HAND . "' ORDER BY `location_order`"
        );

        return array_map(fn(array $row) => [
            'id' => (int) $row['card_id'],
            'type' => $row['card_type'],
            'influence' => $this->scenario->influenceOf($row['card_type']),
        ], $rows);
    }

    /** @return int[] */
    public function handCardIds(): array
    {
        return array_column($this->hand(), 'id');
    }

    public function deckCount(): int
    {
        return (int) Game::getUniqueValueFromDB(
            "SELECT COUNT(*) FROM `iaw_card` WHERE `card_location` = '" . self::DECK . "'"
        );
    }

    // -- writing ------------------------------------------------------------

    /**
     * Draw up to `$count` cards from the deck into the hand. Returns what was
     * actually drawn, which is short of `$count` only as the deck runs out.
     *
     * @return array<int, array{id: int, type: string, influence: int}>
     */
    public function drawToHand(int $count): array
    {
        if ($count <= 0) {
            return [];
        }

        $ids = Game::getObjectListFromDB(
            "SELECT `card_id` FROM `iaw_card` WHERE `card_location` = '" . self::DECK . "'
             ORDER BY `location_order` LIMIT " . $count,
            true
        );
        if (!$ids) {
            return [];
        }

        $handSize = count($this->handCardIds());
        foreach (array_values($ids) as $offset => $cardId) {
            Game::DbQuery(sprintf(
                "UPDATE `iaw_card` SET `card_location` = '%s', `location_order` = %d WHERE `card_id` = %d",
                self::HAND,
                $handSize + $offset,
                (int) $cardId,
            ));
        }

        return $this->hand();
    }

    /**
     * Put cards on top of a town's pile.
     *
     * They go on one at a time in the order given, so the last card in the list
     * ends up topmost — the same semantics as the simulator's `pile.insert(0, …)`.
     *
     * @param int[] $cardIds
     */
    public function placeOnPile(string $townId, array $cardIds): void
    {
        if (!$cardIds) {
            return;
        }

        $location = self::pileLocation($townId);
        $count = count($cardIds);

        Game::DbQuery(sprintf(
            "UPDATE `iaw_card` SET `location_order` = `location_order` + %d WHERE `card_location` = '%s'",
            $count,
            $location,
        ));

        // Last placed is topmost, so positions run backwards over the list.
        foreach (array_values($cardIds) as $offset => $cardId) {
            Game::DbQuery(sprintf(
                "UPDATE `iaw_card` SET `card_location` = '%s', `location_order` = %d WHERE `card_id` = %d",
                $location,
                $count - 1 - $offset,
                (int) $cardId,
            ));
        }
    }

    /**
     * Turn cards face up. They stay where they are in the town and still count
     * at resolution; they simply leave the face-down pile.
     *
     * @param int[] $cardIds
     */
    public function reveal(array $cardIds): void
    {
        if (!$cardIds) {
            return;
        }
        Game::DbQuery(sprintf(
            'UPDATE `iaw_card` SET `empire_seen` = 1 WHERE `card_id` IN (%s)',
            implode(',', array_map('intval', $cardIds)),
        ));
    }

    /** @param array<string, int> $delta town id => signed change in troops */
    public function adjustTroops(array $delta): void
    {
        foreach ($delta as $townId => $change) {
            if ($change === 0) {
                continue;
            }
            Game::DbQuery(sprintf(
                "UPDATE `iaw_town` SET `troops` = `troops` + (%d) WHERE `town_id` = '%s'",
                $change,
                $townId,
            ));
        }
    }

    /**
     * Take cards off the board. Used when the Empire beats a pile: the loser's
     * commitment is captured and scored, not left lying in the town.
     */
    public function discardCardsIn(string $townId): void
    {
        Game::DbQuery(sprintf(
            "DELETE FROM `iaw_card` WHERE `card_location` = '%s'",
            self::pileLocation($townId),
        ));
    }

    /**
     * Freeze a town after a fight.
     *
     * The loser's commitment is removed and scored; the winner's stays where it
     * is. An Empire that holds a town keeps its garrison, so the town goes on
     * carrying supply and, if it can, building.
     *
     * @param array{winner: string, influence: int, strength: int} $outcome
     */
    public function markResolved(string $townId, array $outcome): void
    {
        // Everything in the town is public from here on, whoever won.
        Game::DbQuery(sprintf(
            "UPDATE `iaw_card` SET `empire_seen` = 1 WHERE `card_location` = '%s'",
            self::pileLocation($townId),
        ));

        // The loser's commitment is taken off the board; the winner's stays.
        $empireWon = $outcome['winner'] === Rules::EMPIRE;
        Game::DbQuery(sprintf(
            "UPDATE `iaw_town` SET `resolved` = 1, `winner` = '%s', `resolved_influence` = %d,
             `resolved_strength` = %d%s WHERE `town_id` = '%s'",
            $outcome['winner'],
            $outcome['influence'],
            $outcome['strength'],
            $empireWon ? '' : ', `troops` = 0',
            $townId,
        ));

        if ($empireWon) {
            $this->discardCardsIn($townId);
        }
    }
}
