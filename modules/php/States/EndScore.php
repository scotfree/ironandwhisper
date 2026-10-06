<?php

declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\GameResult\GameResult;
use Bga\GameFramework\StateType;
use Bga\Games\IronAndWhisper\Game;

const ST_END_GAME = 99;

class EndScore extends \Bga\GameFramework\States\GameState
{

    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 98,
            type: StateType::GAME,
        );
    }

    /**
     * Scores are kept live, so there is nothing to compute — only the result
     * to state.
     *
     * A two-player game ranks itself from `player_score`. A solo game cannot:
     * the bot has no player row, so BGA would see one player and call it a win
     * whatever the bot scored. `GameResult::solo` is BGA's documented way for a
     * solo game to say whether the person won, while keeping their real score.
     * A level game is not a win; there is no tie-breaker yet.
     */
    public function onEnteringState(): GameResult|int
    {
        $this->game->announceResult();

        if (!$this->game->isSolo()) {
            return ST_END_GAME;
        }

        $person = array_values(array_filter(
            array_keys($this->game->sides()),
            fn(int $playerId) => !$this->game->isBot($playerId),
        ))[0];
        $side = $this->game->sideForPlayer($person);

        return GameResult::solo(
            score: $this->game->scoreForSide($side),
            win: $this->game->winningSide() === $side,
        );
    }
}
