<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;

/**
 * Stato di gioco 90 - fine del turno di un giocatore.
 * Passa il turno al giocatore successivo oppure, quando tutte le mani sono vuote, distribuisce nuove carte.
 */
class NextPlayer extends GameState
{
    /**
     * Dichiara lo stato: id 90, stato di gioco che aggiorna anche l'avanzamento della partita.
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
     * Viene eseguita dopo ogni carta giocata. Concede un po' di tempo extra al giocatore che ha appena giocato, poi:
     * se tutte le mani sono vuote va a DealHands (che distribuisce di nuovo oppure chiude la smazzata),
     * altrimenti rende attivo il giocatore successivo nell'ordine di tavolo.
     *
     * @param int $activePlayerId il giocatore che ha appena giocato
     * @return string lo stato successivo: DealHands oppure PlayerTurn
     */
    function onEnteringState(int $activePlayerId)
    {
        $this->game->giveExtraTime($activePlayerId);

        if ($this->game->countCardsIn(Game::LOCATION_HAND) === 0) {
            return DealHands::class;
        }

        $this->game->activeNextPlayer();
        return PlayerTurn::class;
    }
}
