<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * ProjectBiagio implementation : © <Your name here> <Your email address here>
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 *
 * Game.php
 *
 * Scopa: classe principale del gioco. Contiene la preparazione della partita, i dati inviati al client e le
 * funzioni condivise dagli stati di gioco (squadre, posti, carte, scope). Le regole pure (prese, punteggi)
 * sono in Rules/ScopaRules.php.
 *
 * Come sono salvate le carte (componente Deck, tabella `scopa_card`):
 *  - card type     = seme (1 denari, 2 coppe, 3 bastoni, 4 spade)
 *  - card type_arg = valore (1..10)
 *  - posizioni     : 'deck' (mazzo), 'hand' (mano, arg = id giocatore), 'table' (tavolo),
 *                    'captured' (carte prese, arg = id squadra)
 *
 * Squadre: con 2 giocatori ogni giocatore è una squadra (id squadra = numero del posto); con 4 giocatori
 * i posti 1 e 3 formano la squadra 1, i posti 2 e 4 la squadra 2.
 */
declare(strict_types=1);

namespace Bga\Games\ProjectBiagio;

use Bga\Games\ProjectBiagio\Rules\ScopaRules;
use Bga\Games\ProjectBiagio\States\NewDeal;

class Game extends \Bga\GameFramework\Table
{
    /** Id dell'opzione di tavolo "punteggio obiettivo" (vedi gameoptions.jsonc). */
    public const OPTION_TARGET_SCORE = 100;

    public const LOCATION_DECK = 'deck';
    public const LOCATION_HAND = 'hand';
    public const LOCATION_TABLE = 'table';
    public const LOCATION_CAPTURED = 'captured';

    /** Simbolo mostrato nel log per ogni seme (id seme => simbolo). */
    private const SUIT_SYMBOL_BY_SUIT = [1 => '♦', 2 => '♥', 3 => '♣', 4 => '♠'];

    /** Etichetta breve mostrata nel log per ogni valore (valore => etichetta). */
    private const RANK_LABEL_BY_RANK = [1 => 'A', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => 'J', 9 => 'Q', 10 => 'K'];

    /** Numero di carte del mazzo. */
    private const DECK_SIZE = 40;

    /** Media approssimativa dei punti fatti in una smazzata, usata solo per stimare l'avanzamento della partita. */
    private const AVERAGE_POINTS_PER_DEAL = 3;

    public \Bga\GameFramework\Components\Deck $cards;

    /** @var array<int, int[]>|null cache: id squadra => id dei giocatori della squadra, in ordine di tavolo */
    private ?array $playerIdsByTeamCache = null;

    /**
     * Crea il componente Deck collegato alla tabella `scopa_card`. Va creato qui, non nel setup.
     */
    public function __construct()
    {
        parent::__construct();

        $this->cards = $this->bga->deckFactory->createDeck('scopa_card');
    }

    ///////////////////////////////////////////////////
    //// Squadre e posti

    /**
     * Vero quando si gioca in 4 giocatori divisi in due squadre da due.
     */
    public function isTeamGame(): bool
    {
        return $this->getPlayersNumber() === 4;
    }

    /**
     * Restituisce la squadra (1 o 2) di un giocatore. Con 2 giocatori la squadra è il numero del posto; con
     * 4 giocatori i compagni siedono di fronte (posti 1 e 3 = squadra 1, posti 2 e 4 = squadra 2).
     */
    public function teamOf(int $playerId): int
    {
        $seatNumber = (int) $this->getPlayerNoById($playerId);
        return $this->isTeamGame() ? (($seatNumber - 1) % 2) + 1 : $seatNumber;
    }

    /**
     * Restituisce i giocatori di ogni squadra, indicizzati per id squadra (il risultato è messo in cache
     * dopo la prima chiamata).
     *
     * @return array<int, int[]> id squadra => id dei giocatori in ordine di tavolo
     */
    public function getTeams(): array
    {
        if ($this->playerIdsByTeamCache === null) {
            $playerIdsByTeam = [];
            $playerRows = $this->getObjectListFromDB("SELECT `player_id`, `player_no` FROM `player` ORDER BY `player_no`");
            foreach ($playerRows as $playerRow) {
                $playerId = (int) $playerRow['player_id'];
                $playerIdsByTeam[$this->teamOf($playerId)][] = $playerId;
            }
            ksort($playerIdsByTeam);
            $this->playerIdsByTeamCache = $playerIdsByTeam;
        }
        return $this->playerIdsByTeamCache;
    }

    /**
     * Restituisce gli id di tutti i giocatori nell'ordine dei posti al tavolo (e quindi dei turni).
     *
     * @return int[]
     */
    public function playerIdsInTableOrder(): array
    {
        $playerRows = $this->getObjectListFromDB("SELECT `player_id` FROM `player` ORDER BY `player_no`");
        return array_map(fn($playerRow) => (int) $playerRow['player_id'], $playerRows);
    }

    /**
     * Restituisce gli id dei giocatori che appartengono a una squadra.
     *
     * @return int[]
     */
    public function playersOfTeam(int $teamId): array
    {
        return $this->getTeams()[$teamId];
    }

    /**
     * Restituisce un nome leggibile per la squadra: i nomi dei suoi giocatori uniti da " & ".
     */
    public function teamName(int $teamId): string
    {
        $playerNames = array_map(fn($playerId) => $this->getPlayerNameById($playerId), $this->playersOfTeam($teamId));
        return implode(' & ', $playerNames);
    }

    /**
     * Restituisce il punteggio totale attuale di una squadra. Tutti i membri hanno lo stesso punteggio,
     * quindi si legge quello del primo.
     */
    public function teamScore(int $teamId): int
    {
        $firstPlayerOfTeam = $this->playersOfTeam($teamId)[0];
        return $this->bga->playerScore->get($firstPlayerOfTeam);
    }

    /**
     * Restituisce tutti i giocatori in ordine di turno, a partire da chi apre la mano
     * (il giocatore dopo il mazziere).
     *
     * @return int[] id dei giocatori
     */
    public function playersFromFirst(): array
    {
        $playerIdsInTurnOrder = [];
        $playerId = $this->getFirstPlayerId();
        for ($count = 0; $count < $this->getPlayersNumber(); $count++) {
            $playerIdsInTurnOrder[] = $playerId;
            $playerId = $this->getPlayerAfter($playerId);
        }
        return $playerIdsInTurnOrder;
    }

    /**
     * Restituisce l'id del mazziere attuale.
     */
    public function getDealerId(): int
    {
        return (int) $this->bga->globals->get('dealer');
    }

    /**
     * Restituisce l'id del giocatore che apre ogni mano della smazzata: quello dopo il mazziere
     * nell'ordine di tavolo di BGA.
     */
    public function getFirstPlayerId(): int
    {
        return $this->getPlayerAfter($this->getDealerId());
    }

    /**
     * Numero di carte date a ogni giocatore in una volta: 3 con 2 giocatori (più giri di distribuzione),
     * 10 con 4 giocatori (tutto il mazzo è distribuito in una volta sola).
     */
    public function handSize(): int
    {
        return $this->isTeamGame() ? 10 : 3;
    }

    /**
     * Restituisce i punti che una squadra deve raggiungere per vincere la partita.
     */
    public function targetScore(): int
    {
        return (int) $this->bga->globals->get('target');
    }

    ///////////////////////////////////////////////////
    //// Carte

    /**
     * Converte una carta restituita dal componente Deck nel formato usato dal client e da ScopaRules
     * (id, seme, valore).
     *
     * @return array{id:int, suit:int, rank:int}
     */
    public function cardView(array $deckCard): array
    {
        return [
            'id' => (int) $deckCard['id'],
            'suit' => (int) $deckCard['type'],
            'rank' => (int) $deckCard['type_arg'],
        ];
    }

    /**
     * Converte un elenco di carte del Deck in carte per il client, ordinate per id
     * (così l'ordine è sempre prevedibile).
     *
     * @return array[]
     */
    public function cardViews(array $deckCards): array
    {
        $cardViews = array_map(fn($deckCard) => $this->cardView($deckCard), array_values($deckCards));
        usort($cardViews, fn($first, $second) => $first['id'] <=> $second['id']);
        return $cardViews;
    }

    /**
     * Restituisce il testo breve usato nel log per una carta, per esempio "7♦" o "K♠".
     */
    public function cardLabel(array $cardView): string
    {
        return self::RANK_LABEL_BY_RANK[$cardView['rank']] . self::SUIT_SYMBOL_BY_SUIT[$cardView['suit']];
    }

    /**
     * Conta le carte in una posizione e restituisce sempre un intero. Il componente Deck restituisce il numero
     * come testo (per esempio "0"), quindi confronti stretti come `=== 0` fallirebbero: si usa sempre questo metodo.
     *
     * @param string   $location     posizione delle carte (mazzo, mano, tavolo, prese)
     * @param int|null $locationArg  argomento della posizione (id giocatore o id squadra), null = tutti
     */
    public function countCardsIn(string $location, ?int $locationArg = null): int
    {
        return (int) $this->cards->countCardInLocation($location, $locationArg);
    }

    /**
     * Restituisce le carte attualmente sul tavolo.
     *
     * @return array[]
     */
    public function tableCards(): array
    {
        return $this->cardViews($this->cards->getCardsInLocation(self::LOCATION_TABLE));
    }

    /**
     * Per ogni carta nella mano del giocatore elenca le prese possibili con quella carta.
     * Un elenco vuoto per una carta significa che non può prendere nulla (può solo essere posata sul tavolo).
     *
     * @return array<int, int[][]> id carta in mano => elenco di prese lecite (ognuna è un elenco di id del tavolo)
     */
    public function capturesByCard(int $playerId): array
    {
        $tableCards = $this->tableCards();
        $capturesByHandCardId = [];
        foreach ($this->cards->getCardsInLocation(self::LOCATION_HAND, $playerId) as $handCard) {
            $capturesByHandCardId[(int) $handCard['id']] = ScopaRules::legalCaptures((int) $handCard['type_arg'], $tableCards);
        }
        return $capturesByHandCardId;
    }

    /**
     * Vero quando la carta appena giocata è l'ultima della smazzata (mazzo e mani tutti vuoti).
     * Una presa che svuota il tavolo in questa giocata NON è una scopa.
     */
    public function isLastPlayOfDeal(): bool
    {
        return $this->countCardsIn(self::LOCATION_DECK) === 0
            && $this->countCardsIn(self::LOCATION_HAND) === 0;
    }

    /**
     * Restituisce quante carte ha in mano ogni giocatore (informazione pubblica).
     *
     * @return array<int, int> id giocatore => numero di carte in mano
     */
    public function handCounts(): array
    {
        $handCountByPlayerId = [];
        foreach (array_keys($this->loadPlayersBasicInfos()) as $playerId) {
            $handCountByPlayerId[(int) $playerId] = $this->countCardsIn(self::LOCATION_HAND, (int) $playerId);
        }
        return $handCountByPlayerId;
    }

    /**
     * Restituisce il numero di scope fatte da ogni squadra nella smazzata corrente.
     *
     * @return array<int, int> id squadra => numero di scope
     */
    public function getScope(): array
    {
        $storedScopeByTeam = $this->bga->globals->get('scope', []);
        $scopeByTeam = [];
        foreach (array_keys($this->getTeams()) as $teamId) {
            $scopeByTeam[$teamId] = (int) ($storedScopeByTeam[$teamId] ?? 0);
        }
        return $scopeByTeam;
    }

    /**
     * Aggiunge una scopa alla squadra per la smazzata corrente.
     */
    public function addScopa(int $teamId): void
    {
        $scopeByTeam = $this->getScope();
        $scopeByTeam[$teamId]++;
        $this->bga->globals->set('scope', $scopeByTeam);
    }

    ///////////////////////////////////////////////////
    //// Punti di ingresso del framework

    /**
     * Restituisce l'avanzamento della partita (0-100) mostrato ai giocatori: punti della squadra in testa
     * più la frazione di smazzata già giocata, rispetto al punteggio obiettivo.
     */
    public function getGameProgression()
    {
        $targetScore = max(1, $this->targetScore());
        $bestTeamScore = 0;
        foreach (array_keys($this->getTeams()) as $teamId) {
            $bestTeamScore = max($bestTeamScore, $this->teamScore($teamId));
        }

        $cardsStillToPlay = $this->countCardsIn(self::LOCATION_DECK) + $this->countCardsIn(self::LOCATION_HAND);
        $fractionOfDealPlayed = (self::DECK_SIZE - $cardsStillToPlay) / self::DECK_SIZE;

        $estimatedPoints = $bestTeamScore + $fractionOfDealPlayed * self::AVERAGE_POINTS_PER_DEAL;
        $progressionPercent = 100 * $estimatedPoints / ($targetScore + self::AVERAGE_POINTS_PER_DEAL);
        return (int) min(99, round($progressionPercent));
    }

    /**
     * Punto di aggancio per le migrazioni del database: per ora non c'è nulla da migrare
     * (il gioco non è ancora pubblicato).
     */
    public function upgradeTableDb($from_version)
    {
    }

    /**
     * Raccoglie tutto ciò che serve al client per disegnare il gioco, limitato a ciò che il giocatore
     * indicato può vedere (solo la propria mano). Viene chiamata al caricamento e all'aggiornamento della pagina.
     */
    protected function getAllDatas(int $currentPlayerId): array
    {
        $gameData = [];

        $gameData['players'] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score` FROM `player`"
        );
        foreach ($gameData['players'] as $playerId => &$playerData) {
            $playerData['team'] = $this->teamOf((int) $playerId);
        }
        unset($playerData);

        $capturedCountByTeam = [];
        $teamNameByTeam = [];
        foreach (array_keys($this->getTeams()) as $teamId) {
            $capturedCountByTeam[$teamId] = $this->countCardsIn(self::LOCATION_CAPTURED, $teamId);
            $teamNameByTeam[$teamId] = $this->teamName($teamId);
        }

        $gameData['isTeamGame'] = $this->isTeamGame();
        $gameData['playerOrder'] = $this->playerIdsInTableOrder();
        $gameData['teamNames'] = $teamNameByTeam;
        $gameData['target'] = $this->targetScore();
        $gameData['dealer'] = $this->getDealerId();
        $gameData['dealNo'] = (int) $this->bga->globals->get('deal_no');
        $gameData['hand'] = $this->cardViews($this->cards->getCardsInLocation(self::LOCATION_HAND, $currentPlayerId));
        $gameData['table'] = $this->tableCards();
        $gameData['handCounts'] = $this->handCounts();
        $gameData['deckCount'] = $this->countCardsIn(self::LOCATION_DECK);
        $gameData['capturedCounts'] = $capturedCountByTeam;
        $gameData['scope'] = $this->getScope();

        return $gameData;
    }

    /**
     * Prepara una nuova partita: crea i giocatori, decide il punteggio obiettivo e il primo mazziere, crea le
     * 40 carte e inizializza le statistiche. La prima smazzata è avviata dallo stato NewDeal.
     *
     * @param array $players  giocatori iscritti al tavolo, indicizzati per id giocatore
     * @param array $options  opzioni scelte alla creazione del tavolo
     */
    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $availableColors = $gameinfos['player_colors'];

        $playerInsertValues = [];
        foreach ($players as $playerId => $playerInfo) {
            $playerInsertValues[] = vsprintf("(%s, '%s', '%s')", [
                $playerId,
                array_shift($availableColors),
                addslashes($playerInfo["player_name"]),
            ]);
        }

        $this->DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $playerInsertValues)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // Punteggio obiettivo: "automatico" vale 11 punti con 2 giocatori e 21 punti con 4 giocatori (squadre).
        $targetScoreOption = (int) $this->bga->tableOptions->get(self::OPTION_TARGET_SCORE);
        $targetScore = match ($targetScoreOption) {
            1 => 11,
            2 => 21,
            3 => 31,
            default => count($players) === 4 ? 21 : 11,
        };
        $this->bga->globals->set('target', $targetScore);

        // Primo mazziere scelto a caso; dalla seconda smazzata il mazziere passa al giocatore successivo.
        $playerIds = array_keys($players);
        $this->bga->globals->set('dealer', (int) $playerIds[bga_rand(0, count($playerIds) - 1)]);
        $this->bga->globals->set('deal_no', 0);
        $this->bga->globals->set('scope', []);
        $this->bga->globals->set('last_capturer', 0);

        // Il mazzo italiano da 40 carte: seme in `type` (1 denari, 2 coppe, 3 bastoni, 4 spade), valore in `type_arg`.
        $cardsToCreate = [];
        foreach (ScopaRules::ALL_SUITS as $suit) {
            for ($rank = 1; $rank <= 10; $rank++) {
                $cardsToCreate[] = ['type' => $suit, 'type_arg' => $rank, 'nbr' => 1];
            }
        }
        $this->cards->createCards($cardsToCreate, self::LOCATION_DECK);

        $this->bga->tableStats->init('deals_played', 0);
        $this->bga->playerStats->init([
            'scope_count', 'settebello_count', 'primiera_count', 'denari_count',
            'carte_count', 'captures_count', 'discards_count',
        ], 0);

        return NewDeal::class;
    }

    /**
     * Funzione di debug (pulsante nel pannello debug di Studio): salta a uno stato dato il suo id,
     * per esempio 95 = EndDeal.
     */
    public function debug_goToState(int $state = 3)
    {
        $this->gamestate->jumpToState($state);
    }
}
