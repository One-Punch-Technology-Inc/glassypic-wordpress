#!/usr/bin/env bash
# End-to-end smoke test: real WordPress + ActionScheduler + this plugin, with a
# mocked GlassyPic API (tests/e2e/mock-api.php). Needs PHP 8.1+ with gd,
# pdo_sqlite and zip; git; curl. No MySQL: WordPress runs on SQLite.
#
#   composer e2e                    # or: bash tests/e2e/run.sh
#   WP_VERSION=7.1.3 bash tests/e2e/run.sh
set -uo pipefail

ROOT=$(cd "$(dirname "$0")/../.." && pwd)
WORK=${E2E_DIR:-"$ROOT/.e2e"}
WP_VERSION=${WP_VERSION:-7.1.3}
SQLITE_VERSION=${SQLITE_VERSION:-v3.1.0}
WPCLI_VERSION=${WPCLI_VERSION:-2.12.0}

mkdir -p "$WORK"
WPDIR="$WORK/wp-$WP_VERSION"
WP="php -d memory_limit=512M $WORK/wp-cli.phar --path=$WPDIR --allow-root"

# --- fetch (cached between runs) ---
[ -f "$WORK/wp-cli.phar" ] || curl -fsSL -o "$WORK/wp-cli.phar" \
	"https://github.com/wp-cli/wp-cli/releases/download/v$WPCLI_VERSION/wp-cli-$WPCLI_VERSION.phar"
[ -d "$WPDIR" ] || git -c advice.detachedHead=false clone -q --depth 1 --branch "$WP_VERSION" \
	https://github.com/WordPress/WordPress.git "$WPDIR"
[ -d "$WORK/sqlite-$SQLITE_VERSION" ] || git -c advice.detachedHead=false clone -q --depth 1 --branch "$SQLITE_VERSION" \
	https://github.com/WordPress/sqlite-database-integration.git "$WORK/sqlite-$SQLITE_VERSION"

# --- fresh site on SQLite with the current plugin code ---
SQ="$WPDIR/wp-content/plugins/sqlite-database-integration"
rm -rf "$SQ" "$WPDIR/wp-content/database" "$WPDIR/wp-content/uploads" "$WPDIR/wp-content/plugins/glassypic"
cp -rL "$WORK/sqlite-$SQLITE_VERSION/packages/plugin-sqlite-database-integration" "$SQ"
sed -e "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#" -e "s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" \
	"$SQ/db.copy" > "$WPDIR/wp-content/db.php"
mkdir -p "$WPDIR/wp-content/database"
[ -f "$WPDIR/wp-config.php" ] || $WP config create --dbname=wp --dbuser=x --dbpass=x --skip-check --quiet
$WP core install --url=http://localhost --title=E2E --admin_user=admin --admin_password=admin \
	--admin_email=admin@example.com --skip-email >/dev/null || { echo "WordPress install failed"; exit 1; }
mkdir -p "$WPDIR/wp-content/plugins/glassypic" "$WPDIR/wp-content/mu-plugins"
(cd "$ROOT" && tar --exclude=./.git --exclude=./.e2e -cf - .) | tar -xf - -C "$WPDIR/wp-content/plugins/glassypic"
cp "$ROOT/tests/e2e/mock-api.php" "$WPDIR/wp-content/mu-plugins/glassypic-mock-api.php"
$WP plugin activate glassypic >/dev/null || { echo "Plugin activation failed"; exit 1; }
echo "WordPress $($WP core version), PHP $(php -r 'echo PHP_VERSION;')"

# --- helpers ---
PASS=0; FAIL=0
check() { if [ "$2" = "$3" ]; then echo "  PASS $1"; PASS=$((PASS+1)); else echo "  FAIL $1: got '$2' want '$3'"; FAIL=$((FAIL+1)); fi; }
meta() { $WP post meta get "$1" "$2" 2>/dev/null; }
pending_for() { $WP eval "echo count(as_get_scheduled_actions(['hook'=>'glassypic/process_attachment','args'=>[$1],'status'=>'pending'], 'ids'));"; }
run_queue() { $WP action-scheduler run --hooks=glassypic/process_attachment --batches=1 >/dev/null 2>&1; }
import_img() { $WP media import "$WORK/test.jpg" --porcelain 2>/dev/null; }
uploads_logged() { $WP eval '$n=0; foreach(get_option("mock_log",[]) as $e){ if($e["path"]==="/upload") $n++; } echo $n;'; }

$WP eval '$i=imagecreatetruecolor(1600,1200); for($x=0;$x<1600;$x++){imageline($i,$x,0,$x,1199,imagecolorallocate($i,$x%256,($x*3)%256,200));} imagejpeg($i,"'"$WORK"'/test.jpg",95);'

echo "== API key: first save (option does not exist yet) + verify"
$WP eval 'require_once ABSPATH."wp-admin/includes/template.php"; (new \GlassyPic\Settings())->register(); update_option("glassypic_api_key", "tfy_live_e2e0000000000000000000000000000000000000000000000000000000");'
check "key decrypts after first save" "$($WP eval 'echo (new \GlassyPic\Settings())->getApiKey();')" "tfy_live_e2e0000000000000000000000000000000000000000000000000000000"
check "verifyKey returns tier" "$($WP eval 'echo (new \GlassyPic\ApiClient((new \GlassyPic\Settings())->getApiKey()))->verifyKey()["tier"];' 2>&1)" "pro"

echo "== Happy path: upload -> queue -> process -> replace -> alt text"
$WP option update mock_mode ok >/dev/null
ID=$(import_img)
check "queued on upload" "$(meta "$ID" _glassypic_status)" "pending"
FILE=$($WP eval "echo get_attached_file($ID);")
BEFORE=$(stat -c %s "$FILE")
run_queue
check "status completed" "$(meta "$ID" _glassypic_status)" "completed"
check "file replaced with smaller one" "$([ "$(stat -c %s "$FILE")" -lt "$BEFORE" ] && echo yes || echo no)" "yes"
check "original backup kept" "$([ -f "$(meta "$ID" _glassypic_orig_backup)" ] && echo yes || echo no)" "yes"
check "alt text written" "$(meta "$ID" _wp_attachment_image_alt)" "Gradient test card with GlassyPic label"
check "/auto body nests settings" "$($WP eval '$a=array_values(array_filter(get_option("mock_log"),fn($e)=>$e["path"]==="/auto")); $b=$a[0]["body"]; echo (($b["settings"]["output_seo_tag_gen"]??null)===true && !isset($b["output_format"]) && count($b["temp_file_ids"])===1) ? "yes" : "no";')" "yes"
check "Bearer API key sent" "$($WP eval 'echo str_starts_with(get_option("mock_log")[0]["auth"]??"", "Bearer tfy_live_") ? "yes" : "no";')" "yes"
check "no re-queue after replace" "$(pending_for "$ID")" "0"

echo "== Expired job fails fast"
$WP option update mock_mode expired >/dev/null
ID=$(import_img); run_queue
check "status failed" "$(meta "$ID" _glassypic_status)" "failed"
check "error message" "$(meta "$ID" _glassypic_error)" "Processing job expired before completion"
check "not rescheduled" "$(pending_for "$ID")" "0"

echo "== Out of credits (402) pauses and schedules resume"
$WP option update mock_mode no_credits >/dev/null
ID=$(import_img); run_queue
check "status paused" "$(meta "$ID" _glassypic_status)" "paused"
check "resume scheduled" "$(pending_for "$ID")" "1"
check "reset time stored" "$($WP eval 'echo get_transient("glassypic_credits_reset_at") ? "yes" : "no";')" "yes"
$WP eval "as_unschedule_all_actions('glassypic/process_attachment', [ $ID ], 'glassypic');"

echo "== Slow job: one run fits a 30s host limit, then resumes without re-upload"
$WP option update mock_mode slow >/dev/null
ID=$(import_img)
T0=$(date +%s); run_queue; ELAPSED=$(( $(date +%s) - T0 ))
check "run under 30s (took ${ELAPSED}s)" "$([ "$ELAPSED" -lt 30 ] && echo yes || echo no)" "yes"
check "still processing" "$(meta "$ID" _glassypic_status)" "processing"
check "resume counter" "$(meta "$ID" _glassypic_poll_resumes)" "1"
check "resume scheduled" "$(pending_for "$ID")" "1"
UPLOADS=$(uploads_logged)
$WP option update mock_mode ok >/dev/null
$WP eval "do_action('glassypic/process_attachment', $ID);" >/dev/null 2>&1
check "resumed to completed" "$(meta "$ID" _glassypic_status)" "completed"
check "no re-upload on resume" "$(uploads_logged)" "$UPLOADS"

echo
echo "RESULT: $PASS passed, $FAIL failed"
[ "$FAIL" -eq 0 ]
