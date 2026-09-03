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

        // A bot's turn happens here, inside the between-turns state, and then
        // the loop runs upkeep again for whoever is next. Only a human's turn
        // needs a state of its own, because only a human has to be asked.
        for ($guard = 0; $guard < 100; $guard++) {
            // The board can run out before the deck does.
            if (!Rules::unresolvedTownIds($board->towns())) {
                return $this->endGame(clienttranslate('every town has been resolved'));
            }

            $side = $this->game->toMove();
            $playerId = $this->game->playerIdForSide($side);

            if ($side === Rules::INSURGENCY && !$this->refillHand($playerId)) {
                return $this->endGame(clienttranslate('the deck is exhausted'));
            }

            if (!$this->game->isBot($playerId)) {
                $this->game->giveExtraTime($playerId);
                $this->gamestate->changeActivePlayer($playerId);

                return $side === Rules::INSURGENCY ? InsurgencyTurn::class : EmpireTurn::class;
            }

            $this->game->playBotTurn($side);
        }

        throw new \RuntimeException('bot turns never handed back to a player');
    }

    /**
     * Draw the Insurgency back up to a full hand.
     *
     * Returns false when there is nothing left to draw and nothing in hand:
     * deck exhaustion ends the game and resolves every remaining town at once
     * (Decision 1). Unresolved towns are deferred, never safe.
     */
    private function refillHand(int $insurgencyId): bool
    {
        $board = $this->game->board;

        $hand = $board->hand();
        $missing = $this->game->scenario->handSize - count($hand);
        if ($missing > 0) {
            $hand = $board->drawToHand($missing);
        }
        if (!$hand) {
            return false;
        }

        $this->notify->all('deckCount', '', [
            'deckCount' => $board->deckCount(),
            'handCount' => count($hand),
        ]);

        // Nobody to tell if the Insurgency is the bot, and nowhere to send it.
        if (!$this->game->isBot($insurgencyId)) {
            $this->notify->player($insurgencyId, 'handDrawn', '', ['hand' => $hand]);
        }

        return true;
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
