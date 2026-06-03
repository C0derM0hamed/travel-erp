#!/usr/bin/env bash
# Tests all POST/PATCH mutating API endpoints after CSRF refresh post-login.
set -euo pipefail

BASE="${BASE_URL:-http://127.0.0.1:8080}"
EMAIL="${API_EMAIL:-admin@travel.kw}"
PASS="${API_PASSWORD:-Travel@2026}"
COOKIES="$(mktemp)"
trap 'rm -f "$COOKIES"' EXIT

get_xsrf() {
  python3 -c "import urllib.parse,re; d=open('$COOKIES').read(); m=re.search(r'XSRF-TOKEN\t([^\n]+)', d); print(urllib.parse.unquote(m.group(1)) if m else '')"
}

csrf() {
  curl -s -c "$COOKIES" -b "$COOKIES" -H "Accept: application/json" "$BASE/sanctum/csrf-cookie" -o /dev/null
}

api() {
  local method="$1" path="$2" body="${3:-}"
  local xsrf; xsrf="$(get_xsrf)"
  local args=(-s -c "$COOKIES" -b "$COOKIES" -H "Accept: application/json" -H "X-XSRF-TOKEN: $xsrf" -X "$method" -w "%{http_code}")
  if [[ -n "$body" ]]; then
    args+=(-H "Content-Type: application/json" -d "$body")
  fi
  local code
  code="$(curl "${args[@]}" "$BASE/api$path" -o /tmp/api-out.json)"
  echo "$method $path => $code"
  if [[ "$code" =~ ^(200|201|204)$ ]]; then
    return 0
  fi
  head -c 200 /tmp/api-out.json; echo
  return 1
}

echo "=== Auth ==="
csrf
api POST /login "{\"email\":\"$EMAIL\",\"password\":\"$PASS\"}"
csrf
echo "XSRF length after refresh: $(get_xsrf | wc -c)"

echo "=== Mutating endpoints ==="
api POST /clients '{"name":"API Test Client","phone":"90000999"}'
DUPE_CODE="$(curl -s -c "$COOKIES" -b "$COOKIES" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(get_xsrf)" -H "Content-Type: application/json" -X POST -d '{"name":"Dup Client","phone":"90000999"}' -w "%{http_code}" "$BASE/api/clients" -o /tmp/api-dup.json)"
echo "POST /clients duplicate => $DUPE_CODE"
[[ "$DUPE_CODE" == "422" ]] || { head -c 200 /tmp/api-dup.json; exit 1; }
api POST /vendors '{"name":"API Test Vendor Unique","category":"hotel","phone":"99001123"}'

BOOT="$(curl -s -c "$COOKIES" -b "$COOKIES" -H "Accept: application/json" "$BASE/api/bootstrap")"
CID=$(python3 -c "import json,sys; print(json.load(sys.stdin)['clients'][0]['id'])" <<<"$BOOT")
SID=$(python3 -c "import json,sys; print(json.load(sys.stdin)['services'][0]['id'])" <<<"$BOOT")
VID=$(python3 -c "import json,sys; print(json.load(sys.stdin)['vendors'][0]['id'])" <<<"$BOOT")
SAFE=$(python3 -c "import json,sys; print(json.load(sys.stdin)['safes'][0]['id'])" <<<"$BOOT")

api POST /operations "{\"client_id\":$CID,\"service_id\":$SID,\"vendor_id\":$VID,\"currency\":\"KWD\",\"client_price\":100,\"vendor_cost\":80,\"initial_payment\":10,\"payment_method\":\"cash\"}"
OPS="$(curl -s -c "$COOKIES" -b "$COOKIES" -H "Accept: application/json" "$BASE/api/operations")"
OID=$(echo "$OPS" | python3 -c "import json,sys; d=json.load(sys.stdin); items=d.get('data',d); print(items[0]['id'])")

api POST /vouchers "{\"type\":\"receipt\",\"party_type\":\"client\",\"party_id\":$CID,\"amount\":5,\"currency\":\"KWD\",\"method\":\"cash\",\"safe_id\":$SAFE,\"description\":\"test\"}"
api POST "/operations/$OID/cancel" ""
api PATCH "/services/$SID/toggle" ""
api PATCH /profile '{"name":"أحمد الكندري"}'

echo "=== All mutating tests passed ==="
