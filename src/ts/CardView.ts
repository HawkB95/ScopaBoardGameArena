/*
 * Disegno delle carte. L'elemento di una carta contiene sia il disegno francese in HTML/CSS (valore e seme in
 * testo) sia le classi per lo sprite delle carte napoletane (img/cards.jpg): il CSS mostra l'uno o l'altro in
 * base alla preferenza "Card style" del giocatore (napoletane di default).
 * Corrispondenza dei semi: denari = quadri, coppe = cuori, bastoni = fiori, spade = picche.
 */

/** Simbolo di ogni seme (id seme => simbolo). */
const SUIT_SYMBOL_BY_SUIT: Record<number, string> = { 1: '♦', 2: '♥', 3: '♣', 4: '♠' };

/** Etichetta mostrata sulla carta per ogni valore (valore => etichetta). */
const RANK_LABEL_BY_RANK: Record<number, string> = {
    1: 'A', 2: '2', 3: '3', 4: '4', 5: '5', 6: '6', 7: '7', 8: 'J', 9: 'Q', 10: 'K',
};

/** Vero per i semi rossi (denari e coppe). */
function isRedSuit(suit: number): boolean {
    return suit === 1 || suit === 2;
}

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
 * Crea l'elemento HTML di una carta scoperta: valore e seme negli angoli, simbolo grande al centro
 * (con la lettera J/Q/K per le figure).
 */
export function createCardElement(card: ScopaCard): HTMLElement {
    const cardElement = document.createElement('div');
    cardElement.id = cardElementId(card.id);
    cardElement.className = `scopa-card scopa-card--${isRedSuit(card.suit) ? 'red' : 'black'} scopa-card--suit-${card.suit} scopa-card--rank-${card.rank}`;
    cardElement.dataset.cardId = String(card.id);
    cardElement.dataset.rank = String(card.rank);
    cardElement.dataset.suit = String(card.suit);
    cardElement.title = cardName(card);
    cardElement.setAttribute('aria-label', cardName(card));

    const rankLabel = RANK_LABEL_BY_RANK[card.rank];
    const suitSymbol = SUIT_SYMBOL_BY_SUIT[card.suit];
    const faceLetter = card.rank >= 8 ? `<span class="scopa-card-face-letter">${rankLabel}</span>` : '';
    cardElement.innerHTML = `
        <span class="scopa-card-corner scopa-card-corner--top">${rankLabel}<br>${suitSymbol}</span>
        <span class="scopa-card-center">${faceLetter}<span class="scopa-card-big-suit">${suitSymbol}</span></span>
        <span class="scopa-card-corner scopa-card-corner--bottom">${rankLabel}<br>${suitSymbol}</span>
    `;
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
