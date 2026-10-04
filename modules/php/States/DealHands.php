<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;

/**
 * Game state 4 - dealing the hands.
 * Gives cards to every player (3 each with 2 players, 10 each with 4 players) and starts the hand with the
 * player after the dealer. When the deck is empty the deal is over.
 */
class DealHands extends GameState
{
    /**
     * Declares the state: id 4, a game state (no player action).
     */
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 4,
            type: StateType::GAME,
        );
    }

    /**
     * Runs every time all the hands are empty. If the deck is empty too, the deal is finished (EndDeal).
     * Otherwise each player receives their cards (privately: only the owner sees them), everybody is told how
     * many cards each player holds, and the player after the dealer becomes the active player.
     *
     * @return string the next state: EndDeal or PlayerTurn
     */
    public function onEnteringState()
    {
        $game = $this->game;

        if ($game->cards->countCardInLocation(Game::LOCATION_DECK) === 0) {
            return EndDeal::class;
        }

        $cardsPerPlayer = $game->handSize();
        foreach ($game->playersFromFirst() as $playerId) {
            $dealtCards = $game->cards->pickCards($cardsPerPlayer, Game::LOCATION_DECK, $playerId);
            $this->bga->notify->player($playerId, 'dealHand', '', [
                'cards' => $game->cardViews($dealtCards),
            ]);
        }

        $this->bga->notify->all('handsDealt', clienttranslate('Each player receives ${count} cards'), [
            'count' => $cardsPerPlayer,
            'handCounts' => $game->handCounts(),
            'deckCount' => $game->cards->countCardInLocation(Game::LOCATION_DECK),
        ]);

        // The player after the dealer leads every hand of the deal.
        $game->gamestate->changeActivePlayer($game->getFirstPlayerId());

        return PlayerTurn::class;
    }
}
