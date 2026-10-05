/*
 * Tipi dei dati scambiati tra server (PHP) e client (TypeScript).
 */

/** Una carta come la invia il server: id univoco, seme (1 denari, 2 coppe, 3 bastoni, 4 spade) e valore 1..10. */
interface ScopaCard {
    id: number;
    suit: number;
    rank: number;
}

/** Dati di un giocatore: oltre a quelli base, la squadra di appartenenza (1 o 2). */
interface ProjectBiagioPlayer extends Player {
    team: number;
}

/** Dati di gioco inviati al caricamento della pagina (vedi Game::getAllDatas in PHP). */
interface ProjectBiagioGamedatas extends Gamedatas<ProjectBiagioPlayer> {
    /** Vero quando si gioca in 4 a coppie. */
    isTeamGame: boolean;
    /** Id dei giocatori nell'ordine dei posti (e dei turni). */
    playerOrder: number[];
    /** Nome di ogni squadra (id squadra => nomi dei giocatori). */
    teamNames: Record<number, string>;
    /** Punteggio da raggiungere per vincere. */
    target: number;
    /** Id del mazziere. */
    dealer: number;
    /** Mano del giocatore corrente (vuota per gli spettatori). */
    hand: ScopaCard[];
    /** Carte sul tavolo. */
    table: ScopaCard[];
    /** Numero di carte in mano a ogni giocatore. */
    handCounts: Record<number, number>;
    /** Numero di carte rimaste nel mazzo. */
    deckCount: number;
    /** Numero di carte prese da ogni squadra. */
    capturedCounts: Record<number, number>;
    /** Numero di scope fatte da ogni squadra nella smazzata. */
    scope: Record<number, number>;
}

/**
 * Prese lecite per ogni carta in mano: id carta => elenco di prese, ognuna è un elenco di id di carte del tavolo.
 * Un elenco vuoto significa che la carta non può prendere (si può solo posare sul tavolo).
 */
type CapturesByCard = Record<number, number[][]>;

/** Argomenti dello stato PlayerTurn: le prese arrivano nella parte privata, solo al giocatore attivo. */
interface PlayerTurnArgs {
    captures?: CapturesByCard;
    _private?: { captures: CapturesByCard };
}
