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
 * Game state 10 - the active player plays one card from their hand (passing is not allowed).
 * If a capture is possible the player must capture; otherwise the card is put on the table.
 */
class PlayerTurn extends GameState
{
    /**
     * Declares the state: id 10, played by the single active player.
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
     * Builds the state arguments sent to the client. The legal captures of every hand card are put in the
     * private part, so only the active player receives them (the hand is hidden information).
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
     * Action: play a card from the hand and capture the given table cards.
     * An empty capture list means "put the card on the table" and is only accepted when no capture is possible.
     * The move is validated on the server with ScopaRules; then the cards move (to the team's captured pile or
     * to the table), a scopa is counted if the table was emptied (except on the last play of the deal), the
     * statistics are updated and everybody is notified.
     *
     * @param int   $card_id        id of the hand card being played
     * @param int[] $capture_ids    ids of the table cards to capture (may be empty)
     * @param int   $activePlayerId filled in automatically by the framework with the acting player
     * @return string the next state: NextPlayer
     * @throws UserException if the card is not in the player's hand or the capture is not allowed
     */
    #[PossibleAction]
    public function actPlay(int $card_id, #[IntArrayParam] array $capture_ids, int $activePlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;

        $playedDeckCard = $cards->getCard($card_id);
        $isInPlayersHand = $playedDeckCard !== null
            && $playedDeckCard['location'] === Game::LOCATION_HAND
            && (int) $playedDeckCard['location_arg'] === $activePlayerId;
        if (!$isInPlayersHand) {
            throw new UserException(clienttranslate('This card is not in your hand'));
        }

        $playedCard = $game->cardView($playedDeckCard);
        $tableCards = $game->tableCards();
        $capturedCardIds = array_values(array_unique(array_map('intval', $capture_ids)));
        sort($capturedCardIds);

        $wantsToCapture = count($capturedCardIds) > 0;
        if (!$wantsToCapture) {
            $captureWasPossible = count(ScopaRules::legalCaptures($playedCard['rank'], $tableCards)) > 0;
            if ($captureWasPossible) {
                throw new UserException(clienttranslate('You must capture when a capture is possible'));
            }
        } elseif (!ScopaRules::isLegalCapture($playedCard['rank'], $tableCards, $capturedCardIds)) {
            throw new UserException(clienttranslate('This capture is not allowed'));
        }

        $teamId = $game->teamOf($activePlayerId);
        $commonNotificationArgs = [
            'player_id' => $activePlayerId,
            'player_name' => $game->getPlayerNameById($activePlayerId),
            'card_label' => $game->cardLabel($playedCard),
            'card' => $playedCard,
            'team' => $teamId,
        ];

        if (!$wantsToCapture) {
            $cards->moveCard($card_id, Game::LOCATION_TABLE);
            $this->bga->playerStats->inc('discards_count', 1, $activePlayerId);
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} puts ${card_label} on the table'), $commonNotificationArgs + [
                'captured' => [],
                'scopa' => false,
                'handCount' => $cards->countCardInLocation(Game::LOCATION_HAND, $activePlayerId),
            ]);
            return NextPlayer::class;
        }

        $capturedCardViews = $game->cardViews($cards->getCards($capturedCardIds));

        // The played card and the captured ones go to the pile of the player's team.
        $cards->moveCards(array_merge([$card_id], $capturedCardIds), Game::LOCATION_CAPTURED, $teamId);
        $this->bga->globals->set('last_capturer', $teamId);
        $this->bga->playerStats->inc('captures_count', 1, $activePlayerId);

        $tableWasEmptied = $cards->countCardInLocation(Game::LOCATION_TABLE) === 0;
        $isScopa = $tableWasEmptied && !$game->isLastPlayOfDeal();
        $capturedLabels = implode(', ', array_map(fn($capturedCardView) => $game->cardLabel($capturedCardView), $capturedCardViews));

        $captureNotificationArgs = $commonNotificationArgs + [
            'captured' => $capturedCardViews,
            'captured_labels' => $capturedLabels,
            'scopa' => $isScopa,
            'handCount' => $cards->countCardInLocation(Game::LOCATION_HAND, $activePlayerId),
        ];
        if ($isScopa) {
            $game->addScopa($teamId);
            $this->bga->playerStats->inc('scope_count', 1, $activePlayerId);
            $captureNotificationArgs['scopeCount'] = $game->getScope()[$teamId];
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label}, captures ${captured_labels} and makes a SCOPA!'), $captureNotificationArgs);
        } else {
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label} and captures ${captured_labels}'), $captureNotificationArgs);
        }

        return NextPlayer::class;
    }

    /**
     * Zombie mode (player left or timed out), level 1: plays a random hand card with a random legal capture,
     * or just puts the card on the table when it cannot capture. Runs on the server without a current player,
     * so it calls actPlay with the explicit player id.
     *
     * @param int $playerId the zombie player
     * @return string the next state: NextPlayer
     */
    function zombie(int $playerId)
    {
        $capturesByHandCardId = $this->game->capturesByCard($playerId);
        $handCardIds = array_keys($capturesByHandCardId);
        $chosenCardId = $handCardIds[bga_rand(0, count($handCardIds) - 1)];

        $availableCaptures = $capturesByHandCardId[$chosenCardId];
        $chosenCapture = count($availableCaptures) > 0 ? $availableCaptures[bga_rand(0, count($availableCaptures) - 1)] : [];
        return $this->actPlay($chosenCardId, $chosenCapture, $playerId);
    }
}
