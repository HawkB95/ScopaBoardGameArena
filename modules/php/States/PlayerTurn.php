<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\States;

use Bga\GameFramework\Actions\Types\IntArrayParam;
use Bga\GameFramework\StateType;
use Bga\GameFramework\States\GameState;
use Bga\GameFramework\States\PossibleAction;
use Bga\GameFramework\UserException;
use Bga\Games\ProjectBiagio\Game;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;

/**
 * Stato di gioco 10 - il giocatore attivo gioca una carta dalla sua mano (non si può passare).
 * Se esiste una presa il giocatore deve prendere; altrimenti la carta viene posata sul tavolo.
 */
class PlayerTurn extends GameState
{
    /**
     * Dichiara lo stato: id 10, giocato dal singolo giocatore attivo.
     */
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    /**
     * Costruisce gli argomenti dello stato inviati al client. Le prese lecite di ogni carta in mano sono nella
     * parte privata, così le riceve solo il giocatore attivo (la mano è un'informazione nascosta).
     *
     * @return array{_private: array<int, array{captures: array<int, int[][]>}>}
     */
    public function getArgs(): array
    {
        $activePlayerId = (int) $this->game->getActivePlayerId();
        return [
            '_private' => [
                $activePlayerId => [
                    'captures' => $this->game->capturesByCard($activePlayerId),
                ],
            ],
        ];
    }

    /**
     * Azione: gioca una carta dalla mano e prende le carte del tavolo indicate.
     * Un elenco di prese vuoto significa "posa la carta sul tavolo" ed è accettato solo se non esiste nessuna presa.
     * La mossa è validata sul server con ScopaRules; poi le carte si spostano (nella pila delle prese della
     * squadra oppure sul tavolo), si conta una scopa se il tavolo è stato svuotato (tranne nell'ultima giocata
     * della smazzata), si aggiornano le statistiche e si avvisano tutti.
     *
     * @param int   $card_id        id della carta in mano che si gioca
     * @param int   $activePlayerId compilato automaticamente dal framework con il giocatore che agisce
     * @param int[] $capture_ids    id delle carte del tavolo da prendere; se vuoto il client non lo invia affatto
     *                              (BGA scarta le liste vuote), quindi il valore predefinito è la lista vuota
     * @return string lo stato successivo: NextPlayer
     * @throws UserException se la carta non è nella mano del giocatore o la presa non è lecita
     */
    #[PossibleAction]
    public function actPlay(int $card_id, int $activePlayerId, #[IntArrayParam] array $capture_ids = [])
    {
        $game = $this->game;
        $cards = $game->cards;

        $playedDeckCard = $cards->getCard($card_id);
        $isInPlayersHand = $playedDeckCard !== null
            && $playedDeckCard['location'] === Game::LOCATION_HAND
            && (int) $playedDeckCard['location_arg'] === $activePlayerId;
        if (!$isInPlayersHand) {
            throw new UserException(clienttranslate('This card is not in your hand'));
        }

        $playedCard = $game->cardView($playedDeckCard);
        $tableCards = $game->tableCards();
        $capturedCardIds = array_values(array_unique(array_map('intval', $capture_ids)));
        sort($capturedCardIds);

        $wantsToCapture = count($capturedCardIds) > 0;
        if (!$wantsToCapture) {
            $captureWasPossible = count(ScopaRules::legalCaptures($playedCard['rank'], $tableCards)) > 0;
            if ($captureWasPossible) {
                throw new UserException(clienttranslate('You must capture when a capture is possible'));
            }
        } elseif (!ScopaRules::isLegalCapture($playedCard['rank'], $tableCards, $capturedCardIds)) {
            throw new UserException(clienttranslate('This capture is not allowed'));
        }

        $teamId = $game->teamOf($activePlayerId);
        $commonNotificationArgs = [
            'player_id' => $activePlayerId,
            'player_name' => $game->getPlayerNameById($activePlayerId),
            'card_label' => $game->cardLabel($playedCard),
            'card' => $playedCard,
            'team' => $teamId,
        ];

        if (!$wantsToCapture) {
            $cards->moveCard($card_id, Game::LOCATION_TABLE);
            $this->bga->playerStats->inc('discards_count', 1, $activePlayerId);
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} puts ${card_label} on the table'), $commonNotificationArgs + [
                'captured' => [],
                'scopa' => false,
                'handCount' => $cards->countCardInLocation(Game::LOCATION_HAND, $activePlayerId),
            ]);
            return NextPlayer::class;
        }

        $capturedCardViews = $game->cardViews($cards->getCards($capturedCardIds));

        // La carta giocata e quelle prese vanno nella pila della squadra del giocatore.
        $cards->moveCards(array_merge([$card_id], $capturedCardIds), Game::LOCATION_CAPTURED, $teamId);
        $this->bga->globals->set('last_capturer', $teamId);
        $this->bga->playerStats->inc('captures_count', 1, $activePlayerId);

        $tableWasEmptied = $cards->countCardInLocation(Game::LOCATION_TABLE) === 0;
        $isScopa = $tableWasEmptied && !$game->isLastPlayOfDeal();
        $capturedLabels = implode(', ', array_map(fn($capturedCardView) => $game->cardLabel($capturedCardView), $capturedCardViews));

        $captureNotificationArgs = $commonNotificationArgs + [
            'captured' => $capturedCardViews,
            'captured_labels' => $capturedLabels,
            'scopa' => $isScopa,
            'handCount' => $cards->countCardInLocation(Game::LOCATION_HAND, $activePlayerId),
        ];
        if ($isScopa) {
            $game->addScopa($teamId);
            $this->bga->playerStats->inc('scope_count', 1, $activePlayerId);
            $captureNotificationArgs['scopeCount'] = $game->getScope()[$teamId];
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label}, captures ${captured_labels} and makes a SCOPA!'), $captureNotificationArgs);
        } else {
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label} and captures ${captured_labels}'), $captureNotificationArgs);
        }

        return NextPlayer::class;
    }

    /**
     * Modalità zombie (giocatore uscito o scaduto), livello 1: gioca una carta a caso con una presa lecita a caso,
     * oppure si limita a posare la carta sul tavolo se non può prendere. Gira sul server senza un giocatore
     * corrente, quindi chiama actPlay passando esplicitamente l'id del giocatore.
     *
     * @param int $playerId il giocatore zombie
     * @return string lo stato successivo: NextPlayer
     */
    function zombie(int $playerId)
    {
        $capturesByHandCardId = $this->game->capturesByCard($playerId);
        $handCardIds = array_keys($capturesByHandCardId);
        $chosenCardId = $handCardIds[bga_rand(0, count($handCardIds) - 1)];

        $availableCaptures = $capturesByHandCardId[$chosenCardId];
        $chosenCapture = count($availableCaptures) > 0 ? $availableCaptures[bga_rand(0, count($availableCaptures) - 1)] : [];
        return $this->actPlay($chosenCardId, $playerId, $chosenCapture);
    }
}
