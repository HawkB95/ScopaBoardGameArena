/*
 * Disegno delle carte. L'elemento di una carta contiene sia il disegno francese in HTML/CSS (valore e seme in
 * testo) sia le classi per lo sprite delle carte napoletane (img/cards.jpg): il CSS mostra l'uno o l'altro in
 * base alla preferenza "Card style" del giocatore (napoletane di default).
 * Corrispondenza dei semi: denari = quadri, coppe = cuori, bastoni = fiori, spade = picche.
 */
/** Simbolo di ogni seme (id seme => simbolo). */
const SUIT_SYMBOL_BY_SUIT = { 1: '♦', 2: '♥', 3: '♣', 4: '♠' };
/** Etichetta mostrata sulla carta per ogni valore (valore => etichetta). */
const RANK_LABEL_BY_RANK = {
    1: 'A', 2: '2', 3: '3', 4: '4', 5: '5', 6: '6', 7: '7', 8: 'J', 9: 'Q', 10: 'K',
};
/** Vero per i semi rossi (denari e coppe). */
function isRedSuit(suit) {
    return suit === 1 || suit === 2;
}
/** Id dell'elemento HTML di una carta. */
function cardElementId(cardId) {
    return `scopa-card-${cardId}`;
}
/**
 * Restituisce il nome completo della carta per tooltip e accessibilità, per esempio "Re di denari".
 */
function cardName(card) {
    const rankNames = {
        1: _('Ace'), 2: _('Two'), 3: _('Three'), 4: _('Four'), 5: _('Five'),
        6: _('Six'), 7: _('Seven'), 8: _('Jack'), 9: _('Knight'), 10: _('King'),
    };
    const suitNames = {
        1: _('Coins'), 2: _('Cups'), 3: _('Clubs'), 4: _('Swords'),
    };
    return `${rankNames[card.rank]} - ${suitNames[card.suit]}`;
}
/**
 * Crea l'elemento HTML di una carta scoperta: valore e seme negli angoli, simbolo grande al centro
 * (con la lettera J/Q/K per le figure).
 */
function createCardElement(card) {
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
function createCardBackElement() {
    const backElement = document.createElement('div');
    backElement.className = 'scopa-card scopa-card--back';
    return backElement;
}
/**
 * Ordina le carte per seme e poi per valore, così la mano è facile da leggere.
 */
function sortCardsForHand(cards) {
    return [...cards].sort((first, second) => first.suit - second.suit || first.rank - second.rank);
}

/** Id del pulsante di conferma nella barra di stato. */
const CONFIRM_BUTTON_ID = 'scopa-confirm-button';
/** Id del pulsante che annulla la selezione corrente. */
const CANCEL_BUTTON_ID = 'scopa-cancel-button';
/**
 * Stato PlayerTurn: il giocatore attivo sceglie una carta dalla mano e, se può prendere, quali carte del tavolo
 * prendere. Le prese lecite arrivano dal server (nessuna regola è calcolata qui): il client si limita a
 * guidare la selezione e a inviare l'azione `actPlay`.
 *
 * Funzionamento:
 *  - clic su una carta in mano: la seleziona (di nuovo clic: la deseleziona);
 *  - se esiste una sola presa possibile, viene preselezionata; se ce ne sono più, il giocatore clicca le carte
 *    del tavolo, e quando la selezione coincide con una presa lecita il pulsante "Prendi" si abilita;
 *  - se nessuna presa è possibile, il pulsante diventa "Posa la carta sul tavolo".
 */
class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        /** Prese lecite per ogni carta in mano, ricevute dal server. */
        this.capturesByCard = {};
        /** Id della carta in mano selezionata, oppure null. */
        this.selectedHandCardId = null;
        /** Id delle carte del tavolo selezionate per la presa. */
        this.selectedCaptureIds = [];
        /** Vero se il giocatore corrente è quello attivo e può agire. */
        this.isActive = false;
    }
    /**
     * Chiamata dal framework all'ingresso nello stato. Se il giocatore è attivo prepara la selezione e i pulsanti
     * della barra di stato; altrimenti mostra solo il titolo di attesa.
     */
    onEnteringState(args, isCurrentPlayerActive) {
        this.isActive = isCurrentPlayerActive;
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.capturesByCard = args?.captures ?? args?._private?.captures ?? {};
        if (!isCurrentPlayerActive) {
            this.bga.statusBar.setTitle(_('${actplayer} must play a card'));
            return;
        }
        this.bga.statusBar.addActionButton(_('Capture'), () => this.onConfirmClick(), { id: CONFIRM_BUTTON_ID });
        this.bga.statusBar.addActionButton(_('Cancel selection'), () => this.onCancelClick(), { id: CANCEL_BUTTON_ID, color: 'secondary' });
        this.refreshSelectionDisplay();
    }
    /**
     * Chiamata dal framework all'uscita dallo stato: toglie tutte le evidenziazioni dalle carte.
     */
    onLeavingState(args, isCurrentPlayerActive) {
        this.isActive = false;
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.clearCardHighlights();
    }
    /**
     * Chiamata dal framework quando il giocatore diventa attivo o smette di esserlo (qui non serve: stato a giocatore singolo).
     */
    onPlayerActivationChange(args, isCurrentPlayerActive) {
    }
    /**
     * Gestisce il clic su una carta della propria mano: la seleziona, oppure la deseleziona se era già selezionata.
     * Se per quella carta esiste una sola presa, la preseleziona.
     */
    onHandCardClick(cardId) {
        if (!this.isActive || !this.capturesByCard[cardId]) {
            return;
        }
        if (this.selectedHandCardId === cardId) {
            this.selectedHandCardId = null;
            this.selectedCaptureIds = [];
        }
        else {
            this.selectedHandCardId = cardId;
            const availableCaptures = this.capturesByCard[cardId];
            this.selectedCaptureIds = availableCaptures.length === 1 ? [...availableCaptures[0]] : [];
        }
        this.refreshSelectionDisplay();
    }
    /**
     * Gestisce il clic su una carta del tavolo: la aggiunge o la toglie dalla presa in costruzione. Se dopo il clic
     * rimane una sola presa compatibile con la selezione, la completa automaticamente.
     */
    onTableCardClick(cardId) {
        if (!this.isActive || this.selectedHandCardId === null) {
            return;
        }
        const isAlreadySelected = this.selectedCaptureIds.includes(cardId);
        if (isAlreadySelected) {
            this.selectedCaptureIds = this.selectedCaptureIds.filter(selectedId => selectedId !== cardId);
        }
        else if (this.getSelectableTableCardIds().includes(cardId)) {
            this.selectedCaptureIds = [...this.selectedCaptureIds, cardId];
        }
        else {
            return;
        }
        const compatibleCaptures = this.getCompatibleCaptures();
        if (compatibleCaptures.length === 1) {
            this.selectedCaptureIds = [...compatibleCaptures[0]];
        }
        this.refreshSelectionDisplay();
    }
    /**
     * Prese lecite della carta in mano selezionata che contengono tutte le carte del tavolo già scelte.
     */
    getCompatibleCaptures() {
        if (this.selectedHandCardId === null) {
            return [];
        }
        const availableCaptures = this.capturesByCard[this.selectedHandCardId];
        return availableCaptures.filter(capture => this.selectedCaptureIds.every(selectedId => capture.includes(selectedId)));
    }
    /**
     * Id delle carte del tavolo che il giocatore può ancora cliccare per completare una presa lecita.
     */
    getSelectableTableCardIds() {
        const idsOfCompatibleCaptures = this.getCompatibleCaptures().flat();
        return [...new Set(idsOfCompatibleCaptures)].filter(cardId => !this.selectedCaptureIds.includes(cardId));
    }
    /**
     * Vero se le carte selezionate coincidono esattamente con una presa lecita.
     */
    isSelectionACompleteCapture() {
        if (this.selectedHandCardId === null || this.selectedCaptureIds.length === 0) {
            return false;
        }
        const sortedSelection = [...this.selectedCaptureIds].sort((first, second) => first - second);
        return this.capturesByCard[this.selectedHandCardId].some(capture => {
            const sortedCapture = [...capture].sort((first, second) => first - second);
            return sortedCapture.length === sortedSelection.length
                && sortedCapture.every((cardId, index) => cardId === sortedSelection[index]);
        });
    }
    /**
     * Aggiorna tutto ciò che il giocatore vede della selezione: evidenziazioni delle carte, titolo della barra di
     * stato e stato dei pulsanti "Prendi / Posa la carta" e "Annulla".
     */
    refreshSelectionDisplay() {
        this.clearCardHighlights();
        const confirmButton = document.getElementById(CONFIRM_BUTTON_ID);
        const cancelButton = document.getElementById(CANCEL_BUTTON_ID);
        // Nessuna carta in mano selezionata: tutte le carte della mano sono giocabili.
        if (this.selectedHandCardId === null) {
            Object.keys(this.capturesByCard).forEach(cardId => this.addClass(Number(cardId), 'scopa-card--playable'));
            this.bga.statusBar.setTitle(_('${you} must play a card'));
            this.setButtonState(confirmButton, false, _('Capture'));
            this.setButtonVisible(cancelButton, false);
            return;
        }
        this.addClass(this.selectedHandCardId, 'scopa-card--selected');
        this.setButtonVisible(cancelButton, true);
        const availableCaptures = this.capturesByCard[this.selectedHandCardId];
        if (availableCaptures.length === 0) {
            // Nessuna presa possibile: la carta può solo essere posata sul tavolo.
            this.bga.statusBar.setTitle(_('${you} cannot capture: put the card on the table'));
            this.setButtonState(confirmButton, true, _('Put the card on the table'));
            return;
        }
        this.selectedCaptureIds.forEach(cardId => this.addClass(cardId, 'scopa-card--selected'));
        this.getSelectableTableCardIds().forEach(cardId => this.addClass(cardId, 'scopa-card--selectable'));
        this.bga.statusBar.setTitle(_('${you} must choose the cards to capture'));
        this.setButtonState(confirmButton, this.isSelectionACompleteCapture(), _('Capture'));
    }
    /**
     * Invia l'azione al server con la carta giocata e le carte da prendere (elenco vuoto = posa sul tavolo).
     * Va chiamata solo da un clic del giocatore.
     */
    onConfirmClick() {
        if (this.selectedHandCardId === null) {
            this.bga.dialogs.showMessage(_('Select a card from your hand first'), 'error');
            return;
        }
        const noCapturePossible = this.capturesByCard[this.selectedHandCardId].length === 0;
        if (!noCapturePossible && !this.isSelectionACompleteCapture()) {
            this.bga.dialogs.showMessage(_('Select the cards to capture first'), 'error');
            return;
        }
        // Con una lista di prese vuota il parametro non viene inviato (BGA scarta gli array vuoti): il server
        // lo considera allora una posa sul tavolo.
        const actionArguments = { card_id: this.selectedHandCardId };
        if (!noCapturePossible) {
            actionArguments.capture_ids = this.selectedCaptureIds;
        }
        this.bga.actions.performAction('actPlay', actionArguments);
    }
    /**
     * Annulla la selezione corrente (carta in mano e carte del tavolo) senza contattare il server.
     */
    onCancelClick() {
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.refreshSelectionDisplay();
    }
    /**
     * Toglie dalle carte tutte le classi di evidenziazione usate dalla selezione.
     */
    clearCardHighlights() {
        document.querySelectorAll('.scopa-card--playable, .scopa-card--selected, .scopa-card--selectable').forEach(cardElement => {
            cardElement.classList.remove('scopa-card--playable', 'scopa-card--selected', 'scopa-card--selectable');
        });
    }
    /**
     * Aggiunge una classe CSS alla carta con l'id indicato (se esiste).
     */
    addClass(cardId, className) {
        document.getElementById(cardElementId(cardId))?.classList.add(className);
    }
    /**
     * Abilita o disabilita un pulsante della barra di stato e ne cambia il testo.
     */
    setButtonState(button, isEnabled, label) {
        if (!button) {
            return;
        }
        // Non si usa la classe 'disabled' di BGA: il pulsante resta cliccabile e il controllo è nella callback.
        button.classList.remove('disabled');
        button.classList.toggle('scopa-button-inactive', !isEnabled);
        button.textContent = label;
    }
    /**
     * Mostra o nasconde un pulsante della barra di stato.
     */
    setButtonVisible(button, isVisible) {
        if (button) {
            button.style.display = isVisible ? '' : 'none';
        }
    }
}

/** Durata in millisecondi degli spostamenti delle carte. */
const CARD_MOVE_DURATION_MS = 500;
/** Durata in millisecondi della scritta "SCOPA!" mostrata sul tavolo. */
const SCOPA_BANNER_DURATION_MS = 1400;
/** Pausa in millisecondi tra la carta posata sul tavolo e la presa. */
const PAUSE_BEFORE_CAPTURE_MS = 450;
/**
 * Classe principale del client: costruisce l'interfaccia (tavolo, mano, mazzo, pile delle prese, avversari),
 * gestisce i clic sulle carte e reagisce alle notifiche del server animando le carte.
 */
class Game {
    constructor(bga) {
        /** Carte in mano a ogni giocatore, aggiornate dalle notifiche. */
        this.handCounts = {};
        /** Carte prese da ogni squadra, aggiornate dalle notifiche. */
        this.capturedCounts = {};
        /** Scope fatte da ogni squadra nella smazzata corrente, aggiornate dalle notifiche. */
        this.scopeCounts = {};
        this.bga = bga;
        // Registra la classe che gestisce lo stato PlayerTurn (stesso nome della classe PHP).
        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
    }
    ///////////////////////////////////////////////////
    //// Costruzione dell'interfaccia
    /**
     * Costruisce l'interfaccia a partire dai dati inviati dal server. Viene chiamata al caricamento e a ogni
     * aggiornamento della pagina, quindi deve disegnare lo stato completo del gioco.
     */
    setup(gamedatas) {
        this.gamedatas = gamedatas;
        this.currentPlayerId = this.bga.players.getCurrentPlayerId();
        this.handCounts = this.toNumberRecord(gamedatas.handCounts);
        this.capturedCounts = this.toNumberRecord(gamedatas.capturedCounts);
        this.scopeCounts = this.toNumberRecord(gamedatas.scope);
        this.buildBoard();
        this.renderDealer(gamedatas.dealer);
        this.renderDeckCount(gamedatas.deckCount);
        this.renderTable(gamedatas.table);
        this.renderHand(gamedatas.hand);
        Object.keys(this.handCounts).forEach(playerId => this.renderOpponentHand(Number(playerId)));
        Object.keys(this.capturedCounts).forEach(teamId => this.renderPile(Number(teamId)));
        this.renderTargetScore(gamedatas.target);
        this.connectCardClicks();
        this.bga.notifications.setupPromiseNotifications({});
    }
    /**
     * Converte in numeri tutti i valori di un oggetto: i dati inviati dal server possono arrivare come testo
     * (per esempio "2"), e sommandoli si otterrebbe una concatenazione ("2" + 1 = "21") invece di un numero.
     */
    toNumberRecord(values) {
        const numericValues = {};
        Object.keys(values).forEach(key => numericValues[Number(key)] = Number(values[Number(key)]));
        return numericValues;
    }
    /**
     * Crea la struttura HTML del gioco: avversari in alto, al centro mazzo + tavolo + pile delle prese,
     * in basso la propria mano.
     */
    buildBoard() {
        const players = this.gamedatas.players;
        const opponentPlayerIds = Object.keys(players).map(Number).filter(playerId => playerId !== this.currentPlayerId);
        const isSpectator = !(this.currentPlayerId in players);
        const opponentsHtml = opponentPlayerIds.map(playerId => `
            <div class="scopa-opponent" id="scopa-opponent-${playerId}">
                ${this.seatHtml(playerId)}
                <div class="scopa-opponent-hand" id="scopa-opponent-hand-${playerId}"></div>
            </div>`).join('');
        const pilesHtml = Object.keys(this.gamedatas.teamNames).map(Number).map(teamId => `
            <div class="scopa-pile" id="scopa-pile-${teamId}">
                <div class="scopa-pile-name">${this.gamedatas.teamNames[teamId]}</div>
                <div class="scopa-pile-cards" id="scopa-pile-cards-${teamId}"></div>
                <div class="scopa-pile-stats">
                    ${_('Cards')}: <span id="scopa-pile-count-${teamId}">0</span>
                    &middot; ${_('Scope')}: <span id="scopa-scope-count-${teamId}">0</span>
                </div>
            </div>`).join('');
        const myAreaHtml = isSpectator ? '' : `
            <div class="scopa-my-area">
                ${this.seatHtml(this.currentPlayerId)}
                <div id="scopa-hand"></div>
            </div>`;
        this.bga.gameArea.getElement().insertAdjacentHTML('beforeend', `
            <div id="scopa-board">
                <div id="scopa-opponents">${opponentsHtml}</div>
                <div id="scopa-middle">
                    <div id="scopa-deck-area">
                        <div id="scopa-deck" class="scopa-card scopa-card--back"></div>
                        <div class="scopa-deck-label">${_('Deck')}: <span id="scopa-deck-count">0</span></div>
                        <div class="scopa-target-label">${_('Target')}: <span id="scopa-target">0</span></div>
                    </div>
                    <div id="scopa-table"></div>
                    <div id="scopa-piles">${pilesHtml}</div>
                </div>
                ${myAreaHtml}
            </div>`);
    }
    /**
     * HTML dell'intestazione di un giocatore: nome (nel suo colore) e il segnalino "Mazziere", nascosto
     * finché non è il suo turno di dare.
     */
    seatHtml(playerId) {
        const player = this.gamedatas.players[playerId];
        return `
            <div class="scopa-seat" id="scopa-seat-${playerId}">
                <span class="scopa-seat-name" style="color: #${player.color}">${player.name}</span>
                <span class="scopa-dealer-badge">${_('Dealer')}</span>
            </div>`;
    }
    /**
     * Collega i clic sulle carte della mano e del tavolo allo stato PlayerTurn (un solo ascoltatore per contenitore).
     */
    connectCardClicks() {
        document.getElementById('scopa-hand')?.addEventListener('click', event => {
            const cardId = this.cardIdFromEvent(event);
            if (cardId !== null) {
                this.playerTurn.onHandCardClick(cardId);
            }
        });
        document.getElementById('scopa-table')?.addEventListener('click', event => {
            const cardId = this.cardIdFromEvent(event);
            if (cardId !== null) {
                this.playerTurn.onTableCardClick(cardId);
            }
        });
    }
    /**
     * Ricava l'id della carta su cui si è cliccato (o null se il clic non era su una carta).
     */
    cardIdFromEvent(event) {
        const cardElement = event.target.closest('.scopa-card');
        return cardElement?.dataset.cardId ? Number(cardElement.dataset.cardId) : null;
    }
    ///////////////////////////////////////////////////
    //// Disegno delle varie parti
    /**
     * Mostra il segnalino "Mazziere" solo accanto al mazziere indicato.
     */
    renderDealer(dealerId) {
        document.querySelectorAll('.scopa-seat').forEach(seat => seat.classList.remove('scopa-seat--dealer'));
        document.getElementById(`scopa-seat-${dealerId}`)?.classList.add('scopa-seat--dealer');
    }
    /**
     * Aggiorna il numero di carte rimaste nel mazzo e nasconde il mazzo quando è vuoto.
     */
    renderDeckCount(deckCount) {
        const deckCountElement = document.getElementById('scopa-deck-count');
        if (deckCountElement) {
            deckCountElement.textContent = String(deckCount);
        }
        document.getElementById('scopa-deck')?.classList.toggle('scopa-deck--empty', deckCount === 0);
    }
    /**
     * Mostra il punteggio da raggiungere per vincere.
     */
    renderTargetScore(target) {
        const targetElement = document.getElementById('scopa-target');
        if (targetElement) {
            targetElement.textContent = String(target);
        }
    }
    /**
     * Svuota il tavolo e vi disegna le carte indicate (senza animazione).
     */
    renderTable(cards) {
        const tableElement = document.getElementById('scopa-table');
        if (!tableElement) {
            return;
        }
        tableElement.innerHTML = '';
        cards.forEach(card => tableElement.appendChild(createCardElement(card)));
    }
    /**
     * Svuota la propria mano e vi disegna le carte indicate, ordinate per seme e valore (senza animazione).
     */
    renderHand(cards) {
        const handElement = document.getElementById('scopa-hand');
        if (!handElement) {
            return;
        }
        handElement.innerHTML = '';
        sortCardsForHand(cards).forEach(card => handElement.appendChild(createCardElement(card)));
    }
    /**
     * Ridisegna le carte coperte della mano di un avversario in base al numero di carte che ha in mano.
     */
    renderOpponentHand(playerId) {
        const opponentHandElement = document.getElementById(`scopa-opponent-hand-${playerId}`);
        if (!opponentHandElement) {
            return;
        }
        opponentHandElement.innerHTML = '';
        for (let cardNumber = 0; cardNumber < (this.handCounts[playerId] ?? 0); cardNumber++) {
            opponentHandElement.appendChild(createCardBackElement());
        }
    }
    /**
     * Aggiorna la pila delle prese di una squadra: numero di carte, numero di scope e dorso visibile se non è vuota.
     */
    renderPile(teamId) {
        const capturedCount = this.capturedCounts[teamId] ?? 0;
        const countElement = document.getElementById(`scopa-pile-count-${teamId}`);
        const scopeElement = document.getElementById(`scopa-scope-count-${teamId}`);
        if (countElement) {
            countElement.textContent = String(capturedCount);
        }
        if (scopeElement) {
            scopeElement.textContent = String(this.scopeCounts[teamId] ?? 0);
        }
        document.getElementById(`scopa-pile-${teamId}`)?.classList.toggle('scopa-pile--has-cards', capturedCount > 0);
    }
    ///////////////////////////////////////////////////
    //// Animazioni
    /**
     * Vero se le animazioni sono attive (il giocatore può disattivarle nelle impostazioni di BGA).
     */
    animationsActive() {
        return this.bga.gameui.bgaAnimationsActive();
    }
    /**
     * Sposta un elemento in un nuovo contenitore con un'animazione di scorrimento (tecnica FLIP: si misura la
     * posizione prima e dopo il cambio di contenitore e si anima la differenza). Se richiesto, alla fine
     * l'elemento viene eliminato (usato per le carte che finiscono nelle pile delle prese).
     */
    async moveElement(element, newParent, removeAtEnd = false) {
        const startRect = element.getBoundingClientRect();
        newParent.appendChild(element);
        if (this.animationsActive()) {
            await this.slideFrom(element, startRect);
        }
        if (removeAtEnd) {
            element.remove();
        }
    }
    /**
     * Anima un elemento dalla posizione indicata (rettangolo di partenza) fino a dove si trova ora.
     */
    async slideFrom(element, startRect) {
        const endRect = element.getBoundingClientRect();
        const deltaX = startRect.left - endRect.left;
        const deltaY = startRect.top - endRect.top;
        element.style.zIndex = '20';
        const animation = element.animate([{ transform: `translate(${deltaX}px, ${deltaY}px)` }, { transform: 'none' }], { duration: CARD_MOVE_DURATION_MS, easing: 'ease-in-out' });
        // Il limite di tempo evita di bloccare la coda delle notifiche se il browser non porta a termine
        // l'animazione (per esempio in una tab in secondo piano).
        await Promise.race([
            animation.finished.catch(() => undefined),
            new Promise(resolve => setTimeout(resolve, CARD_MOVE_DURATION_MS + 300)),
        ]);
        element.style.zIndex = '';
    }
    /**
     * Aggiunge una carta a un contenitore facendola partire dal mazzo (animazione di distribuzione).
     */
    async dealCardFromDeck(cardElement, destination) {
        const deckRect = document.getElementById('scopa-deck').getBoundingClientRect();
        destination.appendChild(cardElement);
        if (this.animationsActive()) {
            await this.slideFrom(cardElement, deckRect);
        }
    }
    /**
     * Pausa che rispetta l'impostazione delle animazioni (nessuna attesa se sono disattivate).
     */
    async pause(milliseconds) {
        if (this.animationsActive()) {
            await this.bga.gameui.wait(milliseconds);
        }
    }
    ///////////////////////////////////////////////////
    //// Notifiche dal server (il nome è 'notif_' + nome della notifica PHP)
    /**
     * Nuova smazzata: ripulisce tavolo, mano e pile, aggiorna il mazziere e distribuisce le carte scoperte sul tavolo.
     */
    async notif_newDeal(args) {
        document.getElementById('scopa-hand')?.replaceChildren();
        document.getElementById('scopa-table')?.replaceChildren();
        Object.keys(this.handCounts).forEach(playerId => this.handCounts[Number(playerId)] = 0);
        Object.keys(this.capturedCounts).forEach(teamId => {
            this.capturedCounts[Number(teamId)] = 0;
            this.scopeCounts[Number(teamId)] = 0;
            this.renderPile(Number(teamId));
        });
        Object.keys(this.handCounts).forEach(playerId => this.renderOpponentHand(Number(playerId)));
        this.renderDealer(Number(args.dealer));
        this.renderDeckCount(Number(args.deckCount) + args.table.length);
        const tableElement = document.getElementById('scopa-table');
        for (const card of args.table) {
            await this.dealCardFromDeck(createCardElement(card), tableElement);
        }
        this.renderDeckCount(Number(args.deckCount));
    }
    /**
     * Carte ricevute dal giocatore corrente (notifica privata): le aggiunge alla mano facendole arrivare dal mazzo.
     */
    async notif_dealHand(args) {
        const handElement = document.getElementById('scopa-hand');
        if (!handElement) {
            return;
        }
        for (const card of args.cards) {
            await this.dealCardFromDeck(createCardElement(card), handElement);
        }
        // Rimette in ordine l'intera mano per seme e valore.
        const sortedElements = [...handElement.children]
            .map(element => element)
            .sort((first, second) => Number(first.dataset.suit) - Number(second.dataset.suit) || Number(first.dataset.rank) - Number(second.dataset.rank));
        sortedElements.forEach(element => handElement.appendChild(element));
    }
    /**
     * Distribuzione conclusa: aggiorna il numero di carte in mano a ogni giocatore e quelle rimaste nel mazzo.
     */
    notif_handsDealt(args) {
        Object.keys(args.handCounts).forEach(playerId => {
            this.handCounts[Number(playerId)] = Number(args.handCounts[Number(playerId)]);
            if (Number(playerId) !== this.currentPlayerId) {
                this.renderOpponentHand(Number(playerId));
            }
        });
        this.renderDeckCount(Number(args.deckCount));
    }
    /**
     * Un giocatore ha giocato una carta: la porta sul tavolo e, se ha preso, la porta insieme alle carte prese
     * nella pila della sua squadra; se è una scopa aggiorna il contatore e la evidenzia.
     */
    async notif_cardPlayed(args) {
        const playerId = Number(args.player_id);
        const teamId = Number(args.team);
        const tableElement = document.getElementById('scopa-table');
        let playedCardElement = document.getElementById(cardElementId(args.card.id));
        if (!playedCardElement) {
            // Carta di un avversario: compare al posto di uno dei suoi dorsi e scorre sul tavolo.
            const opponentHandElement = document.getElementById(`scopa-opponent-hand-${playerId}`);
            opponentHandElement?.lastElementChild?.remove();
            playedCardElement = createCardElement(args.card);
            opponentHandElement?.appendChild(playedCardElement);
        }
        this.handCounts[playerId] = Number(args.handCount);
        await this.moveElement(playedCardElement, tableElement);
        if (args.captured.length === 0) {
            return;
        }
        await this.pause(PAUSE_BEFORE_CAPTURE_MS);
        const pileCardsElement = document.getElementById(`scopa-pile-cards-${teamId}`);
        const elementsToCapture = [...args.captured.map(card => document.getElementById(cardElementId(card.id))), playedCardElement]
            .filter((element) => element !== null);
        await Promise.all(elementsToCapture.map(element => this.moveElement(element, pileCardsElement, true)));
        this.capturedCounts[teamId] = (this.capturedCounts[teamId] ?? 0) + args.captured.length + 1;
        if (args.scopa) {
            this.scopeCounts[teamId] = Number(args.scopeCount);
            document.getElementById(`scopa-pile-${teamId}`)?.classList.add('scopa-pile--scopa');
            await this.showScopaBanner(tableElement);
            document.getElementById(`scopa-pile-${teamId}`)?.classList.remove('scopa-pile--scopa');
        }
        this.renderPile(teamId);
    }
    /**
     * Mostra per un attimo la scritta "SCOPA!" al centro del tavolo, poi la toglie.
     */
    async showScopaBanner(tableElement) {
        const bannerElement = document.createElement('div');
        bannerElement.className = 'scopa-scopa-banner';
        bannerElement.textContent = _('SCOPA!');
        tableElement.appendChild(bannerElement);
        await this.pause(SCOPA_BANNER_DURATION_MS);
        bannerElement.remove();
    }
    /**
     * Fine smazzata: le carte rimaste sul tavolo vanno alla squadra che ha preso per ultima.
     */
    async notif_leftoverCaptured(args) {
        const teamId = Number(args.team);
        const pileCardsElement = document.getElementById(`scopa-pile-cards-${teamId}`);
        const elementsToCapture = args.captured
            .map(card => document.getElementById(cardElementId(card.id)))
            .filter((element) => element !== null);
        await Promise.all(elementsToCapture.map(element => this.moveElement(element, pileCardsElement, true)));
        this.capturedCounts[teamId] = (this.capturedCounts[teamId] ?? 0) + args.captured.length;
        this.renderPile(teamId);
    }
    /**
     * Punteggio di una squadra a fine smazzata: aggiorna il punteggio totale nei pannelli dei suoi giocatori
     * e mostra i punti guadagnati accanto alla pila.
     */
    async notif_teamScored(args) {
        args.players.forEach(playerId => {
            this.bga.playerPanels.getScoreCounter(Number(playerId)).toValue(Number(args.total));
        });
        if (Number(args.points) > 0) {
            this.bga.gameui.displayScoring(`scopa-pile-${args.team}`, '000000', `+${args.points}`, 1500);
        }
        await this.pause(1200);
    }
}

export { Game };
