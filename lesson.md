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
