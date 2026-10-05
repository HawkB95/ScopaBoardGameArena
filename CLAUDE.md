# Scopa su Board Game Arena Studio (`projectbiagio`)

Gioco di carte Scopa per BGA Studio, nuovo framework 2025+. Stato, regole e cose da sapere: leggi **`doc/STATO_PROGETTO.md`**
(poi `doc/ASSUMPTIONS.md`, `doc/ALPHA_CHECKLIST.md`, `doc/CREDITS.md`).

## Regole di stile (richieste dall'utente)

- **Commenti e spiegazioni sempre in italiano**, in tutti i file (PHP, TypeScript, SCSS) e nelle risposte.
- **Nomi di variabili estesi** (`$playerId`, non `$pid`) e **un commento che spiega cosa fa ogni funzione**.
- I testi mostrati ai giocatori (`clienttranslate`, `_()`) restano in inglese, come richiede BGA.
- **Non toccare i file e i commenti generati/scaricati da BGA** (`_ide_helper.php`, `bga-framework.d.ts`, `LICENCE_BGA`,
  intestazione di `Game.php`, `gameoptions.jsonc`, `gamepreferences.jsonc`, README del template): l'utente vuole lasciarli intatti.
- **Commit solo su richiesta esplicita**, uno per blocco di lavoro, con messaggio in italiano.

## Struttura

- `modules/php/Rules/ScopaRules.php`: regole pure, senza framework (prese, primiera, punteggi). Test: `php tests/ScopaRulesTest.php`.
- `modules/php/Game.php`: setup, squadre, dati per il client. `modules/php/States/`: stati di gioco (id 3, 4, 10, 90, 95, 98).
- `src/ts/` (Game.ts, CardView.ts, States/PlayerTurn.ts) e `src/scss/Game.scss`: client. Si compila con `npm run build` in
  `modules/js/Game.js` e `projectbiagio.css` (file generati, non modificarli a mano).
- `img/cards.jpg`: sprite delle carte napoletane (pubblico dominio, vedi `doc/CREDITS.md`). Unico stile di carte: niente carte
  francesi, niente preferenze utente, niente opzioni di tavolo (punteggio obiettivo fisso: 11 con 2 giocatori, 21 con 4).
- `bga-framework.d.ts`: tipi ufficiali dell'API client BGA, scaricato da Studio con WireFerry (serve solo a compilare).

## Deploy e prove

- Il deploy lo fa l'utente con il comando VS Code **WireFerry: Upload Project**. `.vscode/wireferry.json` contiene la
  password SFTP: **non leggerla né stamparla**. Per scaricare un singolo file da Studio usare **Download Active File**; mai
  **Download Project** (sovrascrive i file locali).
- PHP è installato con winget; se manca nel PATH aggiungere `C:\Users\falco\AppData\Local\Microsoft\WinGet\Links`.
- Dopo modifiche a `.jsonc` o `dbmodel.sql`: Reload su Studio e tavolo nuovo.

## Errori già incontrati (non ripeterli)

1. `Deck::countCardInLocation` restituisce **testo**: usare `Game::countCardsIn()` (intero).
2. BGA scarta gli **array vuoti** inviati dal client: i parametri array PHP vanno con default (`array $capture_ids = []`).
3. Non riabilitare il pulsante BGA togliendo la classe `disabled`: usare `scopa-button-inactive` e validare nella callback.
4. I numeri dal server possono essere stringhe: `Number()` prima di sommare.
5. Gli errori in console `web-vitals`, `play() failed`, `scrollHeight` sono di BGA.
6. Nei comandi bash lunghi con più heredoc il tool può troncare i file: per file grandi usare lo strumento Write.

## Stato attuale

Gioco completo a 2 e a 4 giocatori; richiesta PRIVATE ALPHA inviata il 4 ottobre 2026 (versione 261004-2041), in attesa di
risposta BGA (licenza "public domain", possibile richiesta di rinomina in `scopa`).

Il 5 ottobre 2026 è stata fatta una revisione generale (commit `374c376`, `f061944`, `7404d20`, `461d201`): codice
ripulito e commentato, solo carte napoletane, nomi napoletani traducibili nel log (argomenti annidati con `i18n`, **da
verificare su Studio**), tipi BGA ufficiali, punteggio obiettivo fisso, file superflui eliminati. Dopo queste modifiche serve
Reload su Studio e un tavolo nuovo; l'utente sta provando il gioco su Studio. Prossimi passi in `doc/STATO_PROGETTO.md`.
Un riepilogo generale (file, origine, stati, flusso, regole) è in `C:/Users/falco/Desktop/STRUTTURA_PROGETTO.md`, fuori dal progetto.
