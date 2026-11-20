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
