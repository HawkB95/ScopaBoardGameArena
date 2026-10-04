<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;

/**
 * Game state 90 - end of a player's turn.
 * Either passes the turn to the next player or, when every hand is empty, deals new cards.
 */
class NextPlayer extends GameState
{
    /**
     * Declares the state: id 90, a game state that also refreshes the game progression.
     */
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 90,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    /**
     * Runs after each played card. Gives some extra time to the player who just played, then:
     * if all the hands are empty goes to DealHands (which deals again or ends the deal),
     * otherwise makes the next player in table order active.
     *
     * @param int $activePlayerId the player who just played
     * @return string the next state: DealHands or PlayerTurn
     */
    function onEnteringState(int $activePlayerId)
    {
        $this->game->giveExtraTime($activePlayerId);

        if ($this->game->cards->countCardInLocation(Game::LOCATION_HAND) === 0) {
            return DealHands::class;
        }

        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }
}
