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
