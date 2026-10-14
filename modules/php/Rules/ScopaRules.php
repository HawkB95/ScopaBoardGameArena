<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio\Rules;

/**
 * Regole pure della Scopa: nessuna dipendenza dal framework BGA, quindi si possono provare con il solo php
 * (vedi tests/ScopaRulesTest.php).
 *
 * Una "carta" è sempre un array con le chiavi:
 *  - 'id'   : identificativo univoco della carta
 *  - 'suit' : seme, 1 denari, 2 coppe, 3 bastoni, 4 spade
 *  - 'rank' : valore 1..10 (Asso = 1, Fante = 8, Cavallo = 9, Re = 10); è anche il valore usato per le prese
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

    /** Numero minimo di carte di denari per vincere il punto "denari". */
    public const MIN_DENARI_FOR_POINT = 6;

    /** Numero minimo di carte prese per vincere il punto "carte". */
    public const MIN_CARDS_FOR_POINT = 21;

    /** Valore di ogni carta nel calcolo della primiera */
    private const PRIMIERA_VALUE_BY_RANK = [
        1 => 16, 2 => 12, 3 => 13, 4 => 14, 5 => 15, 6 => 18, 7 => 21, 8 => 10, 9 => 10, 10 => 10,
    ];

    /**
     * Restituisce tutte le prese lecite quando si gioca una carta del valore indicato sul tavolo.
     *
     * - Se sul tavolo c'è almeno una carta dello stesso valore, si DEVE prendere esattamente una di quelle
     *   (il risultato contiene una presa da una sola carta per ognuna di esse).
     * - Altrimenti è lecito qualunque gruppo di carte del tavolo la cui somma è uguale al valore giocato.
     * - Un risultato vuoto significa che non esiste nessuna presa: la carta può solo essere posata sul tavolo.
     *
     * @param int     $playedRank il valore della carta che il giocatore sta giocando
     * @param array[] $tableCards le carte attualmente sul tavolo
     * @return int[][] elenco di prese, ognuna è un elenco di id di carte del tavolo ordinati
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
     * Funzione di supporto ricorsiva di legalCaptures: trova ogni gruppo non vuoto di carte la cui somma
     * è uguale al valore cercato. Ogni gruppo è costruito scegliendo le carte in ordine di indice crescente,
     * così nessun gruppo compare due volte.
     *
     * @param array[] $candidateCards   le carte del tavolo, ordinate per id
     * @param int     $startIndex       primo indice di $candidateCards ancora aggiungibile al gruppo
     * @param int     $remainingValue   valore ancora mancante per arrivare alla somma cercata (0 = gruppo completo)
     * @param int[]   $currentGroupIds  id delle carte già inserite nel gruppo in costruzione
     * @param int[][] $matchingGroups   (output) tutti i gruppi completi trovati
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
     * Dice se il gruppo di carte del tavolo scelto è una delle prese lecite per il valore giocato.
     * L'ordine degli id non conta.
     *
     * @param int     $playedRank    il valore della carta che si sta giocando
     * @param array[] $tableCards    le carte attualmente sul tavolo
     * @param int[]   $chosenCardIds id delle carte del tavolo che il giocatore vuole prendere
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
     * Con due giocatori si scoprono 4 carte sul tavolo; se tre o più sono Re non sarebbe mai possibile
     * fare scopa, quindi la distribuzione va rifatta.
     *
     * @param array[] $initialTableCards le 4 carte scoperte sul tavolo
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
     * Calcola il totale di primiera di un insieme di carte prese: la somma, sui quattro semi, del miglior
     * valore di primiera posseduto in quel seme.
     *
     * @param array[] $capturedCards tutte le carte prese da una squadra
     * @return int|null il totale di primiera, oppure null se manca almeno un seme (la squadra non concorre)
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
     * Assegna i punti di una smazzata conclusa: scope (1 ciascuna), settebello, primiera, denari, carte.
     * Per primiera, denari e carte un pareggio non dà il punto a nessuno.
     *
     * @param array<int|string, array{cards: array[], scope: int}> $capturedByTeam
     *        per ogni squadra: tutte le carte prese e il numero di scope fatte
     * @return array<int|string, array{scope:int, settebello:int, primiera:int, denari:int, carte:int, total:int,
     *                                 cardCount:int, denariCount:int, primieraValue:?int}>
     *         per ogni squadra: punti per categoria, punti totali e i conteggi usati per calcolarli
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
     * Trova la squadra che vince il punto della primiera: quella con il totale di primiera completo più alto.
     * Le squadre a cui manca un seme non possono vincere; se il totale migliore è pari il punto non va a nessuno.
     *
     * @param array $scoreByTeam per ogni squadra, un array che contiene almeno 'primieraValue' (int o null)
     * @return int|string|null l'id della squadra vincitrice, oppure null se nessuno vince il punto
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
     * Decide se la partita è finita: vince una squadra se ha raggiunto il punteggio obiettivo ED è
     * strettamente in testa rispetto alle altre. Altrimenti si gioca un'altra smazzata (vale anche per i pareggi).
     *
     * @param array<int|string, int> $totalScoreByTeam punteggio totale attuale di ogni squadra
     * @param int                    $targetScore      punti necessari per vincere
     * @return int|string|null l'id della squadra vincitrice, oppure null se la partita continua
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
