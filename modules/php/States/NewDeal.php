<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

class NewDeal extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 3,
            type: StateType::GAME,
        );
    }

    public function onEnteringState()
    {
        $game = $this->game;
        $globals = $this->bga->globals;

        $dealNo = (int) $globals->get('deal_no') + 1;
        $globals->set('deal_no', $dealNo);
        if ($dealNo > 1) {
            $globals->set('dealer', $game->getPlayerAfter($game->getDealerId()));
        }
        $globals->set('scope', []);
        $globals->set('last_capturer', 0);
        $this->bga->tableStats->inc('deals_played', 1);

        // Collect every card and shuffle. With two players, 4 cards go face up (redeal if 3+ kings).
        $game->cards->moveAllCardsInLocation(null, Game::LOC_DECK);
        $game->cards->shuffle(Game::LOC_DECK);

        if (!$game->isTeamGame()) {
            do {
                $game->cards->moveAllCardsInLocation(Game::LOC_TABLE, Game::LOC_DECK);
                $game->cards->shuffle(Game::LOC_DECK);
                $game->cards->pickCardsForLocation(4, Game::LOC_DECK, Game::LOC_TABLE);
            } while (ScopaRules::needsRedeal($game->tableCards()));
        }

        $dealerId = $game->getDealerId();
        $this->bga->notify->all('newDeal', clienttranslate('Deal ${deal_no}: ${player_name} is the dealer'), [
            'deal_no' => $dealNo,
            'player_id' => $dealerId,
            'player_name' => $game->getPlayerNameById($dealerId),
            'dealer' => $dealerId,
            'table' => $game->tableCards(),
            'deckCount' => $game->cards->countCardInLocation(Game::LOC_DECK),
        ]);

        return DealHands::class;
    }
}
