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
     * parte privata, così le riceve solo il giocatore attivo
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
        // Scorciatoie: il gioco e il gestore delle carte (ItemManager), usati più volte nel resto del metodo
        $game = $this->game;
        $cards = $game->cards;

        // Recupera dal database la carta che il client dice di voler giocare (null se l'id non esiste)
        $playedCardItem = $cards->getItemById($card_id);

        // Validazione 1: la carta deve esistere, essere in una mano e appartenere al giocatore attivo.
        $isInPlayersHand = $playedCardItem !== null
            && $playedCardItem->location === Game::LOCATION_HAND
            && (int) $playedCardItem->locationArg === $activePlayerId;
        if (!$isInPlayersHand) {
            throw new UserException(clienttranslate('This card is not in your hand'));
        }

        // Prepara i dati per validare la presa: la carta giocata e le carte sul tavolo in formato per il client,
        // poi gli id delle carte da prendere ripuliti (numeri interi, senza doppioni, in ordine crescente)
        $playedCard = $game->cardView($playedCardItem);
        $tableCards = $game->tableCards();
        $capturedCardIds = array_values(array_unique(array_map('intval', $capture_ids)));
        sort($capturedCardIds);

        // Validazione 2: se il giocatore non vuole prendere, non deve esistere nessuna presa lecita
        // se vuole prendere, la presa scelta deve rispettare le regole
        $wantsToCapture = count($capturedCardIds) > 0;
        if (!$wantsToCapture) {
            $captureWasPossible = count(ScopaRules::legalCaptures($playedCard['rank'], $tableCards)) > 0;
            if ($captureWasPossible) {
                throw new UserException(clienttranslate('You must capture when a capture is possible'));
            }
        } elseif (!ScopaRules::isLegalCapture($playedCard['rank'], $tableCards, $capturedCardIds)) {
            throw new UserException(clienttranslate('This capture is not allowed'));
        }

        // Da qui la mossa è valida. Trova la squadra del giocatore (con 2 giocatori ogni squadra è un giocatore)
        $teamId = $game->teamOf($activePlayerId);

        // Dati della notifica comuni a entrambi i casi (posa e presa): chi gioca, quale carta e per quale squadra
        $commonNotificationArgs = [
            'player_id' => $activePlayerId,
            'player_name' => $game->getPlayerNameById($activePlayerId),
            'card_label' => $game->cardLabel($playedCard),
            'card' => $playedCard,
            'team' => $teamId,
        ];

        // Caso 1, nessuna presa: la carta passa dalla mano al tavolo, si avvisano tutti i giocatori
        // e il turno passa al prossimo
        if (!$wantsToCapture) {
            $cards->moveItem($card_id, Game::LOCATION_TABLE);
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} puts ${card_label} on the table'), $commonNotificationArgs + [
                'captured' => [],
                'scopa' => false,
                'handCount' => $game->countCardsIn(Game::LOCATION_HAND, $activePlayerId),
            ]);
            return NextPlayer::class;
        }

        // Caso 2, presa La carta giocata e quelle prese vanno nella pila della squadra del giocatore
        $capturedCardViews = $game->cardViews($cards->getItemsByIds($capturedCardIds));
        $cards->moveItems(array_merge([$card_id], $capturedCardIds), $game->locationOf(Game::LOCATION_CAPTURED, $teamId));

        // Ricorda quale squadra ha preso per ultima e conta la presa nelle statistiche del giocatore
        $this->bga->globals->set('last_capturer', $teamId);
        $this->bga->playerStats->inc('captures_count', 1, $activePlayerId);

        // C'è scopa se la presa ha svuotato il tavolo, tranne nell'ultima giocata della smazzata
        $tableWasEmptied = $game->countCardsIn(Game::LOCATION_TABLE) === 0;
        $isScopa = $tableWasEmptied && !$game->isLastPlayOfDeal();

        // Testo con i nomi delle carte prese, da mostrare nel log di gioco
        $capturedLabels = $game->cardsLabel($capturedCardViews);

        // Dati della notifica per il client: carte prese, nomi per il log, se c'è scopa e carte rimaste in mano
        $captureNotificationArgs = $commonNotificationArgs + [
            'captured' => $capturedCardViews,
            'captured_labels' => $capturedLabels,
            'scopa' => $isScopa,
            'handCount' => $game->countCardsIn(Game::LOCATION_HAND, $activePlayerId),
        ];

        // Con la scopa si aggiunge un punto scopa alla squadra, si aggiorna la statistica, si invia il totale
        // aggiornato al client e si usa un messaggio di log dedicato; altrimenti si invia il messaggio di presa semplice
        if ($isScopa) {
            $game->addScopa($teamId);
            $this->bga->playerStats->inc('scope_count', 1, $activePlayerId);
            $captureNotificationArgs['scopeCount'] = $game->getScope()[$teamId];
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label}, captures ${captured_labels} and makes a SCOPA!'), $captureNotificationArgs);
        } else {
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label} and captures ${captured_labels}'), $captureNotificationArgs);
        }

        // Turno concluso: passa a NextPlayer, che decide chi gioca dopo o se la smazzata continua
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
