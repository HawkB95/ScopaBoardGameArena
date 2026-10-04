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
 * Scopa: main game logic and shared helpers. Pure rules live in Rules/ScopaRules.php.
 */
declare(strict_types=1);

namespace Bga\Games\ProjectBiagio;

use Bga\Games\ProjectBiagio\Rules\ScopaRules;
use Bga\Games\ProjectBiagio\States\NewDeal;

class Game extends \Bga\GameFramework\Table
{
    public const OPTION_TARGET_SCORE = 100;

    public const LOC_DECK = 'deck';
    public const LOC_HAND = 'hand';
    public const LOC_TABLE = 'table';
    public const LOC_CAPTURED = 'captured';

    private const SUIT_SYMBOLS = [1 => '♦', 2 => '♥', 3 => '♣', 4 => '♠'];
    private const RANK_LABELS = [1 => 'A', 2 => '2', 3 => '3', 4 => '4', 5 => '5', 6 => '6', 7 => '7', 8 => 'J', 9 => 'Q', 10 => 'K'];

    public \Bga\GameFramework\Components\Deck $cards;

    /** @var array<int, int[]>|null team id => player ids (in table order) */
    private ?array $teamsCache = null;

    public function __construct()
    {
        parent::__construct();

        $this->cards = $this->bga->deckFactory->createDeck('scopa_card');
    }

    ///////////////////////////////////////////////////
    //// Teams and seating

    public function isTeamGame(): bool
    {
        return $this->getPlayersNumber() === 4;
    }

    /** Teams are 1 and 2. With 2 players each player is a team; with 4 players seats 1&3 and 2&4 are partners. */
    public function teamOf(int $playerId): int
    {
        $no = (int) $this->getPlayerNoById($playerId);
        return $this->isTeamGame() ? (($no - 1) % 2) + 1 : $no;
    }

    /** @return array<int, int[]> */
    public function getTeams(): array
    {
        if ($this->teamsCache === null) {
            $teams = [];
            $rows = $this->getObjectListFromDB("SELECT `player_id`, `player_no` FROM `player` ORDER BY `player_no`");
            foreach ($rows as $row) {
                $teams[$this->teamOf((int) $row['player_id'])][] = (int) $row['player_id'];
            }
            ksort($teams);
            $this->teamsCache = $teams;
        }
        return $this->teamsCache;
    }

    /** @return int[] */
    public function playersOfTeam(int $team): array
    {
        return $this->getTeams()[$team];
    }

    public function teamName(int $team): string
    {
        $names = array_map(fn($pid) => $this->getPlayerNameById($pid), $this->playersOfTeam($team));
        return implode(' & ', $names);
    }

    public function teamScore(int $team): int
    {
        return $this->bga->playerScore->get($this->playersOfTeam($team)[0]);
    }

    /** @return int[] player ids in table order, starting from the first player of the hand (after the dealer) */
    public function playersFromFirst(): array
    {
        $order = [];
        $pid = $this->getFirstPlayerId();
        for ($i = 0; $i < $this->getPlayersNumber(); $i++) {
            $order[] = $pid;
            $pid = $this->getPlayerAfter($pid);
        }
        return $order;
    }

    public function getDealerId(): int
    {
        return (int) $this->bga->globals->get('dealer');
    }

    public function getFirstPlayerId(): int
    {
        return $this->getPlayerAfter($this->getDealerId());
    }

    public function handSize(): int
    {
        return $this->isTeamGame() ? 10 : 3;
    }

    public function targetScore(): int
    {
        return (int) $this->bga->globals->get('target');
    }

    ///////////////////////////////////////////////////
    //// Cards

    /** Card in the format exchanged with the client and with ScopaRules. */
    public function cardView(array $card): array
    {
        return [
            'id' => (int) $card['id'],
            'suit' => (int) $card['type'],
            'rank' => (int) $card['type_arg'],
        ];
    }

    /** @return array[] */
    public function cardViews(array $cards): array
    {
        $views = array_map(fn($c) => $this->cardView($c), array_values($cards));
        usort($views, fn($a, $b) => $a['id'] <=> $b['id']);
        return $views;
    }

    public function cardLabel(array $view): string
    {
        return self::RANK_LABELS[$view['rank']] . self::SUIT_SYMBOLS[$view['suit']];
    }

    /** @return array[] */
    public function tableCards(): array
    {
        return $this->cardViews($this->cards->getCardsInLocation(self::LOC_TABLE));
    }

    /** @return array<int, int[][]> hand card id => list of legal captures (empty list = discard only) */
    public function capturesByCard(int $playerId): array
    {
        $table = $this->tableCards();
        $result = [];
        foreach ($this->cards->getCardsInLocation(self::LOC_HAND, $playerId) as $card) {
            $result[(int) $card['id']] = ScopaRules::legalCaptures((int) $card['type_arg'], $table);
        }
        return $result;
    }

    public function isLastPlayOfDeal(): bool
    {
        return $this->cards->countCardInLocation(self::LOC_DECK) === 0
            && $this->cards->countCardInLocation(self::LOC_HAND) === 0;
    }

    /** @return array<int, int> player id => cards in hand */
    public function handCounts(): array
    {
        $counts = [];
        foreach (array_keys($this->loadPlayersBasicInfos()) as $pid) {
            $counts[(int) $pid] = $this->cards->countCardInLocation(self::LOC_HAND, (int) $pid);
        }
        return $counts;
    }

    /** @return array<int, int> team id => scope made in the current deal */
    public function getScope(): array
    {
        $stored = $this->bga->globals->get('scope', []);
        $scope = [];
        foreach (array_keys($this->getTeams()) as $team) {
            $scope[$team] = (int) ($stored[$team] ?? 0);
        }
        return $scope;
    }

    public function addScopa(int $team): void
    {
        $scope = $this->getScope();
        $scope[$team]++;
        $this->bga->globals->set('scope', $scope);
    }

    ///////////////////////////////////////////////////
    //// Framework entry points

    /**
     * Progression: points of the leading team plus the fraction of the current deal, relative to the target.
     */
    public function getGameProgression()
    {
        $target = max(1, $this->targetScore());
        $best = 0;
        foreach (array_keys($this->getTeams()) as $team) {
            $best = max($best, $this->teamScore($team));
        }
        $remaining = $this->cards->countCardInLocation(self::LOC_DECK) + $this->cards->countCardInLocation(self::LOC_HAND);
        $dealFraction = (40 - $remaining) / 40;
        return (int) min(99, round(100 * ($best + $dealFraction * 3) / ($target + 3)));
    }

    public function upgradeTableDb($from_version)
    {
    }

    protected function getAllDatas(int $currentPlayerId): array
    {
        $result = [];

        $result['players'] = $this->getCollectionFromDb(
            "SELECT `player_id` AS `id`, `player_score` AS `score` FROM `player`"
        );
        foreach ($result['players'] as $pid => &$player) {
            $player['team'] = $this->teamOf((int) $pid);
        }
        unset($player);

        $captured = [];
        foreach (array_keys($this->getTeams()) as $team) {
            $captured[$team] = $this->cards->countCardInLocation(self::LOC_CAPTURED, $team);
        }

        $result['isTeamGame'] = $this->isTeamGame();
        $result['teamNames'] = [];
        foreach (array_keys($this->getTeams()) as $team) {
            $result['teamNames'][$team] = $this->teamName($team);
        }
        $result['target'] = $this->targetScore();
        $result['dealer'] = $this->getDealerId();
        $result['dealNo'] = (int) $this->bga->globals->get('deal_no');
        $result['hand'] = $this->cardViews($this->cards->getCardsInLocation(self::LOC_HAND, $currentPlayerId));
        $result['table'] = $this->tableCards();
        $result['handCounts'] = $this->handCounts();
        $result['deckCount'] = $this->cards->countCardInLocation(self::LOC_DECK);
        $result['capturedCounts'] = $captured;
        $result['scope'] = $this->getScope();

        return $result;
    }

    protected function setupNewGame($players, $options = [])
    {
        $gameinfos = $this->getGameinfos();
        $default_colors = $gameinfos['player_colors'];

        $query_values = [];
        foreach ($players as $player_id => $player) {
            $query_values[] = vsprintf("(%s, '%s', '%s')", [
                $player_id,
                array_shift($default_colors),
                addslashes($player["player_name"]),
            ]);
        }

        $this->DbQuery(
            sprintf(
                "INSERT INTO `player` (`player_id`, `player_color`, `player_name`) VALUES %s",
                implode(",", $query_values)
            )
        );

        $this->reattributeColorsBasedOnPreferences($players, $gameinfos["player_colors"]);
        $this->reloadPlayersBasicInfos();

        // Target score: automatic = 11 (2 players) or 21 (4 players, teams).
        $option = (int) $this->bga->tableOptions->get(self::OPTION_TARGET_SCORE);
        $target = match ($option) {
            1 => 11,
            2 => 21,
            3 => 31,
            default => count($players) === 4 ? 21 : 11,
        };
        $this->bga->globals->set('target', $target);

        // Random first dealer; the dealer rotates from the second deal on.
        $playerIds = array_keys($players);
        $this->bga->globals->set('dealer', (int) $playerIds[bga_rand(0, count($playerIds) - 1)]);
        $this->bga->globals->set('deal_no', 0);
        $this->bga->globals->set('scope', []);
        $this->bga->globals->set('last_capturer', 0);

        // The 40-card Italian deck: suit in type (1 denari, 2 coppe, 3 bastoni, 4 spade), rank in type_arg.
        $cards = [];
        foreach ([1, 2, 3, 4] as $suit) {
            for ($rank = 1; $rank <= 10; $rank++) {
                $cards[] = ['type' => $suit, 'type_arg' => $rank, 'nbr' => 1];
            }
        }
        $this->cards->createCards($cards, self::LOC_DECK);

        $this->bga->tableStats->init('deals_played', 0);
        $this->bga->playerStats->init([
            'scope_count', 'settebello_count', 'primiera_count', 'denari_count',
            'carte_count', 'captures_count', 'discards_count',
        ], 0);

        return NewDeal::class;
    }

    /**
     * Debug helper: jump to a state by id (e.g. 95 = EndDeal).
     */
    public function debug_goToState(int $state = 3)
    {
        $this->gamestate->jumpToState($state);
    }
}
