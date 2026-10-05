# Stato del progetto - Scopa su BGA Studio

Ultimo aggiornamento: 5 ottobre 2026.

## Dove siamo

Il gioco è **completo e funzionante** a 2 giocatori e a 4 giocatori a coppie. È stata inviata la richiesta di stato
**PRIVATE ALPHA** per la versione `261004-2041` di `projectbiagio`: BGA risponde per email entro 24 ore.

## Cosa è stato fatto

| Fase | Contenuto | Stato |
|------|-----------|-------|
| 0 | Repo git, documentazione, toolchain TypeScript | fatto |
| 1 | Motore regole `modules/php/Rules/ScopaRules.php` + test `tests/ScopaRulesTest.php` | fatto |
| 2 | Server: `Game.php`, stati `NewDeal`, `DealHands`, `PlayerTurn`, `NextPlayer`, `EndDeal`, `EndScore` | fatto e provato |
| 3 | Client TypeScript (tavolo, mano, selezione prese, animazioni, banner "SCOPA!") | fatto e provato |
| 4 | 4 giocatori a coppie (10 carte a testa, tavolo vuoto, squadre, etichette Partner/Opponent) | fatto e provato |
| 5 | Carte napoletane da sprite (unico stile), CSS per telefoni | fatto |
| 6 | Metadati, immagini (`doc/media/box.png`, `icon.png`), richiesta PRIVATE ALPHA | richiesta inviata |

**5 ottobre 2026 - revisione generale** (nessuna nuova funzione): codice ripulito e commentato; rimosse le carte francesi e la
preferenza "Card style"; rimossa l'opzione "Target score" (obiettivo fisso 11/21); log con nomi di carta traducibili al posto
dei simboli francesi; tipi client dal file ufficiale `bga-framework.d.ts`; eliminati `libs.ts`, `AUTHOR_QUESTIONS.md` e i
README del template. Da verificare su Studio: il log con i nomi annidati e il Reload senza opzioni/preferenze.

Non è stato fatto, per scelta: il riepilogo di fine smazzata in una finestra (basta il log di gioco).

## Regole scelte (dettaglio in `ASSUMPTIONS.md`)

- 2 giocatori: 3 carte a testa e 4 scoperte sul tavolo (da rifare con 3 o più Re). 4 giocatori: 10 carte a testa, tavolo vuoto.
- Presa: se c'è una carta di pari valore si deve prendere quella; altrimenti qualunque somma uguale. Presa obbligatoria se possibile.
- Punti: scope, settebello, primiera, denari (6 o più), carte (21 o più). Parità = nessun punto.
- Target: 11 punti a 2 giocatori, 21 a 4 (fissi, nessuna opzione di tavolo). Vince chi ha raggiunto il target ed è strettamente in testa.
- Nessun "Pass". Ordine di turno secondo il tavolo BGA.

## Come si lavora

- **Deploy**: in VS Code, comando **WireFerry: Upload Project** (configurazione in `.vscode/wireferry.json`, fuori da git perché contiene la password).
- **Build client**: `npm run build` (TypeScript con rollup in `modules/js/Game.js`, SCSS in `projectbiagio.css`). I due file generati sono nel repo.
- **Test regole**: `php tests/ScopaRulesTest.php`. PHP 8.4 è installato con winget; se `php` non si trova, aggiungere al PATH la cartella `...\AppData\Local\Microsoft\WinGet\Links`.
- **Provare il gioco**: Studio → lobby → Create → Express start; con 4 giocatori impostare il numero prima di avviare. Dopo ogni modifica ai file `.jsonc` o a `dbmodel.sql`: Reload su Studio e creare un tavolo **nuovo**.

## Cose da sapere (errori già incontrati)

1. Il componente Deck di BGA restituisce i conteggi come **testo**: usare sempre `Game::countCardsIn()`, mai confronti `=== 0` diretti.
2. BGA **scarta gli array vuoti** nelle azioni del client: i parametri array lato PHP devono avere un valore predefinito (vedi `actPlay`).
3. Il pulsante "disabled" di BGA non si riabilita togliendo la classe: si usa `scopa-button-inactive` e la callback valida.
4. I numeri inviati dal server possono arrivare come testo: convertire con `Number()` prima di sommare.
5. Gli errori `web-vitals`, `play() failed`, `scrollHeight` in console sono di BGA, non nostri.

## Prossimi passi

1. Aspettare la **email di BGA** (entro 24 ore dalla richiesta).
2. Rispondere sulla **licenza**: gioco tradizionale italiano senza editore, regole e carte di pubblico dominio,
   classificazione "public domain" (testo in `ALPHA_CHECKLIST.md`, punto 8).
3. **Nome del progetto**: BGA ha segnalato che "Scopa" non coincide con `projectbiagio`. Se lo chiedono, contattare il
   supporto Studio per un progetto `scopa` e copiarci il gioco.
4. Compilare in Game Metadata Manager le caratteristiche mancanti (complexity, luck, strategy, diplomacy)
   se non fatto, ed eventuali BGG id (`bgg_id` in `gameinfos.jsonc`) se Scopa ha una pagina su BoardGameGeek.
5. Dopo l'ALPHA: partite di prova più lunghe, controllo traduzioni in italiano, eventuali rifiniture grafiche, e per
   la Beta le immagini Title e Banner (vedi `ALPHA_CHECKLIST.md`).

## Idee rimaste fuori (possibili opzioni future)

Carte viste, coppie a carte scoperte, torneo a eliminazione con spareggi, riepilogo di fine smazzata in finestra,
traduzione italiana dei messaggi di log.
