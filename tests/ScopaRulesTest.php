<?php

declare(strict_types=1);

/**
 * Unit tests of the pure rules engine. Run with: php tests/ScopaRulesTest.php
 * Exits with status 0 if every check passes, 1 otherwise.
 */

require __DIR__ . '/../modules/php/Rules/ScopaRules.php';

use Bga\Games\ProjectBiagio\Rules\ScopaRules;

$failedChecksCount = 0;
$lastGeneratedCardId = 0;

/**
 * Compares the actual value with the expected one and prints "ok" or "FAIL" with both values.
 */
function check(string $checkName, $actualValue, $expectedValue): void
{
    global $failedChecksCount;
    if ($actualValue !== $expectedValue) {
        $failedChecksCount++;
        echo "FAIL $checkName\n  expected: " . json_encode($expectedValue) . "\n  actual:   " . json_encode($actualValue) . "\n";
    } else {
        echo "ok   $checkName\n";
    }
}

/**
 * Creates a card with a new unique id (default suit: bastoni, which has no special meaning for scoring).
 */
function makeCard(int $rank, int $suit = ScopaRules::SUIT_BASTONI): array
{
    global $lastGeneratedCardId;
    return ['id' => ++$lastGeneratedCardId, 'suit' => $suit, 'rank' => $rank];
}

/**
 * Creates the cards described by a list of [rank, suit] pairs.
 */
function makeCardsFromRankSuitPairs(array $rankSuitPairs): array
{
    $cards = [];
    foreach ($rankSuitPairs as [$rank, $suit]) {
        $cards[] = makeCard($rank, $suit);
    }
    return $cards;
}

// ---- Legal captures: the three examples of the official rules ----

// Example 1: table 3, 5, 8 and the player plays an 8 -> can only take the 8.
$tableCards = [makeCard(3), makeCard(5), makeCard(8)];
check('same value forces the single card', ScopaRules::legalCaptures(8, $tableCards), [[$tableCards[2]['id']]]);

// Example 2: table 1, 3, 4, 5 and the player plays a 9 -> {1,3,5} or {4,5}.
$tableCards = [makeCard(1), makeCard(3), makeCard(4), makeCard(5)];
check(
    '9 on 1,3,4,5',
    ScopaRules::legalCaptures(9, $tableCards),
    [[$tableCards[0]['id'], $tableCards[1]['id'], $tableCards[3]['id']], [$tableCards[2]['id'], $tableCards[3]['id']]]
);

// Example 3: table 5, 5, 3, 2 and the player plays a 10 -> {5,5} or {5,3,2} with either 5 (3 captures).
$tableCards = [makeCard(5), makeCard(5), makeCard(3), makeCard(2)];
check('10 on 5,5,3,2', count(ScopaRules::legalCaptures(10, $tableCards)), 3);

// Two cards with the same rank: the player chooses one, no sum is allowed.
$tableCards = [makeCard(4), makeCard(4), makeCard(2), makeCard(2)];
check('same value: choose either card', ScopaRules::legalCaptures(4, $tableCards), [[$tableCards[0]['id']], [$tableCards[1]['id']]]);

// No capture possible.
$tableCards = [makeCard(10), makeCard(9)];
check('no capture possible', ScopaRules::legalCaptures(2, $tableCards), []);
check('empty table', ScopaRules::legalCaptures(5, []), []);

// ---- Validation of a capture chosen by the player ----

$tableCards = [['id' => 1, 'suit' => 1, 'rank' => 4], ['id' => 2, 'suit' => 1, 'rank' => 5]];
check('legal capture, ids in any order', ScopaRules::isLegalCapture(9, $tableCards, [2, 1]), true);

$tableCards = [['id' => 1, 'suit' => 1, 'rank' => 5], ['id' => 2, 'suit' => 1, 'rank' => 2], ['id' => 3, 'suit' => 1, 'rank' => 3]];
check('sum forbidden when a card of the same value exists', ScopaRules::isLegalCapture(5, $tableCards, [2, 3]), false);

// ---- Redeal ----

check('redeal with 3 kings', ScopaRules::needsRedeal([makeCard(10), makeCard(10), makeCard(10), makeCard(2)]), true);
check('no redeal with 2 kings', ScopaRules::needsRedeal([makeCard(10), makeCard(10), makeCard(1), makeCard(2)]), false);

// ---- Primiera ----

check(
    'primiera with all four suits',
    ScopaRules::primieraScore([makeCard(7, 1), makeCard(6, 2), makeCard(1, 3), makeCard(5, 4), makeCard(7, 2)]),
    21 + 21 + 16 + 15
);
check('primiera with a missing suit', ScopaRules::primieraScore([makeCard(7, 1), makeCard(7, 2), makeCard(7, 3)]), null);

// ---- Deal scoring ----

// The whole 40-card deck as [rank, suit] pairs.
$fullDeckRankSuitPairs = [];
foreach (ScopaRules::ALL_SUITS as $suit) {
    for ($rank = 1; $rank <= 10; $rank++) {
        $fullDeckRankSuitPairs[] = [$rank, $suit];
    }
}

// Team A takes denari 1..7 (so it has the settebello and enough denari) plus coppe/spade up to 5; team B takes the rest.
$teamACardPairs = [];
$teamBCardPairs = [];
foreach ($fullDeckRankSuitPairs as [$rank, $suit]) {
    $isLowDenari = $suit === ScopaRules::SUIT_DENARI && $rank <= 7;
    $isBastoniOrHighCard = $suit === ScopaRules::SUIT_BASTONI || ($suit !== ScopaRules::SUIT_DENARI && $rank > 5);
    if ($isLowDenari) {
        $teamACardPairs[] = [$rank, $suit];
    } elseif ($isBastoniOrHighCard) {
        $teamBCardPairs[] = [$rank, $suit];
    } else {
        $teamACardPairs[] = [$rank, $suit];
    }
}
$dealScores = ScopaRules::scoreDeal([
    0 => ['cards' => makeCardsFromRankSuitPairs($teamACardPairs), 'scope' => 2],
    1 => ['cards' => makeCardsFromRankSuitPairs($teamBCardPairs), 'scope' => 1],
]);
check(
    'team A has denari and settebello, team B has no settebello',
    [$dealScores[0]['denari'], $dealScores[0]['settebello'], $dealScores[1]['settebello']],
    [1, 1, 0]
);
check('the two teams hold all 40 cards', $dealScores[0]['cardCount'] + $dealScores[1]['cardCount'], 40);
check(
    'team A total includes its scope',
    $dealScores[0]['total'],
    2 + $dealScores[0]['settebello'] + $dealScores[0]['primiera'] + $dealScores[0]['denari'] + $dealScores[0]['carte']
);

// 20 cards each: nobody gets the "carte" point.
$evenSplitTeamACardPairs = [];
$evenSplitTeamBCardPairs = [];
foreach ($fullDeckRankSuitPairs as $position => $rankSuitPair) {
    if ($position % 2 === 1) {
        $evenSplitTeamBCardPairs[] = $rankSuitPair;
    } else {
        $evenSplitTeamACardPairs[] = $rankSuitPair;
    }
}
$dealScores = ScopaRules::scoreDeal([
    0 => ['cards' => makeCardsFromRankSuitPairs($evenSplitTeamACardPairs), 'scope' => 0],
    1 => ['cards' => makeCardsFromRankSuitPairs($evenSplitTeamBCardPairs), 'scope' => 0],
]);
check('20-20 split: nobody gets carte', [$dealScores[0]['carte'], $dealScores[1]['carte']], [0, 0]);

// ---- End of game ----

check('winner: target reached and strictly ahead', ScopaRules::gameWinner([0 => 12, 1 => 8], 11), 0);
check('no winner below the target', ScopaRules::gameWinner([0 => 9, 1 => 8], 11), null);
check('no winner when tied at the top', ScopaRules::gameWinner([0 => 11, 1 => 11], 11), null);

echo $failedChecksCount === 0 ? "\nALL PASS\n" : "\n$failedChecksCount FAILED\n";
exit($failedChecksCount === 0 ? 0 : 1);
