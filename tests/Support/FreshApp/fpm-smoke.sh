#!/usr/bin/env bash
#
# The package under PHP-FPM (not a test of the suite; run by the "runtimes" CI job on a freshly created application
# that has the routes of octane-routes.php):
#
#     bash tests/Support/FreshApp/fpm-smoke.sh /path/to/app /path/to/package
#
# Two FPM workers serve FastCGI requests sent by `cgi-fcgi` (no web server in between). The oracle is the local HTTP/2
# mock (wire-mock.php), which counts the requests and the connections it sees:
#   - 60 HTTP requests, one push on the wire each  -> 60 received on 60 connections: nothing is reused between
#     requests, although the same two worker processes serve them all;
#   - 10 HTTP requests, 5 pushes in one send() each -> 50 received on 10 connections: inside one request the
#     pushes share the connection.
# It needs php-fpm (any binary name of the running PHP version) and cgi-fcgi (package libfcgi-bin).

set -euo pipefail

app="$(cd "$1" && pwd)"
package="$(cd "$2" && pwd)"
port=9123
work="$app/storage/fpm-smoke"
rm -rf "$work" && mkdir -p "$work"

fpm="$(command -v "php-fpm$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" || command -v php-fpm || true)"
test -n "$fpm" || { echo "php-fpm not found"; exit 2; }
command -v cgi-fcgi > /dev/null || { echo "cgi-fcgi not found (package libfcgi-bin)"; exit 2; }

cat > "$work/fpm.conf" <<CONF
[global]
error_log = $work/fpm.log
pid = $work/fpm.pid
daemonize = no
[www]
listen = 127.0.0.1:$port
pm = static
pm.max_children = 2
clear_env = no
catch_workers_output = yes
CONF

# As root (a container) FPM wants the pool user named and an explicit permission to run as root.
root_flag=()
if [ "$(id -u)" = "0" ]; then
    printf '%s\n' 'user = root' 'group = root' >> "$work/fpm.conf"
    root_flag=(-R)
fi

# One GET through FastCGI; prints the response body (the JSON line of the route). cgi-fcgi forwards its whole
# environment as request parameters, so it gets a clean one: a web server would send these and nothing else.
client="$(command -v cgi-fcgi)"
get() {
    env -i SCRIPT_FILENAME="$app/public/index.php" SCRIPT_NAME=/index.php REQUEST_URI="$1" DOCUMENT_URI=/index.php \
        REQUEST_METHOD=GET QUERY_STRING= SERVER_NAME=localhost SERVER_PORT=80 SERVER_PROTOCOL=HTTP/1.1 HTTP_HOST=localhost \
        "$client" -bind -connect "127.0.0.1:$port" < /dev/null | tr -d '\r' | sed '1,/^$/d'
    echo
}

# Start a mock, run the requests of "$2..." against it, stop it and print its counters.
with_mock() {
    local name="$1"; shift
    rm -f "$work/wire.json" "$work/wire.json.stop" "$work/wire.json.stats"
    php "$package/tests/Support/FreshApp/wire-mock.php" "$work/wire.json" > "$work/mock-$name.log" 2>&1 &
    for _ in $(seq 1 50); do test -s "$work/wire.json" && break; sleep 0.2; done
    test -s "$work/wire.json"
    "$@" > "$work/$name.txt"
    touch "$work/wire.json.stop"
    for _ in $(seq 1 50); do test -s "$work/wire.json.stats" && break; sleep 0.2; done
    cp "$work/wire.json.stats" "$work/$name.stats"
    echo "$name: $(cat "$work/$name.stats")"
}

single() { for n in $(seq 1 60); do get "/wire/$n"; done; }
batch() { for n in $(seq 1 10); do get "/wire-batch/$n/5"; done; }
pushes() { for n in $(seq 1 20); do get "/push/$n"; done; }

sed -i '/^MOBILE_PUSH_WIRE_MOCK=/d' "$app/.env"
echo "MOBILE_PUSH_WIRE_MOCK=$work/wire.json" >> "$app/.env"

MOBILE_PUSH_WIRE_MOCK="$work/wire.json" "$fpm" -y "$work/fpm.conf" -F "${root_flag[@]}" > "$work/fpm.out" 2>&1 &
fpm_pid=$!
trap 'kill "$fpm_pid" 2> /dev/null || true; touch "$work/wire.json.stop"' EXIT
for _ in $(seq 1 50); do (exec 3<> "/dev/tcp/127.0.0.1/$port") 2> /dev/null && break; sleep 0.2; done

with_mock single single
with_mock batch batch
pushes > "$work/pushes.txt"

head -1 "$work/single.txt"
head -1 "$work/batch.txt"
head -1 "$work/pushes.txt"

# Served by PHP-FPM, by at most the two workers of the pool.
test "$(grep -c '"sapi":"fpm-fcgi"' "$work/single.txt")" = 60
workers="$(grep -ho '"pid":[0-9]*' "$work/single.txt" "$work/batch.txt" | sort -u | wc -l)"
echo "distinct FPM worker processes: $workers"
test "$workers" -ge 1 && test "$workers" -le 2

# One push per request: every request opened its own connection.
test "$(grep -c '"status":200,"http":"2"' "$work/single.txt")" = 60
php -r '$s = json_decode(file_get_contents($argv[1]), true); exit($s["received"] === 60 && $s["processed"] === 60 && $s["connections"] === 60 ? 0 : 1);' "$work/single.stats"

# Five pushes per request: one connection per request.
test "$(grep -c '"ok":5,"of":5' "$work/batch.txt")" = 10
php -r '$s = json_decode(file_get_contents($argv[1]), true); exit($s["received"] === 50 && $s["processed"] === 50 && $s["connections"] === 10 ? 0 : 1);' "$work/batch.stats"

# The full pipeline (manager, safe mode) answers under FPM as it does elsewhere: simulated, default scope.
test "$(grep -c '"statuses":\["simulated"\]' "$work/pushes.txt")" = 20
test "$(grep -c '"scope":"default"' "$work/pushes.txt")" = 20

echo "PHP-FPM smoke passed"
