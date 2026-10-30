#!/usr/bin/env bash
#
# SmartPresensi - Pemasangan headless di Linux (idempoten).
#
# Skrip ini menyiapkan .env, database MySQL, aset frontend, migrasi, akun admin,
# site Nginx, unit systemd, dan cron scheduler. Aman dijalankan ulang: berkas
# .env dan connector_config.ini tidak ditimpa, dan baris cron tidak berganda.
#
# Pemakaian:
#   sudo bash deploy/linux/install.sh --app-url "http://192.168.1.10" \
#     --admin-name "Administrator Sekolah" \
#     --admin-email "admin@sekolah.sch.id" \
#     --admin-password "KataSandiKuat123"
#
#   sudo bash deploy/linux/install.sh --check    # hanya periksa prasyarat
#
# Lihat docs/PANDUAN_DEPLOYMENT_LINUX.md untuk penjelasan lengkap.

set -Eeuo pipefail

SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
DEFAULT_APP_DIR=$(cd -- "$SCRIPT_DIR/../.." && pwd)
TEMPLATE_DIR="$SCRIPT_DIR"

APP_DIR="$DEFAULT_APP_DIR"
APP_URL=""
SERVER_NAME="_"
WEB_PORT="80"
DB_HOST="127.0.0.1"
DB_PORT="3306"
DB_NAME="absensi_siswa"
DB_USER="smartpresensi"
DB_PASSWORD=""
ADMIN_NAME="Administrator Sekolah"
ADMIN_EMAIL=""
ADMIN_PASSWORD=""
SCHEDULER="cron"
PHP_BIN=""
PHP_FPM_SOCKET=""
APP_GROUP="www-data"
APP_OWNER="${SUDO_USER:-$(id -un)}"
KEEP_DEFAULT_SITE=0
OVERWRITE_ENV=0
CHECK_ONLY=0
SKIP_COMPOSER=0
SKIP_BUILD=0
SKIP_MIGRATE=0
SKIP_ADMIN=0
SKIP_NGINX=0
SKIP_SERVICES=0
SKIP_CONNECTOR=0

# --------------------------------------------------------------------------- #
# Utilitas
# --------------------------------------------------------------------------- #

log()  { printf '\033[1;34m[smartpresensi]\033[0m %s\n' "$*"; }
ok()   { printf '\033[1;32m  [OK]\033[0m %s\n' "$*"; }
warn() { printf '\033[1;33m  [PERHATIAN]\033[0m %s\n' "$*"; }
die()  { printf '\033[1;31m[GAGAL]\033[0m %s\n' "$*" >&2; exit 1; }

trap 'die "Instalasi berhenti pada baris $LINENO. Periksa pesan di atas."' ERR

usage() {
    sed -n '2,20p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
    cat <<'EOF'

Opsi:
  --app-dir PATH            Lokasi aplikasi (default: folder repo skrip)
  --app-url URL             APP_URL (default: http://<IP-LAN>)
  --server-name NAME        server_name Nginx (default: _)
  --web-port PORT           Port Nginx (default: 80)
  --db-host HOST            Host MySQL (default: 127.0.0.1)
  --db-port PORT            Port MySQL (default: 3306)
  --db-name NAME            Nama database (default: absensi_siswa)
  --db-user USER            User database (default: smartpresensi)
  --db-password PASS        Sandi database (default: acak / dari .env)
  --admin-name NAME         Nama administrator
  --admin-email EMAIL       Email administrator (wajib untuk membuat admin)
  --admin-password PASS     Sandi administrator (minimal 8 karakter)
  --scheduler cron|systemd  Cara menjalankan scheduler (default: cron)
  --php-bin PATH            Binary PHP CLI (default: php8.3)
  --php-fpm-socket PATH     Socket PHP-FPM (default: /run/php/phpX.Y-fpm.sock)
  --app-owner USER          Pemilik berkas aplikasi (default: $SUDO_USER)
  --keep-default-site       Jangan nonaktifkan site Nginx "default"
  --overwrite-env           Buat ulang .env dari .env.example (berisiko)
  --skip-composer           Lewati composer install
  --skip-build              Lewati npm ci && npm run build
  --skip-migrate            Lewati php artisan migrate
  --skip-admin              Lewati pembuatan akun admin
  --skip-nginx              Lewati konfigurasi Nginx
  --skip-services           Lewati unit systemd + cron
  --skip-connector          Jangan aktifkan connector systemd
  --check                   Hanya periksa prasyarat lalu keluar
  -h, --help                Tampilkan bantuan ini
EOF
}

have() { command -v "$1" >/dev/null 2>&1; }

generate_secret() {
    if have openssl; then
        openssl rand -hex 24
    else
        head -c 32 /dev/urandom | od -An -tx1 | tr -d ' \n'
    fi
}

env_get() {
    local key="$1" file="$2" raw
    [ -f "$file" ] || return 0
    raw=$(sed -n "s/^${key}=//p" "$file" | head -n1)

    case "$raw" in
        \"*\")
            raw=${raw#\"}
            raw=${raw%\"}
            raw=${raw//\\\"/\"}
            raw=${raw//\\\\/\\}
            ;;
    esac

    printf '%s' "$raw"
}

set_env() {
    local key="$1" value="$2" file="$3"

    KEY="$key" VALUE="$value" awk '
        BEGIN { FS = OFS = "=" }
        $1 == ENVIRON["KEY"] { print ENVIRON["KEY"] "=" ENVIRON["VALUE"]; found = 1; next }
        { print }
        END { if (!found) print ENVIRON["KEY"] "=" ENVIRON["VALUE"] }
    ' "$file" > "${file}.tmp" && mv "${file}.tmp" "$file"
}

set_env_quoted() {
    local key="$1" value="$2" file="$3" escaped

    escaped=$(printf '%s' "$value" | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g')
    set_env "$key" "\"${escaped}\"" "$file"
}

sql_escape() { printf '%s' "$1" | sed "s/'/''/g"; }

run_as_app() {
    if [ "$(id -u)" -eq 0 ] && [ "$APP_OWNER" != "root" ] && id -u "$APP_OWNER" >/dev/null 2>&1; then
        if have runuser; then
            runuser -u "$APP_OWNER" -- "$@"
        else
            sudo -u "$APP_OWNER" -- "$@"
        fi
    else
        "$@"
    fi
}

# Template memakai path default dokumentasi (/var/www/smartpresensi) agar bisa
# disalin langsung; installer menyesuaikan path bila lokasi aplikasi berbeda.
render_template() {
    local src="$1" dst="$2"
    sed -e "s#/var/www/smartpresensi#${APP_DIR}#g" \
        -e "s#/usr/bin/php8\.3#${PHP_BIN}#g" \
        -e "s#/usr/bin/python3#${PYTHON_BIN}#g" \
        "$src" > "$dst"
}

render_nginx() {
    local src="$1" dst="$2"
    render_template "$src" "$dst"
    sed -i \
        -e "s#listen 80;#listen ${WEB_PORT};#g" \
        -e "s#listen \[::\]:80;#listen [::]:${WEB_PORT};#g" \
        -e "s#server_name _;#server_name ${SERVER_NAME};#g" \
        -e "s#/run/php/php8\.3-fpm\.sock#${PHP_FPM_SOCKET}#g" \
        "$dst"
}

# --------------------------------------------------------------------------- #
# Parsing argumen
# --------------------------------------------------------------------------- #

while [ $# -gt 0 ]; do
    case "$1" in
        --app-dir)          APP_DIR="$2"; shift 2 ;;
        --app-url)          APP_URL="$2"; shift 2 ;;
        --server-name)      SERVER_NAME="$2"; shift 2 ;;
        --web-port)         WEB_PORT="$2"; shift 2 ;;
        --db-host)          DB_HOST="$2"; shift 2 ;;
        --db-port)          DB_PORT="$2"; shift 2 ;;
        --db-name)          DB_NAME="$2"; shift 2 ;;
        --db-user)          DB_USER="$2"; shift 2 ;;
        --db-password)      DB_PASSWORD="$2"; shift 2 ;;
        --admin-name)       ADMIN_NAME="$2"; shift 2 ;;
        --admin-email)      ADMIN_EMAIL="$2"; shift 2 ;;
        --admin-password)   ADMIN_PASSWORD="$2"; shift 2 ;;
        --scheduler)        SCHEDULER="$2"; shift 2 ;;
        --php-bin)          PHP_BIN="$2"; shift 2 ;;
        --php-fpm-socket)   PHP_FPM_SOCKET="$2"; shift 2 ;;
        --app-owner)        APP_OWNER="$2"; shift 2 ;;
        --keep-default-site) KEEP_DEFAULT_SITE=1; shift ;;
        --overwrite-env)    OVERWRITE_ENV=1; shift ;;
        --skip-composer)    SKIP_COMPOSER=1; shift ;;
        --skip-build)       SKIP_BUILD=1; shift ;;
        --skip-migrate)     SKIP_MIGRATE=1; shift ;;
        --skip-admin)       SKIP_ADMIN=1; shift ;;
        --skip-nginx)       SKIP_NGINX=1; shift ;;
        --skip-services)    SKIP_SERVICES=1; shift ;;
        --skip-connector)   SKIP_CONNECTOR=1; shift ;;
        --check)            CHECK_ONLY=1; shift ;;
        -h|--help)          usage; exit 0 ;;
        *)                  die "Opsi tidak dikenal: $1 (gunakan --help)" ;;
    esac
done

case "$SCHEDULER" in
    cron|systemd) ;;
    *) die "--scheduler harus 'cron' atau 'systemd'." ;;
esac

APP_DIR=$(cd -- "$APP_DIR" && pwd) || die "Folder aplikasi tidak ditemukan: $APP_DIR"
[ -f "$APP_DIR/artisan" ] || die "Bukan folder aplikasi Laravel (artisan tidak ada): $APP_DIR"

# --------------------------------------------------------------------------- #
# Deteksi lingkungan
# --------------------------------------------------------------------------- #

if [ -z "$PHP_BIN" ]; then
    for candidate in php8.3 php; do
        if have "$candidate"; then PHP_BIN=$(command -v "$candidate"); break; fi
    done
fi

PYTHON_BIN=$(command -v python3 2>/dev/null || true)

detect_php_fpm_socket() {
    [ -n "$PHP_FPM_SOCKET" ] && return 0
    local short
    short=$("$PHP_BIN" -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;' 2>/dev/null || true)
    for path in "/run/php/php${short}-fpm.sock" "/var/run/php/php${short}-fpm.sock"; do
        if [ -S "$path" ] || [ -e "$path" ]; then PHP_FPM_SOCKET="$path"; return 0; fi
    done
    PHP_FPM_SOCKET="/run/php/php${short}-fpm.sock"
}

# --------------------------------------------------------------------------- #
# Pemeriksaan prasyarat
# --------------------------------------------------------------------------- #

check_prerequisites() {
    log "Memeriksa prasyarat..."

    if [ "$(id -u)" -ne 0 ]; then
        die "Jalankan dengan sudo/root: sudo bash deploy/linux/install.sh"
    fi
    ok "Hak akses root."

    [ -n "$PHP_BIN" ] && have "$PHP_BIN" || die "PHP CLI tidak ditemukan. Pasang php8.3-cli."
    local php_id
    php_id=$("$PHP_BIN" -r 'echo PHP_VERSION_ID;')
    [ "$php_id" -ge 80300 ] || die "PHP 8.3+ dibutuhkan, terdeteksi $("$PHP_BIN" -r 'echo PHP_VERSION;')."
    ok "PHP $("$PHP_BIN" -r 'echo PHP_VERSION;') ($PHP_BIN)."

    local missing=()
    for ext in pdo_mysql mbstring xml curl zip bcmath gd intl; do
        "$PHP_BIN" -m | grep -qi "^${ext}$" || missing+=("$ext")
    done
    if [ ${#missing[@]} -gt 0 ]; then
        die "Ekstensi PHP belum terpasang: ${missing[*]}. Pasang paket php8.3 untuk ekstensi tersebut."
    fi
    ok "Ekstensi PHP wajib tersedia."

    if [ "$SKIP_COMPOSER" -eq 0 ]; then
        have composer || die "Composer tidak ditemukan. Pasang composer."
        ok "Composer tersedia."
    fi

    if [ "$SKIP_BUILD" -eq 0 ]; then
        have npm || die "npm tidak ditemukan. Pasang Node.js 22 + npm."
        ok "Node $(node -v) / npm $(npm -v) tersedia."
    fi

    [ -n "$PYTHON_BIN" ] || die "python3 tidak ditemukan. Pasang paket python3."
    ok "Python $("$PYTHON_BIN" -V 2>&1 | awk '{print $2}') ($PYTHON_BIN)."

    if [ "$SKIP_NGINX" -eq 0 ]; then
        have nginx || die "Nginx tidak ditemukan. Pasang paket nginx."
        ok "Nginx tersedia."
    fi

    have mysql || die "Klien mysql tidak ditemukan. Pasang mysql-client/mariadb-client."
    ok "Klien MySQL tersedia."

    if ! mysql --protocol=socket -uroot -e 'SELECT 1' >/dev/null 2>&1; then
        die "Tidak bisa masuk MySQL sebagai root lewat socket. Pastikan 'sudo mysql' berhasil tanpa sandi."
    fi
    ok "Akses root MySQL lewat socket."

    if ! "$PHP_BIN" -r 'echo function_exists("proc_open") ? "1" : "0";' | grep -q 1; then
        warn "proc_open dinonaktifkan di php.ini CLI; tombol Tes Koneksi/Sinkron butuh proc_open."
    fi

    if ! systemctl list-unit-files --type=service >/dev/null 2>&1; then
        warn "systemd tidak terdeteksi. Bagian layanan akan dilewati."
    fi

    detect_php_fpm_socket
    if [ "$SKIP_NGINX" -eq 0 ] && [ ! -e "$PHP_FPM_SOCKET" ]; then
        warn "Socket PHP-FPM belum ada: $PHP_FPM_SOCKET (pastikan php8.3-fpm berjalan)."
    fi
}

check_prerequisites

if [ "$CHECK_ONLY" -eq 1 ]; then
    log "Pemeriksaan selesai. Tidak ada perubahan yang dilakukan."
    exit 0
fi

# --------------------------------------------------------------------------- #
# 1. Berkas .env
# --------------------------------------------------------------------------- #

log "Menyiapkan berkas .env..."
ENV_FILE="$APP_DIR/.env"

if [ -f "$ENV_FILE" ] && [ "$OVERWRITE_ENV" -eq 0 ]; then
    ok ".env sudah ada, tidak ditimpa."
    DB_PASSWORD="${DB_PASSWORD:-$(env_get DB_PASSWORD "$ENV_FILE")}"
    APP_URL="${APP_URL:-$(env_get APP_URL "$ENV_FILE")}"
else
    [ -f "$APP_DIR/.env.example" ] || die ".env.example tidak ditemukan di $APP_DIR."
    if [ -f "$ENV_FILE" ]; then
        cp -f "$ENV_FILE" "$ENV_FILE.bak.$(date +%Y%m%d%H%M%S)"
        warn ".env lama dicadangkan sebelum dibuat ulang."
    fi
    cp -f "$APP_DIR/.env.example" "$ENV_FILE"
    ok ".env dibuat dari .env.example."
fi

if [ -z "$APP_URL" ]; then
    local_ip=$(hostname -I 2>/dev/null | awk '{print $1}')
    APP_URL="http://${local_ip:-127.0.0.1}"
fi
APP_URL="${APP_URL%/}"

if [ -z "$DB_PASSWORD" ]; then
    DB_PASSWORD=$(generate_secret)
fi

set_env APP_ENV production "$ENV_FILE"
set_env APP_DEBUG false "$ENV_FILE"
set_env APP_URL "$APP_URL" "$ENV_FILE"
set_env DB_CONNECTION mysql "$ENV_FILE"
set_env DB_HOST "$DB_HOST" "$ENV_FILE"
set_env DB_PORT "$DB_PORT" "$ENV_FILE"
set_env DB_DATABASE "$DB_NAME" "$ENV_FILE"
set_env DB_USERNAME "$DB_USER" "$ENV_FILE"
set_env_quoted DB_PASSWORD "$DB_PASSWORD" "$ENV_FILE"
set_env DB_QUEUE_RETRY_AFTER 3720 "$ENV_FILE"
set_env CONNECTOR_PRIMARY_DRIVER python "$ENV_FILE"
set_env CONNECTOR_PYTHON_BIN "$PYTHON_BIN" "$ENV_FILE"
set_env CONNECTOR_LOCAL_API_URL "http://127.0.0.1:4371" "$ENV_FILE"

if [ -z "$(env_get CONNECTOR_LOCAL_API_TOKEN "$ENV_FILE")" ]; then
    set_env_quoted CONNECTOR_LOCAL_API_TOKEN "$(generate_secret)" "$ENV_FILE"
    ok "Token Local API connector dibuat."
fi

chown "$APP_OWNER:$APP_GROUP" "$ENV_FILE" 2>/dev/null || true
chmod 640 "$ENV_FILE" 2>/dev/null || true
ok "Konfigurasi .env siap (APP_URL=$APP_URL)."

# --------------------------------------------------------------------------- #
# 2. Database
# --------------------------------------------------------------------------- #

log "Menyiapkan database MySQL..."
DB_PASS_SQL=$(sql_escape "$DB_PASSWORD")
DB_NAME_SQL=$(sql_escape "$DB_NAME")
DB_USER_SQL=$(sql_escape "$DB_USER")
DB_HOST_SQL=$(sql_escape "$DB_HOST")

mysql --protocol=socket -uroot <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME_SQL}\`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'${DB_HOST_SQL}' IDENTIFIED BY '${DB_PASS_SQL}';
ALTER USER '${DB_USER_SQL}'@'${DB_HOST_SQL}' IDENTIFIED BY '${DB_PASS_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_NAME_SQL}\`.* TO '${DB_USER_SQL}'@'${DB_HOST_SQL}';
FLUSH PRIVILEGES;
SQL
ok "Database '${DB_NAME}' dan user '${DB_USER}' siap."

# --------------------------------------------------------------------------- #
# 3. Dependensi dan aset
# --------------------------------------------------------------------------- #

if [ "$SKIP_COMPOSER" -eq 0 ]; then
    log "Memasang dependensi Composer..."
    (cd "$APP_DIR" && composer install --no-dev --optimize-autoloader --no-interaction)
    ok "Dependensi PHP terpasang."
else
    warn "composer install dilewati."
fi

if [ "$SKIP_BUILD" -eq 0 ]; then
    log "Membangun aset frontend..."
    (cd "$APP_DIR" && npm ci --no-audit --no-fund && npm run build)
    ok "Aset frontend dibangun."
else
    warn "npm ci/npm run build dilewati."
fi

# --------------------------------------------------------------------------- #
# 4. Migrasi, kunci aplikasi, akun admin
# --------------------------------------------------------------------------- #

log "Menyiapkan aplikasi..."
run_as_app "$PHP_BIN" "$APP_DIR/artisan" optimize:clear --no-interaction >/dev/null 2>&1 || true

if [ -z "$(env_get APP_KEY "$ENV_FILE")" ]; then
    run_as_app "$PHP_BIN" "$APP_DIR/artisan" key:generate --force --no-interaction
    ok "APP_KEY dibuat."
else
    ok "APP_KEY sudah ada."
fi

if [ "$SKIP_MIGRATE" -eq 0 ]; then
    run_as_app "$PHP_BIN" "$APP_DIR/artisan" migrate --force --no-interaction
    ok "Migrasi database selesai."
else
    warn "Migrasi dilewati."
fi

if [ "$SKIP_ADMIN" -eq 0 ]; then
    if [ -n "$ADMIN_EMAIL" ] && [ -n "$ADMIN_PASSWORD" ]; then
        run_as_app "$PHP_BIN" "$APP_DIR/artisan" sp:create-admin \
            --name="$ADMIN_NAME" --email="$ADMIN_EMAIL" --password="$ADMIN_PASSWORD" \
            --no-interaction
        ok "Akun administrator '${ADMIN_EMAIL}' siap."
    else
        warn "Akun admin tidak dibuat (isi --admin-email dan --admin-password bila perlu)."
    fi
fi

# --------------------------------------------------------------------------- #
# 5. Izin berkas
# --------------------------------------------------------------------------- #

log "Mengatur kepemilikan dan izin berkas..."
chown -R "$APP_OWNER:$APP_GROUP" "$APP_DIR"
for dir in storage bootstrap/cache connector; do
    [ -d "$APP_DIR/$dir" ] || continue
    find "$APP_DIR/$dir" -type d -exec chmod 2775 {} \;
    find "$APP_DIR/$dir" -type f -exec chmod 664 {} \;
done
if [ -f "$APP_DIR/connector/run_connector.sh" ]; then
    chmod +x "$APP_DIR/connector/run_connector.sh"
fi
ok "Izin storage/, bootstrap/cache/, dan connector/ siap."

# --------------------------------------------------------------------------- #
# 6. Nginx
# --------------------------------------------------------------------------- #

if [ "$SKIP_NGINX" -eq 0 ]; then
    log "Memasang konfigurasi Nginx..."
    NGINX_SITE="/etc/nginx/sites-available/smartpresensi.conf"
    render_nginx "$TEMPLATE_DIR/nginx/smartpresensi.conf" "$NGINX_SITE"
    ln -sf "$NGINX_SITE" /etc/nginx/sites-enabled/smartpresensi.conf

    if [ "$KEEP_DEFAULT_SITE" -eq 0 ] && [ -L /etc/nginx/sites-enabled/default ]; then
        rm -f /etc/nginx/sites-enabled/default
        warn "Site Nginx 'default' dinonaktifkan agar tidak bentrok pada port ${WEB_PORT}."
    fi

    nginx -t
    if ! systemctl reload nginx 2>/dev/null; then
        service nginx reload 2>/dev/null || nginx -s reload
    fi
    ok "Nginx siap pada port ${WEB_PORT} (server_name: ${SERVER_NAME})."
else
    warn "Konfigurasi Nginx dilewati."
fi

# --------------------------------------------------------------------------- #
# 7. Layanan systemd dan scheduler
# --------------------------------------------------------------------------- #

if [ "$SKIP_SERVICES" -eq 0 ] && systemctl list-unit-files --type=service >/dev/null 2>&1; then
    log "Memasang unit systemd..."

    render_template "$TEMPLATE_DIR/systemd/smartpresensi-worker.service" \
        /etc/systemd/system/smartpresensi-worker.service
    ok "Unit smartpresensi-worker terpasang."

    if [ "$SCHEDULER" = "systemd" ]; then
        render_template "$TEMPLATE_DIR/systemd/smartpresensi-scheduler.service" \
            /etc/systemd/system/smartpresensi-scheduler.service
        rm -f /etc/cron.d/smartpresensi-scheduler
        ok "Unit smartpresensi-scheduler terpasang (scheduler=systemd)."
    else
        rm -f /etc/systemd/system/smartpresensi-scheduler.service
        cat > /etc/cron.d/smartpresensi-scheduler <<EOF
SHELL=/bin/sh
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
MAILTO=""
* * * * * ${APP_OWNER} cd ${APP_DIR} && ${PHP_BIN} artisan schedule:run >> ${APP_DIR}/storage/logs/scheduler.log 2>&1
EOF
        chmod 644 /etc/cron.d/smartpresensi-scheduler
        systemctl restart cron 2>/dev/null || systemctl restart crond 2>/dev/null || true
        ok "Cron scheduler terpasang di /etc/cron.d/smartpresensi-scheduler."
    fi

    CONNECTOR_UNIT=/etc/systemd/system/solutionx601-connector.service
    render_template "$TEMPLATE_DIR/systemd/solutionx601-connector.service" "$CONNECTOR_UNIT"
    systemctl daemon-reload
    systemctl enable --now smartpresensi-worker
    ok "Queue worker aktif."

    if [ "$SCHEDULER" = "systemd" ]; then
        systemctl enable --now smartpresensi-scheduler
        ok "Scheduler systemd aktif."
    fi

    if [ "$SKIP_CONNECTOR" -eq 0 ] && [ -f "$APP_DIR/connector/connector_config.ini" ]; then
        systemctl enable --now solutionx601-connector
        ok "Connector Solution X601 aktif."
    else
        warn "Connector belum diaktifkan: unduh connector_config.ini dari halaman Mesin Fingerprint,"
        warn "lalu jalankan: sudo systemctl enable --now solutionx601-connector"
    fi
else
    warn "Unit systemd dilewati."
fi

# --------------------------------------------------------------------------- #
# 8. Optimasi dan preflight
# --------------------------------------------------------------------------- #

log "Mengoptimalkan aplikasi..."
run_as_app "$PHP_BIN" "$APP_DIR/artisan" optimize --no-interaction

if ! run_as_app "$PHP_BIN" "$APP_DIR/artisan" app:preflight --no-interaction; then
    warn "Preflight melaporkan masalah. Periksa output di atas sebelum go-live."
fi

# --------------------------------------------------------------------------- #
# Ringkasan
# --------------------------------------------------------------------------- #

cat <<EOF

==========================================================================
Pemasangan selesai.

  Aplikasi      : $APP_URL
  Folder        : $APP_DIR
  PHP           : $PHP_BIN
  Database      : $DB_NAME (user: $DB_USER)
  Scheduler     : $SCHEDULER
  Pemilik berkas: $APP_OWNER:$APP_GROUP

Langkah berikutnya:
  1. Buka $APP_URL dan login sebagai administrator.
  2. Tambah mesin di menu Mesin Fingerprint, unduh connector_config.ini,
     simpan ke $APP_DIR/connector/, lalu:
       sudo systemctl enable --now solutionx601-connector
  3. Uji koneksi mesin:
       sudo -u $APP_OWNER $PYTHON_BIN $APP_DIR/connector/zkconnector.py --test --ip=<IP_MESIN> --port=4370
  4. Status layanan:
       sudo systemctl status nginx php8.3-fpm smartpresensi-worker solutionx601-connector

Panduan lengkap: docs/PANDUAN_DEPLOYMENT_LINUX.md
==========================================================================
EOF
