# progress.md — stato del progetto

> Memoria operativa tra sessioni. **Chi riprende il lavoro legge prima questo file, poi `lesson.md`.**
> La tabella di stato qui sopra la aggiorna solo l'orchestratore. Gli agenti aggiungono righe in fondo al **Registro** con un append (`>>`), senza riscrivere il file.

## Dove siamo

- **Porta corrente:** Contratto → Alpha (invio diretto)
- **Milestone corrente:** ondate 1 e 2 concluse; ondata 3 in corso (W3-A manager/facade/eventi, W3-L Live Activities, W3-FIX correzioni)
- **Prossimo passo concreto:** vedi "Ondata in corso"
- **Piano:** `docs/PLAN.md` (v2 approvata il 2026-11-20)
- **Repository remoto:** `https://github.com/padosoft/laravel-mobile-push` (privato), branch `main`. Push dopo ogni ondata integrata.

## Stato per milestone

| Milestone | Stato | Note |
|---|---|---|
| M0 Contratto e scheletro | fatto (salvo `docs/contracts`, in W1-H) | Scheletro, dipendenze, contratti `src/Core`, AGENTS.md, CI |
| M1 Trasporto HTTP/2 + mock | fatto | `CurlTransport`, pool per chiave, server mock Node; 89 test. Vedi `docs/transport.md` |
| M2 APNs | in corso | Fatti auth, messaggi, compiler, classificatore, client canali. Manca il driver (W2-A) |
| M3 FCM | in corso | Fatti auth, messaggi, compiler, classificatore, client topic. Manca il driver (W2-A) |
| M4 Strato Laravel (profilo leggero) → 0.1 alpha | da fare | |
| M5 Invio durevole → 0.5 beta | in corso | Fatti: registro dispositivi (W2-P2), adattatori cache (W2-P1). In corso: ledger (W2-P4). Mancano relay, eleggibilità come gate, runtime longevi |
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

**Stato ondata 1 (aggiornato):** conclusi W1-B, W1-C, W1-D, W1-F, W1-G, W1-H (483 test verdi insieme, PHPStan max pulito). In corso: W1-A (trasporto) e W1-E (classificatori e retry planner).

**Ondata 2-pre** (lanciata 2026-11-20, compiti che non dipendono da trasporto e classificatori):

| Id | Modello | Cartelle | Contenuto |
|---|---|---|---|
| W2-P1 | Sonnet | `src/Laravel/Cache`, `src/Laravel/Privacy` | `CacheTokenStore`, `CacheGuardStore`, chiavi e binding di riservatezza |
| W2-P2 | Sonnet | `src/Laravel/Devices`, migration dispositivi | Registro dispositivi, generazioni, quarantena/invalidazione, eleggibilità |
| W2-P3 | Sonnet | `src/Core/Apns/Channels`, `src/Core/Fcm/Topics` | Client gestione canali broadcast APNs, client iscrizioni topic FCM |

**Meccanismo moduli Laravel:** ogni fetta dello strato Laravel è una classe `Module` elencata in `MobilePushServiceProvider::MODULES`; gli agenti non toccano il provider.

**Ondata 3** (lanciata 2026-11-20; ondate 1 e 2 concluse, suite a 1327 test verdi al momento del lancio):

| Id | Modello | Cartelle | Contenuto |
|---|---|---|---|
| W3-A | Opus | `src/Laravel/Sending`, `Facades`, `helpers.php`, `config/mobile-push.php` | PushManager (implementa `PushDispatcher`), PendingSend, facade, helper, config, eventi, cablaggio observer |
| W3-L | Opus | `src/Laravel/LiveActivities` | Registro attività, macchina a stati, token push-to-start/update, canali broadcast |
| W3-FIX | Sonnet | file puntuali in `src/Core` | `ttl(0)` = un solo tentativo; transizioni dei guard con nome; push silenziose FCM verso iOS; observer per chiamata e `with…()` in `SendOptions` |
| W3-B | Opus | `src/Laravel/Queue` | `SendPushJob` (profilo leggero), outbox + `RelayDeliveriesJob` + sweeper (profilo durevole), catena dei timeout |
| W3-D | Sonnet | `src/Core/Receipts`, `src/Laravel/Receipts` (+ aggiunte al ledger) | Marcatori di ricevuta, endpoint, funnel con copertura |
| W3-E | Sonnet | `src/Laravel/Health` | Health (liveness/readiness), metriche Prometheus, controllo credenziali, avvisi operativi |

**Da lanciare quando rientra W3-A** (dipendono dall'API del manager): canali Notification + ripiego su altro canale; `PushFake` e `fakeTransport`; comandi Artisan (install, doctor, send, preview, explain, simulator, connections, circuit, relay, prune, health, credentials:check, forget, export, live-activity:*, channels:*, fcm:topic-subscription); modulo Policies (limite di frequenza, contatore badge); Pulse e Telescope; integrazione finale (config del ledger/health/receipts/queue, cablaggio metriche e avvisi).
**Poi:** campagne (M8), test sui runtime longevi, benchmark, `ConfigSchema` + docs generate, README (M9).

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
- 2026-11-20 22:58 [W1-B] done: PrivateKey/Secret/DerSignature, ES256+RS256 signers, JwtEncoder, in-memory/null token stores, ApnsTokenProvider (20-min floor, refresh-ahead, CAS) e ServiceAccountTokenProvider (scambio asserzione->access token); test unit+scenario verdi, phpstan max pulito. Manca: ADC/metadata.
- 2026-11-20 22:58 [W1-D] done: FcmMessage (+android/androidV2/apns builders, enums), FcmCompiler, FcmPayloadLimits, Discovery rev 20261112 snapshot + schema contract test; 113 tests green, phpstan max clean. Open: PushMessage::data() renumbers integer-like keys (frozen file); counting rule 4096/2048 unverified.
- 2026-11-20 22:59 [W1-C] done: ApnsMessage/LiveActivityMessage/PushType/Priority/InterruptionLevel/TopicResolver/ApnsPayloadLimits/ApnsCompiler, Truncator con scelta intl/PCRE; 225 test verdi (Unit+Scenario), Arch e phpstan max puliti. Dubbi aperti: header id broadcast (apns-request-id vs apns-id), priorità widgets/controls.
- 2026-11-20 23:04 [W1-E] done: ApnsOutcomeClassifier/FcmOutcomeClassifier (cataloghi ApnsReasonCatalog/FcmReasonCatalog con hint), RetryPolicy/RetryBudget/Backoff/RetryPlanner/RetryAfter in src/Core/Retry; 197 test (unit + 9 scenari in tests/Scenario/Outcome) verdi, phpstan max ok. Restano: integrazione nel sender/job, pagina docs/contracts/outcomes.md con la semantica dei motivi.
- 2026-11-20 23:06 [W2-P1] done: Laravel PrivacyModule (indice HMAC e cifrario con keyring separati, derivazione HKDF dalla APP_KEY) e CacheModule (CacheTokenStore cifrato AES-GCM legato alla chiave, CacheGuardStore, CAS con cache lock, degrado a store vuoto con warning 1/min); 22 test verdi, phpstan max e Arch puliti. Chiavi config da aggiungere a config/mobile-push.php (vedi messaggio finale).
- 2026-11-20 [orchestratore] W1-E rientrato (classificatori + retry planner). Lanciato W2-A (Opus): contratto Driver, Sender, ApnsDriver, FcmDriver. W2-P1 rientrato; chiavi cache/privacy aggiunte a config/mobile-push.php. In corso: W1-A, W2-A, W2-P2, W2-P3.
- 2026-11-20 23:08 [W2-P3] done: ChannelClient (create/get/delete/all/reconcile, 403 refresh-once, unknown-create exception, gate) e TopicSubscriptionClient (subscribe/get/list/patch/unsubscribe + *Many, 401 refresh-once, gate) con 48 test verdi, Arch e phpstan max puliti. Non verificati: stringhe di errore Apple per i canali. Il catalogo non ha un URL per l'elemento topicSubscriptions/{topic}: si accoda un segmento all'URL del catalogo.
- 2026-11-20 23:08 [W2-P2] done: src/Laravel/Devices (PushDevice, DeviceRegistrar, DeviceOutcomeApplier, DeviceEligibility, DevicePruner, HasPushDevices, DevicesModule) + migration stub; 31 tests green, phpstan max e Arch puliti. Manca: comando artisan prune, eventi, eleggibilita' consumata dal sender.
- 2026-11-20 [orchestratore] Lanciato W2-P4 (Opus): registro durevole degli effetti (store, macchina a stati, lease, outbox) in src/Core/Ledger e src/Laravel/Ledger; il relay verso il Sender è un compito successivo. In corso: W1-A, W2-A, W2-P2, W2-P3, W2-P4.
- 2026-11-20 [orchestratore] W2-P2 (dispositivi) e W2-P3 (client canali/topic) rientrati; blocco devices aggiunto al config. Backlog minore: EndpointCatalog::fcmTopicSubscriptionItemUrl() dedicato; ReasonCode dedicato per il limite canali APNs (oggi QuotaExceeded). In corso: W1-A, W2-A, W2-P4.
- 2026-11-20 [orchestratore] Verifica d'insieme sulle parti concluse: 781 test verdi, PHPStan max pulito (esclusi i lavori in corso: Transport/Curl, Driver/Sender, Ledger).
- 2026-11-20 23:16 [W1-A] done: CurlTransport (curl_multi, finestra scorrevole, input pigro, pool persistenti con registro LRU/idle/fork, capacità, classificazione NotSent con prova) + server mock HTTP/2 Node (regole, contatori lato server, mTLS, proxy CONNECT) + 37 scenari e 48 unit in tests/Scenario/Transport e tests/Unit/Core/Transport; indagine REFUSED_STREAM/GOAWAY/MAXAGE/upkeep in docs/transport.md. Resta: escludere il gruppo 'slow' di default in phpunit.xml (orchestratore), benchmark reali in M9.
- 2026-11-20 [orchestratore] ONDATA 1 CONCLUSA: W1-A rientrato (trasporto). Scoperta chiave: libcurl 8.5 rinvia da solo le richieste con corpo in memoria (duplicato reale misurato); il trasporto ora invia ogni corpo in streaming, così il server riceve la richiesta una sola volta. Gruppo `slow` escluso dalla suite di default (composer test:slow). In corso: W2-A, W2-P4.
- 2026-11-20 [orchestratore] Installate in dev: open-telemetry/api, google/auth, laravel/pulse, laravel/telescope, spatie/laravel-health. Lanciati W2-P5 (Sonnet: ispezione credenziali + adattatore google/auth) e W2-P6 (Sonnet: sink di log channel/database/OpenTelemetry). In corso: W2-A, W2-P4, W2-P5, W2-P6.
- 2026-11-20 23:24 [W2-P5] done: src/Core/Credentials (ApnsCertificateInspector con estensioni Apple, ApnsKeyInspector, ServiceAccountInspector, CredentialCheck, CredentialIdentity) e Fcm/Auth/GoogleAuthLibraryProvider; 41 test verdi, Arch e phpstan max puliti. Non verificati: certificati Apple reali (le estensioni sono decodificate best-effort).
- 2026-11-20 [orchestratore] W2-P5 rientrato (ispezione credenziali + adattatore google/auth, 41 test). Non verificato su certificati Apple reali: layout dell'estensione topic e convenzioni del CN. In corso: W2-A, W2-P4, W2-P6.
- 2026-11-20 23:28 [W2-P6] done: src/Laravel/Logging (LogRecord+factory con redazione, SinkManager con buffer/contatori/isolamento, sink log/database/otel, PushLog, LogPruner, LoggingModule) + migration logs; 43 test verdi, phpstan max e Arch puliti. Manca: aggancio dal Sender (SinkManager::recordResult), comando prune, chiavi config in config/mobile-push.php.
- 2026-11-20 23:28 [W2-A] done: contratto Driver, Sender (finestre lazy, scadenza→modalità→gate→guardie→compila→bearer→trasporto→classifica→refresh/failover→planner), SendOptions/SendItem/SendObserver/DestinationGate, ApnsDriver/ApnsConfig/ApnsCredential (token e mTLS), FcmDriver/FcmConfig, PoolKey, MessageIds (UUIDv5); 71 test (15 scenari + E2E su CurlTransport/mock HTTP2) + 1M nel gruppo slow; phpstan max e Arch puliti. Restano: escludere il gruppo slow in phpunit.xml, ttl(0) da decidere.
- 2026-11-20 [orchestratore] W2-P6 rientrato (sink log/database/OpenTelemetry, 43 test); blocco log aggiunto al config. Da cablare in M4: SinkManager::recordResult() dal SendObserver, passando i segreti a runtime (bearer, marcatore di ricevuta) nel contesto 'secrets'. In corso: W2-A, W2-P4.
- 2026-11-20 23:30 [W2-P4] done: registro durevole degli effetti (src/Core/Ledger: contratti EffectStore/DeliveryStore/Ledger, macchina a stati, PayloadFingerprint, MessageVault, InMemoryLedger; src/Laravel/Ledger: DatabaseLedger su query builder con claim SKIP LOCKED/ottimistico, LedgerModule, due migration); contratto condiviso eseguito su memoria, DB ottimistico e DB skip-locked, 300 test verdi (+1 milione in gruppo slow, 51 s). Manca: relay/job, chiavi config in config/mobile-push.php, prova su MySQL/PostgreSQL reali.
- 2026-11-20 [orchestratore] W2-A rientrato (Driver, Sender, ApnsDriver, FcmDriver; end-to-end su CurlTransport + mock). Suite completa: 1327 test verdi. Aggiunti i contratti src/Laravel/Contracts (PushDispatcher, QueuedSender, QueueOptions). Difetti da correggere (W3-FIX): PushMessage::ttl(0) non invia mai; transizioni di Guards::before senza nome del breaker.
- 2026-11-20 [orchestratore] ONDATA 2 CONCLUSA: W2-P4 rientrato (ledger: 299 test; concorrenza provata solo su SQLite, il percorso SKIP LOCKED di MySQL/PostgreSQL va provato in CI). Lanciata ondata 3: W3-A (Opus: PushManager, PendingSend, facade, helper, config, eventi, cablaggio observer), W3-L (Opus: modulo Live Activities), W3-FIX (Sonnet: ttl(0), transizioni dei guard con nome, push silenziose FCM verso iOS, observer per chiamata in SendOptions). Chiavi config del ledger da aggiungere quando W3-A rilascia config/mobile-push.php. Lista MODULES estesa (Queue, Notifications, Policies, Pulse, Telescope, Testing).
- 2026-11-20 23:38 [W3-FIX] done: ttl(0) ora-o-mai (RetryBudget::$singleAttempt, APNs apns-expiration 0, FCM 0s), transizioni guardia con origine (GuardSource/SourcedTransition), FCM silent con blocco apns per iOS, SendOptions con observers per chiamata e helper with*(); suite verde, phpstan max e Arch puliti.
- 2026-11-20 [orchestratore] W3-FIX rientrato: ttl(0) = un solo tentativo con scadenza no-storage; transizioni dei guard con origine; push silenziose FCM verso iOS con blocco apns; SendOptions con observer per chiamata e helper with…(). Suite: 1345 test verdi. Verificato a mano che il planner dà Expired per ttl(60)+503 (dubbio sollevato dal subagente, non riprodotto). Test instabile da tenere d'occhio: tests/Scenario/Transport/ConnectionPoolTest (clientResets) fallito una volta.
- 2026-11-20 23:45 [W3-E] done: src/Laravel/Health (MetricsRecorder, PrometheusExporter + rotta con token, HealthInspector liveness/readiness, MobilePushHealthCheck spatie, CredentialMonitor, OpsAlerter con cooldown, HealthModule); 30 test verdi, phpstan max e Arch puliti. Da cablare: record/recordThrottle/recordGuardTransition + alerter->guardTransition dal Sender, ConnectionCredentialsSource reale, comandi health/credentials:check, evaluate() da scheduler.
- 2026-11-20 [orchestratore] W3-E rientrato (health, metriche Prometheus, avvisi: 30 test). Creato docs/internal/integration-todo.md con chiavi di config e cablaggi pendenti (ledger, health, metriche, avvisi). In corso: W3-A, W3-L, W3-B, W3-D.
- 2026-11-20 23:46 [W3-D] done: ricevute client. Core/Receipts (ReceiptMarker HMAC v1 con key ring dedicato, 55 char, DeliveryReference ULID<->UUID, ClientTimestamp, ClientEvidence); ledger: ClientReceiptStore (deliveryByMessageId, recordClientEvent first-write-wins, effectFunnel/campaignFunnel) su InMemory e Database + migrazione 000012 (message_id, receipt_marker); Laravel/Receipts (module, controller 204/422/429, issuer, eventi, funnel reader, CLIENT_CONTRACT.md). Da fare fuori corsia: il relay deve passare DeliveryReference::messageIdFor(deliveryId) come SendItem.providerMessageId; SendRun potrebbe mettere meta['receipt_marker']='1'; chiavi config in config/mobile-push.php.
- 2026-11-20 [orchestratore] W3-D rientrato (ricevute: marcatore HMAC 55 caratteri, endpoint, funnel con copertura; suite a 1455 test). Lanciato W3-FIX2 (Sonnet): il ledger assegna un UUIDv4 message_id a ogni delivery all'inserimento (serve ad apns-id e alle ricevute anche con risposta persa), flag receipt_marker nei risultati, test instabile del pool. In corso: W3-A, W3-L, W3-B, W3-FIX2.
- 2026-11-20 23:58 [W3-FIX2] done: message_id UUIDv4 assegnato dal ledger all'insert (Core\Ledger\MessageId, Delivery::$messageId, ClaimedDelivery::providerMessageId()), ReceiptRecorder cerca prima per message_id (fallback ULID), meta receipt_marker='1' da SendRun, test ConnectionPoolTest deterministico; suite 1600 verdi, phpstan/Arch puliti.
- 2026-11-20 23:58 [W3-B] done: src/Laravel/Queue (QueueModule, QueuedSender per profilo; leggero: SendPushJob cifrato, chunk per numero e byte, continuazioni solo con i Deferred e i loro budget, mai release, tries=1; durevole: outbox con dispatch dopo il commit della connessione del ledger, RelayDeliveriesJob con lease/markAttemptStarted/registrazione per esito prima dell'ack, follow-up unico per effetto, SweepLedgerJob schedulato ogni minuto, receipt marker; QueueTimeouts per doctor); 51 test verdi, phpstan max e Arch puliti. Restano: chiavi config in config/mobile-push.php, comando relay/doctor, eleggibilita' registro nel profilo leggero (gate del manager).
- 2026-11-20 [orchestratore] W3-FIX2 rientrato: il ledger assegna un UUIDv4 message_id all'inserimento; flag receipt_marker nei risultati; ConnectionPoolTest reso deterministico (60/60 sotto carico). Suite: 1600 test verdi. In corso: W3-A, W3-L, W3-B.
- 2026-11-20 23:59 [W3-A] done: PushManager (PushDispatcher, scope, runtime/resolver connections, LRU dei driver, BindingMismatch), PendingSend (to/toToken/toTopic, multi-connessione, queue via QueuedSender), ConnectionFactory (segreti, CredentialCheck, generazione), gate eleggibilità registro + consenso (eligibleUsing), SendingObserver (registro, sink, 10 eventi), SendingModule, facade, helper, config completo; 62 test (14 scenari) verdi, suite intera 1608 verdi, phpstan max e Arch puliti. Restano: job/queue (altro task), profilo durevole.
- 2026-11-20 [orchestratore] W3-B rientrato (code: SendPushJob profilo leggero, outbox + RelayDeliveriesJob + sweeper profilo durevole, catena dei timeout; 51 test). Non verificato su Redis/SQS/Horizon reali. Lanciati W4-C (Opus: campagne) e W4-P (Sonnet: limite di frequenza e contatore badge). In corso: W3-A, W3-L, W4-C, W4-P.
- 2026-11-21 00:01 [W3-L] done: modulo Live Activities (src/Laravel/LiveActivities + 3 migration): registro push-to-start/token attività con generazioni, ActivityManager start/update/end/broadcast su PushDispatcher, transizioni serializzate (ticket + CAS su version), coalescenza nello stesso secondo, limitatore orario priorità 10, ChannelRegistry (create/unknown/sync/cap/cleanup), pruner, facade LiveActivity; 69 test verdi, phpstan max e Arch puliti. Manca: job in coda (usa ActivityTicket + replay), comandi artisan, ruolo ApnsChannelManagement in ApnsDriver, prove su MySQL/PostgreSQL.
- 2026-11-21 [orchestratore] W3-A rientrato (PushManager, PendingSend, facade, helper, config completa, eventi, cablaggio observer; suite a 1608 test). Lanciata ondata 4: W4-N (canali Notification + ripiego), W4-T (PushFake/fakeTransport), W4-K1 (comandi base), W4-K2 (comandi operativi), W4-I (integrazione config e cablaggi), W4-O (Pulse/Telescope). Già in corso: W3-L, W4-C, W4-P.
- 2026-11-21 [orchestratore] W3-L rientrato (Live Activities: registro, start/update/end, coalescenza nello stesso secondo per rinvio, canali broadcast, pruner; 69 test). Punti di raccordo passati a W4-I (gate solo-dispositivi, profilo gestione canali nel driver, config, scheduler flushDue, job). In corso: W4-C, W4-P, W4-N, W4-T, W4-K1, W4-K2, W4-I, W4-O.
- 2026-11-21 00:07 [W4-P] done: src/Core/Policy/{Frequency,Badge} (FrequencyLimits/Counter/CapGate, BadgeCounter/Resolver, decisione per effetto nello stesso valore CAS) + src/Laravel/Policies (PoliciesModule, PolicyGates); 59 test verdi, Arch e phpstan max puliti. Da cablare: PolicyGates::gates() nelle opzioni di default, BadgeResolver per proprietario prima dell'invio, chiavi config frequency.*/badge.*.
- 2026-11-21 [orchestratore] W4-P rientrato (limite di frequenza e contatore badge, 59 test). Decisione: frequency.enabled passa a default false (ogni comportamento opzionale è spento di default); cablaggio e default passati a W4-I. In corso: W4-C, W4-N, W4-T, W4-K1, W4-K2, W4-K3, W4-I, W4-O.
- 2026-11-21 00:11 [W4-N] done: src/Laravel/Notifications (canali push/apns/fcm, un solo proprietario della coda, ripiego con regole FallbackRules, FallbackPolicy, idempotenza in cache, PushFellBack, FallbackEvaluator per esiti differiti); 39 test verdi, phpstan max e Arch puliti. Non cablato: chiamata di FallbackEvaluator dal ledger (profilo durevole).
- 2026-11-21 [orchestratore] W4-N rientrato (canali Notification push/apns/fcm, un solo proprietario dell'accodamento, ripiego su altro canale con regole strette; 39 test). Verificato nel sorgente Laravel: l'id della notifica è stabile tra i retry di una notifica in coda. Lacune del manager passate a W4-I (notifiable anonimi, passaggio della notifica al routing, filtro per provider, chiavi fallback). In corso: W4-C, W4-T, W4-K1, W4-K2, W4-K3, W4-I, W4-O.
- 2026-11-21 00:13 [W4-O] done: Pulse (MobilePushRecorder con $listen, card Livewire mobile-push.pulse-card, vista pubblicabile tag mobile-push-views, tetto sulle connessioni) e Telescope (PushWatcher: voci di tipo event per batch e per risultati non accettati, filtro che nasconde gli eventi grezzi al EventWatcher); 49 test verdi, phpstan max e Arch puliti. Non verificato in browser: solo rendering Livewire.
- 2026-11-21 [orchestratore] W4-O rientrato (Pulse: recorder + card Livewire; Telescope: voci registrate come eventi, con filtro che scarta gli eventi grezzi del package dal watcher generico; 49 test). Chiuse le due voci aperte del piano su Pulse/Telescope (Pulse 1.11, Telescope 5.29: nessun tipo di voce custom visibile nella UI). Non verificato in browser. In corso: W4-C, W4-T, W4-K1, W4-K2, W4-K3, W4-I.
