<?php
/**
 * The Empire's turn: raise a troop, move, look, then optionally resolve.
 *
 * Ported from apply_empire_turn() in sim/engine.py. The order matters — a
 * resolution is judged against where troops *will be*, not where they were at
 * the start of the turn (Decision 4).
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper\States;

use Bga\GameFramework\Actions\Types\JsonParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\Games\IronAndWhisper\Game;
use Bga\Games\IronAndWhisper\Rules;

class EmpireTurn extends GameState
{
    public function __construct(
        protected Game $game,
    ) {
        parent::__construct(
            $game,
            id: 11,
            type: StateType::ACTIVE_PLAYER,
            description: clienttranslate('${actplayer} must move'),
            descriptionMyTurn: clienttranslate('${you} may raise a troop and move, and may then resolve one town'),
        );
    }

    public function getArgs(): array
    {
        $towns = $this->game->board->towns();

        return [
            'production' => self::productionOffer($towns, $this->game->scenario),
            'networks' => self::networkView($towns, $this->game->scenario),
            'resolvable' => Rules::legalResolutions($towns, Rules::EMPIRE),
        ];
    }

    /**
     * Commit the whole turn at once.
     *
     * The work is in Game::applyEmpireTurn, so that a bot takes its turn
     * through the same code and the same validation as a person.
     *
     * @param array<int, array{from: string, to: string, count: int}> $moves
     */
    #[PossibleAction]
    public function actCommitTurn(
        #[JsonParam] array $produce,
        #[JsonParam] array $moves,
        ?string $resolve,
        #[JsonParam] array $disband,
        int $activePlayerId,
    ) {
        $this->game->applyEmpireTurn($produce, $moves, $resolve, $disband, $activePlayerId);

        return NextTurn::class;
    }

    /**
     * How many troops each town could build this turn, ceiling included.
     *
     * @param array<string, array> $towns
     * @return array<string, int>
     */
    private static function productionOffer(array $towns, \Bga\Games\IronAndWhisper\Scenario $scenario): array
    {
        $offer = [];
        $spare = [];

        foreach (Rules::productionSites($towns, $scenario->productionCost) as $site) {
            $key = implode(',', Rules::componentOf($towns, $site));
            if (!isset($spare[$key])) {
                $spare[$key] = Rules::headroom($towns, $site, $scenario->supplyPerTroop);
            }
            $offer[$site] = min(
                Rules::productionCapacity($towns, $site, $scenario->productionCost),
                $spare[$key],
            );
        }

        return $offer;
    }

    /**
     * The Empire's networks, with the ceiling and load of each, so the client
     * can colour the roads and show a town's supply against its network total.
     *
     * @param array<string, array> $towns
     * @return array<int, array{towns: string[], ceiling: int, troops: int}>
     */
    private static function networkView(array $towns, \Bga\Games\IronAndWhisper\Scenario $scenario): array
    {
        return array_map(
            fn(array $component) => [
                'towns' => $component,
                'ceiling' => Rules::ceiling($towns, $component, $scenario->supplyPerTroop),
                'troops' => Rules::troopsIn($towns, $component),
            ],
            Rules::components($towns),
        );
    }

    /**
     * A zombie Empire simply stands still. Standing still is always legal, and
     * the stationary troops still look — that costs the absent player nothing
     * and keeps the clock running.
     */
    public function zombie(int $playerId)
    {
        return $this->actCommitTurn([], [], null, [], $playerId);
    }
}
