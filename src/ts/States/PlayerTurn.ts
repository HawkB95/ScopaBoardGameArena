import { Game } from "../Game";
import { cardElementId } from "../CardView";

/** Id del pulsante di conferma nella barra di stato. */
const CONFIRM_BUTTON_ID = 'scopa-confirm-button';
/** Id del pulsante che annulla la selezione corrente. */
const CANCEL_BUTTON_ID = 'scopa-cancel-button';

/**
 * Stato PlayerTurn: il giocatore attivo sceglie una carta dalla mano e, se può prendere, quali carte del tavolo
 * prendere. Le prese lecite arrivano dal server, il client si limita a
 * guidare la selezione e a inviare l'azione `actPlay`
 *
 * Funzionamento:
 *  - clic su una carta in mano: la seleziona (di nuovo clic: la deseleziona);
 *  - se esiste una sola presa possibile, viene preselezionata; se ce ne sono più, il giocatore clicca le carte
 *    del tavolo, e quando la selezione coincide con una presa lecita il pulsante "Prendi" si abilita;
 *  - se nessuna presa è possibile, il pulsante diventa "Posa la carta sul tavolo"
 */
export class PlayerTurn {
    /** Prese lecite per ogni carta in mano, ricevute dal server */
    private capturesByCard: CapturesByCard = {};
    /** Id della carta in mano selezionata, oppure null */
    private selectedHandCardId: number | null = null;
    /** Id delle carte del tavolo selezionate per la presa */
    private selectedCaptureIds: number[] = [];
    /** Vero se il giocatore corrente è quello attivo e può agire */
    private isActive = false;

    constructor(private game: Game, private bga: Bga<ProjectBiagioPlayer, ProjectBiagioGamedatas>) {
    }

    /**
     * Chiamata dal framework all'ingresso nello stato. Se il giocatore è attivo prepara la selezione e i pulsanti
     * della barra di stato; altrimenti mostra solo il titolo di attesa
     */
    onEnteringState(args: PlayerTurnArgs, isCurrentPlayerActive: boolean) {
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
     * Chiamata dal framework all'uscita dallo stato: azzera la selezione e toglie le evidenziazioni dalle carte
     */
    onLeavingState() {
        this.isActive = false;
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.clearCardHighlights();
    }

    /**
     * Gestisce il clic su una carta della propria mano: la seleziona, oppure la deseleziona se era già selezionata.
     * Se per quella carta esiste una sola presa, la preseleziona
     */
    onHandCardClick(cardId: number) {
        if (!this.isActive || !this.capturesByCard[cardId]) {
            return;
        }
        if (this.selectedHandCardId === cardId) {
            this.selectedHandCardId = null;
            this.selectedCaptureIds = [];
        } else {
            this.selectedHandCardId = cardId;
            const availableCaptures = this.capturesByCard[cardId];
            this.selectedCaptureIds = availableCaptures.length === 1 ? [...availableCaptures[0]] : [];
        }
        this.refreshSelectionDisplay();
    }

    /**
     * Gestisce il clic su una carta del tavolo: la aggiunge o la toglie dalla presa in costruzione. Se dopo il clic
     * rimane una sola presa compatibile con la selezione, la completa automaticamente
     */
    onTableCardClick(cardId: number) {
        if (!this.isActive || this.selectedHandCardId === null) {
            return;
        }
        const isAlreadySelected = this.selectedCaptureIds.includes(cardId);
        if (isAlreadySelected) {
            this.selectedCaptureIds = this.selectedCaptureIds.filter(selectedId => selectedId !== cardId);
        } else if (this.getSelectableTableCardIds().includes(cardId)) {
            this.selectedCaptureIds = [...this.selectedCaptureIds, cardId];
        } else {
            return;
        }

        const compatibleCaptures = this.getCompatibleCaptures();
        if (compatibleCaptures.length === 1) {
            this.selectedCaptureIds = [...compatibleCaptures[0]];
        }
        this.refreshSelectionDisplay();
    }

    /**
     * Prese lecite della carta in mano selezionata che contengono tutte le carte del tavolo già scelte
     */
    private getCompatibleCaptures(): number[][] {
        if (this.selectedHandCardId === null) {
            return [];
        }
        const availableCaptures = this.capturesByCard[this.selectedHandCardId];
        return availableCaptures.filter(capture => this.selectedCaptureIds.every(selectedId => capture.includes(selectedId)));
    }

    /**
     * Id delle carte del tavolo che il giocatore può ancora cliccare per completare una presa lecita
     */
    private getSelectableTableCardIds(): number[] {
        const idsOfCompatibleCaptures = this.getCompatibleCaptures().flat();
        return [...new Set(idsOfCompatibleCaptures)].filter(cardId => !this.selectedCaptureIds.includes(cardId));
    }

    /**
     * Vero se le carte selezionate coincidono esattamente con una presa lecita
     */
    private isSelectionACompleteCapture(): boolean {
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
     * stato e stato dei pulsanti "Prendi / Posa la carta" e "Annulla"
     */
    private refreshSelectionDisplay() {
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
            // Nessuna presa possibile: la carta può solo essere posata sul tavolo
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
     * Invia l'azione al server con la carta giocata e le carte da prendere
     */
    private onConfirmClick() {
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
        // lo considera allora una posa sul tavolo
        const actionArguments: { card_id: number; capture_ids?: number[] } = { card_id: this.selectedHandCardId };
        if (!noCapturePossible) {
            actionArguments.capture_ids = this.selectedCaptureIds;
        }
        this.bga.actions.performAction('actPlay', actionArguments);
    }

    /**
     * Annulla la selezione corrente (carta in mano e carte del tavolo) senza contattare il server
     */
    private onCancelClick() {
        this.selectedHandCardId = null;
        this.selectedCaptureIds = [];
        this.refreshSelectionDisplay();
    }

    /**
     * Toglie dalle carte tutte le classi di evidenziazione usate dalla selezione
     */
    private clearCardHighlights() {
        document.querySelectorAll('.scopa-card--playable, .scopa-card--selected, .scopa-card--selectable').forEach(cardElement => {
            cardElement.classList.remove('scopa-card--playable', 'scopa-card--selected', 'scopa-card--selectable');
        });
    }

    /**
     * Aggiunge una classe CSS alla carta con l'id indicato (se esiste)
     */
    private addClass(cardId: number, className: string) {
        document.getElementById(cardElementId(cardId))?.classList.add(className);
    }

    /**
     * Abilita o disabilita un pulsante della barra di stato e ne cambia il testo
     */
    private setButtonState(button: HTMLElement | null, isEnabled: boolean, label: string) {
        if (!button) {
            return;
        }
        // Non si usa la classe 'disabled' di BGA: il pulsante resta cliccabile e il controllo è nella callback
        button.classList.remove('disabled');
        button.classList.toggle('scopa-button-inactive', !isEnabled);
        button.textContent = label;
    }

    /**
     * Mostra o nasconde un pulsante della barra di stato
     */
    private setButtonVisible(button: HTMLElement | null, isVisible: boolean) {
        if (button) {
            button.style.display = isVisible ? '' : 'none';
        }
    }
}
