<?php
/**
 * Between turns: upkeep, end-of-game detection, and handing the board to the
 * other side.
 *
 * Ported from prepare_turn() in sim/engine.py. Everything here happens before a
 * player is asked for anything, which is why the hand refill and the end of the
 * game both live in this state rather than at the end of a turn.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;

class NextTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 90,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState()
    {
        $board = $this->game->board;

        // The board can run out before the deck does.
        if (!Rules::unresolvedTownIds($board->towns())) {
            return $this->endGame(clienttranslate('every town has been resolved'));
        }

        $side = $this->game->toMove();

        if ($side === Rules::INSURGENCY) {
            $hand = $board->hand();
            $missing = $this->game->scenario->handSize - count($hand);
            if ($missing > 0) {
                $hand = $board->drawToHand($missing);
            }

            // Deck exhausted and nothing left to place: the game ends and every
            // remaining town resolves at once (Decision 1). Unresolved towns are
            // deferred, never safe.
            if (!$hand) {
                return $this->endGame(clienttranslate('the deck is exhausted'));
            }

            $insurgencyId = $this->game->playerIdForSide(Rules::INSURGENCY);
            $this->notify->all('deckCount', '', [
                'deckCount' => $board->deckCount(),
                'handCount' => count($hand),
            ]);
            $this->notify->player($insurgencyId, 'handDrawn', '', [
                'hand' => $hand,
            ]);
        }

        $playerId = $this->game->playerIdForSide($side);
        $this->game->giveExtraTime($playerId);
        $this->gamestate->changeActivePlayer($playerId);

        return $side === Rules::INSURGENCY ? InsurgencyTurn::class : EmpireTurn::class;
    }

    /**
     * Resolve everything still standing, all at once, and stop.
     */
    private function endGame(string $reason)
    {
        $this->notify->all('gameEnding', clienttranslate('The game ends: ${reason}'), [
            'reason' => $reason,
            'i18n' => ['reason'],
        ]);

        foreach (Rules::unresolvedTownIds($this->game->board->towns()) as $townId) {
            $this->game->resolveTown($townId, null);
        }

        return EndScore::class;
    }
}
