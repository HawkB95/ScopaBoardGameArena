<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

/**
 * Game state 95 - end of a deal.
 * Gives the cards left on the table to the last team that captured, scores the deal, updates scores and
 * statistics, and decides whether the game is over or another deal is played.
 */
class EndDeal extends GameState
{
    /**
     * Declares the state: id 95, a game state that also refreshes the game progression.
     */
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 95,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    /**
     * Runs when the deck and all the hands are empty:
     *  1. cards left on the table go to the team that captured last (or, if nobody captured, to the team of
     *     the player who played last);
     *  2. every team is scored with ScopaRules::scoreDeal and each member's score and statistics are updated;
     *  3. one notification per team shows the score breakdown;
     *  4. if a team reached the target and is strictly ahead the game ends, otherwise a new deal starts.
     *
     * @param int $activePlayerId the player who played the last card of the deal
     * @return string the next state: EndScore or NewDeal
     */
    public function onEnteringState(int $activePlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $teamIds = array_keys($game->getTeams());

        $cardsLeftOnTable = $cards->getCardsInLocation(Game::LOCATION_TABLE);
        if (count($cardsLeftOnTable) > 0) {
            $lastCapturingTeamId = (int) $this->bga->globals->get('last_capturer');
            $teamReceivingCardsId = $lastCapturingTeamId ?: $game->teamOf($activePlayerId);
            $cards->moveAllCardsInLocation(Game::LOCATION_TABLE, Game::LOCATION_CAPTURED, null, $teamReceivingCardsId);
            $this->bga->notify->all('leftoverCaptured', clienttranslate('${team_name} takes the remaining cards on the table'), [
                'team' => $teamReceivingCardsId,
                'team_name' => $game->teamName($teamReceivingCardsId),
                'captured' => $game->cardViews($cardsLeftOnTable),
            ]);
        }

        $scopeByTeam = $game->getScope();
        $capturesByTeam = [];
        foreach ($teamIds as $teamId) {
            $capturesByTeam[$teamId] = [
                'cards' => $game->cardViews($cards->getCardsInLocation(Game::LOCATION_CAPTURED, $teamId)),
                'scope' => $scopeByTeam[$teamId],
            ];
        }
        $dealScoreByTeam = ScopaRules::scoreDeal($capturesByTeam);

        foreach ($teamIds as $teamId) {
            $dealScore = $dealScoreByTeam[$teamId];
            foreach ($game->playersOfTeam($teamId) as $playerId) {
                // null = do not send the default score notification: the 'teamScored' one updates the client.
                $this->bga->playerScore->inc($playerId, $dealScore['total'], null);
                $this->bga->playerStats->inc('settebello_count', $dealScore['settebello'], $playerId);
                $this->bga->playerStats->inc('primiera_count', $dealScore['primiera'], $playerId);
                $this->bga->playerStats->inc('denari_count', $dealScore['denari'], $playerId);
                $this->bga->playerStats->inc('carte_count', $dealScore['carte'], $playerId);
            }
        }

        $totalScoreByTeam = [];
        foreach ($teamIds as $teamId) {
            $totalScoreByTeam[$teamId] = $game->teamScore($teamId);
        }

        foreach ($teamIds as $teamId) {
            $dealScore = $dealScoreByTeam[$teamId];
            $this->bga->notify->all('teamScored', clienttranslate('${team_name} scores ${points} (scope: ${scope}, settebello: ${settebello}, primiera: ${primiera}, denari: ${denari}, carte: ${carte})'), [
                'team' => $teamId,
                'team_name' => $game->teamName($teamId),
                'points' => $dealScore['total'],
                'scope' => $dealScore['scope'],
                'settebello' => $dealScore['settebello'],
                'primiera' => $dealScore['primiera'],
                'denari' => $dealScore['denari'],
                'carte' => $dealScore['carte'],
                'cardCount' => $dealScore['cardCount'],
                'denariCount' => $dealScore['denariCount'],
                'primieraValue' => $dealScore['primieraValue'],
                'total' => $totalScoreByTeam[$teamId],
                'players' => $game->playersOfTeam($teamId),
            ]);
        }

        $winnerTeamId = ScopaRules::gameWinner($totalScoreByTeam, $game->targetScore());
        return $winnerTeamId !== null ? EndScore::class : NewDeal::class;
    }
}
