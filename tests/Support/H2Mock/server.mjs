// Scriptable HTTP/2 (and HTTP/1.1) mock server for transport tests.
//
// Zero dependencies: only Node built-ins. Started by MockServer.php with one JSON argument:
//   {
//     "certPath", "keyPath", "caPath": PEM files generated at runtime by the PHP helper,
//     "mode": "h2" | "h2+h1" | "h1"            (ALPN offered by the TLS port),
//     "requireClientCert": bool                (mTLS, client cert must chain to caPath),
//     "maxConcurrentStreams": int              (HTTP/2 SETTINGS_MAX_CONCURRENT_STREAMS),
//     "recordLimit": int                       (how many requests are kept in /requests),
//     "proxy": bool                            (also open an HTTP CONNECT proxy port)
//   }
// When ready it prints one JSON line on stdout: {"ready":true,"port":..,"controlPort":..,"proxyPort":..}.
// It exits when its stdin closes (the PHP parent died or called stop()).
//
// Control API (plain HTTP/1.1 on 127.0.0.1:controlPort, JSON bodies):
//   GET  /health                 -> {"ok":true}
//   GET  /stats                  -> counters (see emptyStats())
//   GET  /requests               -> recorded requests (capped by recordLimit)
//   PUT  /rules                  -> replace the rule list  {"rules":[...]}
//   POST /rules                  -> append rules           {"rules":[...]}
//   POST /settings               -> {"maxConcurrentStreams":n,"recordLimit":n}
//   POST /reset                  -> clear rules, counters, recorded requests and close every open session
//   POST /close-sessions         -> destroy every open connection, keep counters
//
// A rule: {
//   "method": "POST", "path": "^/3/device/", "headers": {"apns-topic": "^com\\."},  (regexes; all optional)
//   "afterRequests": n,          only match once the server has already received n requests (since reset)
//   "times": n,                  only the first n matches use this rule
//   "action": "respond" | "drop" | "refuse" | "goaway" | "hang" | "close-mid-body",
//   "status": 200, "headers": {..}, "body": "..", "delayMs": 0,
//   "lastStreamId": "previous" | "current" | <int>,   (goaway only, default "previous")
//   "goawayCode": 0                                    (goaway only, HTTP/2 error code)
// }
// Rules are evaluated in order; the first matching rule wins; no match -> 200 with an empty JSON body "{}".
//
// Actions and what they count:
//   respond         processed = yes, then answers (after delayMs)
//   drop            processed = yes, then the TCP connection is destroyed before any response byte is sent
//   refuse          processed = no,  RST_STREAM(REFUSED_STREAM) on that stream (HTTP/1.1: connection destroyed)
//   goaway          GOAWAY(lastStreamId) on the session. Streams <= lastStreamId are answered like "respond";
//                   streams above it are NOT processed and are left for the client to retry elsewhere.
//                   The session is then closed gracefully once the remaining streams finish.
//   hang            processed = no,  never answers
//   close-mid-body  processed = yes, sends the headers and half of the body, then destroys the connection

import http from 'node:http';
import http2 from 'node:http2';
import https from 'node:https';
import net from 'node:net';
import fs from 'node:fs';

const config = JSON.parse(process.argv[2] ?? '{}');
const mode = config.mode ?? 'h2';
const settings = {
    maxConcurrentStreams: config.maxConcurrentStreams ?? 1000,
    recordLimit: config.recordLimit ?? 1000,
};

let rules = [];
let stats = emptyStats();
let recorded = [];
let nextSessionId = 1;
const liveSockets = new Set();
const liveSessions = new Set();

function emptyStats() {
    return {
        connections: 0,          // TLS handshakes completed
        tcpConnections: 0,       // TCP connections accepted on the TLS port
        openConnections: 0,      // TCP connections currently open
        tlsErrors: 0,            // handshakes that failed (untrusted client, missing client cert, ...)
        alpn: {},                // negotiated ALPN protocol => count
        sessions: 0,             // HTTP/2 sessions
        received: 0,             // requests whose headers arrived
        processed: 0,            // requests the server acted upon (see actions)
        responded: 0,            // complete responses written
        refused: 0,              // streams reset with REFUSED_STREAM
        goaways: 0,              // GOAWAY frames sent by a rule
        notProcessedAfterGoaway: 0,
        pings: 0,                // HTTP/2 PING frames received from clients
        clientResets: 0,         // streams the client reset/aborted before completion
        maxConcurrentStreams: 0, // max concurrently open streams observed on any single session
        perSession: {},          // sessionId => {received, maxConcurrent, protocol}
        proxyConnects: 0,
    };
}

// ---------------------------------------------------------------- request handling

function matchRule(method, path, headers) {
    for (const rule of rules) {
        if (rule.times !== undefined && rule.used >= rule.times) continue;
        if (rule.method && rule.method.toUpperCase() !== method) continue;
        if (rule.path && !new RegExp(rule.path).test(path)) continue;
        if (rule.afterRequests !== undefined && stats.received <= rule.afterRequests) continue;
        if (rule.headers) {
            let ok = true;
            for (const [name, re] of Object.entries(rule.headers)) {
                const value = headers[name.toLowerCase()];
                if (value === undefined || !new RegExp(re).test(String(value))) { ok = false; break; }
            }
            if (!ok) continue;
        }
        rule.used = (rule.used ?? 0) + 1;
        return rule;
    }
    return null;
}

function record(entry) {
    if (recorded.length < settings.recordLimit) recorded.push(entry);
}

function sessionStats(id, protocol) {
    if (!stats.perSession[id]) stats.perSession[id] = { received: 0, active: 0, maxConcurrent: 0, protocol };
    return stats.perSession[id];
}

// One code path for HTTP/2 streams and HTTP/1.1 requests (compat API).
function handle(req, res) {
    const isH2 = req.httpVersionMajor === 2;
    const stream = isH2 ? req.stream : null;
    const session = isH2 ? stream.session : null;
    const sid = isH2 ? session.__id : req.socket.__id;
    const ss = sessionStats(sid, isH2 ? 'h2' : 'http/1.1');

    stats.received++;
    ss.received++;
    ss.active++;
    if (ss.active > ss.maxConcurrent) ss.maxConcurrent = ss.active;
    if (ss.active > stats.maxConcurrentStreams) stats.maxConcurrentStreams = ss.active;

    let finished = false;
    const done = () => { if (!finished) { finished = true; ss.active--; } };
    (stream ?? res).on('close', () => {
        if (!res.writableFinished && !req.__handled) stats.clientResets++;
        done();
    });

    const headers = {};
    for (const [k, v] of Object.entries(req.headers)) if (!k.startsWith(':')) headers[k] = Array.isArray(v) ? v.join(', ') : String(v);
    const path = req.url;
    const method = req.method;
    const rule = matchRule(method, path, headers);
    const chunks = [];

    req.on('data', (c) => chunks.push(c));
    req.on('end', () => {
        const body = Buffer.concat(chunks).toString('utf8');
        const action = rule?.action ?? 'respond';
        const entry = { method, path, headers, body, session: sid, stream: stream?.id ?? null, protocol: isH2 ? 'h2' : 'http/1.1', action, processed: false };
        record(entry);
        const markProcessed = () => { entry.processed = true; stats.processed++; };

        const respond = () => {
            const send = () => {
                if (res.destroyed || (stream && stream.destroyed)) return;
                req.__handled = true;
                const status = rule?.status ?? 200;
                const outHeaders = rule?.headers ?? { 'content-type': 'application/json' };
                const outBody = rule?.body ?? '{}';
                res.writeHead(status, outHeaders);
                res.end(outBody, () => { stats.responded++; });
            };
            const delay = rule?.delayMs ?? 0;
            if (delay > 0) setTimeout(send, delay); else send();
        };

        switch (action) {
            case 'respond':
                markProcessed();
                respond();
                break;
            case 'drop':
                markProcessed();
                req.__handled = true;
                rawSocket(isH2 ? session : null, req).destroy();
                break;
            case 'refuse':
                stats.refused++;
                req.__handled = true;
                if (isH2) stream.close(http2.constants.NGHTTP2_REFUSED_STREAM);
                else req.socket.destroy();
                break;
            case 'hang':
                req.__handled = true;
                break;
            case 'close-mid-body': {
                markProcessed();
                req.__handled = true;
                const outBody = rule?.body ?? '{"padding":"' + 'x'.repeat(2048) + '"}';
                res.writeHead(rule?.status ?? 200, { 'content-type': 'application/json', 'content-length': String(Buffer.byteLength(outBody)) });
                res.write(outBody.slice(0, Math.floor(outBody.length / 2)), () => {
                    setTimeout(() => rawSocket(isH2 ? session : null, req).destroy(), 20);
                });
                break;
            }
            case 'goaway': {
                if (!isH2) { req.socket.destroy(); break; }
                let last = rule.lastStreamId ?? 'previous';
                if (last === 'previous') last = Math.max(0, stream.id - 2);
                else if (last === 'current') last = stream.id;
                session.__goawayLast = last;
                stats.goaways++;
                session.goaway(rule.goawayCode ?? 0, last);
                if (stream.id <= last) { markProcessed(); respond(); }
                else { stats.notProcessedAfterGoaway++; entry.action = 'not-processed-after-goaway'; req.__handled = true; }
                // Streams above last that are still open are not processed (see the guard below).
                setTimeout(() => { if (!session.destroyed) session.close(); }, 50);
                break;
            }
            default:
                markProcessed();
                respond();
        }
    });
}

// Guard: after a GOAWAY, streams above last-stream-id must never be processed.
function guardedHandle(req, res) {
    if (req.httpVersionMajor === 2) {
        const session = req.stream.session;
        if (session.__goawayLast !== undefined && req.stream.id > session.__goawayLast) {
            stats.received++;
            stats.notProcessedAfterGoaway++;
            req.__handled = true;
            record({ method: req.method, path: req.url, headers: {}, body: '', session: session.__id, stream: req.stream.id, protocol: 'h2', action: 'not-processed-after-goaway', processed: false });
            return;
        }
    }
    handle(req, res);
}

// The HTTP/2 session socket is a guarded proxy: find the real TLS socket to drop it without a GOAWAY.
const rawByPeer = new Map();
function rawSocket(session, req) {
    if (!session) return req.socket;
    const key = `${session.socket.remoteAddress}:${session.socket.remotePort}`;
    return rawByPeer.get(key) ?? { destroy: () => session.destroy() };
}

// ---------------------------------------------------------------- TLS server

const tlsOptions = {
    cert: fs.readFileSync(config.certPath),
    key: fs.readFileSync(config.keyPath),
    ca: fs.readFileSync(config.caPath),
    requestCert: !!config.requireClientCert,
    rejectUnauthorized: !!config.requireClientCert,
};

let server;
if (mode === 'h1') {
    server = https.createServer({ ...tlsOptions, ALPNProtocols: ['http/1.1'], keepAlive: true }, guardedHandle);
    server.keepAliveTimeout = 600_000;
} else {
    server = http2.createSecureServer({
        ...tlsOptions,
        allowHTTP1: mode === 'h2+h1',
        settings: { maxConcurrentStreams: settings.maxConcurrentStreams },
        peerMaxConcurrentStreams: 100000,
        maxSessionMemory: 64,
    }, guardedHandle);
    server.on('session', (session) => {
        session.__id = 'h2-' + (nextSessionId++);
        stats.sessions++;
        sessionStats(session.__id, 'h2');
        liveSessions.add(session);
        session.settings({ maxConcurrentStreams: settings.maxConcurrentStreams });
        session.on('ping', () => { stats.pings++; });
        session.on('close', () => liveSessions.delete(session));
        session.on('error', () => {});
    });
    server.setTimeout(0);
}

server.on('connection', (socket) => {
    stats.tcpConnections++;
    stats.openConnections++;
    liveSockets.add(socket);
    socket.on('close', () => { stats.openConnections--; liveSockets.delete(socket); });
    socket.on('error', () => {});
});
server.on('secureConnection', (tlsSocket) => {
    stats.connections++;
    tlsSocket.__id = 'tls-' + (nextSessionId++);
    const proto = tlsSocket.alpnProtocol || 'none';
    stats.alpn[proto] = (stats.alpn[proto] ?? 0) + 1;
    const key = `${tlsSocket.remoteAddress}:${tlsSocket.remotePort}`;
    rawByPeer.set(key, tlsSocket);
    tlsSocket.on('close', () => rawByPeer.delete(key));
    tlsSocket.on('error', () => {});
});
server.on('tlsClientError', () => { stats.tlsErrors++; });

// ---------------------------------------------------------------- CONNECT proxy (optional)

let proxy = null;
if (config.proxy) {
    proxy = http.createServer((req, res) => { res.writeHead(405); res.end(); });
    proxy.on('connect', (req, clientSocket, head) => {
        stats.proxyConnects++;
        const upstream = net.connect(server.address().port, '127.0.0.1', () => {
            clientSocket.write('HTTP/1.1 200 Connection Established\r\nProxy-Agent: h2mock\r\n\r\n');
            if (head.length) upstream.write(head);
            upstream.pipe(clientSocket);
            clientSocket.pipe(upstream);
        });
        upstream.on('error', () => clientSocket.destroy());
        clientSocket.on('error', () => upstream.destroy());
    });
}

// ---------------------------------------------------------------- control API

function readJson(req) {
    return new Promise((resolve) => {
        const chunks = [];
        req.on('data', (c) => chunks.push(c));
        req.on('end', () => {
            const text = Buffer.concat(chunks).toString('utf8');
            try { resolve(text === '' ? {} : JSON.parse(text)); } catch { resolve({}); }
        });
    });
}

function closeAll() {
    for (const s of liveSessions) s.destroy();
    for (const s of liveSockets) s.destroy();
}

const control = http.createServer(async (req, res) => {
    const reply = (code, data) => { res.writeHead(code, { 'content-type': 'application/json' }); res.end(JSON.stringify(data)); };
    const url = new URL(req.url, 'http://control');
    const body = await readJson(req);
    switch (`${req.method} ${url.pathname}`) {
        case 'GET /health': return reply(200, { ok: true });
        case 'GET /stats': return reply(200, { ...stats, liveSessions: liveSessions.size, settings });
        case 'GET /requests': return reply(200, recorded);
        case 'PUT /rules': rules = (body.rules ?? []).map((r) => ({ ...r, used: 0 })); return reply(200, { rules: rules.length });
        case 'POST /rules': rules.push(...(body.rules ?? []).map((r) => ({ ...r, used: 0 }))); return reply(200, { rules: rules.length });
        case 'DELETE /rules': rules = []; return reply(200, { rules: 0 });
        case 'POST /settings':
            if (body.maxConcurrentStreams !== undefined) {
                settings.maxConcurrentStreams = body.maxConcurrentStreams;
                for (const s of liveSessions) s.settings({ maxConcurrentStreams: settings.maxConcurrentStreams });
            }
            if (body.recordLimit !== undefined) settings.recordLimit = body.recordLimit;
            return reply(200, settings);
        case 'POST /close-sessions': closeAll(); return reply(200, { ok: true });
        case 'POST /reset': {
            closeAll();
            rules = [];
            recorded = [];
            // Give the sockets a moment to emit 'close' so openConnections settles to 0 before zeroing.
            setTimeout(() => { stats = emptyStats(); reply(200, { ok: true }); }, 30);
            return;
        }
        default: return reply(404, { error: 'unknown endpoint' });
    }
});

// ---------------------------------------------------------------- start

const listen = (srv) => new Promise((resolve) => srv.listen(0, '127.0.0.1', () => resolve(srv.address().port)));

const port = await listen(server);
const controlPort = await listen(control);
const proxyPort = proxy ? await listen(proxy) : null;

process.stdout.write(JSON.stringify({ ready: true, port, controlPort, proxyPort, pid: process.pid }) + '\n');

process.stdin.on('data', () => {});
process.stdin.on('end', () => process.exit(0));
process.stdin.on('error', () => process.exit(0));
process.on('SIGTERM', () => process.exit(0));
