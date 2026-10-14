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
 * Come sono salvate le carte (componente ItemManager, classe Card, tabella `card`):
 *  - suit     = seme (1 denari, 2 coppe, 3 bastoni, 4 spade)
 *  - rank     = valore (1..10)
 *  - posizioni: 'deck' (mazzo), 'hand' (mano, arg = id giocatore), 'table' (tavolo),
 *               'captured' (carte prese, arg = id squadra)
 *
 * Squadre: con 2 giocatori ogni giocatore è una squadra (id squadra = numero del posto); con 4 giocatori
 * i posti 1 e 3 formano la squadra 1, i posti 2 e 4 la squadra 2.
 */
declare(strict_types=1);

namespace Bga\Games\ProjectBiagio;

use Bga\GameFramework\Components\ItemManager\ItemLocation;
use Bga\GameFramework\Components\ItemManager\ItemManager;
use Bga\GameFramework\Components\ItemManager\Location;
use Bga\Games\ProjectBiagio\Rules\ScopaRules;
use Bga\Games\ProjectBiagio\States\NewDeal;

class Game extends \Bga\GameFramework\Table
{
    /** Punteggio da raggiungere per vincere con 2 giocatori. */
    private const TARGET_SCORE_TWO_PLAYERS = 11;

    /** Punteggio da raggiungere per vincere con 4 giocatori a squadre (le smazzate sono condivise, quindi serve di più). */
    private const TARGET_SCORE_FOUR_PLAYERS = 21;

    public const LOCATION_DECK = 'deck';
    public const LOCATION_HAND = 'hand';
    public const LOCATION_TABLE = 'table';
    public const LOCATION_CAPTURED = 'captured';

    /** Numero di carte del mazzo. */
    private const DECK_SIZE = 40;

    /**
     * Media approssimativa dei punti fatti da una squadra in una smazzata, usata solo per stimare l'avanzamento
     * della partita (la durata reale non è prevedibile: dipende da quando qualcuno raggiunge l'obiettivo).
     */
    private const AVERAGE_POINTS_PER_DEAL = 3;

    /** @var ItemManager<Card> gestore delle 40 carte (tabella `card`) */
    public ItemManager $cards;

    /** @var array<int, int[]>|null cache: id squadra => id dei giocatori della squadra, in ordine di tavolo */
    private ?array $playerIdsByTeamCache = null;

    /**
     * Crea il gestore delle carte (ItemManager) per la classe Card, registrando le quattro posizioni in cui una
     * carta può trovarsi. Va creato qui, non nel setup.
     */
    public function __construct()
    {
        parent::__construct();

        $this->cards = $this->bga->itemManagerFactory->createItemManager(Card::class, null, [
            new ItemLocation(self::LOCATION_DECK),
            new ItemLocation(self::LOCATION_HAND),
            new ItemLocation(self::LOCATION_TABLE),
            new ItemLocation(self::LOCATION_CAPTURED),
        ]);
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
     * Restituisce i giocatori di ogni squadra, indicizzati per id squadra. Il risultato è messo in cache
     * perché le squadre non cambiano durante la partita e questo metodo è chiamato molte volte per richiesta
     * (punteggi, nomi, pile): così si evita di rifare le query ad ogni chiamata.
     *
     * @return array<int, int[]> id squadra => id dei giocatori in ordine di tavolo
     */
    public function getTeams(): array
    {
        if ($this->playerIdsByTeamCache === null) {
            $playerIdsByTeam = [];
            foreach ($this->playerIdsInTableOrder() as $playerId) {
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
     * Restituisce la posizione di una carta nel formato di ItemManager: una posizione precisa se si indica anche
     * l'argomento (mano di un giocatore, pila di una squadra), altrimenti un filtro che comprende ogni argomento
     * (per esempio "tutte le mani").
     */
    public function locationOf(string $location, ?int $locationArg = null): Location
    {
        return $locationArg === null ? Location::filter($location) : Location::from($location, $locationArg);
    }

    /**
     * Converte una carta (oggetto Card) nel formato usato dal client e da ScopaRules (id, seme, valore).
     *
     * @return array{id:int, suit:int, rank:int}
     */
    public function cardView(Card $card): array
    {
        return [
            'id' => (int) $card->id,
            'suit' => (int) $card->suit,
            'rank' => (int) $card->rank,
        ];
    }

    /**
     * Converte un elenco di carte (array o Collection restituita da ItemManager) in carte per il client,
     * ordinate per id (così l'ordine è sempre prevedibile).
     *
     * @param iterable<Card> $cards
     * @return array[]
     */
    public function cardViews(iterable $cards): array
    {
        $cardViews = [];
        foreach ($cards as $card) {
            $cardViews[] = $this->cardView($card);
        }
        usort($cardViews, fn($first, $second) => $first['id'] <=> $second['id']);
        return $cardViews;
    }

    /**
     * Restituisce il nome di una carta da usare come argomento di una notifica, per esempio "King of Coins".
     * È un argomento "annidato" (testo con segnaposto + argomenti) perché i nomi vanno tradotti dal client nella
     * lingua di ogni giocatore: le chiavi in `i18n` dicono a BGA quali argomenti tradurre. Gli stessi testi
     * (Ace, Coins, ...) sono usati dal client per i tooltip, quindi hanno una sola traduzione.
     *
     * @return array{log:string, args:array}
     */
    public function cardLabel(array $cardView): array
    {
        $rankNameByRank = [
            1 => clienttranslate('Ace'), 2 => clienttranslate('Two'), 3 => clienttranslate('Three'),
            4 => clienttranslate('Four'), 5 => clienttranslate('Five'), 6 => clienttranslate('Six'),
            7 => clienttranslate('Seven'), 8 => clienttranslate('Jack'), 9 => clienttranslate('Knight'),
            10 => clienttranslate('King'),
        ];
        $suitNameBySuit = [
            1 => clienttranslate('Coins'), 2 => clienttranslate('Cups'),
            3 => clienttranslate('Clubs'), 4 => clienttranslate('Swords'),
        ];
        return [
            'log' => clienttranslate('${rank_name} of ${suit_name}'),
            'args' => [
                'rank_name' => $rankNameByRank[$cardView['rank']],
                'suit_name' => $suitNameBySuit[$cardView['suit']],
                'i18n' => ['rank_name', 'suit_name'],
            ],
        ];
    }

    /**
     * Restituisce l'elenco di più carte come argomento di notifica annidato, per esempio
     * "Two of Cups, Five of Swords": ogni carta è un argomento (`card_0`, `card_1`, ...) con il proprio nome
     * tradotto, così l'elenco resta traducibile anche se contiene un numero variabile di carte.
     *
     * @param array[] $cardViews carte nel formato del client
     * @return array{log:string, args:array}
     */
    public function cardsLabel(array $cardViews): array
    {
        $cardLabelByKey = [];
        foreach (array_values($cardViews) as $index => $cardView) {
            $cardLabelByKey['card_' . $index] = $this->cardLabel($cardView);
        }
        return [
            'log' => implode(', ', array_map(fn($key) => '${' . $key . '}', array_keys($cardLabelByKey))),
            'args' => $cardLabelByKey,
        ];
    }

    /**
     * Conta le carte in una posizione e restituisce un intero (con ItemManager il conteggio è già un intero).
     *
     * @param string   $location     posizione delle carte (mazzo, mano, tavolo, prese)
     * @param int|null $locationArg  argomento della posizione (id giocatore o id squadra), null = tutti
     */
    public function countCardsIn(string $location, ?int $locationArg = null): int
    {
        return $this->cards->countItemsInLocation($this->locationOf($location, $locationArg));
    }

    /**
     * Restituisce le carte attualmente sul tavolo.
     *
     * @return array[]
     */
    public function tableCards(): array
    {
        return $this->cardViews($this->cards->getItemsInLocation(self::LOCATION_TABLE));
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
        foreach ($this->cards->getItemsInLocation($this->locationOf(self::LOCATION_HAND, $playerId)) as $handCard) {
            $capturesByHandCardId[$handCard->id] = ScopaRules::legalCaptures($handCard->rank, $tableCards);
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
     * Punto di aggancio per le migrazioni del database. Il framework BGA lo richiede (è chiamato quando si
     * carica una partita creata con una versione precedente del gioco): per ora non c'è nulla da migrare
     * perché il gioco non è ancora pubblicato; quando cambierà `dbmodel.sql` le modifiche andranno qui.
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
        $gameData['hand'] = $this->cardViews($this->cards->getItemsInLocation($this->locationOf(self::LOCATION_HAND, $currentPlayerId)));
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
        // Crea la tabella delle carte (`card`): va fatto per primo, prima di creare qualsiasi carta.
        $this->cards->initDb();

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

        //Imposta il punteggio obiettivo in base al numero di giocatori: 11 con 2 giocatori, 21 con 4 giocatori
        $targetScore = count($players) === 4 ? self::TARGET_SCORE_FOUR_PLAYERS : self::TARGET_SCORE_TWO_PLAYERS;
        $this->bga->globals->set('target', $targetScore);

        // Sceglie il mazziere iniziale a caso e azzera il numero di smazzate giocate e le scope fatte
        $playerIds = array_keys($players);
        $this->bga->globals->set('dealer', (int) $playerIds[bga_rand(0, count($playerIds) - 1)]);
        $this->bga->globals->set('deal_no', 0);
        $this->bga->globals->set('scope', []);
        $this->bga->globals->set('last_capturer', 0);

        // Crea le 40 carte del mazzo, tutte in posizione "deck"
        $cardsToCreate = [];
        foreach (ScopaRules::ALL_SUITS as $suit) {
            for ($rank = 1; $rank <= 10; $rank++) {
                $cardsToCreate[] = ['location' => self::LOCATION_DECK, 'suit' => $suit, 'rank' => $rank];
            }
        }
        $this->cards->createItems($cardsToCreate);

        // Inizializza le statistiche di partita e giocatore (tutte a 0)
        $this->bga->tableStats->init('deals_played', 0);
        $this->bga->playerStats->init([
            'scope_count', 'settebello_count', 'primiera_count', 'denari_count',
            'carte_count', 'captures_count',
        ], 0);

        return NewDeal::class;
    }

    /**
     * Funzione di debug (pulsante nel pannello debug di Studio, il prefisso `debug_` la rende visibile lì):
     * salta a uno stato dato il suo id, per esempio 95 = EndDeal. Serve a provare la fine smazzata senza giocare
     * tutte le carte; in produzione BGA non la espone ai giocatori.
     */
    public function debug_goToState(int $state = 3)
    {
        $this->gamestate->jumpToState($state);
    }
}
