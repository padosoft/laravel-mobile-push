# progress.md — stato del progetto

> Memoria operativa tra sessioni. **Chi riprende il lavoro legge prima questo file, poi `lesson.md`.**
> La tabella di stato qui sopra la aggiorna solo l'orchestratore. Gli agenti aggiungono righe in fondo al **Registro** con un append (`>>`), senza riscrivere il file.

## Dove siamo

- **Porta corrente:** Contratto → Alpha (invio diretto)
- **Milestone corrente:** M0 chiuso (commit `2f25206`); ondata 1 in corso su M1–M3
- **Prossimo passo concreto:** vedi "Ondata in corso"
- **Piano:** `docs/PLAN.md` (v2 approvata il 2026-11-20)
- **Repository remoto:** `https://github.com/padosoft/laravel-mobile-push` (privato), branch `main`. Push dopo ogni ondata integrata.

## Stato per milestone

| Milestone | Stato | Note |
|---|---|---|
| M0 Contratto e scheletro | fatto (salvo `docs/contracts`, in W1-H) | Scheletro, dipendenze, contratti `src/Core`, AGENTS.md, CI |
| M1 Trasporto HTTP/2 + mock | in corso (W1-A) | |
| M2 APNs | in corso (W1-B auth, W1-C messaggi, W1-E classificatore) | Driver e Sender nell'ondata 2 |
| M3 FCM | in corso (W1-B auth, W1-D messaggi, W1-E classificatore) | Driver nell'ondata 2 |
| M4 Strato Laravel (profilo leggero) → 0.1 alpha | da fare | |
| M5 Invio durevole → 0.5 beta | da fare | |
| M6 Live Activities → RC1 | da fare | |
| M7 Osservabilità, health, credenziali | da fare | |
| M8 Campagne, cap, badge, ripiego, ricevute → RC2 | da fare | |
| M9 Benchmark, README, docs → 1.0 | da fare | |

## Ondata in corso

**Ondata 1** (lanciata 2026-11-20, otto subagenti in parallelo su cartelle disgiunte):

| Id | Modello | Cartelle | Contenuto |
|---|---|---|---|
| W1-A | Opus | `src/Core/Transport/Curl`, `tests/Support/H2Mock`, `tests/Scenario/Transport` | Trasporto `curl_multi`, pool, server mock HTTP/2, indagine su REFUSED_STREAM/GOAWAY |
| W1-B | Sonnet | `src/Core/Auth` (nuovi file), `src/Core/Apns/Auth`, `src/Core/Fcm/Auth` | Signer ES256/RS256, JWT APNs, scambio OAuth Google |
| W1-C | Sonnet | `src/Core/Apns/Message`, `src/Core/Apns/LiveActivity`, `ApnsCompiler`, `src/Core/Message/Support` | Messaggi e compiler APNs, Live Activity, troncamento |
| W1-D | Sonnet | `src/Core/Fcm/Message`, `FcmCompiler`, `tests/Fixtures/fcm` | Messaggi e compiler FCM, snapshot Discovery |
| W1-E | Opus | `src/Core/Apns/Outcome`, `src/Core/Fcm/Outcome`, `src/Core/Retry` | Classificatori degli esiti e pianificatore dei retry |
| W1-F | Sonnet | `src/Core/Guard` | Rate limiter, throttle gate, tre breaker |
| W1-G | Sonnet | `src/Core/Policy`, `src/Core/Privacy` | Modalità, endpoint ufficiali, matrice di capacità, redazione, indice HMAC, cifratura |
| W1-H | Haiku | `docs/contracts`, boilerplate repository | Documenti dei contratti |

**Dopo l'ondata 1 (orchestratore):** integrare, `composer check`, commit locale, aggiornare questa tabella.
**Ondata 2 prevista:** interfaccia `Driver`, `ApnsDriver`, `FcmDriver`, `Sender` (compila → guardia di modalità → trasporto → classifica → pianifica retry → report), client canali APNs, client iscrizioni topic FCM, scenari end-to-end sul server mock.

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
- 2026-11-20 [M0] chiuso: suite verde (7 test), commit locale 2f25206. Aggiunto `SendStatus::Failed` (noto non accettato, tentativi finiti) distinto da `Rejected`. Ondata 1 lanciata.
- 2026-11-20 22:53 [W1-F] done: src/Core/Guard (RateLimiter, ThrottleGate, AuthBreaker, DestinationQualityBreaker, Guards facade, GuardStore + in-memory); 25 tests green; Laravel-cache GuardStore still to write.
- 2026-11-20 22:55 [W1-G] done: Core/Policy (ModeResolver, SafeModeGuard, EndpointCatalog+Override, CapabilityMatrix) e Core/Privacy (KeyRing, TokenIndex, TokenCipher, TokenMasker, Redactor) con 60 test verdi, phpstan max pulito, Arch verde.
- 2026-11-20 22:56 [W1-H] done: docs/contracts (outcomes, identity, ledger, classification, retry, modes, messages, README con le tre domande del gate) e boilerplate (CONTRIBUTING, SECURITY, CHANGELOG, template issue e PR). Discrepanze piano/codice annotate nei documenti e da confermare: Failed assente dal piano §4.1, VoIP assente dal §4.2, NotSent con risposta HTTP, isTerminal su OutcomeUnknown, SendReport a passata singola. Ledger, classificatore e retry restano solo piano (nessun codice in quelle cartelle).
- 2026-11-20 [orchestratore] Repository GitHub agganciato; push di M0 (2f25206) e delle parti concluse dell'ondata 1: W1-F, W1-G, W1-H (4146fd4). In corso: W1-A, W1-B, W1-C, W1-D, W1-E.
