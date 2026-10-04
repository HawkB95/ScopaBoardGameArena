<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\Rules;

/**
 * Pure Scopa rules: no dependency on the BGA framework (unit-testable with plain php).
 *
 * A card is an array with keys 'id', 'suit' (1..4) and 'rank' (1..10, rank == capture value).
 */
class ScopaRules
{
    public const SUIT_DENARI = 1;
    public const SUIT_COPPE = 2;
    public const SUIT_BASTONI = 3;
    public const SUIT_SPADE = 4;

    public const SUITS = [self::SUIT_DENARI, self::SUIT_COPPE, self::SUIT_BASTONI, self::SUIT_SPADE];
    public const RANK_SEVEN = 7;
    public const RANK_KING = 10;

    private const PRIMIERA_VALUES = [
        1 => 16, 2 => 12, 3 => 13, 4 => 14, 5 => 15, 6 => 18, 7 => 21, 8 => 10, 9 => 10, 10 => 10,
    ];

    /**
     * Legal captures when playing a card of $rank onto $table (list of cards).
     * Returns a list of captures, each a sorted list of table card ids. Empty list = no capture (discard).
     * If a table card has the same rank, only single-card captures of that rank are allowed.
     *
     * @return int[][]
     */
    public static function legalCaptures(int $rank, array $table): array
    {
        $same = [];
        foreach ($table as $card) {
            if ((int) $card['rank'] === $rank) {
                $same[] = [(int) $card['id']];
            }
        }
        if (count($same) > 0) {
            return $same;
        }

        $cards = array_values($table);
        usort($cards, fn($a, $b) => (int) $a['id'] <=> (int) $b['id']);
        $result = [];
        self::collectSubsets($cards, 0, $rank, [], $result);
        return $result;
    }

    private static function collectSubsets(array $cards, int $from, int $remaining, array $current, array &$result): void
    {
        if ($remaining === 0) {
            if (count($current) > 0) {
                $result[] = $current;
            }
            return;
        }
        for ($i = $from; $i < count($cards); $i++) {
            $value = (int) $cards[$i]['rank'];
            if ($value <= $remaining) {
                self::collectSubsets($cards, $i + 1, $remaining - $value, array_merge($current, [(int) $cards[$i]['id']]), $result);
            }
        }
    }

    public static function isLegalCapture(int $rank, array $table, array $captureIds): bool
    {
        $ids = array_map('intval', $captureIds);
        sort($ids);
        foreach (self::legalCaptures($rank, $table) as $capture) {
            if ($capture === $ids) {
                return true;
            }
        }
        return false;
    }

    /** Dealing 4 table cards with 3+ kings forces a redeal. */
    public static function needsRedeal(array $tableCards): bool
    {
        $kings = 0;
        foreach ($tableCards as $card) {
            if ((int) $card['rank'] === self::RANK_KING) {
                $kings++;
            }
        }
        return $kings >= 3;
    }

    /** Primiera total, or null if the suit set is incomplete. */
    public static function primieraScore(array $cards): ?int
    {
        $best = [];
        foreach ($cards as $card) {
            $value = self::PRIMIERA_VALUES[(int) $card['rank']];
            $suit = (int) $card['suit'];
            if (!isset($best[$suit]) || $value > $best[$suit]) {
                $best[$suit] = $value;
            }
        }
        foreach (self::SUITS as $suit) {
            if (!isset($best[$suit])) {
                return null;
            }
        }
        return array_sum($best);
    }

    /**
     * Score a deal.
     *
     * @param array<int|string, array{cards: array, scope: int}> $teams keyed by team id
     * @return array<int|string, array{scope:int, settebello:int, primiera:int, denari:int, carte:int, total:int,
     *                                 cardCount:int, denariCount:int, primieraValue:?int}>
     */
    public static function scoreDeal(array $teams): array
    {
        $stats = [];
        foreach ($teams as $teamId => $team) {
            $cards = $team['cards'];
            $denari = 0;
            $settebello = 0;
            foreach ($cards as $card) {
                if ((int) $card['suit'] === self::SUIT_DENARI) {
                    $denari++;
                    if ((int) $card['rank'] === self::RANK_SEVEN) {
                        $settebello = 1;
                    }
                }
            }
            $stats[$teamId] = [
                'scope' => (int) $team['scope'],
                'settebello' => $settebello,
                'primiera' => 0,
                'denari' => 0,
                'carte' => 0,
                'total' => 0,
                'cardCount' => count($cards),
                'denariCount' => $denari,
                'primieraValue' => self::primieraScore($cards),
            ];
        }

        // Denari (>= 6) and carte (>= 21) cannot be held by two teams at once with 2 teams.
        foreach ($stats as $teamId => $s) {
            $stats[$teamId]['denari'] = $s['denariCount'] >= 6 ? 1 : 0;
            $stats[$teamId]['carte'] = $s['cardCount'] >= 21 ? 1 : 0;
        }

        // Primiera: highest complete total, ties give nothing.
        $bestValue = null;
        $bestTeams = [];
        foreach ($stats as $teamId => $s) {
            $value = $s['primieraValue'];
            if ($value === null) {
                continue;
            }
            if ($bestValue === null || $value > $bestValue) {
                $bestValue = $value;
                $bestTeams = [$teamId];
            } elseif ($value === $bestValue) {
                $bestTeams[] = $teamId;
            }
        }
        if (count($bestTeams) === 1) {
            $stats[$bestTeams[0]]['primiera'] = 1;
        }

        foreach ($stats as $teamId => $s) {
            $stats[$teamId]['total'] = $s['scope'] + $s['settebello'] + $s['primiera'] + $s['denari'] + $s['carte'];
        }
        return $stats;
    }

    /** Game over when someone reached the target and leads strictly. Returns the winning team id or null. */
    public static function gameWinner(array $totals, int $target)
    {
        arsort($totals);
        $ids = array_keys($totals);
        $values = array_values($totals);
        if ($values[0] < $target) {
            return null;
        }
        if (count($values) > 1 && $values[0] === $values[1]) {
            return null;
        }
        return $ids[0];
    }
}
