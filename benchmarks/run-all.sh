#!/usr/bin/env bash
# Reproduces every number in benchmarks/RESULTS.md. Takes roughly 20-30 minutes.
# Usage: benchmarks/run-all.sh [--million]     (the 1,000,000-destination run adds a few minutes)
set -euo pipefail
cd "$(dirname "$0")/.."
out=benchmarks/results
mkdir -p "$out"
million=""
[ "${1:-}" = "--million" ] && million="--million"

run() { # name, args...
  local name="$1"; shift
  echo ">>> php benchmarks/run.php $* --json --out=$out/$name.json" | tee -a "$out/commands.txt"
  php benchmarks/run.php "$@" --json --out="$out/$name.json" > /dev/null
}

: > "$out/commands.txt"
run transport-warm-d0      --scenario=transport-warm --count=20000 --server-delay-ms=0  --repeat=5
run transport-warm-d20     --scenario=transport-warm --count=20000 --server-delay-ms=20 --repeat=5
run transport-cold-d0      --scenario=transport-cold --count=20000 --server-delay-ms=0  --repeat=5
run transport-warm-single --scenario=transport-warm --count=1 --server-delay-ms=0 --repeat=30
run transport-cold-single --scenario=transport-cold --count=1 --server-delay-ms=0 --repeat=30
run pipeline-apns-d0       --scenario=pipeline-apns  --count=20000 --server-delay-ms=0  --repeat=5
run pipeline-apns-d20      --scenario=pipeline-apns  --count=20000 --server-delay-ms=20 --repeat=5
run pipeline-fcm-d0        --scenario=pipeline-fcm   --count=20000 --server-delay-ms=0  --repeat=5
run pipeline-fcm-d20       --scenario=pipeline-fcm   --count=20000 --server-delay-ms=20 --repeat=5
run pipeline-mixed-d0      --scenario=pipeline-mixed-outcomes --count=20000 --server-delay-ms=0 --repeat=5
run window-sweep           --scenario=window-sweep --count=10000 --server-delay-ms=20 --repeat=3
run connections-sweep      --scenario=connections-sweep --count=20000 --server-delay-ms=20 --repeat=3
run large-batch-memory     --scenario=large-batch-memory $million
run many-tenants           --scenario=many-tenants --tenants=200 --per-tenant=5 --repeat=3
run compare-pushok-w100    --scenario=compare-pushok --count=5000 --window=100 --server-delay-ms=10 --repeat=7
run compare-pushok-w20     --scenario=compare-pushok --count=5000 --window=20  --server-delay-ms=10 --repeat=7
run compare-pushok-d0      --scenario=compare-pushok --count=5000 --window=100 --server-delay-ms=0  --repeat=7
echo "done"
