<?php

declare(strict_types=1);

namespace Bga\Games\ProjectBiagio;

use Bga\GameFramework\Components\ItemManager\Item;
use Bga\GameFramework\Components\ItemManager\ItemField;
use Bga\GameFramework\Components\ItemManager\ItemFieldKind;

/**
 * Una carta napoletana, gestita dal componente ItemManager di BGA. Ogni proprietà con l'attributo `ItemField`
 * è una colonna della tabella `card`, che il componente crea da solo con `initDb()` (non va dichiarata in dbmodel.sql).
 *
 * Posizioni (`location`) e relativo argomento (`locationArg`):
 *  - 'deck'     : mazzo (l'ordine nel mazzo è nella colonna `order`)
 *  - 'hand'     : mano di un giocatore (argomento = id giocatore)
 *  - 'table'    : tavolo
 *  - 'captured' : carte prese da una squadra (argomento = id squadra)
 */
#[Item('card')]
class Card
{
    /** Identificativo univoco della carta (1..40). */
    #[ItemField(kind: ItemFieldKind::ID)]
    public int $id;

    /** Dove si trova la carta: 'deck', 'hand', 'table' o 'captured'. */
    #[ItemField(kind: ItemFieldKind::LOCATION, locationIndex: 0)]
    public string $location;

    /**
     * Id del giocatore (mano) o della squadra (carte prese); vuoto per mazzo e tavolo. È dichiarato solo `?int`
     * (non `int|string|null`): con il tipo unione ItemManager lo considera testo e rifiuta gli interi.
     */
    #[ItemField(kind: ItemFieldKind::LOCATION, locationIndex: 1)]
    public ?int $locationArg = null;

    /** Posizione della carta dentro la sua location (nel mazzo la cima ha l'ordine più alto). */
    #[ItemField(kind: ItemFieldKind::ORDER)]
    public int $order;

    /** Seme: 1 denari, 2 coppe, 3 bastoni, 4 spade. */
    #[ItemField]
    public int $suit;

    /** Valore: 1 asso ... 7 sette, 8 fante, 9 cavallo, 10 re (è anche il valore usato per le prese). */
    #[ItemField]
    public int $rank;
}
