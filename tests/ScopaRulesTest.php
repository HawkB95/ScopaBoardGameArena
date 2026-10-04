<?php

declare(strict_types=1);

require __DIR__ . '/../modules/php/Rules/ScopaRules.php';

use Bga\Games\ProjectBiagio\Rules\ScopaRules as R;

$fails = 0;
function check(string $name, $actual, $expected): void
{
    global $fails;
    if ($actual !== $expected) {
        $fails++;
        echo "FAIL $name\n  expected: " . json_encode($expected) . "\n  actual:   " . json_encode($actual) . "\n";
    } else {
        echo "ok   $name\n";
    }
}

$id = 0;
function c(int $rank, int $suit = 3): array
{
    global $id;
    return ['id' => ++$id, 'suit' => $suit, 'rank' => $rank];
}

// PDF example 1: table 3,5,8 ; playing 8 -> only the 8
$t = [c(3), c(5), c(8)];
check('same value forces single card', R::legalCaptures(8, $t), [[$t[2]['id']]]);

// PDF example 2: table 1,3,4,5 ; playing 9 -> {1,3,5} or {4,5}
$t = [c(1), c(3), c(4), c(5)];
check('9 on 1,3,4,5', R::legalCaptures(9, $t), [[$t[0]['id'], $t[1]['id'], $t[3]['id']], [$t[2]['id'], $t[3]['id']]]);

// PDF example 3: table 5,5,3,2 ; playing 10 -> {5,5} or {5,3,2} with either 5
$t = [c(5), c(5), c(3), c(2)];
check('10 on 5,5,3,2', count(R::legalCaptures(10, $t)), 3);

// two equal cards -> choose either, nothing else
$t = [c(4), c(4), c(2), c(2)];
check('same value choose either', R::legalCaptures(4, $t), [[$t[0]['id']], [$t[1]['id']]]);

// no capture
$t = [c(10), c(9)];
check('no capture', R::legalCaptures(2, $t), []);
check('empty table', R::legalCaptures(5, []), []);

$t = [['id' => 1, 'suit' => 1, 'rank' => 4], ['id' => 2, 'suit' => 1, 'rank' => 5]];
check('isLegalCapture ok', R::isLegalCapture(9, $t, [2, 1]), true);
$t = [['id' => 1, 'suit' => 1, 'rank' => 5], ['id' => 2, 'suit' => 1, 'rank' => 2], ['id' => 3, 'suit' => 1, 'rank' => 3]];
check('subset forbidden with same value', R::isLegalCapture(5, $t, [2, 3]), false);

// redeal
check('redeal 3 kings', R::needsRedeal([c(10), c(10), c(10), c(2)]), true);
check('no redeal 2 kings', R::needsRedeal([c(10), c(10), c(1), c(2)]), false);

// primiera
check('primiera complete', R::primieraScore([c(7, 1), c(6, 2), c(1, 3), c(5, 4), c(7, 2)]), 21 + 21 + 16 + 15);
check('primiera incomplete', R::primieraScore([c(7, 1), c(7, 2), c(7, 3)]), null);

// build a full deck split
$all = [];
foreach (R::SUITS as $s) {
    for ($r = 1; $r <= 10; $r++) {
        $all[] = [$r, $s];
    }
}
$mk = function (array $spec) {
    $cards = [];
    foreach ($spec as [$rank, $suit]) {
        $cards[] = c($rank, $suit);
    }
    return $cards;
};

// Team A: 7 denari (incl. settebello) + others; team B: bastoni + high coppe/spade
$a = [];
$b = [];
foreach ($all as [$r, $s]) {
    if ($s === 1 && $r <= 7) {
        $a[] = [$r, $s];
    } elseif ($s === 3 || ($s !== 1 && $r > 5)) {
        $b[] = [$r, $s];
    } else {
        $a[] = [$r, $s];
    }
}
$res = R::scoreDeal([0 => ['cards' => $mk($a), 'scope' => 2], 1 => ['cards' => $mk($b), 'scope' => 1]]);
check('A denari+settebello', [$res[0]['denari'], $res[0]['settebello'], $res[1]['settebello']], [1, 1, 0]);
check('cards sum to 40', $res[0]['cardCount'] + $res[1]['cardCount'], 40);
check('total A includes scope', $res[0]['total'], 2 + $res[0]['settebello'] + $res[0]['primiera'] + $res[0]['denari'] + $res[0]['carte']);

// 20-20 split: nobody gets carte
$a = [];
$b = [];
foreach ($all as $i => $card) {
    if ($i % 2) {
        $b[] = $card;
    } else {
        $a[] = $card;
    }
}
$res = R::scoreDeal([0 => ['cards' => $mk($a), 'scope' => 0], 1 => ['cards' => $mk($b), 'scope' => 0]]);
check('carte tie none', [$res[0]['carte'], $res[1]['carte']], [0, 0]);

// game winner
check('winner reached target', R::gameWinner([0 => 12, 1 => 8], 11), 0);
check('no winner below target', R::gameWinner([0 => 9, 1 => 8], 11), null);
check('no winner on tie', R::gameWinner([0 => 11, 1 => 11], 11), null);

echo $fails === 0 ? "\nALL PASS\n" : "\n$fails FAILED\n";
exit($fails === 0 ? 0 : 1);
