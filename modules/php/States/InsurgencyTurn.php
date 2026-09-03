<?php
/**
 * The Insurgency's turn: seed the whole hand, then optionally resolve.
 *
 * Ported from apply_insurgency_turn() in sim/engine.py.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\IllegalMove;
use Bga\Games\IronAndWhisper\Rules;

class InsurgencyTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must place the whole hand'),
            descriptionMyTurn: clienttranslate('${you} must place your entire hand, and may then resolve one town'),
        );
    }

    public function getArgs(): array
    {
        $towns = $this->game->board->towns();

        return [
            // Every card must be placed (Decision 6), so the only real choice
            // is how to split them across the towns still open.
            'openTowns' => Rules::unresolvedTownIds($towns),
            'resolvable' => Rules::legalResolutions($towns, Rules::INSURGENCY),
        ];
    }

    /**
     * Commit the whole turn at once: the placement is one simultaneous
     * decision, and the optional resolution is judged against the board as it
     * stands after the cards land.
     *
     * @param array<string, int[]> $placements town id => card ids, in the order
     *                                         they go onto the pile (last on top)
     */
    #[PossibleAction]
    public function actCommitTurn(
        #[JsonParam] array $placements,
        ?string $resolve,
        int $activePlayerId,
    ) {
        // The client sends an empty string for "no resolution": BGA action
        // parameters travel as strings, and there is no null on the wire.
        $resolve = $resolve === '' ? null : $resolve;

        $board = $this->game->board;
        $towns = $board->towns();
        $hand = $board->handCardIds();

        try {
            Rules::validatePlacements($towns, $hand, $placements);
        } catch (IllegalMove $e) {
            throw new UserException($e->getMessage());
        }

        $placed = [];
        foreach ($placements as $townId => $cardIds) {
            $cardIds = array_map('intval', $cardIds);
            if (!$cardIds) {
                continue;
            }
            $board->placeOnPile($townId, $cardIds);
            $placed[$townId] = $cardIds;
        }

        // Pile heights are public — that is the whole point of forcing the
        // entire hand out every turn — and so is which card sits where, since
        // the Empire watches the heights change. What the cards *are* is not,
        // so this carries ids and no types. The Insurgency's client already
        // holds its own hand and fills the faces in from that; the Empire's
        // client leaves them face down.
        $this->notify->all('cardsPlaced', clienttranslate('${player_name} seeds ${count} cards'), [
            'player_id' => $activePlayerId,
            'player_name' => $this->game->getPlayerNameById($activePlayerId),
            'count' => count($hand),
            'cards' => $placed,
        ]);

        if ($resolve !== null) {
            // Checked after placement: a town seeded a moment ago is a legal
            // target, even though it was empty at the start of the turn.
            $towns = $board->towns();
            if (!Rules::canDeclare($towns, $resolve, Rules::INSURGENCY)) {
                throw new UserException(clienttranslate('You have no cards in that town, or it is already resolved'));
            }
            $this->game->resolveTown($resolve, Rules::INSURGENCY);
        }

        $this->game->setToMove(Rules::EMPIRE);

        return NextTurn::class;
    }

    /**
     * A zombie Insurgency still has to empty its hand — the deck is the clock,
     * and skipping would stop it. Dumping everything into one open town is the
     * simplest legal turn.
     */
    public function zombie(int $playerId)
    {
        $towns = $this->game->board->towns();
        $open = Rules::unresolvedTownIds($towns);
        $hand = $this->game->board->handCardIds();

        if (!$open || !$hand) {
            $this->game->setToMove(Rules::EMPIRE);
            return NextTurn::class;
        }

        return $this->actCommitTurn(
            [$this->getRandomZombieChoice($open) => $hand],
            null,
            $playerId,
        );
    }
}
