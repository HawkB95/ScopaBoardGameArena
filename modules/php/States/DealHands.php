<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;

/**
 * Stato di gioco 4 - distribuzione delle mani.
 * Dà le carte a ogni giocatore (3 a testa con 2 giocatori, 10 a testa con 4 giocatori) e fa iniziare la mano
 * al giocatore dopo il mazziere. Quando il mazzo è finito la smazzata è conclusa.
 */
class DealHands extends GameState
{
    /**
     * Dichiara lo stato: id 4, stato di gioco (nessuna azione dei giocatori).
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
     * Viene eseguita ogni volta che tutte le mani sono vuote. Se anche il mazzo è vuoto la smazzata è finita
     * (EndDeal). Altrimenti ogni giocatore riceve le sue carte (in privato: le vede solo il proprietario),
     * tutti sanno quante carte ha in mano ciascuno e il giocatore dopo il mazziere diventa attivo.
     *
     * @return string lo stato successivo: EndDeal oppure PlayerTurn
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

        // Il giocatore dopo il mazziere apre ogni mano della smazzata.
        $game->gamestate->changeActivePlayer($game->getFirstPlayerId());

        return PlayerTurn::class;
    }
}
