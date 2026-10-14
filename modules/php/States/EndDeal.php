<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

/**
 * Stato di gioco 95 - fine di una smazzata.
 * Assegna le carte rimaste sul tavolo all'ultima squadra che ha preso, calcola i punti della smazzata,
 * aggiorna punteggi e statistiche e decide se la partita è finita o se se ne gioca un'altra.
 */
class EndDeal extends GameState
{
    /**
     * Dichiara lo stato: id 95, stato di gioco che aggiorna anche l'avanzamento della partita.
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
     * Viene eseguita quando mazzo e mani sono vuoti:
     *  1. le carte rimaste sul tavolo vanno alla squadra che ha preso per ultima
     *  2. ogni squadra è valutata con ScopaRules::scoreDeal e si aggiornano punteggio e statistiche dei suoi membri;
     *  3. una notifica per squadra mostra il dettaglio dei punti;
     *  4. se una squadra ha raggiunto l'obiettivo ed è strettamente in testa la partita finisce, altrimenti
     *     inizia una nuova smazzata.
     *
     * @param int $activePlayerId il giocatore che ha giocato l'ultima carta della smazzata
     * @return string lo stato successivo: EndScore oppure NewDeal
     */
    public function onEnteringState(int $activePlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;
        $teamIds = array_keys($game->getTeams());

        // Se ci sono carte rimaste sul tavolo, vanno alla squadra che ha preso per ultima
        $cardsLeftOnTable = $cards->getItemsInLocation(Game::LOCATION_TABLE);
        if (count($cardsLeftOnTable) > 0) {
            $lastCapturingTeamId = (int) $this->bga->globals->get('last_capturer');
            $teamReceivingCardsId = $lastCapturingTeamId ?: $game->teamOf($activePlayerId);
            $cards->moveAllItemsInLocation(Game::LOCATION_TABLE, $game->locationOf(Game::LOCATION_CAPTURED, $teamReceivingCardsId));
            $this->bga->notify->all('leftoverCaptured', clienttranslate('${team_name} takes the remaining cards on the table'), [
                'team' => $teamReceivingCardsId,
                'team_name' => $game->teamName($teamReceivingCardsId),
                'captured' => $game->cardViews($cardsLeftOnTable),
            ]);
        }

        // Calcola il punteggio della smazzata per ogni squadra
        $scopeByTeam = $game->getScope();
        $capturesByTeam = [];
        foreach ($teamIds as $teamId) {
            $capturesByTeam[$teamId] = [
                'cards' => $game->cardViews($cards->getItemsInLocation($game->locationOf(Game::LOCATION_CAPTURED, $teamId))),
                'scope' => $scopeByTeam[$teamId],
            ];
        }
        $dealScoreByTeam = ScopaRules::scoreDeal($capturesByTeam);

        // Per ogni squadra: si aggiornano punteggio e statistiche di tutti i membri, si rilegge il totale
        // aggiornato (somma di tutte le smazzate) e si invia il dettaglio ai client. Il totale serve sia alla
        // notifica sia al controllo di fine partita.
        $totalScoreByTeam = [];
        foreach ($teamIds as $teamId) {
            $dealScore = $dealScoreByTeam[$teamId];
            foreach ($game->playersOfTeam($teamId) as $playerId) {
                // null = non inviare la notifica di punteggio predefinita: la aggiorna la notifica 'teamScored'.
                $this->bga->playerScore->inc($playerId, $dealScore['total'], null);
                $this->bga->playerStats->inc('settebello_count', $dealScore['settebello'], $playerId);
                $this->bga->playerStats->inc('primiera_count', $dealScore['primiera'], $playerId);
                $this->bga->playerStats->inc('denari_count', $dealScore['denari'], $playerId);
                $this->bga->playerStats->inc('carte_count', $dealScore['carte'], $playerId);
            }
            $totalScoreByTeam[$teamId] = $game->teamScore($teamId);

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
        // Controlla se la partita è finita
        $winnerTeamId = ScopaRules::gameWinner($totalScoreByTeam, $game->targetScore());

        // Se la partita è finita, passa a EndScore; altrimenti inizia una nuova smazzata
        return $winnerTeamId !== null ? EndScore::class : NewDeal::class;
    }
}
