# progress.md — stato del progetto

> Memoria operativa tra sessioni. **Chi riprende il lavoro legge prima questo file, poi `lesson.md`.**
> La tabella di stato qui sopra la aggiorna solo l'orchestratore. Gli agenti aggiungono righe in fondo al **Registro** con un append (`>>`), senza riscrivere il file.

## Dove siamo

- **Porta corrente:** Contratto → Alpha (invio diretto)
- **Milestone corrente:** M0 in chiusura, M1–M3 in avvio
- **Prossimo passo concreto:** vedi "Ondata in corso"
- **Piano:** `docs/PLAN.md` (v2 approvata il 2026-11-20)
- **Repository remoto:** nessuno collegato. Il lavoro vive solo nel container finché non viene agganciato un repository GitHub (es. `padosoft/laravel-mobile-push`).

## Stato per milestone

| Milestone | Stato | Note |
|---|---|---|
| M0 Contratto e scheletro | in corso | Contratti `src/Core` scritti; restano `docs/contracts/*.md`, CI |
| M1 Trasporto HTTP/2 + mock | da fare | |
| M2 APNs | da fare | |
| M3 FCM | da fare | |
| M4 Strato Laravel (profilo leggero) → 0.1 alpha | da fare | |
| M5 Invio durevole → 0.5 beta | da fare | |
| M6 Live Activities → RC1 | da fare | |
| M7 Osservabilità, health, credenziali | da fare | |
| M8 Campagne, cap, badge, ripiego, ricevute → RC2 | da fare | |
| M9 Benchmark, README, docs → 1.0 | da fare | |

## Ondata in corso

_Nessuna ancora lanciata._

## Come verificare

```bash
export COMPOSER_ALLOW_SUPERUSER=1
composer install
composer check        # pint --test, phpstan max, pest
```

## Decisioni prese (non rimettere in discussione senza il proprietario)

- Nome `padosoft/laravel-mobile-push`; PHP 8.3+, Laravel 12–13.
- Scope v1: APNs + FCM + Live Activities (broadcast incluso). Web Push dopo la 1.0.
- Tutti i moduli stabili nella 1.0, rilascio per porte.
- Profilo leggero di default (best effort), profilo durevole a scelta.
- Nessun cambio automatico di ambiente APNs: ambiente dichiarato, quarantena, sonda esplicita.
- Signer interno (OpenSSL) dietro interfaccia `Signer`; niente libreria JOSE a runtime; `firebase/php-jwt` solo in dev per verifica incrociata.
- Piattaforma sempre esplicita in API e helper; il solo comando interattivo `mobile-push:send` può proporla e chiedere conferma.
- Helper globali `mobile_push()` e `mobile_push_to_token()`.

## Registro (append-only)

- 2026-11-20 [M0] Piano v2 approvato; scheletro, dipendenze e contratti condivisi di `src/Core` creati dall'orchestratore.
