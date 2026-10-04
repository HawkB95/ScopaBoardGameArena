<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\Games\ProjectBiagio\Game;

/** Id of the framework's final state: entering it ends the game and shows the ranking. */
const ST_END_GAME = 99;

/**
 * Game state 98 - last state before the end of the game.
 * Scores are already written at the end of each deal, so it only hands over to the framework's end state.
 */
class EndScore extends \Bga\GameFramework\States\GameState
{
    /**
     * Declares the state: id 98, a game state (no player action).
     */
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 98,
            type: StateType::GAME,
        );
    }

    /**
     * Runs just before the end of the game; the final scores are already stored in the players' scores.
     *
     * @return int the framework's end-of-game state
     */
    public function onEnteringState()
    {
        return ST_END_GAME;
    }
}
