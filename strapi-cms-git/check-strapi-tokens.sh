#!/usr/bin/env bash
# Daily check that every Strapi API token configured on this host still
# authenticates, and that the running strapi-cms container's secrets match
# backend/strapi-deploy/strapi-secrets.env. Run by cronmanager; exits non-zero
# (a failed job) when anything is wrong.
#
# Why: on 11 Sep 2026 strapi-cms was recreated with a different API_TOKEN_SALT
# and every token returned 401 for three days before anyone noticed. The sites
# fall back to public reads or seed content, so nothing looks broken.
#
# Tokens are never printed. A token is valid when Strapi does not answer 401;
# 403 means valid but lacking permission on the probe collection.

set -uo pipefail

STRAPI="${STRAPI_URL:-http://127.0.0.1:8888}"
PROBE="/api/nxt-comments?pagination%5BpageSize%5D=1"
SECRETS_FILE=/opt/strapi-cms-git/backend/strapi-deploy/strapi-secrets.env

ENV_FILES=(
  /opt/projects/*/.env.local
  /opt/projects/*/.env
  /opt/nxt-job-runner/.env
  /opt/nxt-job-runner/.env.local
  /opt/strapi-cms-git/backend/nxt-sourcing/.env
  /opt/strapi-cms-git/backend/nxt-sourcing/.env.local
  /opt/strapi-cms-git/backend/ai-writer-cli/.env
  /opt/strapi-cms-git/backend/strapi-deploy/.env
)

failures=0
checked=0

if ! curl -s -o /dev/null --max-time 10 "$STRAPI/_health"; then
  echo "FAIL  Strapi not reachable at $STRAPI"
  exit 1
fi

# 1. Container secrets vs the pinned file (compared by hash, never shown).
if [ -f "$SECRETS_FILE" ]; then
  container_env="$(docker inspect strapi-cms --format '{{range .Config.Env}}{{println .}}{{end}}')"
  while IFS='=' read -r key value; do
    [[ "$key" =~ ^[A-Z_]+$ ]] || continue
    running="$(printf '%s\n' "$container_env" | grep -m1 "^${key}=" | cut -d= -f2-)"
    if [ "$(printf '%s' "$running" | sha256sum)" != "$(printf '%s' "$value" | sha256sum)" ]; then
      echo "FAIL  strapi-cms $key differs from strapi-secrets.env (container was recreated with other secrets?)"
      failures=$((failures + 1))
    fi
  done < <(grep -E '^[A-Z_]+=' "$SECRETS_FILE")
else
  echo "WARN  $SECRETS_FILE missing; secret drift not checked"
fi

# 2. Every *STRAPI*TOKEN* variable in the consumers' env files.
for file in "${ENV_FILES[@]}"; do
  [ -f "$file" ] || continue
  while IFS= read -r line; do
    key="${line%%=*}"
    value="$(printf '%s' "${line#*=}" | tr -d "\"' \r")"
    [ -n "$value" ] || continue
    checked=$((checked + 1))
    code="$(curl -s -o /dev/null --max-time 15 -w '%{http_code}' -H "Authorization: Bearer $value" "$STRAPI$PROBE")"
    if [ "$code" = 401 ] || [ "$code" = 000 ]; then
      echo "FAIL  $file $key -> HTTP $code"
      failures=$((failures + 1))
    else
      echo "ok    $file $key -> HTTP $code"
    fi
  done < <(grep -E '^[A-Z_]*STRAPI[A-Z_]*TOKEN[A-Z_]*=' "$file")
done

echo "$checked tokens checked, $failures problem(s)"
[ "$failures" -eq 0 ]
