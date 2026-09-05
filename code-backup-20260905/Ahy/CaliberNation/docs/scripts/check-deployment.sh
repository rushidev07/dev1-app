#!/usr/bin/env bash
#
# Caliber Nation — deployment checker.
#
# Run from the Magento root on ANY instance (dev1, staging, production) to find every
# file the module depends on that lives OUTSIDE app/code/Ahy/CaliberNation — the ones
# that do not travel when you copy "the module".
#
#   cd /home2/dev1/www
#   bash app/code/Ahy/CaliberNation/docs/scripts/check-deployment.sh
#
# Reference md5s are from the known-good local instance. A mismatch means the file
# exists but differs (stale or locally modified) — inspect before overwriting.

set -uo pipefail

RED=$'\033[0;31m'; GRN=$'\033[0;32m'; YEL=$'\033[0;33m'; NC=$'\033[0m'
fail=0; warn=0

md5of() {
    if command -v md5sum >/dev/null 2>&1; then md5sum "$1" | awk '{print $1}'
    else md5 -q "$1"; fi
}

check_file() {          # path | expected_md5 | what it provides
    local path="$1" want="$2" desc="$3"
    if [ ! -f "$path" ]; then
        printf "  ${RED}MISSING${NC}  %-72s %s\n" "$path" "$desc"; fail=$((fail+1)); return
    fi
    local got; got=$(md5of "$path")
    if [ "$got" = "$want" ]; then
        printf "  ${GRN}ok     ${NC}  %-72s\n" "$path"
    else
        printf "  ${YEL}DIFFERS${NC}  %-72s %s\n" "$path" "$desc"
        printf "             expected %s\n             actual   %s\n" "$want" "$got"
        warn=$((warn+1))
    fi
}

check_contains() {      # path | needle | what it provides
    local path="$1" needle="$2" desc="$3"
    if [ ! -f "$path" ]; then
        printf "  ${RED}MISSING${NC}  %-72s %s\n" "$path" "$desc"; fail=$((fail+1)); return
    fi
    if grep -q -- "$needle" "$path" 2>/dev/null; then
        printf "  ${GRN}ok     ${NC}  %-72s\n" "$path"
    else
        printf "  ${RED}NO HOOK${NC}  %-72s %s\n" "$path" "$desc"
        printf "             missing: %s\n" "$needle"
        fail=$((fail+1))
    fi
}

echo
echo "=== 1. Theme files the module needs (not shipped with app/code) ==="
check_file "app/design/frontend/Ahy/Everest2/Magento_Checkout/templates/success.phtml" \
    "3924e27bd67dbf705894882950150dec" "membership congrats message on the thank-you page"
check_file "app/design/frontend/Ahy/Everest2/Hyva_Checkout/templates/breadcrumbs.phtml" \
    "c9cc928fb951c3cce438397225936dad" "checkout step numbering for single-step (virtual) carts"
check_file "app/design/frontend/Ahy/Everest2/Magento_Customer/layout/customer_account.xml" \
    "369e5593ab9fb6be18c1ca1b136efeee" "'Caliber Membership' link in the account nav"
check_file "app/design/frontend/Ahy/Everest2/Klevu_Categorynavigation/templates/html/head/js_additional.phtml" \
    "a25fc1260bc44b6e1c46e03eab8a48a0" "member prices on the PLP (Klevu overlay)"
check_file "app/code/Ahy/ThemeCustomization/view/frontend/templates/static-pages/caliber-nation.phtml" \
    "fc4400b4ae541a6168bdd42bf87548ef" "the /caliber-nation landing page"

echo
echo "=== 2. Hook points (a file can exist but not call the CN blocks) ==="
check_contains "app/design/frontend/Ahy/Everest2/Magento_Catalog/templates/product/view/product-info.phtml" \
    "caliber_nation.member_price" "theme PDP must render the member-price block"
check_contains "app/code/Ahy/PDPRevamp/view/frontend/templates/product/view/product-info.phtml" \
    "caliber_nation.member_price" "PDPRevamp overrides the theme PDP — needs the call too"
check_contains "app/design/frontend/Ahy/Everest2/Magento_Checkout/templates/success.phtml" \
    "cn_success" "success page must read the cn_success view model"
check_contains "app/design/frontend/Ahy/Everest2/Magento_Customer/layout/customer_account.xml" \
    "Caliber Membership" "account nav link"

echo
echo "=== 3. Duplicate member-price box (PDPRevamp's own stub) ==="
P="app/code/Ahy/PDPRevamp/view/frontend/templates/product/view/calibernation-price.phtml"
if [ -f "$P" ]; then
    # Skip the docblock: it mentions these strings even in the stripped version.
    if sed -n '15,400p' "$P" | grep -qiE "CALIBERNATION PRICE|EXCLUSIVE MEMBER|Join now"; then
        printf "  ${RED}DUPLICATE${NC} %s\n" "$P"
        printf "             still renders its own (stub-priced) Calibernation box —\n"
        printf "             replace with the stripped version so only CN renders it.\n"
        fail=$((fail+1))
    else
        printf "  ${GRN}ok     ${NC}  %s (plain PRICE/MSRP only)\n" "$P"
    fi
else
    printf "  ${YEL}absent ${NC}  %s (Ahy_PDPRevamp not installed?)\n" "$P"
fi

echo
echo "=== 4. Tailwind CSS — must be REBUILT on this box, never copied ==="
CSS="app/design/frontend/Ahy/Everest2/web/css/styles.css"
if [ ! -f "$CSS" ]; then
    printf "  ${RED}MISSING${NC}  %s\n" "$CSS"; fail=$((fail+1))
else
    for c in 'font-league-gothic' 'bg-ahy-blue' 'bg-green-700' \
             'tracking-\[0.2em\]' 'text-\[52px\]' 'text-\[11px\]' \
             'gap-3\.5' 'md\:divide-white\/10' 'rounded-2xl'; do
        n=$(grep -o -F -- ".$c" "$CSS" 2>/dev/null | wc -l | tr -d ' ')
        if [ "$n" -eq 0 ]; then
            printf "  ${RED}MISSING${NC}  css class: %s\n" "$c"; fail=$((fail+1))
        else
            printf "  ${GRN}ok     ${NC}  css class: %s\n" "$c"
        fi
    done
    printf "             if any are missing:\n"
    printf "               cd app/design/frontend/Ahy/Everest2/web/tailwind && npm ci && npm run build\n"
fi

echo
echo "=== 5. pub/static must serve the same CSS ==="
PUB="pub/static/frontend/Ahy/Ahy_Everest2/en_US/css/styles.css"
if [ -L "$PUB" ]; then
    printf "  ${GRN}ok     ${NC}  %s is a symlink (developer mode)\n" "$PUB"
elif [ -f "$PUB" ]; then
    a=$(wc -c < "$CSS" 2>/dev/null | tr -d ' '); b=$(wc -c < "$PUB" | tr -d ' ')
    if [ "$a" = "$b" ]; then
        printf "  ${GRN}ok     ${NC}  pub/static matches source (%s bytes)\n" "$a"
    else
        printf "  ${YEL}STALE  ${NC}  pub/static differs: source=%s pub=%s\n" "$a" "$b"
        printf "             run: rm -f %s && bin/magento cache:flush\n" "$PUB"
        printf "             (production mode: bin/magento setup:static-content:deploy en_US -f)\n"
        warn=$((warn+1))
    fi
else
    printf "  ${YEL}absent ${NC}  %s (will regenerate on next request)\n" "$PUB"
fi

echo
echo "=== 6. Module classes that were missing from an earlier pull ==="
for f in \
  "app/code/Ahy/CaliberNation/Model/Service/EarlyAccessService.php" \
  "app/code/Ahy/CaliberNation/ViewModel/EarlyAccessBadge.php" \
  "app/code/Ahy/CaliberNation/Plugin/Quote/BypassShippingForMembershipCart.php" \
  "app/code/Ahy/CaliberNation/Model/Service/RefundRequestService.php"; do
    if [ -f "$f" ]; then printf "  ${GRN}ok     ${NC}  %s\n" "$f"
    else printf "  ${RED}MISSING${NC}  %s\n" "$f"; fail=$((fail+1)); fi
done

echo
echo "=== 7. DB + config (module code alone is not enough) ==="
php -r '
$c = include "app/etc/env.php";
$d = $c["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s", $d["host"], $d["dbname"]), $d["username"], $d["password"]);

$tables = ["membership","activity_log","renewal_log","seller_participation",
           "member_price_rule","order_savings","refund_request"];
$missing = [];
foreach ($tables as $t) {
    $n = $pdo->query("SHOW TABLES LIKE \"ahy_caliber_nation_$t\"")->rowCount();
    if (!$n) { $missing[] = $t; }
}
printf("  %s  %d/%d tables present%s\n",
    $missing ? "MISSING" : "ok     ", count($tables) - count($missing), count($tables),
    $missing ? " — missing: " . implode(", ", $missing) . "  → run bin/magento setup:upgrade" : "");

$rows = $pdo->query("SELECT path, value FROM core_config_data WHERE path LIKE \"caliber_nation/%\"")
            ->fetchAll(PDO::FETCH_KEY_PAIR);
foreach ([
    "caliber_nation/general/enabled"      => "1",
    "caliber_nation/pricing/enabled"      => "1",
    "caliber_nation/general/member_group_id" => null,
    "caliber_nation/general/membership_sku"  => null,
] as $path => $want) {
    $have = $rows[$path] ?? "(unset - using config.xml default)";
    $flag = ($want !== null && (string)$have !== $want) ? "CHECK  " : "ok     ";
    printf("  %s  %-42s = %s\n", $flag, $path, $have);
}

$n = (int) $pdo->query("SELECT COUNT(*) FROM ahy_caliber_nation_seller_participation WHERE is_enabled=1")
               ->fetchColumn();
printf("  %s  participating sellers: %d%s\n", $n ? "ok     " : "CHECK  ", $n,
    $n ? "" : "  → no seller participating: member pricing is gated OFF for ALL seller products");
' 2>/dev/null || echo "  (could not read env.php / connect to DB)"

echo
echo "=== 8. Email safety (a dev box should not send) ==="
php -r '
$c = include "app/etc/env.php";
$d = $c["db"]["connection"]["default"];
$pdo = new PDO(sprintf("mysql:host=%s;dbname=%s", $d["host"], $d["dbname"]), $d["username"], $d["password"]);
$q = $pdo->query("SELECT path, value FROM core_config_data
                  WHERE path=\"system/smtp/disable\"
                     OR path LIKE \"smtp/general/%\"
                     OR (path LIKE \"%copy_to%\" AND value IS NOT NULL AND TRIM(value)<>\"\")");
foreach ($q as $r) {
    $flag = ($r["path"] === "system/smtp/disable" && $r["value"] !== "1") ? "CHECK  "
          : (strpos($r["path"], "copy_to") !== false ? "CHECK  " : "ok     ");
    printf("  %s  %-40s = %s\n", $flag, $r["path"], $r["value"]);
}
' 2>/dev/null || echo "  (could not query email config)"

echo
echo "──────────────────────────────────────────────────────────────"
printf " %d blocking issue(s), %d warning(s)\n" "$fail" "$warn"
echo "──────────────────────────────────────────────────────────────"
echo
exit 0
