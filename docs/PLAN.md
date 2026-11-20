# Piano v2: `padosoft/laravel-mobile-push`

**APNs, FCM & Live Activities for Laravel** — namespace `Padosoft\MobilePush`, facade `MobilePush`, config `mobile-push.php`, comandi `mobile-push:*`, env `MOBILE_PUSH_*`, tabelle `mobile_push_*`. PHP 8.3+, Laravel 12–13, MIT. Repository greenfield in `/home/user/laravel-mobile-push`.

## 1. Contesto

Package Laravel che invia push **direttamente** ad APNs e a FCM HTTP v1, incluse le Live Activities Apple, a livello enterprise. La v1 del piano definiva bene trasporto e strato Laravel, ma la review tecnica del 20/11/2026 ha mostrato che non conservava abbastanza informazione per decidere in sicurezza, dopo un'interruzione, se ritentare, invalidare un dispositivo o ripiegare su un altro canale. La v2 parte quindi da tre contratti (esito, identità di destinazione, registro degli effetti) e ci costruisce sopra il resto.

Decisioni tue confermate in v2:
- Scope: APNs + FCM + Live Activities (broadcast incluso). Web Push dopo la 1.0.
- Rilascio per porte (alpha → beta → rc), ma **la 1.0 esce solo quando ogni modulo ha superato i propri criteri**.
- Profilo **leggero di default** (senza tabelle, dichiarato best effort); profilo **durevole** a scelta, consigliato per la produzione.
- Ambiente APNs: niente cambio automatico; ambiente dichiarato + quarantena + sonda esplicita dell'operatore.

## 2. Esito della review

| Punto | Esito | Cosa cambia nel piano |
|---|---|---|
| R01 esito ignoto | Accolto | `OutcomeUnknown` pubblico e durevole; `NotSent` solo con prova; M1 promette «nessun target scomparso dal report» (§4.1) |
| R02 registro degli invii | Accolto | Registro durevole + outbox nel profilo durevole; niente `release()` con payload riscritto; profilo leggero dichiarato best effort (§4.3) |
| R03 identità | Accolto, con una variante | Riferimento immutabile `(scope, app, provider, ambiente, generazione)` nei job; token grezzi solo con piattaforma esplicita (§4.2). Variante: vedi sotto |
| R04 `BadDeviceToken` | Accolto | Classificatore unico con decisione finale; quarantena ≠ invalidazione; nessun cambio di ambiente in invio; generazioni (§4.4) |
| R05 JWT/OAuth/cache | Accolto in parte | `cacheKey` separata dalla chiave di cifratura; interfaccia `Signer` per KMS; `google/auth` per ADC/metadata/WIF. Libreria JOSE: vedi sotto |
| R06 pool | Accolto | Chiave di pool per identità TLS/autorizzativa (incl. impronta del certificato), limite globale, scadenza per inattività, default di concorrenza da misurare (§6.1) |
| R07 budget di retry | Accolto | Un solo budget per destinazione con deadline assoluta; limiti per progetto e per messaggi; breaker separati (§4.5) |
| R08 contratto FCM | Accolto | `fid`/`token`, `androidV2`, `topicSubscriptions` fissati sul Discovery rev. `20261112` con snapshot nei test; IID legacy escluso (§6.4) |
| R09 limiti e contenuto | Accolto | Limiti per driver/target (FCM topic 2048), rifiuto di default, scadenza logica, matrice di capacità, `actionCategory` ≠ `policy` (§6.3) |
| R10 Live Activities | Accolto | Token tipizzati disgiunti, mapping al nome Swift, transizioni serializzate, nessun secondo inventato, broadcast = 1 pubblicazione (§6.5) |
| R11 code | Accolto | Catena dei timeout, deadline del chunk, job con ID/checkpoint, `doctor` con «non verificabile» (§6.6) |
| R12 consenso e campagne | Accolto | Controllo di eleggibilità subito prima della rete; cap e badge per effetto logico; regole del ripiego (§8) |
| R13 ricevute | Accolto | `provider_accepted` / `client_reported_received` / `client_reported_opened`; unicità per evento; niente ricevute da topic/broadcast (§8) |
| R14 riservatezza | Accolto | Cifratura autenticata con versione, indice HMAC, redazione prima di ogni sink, nessuna promessa GDPR dal solo comando (§8) |
| R15 safe mode | Accolto | Simulazione senza rete di default fuori produzione; `Simulated`/`Validated`/`Accepted`; host ufficiali per ruolo (§4.6) |
| §5 manutenibilità | Accolto, con una variante | Report a cursore, `reasonCode` + `rawReason`, sink isolati, exit code CLI, doc generata dal config, benchmark riproducibili. Variante sugli helper: vedi sotto |
| §6 roadmap | Accolta con tua modifica | Porte adottate; moduli avanzati stabili nella 1.0 (§10) |

**Tre punti accolti solo in parte, e perché**

1. **R05 — libreria JOSE al posto del signer interno: non adottata.** La firma la esegue comunque OpenSSL; una libreria aggiungerebbe una dipendenza runtime per circa 40 righe (base64url e conversione DER → `R||S`). Adotto l'alternativa che la review stessa ammette: signer interno dietro un'interfaccia `Signer`, vettori RFC 7515/7518 bloccanti (inclusi `R`/`S` con zeri iniziali) e verifica incrociata con `firebase/php-jwt` come dipendenza di solo sviluppo.
2. **R03 — inferenza della piattaforma dal token: tolta dall'API, tenuta solo nel comando interattivo.** `mobile-push:send` può *proporre* la piattaforma e chiede conferma; in modalità non interattiva, negli helper e nella facade la piattaforma è obbligatoria. Motivo: avevi chiesto un invio al volo comodo, e una proposta confermata a mano non è una decisione di instradamento automatica.
3. **§5 — helper globali: tenuti ma rinominati.** Li avevi chiesti esplicitamente. Diventano `mobile_push()` e `mobile_push_to_token()` (prefisso che evita collisioni, guardia `function_exists`), con piattaforma esplicita.

## 3. Analisi dei pacchetti esistenti

Nessun pacchetto PHP copre oggi APNs completo, Live Activities con broadcast e FCM v1 aggiornato in un'API Laravel coerente. Le osservazioni sotto vengono dalla lettura dei sorgenti; **prima di pubblicarle nel README vanno ancorate a tag/commit e percorso esatto**, e ogni confronto prestazionale richiede un benchmark ripetibile (§11).

| Idea da riprendere | Fonte |
|---|---|
| Loop a finestra scorrevole su `curl_multi` con HTTP/2 multiplexato | pushok `Client::push` |
| Suffisso del topic derivato dal push type; catalogo dei motivi d'errore | pushok |
| Enum per push type/priorità/interruption level; classi separate per Live Activity e VoIP | canale Laravel apn |
| Troncamento opzionale del payload; errori permanenti vs transitori; esito per messaggio legato a un ID del chiamante | ApnsPHP |

| Limite osservato | Dove |
|---|---|
| JWT creato una volta e mai rinnovato; intestazione `alg` ES512 | pushok `AuthProvider/Token` |
| Un errore di trasporto lancia un'eccezione nel loop e scarta le risposte già raccolte | pushok `Client::push` |
| Nessun retry su 429/5xx | pushok, ApnsPHP |
| `push()` chiude la connessione di default; il canale Laravel chiama `push()` una volta per notifiable (all'interno di un singolo `push()` le richieste sono multiplexate) | pushok + canale apn |
| Errore curl registrato come stato 0 e poi rimosso come riuscito; `apns-expiration` relativo | ApnsPHP (fork) |
| Push type e chiavi `aps` mancanti; nessuna API broadcast/canali | pushok |

Dipendenze runtime: `ext-curl`, `ext-openssl`, `ext-json`, `psr/log`, `psr/clock`, `illuminate/*`. Suggerite: `ext-intl`, `google/auth`, `open-telemetry/api`, `laravel/pulse`, `laravel/telescope`, `spatie/laravel-health`. Solo sviluppo: `firebase/php-jwt` (verifica incrociata delle firme).

## 4. Contratti fondamentali (da fissare prima di M1)

Ognuno ha un documento in `docs/contracts/` scritto e approvato in M0; il codice li implementa, non li reinventa.

### 4.1 Esiti

`SendStatus`: `Accepted` (accettata dal provider, mai «consegnata» o «vista»), `Rejected` (rifiuto definitivo), `Deferred` (nuovo tentativo pianificato, con `nextAttemptAt`), `OutcomeUnknown` (non si sa se il provider l'ha elaborata), `Expired` (scadenza logica superata prima del tentativo), `Skipped` (regola locale: consenso, cap, safe mode, circuito aperto, identità incongruente), `Simulated` (nessuna rete), `Validated` (FCM `validate_only`).

- Motivo = oggetto `Reason { ReasonCode code; ?string rawReason; ?string hint }`: l'enum non porta la stringa grezza, che sta in un campo separato e sanificato.
- **`NotSent` è un fatto interno del trasporto, ammesso solo con prova**: fallimento di DNS/connessione/TLS prima di scrivere la richiesta, oppure una risposta HTTP esplicita. Un errore HTTP/2 generico di cURL, un timeout o un reset dopo la scrittura → `OutcomeUnknown`. Se in M1 misuriamo che il binding PHP distingue in modo affidabile `REFUSED_STREAM` e l'ultimo stream di `GOAWAY`, li promuoviamo a prova; altrimenti restano ignoti.
- Retry da esito ignoto: `unknown_outcome.retry = never | replaceable | always`, default `replaceable`. Sono sostituibili gli aggiornamenti/chiusure di Live Activity, i push background/widgets/controls e i messaggi marcati `->replaceable()`. Alert e `start` non si ritentano senza opt-in.
- Da uno stato ignoto non partono né ripiego su altro canale né invalidazione.
- Errori di input o configurazione globalmente non validi → eccezione **prima** della rete. Errori del singolo target → risultato, mai eccezione.
- `SendReport`: contatori limitati in memoria + `results()` a cursore/generatore + `each(callable)`. Non colleziona milioni di oggetti.

### 4.2 Identità

- **Scope** (tenant): stringa, default `default`, da `MobilePush::scopeUsing()` o `->scope()`. Mai ricavato dal payload del client.
- **App binding**: `(scope, nome, provider, app_id, ambiente, generazione)`; `app_id` = bundle ID o progetto Firebase + package. La generazione cambia a ogni rotazione di credenziale o riconfigurazione.
- I job accodano un **`BindingRef` immutabile** più ID e generazione della destinazione, mai il segreto. Al consumo si risolve la credenziale corrente *per quella identità*; se non combacia → `Skipped(BindingMismatch)` con evento, senza ricadere sul default.
- **Destinazioni tipizzate e disgiunte**: `ApnsDeviceToken`, `ApnsLiveActivityToken`, `ApnsPushToStartToken`, `ApnsBroadcastChannel`, `FcmToken`, `FcmFid`, `FcmTopic`, `FcmCondition`. Per `to($user)` valgono i metadati del registro; un override incongruente è un errore prima della rete.
- Lo stesso ambito vale per cache, lock, deduplica, ricevute, `export`/`forget` e azioni sui canali.

### 4.3 Registro degli effetti e profili

**Profilo leggero (default)** — nessuna tabella. Deduplica solo nel processo. Dichiarato *best effort* in documentazione e nei report, anche per `queueMany()`: dopo un crash sono possibili duplicati o omissioni.

**Profilo durevole** (`delivery.profile = durable`):
- `mobile_push_effects`: ID generato prima del dispatch, scope, binding, `effect_key`, impronta del payload, snapshot cifrato del messaggio, tipo, sostituibilità, scadenza logica, `policy`.
- `mobile_push_deliveries`: una riga per destinazione logica, con stato, tentativi, `next_attempt_at`, lease (proprietario e scadenza), versione, ID del provider, `reason_code`, `raw_reason`, timestamp.
- Vincolo univoco `(scope, effect_key, destinazione logica)`; stessa chiave con impronta diversa → `IdempotencyConflictException`.
- **Outbox**: se la chiamata avviene dentro una transazione sulla stessa connessione DB del registro, l'intento è committato insieme ai dati di business; un relay recuperabile (`RelayDeliveriesJob` + sweeper schedulato) lo prende in carico.
- Il worker rilegge le destinazioni non concluse e **registra gli esiti prima dell'ack**. Nessuna transazione SQL aperta durante la rete. Una lease scaduta dopo l'inizio dell'invio non prova `NotSent`: si applica §4.1.
- `effect_key` di default: UUID della Notification Laravel, oppure ULID generato alla chiamata; durata della deduplica configurabile. Le chiavi coprono anche topic, condition e canali.

### 4.4 Classificatore unico

Un solo `OutcomeClassifier` produce per ogni operazione una `Decision` finale: stato, eventuale `nextAttemptAt`, azione sulla destinazione (`none | quarantine | invalidate`), azione sulla credenziale, segnale di throttling. Listener e breaker agiscono solo sulla decisione finale, mai sulle risposte intermedie.

- **Quarantena** (`BadDeviceToken`, `DeviceTokenNotForTopic`, FCM `SENDER_ID_MISMATCH`): sospetto di instradamento. L'invio corrente è scartato, la registrazione è sospesa nel suo ambito, nessun retry.
- **Invalidazione**: APNs 410 (`Unregistered`, `ExpiredToken`) solo se topic e generazione corrispondono e il `timestamp` (millisecondi Unix) è successivo alla registrazione; FCM `UNREGISTERED` per la destinazione tentata. FCM `INVALID_ARGUMENT` si legge da `error.details` e di norma riguarda il payload: non invalida.
- Una risposta tardiva sulla generazione 7 non tocca la generazione 8, anche con token identico.
- **Sonda esplicita dell'ambiente**: `mobile-push:devices:probe-environment {--device=} {--quarantined} {--allow-cross-environment}`. Lanciata dall'operatore, invia una push silenziosa sull'altro ambiente dello stesso scope/app e corregge l'etichetta. Limitata in frequenza, rifiutata con chiavi vincolate a un solo ambiente. L'ambiente normale è quello dichiarato dall'app alla registrazione.

### 4.5 Budget di retry unico

- Un budget per destinazione: deadline assoluta = min(scadenza logica, `retry.max_elapsed`), `max_attempts`, `next_attempt_at`. **Un solo componente (`RetryPlanner`) pianifica**; trasporto, sender, job e failover consumano lo stesso contatore.
- Nessun `sleep` lungo nel worker: oltre una soglia breve il tentativo diventa `Deferred`. Profilo durevole: `next_attempt_at` persistito. Profilo leggero in coda: nuovo job con la lista residua (non `release()`).
- Un tentativo differito oltre la scadenza non tocca la rete → `Expired`.
- **Limiti contati in messaggi**, con chiave per progetto Firebase / team Apple (non per alias), poi per topic/dispositivo se segnalato; quota riservata al transazionale. `rate.fcm_per_minute` è un tetto applicativo, non la quota Google.
- **Breaker separati**: autenticazione (per credenziale), quota (per progetto), qualità delle destinazioni (per binding, con campione minimo e prova controllata prima di riaprire).

| Segnale | Decisione |
|---|---|
| APNs `ExpiredProviderToken` | Un solo refresh coordinato della generazione pertinente, poi un tentativo |
| APNs `TooManyProviderTokenUpdates` | Frenare i rinnovi; non passare alla credenziale secondaria |
| APNs 5xx su invio a dispositivo | `Deferred` secondo l'indicazione Apple (15 minuti, da fissare con citazione in fixture); se scade prima → `Expired` |
| FCM `QUOTA_EXCEEDED` / `UNAVAILABLE` | Minimo iniziale 1 minuto, `Retry-After` se presente, jitter che non anticipa il minimo |
| Errore HTTP/2 generico, timeout dopo l'invio, risposta non interpretabile | `OutcomeUnknown` con evidenza conservata |

### 4.6 Modalità di esecuzione ed endpoint

- `mode = auto | live | simulate`; `auto` = `live` solo con `APP_ENV=production`, altrimenti `simulate` (nessuna rete, esito `Simulated`).
- Invio reale fuori produzione: solo con `live.allow` sul binding **e** destinazioni di prova in `live.only_to`. Topic, broadcast e gestione canali restano bloccati se non autorizzati esplicitamente. Un'incoerenza blocca, non degrada a invio reale.
- Il controllo avviene prima di qualsiasi effetto esterno e copre CLI, worker, failover, ripiego e gestione canali.
- `->validateOnly()` (FCM) è una chiamata remota autenticata → `Validated`. APNs non ha dry-run: `preview` è locale e non verifica le credenziali; `doctor --live` è descritto come richiesta reale.
- **Host ufficiali per ruolo, non URL liberi**: invio, gestione canali, pubblicazione broadcast (`broadcast_publish_host = standard | api-broadcast`, scelta tra i due host documentati da Apple), token exchange Google. TLS e verifica di hostname/CA sempre attivi. Endpoint di test iniettabili solo con credenziali di test e mai in produzione. Il messaggio non può indicare host.

## 5. Architettura

Pacchetto unico a due strati; un test di architettura vieta a `Core` di importare `Illuminate\*`.

```
src/Core/
  Transport/     Transport, Request, Response, TransportEvidence, Curl/{CurlTransport, ConnectionPool,
                 PoolKey, PoolRegistry, Lane, HeaderParser, CurlCapabilities}, FakeTransport, RecordingTransport
  Auth/          Signer, OpenSslEs256Signer, OpenSslRs256Signer, DerSignature, PrivateKey, Secret,
                 SecretResolver, TokenStore, RefreshPolicy
  Outcome/       SendStatus, Reason, ReasonCode, SendResult, SendReport, OutcomeClassifier, Decision
  Identity/      Scope, AppBinding, BindingRef, Destination + tipi, DestinationRef
  Retry/         RetryPlanner, RetryBudget, Backoff, RateLimiter, ThrottleGate, Breaker{Auth,Quota,Destination}
  Ledger/        EffectStore, DeliveryStore (contratti), InMemoryLedger, PayloadFingerprint
  Policy/        ExecutionMode, EndpointCatalog, EligibilityGuard, CapabilityMatrix
  Message/       PushMessage, MessageCompiler, PayloadLimits, Truncator, JsonEncoder, Expiry
  Driver/        Driver, Sender, capability: SupportsLiveActivities, ManagesChannels, ManagesTopicSubscriptions
  Apns/          ApnsDriver, ApnsConfig, ApnsTokenProvider, CertificateAuth, TopicResolver, PushType, Priority,
                 Message/…, LiveActivity/{LiveActivityMessage, LiveActivityType, BroadcastPublisher}, Channels/…
  Fcm/           FcmDriver, FcmConfig, Auth/{ServiceAccountTokenProvider, GoogleAuthLibraryProvider},
                 Message/{FcmMessage, AndroidConfig, AndroidConfigV2, ApnsOverride}, Topics/TopicSubscriptionClient
  Privacy/       Redactor, TokenIndex (HMAC), TokenCipher
  Exceptions/
src/Laravel/
  MobilePushServiceProvider, PushManager, PendingSend, Facades/, helpers.php, Config/ConfigSchema
  Ledger/ (Eloquent + outbox + relay)   Cache/   Notifications/   Jobs/   Events/   Testing/
  Devices/   LiveActivities/   Campaigns/   Receipts/   Logging/   Health/   Pulse/   Telescope/   Console/
config/mobile-push.php   database/migrations/*.stub   docs/   benchmarks/
```

## 6. Scelte tecniche

### 6.1 Trasporto e pool
- `CurlMultiHandle` persistente per chiave di pool = identità TLS/autorizzativa effettiva (team + key ID, **oppure impronta SHA-256 del certificato mTLS**, oppure principal Google), endpoint/ruolo, ambiente, profilo proxy/TLS, generazione. L'isolamento per chiave è una politica del package, più stretta di quanto Apple richieda.
- Registro per processo con **limite globale, scadenza per inattività (LRU), chiusura controllata, reset al cambio di PID**. Handle con un solo proprietario anche sotto Octane.
- Limite degli *in-flight* distinto da connessioni e stream concessi dal peer. I default di concorrenza partono prudenti e si alzano solo dopo le misure di M1.
- Verifica delle capacità effettive (HTTP/2, ALPN, blob mTLS): se mancano, errore con rimedio esplicito. Se un runtime non supera i test di riuso, il riuso viene disattivato per quel runtime.
- Mai eccezioni a metà batch; `CURLOPT_HEADERFUNCTION`; timeout di connessione e richiesta separati; proxy supportato.

### 6.2 Autenticazione
- **`Signer`** (`sign()`, `algorithm()`, `keyId()`): implementazioni OpenSSL interne; un KMS non esportabile si integra implementando `Signer`. `SecretResolver` resta per i segreti esportabili.
- APNs: ES256 su P-256, firma JWS `R||S` di 64 byte ottenuta dal DER di OpenSSL.
- Google: l'asserzione RS256 del service account **si scambia** con un access token OAuth (provider nativo). ADC, metadata server, Workload Identity Federation e impersonation passano da `google/auth` (opzionale).
- Cache dei token: `cacheKey` = hash di (scope, provider, identità, scope OAuth, contesto di delega) — è un nome, non materiale crittografico. Valore protetto con cifratura autenticata (Encrypter di Laravel) e chiave segreta ruotabile, opzionalmente dedicata.
- Refresh concorrenti riusano il token valido (lock non bloccante + compare-and-swap). Cache indisponibile → riuso locale nel rispetto dei limiti Apple, oppure `Deferred`; mai una firma per messaggio.
- **Credenziale secondaria**: stessa identità di team/progetto/ambiente (verificata all'avvio). Il passaggio è deciso una volta dal breaker di autenticazione, non per destinatario. Rotazione o revoca drenano pool e cache.

### 6.3 Messaggi, limiti e capacità
- `PushMessage` unificato e piccolo + builder nativi; overlay `->apns(fn)` / `->fcm(fn)` eseguiti subito e serializzabili.
- **Limiti per driver, target e tipo**, applicati dopo overlay, codifica e marcatore di ricevuta: APNs 4096 (VoIP e broadcast 5120); FCM 4096, **2048 per topic/condition**, misurati sul payload FCM e non sulla busta REST.
- **Rifiuto di default.** Troncamento opzionale solo su titolo/sottotitolo/testo; mai su ID, URL, dati o stato ActivityKit. Grafemi con `ext-intl` se presente, altrimenti PCRE `\X`; entrambe le strade testate ai limiti esatti e a +1 byte.
- Validazione di UTF-8, chiavi riservate, valori stringa, conflitti tra `silent`, suono, alert e priorità.
- **Scadenza logica** (`->expiresAt()` / `->ttl()`): epoch APNs e TTL residua FCM ricalcolati a ogni tentativo, senza estenderla.
- **Matrice di capacità** pubblicata e applicata a runtime («nativo / richiede codice lato app / non disponibile»). Una capacità mancante è un errore di validazione, non un successo silenzioso (`->lenient()` per scartarla con avviso nel report).
- `->actionCategory()` (azioni lato app) è distinto da `->policy('marketing')` (regole di invio).

### 6.4 FCM (contratto fissato sul Discovery rev. `20261112`)
- Un solo target per richiesta tra `fid`, `token`, `topic`, `condition`. `token` è deprecato ma supportato; si conserva il tipo d'origine, nessuna conversione per euristica. Un'installazione con FID e token riceve **un solo** invio per effetto (`fcm.prefer = fid | token`).
- `android` e `androidV2` mutuamente esclusivi: il builder rifiuta entrambi insieme.
- Pubblicazione su topic separata dalla gestione delle iscrizioni (`topicSubscriptions`: create/get/list/patch/delete, per installazione). Nessuna operazione bulk promessa. **Nessun uso dell'API IID legacy.**
- Snapshot del Discovery nei test con revisione e impronta; un workflow schedulato confronta l'upstream e apre una issue con il diff.

### 6.5 Live Activities
- Operazioni e token disgiunti: `start` → push-to-start (iOS 17.2+); `update`/`end` → token dell'attività o canale broadcast (iOS 18+). Il broadcast non avvia attività.
- **Mapping esplicito al tipo Swift**: classe `LiveActivityType` con `swiftName()` e `version()` per `attributes` e `content-state`. Fixture condivisa con l'app iOS di prova.
- Token con generazione e rotazione. Transizioni serializzate per attività/canale; `end` è terminale e un update vecchio non la riapre.
- `timestamp` = secondi Unix correnti. Due update nello stesso secondo si accorpano (vince l'ultimo) o si differiscono; nessun secondo futuro inventato.
- Il server limita i tentativi ad alta priorità (`high_priority.max_per_hour`); non misura il budget del dispositivo.
- La «chiusura automatica» distingue scadenza locale, `end` accettata e stato visto dal client.
- Broadcast: gestione canali e pubblicazione sono profili separati per operazione e ambiente; un'accettazione = **una pubblicazione**, non N consegne. Riconciliazione dei canali se si perde la risposta di creazione (`channels:sync`), limiti e cleanup.

### 6.6 Code
- Catena verificata da `doctor`: timeout I/O < deadline del chunk < `timeout` del job < timeout di Horizon/worker < `retry_after` (o *visibility timeout* SQS), ciascuno con margine.
- Deadline complessiva del chunk; chunk piccoli anche in byte.
- Profilo durevole: i job portano ID e checkpoint. Profilo leggero: liste piccole di token in job cifrati (`ShouldBeEncrypted`).
- Un solo proprietario dell'accodamento: con una Notification `ShouldQueue` il canale invia dentro quel job; altrimenti `SendChunkJob`.
- Parità dichiarata solo per i driver verificati in CI (`sync`, `redis`, `database`, SQS via emulatore). Dove il backend non è ispezionabile `doctor` riporta «non verificabile».
- Tag Horizon e blocco supervisor stampato da `install`; il metodo per la coda si chiama `->onQueueConnection()`, distinto dalla connessione push.

## 7. API pubblica (sintesi)

```php
MobilePush::to($user)->send(PushMessage::make('Ordine spedito', 'Arriva venerdì')
    ->data(['order' => '42'])->expiresAt(now()->addHour())->policy('orders'));      // SendReport

MobilePush::connection('ios-consumer')->to(ApnsDeviceToken::of($hex))->send($msg);
MobilePush::connection('android')->to(FcmTopic::of('news'))->validateOnly()->send($msg);
MobilePush::to($users)->queue($msg)->onQueueConnection('redis')->onQueue('push');

mobile_push($user)->send($msg);
mobile_push_to_token(Platform::Ios, $token, 'Titolo', 'Testo');                     // piattaforma obbligatoria

$report->counts();  $report->results();  $report->each(fn (SendResult $r) => ...);
$result->status;  $result->reason->code;  $result->reason->rawReason;  $result->reason->hint;

LiveActivity::start(DeliveryActivity::class, attributes: [...], state: [...])
    ->alert('Rider assegnato', 'Tocca per seguire')->to($user)->send();
LiveActivity::for($activity)->update(['eta' => 5])->send();
LiveActivity::broadcast($channel)->update(['score' => '2-1'])->send();

MobilePush::fake();           MobilePush::assertSent(fn (SentPush $p) => ...);     // intento
MobilePush::fakeTransport();                                                        // byte e protocollo
```

`mobile-push:send` accetta `--platform`/`--connection`, `--stdin` e `--file` (consigliati per invii reali), token posizionali per lo sviluppo; in interattivo propone la piattaforma e chiede conferma. Exit code documentati e distinti per: validazione, risultati parziali, simulato, esiti ignoti.

## 8. Moduli e funzioni (tutti disattivati di default)

| Modulo | Contenuto e regole |
|---|---|
| **Dispositivi** | Owner polimorfico, scope, binding, piattaforma, ambiente dichiarato, tipo token, generazione, stato `active/quarantined/invalidated`, token cifrato + indice HMAC. Sonda dell'ambiente (§4.4). |
| **Eleggibilità** | `EligibilityGuard` chiamato **subito prima della rete**: proprietario invariato, dispositivo attivo, generazione corrente, consenso dell'app ospite (`MobilePush::eligibleUsing()`). Copre revoca, logout e telefono passato a un altro utente. |
| **Live Activities** | §6.5. |
| **Log e osservabilità** | Sink `log`, `database`, `otel` in qualsiasi combinazione. Il guasto di un sink non fa ritentare un invio accettato. Metriche: età della coda, ignoti, differiti/scaduti, drop del buffer, circuiti aperti. Pulse e Telescope. |
| **Health e metriche** | Liveness, readiness e sonda live separate; check per `spatie/laravel-health`; endpoint Prometheus **non pubblico di default**, senza ID utente/dispositivo/campagna come label. |
| **Credenziali** | Primaria + secondaria (§6.2), `mobile-push:credentials:check`, avvisi mail/Slack con cooldown. |
| **Limite di frequenza e badge** | Decisi **una volta per effetto logico** e per proprietario, non per dispositivo o retry. `bypassFrequencyCap()` non scavalca il consenso. Non applicabili a topic e broadcast. |
| **Ripiego su altro canale** | Solo con tutti gli esiti terminali, nessun `Accepted` né ignoto, nuova verifica della preferenza per quel canale, ID proprio. `Skipped` per opt-out non attiva il ripiego. Non per topic/broadcast. |
| **Ricevute** (richiede profilo durevole) | Stati distinti `provider_accepted`, `client_reported_received`, `client_reported_opened`. Marcatore per evento e destinazione con scadenza e versione di chiave; la chiave HMAC resta sul server. Unicità `(delivery_id, event_type)`; duplicati e fuori ordine tollerati; callback mancante = «non osservata». Denominatore e copertura del funnel visibili. Nessuna ricevuta individuale da topic/broadcast. |
| **Campagne** (richiede profilo durevole) | Limite superiore stabile fissato alla pianificazione + controllo finale di eleggibilità. Unicità campagna + destinazione nel registro. `pause` ferma le nuove acquisizioni, `cancel` non ritira l'in-flight, `resume` non ricalcola da zero. |
| **Riservatezza** | Cifratura autenticata con versione di chiave; indice HMAC con segreto separato e ruotabile; `Redactor` prima di ogni sink (URL, header, errori del provider, attributi OTel); `preview` non mostra mai bearer, token o marcatori; payload `full` spento. `forget` ed `export` circoscritti allo scope, con tombstone contro la ricomparsa da job pendenti; documentati copertura e tempi di retention esterna. **Nessuna promessa di conformità GDPR basata sul solo comando.** |
| **DX** | `explain`, `preview`, `simulator` (verifica il rendering, non l'infrastruttura APNs), helper per contenuti ricchi secondo la matrice di capacità. |

## 9. Comandi Artisan

`install`, `doctor {--live}`, `send`, `preview`, `explain`, `simulator`, `connections`, `circuit`, `relay` (profilo durevole), `deliveries:prune`, `devices:prune`, `devices:probe-environment`, `logs:prune`, `live-activity:{start|update|end|prune}`, `channels:{list|create|delete|sync}`, `fcm:topic-subscription`, `campaign:{status|pause|resume|cancel}`, `credentials:check`, `health`, `forget`, `export`.

## 10. Roadmap a porte (tutto stabile nella 1.0)

| Porta | Milestone e contenuto | Evidenza per passare |
|---|---|---|
| **Contratto** | **M0** — documenti §4 in `docs/contracts/`, tabella target/capacità, scheletro, CI, test di architettura | Per «risposta persa», «token rinnovato», «tenant riassegnato» esiste un solo comportamento pubblico dichiarato |
| **Alpha — invio diretto** (0.1) | **M1** trasporto + mock · **M2** APNs · **M3** FCM · **M4** strato Laravel nel profilo leggero: manager, modalità, report, fake, helper, CLI | Il mock registra la richiesta e chiude prima della risposta → **un esito ignoto**, nessun target mancante dal report. Fixture indipendenti e limiti per piattaforma. Prova su dispositivi controllati distinta da `validate_only` |
| **Beta — invio durevole** (0.5) | **M5** registro/outbox/relay, code, budget di retry, limiti, breaker, dispositivi, quarantena/invalidazione, eleggibilità, riservatezza, **test sui runtime longevi** | Crash prima del dispatch, a metà chunk e dopo la risposta ma prima del salvataggio; due tenant sullo stesso worker; rotazione credenziali; quota condivisa tra due alias; `forget` con job pendenti; stessa chiave con payload diverso respinta |
| **RC1 — Live Activities** | **M6** token tipizzati, `start/update/end`, canali e broadcast, documentazione client/server versionata | App iOS di prova e canale isolato: creazione → update → end → delete. Due update simultanei, token ruotato, `end` seguito da un job vecchio |
| **RC2 — moduli avanzati** | **M7** osservabilità, health, credenziali · **M8** campagne, cap, badge, ripiego, ricevute | Ogni modulo supera il proprio caso del quadro di collaudo (§11) |
| **1.0** | **M9** benchmark riproducibili, README e documentazione completa | Tutte le porte superate; nessun modulo in anteprima |

Dopo la 1.0: driver Web Push, contratto dati per Android Live Updates, e il backlog già concordato (localizzazione per dispositivo, segmenti, preferenze per categoria, programmazione/ore di silenzio, worker dedicato, test A/B, webhook, Huawei HMS, payload cifrato end-to-end, dashboard).

## 11. Qualità, collaudo e documentazione

- **Strumenti**: Pest + Testbench, PHPStan max con Larastan, Pint, mutation testing su Auth, Outcome, Retry, Ledger, Truncator, compiler e parser.
- **Server mock HTTP/2** (Node `http2`, zero dipendenze): distingue *richiesta elaborata* da *risposta consegnata*; pilota GOAWAY, reset, ritardi; conta le richieste esterne. Il numero di richieste si legge dal mock, non dal sender.
- **`PushFake` verifica l'intento, `FakeTransport` i byte**: il primo non fa da oracolo al secondo.
- **Migrazioni**: test di upgrade e di tutte le combinazioni con moduli spenti.
- **CI**: PHP 8.3–8.5 × Laravel 12–13 × lowest/stable. Le PR non fidate usano solo mock e chiavi effimere. Le sonde live girano separate, su ref protetto, con progetto/app dedicati e destinazioni consentite, zero segreti nei log.
- **Benchmark** (`benchmarks/`): stessi payload, batch, concorrenza, versioni e hardware dichiarati; warm/cold separati; throughput, p95/p99, memoria, errori, connessioni. Nessuna affermazione di superiorità senza questi numeri.

**Quadro di collaudo per la release**

| Caso | Cosa osservare |
|---|---|
| Batch misto con due esiti definitivi e uno incerto | Un solo esito corrente per destinazione; contatori coerenti; nessuna eccezione cancella gli altri risultati |
| Retry simultanei e cache indisponibile | Richieste contate dal mock; una sola transizione definitiva per effetto |
| Registro e consenso cambiano durante un backlog | Scope che non si incrociano; target revocati non partono; risultati tardivi non invalidano registrazioni correnti. Ordine accodato per A, telefono passato a B: nulla arriva a B |
| Campagna grande con worker arrestati | Invariante sul numero di destinazioni uniche (pendenti, in corso, ignote, concluse), confrontato col registro a checkpoint diversi |
| Carico su più tenant e runtime | Hardware, dataset e soglie fissati prima; memoria RSS, file descriptor e connessioni dopo riscaldamento e dopo svuotamento |
| Riservatezza | Marcatori fittizi su invio riuscito, fallito e ritentato: assenti da log, Pulse, Telescope, OTel. Dopo `forget` il vecchio job non ricrea il record né contatta il provider |
| Safe mode | Un comando sandbox con configurazione di produzione **non apre connessioni** |

**Documentazione e README (deliverable di ogni milestone, completati in M9)**

- Requisito esplicito: README e `docs/` descrivono **tutte** le possibilità e **tutte** le configurazioni.
- `docs/configuration.md` è **generato** da `ConfigSchema` (chiave, env, default, valori ammessi); la CI fallisce se non è aggiornato.
- Gli esempi di README e docs sono estratti ed **eseguiti in un'app pulita** (`DocExamplesTest`).
- README «wow»: perché sceglierlo (confronti ancorati a commit e benchmark), funzioni, setup passo-passo a prova di junior (chiave `.p8`, service account Firebase, `composer require`, `install`, `.env`, `doctor`, primo invio simulato e poi reale), profilo leggero vs durevole con garanzie dichiarate, guida Live Activities server + app, matrice di capacità, test con i fake, troubleshooting per motivo d'errore, exit code della CLI, guida di migrazione da pushok e dal canale apn.

## 12. Modalità di esecuzione (richiesta da te)

**Subagenti in parallelo, modello scelto per difficoltà**

| Modello | Tipo di compito |
|---|---|
| Opus 5.5 | Trasporto `curl_multi` e pool, server mock HTTP/2, classificatore degli esiti, budget di retry e breaker, registro/outbox/relay, campagne, transizioni delle Live Activities |
| Sonnet 5.5 | Signer e token provider, compilatori e builder dei messaggi APNs/FCM, parser delle risposte, strato Laravel (manager, canali, job, comandi), moduli dispositivi/log/ricevute, fake |
| Haiku 5.5 | Cataloghi enum e testi di `explain`, stub di migration e config, file CI, boilerplate di documentazione |

- M0 lo eseguo io in sequenza: scheletro, dipendenze installate, **contratti condivisi** (interfacce e DTO di `Core`) e documenti `docs/contracts/`. Tutto il resto dipende da questi.
- Da M1 in poi lancio più subagenti insieme su **cartelle disgiunte** dello stesso albero (es. `Core/Transport`, `Core/Auth`, `Core/Apns/Message`, `Core/Fcm/Message`, `Core/Outcome` + `Core/Retry`). Nessun subagente tocca `composer.json`, i contratti condivisi o le cartelle altrui: se serve una modifica lì, la segnala e la applico io.
- Dopo ogni ondata integro, eseguo l'intera suite, PHPStan e Pint, e solo allora parte l'ondata successiva.

**Test: robusti e soprattutto di alto livello**

- Priorità a **scenari completi** che attraversano gli strati: messaggio → compilazione → invio sul server mock HTTP/2 → classificazione → report (e, nel profilo durevole, registro e coda). Ogni scenario del quadro di collaudo (§11) diventa un test.
- Test unitari mirati solo dove la logica è densa e fragile: firme e conversione DER, troncamento, limiti di byte, classificatore, pianificatore dei retry, parser.
- Niente test che ripetono l'implementazione o che verificano solo mock di se stessi.

**File di lavoro nel repository**

- `progress.md` — stato per milestone e per compito (fatto / in corso / bloccato), ultima ondata lanciata, prossimo passo concreto, comandi per verificare. Aggiornato a ogni compito concluso, così una nuova sessione riparte da lì.
- `lesson.md` — scoperte ed errori via via incontrati (comportamenti di curl/PHP, trappole di Laravel, fatti dei protocolli verificati o smentiti), con data, contesto e regola da seguire, per non ripeterli.
- `AGENTS.md` e `CLAUDE.md` — istruzioni operative per ogni agente: leggere `progress.md`, `lesson.md` e i contratti prima di iniziare; restare nelle proprie cartelle; stile dei test; comandi di verifica; obbligo di aggiornare `progress.md` e `lesson.md` a fine compito. Il loro contenuto è passato nel brief di ogni subagente.
- `docs/PLAN.md` — copia di questo piano v2.

**Persistenza**: l'ambiente è temporaneo e la cartella non è collegata a un repository. Per ripartire davvero in nuove sessioni serve un repository GitHub (es. `padosoft/laravel-mobile-push`) agganciato alla sessione; finché non c'è, `progress.md` vive solo in questo container.

## 13. Ancora da verificare sulle fonti durante l'implementazione

- **Apple**: testo esatto dell'indicazione sui 5xx (15 minuti); ambito di `TooManyProviderTokenUpdates`; regole di connessione per chiavi di team e di topic; quale dei due host di pubblicazione broadcast è operativo (verifica d'integrazione); stringhe `BadEnvironmentKeyIdInToken` / `BadEnvironmentKeyInToken`.
- **Google**: data di cessazione di ciascuna funzione IID (solo per la guida di migrazione); regola esatta di misura dei 4096/2048 byte; forma di `Retry-After`.
- **curl/PHP**: cosa espone il binding su `REFUSED_STREAM` e `GOAWAY`; default di `CURLOPT_MAXAGE_CONN`; retry automatici interni di libcurl; comportamento con Swoole; supporto ai blob mTLS.
- **Laravel**: API di Pulse su Laravel 13; voci custom in Telescope; stabilità dell'UUID della Notification tra i retry.
