<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

class EndDeal extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 95,
            type: StateType::GAME,
            updateGameProgression: true,
        );
    }

    public function onEnteringState(int $activePlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $teams = array_keys($game->getTeams());

        // Cards left on the table go to the last team that captured (or to the team of the last player).
        $left = $cards->getCardsInLocation(Game::LOC_TABLE);
        if (count($left) > 0) {
            $team = (int) $this->bga->globals->get('last_capturer') ?: $game->teamOf($activePlayerId);
            $cards->moveAllCardsInLocation(Game::LOC_TABLE, Game::LOC_CAPTURED, null, $team);
            $this->bga->notify->all('leftoverCaptured', clienttranslate('${team_name} takes the remaining cards on the table'), [
                'team' => $team,
                'team_name' => $game->teamName($team),
                'captured' => $game->cardViews($left),
            ]);
        }

        $scope = $game->getScope();
        $input = [];
        foreach ($teams as $team) {
            $input[$team] = [
                'cards' => $game->cardViews($cards->getCardsInLocation(Game::LOC_CAPTURED, $team)),
                'scope' => $scope[$team],
            ];
        }
        $result = ScopaRules::scoreDeal($input);

        foreach ($teams as $team) {
            $r = $result[$team];
            foreach ($game->playersOfTeam($team) as $playerId) {
                $this->bga->playerScore->inc($playerId, $r['total'], null);
                $this->bga->playerStats->inc('settebello_count', $r['settebello'], $playerId);
                $this->bga->playerStats->inc('primiera_count', $r['primiera'], $playerId);
                $this->bga->playerStats->inc('denari_count', $r['denari'], $playerId);
                $this->bga->playerStats->inc('carte_count', $r['carte'], $playerId);
            }
        }

        $totals = [];
        foreach ($teams as $team) {
            $totals[$team] = $game->teamScore($team);
        }

        foreach ($teams as $team) {
            $r = $result[$team];
            $this->bga->notify->all('teamScored', clienttranslate('${team_name} scores ${points} (scope: ${scope}, settebello: ${settebello}, primiera: ${primiera}, denari: ${denari}, carte: ${carte})'), [
                'team' => $team,
                'team_name' => $game->teamName($team),
                'points' => $r['total'],
                'scope' => $r['scope'],
                'settebello' => $r['settebello'],
                'primiera' => $r['primiera'],
                'denari' => $r['denari'],
                'carte' => $r['carte'],
                'cardCount' => $r['cardCount'],
                'denariCount' => $r['denariCount'],
                'primieraValue' => $r['primieraValue'],
                'total' => $totals[$team],
                'players' => $game->playersOfTeam($team),
            ]);
        }

        if (ScopaRules::gameWinner($totals, $game->targetScore()) !== null) {
            return EndScore::class;
        }
        return NewDeal::class;
    }
}
