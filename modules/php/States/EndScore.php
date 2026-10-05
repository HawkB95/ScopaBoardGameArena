<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\Games\ProjectBiagio\Game;

/**
 * Stato di gioco 98 - ultimo stato prima della fine della partita.
 * I punteggi sono già scritti alla fine di ogni smazzata, quindi si limita a passare allo stato finale del framework.
 * Lo stato è tenuto separato da EndDeal per avere un punto unico dove aggiungere, in futuro, calcoli o statistiche
 * finali prima che BGA chiuda la partita con lo stato 99.
 */
class EndScore extends \Bga\GameFramework\States\GameState
{
    /** Id dello stato finale del framework: entrarci termina la partita e mostra la classifica. */
    private const ST_END_GAME = 99;

    /**
     * Dichiara lo stato: id 98, stato di gioco (nessuna azione dei giocatori).
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
     * Viene eseguita subito prima della fine della partita; i punteggi finali sono già nei punteggi dei giocatori.
     *
     * @return int lo stato di fine partita del framework
     */
    public function onEnteringState()
    {
        return self::ST_END_GAME;
    }
}
