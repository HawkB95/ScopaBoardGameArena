<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio;

use Bga\GameFramework\Components\ItemManager\Item;
use Bga\GameFramework\Components\ItemManager\ItemField;
use Bga\GameFramework\Components\ItemManager\ItemFieldKind;

#[Item('card')]
class Card
{
    /** Id della carta */
    #[ItemField(kind: ItemFieldKind::ID)]
    public int $id;

    /** Dove si trova la carta: 'deck', 'hand', 'table' o 'captured'. */
    #[ItemField(kind: ItemFieldKind::LOCATION, locationIndex: 0)]
    public string $location;

    /** * Id del giocatore o della squadra */
    #[ItemField(kind: ItemFieldKind::LOCATION, locationIndex: 1)]
    public ?int $locationArg = null;

    /** Posizione della carta dentro la sua location */
    #[ItemField(kind: ItemFieldKind::ORDER)]
    public int $order;

    /** Seme: 1 denari, 2 coppe, 3 bastoni, 4 spade. */
    #[ItemField]
    public int $suit;

    /** Valore della carta */
    #[ItemField]
    public int $rank;
}
