function cardElementId(cardId) {
    return `scopa-card-${cardId}`;
}
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
function createCardElement(card) {
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
function createCardBackElement() {
    const backElement = document.createElement('div');
    backElement.className = 'scopa-card scopa-card--back';
    return backElement;
}
function sortCardsForHand(cards) {
    return [...cards].sort((first, second) => first.suit - second.suit || first.rank - second.rank);
}
function sortCardElementsInHand(handElement) {
    const sortedElements = [...handElement.children]
        .map(element => element)
        .sort((first, second) => Number(first.dataset.suit) - Number(second.dataset.suit) || Number(first.dataset.rank) - Number(second.dataset.rank));
    sortedElements.forEach(element => handElement.appendChild(element));
}

const CONFIRM_BUTTON_ID = 'scopa-confirm-button';
const CANCEL_BUTTON_ID = 'scopa-cancel-button';
class PlayerTurn {
    constructor(game, bga) {
        this.game = game;
        this.bga = bga;
        this.capturesByCard = {};
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.isActive = false;
    }
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
    onLeavingState() {
        this.isActive = false;
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.clearCardHighlights();
    }
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
    getCompatibleCaptures() {
        if (this.selectedHandCardId === null) {
            return [];
        }
        const availableCaptures = this.capturesByCard[this.selectedHandCardId];
        return availableCaptures.filter(capture => this.selectedCaptureIds.every(selectedId => capture.includes(selectedId)));
    }
    getSelectableTableCardIds() {
        const idsOfCompatibleCaptures = this.getCompatibleCaptures().flat();
        return [...new Set(idsOfCompatibleCaptures)].filter(cardId => !this.selectedCaptureIds.includes(cardId));
    }
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
    refreshSelectionDisplay() {
        this.clearCardHighlights();
        const confirmButton = document.getElementById(CONFIRM_BUTTON_ID);
        const cancelButton = document.getElementById(CANCEL_BUTTON_ID);
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
            this.bga.statusBar.setTitle(_('${you} cannot capture: put the card on the table'));
            this.setButtonState(confirmButton, true, _('Put the card on the table'));
            return;
        }
        this.selectedCaptureIds.forEach(cardId => this.addClass(cardId, 'scopa-card--selected'));
        this.getSelectableTableCardIds().forEach(cardId => this.addClass(cardId, 'scopa-card--selectable'));
        this.bga.statusBar.setTitle(_('${you} must choose the cards to capture'));
        this.setButtonState(confirmButton, this.isSelectionACompleteCapture(), _('Capture'));
    }
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
        const actionArguments = { card_id: this.selectedHandCardId };
        if (!noCapturePossible) {
            actionArguments.capture_ids = this.selectedCaptureIds;
        }
        this.bga.actions.performAction('actPlay', actionArguments);
    }
    onCancelClick() {
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.refreshSelectionDisplay();
    }
    clearCardHighlights() {
        document.querySelectorAll('.scopa-card--playable, .scopa-card--selected, .scopa-card--selectable').forEach(cardElement => {
            cardElement.classList.remove('scopa-card--playable', 'scopa-card--selected', 'scopa-card--selectable');
        });
    }
    addClass(cardId, className) {
        document.getElementById(cardElementId(cardId))?.classList.add(className);
    }
    setButtonState(button, isEnabled, label) {
        if (!button) {
            return;
        }
        button.classList.remove('disabled');
        button.classList.toggle('scopa-button-inactive', !isEnabled);
        button.textContent = label;
    }
    setButtonVisible(button, isVisible) {
        if (button) {
            button.style.display = isVisible ? '' : 'none';
        }
    }
}

const CARD_MOVE_DURATION_MS = 500;
const SCOPA_BANNER_DURATION_MS = 1400;
const PAUSE_BEFORE_CAPTURE_MS = 450;
class Game {
    constructor(bga) {
        this.handCounts = {};
        this.capturedCounts = {};
        this.scopeCounts = {};
        this.bga = bga;
        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
    }
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
        this.renderAllOpponentHands();
        this.renderAllPiles();
        this.renderTargetScore(gamedatas.target);
        this.connectCardClicks();
        this.bga.notifications.setupPromiseNotifications({});
    }
    toNumberRecord(values) {
        const numericValues = {};
        Object.keys(values).forEach(key => numericValues[Number(key)] = Number(values[Number(key)]));
        return numericValues;
    }
    renderAllOpponentHands() {
        Object.keys(this.handCounts).forEach(playerId => this.renderOpponentHand(Number(playerId)));
    }
    renderAllPiles() {
        Object.keys(this.capturedCounts).forEach(teamId => this.renderPile(Number(teamId)));
    }
    buildBoard() {
        const players = this.gamedatas.players;
        const opponentPlayerIds = this.otherPlayerIdsInDisplayOrder();
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
    otherPlayerIdsInDisplayOrder() {
        const playerOrder = this.gamedatas.playerOrder.map(Number);
        const myIndex = playerOrder.indexOf(this.currentPlayerId);
        const orderFromMe = myIndex === -1
            ? playerOrder
            : [...playerOrder.slice(myIndex + 1), ...playerOrder.slice(0, myIndex)];
        return orderFromMe.filter(playerId => playerId !== this.currentPlayerId);
    }
    roleBadgeHtml(playerId) {
        const myPlayer = this.gamedatas.players[this.currentPlayerId];
        if (!this.gamedatas.isTeamGame || !myPlayer || playerId === this.currentPlayerId) {
            return '';
        }
        const isPartner = this.gamedatas.players[playerId].team === myPlayer.team;
        return isPartner
            ? `<span class="scopa-role-badge scopa-role-badge--partner">${_('Partner')}</span>`
            : `<span class="scopa-role-badge scopa-role-badge--opponent">${_('Opponent')}</span>`;
    }
    seatHtml(playerId) {
        const player = this.gamedatas.players[playerId];
        return `
            <div class="scopa-seat" id="scopa-seat-${playerId}">
                <span class="scopa-seat-name" style="color: #${player.color}">${player.name}</span>
                ${this.roleBadgeHtml(playerId)}
                <span class="scopa-dealer-badge">${_('Dealer')}</span>
            </div>`;
    }
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
    cardIdFromEvent(event) {
        const cardElement = event.target.closest('.scopa-card');
        return cardElement?.dataset.cardId ? Number(cardElement.dataset.cardId) : null;
    }
    renderDealer(dealerId) {
        document.querySelectorAll('.scopa-seat').forEach(seat => seat.classList.remove('scopa-seat--dealer'));
        document.getElementById(`scopa-seat-${dealerId}`)?.classList.add('scopa-seat--dealer');
    }
    renderDeckCount(deckCount) {
        const deckCountElement = document.getElementById('scopa-deck-count');
        if (deckCountElement) {
            deckCountElement.textContent = String(deckCount);
        }
        document.getElementById('scopa-deck')?.classList.toggle('scopa-deck--empty', deckCount === 0);
    }
    renderTargetScore(target) {
        const targetElement = document.getElementById('scopa-target');
        if (targetElement) {
            targetElement.textContent = String(target);
        }
    }
    renderTable(cards) {
        const tableElement = document.getElementById('scopa-table');
        if (!tableElement) {
            return;
        }
        tableElement.innerHTML = '';
        cards.forEach(card => tableElement.appendChild(createCardElement(card)));
    }
    renderHand(cards) {
        const handElement = document.getElementById('scopa-hand');
        if (!handElement) {
            return;
        }
        handElement.innerHTML = '';
        sortCardsForHand(cards).forEach(card => handElement.appendChild(createCardElement(card)));
    }
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
    animationsActive() {
        return this.bga.gameui.bgaAnimationsActive();
    }
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
    async slideFrom(element, startRect) {
        const endRect = element.getBoundingClientRect();
        const deltaX = startRect.left - endRect.left;
        const deltaY = startRect.top - endRect.top;
        element.style.zIndex = '20';
        const animation = element.animate([{ transform: `translate(${deltaX}px, ${deltaY}px)` }, { transform: 'none' }], { duration: CARD_MOVE_DURATION_MS, easing: 'ease-in-out' });
        await Promise.race([
            animation.finished.catch(() => undefined),
            new Promise(resolve => setTimeout(resolve, CARD_MOVE_DURATION_MS + 300)),
        ]);
        element.style.zIndex = '';
    }
    async dealCardFromDeck(cardElement, destination) {
        const deckRect = document.getElementById('scopa-deck').getBoundingClientRect();
        destination.appendChild(cardElement);
        if (this.animationsActive()) {
            await this.slideFrom(cardElement, deckRect);
        }
    }
    async pause(milliseconds) {
        if (this.animationsActive()) {
            await this.bga.gameui.wait(milliseconds);
        }
    }
    async notif_newDeal(args) {
        document.getElementById('scopa-hand')?.replaceChildren();
        document.getElementById('scopa-table')?.replaceChildren();
        Object.keys(this.handCounts).forEach(playerId => this.handCounts[Number(playerId)] = 0);
        Object.keys(this.capturedCounts).forEach(teamId => {
            this.capturedCounts[Number(teamId)] = 0;
            this.scopeCounts[Number(teamId)] = 0;
        });
        this.renderAllPiles();
        this.renderAllOpponentHands();
        this.renderDealer(Number(args.dealer));
        this.renderDeckCount(Number(args.deckCount) + args.table.length);
        const tableElement = document.getElementById('scopa-table');
        for (const card of args.table) {
            await this.dealCardFromDeck(createCardElement(card), tableElement);
        }
        this.renderDeckCount(Number(args.deckCount));
    }
    async notif_dealHand(args) {
        const handElement = document.getElementById('scopa-hand');
        if (!handElement) {
            return;
        }
        for (const card of args.cards) {
            await this.dealCardFromDeck(createCardElement(card), handElement);
        }
        sortCardElementsInHand(handElement);
    }
    notif_handsDealt(args) {
        Object.keys(args.handCounts).forEach(playerId => {
            this.handCounts[Number(playerId)] = Number(args.handCounts[Number(playerId)]);
            if (Number(playerId) !== this.currentPlayerId) {
                this.renderOpponentHand(Number(playerId));
            }
        });
        this.renderDeckCount(Number(args.deckCount));
    }
    async notif_cardPlayed(args) {
        const playerId = Number(args.player_id);
        const teamId = Number(args.team);
        const tableElement = document.getElementById('scopa-table');
        let playedCardElement = document.getElementById(cardElementId(args.card.id));
        if (!playedCardElement) {
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
    async showScopaBanner(tableElement) {
        const bannerElement = document.createElement('div');
        bannerElement.className = 'scopa-scopa-banner';
        bannerElement.textContent = _('SCOPA!');
        tableElement.appendChild(bannerElement);
        await this.pause(SCOPA_BANNER_DURATION_MS);
        bannerElement.remove();
    }
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
