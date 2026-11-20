# lesson.md — scoperte ed errori da non ripetere

> Aggiungi in fondo con un append (`cat >> lesson.md <<'EOF' ... EOF`), mai riscrivendo il file.
> Una voce per scoperta. Scrivi solo cose non ovvie e verificate; se è un'ipotesi, dillo.

Modello di voce:

```
## AAAA-MM-GG — titolo breve
- **Contesto:** dove è emerso (file, comando, milestone)
- **Fatto:** cosa si è osservato (con l'evidenza: output, versione, link)
- **Regola:** cosa fare d'ora in poi
```

---

## 2026-11-20 — Composer come root nel container
- **Contesto:** M0, `composer install`.
- **Fatto:** senza `COMPOSER_ALLOW_SUPERUSER=1` Composer disattiva i plugin («Composer plugins have been disabled for safety»), e Pest ha bisogno del proprio plugin. La prima installazione attraverso il proxy ha richiesto diversi minuti.
- **Regola:** esportare sempre `COMPOSER_ALLOW_SUPERUSER=1`; lanciare `composer install` in background e non rilanciarlo mentre gira.

## 2026-11-20 — Ambiente verificato
- **Contesto:** M0.
- **Fatto:** PHP 8.3.6 NTS, libcurl 8.5.0 con HTTP/2 e OpenSSL 3.0.13, Node 22. Disponibili `CURLOPT_SSLCERT_BLOB`, `CURLMOPT_MAX_CONCURRENT_STREAMS`, `CURLOPT_MAXAGE_CONN`, `curl_upkeep()`, `grapheme_strlen()` (ext-intl), estensioni redis, pcntl, pdo_sqlite/mysql/pgsql.
- **Regola:** il server mock HTTP/2 in Node e i test di trasporto reali si possono eseguire in questo ambiente.

## 2026-11-20 — Fonti non raggiungibili
- **Contesto:** ricerca iniziale.
- **Fatto:** `firebase.google.com` non era raggiungibile dalla sessione di ricerca; `api.github.com` rispondeva 403 (letti i sorgenti da `raw.githubusercontent.com`). Il Discovery FCM (`fcm.googleapis.com/$discovery/rest?version=v1`, rev. 20261112) e i JSON Apple (`developer.apple.com/tutorials/data/documentation/...json`) erano raggiungibili.
- **Regola:** per i fatti FCM usare il Discovery come fonte primaria; marcare come «non verificato» ciò che viene solo da ricerca web.

## 2026-11-20 — Composer: gli archivi GitHub sono bloccati, usare i sorgenti git
- **Contesto:** M0, `composer install` fallito con «Could not authenticate against github.com».
- **Fatto:** nel container `api.github.com` e `codeload.github.com` rispondono 403 («No linked GitHub account»), quindi gli archivi dist di Composer falliscono; `git ls-remote https://github.com/...` invece funziona attraverso il proxy.
- **Regola:** installare con `composer install --prefer-source` (clona via git). Non serve se un account GitHub viene collegato alla sessione.

## 2026-11-20 — PHPStan non ha sorgente git su Packagist: precaricare la cache di Composer
- **Contesto:** M0, anche `composer install --prefer-source` falliva.
- **Fatto:** `phpstan/phpstan` è distribuito solo come archivio dist (`api.github.com/.../zipball/...`), che qui risponde 403. Soluzione che ha funzionato: `git clone --depth 1 --branch <versione> https://github.com/phpstan/phpstan.git`, togliere `.git`, zippare la cartella e salvarla in `~/.cache/composer/files/phpstan/phpstan/<sha1 dell'URL dist nel composer.lock>.zip`; poi `composer install --prefer-source` usa la cache.
- **Regola:** se cambia la versione di PHPStan nel lock, ripetere il precaricamento con il nuovo URL (lo sha1 è dell'URL completo). Con un account GitHub collegato il problema scompare.

## 2026-11-20 — Guard: limiti e stato condiviso
- **Contesto:** W1-F, `src/Core/Guard`.
- **Fatto:** un token bucket con capacità `burst` concede al massimo burst + rate*T/60 permessi in una finestra T (il bucket parte pieno): «mai oltre la quota al minuto» vale solo come tetto burst+rate. Lo stato dei guard è una mappa di interi (JSON) aggiornata con compare-and-set su `GuardStore`, così basta uno store con get/put/CAS; un TTL fisso breve su una pausa più lunga di quella esistente la farebbe scadere troppo presto.
- **Regola:** nei store Laravel implementare `compareAndSet` in modo atomico (lock o Lua); i fallimenti di una generazione di credenziale non più attiva vanno ignorati (è così che il failover resta unico).

## 2026-11-20 — Documentazione Apple sul broadcast (W1-G)
- **Contesto:** verifica host/porte/path per `EndpointCatalog`.
- **Fatto:** i JSON Apple sono raggiungibili. Invio broadcast: sezione connessione = `api.push.apple.com:443` / `api.sandbox.push.apple.com:443`, ma gli esempi usano `host = api-broadcast.sandbox.push.apple.com` e `:path = 4/broadcasts/apps/<bundle>` (senza slash iniziale nella pagina). Gestione canali: `api-manage-broadcast.push.apple.com:2196` / `api-manage-broadcast.sandbox.push.apple.com:2195`, POST/GET/DELETE `/1/apps/<bundle>/channels` (GET/DELETE con header `apns-channel-id`), elenco `GET /1/apps/<bundle>/all-channels`; creazione risponde 201, delete 204.
- **Regola:** il host di pubblicazione broadcast resta una scelta enum (`standard`|`api-broadcast`) da verificare in integrazione.

## 2026-11-20 — Arch test e print_r
- **Contesto:** `ArchTest` vieta `print_r` nel namespace `Padosoft\MobilePush`, che può includere i test.
- **Regola:** nei test usare `call_user_func('print_r', $v, true)`; `var_export` è consentito.

## 2026-11-20 — Contratti: discrepanze tra piano e codice (W1-H)
- **Contesto:** W1-H, stesura di `docs/contracts/*.md` da `docs/PLAN.md` §4 e dai sorgenti di `src/Core/{Outcome,Identity,Transport,Message,Auth,Exceptions}`.
- **Fatto:**
  - `SendStatus::Failed` esiste nel codice e in `AGENTS.md`, ma non è nell'elenco degli stati del piano §4.1.
  - Il piano §4.2 elenca otto classi di destinazione; il codice ne ha nove (manca nel piano `ApnsVoipToken`, che però compare nei limiti del §6.3).
  - Il piano §4.1 ammette `NotSent` anche per «una risposta HTTP esplicita»; la docblock di `TransportEvidence` non lo prevede, e quella di `Response` dice che una risposta prova che il provider ha elaborato la richiesta.
  - `SendStatus::isTerminal()` restituisce true anche per `OutcomeUnknown`, mentre il piano consente il retry degli esiti ignoti sostituibili.
  - `SendReport` è a passata singola: `counts()` consuma il cursore e una seconda iterazione restituisce solo i risultati trattenuti. Verificato con uno script PHP scratch (5 risultati, `retain = 2`: `counts()` poi `results()` restituisce 2 elementi).
- **Regola:** nei documenti di contratto una discrepanza si scrive esplicitamente, senza scegliere in silenzio una delle due fonti. Chi consuma un `SendReport` usa `each()` o `results()` prima di chiedere i conteggi, oppure chiede solo i conteggi.

## 2026-11-20 — var_export ignora __debugInfo
- **Contesto:** W1-B, test «nessun segreto in print_r/var_export».
- **Fatto:** `print_r`/`var_dump` usano `__debugInfo`, `var_export` no: esporta le proprietà (anche private, ricorsivamente). Un `AuthToken` o una PEM tenuti in una proprietà escono. `OpenSSLAsymmetricKey`, `Closure` e `SplObjectStorage` si esportano vuoti (ma `print_r` di SplObjectStorage mostra il contenuto).
- **Regola:** tenere le chiavi come oggetto `PrivateKey` (mai stringa PEM) e i token in-process in `TokenSlot` (SplObjectStorage); i test double come `FakeTransport` registrano comunque i body.

## 2026-11-20 — PHPStan e openssl_error_string
- **Contesto:** W1-B.
- **Fatto:** un `while (openssl_error_string() !== false)` iniziale viene segnalato «always false»; `is_string(...)` nel ciclo dopo la chiamata a openssl funziona.
- **Regola:** svuotare la coda errori OpenSSL dopo le chiamate, mai esporne il testo nei messaggi.

## 2026-11-20 — FCM: Discovery usa camelCase, il filo accetta snake_case
- **Contesto:** W1-D, compilatore FCM e test di contratto sullo schema.
- **Fatto:** il Discovery (rev. 20261112) nomina le proprieta in camelCase (`androidV2`, `validateOnly`, `collapseKey`); il compilatore emette i nomi protobuf snake_case (`android_v2`, `validate_only`), che l'API accetta (JSON proto3). Il test di contratto converte snake→camel prima del confronto. Il Discovery dice che le chiavi `data` riservate sono "from", "message_type", prefissi "google." e "gcm.notification." (non tutto "gcm."): noi rifiutiamo l'intero prefisso "gcm." (insieme piu prudente). `AndroidConfigV2` non ha `priority`; `proxy`/`bypassProxyNotification` esistono solo in `AndroidNotification` (V1), `id` solo in `AndroidNotificationV2`; `AndroidBackgroundSyncMessage` e un oggetto vuoto.
- **Regola:** per ogni nuovo campo FCM aggiornare prima lo snapshot e far fallire il test di copertura inversa (`DiscoverySchemaContractTest`).

## 2026-11-20 — PHP: lo spread di array rinumera le chiavi intere
- **Contesto:** `PushMessage::data()` fa `[...$this->data, ...$data]`.
- **Fatto:** con lo spread, le chiavi intere (anche stringhe numeriche come "7") vengono rinumerate da 0; le chiavi stringa restano. Un dato `['7' => 'x']` diventa `[0 => 'x']`.
- **Regola:** per unire mappe usare `array_replace()` o `+`. Segnalato all'orchestratore (file congelato `PushMessage.php`); nel codice FCM si usa `array_replace`.

## 2026-11-20 — PCRE2 10.42 `\X` incolla le emoji consecutive
- **Contesto:** W1-C, `Truncator` percorso di ripiego senza ext-intl.
- **Fatto:** con PCRE 10.42 `preg_match_all('/\X/u', '👍👍')` restituisce 1 cluster (anche `👍🏽👍🏿`); ICU (`grapheme_extract`) ne dà 2 (UAX #29: nessuna regola unisce due ExtPict senza ZWJ). Il fallback grezzo avrebbe troncato intere sequenze di emoji in un colpo.
- **Regola:** il ripiego PCRE ri-divide ogni cluster prima di ogni `\p{Extended_Pictographic}` non preceduto da U+200D; test di equivalenza intl/PCRE su famiglie ZWJ, bandiere, tonalità di pelle, combinanti, CJK a ogni limite di byte.

## 2026-11-20 — Broadcast APNs: nome dell'header di identificazione ambiguo
- **Contesto:** W1-C, JSON Apple `sending-broadcast-push-notification-requests-to-apns` (letto il 2026-11-20).
- **Fatto:** la tabella degli header della pubblicazione broadcast elenca `apns-request-id` (non `apns-id`) e la risposta restituisce `apns-request-id`; l'esempio con token usa però `apns-id`, quello con certificato `apns-request-id`. Il broadcast non ha `apns-topic` (il bundle id sta nel path `/4/broadcasts/apps/<bundle id>`); `apns-expiration`, `apns-priority`, `apns-push-type` (`liveactivity`) e `apns-channel-id` sono obbligatori; payload max 5120 byte; expiration diversa da 0 su canale senza storage = rifiuto.
- **Regola:** il compilatore emette `apns-request-id` sul broadcast e `apns-id` sul per-dispositivo; verificare con un'integrazione reale quale dei due l'host broadcast accetta.

## 2026-11-20 — Live Activity: forma dell'alert nei payload Apple
- **Contesto:** W1-C.
- **Fatto:** negli esempi Apple di Live Activity `alert.title` e `alert.body` possono essere dizionari `{"loc-key","loc-args"}` (non `title-loc-key`), e `sound` compare dentro `alert`. `start` richiede `alert`, `attributes-type`, `attributes`; `timestamp` = secondi correnti; `dismissal-date` nel passato = rimozione immediata; `input-push-token: 1` / `input-push-channel` solo all'avvio (iOS 18); broadcast non può avviare.
- **Regola:** `ApnsCompiler` converte i campi di localizzazione di `Alert` in questa forma annidata per le Live Activity e colloca il suono dentro `alert`.

## 2026-11-20 — Lo spread di array rinumera le chiavi numeriche
- **Contesto:** `PushMessage::data()` (segnalato da W1-D).
- **Fatto:** `[...$a, ...$b]` rinumera le chiavi intere o "tipo intero": `['7' => 'x']` diventa `[0 => 'x']`, corrompendo le chiavi dei dati FCM/APNs.
- **Regola:** per unire mappe chiave → valore usare `array_replace($a, $b)`, mai lo spread né `array_merge`.

## 2026-11-20 — APNs: testo esatto sui 5xx e tabelle degli errori
- **Contesto:** W1-E, classificatore APNs; JSON `developer.apple.com/tutorials/data/documentation/usernotifications/handling-notification-responses-from-apns.json`.
- **Fatto:** testo Apple: «After 15 minutes, you can retry JSON payloads that receive response status codes that begin with 5XX. While retrying, you may employ a back-off technique.» e «Don't retry … `BadDeviceToken`, `DeviceTokenNotForTopic`, `Forbidden`, `ExpiredToken`, `Unregistered`, or `PayloadTooLarge`. You can retry with a delay, if you get the error code `TooManyRequests`.» Il `timestamp` del 410 è in millisecondi. La tabella documenta solo `BadEnvironmentKeyIdInToken` (la variante `BadEnvironmentKeyInToken` non compare). `TopicMismatch` (403) e gli errori broadcast/canali (`FeatureNotEnabled`, `ChannelNotRegistered`, `CannotCreateChannelConfig`, …) stanno in una pagina diversa: `handling-error-responses-from-apns.json`, dove 503 è scritto `Service Unavailable` con lo spazio. Il broadcast restituisce `apns-request-id` (non `apns-id`); creazione canale 201, cancellazione 204.
- **Regola:** leggere entrambe le pagine Apple; accettare entrambe le grafie; trattare ogni 2xx come accettato.

## 2026-11-20 — FCM: codici d'errore non presenti nel Discovery
- **Contesto:** W1-E, classificatore FCM.
- **Fatto:** il Discovery rev. 20261112 non elenca `FcmError` né i codici (`UNREGISTERED`, `QUOTA_EXCEEDED`, …); `firebase.google.com` resta irraggiungibile. Verificati sui sorgenti di firebase-admin-go (`messaging.go`, `handleFCMError`: cerca il primo detail con `@type` `type.googleapis.com/google.firebase.fcm.v1.FcmError`) e firebase-admin-node (che tratta anche `NOT_FOUND` + `fid` come non registrato). Esiste ancora il nome legacy `APNS_AUTH_ERROR`. firebase-admin-python rispetta `Retry-After` (delta-seconds o HTTP-date) solo su 413/429/503.
- **Regola:** codici FCM e ritardi minimi sono «verificati solo su SDK ufficiali», non sulla pagina Google: ricontrollare quando `firebase.google.com` è raggiungibile.

## 2026-11-20 — Un esito ignoto «contamina» il budget
- **Contesto:** W1-E, RetryPlanner.
- **Fatto:** se un messaggio sostituibile viene ritentato dopo un esito ignoto e i tentativi successivi falliscono (503, budget finito, scadenza), dichiarare `Failed`/`Expired` sarebbe falso: il primo tentativo potrebbe essere stato accettato, e `allowsFallback()` aprirebbe il ripiego. Il budget porta `unknownOutcomeSeen`, e ogni esito finale Failed/Expired diventa `OutcomeUnknown`.
- **Regola:** chi persiste il budget (ledger, job) deve salvare anche quel flag; mai ricostruire il budget da zero tra un tentativo e l'altro.

## 2026-11-20 — Cache Laravel: lock e valori (W2-P1)
- **Contesto:** `src/Laravel/Cache`.
- **Fatto:** `Illuminate\Cache\Repository` non ha `lock()`: si passa da `getStore()` e `instanceof LockProvider` (`NullStore` è LockProvider ma restituisce `NoLock`, che concede sempre). `Lock::block()` dorme a passi di 250 ms: per un'attesa breve limitata si usa `get()` in ciclo con `usleep(5000)`. I messaggi delle eccezioni dei client cache possono contenere URL con password: nei log solo il nome della classe. Il driver redis restituisce le stringhe numeriche come numeri: castare in stringa nella lettura.
- **Regola:** cifrare il valore con `TokenCipher` usando il nome della chiave come scope (AAD): un valore copiato sotto un'altra chiave non si autentica. Le chiavi di cache sono `prefix:tipo:sha256(nome)`.

## 2026-11-20 — Mai dichiarare variabili "comuni" in un file di config
- **Contesto:** `config/mobile-push.php`, 22 test Laravel falliti con `array_key_exists(): Argument #1 ($key) must be a valid array offset type`.
- **Fatto:** `ServiceProvider::mergeConfigFrom($path, $key)` fa `require $path` dentro il metodo: una variabile `$key` (o `$path`) dichiarata nel file di config sovrascrive il parametro del framework.
- **Regola:** nei file di config usare nomi di variabile specifici (es. `$versionedKey`) o nessuna variabile locale.

## 2026-11-20 — Gestione canali APNs e iscrizioni topic FCM (W2-P3)
- **Contesto:** W2-P3, JSON Apple `sending-channel-management-requests-to-apns` e Discovery FCM rev. 20261112.
- **Fatto:** Apple documenta create 201 (id base64 nell'header `apns-channel-id`, corpo vuoto), read 200, delete 204, list 200 `{"channels":[...]}`, limite 10.000 canali per ambiente, nessuna paginazione, e non elenca alcuna stringa `reason` specifica dei canali (ne `FeatureNotEnabled` ne `CannotCreateChannelConfig` compaiono nella pagina ne in quella generica delle risposte): mappate per nome dal brief, non verificate. Discovery `topicSubscriptions`: create = POST con query `topicName`, ALREADY_EXISTS se esiste; patch con `allowMissing` (upsert); delete con `allowMissing` (successo senza azione); list con `pageSize` (default 1000, max 2000) e `pageToken`; nessuna operazione bulk.
- **Regola:** una create di canale con esito ignoto non si ritenta: `reconcile()` via `all()`. Gli errori Google non si copiano mai (`error.message` puo contenere il token): si leggono solo `error.status` e `details[].errorCode` se hanno forma di enum. Per tenere token e id di registrazione fuori da `var_export($exception)` marcare `#[\SensitiveParameter]` ogni parametro nello stack che li contiene (anche oggetti come `FcmToken`).

## 2026-11-20 — Registro dispositivi: gotcha Laravel/SQLite (W2-P2)
- **Contesto:** `src/Laravel/Devices`, test con SQLite.
- **Fatto:** (1) il query builder formatta i `DateTimeInterface` legati come `Y-m-d H:i:s` (senza millisecondi) anche se il modello ha `$dateFormat` con `.v`: i confronti `registered_at` vs timestamp APNs in millisecondi vanno fatti in PHP, e gli `update()` di massa vanno passati con `$model->fromDateTime()`. (2) `Migrator::path()` accetta il percorso di un singolo file `.php`: un modulo carica solo la propria migration senza trascinare quelle degli altri moduli nella stessa cartella. (3) Una corsa vera tra due richieste si simula con un file SQLite e due connessioni: la seconda scrive e committa dentro l'evento `creating` della prima (transazione differita, nessun lock ancora); con `:memory:` il rollback della transazione cancellerebbe anche la riga concorrente. (4) `ServiceProvider::publishes()` e' protected: un provider anonimo registra il tag senza toccare quello principale. (5) `Orchestra\Testbench\TestCase::$app` e' protected: negli helper usare `app()`.
- **Regola:** timestamp ms confrontati in PHP; per registrare un tag di publish da un modulo usare un provider anonimo.

## 2026-11-20 — libcurl 8.5 rimanda da solo le richieste (anche già elaborate) → duplicati
- **Contesto:** W1-A, `src/Core/Transport/Curl`, server mock `tests/Support/H2Mock`.
- **Fatto:** `Curl_retry_request()` (lib/transfer.c) ripete in silenzio, su una connessione nuova e fino a 5 volte, un trasferimento senza byte di risposta se lo stream è stato rifiutato (`REFUSED_STREAM`, o id sopra il last-stream-id di `GOAWAY`) **oppure** se la connessione era «riusata» (ogni connessione multiplex) ed è caduta. Misurato con `CURLOPT_POSTFIELDS`: una richiesta elaborata dal server e poi persa con la connessione è stata elaborata **due volte**, e PHP ha visto un 200 normale (`CURLINFO_REQUEST_SIZE` raddoppiato 131→262). Rifiuto permanente → errno 56 «Connection died, tried 5 times», il server l'ha ricevuta 6 volte.
- **Regola:** inviare sempre il body con `CURLOPT_READFUNCTION` + `CURLOPT_UPLOAD` + `CURLOPT_CUSTOMREQUEST` + `CURLOPT_INFILESIZE`, senza seek callback (PHP 8.3 non lo imposta): il re-invio fallisce con errno 65 «necessary data rewind wasn't possible» e il server riceve la richiesta una volta sola. Le richieste senza body (GET/DELETE) restano ripetibili da libcurl: usarle solo per chiamate idempotenti.

## 2026-11-20 — REFUSED_STREAM e GOAWAY non si possono promuovere a NotSent da PHP
- **Contesto:** W1-A, indagine richiesta da PLAN §4.1/§13.
- **Fatto:** il binding PHP 8.3 non espone codici di frame HTTP/2 né last-stream-id; con body in memoria i casi sono invisibili (errno 0) o errno 56; con body in streaming danno errno 65 con lo stesso messaggio di una connessione riusata chiusa pulita dopo aver elaborato la richiesta. Non distinguibili in modo affidabile.
- **Regola:** restano `Unknown`. Rivalutare solo con PHP ≥ 8.4 (`CURLOPT_DEBUGFUNCTION`) o se libcurl espone lo stato.

## 2026-11-20 — Prova di NotSent: errno da solo non basta
- **Contesto:** W1-A, `CurlErrorClassifier`.
- **Fatto:** misurato errno 7 «Couldn't connect» con `CURLINFO_REQUEST_SIZE` 131: era il retry interno di libcurl di una richiesta già scritta. `REQUEST_SIZE` è cumulativo tra i retry interni, `PRETRANSFER_TIME_T` non viene azzerato, `SIZE_UPLOAD_T` invece sì (non usarlo come prova). Con TLS 1.3 un certificato client mancante emerge dopo che la richiesta è stata scritta (errno 56, REQUEST_SIZE 53) → `Unknown`, non NotSent.
- **Regola:** NotSent solo se errno di fase pre-richiesta **e** REQUEST_SIZE = 0 **e** PRETRANSFER = 0 **e** nessun byte di body letto né di risposta ricevuto.

## 2026-11-20 — Variabili d'ambiente proxy, ALPN, MAXAGE_CONN, curl_upkeep
- **Contesto:** W1-A, misure nel container.
- **Fatto:** libcurl usa `HTTPS_PROXY`/`NO_PROXY` dell'ambiente (qui il proxy dell'agente ha intercettato un test DNS e `NO_PROXY=localhost` scavalcava un proxy esplicito). `CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE` su TLS offre comunque `http/1.1` in ALPN e degrada in silenzio. Default di `CURLOPT_MAXAGE_CONN` = 118 s (url.c; misurato: riuso dopo 110 s, nuova connessione dopo 125 s). `curl_upkeep()` non invia PING HTTP/2: per gli handle in un multi ritorna subito, e in 8.5 i protocolli HTTP(S) non hanno `connection_check` (0 PING contati dal server).
- **Regola:** impostare sempre `CURLOPT_PROXY` (anche '') e `CURLOPT_NOPROXY=''`; verificare `CURLINFO_HTTP_VERSION` dopo la risposta; impostare `MAXAGE_CONN` esplicitamente; affidare la vitalità a TCP keepalive, non a `curl_upkeep()`.

## 2026-11-20 — fork(): il figlio non deve chiudere i pool ereditati, ma lo shutdown di PHP lo fa comunque
- **Contesto:** W1-A, `CurlPoolRegistry`, test con `pcntl_fork`.
- **Fatto:** chiudere un handle ereditato fa `SSL_read` + `SSL_shutdown` sul socket del padre (`ossl_close()` 8.5). Il registro li abbandona al cambio di PID, ma se il figlio termina normalmente lo shutdown di PHP li libera: misurato, la connessione del padre viene chiusa dal server (il padre si riprende al send successivo; gli in-flight di quel momento sarebbero `Unknown`). Anche il `register_shutdown_function` del `MockServer` veniva eseguito dal figlio e fermava il server del padre.
- **Regola:** non inviare prima di un fork; un figlio che deve uscire senza toccare le connessioni ereditate termina con `posix_kill(getmypid(), SIGKILL)`. Le funzioni di shutdown dei test devono controllare `getmypid()`.

## 2026-11-20 — Server mock Node: trappole
- **Contesto:** W1-A, `tests/Support/H2Mock/server.mjs`.
- **Fatto:** `session.socket` HTTP/2 è un proxy: `destroy()` lancia `ERR_HTTP2_NO_SOCKET_MANIPULATION` (serve il TLSSocket reale da `secureConnection`). Nel compat API `res.writableFinished` è true anche per stream resettati: usare `stream.rstCode`. Gli stream aperti dopo un GOAWAY con last-stream-id più basso vengono scartati da nghttp2 e non arrivano all'applicazione (non contabili come ricevuti). libcurl 8.5 accoda il `RST_STREAM` degli handle rimossi e lo spedisce solo alla successiva attività sulla connessione.
- **Regola:** negli oracoli dei test contare «elaborate» dal server e confrontarle con gli Exchange, non pretendere che il server conti stream mai consegnati all'applicazione.

## 2026-11-20 — Ispezione credenziali e adattatore google/auth (W2-P5)
- **Contesto:** src/Core/Credentials, GoogleAuthLibraryProvider.
- **Fatto:** (1) una arrow fn `static fn () => openssl_pkcs12_read($b, $out, ...)` cattura $out per valore: serve `function () use (&$out)`. (2) `@` non basta nei test Pest: il warning PHP di openssl_x509_read compare comunque; usare set_error_handler locale. (3) Un provider che riceve un oggetto google/auth lo espone in var_export (la chiave privata del service account): va tenuto in uno SplObjectStorage come TokenSlot. (4) Estensioni Apple 1.2.840.113635.100.6.3.4 (topic) e .6.3.6 (universale): decodificate con un lettore DER proprio, non con openssl_x509_parse (valori grezzi per OID ignoti); la semantica esatta delle etichette (app/voip/...) e il contenuto di .6.3.6 NON sono verificati su certificati Apple reali. Un CN 'Apple Push Services: x' senza .6.3.6 resta Unknown. (5) Validita' di un certificato di test: openssl_csr_sign accetta solo giorni, quindi i test spostano il FakeClock rispetto a notAfter.
- **Regola:** niente chiavi o credenziali di terzi come proprieta' visibili a var_export; ambiente del certificato mai dedotto oltre CN/estensioni.

## 2026-11-20 — Log sink: trappole Laravel/OTel (W2-P6)
- **Contesto:** `src/Laravel/Logging`, test con canale monolog e provider OTel in memoria.
- **Fatto:** (1) il driver `monolog` di Laravel costruisce l'handler con `make($handler, ['level'=>...])`: un'istanza legata con `app()->instance()` viene ignorata (parametri non vuoti); nei test usare un handler che scrive in una proprieta' statica. (2) `open-telemetry/api` non include le semantic conventions (nessun pacchetto `sem-conv` installato): i nomi `messaging.*`, `error.type`, `http.response.status_code` sono scritti a memoria dalla specifica, non verificati sulle sorgenti. (3) Gli strumenti OTel (`CounterInterface`, `HistogramInterface`) richiedono anche `isEnabled()` (da `Instrument`); `Span` e' una classe astratta da estendere, non un'interfaccia da implementare. (4) Una arrow function `fn` cattura per valore al momento della creazione: un `use (&$x)` annidato non vede le modifiche successive. (5) `Illuminate\Database\Query\Builder::newQuery()` non porta la tabella: riaprire `connection->table()` per ogni chunk. (6) PHPStan max vuole attributi OTel come `non-empty-array<non-empty-string,...>`: costruirli esplicitamente, non con `array_filter`.
- **Regola:** i segreti a runtime (bearer, marcatori di ricevuta) arrivano al `LogRecordFactory` solo nel contesto `secrets`: senza, i marcatori in URL o payload non sono riconosciuti (verificato togliendoli dal test: perdono).

## 2026-11-20 — Sender: avvio del primo blocco dentro send() (W2-A)
- **Contesto:** `src/Core/Driver/Sender.php`, `SendReport` a cursore.
- **Fatto:** con un generatore puro, `send()` non fa nulla finché il report non viene letto: un report ignorato non invia niente e un messaggio globalmente invalido esploderebbe dal report, non da `send()`. `send()` chiama `$generator->current()` prima di costruire il `SendReport` (che usa solo valid/current/next, mai rewind), così la prima finestra parte subito e le eccezioni globali escono da `send()`.
- **Regola:** chi usa il Sender deve consumare il report (`counts()`, `each()`); dopo la prima finestra nessuna eccezione per destinazione può più uscire, tutto diventa `SendResult`.

## 2026-11-20 — Ripresa da ledger: la scadenza relativa non va ricalcolata
- **Contesto:** W2-A, `SendItem` con `RetryBudget` ripreso.
- **Fatto:** `PushMessage::resolveExpiry(now)` con `ttl()` ricalcola la scadenza dall'istante della ripresa: un messaggio ripreso dopo 10 minuti avrebbe vissuto 10 minuti in più. Il Sender usa, in ordine: `SendOptions::$expiresAt`, poi `RetryBudget::$expiresAt` del budget ripreso (anche se null), poi la scadenza del messaggio fissata all'inizio dell'invio.
- **Regola:** il relay passa sempre il budget salvato (o `expiresAt` dell'effetto) e mai si affida al ttl relativo del messaggio.

## 2026-11-20 — PushMessage::ttl(0) non invia mai
- **Contesto:** W2-A.
- **Fatto:** `ttl(0)` è documentato come «ora o mai», ma pinna `expiresAt = createdAt` e `RetryBudget::isExpired()` usa `now >= expiresAt`: `beforeAttempt()` restituisce subito `Expired`, zero richieste. Non corretto (file fuori corsia), segnalato.
- **Regola:** finché non si decide, usare `ttl(1)` o più per «ora o mai».

## 2026-11-20 — @ non basta con failOnWarning
- **Contesto:** W2-A, `ApnsCredential::certificate()` con `openssl_x509_fingerprint()` su un PEM non valido.
- **Fatto:** con `@openssl_x509_fingerprint(...)` PHPUnit (failOnWarning=true) segnala comunque il warning «X.509 Certificate cannot be retrieved». `set_error_handler(fn () => true)` attorno alle chiamate OpenSSL, poi `restore_error_handler()` e svuotamento di `openssl_error_string()`, lo elimina.
- **Regola:** per le funzioni openssl che emettono warning su input invalido usare un error handler temporaneo, non `@`.

## 2026-11-20 — FakeTransport e invii enormi
- **Contesto:** W2-A, scenario «un milione di destinazioni».
- **Fatto:** `FakeTransport` registra ogni richiesta (body compreso): 1M richieste non stanno in memoria. Nel test l'handler `respondUsing` conta e chiama `$transport->reset()` ogni 1000 richieste (sicuro dentro `send()`). Con questo la crescita di memoria del Sender misurata è ~2,2 MB sia a 10k sia a 100k destinazioni (i 1000 risultati trattenuti dal report); 100k in ~2 s, 1M in ~20 s.
- **Regola:** nei test di volume contare nel callback e azzerare il registro; asserire il tetto di memoria campionando durante `each()`.

## 2026-11-20 — Ledger: concorrenza simulata su SQLite e limiti (W2-P4)
- **Contesto:** `src/Laravel/Ledger/DatabaseLedger`, test `tests/Scenario/Laravel/Ledger/ConcurrentClaimTest.php`.
- **Fatto:** (1) `SQLiteGrammar::compileLock()` restituisce `''`: `->lock('for update skip locked')` su SQLite viene ignorato, quindi il percorso SKIP LOCKED si può eseguire ma non dimostrare. (2) Una gara vera tra SELECT e UPDATE si inietta con `DB::connection('a')->beforeExecuting(fn (string $sql) => ...)` e una seconda connessione allo stesso file SQLite: funziona solo se la prima connessione NON è in transazione; dentro la transazione del percorso SKIP LOCKED la seconda connessione resta bloccata fino al busy timeout (60 s) e fallisce con «database is locked». (3) `memory_get_peak_usage()` non conta la memoria di SQLite (malloc di libsqlite): con `memory_reset_peak_usage()` (PHP 8.2+) si misura solo la crescita PHP; 1.000.000 di righe da un generatore in chunk da 1000 restano sotto 24 MB e richiedono ~51 s su SQLite in memoria. (4) Un cancel su una riga in volo che incrementa `version` rende stantia la lease del worker e fa perdere l'esito reale della richiesta: il flag `cancel_requested` si scrive SENZA cambiare versione, e chi scrive dopo (record, reclaim) fa guardia anche su quel flag.
- **Regola:** nel ledger ogni scrittura è un UPDATE con guardia su `version` (+ lease owner, + `cancel_requested` dove serve), calcolato da `LedgerRules`; le prove di concorrenza «vere» su MySQL/PostgreSQL vanno fatte in CI con quei database.

## 2026-11-20 — Il budget «contaminato» vale anche per scadenza e cancel nel ledger
- **Contesto:** W2-P4, `DeliveryStateMachine`.
- **Fatto:** una riga rimessa in coda dopo un esito ignoto (lease persa dopo l'inizio dell'invio, messaggio sostituibile) che poi scade o viene annullata non può diventare `Expired`/`Cancelled`: il primo tentativo potrebbe essere stato accettato. Il ledger tiene la colonna `unknown_seen` (copia di `RetryBudget::$unknownOutcomeSeen`) e in quei casi termina con `OutcomeUnknown`.
- **Regola:** ogni transizione «di stop» del ledger consulta `unknown_seen`.

## 2026-11-20 — ttl(0), origine delle transizioni, silent FCM su iOS (W3-FIX)
- **Contesto:** correzioni a RetryBudget/Planner, Guards, FcmCompiler, ApnsCompiler.
- **Fatto:** (1) con ttl(0) la scadenza coincide con l'istante di creazione: il flag `singleAttempt` nel budget (serializzato, default false) rende lecito il primo tentativo e vieta ogni retry, failover incluso; un 503 termina Expired, un esito ignoto resta OutcomeUnknown. ApnsCompiler emetteva un epoch passato: ora scrive 0 se expiresAt <= now. (2) Il Discovery di FCM (Message.data) conferma che i messaggi solo-dati verso iOS ammettono solo apns-priority 5. (3) Un 503 APNs mette in pausa l'account 60 s (throttle): in un test di ripresa far avanzare il clock prima di reinviare.
- **Regola:** una proprietà che cambia il comportamento del retry viaggia nel budget, non in un confronto di timestamp.

## 2026-11-20 — Health/metriche: trappole cache e test (W3-E)
- **Contesto:** `src/Laravel/Health`.
- **Fatto:** (1) il TTL delle cache Laravel usa l'orologio reale (Carbon), non il `FakeClock`: un cooldown testabile deve salvare l'istante di fine e confrontarlo con il `ClockInterface` (`add` per l'atomicità, poi `get` se già presente). (2) `Repository::increment` su chiave assente crea la chiave senza TTL su molti store: per i bucket a finestra fare prima `add($k, 0, $ttl)`; l'increment poi conserva il TTL. (3) Un file di test con più classi non è autocaricabile per le classi non omonime: `require_once` del file kit in cima ai test. (4) `Illuminate\Contracts\Cache\Store` richiede anche `touch()`. (5) Dopo `config()->set` nei test, i singleton già risolti dal boot del provider (HealthSettings) vanno `forgetInstance`. (6) PHPStan max: i docblock su closure assegnate a variabile non valgono, usare metodi statici privati.
- **Regola:** nessun conteggio per destinazione come label; l'indice delle serie è limitato da `metrics.max_series`.

## 2026-11-20 — Il marcatore ricevuta non può puntare a provider_message_id
- **Contesto:** W3-D, ricevute client.
- **Fatto:** `SendRun` passa al callback `receiptMarker` solo `(Destination, messageId)`, dove `messageId` è l'id generato dal sender. Il ledger però salva `provider_message_id` = id della risposta (per FCM è il nome assegnato da Google, non il nostro UUID) e lo scrive solo in `record()`: un recapito `OutcomeUnknown` (risposta persa) non lo ha mai. Quindi cercare la riga per `provider_message_id` non funziona né per FCM né per il caso "risposta persa", proprio quello in cui la ricevuta serve di più.
- **Regola:** il riferimento nel marcatore è il message id del sender; la riga si trova (1) se il relay usa `DeliveryReference::messageIdFor($deliveryId)` come `SendItem::$providerMessageId` (mappatura ULID<->UUID senza tabella segreta, esiste prima dell'invio), (2) altrimenti dalla colonna `message_id` scritta da `record()` dal `meta['message_id']` del risultato.

## 2026-11-20 — Test che chiamano `report()` / base64url senza padding
- **Contesto:** W3-D, test Pest.
- **Fatto:** una funzione globale di test chiamata `report()` va in conflitto con l'helper di Laravel (`Cannot redeclare report()`); la lunghezza base64url senza padding è `ceil(n*4/3)` (41 byte -> 55 caratteri), e `base64_decode(strict)` rifiuta input senza padding quando manca `=`.
- **Regola:** nei file di test prefissare le funzioni globali (`clientReports`, `receiptAt`); per decodificare base64url in strict mode riaggiungere il padding.
