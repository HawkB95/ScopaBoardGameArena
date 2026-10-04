<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;

class DealHands extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 4,
            type: StateType::GAME,
        );
    }

    public function onEnteringState()
    {
        $game = $this->game;

        if ($game->cards->countCardInLocation(Game::LOC_DECK) === 0) {
            return EndDeal::class;
        }

        $size = $game->handSize();
        foreach ($game->playersFromFirst() as $playerId) {
            $dealt = $game->cards->pickCards($size, Game::LOC_DECK, $playerId);
            $this->bga->notify->player($playerId, 'dealHand', '', [
                'cards' => $game->cardViews($dealt),
            ]);
        }

        $this->bga->notify->all('handsDealt', clienttranslate('Each player receives ${count} cards'), [
            'count' => $size,
            'handCounts' => $game->handCounts(),
            'deckCount' => $game->cards->countCardInLocation(Game::LOC_DECK),
        ]);

        // The player after the dealer leads every hand of the deal.
        $game->gamestate->changeActivePlayer($game->getFirstPlayerId());

        return PlayerTurn::class;
    }
}
