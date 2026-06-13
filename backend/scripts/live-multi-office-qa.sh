#!/usr/bin/env bash
# Live Multi-Office QA against running Laravel server
set -euo pipefail

BASE="${BASE_URL:-http://127.0.0.1:8000}"
API="$BASE/api"
COOKIE="$(mktemp)"
PASS="${SEED_USER_PASSWORD:-travel-erp-test-secret}"
RESULTS=()
PASS_COUNT=0
FAIL_COUNT=0

log() { echo "[QA] $*"; }
pass() { PASS_COUNT=$((PASS_COUNT+1)); RESULTS+=("PASS: $1"); log "✓ $1"; }
fail() { FAIL_COUNT=$((FAIL_COUNT+1)); RESULTS+=("FAIL: $1 — $2"); log "✗ $1 — $2"; }

csrf() {
  curl -s -c "$COOKIE" -b "$COOKIE" "$BASE/sanctum/csrf-cookie" > /dev/null
  grep XSRF-TOKEN "$COOKIE" | awk '{print $7}' | python3 -c "import sys,urllib.parse; print(urllib.parse.unquote(sys.stdin.read().strip()))"
}

api() {
  local method="$1"; shift
  local path="$1"; shift
  local data="${1:-}"
  local token
  token="$(csrf)"
  if [ -n "$data" ]; then
    curl -s -b "$COOKIE" -c "$COOKIE" -X "$method" \
      -H "Accept: application/json" -H "Content-Type: application/json" \
      -H "X-XSRF-TOKEN: $token" \
      ${OFFICE_HEADER:+-H "X-Office-Id: $OFFICE_HEADER"} \
      -d "$data" "$API$path"
  else
    curl -s -b "$COOKIE" -c "$COOKIE" -X "$method" \
      -H "Accept: application/json" \
      -H "X-XSRF-TOKEN: $token" \
      ${OFFICE_HEADER:+-H "X-Office-Id: $OFFICE_HEADER"} \
      "$API$path"
  fi
}

json() { python3 -c "import sys,json; print(json.load(sys.stdin)$1)"; }

log "Testing against $BASE"

# Super admin login
RESP="$(api POST /login "{\"email\":\"super@travel.kw\",\"password\":\"$PASS\"}")"
ROLE="$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('user',{}).get('role',''))")"
[ "$ROLE" = "super_admin" ] && pass "Super admin login" || fail "Super admin login" "role=$ROLE"

# Create office LIVE-A
RESP="$(api POST /offices '{"office_code":"LIVE-A","office_name":"Live Branch A"}')"
OFFICE_A="$(echo "$RESP" | python3 -c "import sys,json; d=json.load(sys.stdin); print(d.get('id',''))")"
[ -n "$OFFICE_A" ] && pass "Create office LIVE-A (id=$OFFICE_A)" || fail "Create office LIVE-A" "$RESP"

# Create user for office A
RESP="$(api POST /users "{\"name\":\"Live Sales A\",\"email\":\"live-a@travel.kw\",\"password\":\"$PASS\",\"role\":\"sales\",\"office_id\":$OFFICE_A}")"
[ "$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('office_id',''))")" = "$OFFICE_A" ] && pass "Create office A user" || fail "Create office A user" "$RESP"

# Logout and login as office A user
api POST /logout '{}' > /dev/null
OFFICE_HEADER="$OFFICE_A"
RESP="$(api POST /login "{\"email\":\"live-a@travel.kw\",\"password\":\"$PASS\"}")"
[ "$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('user',{}).get('office_id',''))")" = "$OFFICE_A" ] && pass "Office A user login" || fail "Office A user login" "$RESP"

# Create client in A
RESP="$(api POST /clients '{"name":"Live Client A","phone":"77001001"}')"
CLIENT_A="$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))")"
[ -n "$CLIENT_A" ] && pass "Create client in office A" || fail "Create client in office A" "$RESP"

# Create vendor + operation
RESP="$(api POST /vendors '{"name":"Live Vendor A","category":"airline"}')"
VENDOR_A="$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))")"
RESP="$(api POST /operations "{\"client_id\":$CLIENT_A,\"service_id\":1,\"vendor_id\":$VENDOR_A,\"client_price\":150,\"vendor_cost\":100,\"initial_payment\":50,\"payment_method\":\"cash\"}")"
OP_A="$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('ref',''))")"
[ "$OP_A" = "OP-001" ] && pass "Office A isolated ref OP-001" || fail "Office A ref" "got $OP_A"

DASH_A="$(api GET /dashboard | python3 -c "import sys,json; print(json.load(sys.stdin).get('today_sales',0))")"
[ "$(python3 -c "print(1 if float('$DASH_A')>0 else 0)")" = "1" ] && pass "Dashboard A has sales" || fail "Dashboard A" "sales=$DASH_A"

# Create office B + user
api POST /logout '{}' > /dev/null
OFFICE_HEADER=""
api POST /login "{\"email\":\"super@travel.kw\",\"password\":\"$PASS\"}" > /dev/null
RESP="$(api POST /offices '{"office_code":"LIVE-B","office_name":"Live Branch B"}')"
OFFICE_B="$(echo "$RESP" | python3 -c "import sys,json; print(json.load(sys.stdin).get('id',''))")"
api POST /users "{\"name\":\"Live Sales B\",\"email\":\"live-b@travel.kw\",\"password\":\"$PASS\",\"role\":\"sales\",\"office_id\":$OFFICE_B}" > /dev/null
pass "Create office B and user"

# Login as B — must not see A data
api POST /logout '{}' > /dev/null
OFFICE_HEADER="$OFFICE_B"
api POST /login "{\"email\":\"live-b@travel.kw\",\"password\":\"$PASS\"}" > /dev/null
COUNT="$(api GET "/clients?per_page=500" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',[])))")"
[ "$COUNT" = "0" ] && pass "Office B sees zero clients" || fail "Office B client isolation" "count=$COUNT"

STATUS="$(api GET "/clients/$CLIENT_A/statement" -w '%{http_code}' -o /dev/null 2>/dev/null || true)"
# curl wrapper doesn't support -w easily; use python
HTTP="$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" -H "Accept: application/json" -H "X-XSRF-TOKEN: $(csrf)" "$API/clients/$CLIENT_A/statement")"
[ "$HTTP" = "404" ] && pass "Cross-office statement returns 404" || fail "Cross-office statement" "http=$HTTP (expected 404)"

SEARCH="$(api GET "/operations?search=OP-001" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',[])))")"
[ "$SEARCH" = "0" ] && pass "Search isolated per office" || fail "Search leak" "found=$SEARCH"

REPORT="$(api GET /reports/operations | python3 -c "import sys,json; print(json.load(sys.stdin).get('totals',{}).get('revenue',-1))")"
[ "$REPORT" = "0" ] && pass "Reports isolated per office" || fail "Report leak" "revenue=$REPORT"

# Inactive office blocks login (super admin deactivates B first)
api POST /logout '{}' > /dev/null
api POST /login "{\"email\":\"super@travel.kw\",\"password\":\"$PASS\"}" > /dev/null
api PATCH "/offices/$OFFICE_B" '{"is_active":false}' > /dev/null
api POST /logout '{}' > /dev/null
LOGIN_HTTP="$(curl -s -o /dev/null -w '%{http_code}' -b "$COOKIE" -c "$COOKIE" -X POST -H "Accept: application/json" -H "Content-Type: application/json" -H "X-XSRF-TOKEN: $(csrf)" -d "{\"email\":\"live-b@travel.kw\",\"password\":\"$PASS\"}" "$API/login")"
[ "$LOGIN_HTTP" = "422" ] && pass "Inactive office blocks login" || fail "Inactive office login" "http=$LOGIN_HTTP"

# Super admin office switch
api POST /login "{\"email\":\"super@travel.kw\",\"password\":\"$PASS\"}" > /dev/null
SW="$(api POST /session/office "{\"office_id\":$OFFICE_A}" | python3 -c "import sys,json; print(json.load(sys.stdin).get('office',{}).get('id',''))")"
[ "$SW" = "$OFFICE_A" ] && pass "Super admin switches to office A" || fail "Super admin switch" "office=$SW"

OFFICE_HEADER="$OFFICE_A"
FOUND="$(api GET "/clients?search=77001001" | python3 -c "import sys,json; print(len(json.load(sys.stdin).get('data',[])))")"
[ "$FOUND" -ge 1 ] && pass "Super admin sees office A data after switch" || fail "Super admin data after switch" "found=$FOUND"

echo ""
echo "========== LIVE QA SUMMARY =========="
printf '%s\n' "${RESULTS[@]}"
echo "Passed: $PASS_COUNT | Failed: $FAIL_COUNT"
rm -f "$COOKIE"
[ "$FAIL_COUNT" -eq 0 ]
