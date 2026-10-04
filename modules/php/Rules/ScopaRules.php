<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\Rules;

/**
 * Pure Scopa rules: no dependency on the BGA framework, so it can be unit-tested with plain php
 * (see tests/ScopaRulesTest.php).
 *
 * A "card" is always an array with the keys:
 *  - 'id'   : unique card id
 *  - 'suit' : 1 denari, 2 coppe, 3 bastoni, 4 spade
 *  - 'rank' : 1..10 (Ace = 1, Fante/Jack = 8, Cavallo/Queen = 9, Re/King = 10); the rank is also the capture value
 */
class ScopaRules
{
    public const SUIT_DENARI = 1;
    public const SUIT_COPPE = 2;
    public const SUIT_BASTONI = 3;
    public const SUIT_SPADE = 4;

    public const ALL_SUITS = [self::SUIT_DENARI, self::SUIT_COPPE, self::SUIT_BASTONI, self::SUIT_SPADE];

    public const RANK_SEVEN = 7;
    public const RANK_KING = 10;

    /** Minimum number of denari cards needed to win the "denari" point. */
    public const MIN_DENARI_FOR_POINT = 6;

    /** Minimum number of captured cards needed to win the "carte" point. */
    public const MIN_CARDS_FOR_POINT = 21;

    /** Value of each rank when computing the primiera (rank => primiera value). */
    private const PRIMIERA_VALUE_BY_RANK = [
        1 => 16, 2 => 12, 3 => 13, 4 => 14, 5 => 15, 6 => 18, 7 => 21, 8 => 10, 9 => 10, 10 => 10,
    ];

    /**
     * Returns every capture the player may legally make when playing a card of the given rank on the table.
     *
     * - If the table holds card(s) of the same rank, the player MUST take exactly one of them
     *   (so the result is one single-card capture for each such card).
     * - Otherwise any group of table cards whose ranks sum to the played rank is allowed.
     * - An empty result means no capture is possible: the card can only be put on the table.
     *
     * @param int     $playedRank the rank of the card the player is playing
     * @param array[] $tableCards the cards currently on the table
     * @return int[][] list of captures, each one a list of table card ids sorted by id
     */
    public static function legalCaptures(int $playedRank, array $tableCards): array
    {
        $sameRankCaptures = [];
        foreach ($tableCards as $tableCard) {
            if ((int) $tableCard['rank'] === $playedRank) {
                $sameRankCaptures[] = [(int) $tableCard['id']];
            }
        }
        if (count($sameRankCaptures) > 0) {
            return $sameRankCaptures;
        }

        $tableCardsSortedById = array_values($tableCards);
        usort($tableCardsSortedById, fn($first, $second) => (int) $first['id'] <=> (int) $second['id']);

        $allMatchingSubsets = [];
        self::collectSubsetsWithSum($tableCardsSortedById, 0, $playedRank, [], $allMatchingSubsets);
        return $allMatchingSubsets;
    }

    /**
     * Recursive helper of legalCaptures: finds every non-empty group of cards whose ranks sum to a target value.
     * Each group is built by always choosing cards in increasing index order, so no group appears twice.
     *
     * @param array[] $candidateCards   the table cards, sorted by id
     * @param int     $startIndex       first index of $candidateCards that may still be added to the group
     * @param int     $remainingValue   value still missing to reach the target (0 = group complete)
     * @param int[]   $currentGroupIds  ids of the cards already in the group being built
     * @param int[][] $matchingGroups   (output) every complete group found
     */
    private static function collectSubsetsWithSum(
        array $candidateCards,
        int $startIndex,
        int $remainingValue,
        array $currentGroupIds,
        array &$matchingGroups
    ): void {
        if ($remainingValue === 0) {
            if (count($currentGroupIds) > 0) {
                $matchingGroups[] = $currentGroupIds;
            }
            return;
        }
        for ($index = $startIndex; $index < count($candidateCards); $index++) {
            $cardValue = (int) $candidateCards[$index]['rank'];
            if ($cardValue <= $remainingValue) {
                $groupWithThisCard = array_merge($currentGroupIds, [(int) $candidateCards[$index]['id']]);
                self::collectSubsetsWithSum($candidateCards, $index + 1, $remainingValue - $cardValue, $groupWithThisCard, $matchingGroups);
            }
        }
    }

    /**
     * Tells whether the chosen group of table cards is one of the legal captures for the played rank.
     * The order of the ids is irrelevant.
     *
     * @param int     $playedRank  the rank of the card being played
     * @param array[] $tableCards  the cards currently on the table
     * @param int[]   $chosenCardIds ids of the table cards the player wants to capture
     */
    public static function isLegalCapture(int $playedRank, array $tableCards, array $chosenCardIds): bool
    {
        $sortedChosenIds = array_map('intval', $chosenCardIds);
        sort($sortedChosenIds);
        foreach (self::legalCaptures($playedRank, $tableCards) as $legalCaptureIds) {
            if ($legalCaptureIds === $sortedChosenIds) {
                return true;
            }
        }
        return false;
    }

    /**
     * With two players, 4 cards are dealt face up; if three or more of them are kings no scopa would ever
     * be possible, so the deal must be redone.
     *
     * @param array[] $initialTableCards the 4 cards dealt on the table
     */
    public static function needsRedeal(array $initialTableCards): bool
    {
        $kingsCount = 0;
        foreach ($initialTableCards as $tableCard) {
            if ((int) $tableCard['rank'] === self::RANK_KING) {
                $kingsCount++;
            }
        }
        return $kingsCount >= 3;
    }

    /**
     * Computes the primiera total of a set of captured cards: the sum, over the four suits, of the best
     * primiera value in that suit.
     *
     * @param array[] $capturedCards all the cards captured by a team
     * @return int|null the primiera total, or null if at least one suit is missing (the team cannot compete)
     */
    public static function primieraScore(array $capturedCards): ?int
    {
        $bestValueBySuit = [];
        foreach ($capturedCards as $capturedCard) {
            $cardValue = self::PRIMIERA_VALUE_BY_RANK[(int) $capturedCard['rank']];
            $suit = (int) $capturedCard['suit'];
            if (!isset($bestValueBySuit[$suit]) || $cardValue > $bestValueBySuit[$suit]) {
                $bestValueBySuit[$suit] = $cardValue;
            }
        }
        foreach (self::ALL_SUITS as $suit) {
            if (!isset($bestValueBySuit[$suit])) {
                return null;
            }
        }
        return array_sum($bestValueBySuit);
    }

    /**
     * Scores a finished deal. Points: scope (1 each), settebello, primiera, denari, carte.
     * For primiera, denari and carte a tie gives the point to nobody.
     *
     * @param array<int|string, array{cards: array[], scope: int}> $capturedByTeam
     *        for each team id: all its captured cards and the number of scope it made
     * @return array<int|string, array{scope:int, settebello:int, primiera:int, denari:int, carte:int, total:int,
     *                                 cardCount:int, denariCount:int, primieraValue:?int}>
     *         for each team id: points per category, total points and the counts used to compute them
     */
    public static function scoreDeal(array $capturedByTeam): array
    {
        $scoreByTeam = [];
        foreach ($capturedByTeam as $teamId => $teamCaptures) {
            $capturedCards = $teamCaptures['cards'];
            $denariCount = 0;
            $hasSettebello = false;
            foreach ($capturedCards as $capturedCard) {
                if ((int) $capturedCard['suit'] === self::SUIT_DENARI) {
                    $denariCount++;
                    if ((int) $capturedCard['rank'] === self::RANK_SEVEN) {
                        $hasSettebello = true;
                    }
                }
            }
            $scoreByTeam[$teamId] = [
                'scope' => (int) $teamCaptures['scope'],
                'settebello' => $hasSettebello ? 1 : 0,
                'primiera' => 0,
                'denari' => $denariCount >= self::MIN_DENARI_FOR_POINT ? 1 : 0,
                'carte' => count($capturedCards) >= self::MIN_CARDS_FOR_POINT ? 1 : 0,
                'total' => 0,
                'cardCount' => count($capturedCards),
                'denariCount' => $denariCount,
                'primieraValue' => self::primieraScore($capturedCards),
            ];
        }

        $primieraWinnerId = self::findPrimieraWinner($scoreByTeam);
        if ($primieraWinnerId !== null) {
            $scoreByTeam[$primieraWinnerId]['primiera'] = 1;
        }

        foreach ($scoreByTeam as $teamId => $teamScore) {
            $scoreByTeam[$teamId]['total'] = $teamScore['scope'] + $teamScore['settebello']
                + $teamScore['primiera'] + $teamScore['denari'] + $teamScore['carte'];
        }
        return $scoreByTeam;
    }

    /**
     * Finds the team that wins the primiera point: the one with the highest complete primiera total.
     * Teams missing a suit cannot win; a tie for the best total gives the point to nobody.
     *
     * @param array $scoreByTeam per team, an array containing at least 'primieraValue' (int or null)
     * @return int|string|null the winning team id, or null if nobody wins the point
     */
    private static function findPrimieraWinner(array $scoreByTeam)
    {
        $bestPrimieraValue = null;
        $teamsWithBestValue = [];
        foreach ($scoreByTeam as $teamId => $teamScore) {
            $primieraValue = $teamScore['primieraValue'];
            if ($primieraValue === null) {
                continue;
            }
            if ($bestPrimieraValue === null || $primieraValue > $bestPrimieraValue) {
                $bestPrimieraValue = $primieraValue;
                $teamsWithBestValue = [$teamId];
            } elseif ($primieraValue === $bestPrimieraValue) {
                $teamsWithBestValue[] = $teamId;
            }
        }
        return count($teamsWithBestValue) === 1 ? $teamsWithBestValue[0] : null;
    }

    /**
     * Decides whether the game is over: a team wins when it has reached the target score AND is strictly
     * ahead of every other team. Otherwise another deal is played (this also handles ties).
     *
     * @param array<int|string, int> $totalScoreByTeam current total score of each team
     * @param int                    $targetScore      points needed to win
     * @return int|string|null the winning team id, or null if the game goes on
     */
    public static function gameWinner(array $totalScoreByTeam, int $targetScore)
    {
        arsort($totalScoreByTeam);
        $teamIdsFromBestToWorst = array_keys($totalScoreByTeam);
        $scoresFromBestToWorst = array_values($totalScoreByTeam);

        $bestScore = $scoresFromBestToWorst[0];
        if ($bestScore < $targetScore) {
            return null;
        }
        $isTiedForFirstPlace = count($scoresFromBestToWorst) > 1 && $bestScore === $scoresFromBestToWorst[1];
        if ($isTiedForFirstPlace) {
            return null;
        }
        return $teamIdsFromBestToWorst[0];
    }
}
