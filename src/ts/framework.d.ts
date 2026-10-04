/*
 * Tipi minimi dell'API client di BGA, usati solo per far compilare TypeScript in locale.
 * Il file ufficiale `bga-framework.d.ts` non è presente nel progetto: se lo scarichi dal server
 * puoi eliminare questo file e rimuovere le dichiarazioni duplicate.
 */

/** Traduce un testo nella lingua del giocatore (funzione globale di BGA). */
declare function _(text: string): string;

/** Libreria globale di BGA con i componenti legacy (counter, stock, ...). */
declare const ebg: any;

/** Dati base di un giocatore inviati dal server. */
interface Player {
    id: number;
    name: string;
    color: string;
    score: number | string;
}

/** Dati di gioco inviati dal server al caricamento della pagina (risultato di getAllDatas). */
interface Gamedatas<PlayerType = Player> {
    players: Record<number, PlayerType>;
    gamestate: any;
    [key: string]: any;
}

interface EmptyGamePlayer extends Player {}
interface EmptyGameGamedatas extends Gamedatas<EmptyGamePlayer> {}

/** Oggetto `bga` passato al costruttore del gioco: tutta l'API del client (states, actions, statusBar, ...). */
type Bga<PlayerType = Player, GamedatasType = Gamedatas<PlayerType>> = any;
