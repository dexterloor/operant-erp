#!/usr/bin/env bash
# Fills the five settings files that `make env` copies from the templates with throwaway values, for a fresh checkout
# only (the docs-free CI job, and its local dry run). It never prints a value: only names and counts.
#
#   bash .github/scripts/fill-env.sh
#
# Under GITHUB_ACTIONS=true every value is registered as a log mask (`::add-mask::`) before any file is written.
# Bash and POSIX awk only, so it runs the same on Linux and macOS. No in-place sed; no command tracing.
set -euo pipefail
cd "$(dirname "$0")/../.."

FILES=(.env .env.infra .env.test .env.worker .env.demo)
PASSWORD_FILES=(.env .env.infra .env.test .env.worker)
EXPECTED_TOKENS="CHANGE_ME_AI CHANGE_ME_APP CHANGE_ME_AUTH CHANGE_ME_AUTHLOG CHANGE_ME_OWNER CHANGE_ME_RATELIMIT CHANGE_ME_REDIS CHANGE_ME_SUPERUSER CHANGE_ME_WORKER"

fail() { echo "fill-env: $*" >&2; exit 1; }
tokens() { grep -ohE 'CHANGE_ME_[A-Za-z0-9_]+' "$@" 2>/dev/null | LC_ALL=C sort -u | tr '\n' ' ' | sed 's/ $//'; }

# 1. A fresh copy of every template: each file exists and holds exactly the placeholders its template holds.
for f in "${FILES[@]}"; do
  [ -f "$f" ] || fail "$f is missing (run make env first)"
  [ -f "$f.example" ] || fail "$f.example is missing"
  [ "$(tokens "$f")" = "$(tokens "$f.example")" ] || fail "$f does not hold its template's placeholders; this script fills a fresh copy only"
  [ -n "$(tokens "$f")" ] || fail "$f holds no placeholder"
done

# Rewrites one file through awk, reading values from the environment (never from -v, which processes escapes).
# The file is rewritten in place by content, so its mode is kept.
rewrite() {
  local file="$1" program="$2" tmp
  tmp="$(mktemp)"
  awk "$program" "$file" > "$tmp"
  cat "$tmp" > "$file"
  rm -f "$tmp"
}

mask() {
  if [ "${GITHUB_ACTIONS:-}" = "true" ]; then
    printf '::add-mask::%s\n' "$1"
  fi
}

# Generate every value first, and register its mask before the first write.
export FILL_KEY_AUTH_TOKEN_SIGNING_KEY FILL_KEY_AUTH_EMAIL_HASH_KEY FILL_KEY_AUTH_CREDENTIAL_MAC_KEY FILL_KEY_APP_SECRET FILL_KEY_DEMO_PASSWORD
FILL_KEY_AUTH_TOKEN_SIGNING_KEY="$(openssl rand -base64 32)"
FILL_KEY_AUTH_EMAIL_HASH_KEY="$(openssl rand -base64 32)"
FILL_KEY_AUTH_CREDENTIAL_MAC_KEY="$(openssl rand -base64 32)"
FILL_KEY_APP_SECRET="$(openssl rand -hex 32)"
FILL_KEY_DEMO_PASSWORD="$(openssl rand -hex 16)"
KEY_NAMES="AUTH_TOKEN_SIGNING_KEY AUTH_EMAIL_HASH_KEY AUTH_CREDENTIAL_MAC_KEY APP_SECRET DEMO_PASSWORD"

# The password tokens are read after the key lines are gone (step 2 runs first), so CHANGE_ME_APP_SECRET is never one.
for name in $KEY_NAMES; do
  var="FILL_KEY_$name"
  mask "${!var}"
done

# 2. Keys first: whole lines, matched by ^NAME= exactly.
written=0
for name in $KEY_NAMES; do
  hits=0
  for f in "${FILES[@]}"; do
    n="$(grep -c "^$name=" "$f" || true)"
    if [ "$n" -gt 0 ]; then
      hits=$((hits + n))
      FILL_NAME="$name" rewrite "$f" 'BEGIN { n = ENVIRON["FILL_NAME"]; v = ENVIRON["FILL_KEY_" n] } index($0, n "=") == 1 { print n "=" v; next } { print }'
    fi
  done
  [ "$hits" -eq 1 ] || fail "$name is set on $hits lines across the five files, expected exactly 1"
  written=$((written + 1))
done

# 3. Then passwords: one value per distinct token, replacing whole tokens (maximal runs) only.
found="$(tokens "${PASSWORD_FILES[@]}")"
[ "$found" = "$EXPECTED_TOKENS" ] || fail "expected exactly these nine password placeholders: $EXPECTED_TOKENS; found: $found"
for token in $found; do
  value="$(openssl rand -hex 24)"
  mask "$value"
  export "FILL_PW_${token#CHANGE_ME_}=$value"
done
for f in "${PASSWORD_FILES[@]}"; do
  rewrite "$f" '{
    out = ""; line = $0
    while (match(line, /CHANGE_ME_[A-Za-z0-9_]+/)) {
      tok = substr(line, RSTART, RLENGTH); v = ENVIRON["FILL_PW_" substr(tok, 11)]
      if (v == "") { v = tok }
      out = out substr(line, 1, RSTART - 1) v; line = substr(line, RSTART + RLENGTH)
    }
    print out line
  }'
done
for token in $found; do written=$((written + 1)); done

# 5. Afterwards: nothing left, all values distinct, every DSN agrees with the password it must carry.
for f in "${FILES[@]}"; do
  ! grep -q 'CHANGE_ME_' "$f" || fail "$f still holds a placeholder"
done
distinct="$(for token in $found; do var="FILL_PW_${token#CHANGE_ME_}"; printf '%s\n' "${!var}"; done; for name in $KEY_NAMES; do var="FILL_KEY_$name"; printf '%s\n' "${!var}"; done)"
[ "$(printf '%s\n' "$distinct" | LC_ALL=C sort -u | wc -l | tr -d ' ')" -eq "$written" ] || fail "two generated values are equal"

infra() { awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); print; exit }' .env.infra; }
password_for_user() {
  local user="$1" var
  [ "$user" = "$(infra DB_OWNER)" ] && { infra DB_OWNER_PASSWORD; return; }
  var="$(awk -F= -v u="$user" '$1 ~ /_USER$/ && $2 == u { sub(/_USER$/, "", $1); print $1 "_PASSWORD"; exit }' .env.infra)"
  [ -n "$var" ] || fail "no login in .env.infra is named $user"
  infra "$var"
}
checked=0
for f in .env .env.test .env.worker; do
  while IFS= read -r dsn; do
    user="${dsn#postgresql://}"; user="${user%%:*}"
    pass="${dsn#postgresql://*:}"; pass="${pass%%@*}"
    [ "$pass" = "$(password_for_user "$user")" ] || fail "the $user password in $f does not match .env.infra"
    checked=$((checked + 1))
  done < <(grep -oE 'postgresql://[a-z_]+:[^@]+@' "$f")
done
redis="$(grep -oE 'redis://:[^@]+@' .env)"; redis="${redis#redis://:}"; redis="${redis%@}"
[ "$redis" = "$(infra REDIS_PASSWORD)" ] || fail "the Redis password in .env does not match REDIS_PASSWORD"
[ "$(grep -oE 'postgresql://neondb_owner:[^@]+@' .env.test | head -1 | sed 's#^postgresql://neondb_owner:##; s#@$##')" = "$(infra DB_OWNER_PASSWORD)" ] || fail "the owner DSN in .env.test does not match DB_OWNER_PASSWORD"
[ "$checked" -ge 9 ] || fail "only $checked database URLs were checked, expected at least 9"

echo "filled $written values in ${#FILES[@]} files"
