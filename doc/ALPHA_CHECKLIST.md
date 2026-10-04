# Checklist per la richiesta dello stato ALPHA

## Fatto nel codice

- [x] `gameinfos.jsonc`: nome "Scopa", nessun editore, 2 o 4 giocatori (consigliato 2), 20 minuti stimati.
- [x] `giveExtraTime()` dopo ogni giocata (`States/NextPlayer.php`).
- [x] Statistiche di tavolo e di giocatore (`stats.jsonc`).
- [x] Nessun `console.log` nel client.
- [x] Zombie di livello 1 (mossa casuale legale) in `States/PlayerTurn.php`.
- [x] Interruttore di test `TEST_SINGLE_DEAL` rimosso.
- [x] Licenza delle immagini: carte di pubblico dominio (vedi `CREDITS.md`).
- [x] Immagini obbligatorie generate: `doc/media/box.png` (280x280) e `doc/media/icon.png` (50x50).

## Da fare su BGA Studio (interfaccia web)

Percorso: `studiogame?game=projectbiagio` → **Game Metadata Manager**.

1. **Media**: caricare `doc/media/box.png` (Box) e `doc/media/icon.png` (Icon). Ogni finestra di caricamento riporta
   le specifiche esatte: se differiscono da quelle sopra, valgono quelle della finestra.
2. **Metadata**: impostare **Zombie Mode level = 1** (è obbligatorio, senza non si può chiedere l'ALPHA).
   Altri campi consigliati: anno 1500 circa non documentato (lasciare vuoto se incerto), designer "Tradizionale",
   artista "Florixc (pubblico dominio)".
3. **Tags**: servono **almeno 2 tag**. Usare la casella "Find a tag" (non cliccare le categorie). Proposte:
   carte (Card game), tradizionale/classico, 2 giocatori e a squadre.
4. **Descrizione** (da incollare nel campo apposito):
   > Scopa è il classico gioco di carte italiano di prese. Si gioca con il mazzo da 40 carte napoletane: a
   > turno si gioca una carta e, se possibile, si prendono dal tavolo la carta di pari valore oppure un gruppo di
   > carte la cui somma è uguale al valore giocato. Chi svuota il tavolo fa scopa. A fine smazzata si assegnano
   > punti per scope, settebello, primiera, denari e carte. Si gioca in 2 oppure in 4 a coppie.
5. Fare **Reload game informations / statistics / options** dopo ogni modifica ai file `.jsonc`.
6. `studiogame?game=projectbiagio` → **Build a new release version** → **Commit & build a new version now**
   (dopo aver caricato i file con WireFerry).
7. **Request ALPHA status**: risolvere o ignorare gli avvisi elencati. Il campo "Zombie Mode level" e i tag
   sono bloccanti.
8. **Licenza**: il gioco non ha editore e le regole sono tradizionali. Dopo la richiesta BGA invia un ticket per
   email: rispondere indicando che Scopa è un gioco tradizionale italiano senza editore, con regole di pubblico
   dominio e carte di pubblico dominio, e chiedere la classificazione **"public domain"**.

## Controlli finali prima di inviare

- [ ] Partita completa a 2 giocatori fino al target (11 punti) senza errori.
- [ ] Partita completa a 4 giocatori a coppie fino al target (21 punti) senza errori.
- [ ] Uscita di un giocatore a metà partita: lo zombie gioca e la partita prosegue.
- [ ] Interfaccia leggibile su schermo stretto (telefono) con la mano da 10 carte.
- [ ] Nessun errore nel log di Studio ("BGA unexpected exceptions logs").
