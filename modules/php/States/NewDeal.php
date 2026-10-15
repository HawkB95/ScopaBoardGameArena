<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

/**
 * Stato di gioco 3 - inizio di una nuova smazzata.
 * Cambia il mazziere, azzera i contatori della smazzata, mescola il mazzo e (con 2 giocatori) scopre 4 carte
 * sul tavolo. Poi passa alla distribuzione delle mani.
 */
class NewDeal extends GameState
{
    /** Numero di carte scoperte sul tavolo all'inizio di una smazzata a 2 giocatori. */
    private const INITIAL_TABLE_CARDS_COUNT = 4;

    /**
     * Dichiara lo stato: id 3, stato di gioco (nessuna azione dei giocatori).
     */
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 3,
            type: StateType::GAME,
        );
    }

    /**
     * Viene eseguita all'inizio della smazzata: aggiorna il numero di smazzata e il mazziere,
     * raccoglie e mescola tutte le carte, scopre le carte iniziali sul tavolo (solo con
     * 2 giocatori, rifacendo la distribuzione se ci sono 3 o più Re) e avvisa i client della nuova smazzata
     *
     * @return string lo stato successivo: DealHands
     */
    public function onEnteringState()
    {
        $game = $this->game;
        $globals = $this->bga->globals;

        $dealNumber = (int) $globals->get('deal_no') + 1;
        $globals->set('deal_no', $dealNumber);
        if ($dealNumber > 1) {
            $globals->set('dealer', $game->getPlayerAfter($game->getDealerId()));
        }
        $globals->set('scope', []);
        $globals->set('last_capturer', 0);
        $this->bga->tableStats->inc('deals_played', 1);

        // Riporta tutte le carte nel mazzo e mescola.
        $game->cards->moveAllItemsInLocation(null, Game::LOCATION_DECK);
        $game->cards->shuffle(Game::LOCATION_DECK);

        // Con 4 giocatori il tavolo parte vuoto; con 2 giocatori si scoprono 4 carte (da rifare se ci sono 3+ Re).
        if (!$game->isTeamGame()) {
            do {
                $game->cards->moveAllItemsInLocation(Game::LOCATION_TABLE, Game::LOCATION_DECK);
                $game->cards->shuffle(Game::LOCATION_DECK);
                $game->cards->pickItems(self::INITIAL_TABLE_CARDS_COUNT, Game::LOCATION_DECK, Game::LOCATION_TABLE);
            } while (ScopaRules::needsRedeal($game->tableCards()));
        }

        $dealerId = $game->getDealerId();
        $this->bga->notify->all('newDeal', clienttranslate('Deal ${deal_no}: ${player_name} is the dealer'), [
            'deal_no' => $dealNumber,
            'player_id' => $dealerId,
            'player_name' => $game->getPlayerNameById($dealerId),
            'dealer' => $dealerId,
            'table' => $game->tableCards(),
            'deckCount' => $game->countCardsIn(Game::LOCATION_DECK),
        ]);

        return DealHands::class;
    }
}
