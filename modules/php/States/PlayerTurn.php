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

class PlayerTurn extends GameState
{
    function __construct(
        protected Game $game,
    ) {
        parent::__construct($game,
            id: 10,
            type: StateType::ACTIVE_PLAYER,
        );
    }

    /**
     * The legal captures of each hand card are only sent to the active player (the hand is private).
     */
    public function getArgs(): array
    {
        $playerId = (int) $this->game->getActivePlayerId();
        return [
            '_private' => [
                $playerId => [
                    'captures' => $this->game->capturesByCard($playerId),
                ],
            ],
        ];
    }

    /**
     * Play a card, capturing $capture_ids from the table (empty = no capture, only allowed if no capture is possible).
     *
     * @throws UserException
     */
    #[PossibleAction]
    public function actPlay(int $card_id, #[IntArrayParam] array $capture_ids, int $activePlayerId)
    {
        $game = $this->game;
        $cards = $game->cards;

        $card = $cards->getCard($card_id);
        if ($card === null || $card['location'] !== Game::LOC_HAND || (int) $card['location_arg'] !== $activePlayerId) {
            throw new UserException(clienttranslate('This card is not in your hand'));
        }

        $played = $game->cardView($card);
        $table = $game->tableCards();
        $captureIds = array_values(array_unique(array_map('intval', $capture_ids)));
        sort($captureIds);

        if (count($captureIds) === 0) {
            if (count(ScopaRules::legalCaptures($played['rank'], $table)) > 0) {
                throw new UserException(clienttranslate('You must capture when a capture is possible'));
            }
        } elseif (!ScopaRules::isLegalCapture($played['rank'], $table, $captureIds)) {
            throw new UserException(clienttranslate('This capture is not allowed'));
        }

        $team = $game->teamOf($activePlayerId);
        $notifArgs = [
            'player_id' => $activePlayerId,
            'player_name' => $game->getPlayerNameById($activePlayerId),
            'card_label' => $game->cardLabel($played),
            'card' => $played,
            'team' => $team,
        ];

        if (count($captureIds) === 0) {
            $cards->moveCard($card_id, Game::LOC_TABLE);
            $this->bga->playerStats->inc('discards_count', 1, $activePlayerId);
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} puts ${card_label} on the table'), $notifArgs + [
                'captured' => [],
                'scopa' => false,
                'handCount' => $cards->countCardInLocation(Game::LOC_HAND, $activePlayerId),
            ]);
            return NextPlayer::class;
        }

        $capturedViews = $game->cardViews($cards->getCards($captureIds));

        $cards->moveCards(array_merge([$card_id], $captureIds), Game::LOC_CAPTURED, $team);
        $this->bga->globals->set('last_capturer', $team);
        $this->bga->playerStats->inc('captures_count', 1, $activePlayerId);

        $scopa = $cards->countCardInLocation(Game::LOC_TABLE) === 0 && !$game->isLastPlayOfDeal();
        $labels = implode(', ', array_map(fn($v) => $game->cardLabel($v), $capturedViews));

        $args = $notifArgs + [
            'captured' => $capturedViews,
            'captured_labels' => $labels,
            'scopa' => $scopa,
            'handCount' => $cards->countCardInLocation(Game::LOC_HAND, $activePlayerId),
        ];
        if ($scopa) {
            $game->addScopa($team);
            $this->bga->playerStats->inc('scope_count', 1, $activePlayerId);
            $args['scopeCount'] = $game->getScope()[$team];
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label}, captures ${captured_labels} and makes a SCOPA!'), $args);
        } else {
            $this->bga->notify->all('cardPlayed', clienttranslate('${player_name} plays ${card_label} and captures ${captured_labels}'), $args);
        }

        return NextPlayer::class;
    }

    /**
     * Zombie level 1: random card, random legal capture (or a plain discard when no capture is possible).
     */
    function zombie(int $playerId)
    {
        $captures = $this->game->capturesByCard($playerId);
        $cardIds = array_keys($captures);
        $cardId = $cardIds[bga_rand(0, count($cardIds) - 1)];
        $options = $captures[$cardId];
        $capture = count($options) > 0 ? $options[bga_rand(0, count($options) - 1)] : [];
        return $this->actPlay($cardId, $capture, $playerId);
    }
}
