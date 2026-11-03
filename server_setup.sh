#!/bin/bash
# Set up or update AFP Watch Out (Laravel) on the cPanel server, then check it from outside. In Terminal:
#     bash ~/surveillance_app/server_setup.sh
# Safe to run again: it never replaces the database, the workbooks, the SMS settings or the support password.
#
# The app lives in this folder (private). The subdomain's document root must be its public/ folder, which holds only
# index.php, the CSS/JS and the logos; the database, .env and workbooks are one level up, out of the web's reach.
set -e
APP="$(cd "$(dirname "$0")" && pwd)"
cd "$APP"
PHP="${PHP:-php}"
fail() { echo; echo "STOPPED: $1"; exit 1; }

echo "== PHP"
"$PHP" -r 'exit(PHP_VERSION_ID < 80300 ? 1 : 0);' \
  || fail "PHP $("$PHP" -r 'echo PHP_VERSION;') is too old. In cPanel open Select PHP Version, choose 8.3 (or newer), click Set as current, then run this again."
missing=$("$PHP" -r 'echo implode(" ", array_filter(["pdo_sqlite","sqlite3","mbstring","openssl","zip","gd","xml","xmlreader","xmlwriter","simplexml","dom","fileinfo","curl","iconv","ctype","tokenizer","zlib"], fn($e) => !extension_loaded($e)));')
[ -z "$missing" ] || fail "PHP is missing: $missing. In cPanel open Select PHP Version > Extensions, tick them, then run this again."
echo "  PHP $("$PHP" -r 'echo PHP_VERSION;') with every extension needed"

[ -f .env ] || fail "no .env in $APP. Upload dist/.env here (turn on Show Hidden Files in File Manager)."
grep -q '^APP_KEY=base64:' .env || fail ".env has no APP_KEY. Upload the dist/.env made by make_upload.py."
[ -f vendor/autoload.php ] || fail "vendor/ is missing: extract surveillance-app.zip here again."

HOST="$(sed -n 's#^APP_URL=https\?://##p' .env | tr -d '\r"/ ')"
WEB="$APP/public"

echo "== Document root of $HOST"
docroot() { uapi DomainInfo single_domain_data domain="$HOST" 2>/dev/null | sed -n 's/^ *documentroot: *//p'; }
if command -v uapi >/dev/null; then
  now="$(docroot)"
  if [ "$now" != "$WEB" ]; then
    echo "  It is ${now:-unknown}; pointing it at $WEB"
    cpapi2 SubDomain changedocroot rootdomain="${HOST#*.}" subdomain="${HOST%%.*}" dir="${WEB#$HOME/}" >/dev/null 2>&1 || true
    now="$(docroot)"
  fi
  [ "$now" = "$WEB" ] || fail "the document root of $HOST is ${now:-unknown}. In cPanel > Domains > $HOST > Manage, set it to ${WEB#$HOME/}, then run this again."
  echo "  $now"
fi

echo "== Folders and permissions"
mkdir -p storage/app/data storage/app/private storage/app/public storage/logs bootstrap/cache \
  storage/framework/cache/data storage/framework/sessions storage/framework/views
[ -f database/database.sqlite ] || touch database/database.sqlite
chmod 600 .env database/database.sqlite
chmod 700 storage/app/data storage/app/private
chmod -R u+rwX storage bootstrap/cache database

echo "== Database"
"$PHP" artisan package:discover --ansi >/dev/null
"$PHP" artisan migrate --force
"$PHP" artisan create-support --if-missing

echo "== Caches"
"$PHP" artisan optimize:clear >/dev/null
"$PHP" artisan optimize

if [ -n "$HOST" ] && command -v curl >/dev/null; then
  echo "== Checking https://$HOST from outside"
  status() { curl -s -o /dev/null -w '%{http_code}' "https://$HOST$1"; }
  login="$(status /login)"
  echo "  /login  $login (want 200)"
  bad=0
  for f in /.env /database/database.sqlite /storage/app/data/ /composer.json /artisan; do
    s="$(status "$f")"
    echo "  $f  $s (want 403 or 404)"
    [ "$s" = "200" ] && bad=1
  done
  [ "$bad" = "0" ] || fail "private files can be downloaded. The document root must be $WEB."
  [ "$login" = "200" ] || echo "  The login page did not answer 200: see the end of $APP/storage/logs/ (the newest laravel-*.log)."
fi

echo
echo "Done. Open https://${HOST:-your-subdomain}/login"
ls ./*.zip >/dev/null 2>&1 && echo "Delete the uploaded .zip files from $APP: rm $APP/*.zip"
exit 0
