import { PlayerTurn } from "./States/PlayerTurn";
import { cardElementId, createCardBackElement, createCardElement, sortCardElementsInHand, sortCardsForHand } from "./CardView";

/** Durata in millisecondi degli spostamenti delle carte. */
const CARD_MOVE_DURATION_MS = 500;
/** Durata in millisecondi della scritta "SCOPA!" mostrata sul tavolo. */
const SCOPA_BANNER_DURATION_MS = 1400;
/** Pausa in millisecondi tra la carta posata sul tavolo e la presa. */
const PAUSE_BEFORE_CAPTURE_MS = 450;

/**
 * Classe principale del client: costruisce l'interfaccia (tavolo, mano, mazzo, pile delle prese, avversari),
 * gestisce i clic sulle carte e reagisce alle notifiche del server animando le carte
 */
export class Game {
    public bga: Bga<ProjectBiagioPlayer, ProjectBiagioGamedatas>;
    private gamedatas: ProjectBiagioGamedatas;

    private playerTurn: PlayerTurn;

    /** Id del giocatore che sta guardando (può non essere tra i giocatori se è uno spettatore) */
    private currentPlayerId: number;
    /** Carte in mano a ogni giocatore, aggiornate dalle notifiche */
    private handCounts: Record<number, number> = {};
    /** Carte prese da ogni squadra, aggiornate dalle notifiche */
    private capturedCounts: Record<number, number> = {};
    /** Scope fatte da ogni squadra nella smazzata corrente, aggiornate dalle notifiche */
    private scopeCounts: Record<number, number> = {};

    constructor(bga: Bga<ProjectBiagioPlayer, ProjectBiagioGamedatas>) {
        this.bga = bga;

        // Registra la classe che gestisce lo stato PlayerTurn
        this.playerTurn = new PlayerTurn(this, bga);
        this.bga.states.register('PlayerTurn', this.playerTurn);
    }

    ///////////////////////////////////////////////////
    //// Costruzione dell'interfaccia

    /**
     * Costruisce l'interfaccia a partire dai dati inviati dal server. Viene chiamata al caricamento e a ogni
     * aggiornamento della pagina, quindi deve disegnare lo stato completo del gioco
     */
    setup(gamedatas: ProjectBiagioGamedatas) {
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

    /**
     * Converte in numeri tutti i valori di un oggetto
     */
    private toNumberRecord(values: Record<number, number | string>): Record<number, number> {
        const numericValues: Record<number, number> = {};
        Object.keys(values).forEach(key => numericValues[Number(key)] = Number(values[Number(key)]));
        return numericValues;
    }

    /**
     * Ridisegna le carte coperte di tutti gli avversari
     */
    private renderAllOpponentHands() {
        Object.keys(this.handCounts).forEach(playerId => this.renderOpponentHand(Number(playerId)));
    }

    /**
     * Aggiorna la pila delle prese di ogni squadra.
     */
    private renderAllPiles() {
        Object.keys(this.capturedCounts).forEach(teamId => this.renderPile(Number(teamId)));
    }

    /**
     * Crea la struttura HTML del gioco
     */
    private buildBoard() {
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

    /**
     * Restituisce gli altri giocatori nell'ordine in cui vengono mostrati in alto, da sinistra a destra
     */
    private otherPlayerIdsInDisplayOrder(): number[] {
        const playerOrder = this.gamedatas.playerOrder.map(Number);
        const myIndex = playerOrder.indexOf(this.currentPlayerId);
        const orderFromMe = myIndex === -1
            ? playerOrder
            : [...playerOrder.slice(myIndex + 1), ...playerOrder.slice(0, myIndex)];
        return orderFromMe.filter(playerId => playerId !== this.currentPlayerId);
    }

    /**
     * Etichetta del ruolo di un giocatore
     */
    private roleBadgeHtml(playerId: number): string {
        const myPlayer = this.gamedatas.players[this.currentPlayerId];
        if (!this.gamedatas.isTeamGame || !myPlayer || playerId === this.currentPlayerId) {
            return '';
        }
        const isPartner = this.gamedatas.players[playerId].team === myPlayer.team;
        return isPartner
            ? `<span class="scopa-role-badge scopa-role-badge--partner">${_('Partner')}</span>`
            : `<span class="scopa-role-badge scopa-role-badge--opponent">${_('Opponent')}</span>`;
    }

    /**
     * HTML dell'intestazione di un giocatore: nome (nel suo colore) e il segnalino "Dealer", nascosto
     * finché non è il suo turno
     */
    private seatHtml(playerId: number): string {
        const player = this.gamedatas.players[playerId];
        return `
            <div class="scopa-seat" id="scopa-seat-${playerId}">
                <span class="scopa-seat-name" style="color: #${player.color}">${player.name}</span>
                ${this.roleBadgeHtml(playerId)}
                <span class="scopa-dealer-badge">${_('Dealer')}</span>
            </div>`;
    }

    /**
     * Collega i clic sulle carte della mano e del tavolo allo stato PlayerTurn
     */
    private connectCardClicks() {
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
     * Ricava l'id della carta su cui si è cliccato
     */
    private cardIdFromEvent(event: Event): number | null {
        const cardElement = (event.target as HTMLElement).closest('.scopa-card') as HTMLElement | null;
        return cardElement?.dataset.cardId ? Number(cardElement.dataset.cardId) : null;
    }

    ///////////////////////////////////////////////////
    //// Disegno delle varie parti

    /**
     * Mostra il segnalino "Dealer" solo accanto al mazziere indicato
     */
    private renderDealer(dealerId: number) {
        document.querySelectorAll('.scopa-seat').forEach(seat => seat.classList.remove('scopa-seat--dealer'));
        document.getElementById(`scopa-seat-${dealerId}`)?.classList.add('scopa-seat--dealer');
    }

    /**
     * Aggiorna il numero di carte rimaste nel mazzo e nasconde il mazzo quando è vuoto
     */
    private renderDeckCount(deckCount: number) {
        const deckCountElement = document.getElementById('scopa-deck-count');
        if (deckCountElement) {
            deckCountElement.textContent = String(deckCount);
        }
        document.getElementById('scopa-deck')?.classList.toggle('scopa-deck--empty', deckCount === 0);
    }

    /**
     * Mostra il punteggio da raggiungere per vincere
     */
    private renderTargetScore(target: number) {
        const targetElement = document.getElementById('scopa-target');
        if (targetElement) {
            targetElement.textContent = String(target);
        }
    }

    /**
     * Svuota il tavolo e vi disegna le carte indicate
     */
    private renderTable(cards: ScopaCard[]) {
        const tableElement = document.getElementById('scopa-table');
        if (!tableElement) {
            return;
        }
        tableElement.innerHTML = '';
        cards.forEach(card => tableElement.appendChild(createCardElement(card)));
    }

    /**
     * Svuota la propria mano e vi disegna le carte indicate, ordinate per seme e valore
     */
    private renderHand(cards: ScopaCard[]) {
        const handElement = document.getElementById('scopa-hand');
        if (!handElement) {
            return;
        }
        handElement.innerHTML = '';
        sortCardsForHand(cards).forEach(card => handElement.appendChild(createCardElement(card)));
    }

    /**
     * Ridisegna le carte coperte della mano di un avversario in base al numero di carte che ha in mano
     */
    private renderOpponentHand(playerId: number) {
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
     * Aggiorna la pila delle prese di una squadra: numero di carte, numero di scope e dorso visibile se non è vuota
     */
    private renderPile(teamId: number) {
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
     * Vero se le animazioni sono attive
     */
    private animationsActive(): boolean {
        return this.bga.gameui.bgaAnimationsActive();
    }

    /**
     * Sposta una carta e in caso di presa lo rimuove alla fine
     */
    private async moveElement(element: HTMLElement, newParent: HTMLElement, removeAtEnd = false) {
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
     * Anima un elemento dalla posizione indicata a quella attuale
     */
    private async slideFrom(element: HTMLElement, startRect: DOMRect) {
        const endRect = element.getBoundingClientRect();
        const deltaX = startRect.left - endRect.left;
        const deltaY = startRect.top - endRect.top;
        element.style.zIndex = '20';
        const animation = element.animate(
            [{ transform: `translate(${deltaX}px, ${deltaY}px)` }, { transform: 'none' }],
            { duration: CARD_MOVE_DURATION_MS, easing: 'ease-in-out' },
        );
        // Il limite di tempo è necessario perché BGA esegue le notifiche una dopo l'altra: se il browser non
        // portasse a termine l'animazione (per esempio in una scheda in secondo piano) la coda resterebbe bloccata
        await Promise.race([
            animation.finished.catch(() => undefined),
            new Promise(resolve => setTimeout(resolve, CARD_MOVE_DURATION_MS + 300)),
        ]);
        element.style.zIndex = '';
    }

    /**
     * Aggiunge una carta a un contenitore facendola partire dal mazzo (animazione di distribuzione)
     */
    private async dealCardFromDeck(cardElement: HTMLElement, destination: HTMLElement) {
        const deckRect = (document.getElementById('scopa-deck') as HTMLElement).getBoundingClientRect();
        destination.appendChild(cardElement);
        if (this.animationsActive()) {
            await this.slideFrom(cardElement, deckRect);
        }
    }

    /**
     * Pausa che rispetta l'impostazione delle animazioni
     */
    private async pause(milliseconds: number) {
        if (this.animationsActive()) {
            await this.bga.gameui.wait(milliseconds);
        }
    }

    ///////////////////////////////////////////////////
    //// Notifiche dal server

    /**
     * Nuova smazzata: ripulisce tavolo, mano e pile, aggiorna il mazziere e distribuisce le carte scoperte sul tavolo
     */
    async notif_newDeal(args: { dealer: number; table: ScopaCard[]; deckCount: number }) {
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

        const tableElement = document.getElementById('scopa-table') as HTMLElement;
        for (const card of args.table) {
            await this.dealCardFromDeck(createCardElement(card), tableElement);
        }
        this.renderDeckCount(Number(args.deckCount));
    }

    /**
     * Carte ricevute dal giocatore corrente (notifica privata): le aggiunge alla mano facendole arrivare dal mazzo
     */
    async notif_dealHand(args: { cards: ScopaCard[] }) {
        const handElement = document.getElementById('scopa-hand');
        if (!handElement) {
            return;
        }
        for (const card of args.cards) {
            await this.dealCardFromDeck(createCardElement(card), handElement);
        }
        // Le carte sono arrivate una alla volta: a fine distribuzione si riordina l'intera mano
        sortCardElementsInHand(handElement);
    }

    /**
     * Distribuzione conclusa: aggiorna il numero di carte in mano a ogni giocatore e quelle rimaste nel mazzo
     */
    notif_handsDealt(args: { handCounts: Record<number, number>; deckCount: number }) {
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
     * nella pila della sua squadra; se è una scopa aggiorna il contatore e la evidenzia
     */
    async notif_cardPlayed(args: {
        player_id: number; team: number; card: ScopaCard; captured: ScopaCard[];
        scopa: boolean; scopeCount?: number; handCount: number;
    }) {
        const playerId = Number(args.player_id);
        const teamId = Number(args.team);
        const tableElement = document.getElementById('scopa-table') as HTMLElement;

        let playedCardElement = document.getElementById(cardElementId(args.card.id));
        if (!playedCardElement) {
            // Carta di un avversario: compare al posto di uno dei suoi dorsi e scorre sul tavolo
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
        const pileCardsElement = document.getElementById(`scopa-pile-cards-${teamId}`) as HTMLElement;
        const elementsToCapture = [...args.captured.map(card => document.getElementById(cardElementId(card.id))), playedCardElement]
            .filter((element): element is HTMLElement => element !== null);
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
     * Mostra per un attimo la scritta "SCOPA!" al centro del tavolo, poi la toglie
     */
    private async showScopaBanner(tableElement: HTMLElement) {
        const bannerElement = document.createElement('div');
        bannerElement.className = 'scopa-scopa-banner';
        bannerElement.textContent = _('SCOPA!');
        tableElement.appendChild(bannerElement);
        await this.pause(SCOPA_BANNER_DURATION_MS);
        bannerElement.remove();
    }

    /**
     * Fine smazzata: le carte rimaste sul tavolo vanno alla squadra che ha preso per ultima
     */
    async notif_leftoverCaptured(args: { team: number; captured: ScopaCard[] }) {
        const teamId = Number(args.team);
        const pileCardsElement = document.getElementById(`scopa-pile-cards-${teamId}`) as HTMLElement;
        const elementsToCapture = args.captured
            .map(card => document.getElementById(cardElementId(card.id)))
            .filter((element): element is HTMLElement => element !== null);
        await Promise.all(elementsToCapture.map(element => this.moveElement(element, pileCardsElement, true)));
        this.capturedCounts[teamId] = (this.capturedCounts[teamId] ?? 0) + args.captured.length;
        this.renderPile(teamId);
    }

    /**
     * Punteggio di una squadra a fine smazzata: aggiorna il punteggio totale nei pannelli dei suoi giocatori
     * e mostra i punti guadagnati accanto alla pila
     */
    async notif_teamScored(args: { team: number; points: number; total: number; players: number[] }) {
        args.players.forEach(playerId => {
            this.bga.playerPanels.getScoreCounter(Number(playerId)).toValue(Number(args.total));
        });
        if (Number(args.points) > 0) {
            this.bga.gameui.displayScoring(`scopa-pile-${args.team}`, '000000', `+${args.points}`, 1500);
        }
        await this.pause(1200);
    }
}
