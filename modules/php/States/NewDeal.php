<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

/**
 * Game state 3 - start of a new deal.
 * Rotates the dealer, resets the deal counters, shuffles the deck and (with 2 players) puts 4 cards face up
 * on the table. Then moves on to dealing the hands.
 */
class NewDeal extends GameState
{
    /** Number of face-up cards put on the table at the start of a 2-player deal. */
    private const INITIAL_TABLE_CARDS_COUNT = 4;

    /**
     * Declares the state: id 3, a game state (no player action).
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
     * Runs when the deal starts: updates the deal number and the dealer (the dealer changes from the second
     * deal on), collects and shuffles all the cards, deals the initial table cards (2 players only, redoing
     * the deal if it contains 3 or more kings) and tells the clients about the new deal.
     *
     * @return string the next state: DealHands
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

        // Bring every card back to the deck and shuffle it.
        $game->cards->moveAllCardsInLocation(null, Game::LOCATION_DECK);
        $game->cards->shuffle(Game::LOCATION_DECK);

        // With 4 players the table starts empty; with 2 players 4 cards go face up (redeal if 3+ kings).
        if (!$game->isTeamGame()) {
            do {
                $game->cards->moveAllCardsInLocation(Game::LOCATION_TABLE, Game::LOCATION_DECK);
                $game->cards->shuffle(Game::LOCATION_DECK);
                $game->cards->pickCardsForLocation(self::INITIAL_TABLE_CARDS_COUNT, Game::LOCATION_DECK, Game::LOCATION_TABLE);
            } while (ScopaRules::needsRedeal($game->tableCards()));
        }

        $dealerId = $game->getDealerId();
        $this->bga->notify->all('newDeal', clienttranslate('Deal ${deal_no}: ${player_name} is the dealer'), [
            'deal_no' => $dealNumber,
            'player_id' => $dealerId,
            'player_name' => $game->getPlayerNameById($dealerId),
            'dealer' => $dealerId,
            'table' => $game->tableCards(),
            'deckCount' => $game->cards->countCardInLocation(Game::LOCATION_DECK),
        ]);

        return DealHands::class;
    }
}
