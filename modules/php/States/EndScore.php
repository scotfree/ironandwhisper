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
     * A level score goes to the tie-breaker, which never leaves a draw.
     */
    public function onEnteringState(): GameResult|int
    {
        $this->game->announceResult();

        // The tie-breaker as BGA reads it: player_score_aux, split by
        // tie_breaker_split in gameinfos.jsonc. Only people have a row.
        foreach (array_keys($this->game->sides()) as $playerId) {
            if (!$this->game->isBot($playerId)) {
                $side = $this->game->sideForPlayer($playerId);
                $this->bga->playerScoreAux->set($playerId,
                    \Bga\Games\IronAndWhisper\Rules::tiebreakAux($this->game->tiebreakFor($side)));
            }
        }

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
