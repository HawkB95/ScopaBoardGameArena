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
 * Scopa: main game class. It holds the game setup, the data sent to the client and the helpers shared by the
 * game states (teams, seating, cards, scope). The pure rules (captures, scoring) are in Rules/ScopaRules.php.
 *
 * Card storage (Deck component, table `scopa_card`):
 *  - card type     = suit (1 denari, 2 coppe, 3 bastoni, 4 spade)
 *  - card type_arg = rank (1..10)
 *  - locations     : 'deck', 'hand' (arg = player id), 'table', 'captured' (arg = team id)
 *
 * Teams: with 2 players each player is a team (team id = seat number); with 4 players seats 1&3 are team 1
 * and seats 2&4 are team 2.
 */
declare(strict_types=1);

namespace Bga\Games\ProjectBiagio;

use Bga\Games\ProjectBiagio\Rules\ScopaRules;
use Bga\Games\ProjectBiagio\States\NewDeal;

class Game extends \Bga\GameFramework\Table
{
    /** Id of the "target score" table option (see gameoptions.jsonc). */
    public const OPTION_TARGET_SCORE = 100;

    public const LOCATION_DECK = 'deck';
    public const LOCATION_HAND = 'hand';
    public const LOCATION_TABLE = 'table';
    public const LOCATION_CAPTURED = 'captured';

    /** Symbol shown in the game log for each suit (suit id => symbol). */
    private const SUIT_SYMBOL_BY_SUIT = [1 => '♦', 2 => '♥', 3 => '♣', 4 => '♠'];

    /** Short label shown in the game log for each rank (rank => label). */
    private const RANK_LABEL_BY_RANK = [1 => 'A', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => 'J', 9 => 'Q', 10 => 'K'];

    /** Number of cards in the deck. */
    private const DECK_SIZE = 40;

    /** Rough average of the points scored in one deal, used only to estimate the game progression. */
    private const AVERAGE_POINTS_PER_DEAL = 3;

    public \Bga\GameFramework\Components\Deck $cards;

    /** @var array<int, int[]>|null cache: team id => ids of the players of that team, in table order */
    private ?array $playerIdsByTeamCache = null;

    /**
     * Creates the Deck component bound to the `scopa_card` table. It must be created here, not in setup.
     */
    public function __construct()
    {
        parent::__construct();

        $this->cards = $this->bga->deckFactory->createDeck('scopa_card');
    }

    ///////////////////////////////////////////////////
    //// Teams and seating

    /**
     * True when the game is played by 4 players in two teams of two.
     */
    public function isTeamGame(): bool
    {
        return $this->getPlayersNumber() === 4;
    }

    /**
     * Returns the team (1 or 2) of a player. With 2 players the team is the seat number; with 4 players
     * partners sit opposite each other (seats 1&3 are team 1, seats 2&4 are team 2).
     */
    public function teamOf(int $playerId): int
    {
        $seatNumber = (int) $this->getPlayerNoById($playerId);
        return $this->isTeamGame() ? (($seatNumber - 1) % 2) + 1 : $seatNumber;
    }

    /**
     * Returns the players of every team, keyed by team id (cached after the first call).
     *
     * @return array<int, int[]> team id => player ids in table order
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
     * Returns the ids of the players belonging to a team.
     *
     * @return int[]
     */
    public function playersOfTeam(int $teamId): array
    {
        return $this->getTeams()[$teamId];
    }

    /**
     * Returns a readable name for a team: the names of its players joined by " & ".
     */
    public function teamName(int $teamId): string
    {
        $playerNames = array_map(fn($playerId) => $this->getPlayerNameById($playerId), $this->playersOfTeam($teamId));
        return implode(' & ', $playerNames);
    }

    /**
     * Returns the current total score of a team. Every member has the same score, so the first one is read.
     */
    public function teamScore(int $teamId): int
    {
        $firstPlayerOfTeam = $this->playersOfTeam($teamId)[0];
        return $this->bga->playerScore->get($firstPlayerOfTeam);
    }

    /**
     * Returns all the players in turn order, starting from the player who leads the hand
     * (the player after the dealer).
     *
     * @return int[] player ids
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
     * Returns the id of the current dealer.
     */
    public function getDealerId(): int
    {
        return (int) $this->bga->globals->get('dealer');
    }

    /**
     * Returns the id of the player who leads every hand of the deal: the player after the dealer
     * in the BGA table order.
     */
    public function getFirstPlayerId(): int
    {
        return $this->getPlayerAfter($this->getDealerId());
    }

    /**
     * Number of cards dealt to each player at once: 3 with two players (several rounds of dealing),
     * 10 with four players (the whole deck is dealt in one go).
     */
    public function handSize(): int
    {
        return $this->isTeamGame() ? 10 : 3;
    }

    /**
     * Returns the number of points a team must reach to win the game.
     */
    public function targetScore(): int
    {
        return (int) $this->bga->globals->get('target');
    }

    ///////////////////////////////////////////////////
    //// Cards

    /**
     * Converts a card as returned by the Deck component into the format used by the client and by ScopaRules
     * (id, suit, rank).
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
     * Converts a list of Deck cards into card views, sorted by card id (so the order is always predictable).
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
     * Returns the short text used in the game log for a card, e.g. "7♦" or "K♠".
     */
    public function cardLabel(array $cardView): string
    {
        return self::RANK_LABEL_BY_RANK[$cardView['rank']] . self::SUIT_SYMBOL_BY_SUIT[$cardView['suit']];
    }

    /**
     * Returns the cards currently on the table as card views.
     *
     * @return array[]
     */
    public function tableCards(): array
    {
        return $this->cardViews($this->cards->getCardsInLocation(self::LOCATION_TABLE));
    }

    /**
     * For each card in the player's hand, lists the captures the player can make with it.
     * An empty list for a card means that card cannot capture (it can only be put on the table).
     *
     * @return array<int, int[][]> hand card id => list of legal captures (each a list of table card ids)
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
     * True when the card just played is the very last one of the deal (deck and all hands are empty).
     * A capture that empties the table on this play is NOT a scopa.
     */
    public function isLastPlayOfDeal(): bool
    {
        return $this->cards->countCardInLocation(self::LOCATION_DECK) === 0
            && $this->cards->countCardInLocation(self::LOCATION_HAND) === 0;
    }

    /**
     * Returns how many cards each player holds in their hand (public information).
     *
     * @return array<int, int> player id => number of cards in hand
     */
    public function handCounts(): array
    {
        $handCountByPlayerId = [];
        foreach (array_keys($this->loadPlayersBasicInfos()) as $playerId) {
            $handCountByPlayerId[(int) $playerId] = $this->cards->countCardInLocation(self::LOCATION_HAND, (int) $playerId);
        }
        return $handCountByPlayerId;
    }

    /**
     * Returns the number of scope made by each team in the current deal.
     *
     * @return array<int, int> team id => scope count
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
     * Adds one scopa to a team for the current deal.
     */
    public function addScopa(int $teamId): void
    {
        $scopeByTeam = $this->getScope();
        $scopeByTeam[$teamId]++;
        $this->bga->globals->set('scope', $scopeByTeam);
    }

    ///////////////////////////////////////////////////
    //// Framework entry points

    /**
     * Returns the game progression (0-100) shown to the players: the score of the leading team plus the
     * fraction of the current deal already played, relative to the target score.
     */
    public function getGameProgression()
    {
        $targetScore = max(1, $this->targetScore());
        $bestTeamScore = 0;
        foreach (array_keys($this->getTeams()) as $teamId) {
            $bestTeamScore = max($bestTeamScore, $this->teamScore($teamId));
        }

        $cardsStillToPlay = $this->cards->countCardInLocation(self::LOCATION_DECK) + $this->cards->countCardInLocation(self::LOCATION_HAND);
        $fractionOfDealPlayed = (self::DECK_SIZE - $cardsStillToPlay) / self::DECK_SIZE;

        $estimatedPoints = $bestTeamScore + $fractionOfDealPlayed * self::AVERAGE_POINTS_PER_DEAL;
        $progressionPercent = 100 * $estimatedPoints / ($targetScore + self::AVERAGE_POINTS_PER_DEAL);
        return (int) min(99, round($progressionPercent));
    }

    /**
     * Database migration hook: nothing to migrate yet (the game is not published).
     */
    public function upgradeTableDb($from_version)
    {
    }

    /**
     * Collects everything the client needs to draw the game, limited to what the given player is allowed to
     * see (only their own hand). Called on page load and refresh.
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
            $capturedCountByTeam[$teamId] = $this->cards->countCardInLocation(self::LOCATION_CAPTURED, $teamId);
            $teamNameByTeam[$teamId] = $this->teamName($teamId);
        }

        $gameData['isTeamGame'] = $this->isTeamGame();
        $gameData['teamNames'] = $teamNameByTeam;
        $gameData['target'] = $this->targetScore();
        $gameData['dealer'] = $this->getDealerId();
        $gameData['dealNo'] = (int) $this->bga->globals->get('deal_no');
        $gameData['hand'] = $this->cardViews($this->cards->getCardsInLocation(self::LOCATION_HAND, $currentPlayerId));
        $gameData['table'] = $this->tableCards();
        $gameData['handCounts'] = $this->handCounts();
        $gameData['deckCount'] = $this->cards->countCardInLocation(self::LOCATION_DECK);
        $gameData['capturedCounts'] = $capturedCountByTeam;
        $gameData['scope'] = $this->getScope();

        return $gameData;
    }

    /**
     * Prepares a new game: creates the players, decides the target score and the first dealer, creates the
     * 40 cards and initialises the statistics. The first deal is started by the NewDeal state.
     *
     * @param array $players  players registered at the table, keyed by player id
     * @param array $options  table options chosen at creation
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

        // Target score: "automatic" means 11 points with 2 players and 21 points with 4 players (teams).
        $targetScoreOption = (int) $this->bga->tableOptions->get(self::OPTION_TARGET_SCORE);
        $targetScore = match ($targetScoreOption) {
            1 => 11,
            2 => 21,
            3 => 31,
            default => count($players) === 4 ? 21 : 11,
        };
        $this->bga->globals->set('target', $targetScore);

        // Random first dealer; from the second deal on the dealer moves to the next player.
        $playerIds = array_keys($players);
        $this->bga->globals->set('dealer', (int) $playerIds[bga_rand(0, count($playerIds) - 1)]);
        $this->bga->globals->set('deal_no', 0);
        $this->bga->globals->set('scope', []);
        $this->bga->globals->set('last_capturer', 0);

        // The 40-card Italian deck: suit in `type` (1 denari, 2 coppe, 3 bastoni, 4 spade), rank in `type_arg`.
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
     * Debug helper (button in the Studio debug panel): jumps to a state by id, e.g. 95 = EndDeal.
     */
    public function debug_goToState(int $state = 3)
    {
        $this->gamestate->jumpToState($state);
    }
}
