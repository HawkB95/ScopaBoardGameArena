/*
 * Disegno delle carte. Ogni carta è un elemento vuoto: la faccia viene dallo sprite delle carte napoletane
 * (img/cards.jpg), scelta dal CSS con le classi di seme e valore (vedi src/scss/Game.scss).
 * Seme: 1 denari, 2 coppe, 3 bastoni, 4 spade.
 */

/** Id dell'elemento HTML di una carta. */
export function cardElementId(cardId: number): string {
    return `scopa-card-${cardId}`;
}

/**
 * Restituisce il nome completo della carta per tooltip e accessibilità, per esempio "Re di denari".
 */
export function cardName(card: ScopaCard): string {
    const rankNames: Record<number, string> = {
        1: _('Ace'), 2: _('Two'), 3: _('Three'), 4: _('Four'), 5: _('Five'),
        6: _('Six'), 7: _('Seven'), 8: _('Jack'), 9: _('Knight'), 10: _('King'),
    };
    const suitNames: Record<number, string> = {
        1: _('Coins'), 2: _('Cups'), 3: _('Clubs'), 4: _('Swords'),
    };
    return `${rankNames[card.rank]} - ${suitNames[card.suit]}`;
}

/**
 * Crea l'elemento HTML di una carta scoperta. Non ha contenuto: il disegno è lo sprite scelto dalle classi
 * di seme e valore; nome e dati servono al tooltip, all'accessibilità e ai clic/riordino (data-*).
 */
export function createCardElement(card: ScopaCard): HTMLElement {
    const cardElement = document.createElement('div');
    cardElement.id = cardElementId(card.id);
    cardElement.className = `scopa-card scopa-card--suit-${card.suit} scopa-card--rank-${card.rank}`;
    cardElement.dataset.cardId = String(card.id);
    cardElement.dataset.rank = String(card.rank);
    cardElement.dataset.suit = String(card.suit);
    cardElement.title = cardName(card);
    cardElement.setAttribute('aria-label', cardName(card));
    return cardElement;
}

/**
 * Crea l'elemento HTML di una carta coperta (dorso), usato per le mani degli avversari e per il mazzo.
 */
export function createCardBackElement(): HTMLElement {
    const backElement = document.createElement('div');
    backElement.className = 'scopa-card scopa-card--back';
    return backElement;
}

/**
 * Ordina le carte per seme e poi per valore, così la mano è facile da leggere.
 */
export function sortCardsForHand(cards: ScopaCard[]): ScopaCard[] {
    return [...cards].sort((first, second) => first.suit - second.suit || first.rank - second.rank);
}

/**
 * Riordina per seme e valore gli elementi HTML delle carte già presenti nella mano (stesso ordine di
 * sortCardsForHand, ma lavora sugli elementi perché la mano è già disegnata e va solo riordinata, senza ricrearla).
 */
export function sortCardElementsInHand(handElement: HTMLElement): void {
    const sortedElements = [...handElement.children]
        .map(element => element as HTMLElement)
        .sort((first, second) =>
            Number(first.dataset.suit) - Number(second.dataset.suit) || Number(first.dataset.rank) - Number(second.dataset.rank));
    sortedElements.forEach(element => handElement.appendChild(element));
}
