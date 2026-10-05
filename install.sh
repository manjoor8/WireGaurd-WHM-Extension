#!/usr/bin/env bash
# ==============================================================================
# WireGuard VPN Manager - Thin Bootstrap Installer
# AlmaLinux 10.2 / RHEL / CentOS / Rocky / Fedora / Debian / Ubuntu
# ==============================================================================
#
# Core Design Principle:
# The installer performs only the minimum work required to install and launch
# our application. All WireGuard installation, configuration, administrator
# password setup, and lifecycle operations are handled inside the Web UI.
#
# ==============================================================================

set -euo pipefail

APP_NAME="wireguard-manager"
APP_DIR="/opt/${APP_NAME}"
DATA_DIR="/var/lib/${APP_NAME}"
LOG_DIR="/var/log/${APP_NAME}"
TLS_DIR="/etc/${APP_NAME}"

SERVICE_NAME="${APP_NAME}.service"
TLS_SERVICE_NAME="${APP_NAME}-tls.service"
SYSTEMD_FILE="/etc/systemd/system/${SERVICE_NAME}"
TLS_SYSTEMD_FILE="/etc/systemd/system/${TLS_SERVICE_NAME}"

HELPER_BIN="/usr/local/bin/wireguard-manager-helper"
PASSWD_BIN="/usr/local/bin/wireguard-manager-passwd"
SUDOERS_FILE="/etc/sudoers.d/${APP_NAME}"

TLS_PORT="5443"
BACKEND_PORT="5050"

APP_USER="${APP_NAME}"
APP_GROUP="${APP_NAME}"

COLOR_RED="\033[0;31m"
COLOR_GREEN="\033[0;32m"
COLOR_YELLOW="\033[0;33m"
COLOR_BLUE="\033[0;34m"
COLOR_RESET="\033[0m"

log_info()  { echo -e "${COLOR_BLUE}[INFO]${COLOR_RESET} $*"; }
log_ok()    { echo -e "${COLOR_GREEN}[OK]${COLOR_RESET} $*"; }
log_warn()  { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }
log_error() { echo -e "${COLOR_RED}[ERROR]${COLOR_RESET} $*"; }
fatal()     { log_error "$*"; echo -e "${COLOR_RED}Installation aborted.${COLOR_RESET}"; exit 1; }

echo "=================================================================="
echo " WireGuard VPN Manager - Thin Application Bootstrap Installer"
echo "=================================================================="

# 1. Verify Root Privileges
if [[ "$(id -u)" -ne 0 ]]; then
    fatal "This installer must be run as root."
fi

# 2. Detect OS (AlmaLinux / RHEL / Debian Family)
log_info "Checking operating system..."
OS_NAME="Linux"
if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    OS_NAME="${PRETTY_NAME:-$NAME}"
    log_info "Detected OS: ${OS_NAME}"
else
    log_warn "Cannot read /etc/os-release. Proceeding with standard Linux defaults."
fi

# 3. Detect or Install Required Application Dependencies (PHP CLI, SQLite PDO, stunnel, OpenSSL, qrencode)
log_info "Detecting PHP CLI with PDO SQLite support..."
PHP_CANDIDATES=(
    "/usr/bin/php"
    "/usr/local/bin/php"
    "$(command -v php 2>/dev/null || true)"
)

for ea_bin in /opt/cpanel/ea-php*/root/usr/bin/php; do
    if [[ -x "$ea_bin" ]]; then
        PHP_CANDIDATES+=("$ea_bin")
    fi
done

CHOSEN_PHP=""
for php_path in "${PHP_CANDIDATES[@]}"; do
    [[ -z "$php_path" || ! -x "$php_path" ]] && continue
    if "$php_path" -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" 2>/dev/null; then
        CHOSEN_PHP="$php_path"
        break
    fi
done

if [[ -z "$CHOSEN_PHP" ]]; then
    log_info "Suitable PHP CLI not found. Attempting automatic installation of php-cli and php-pdo..."
    if command -v dnf >/dev/null 2>&1; then
        dnf install -y epel-release >/dev/null 2>&1 || true
        dnf install -y php-cli php-pdo php-json php-mbstring >/dev/null 2>&1 || true
    elif command -v yum >/dev/null 2>&1; then
        yum install -y epel-release >/dev/null 2>&1 || true
        yum install -y php-cli php-pdo php-json php-mbstring >/dev/null 2>&1 || true
    elif command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get update -y >/dev/null 2>&1 || true
        DEBIAN_FRONTEND=noninteractive apt-get install -y php-cli php-sqlite3 php-mbstring php-curl >/dev/null 2>&1 || true
    fi

    # Recheck after installation
    for php_path in /usr/bin/php /usr/local/bin/php; do
        if [[ -x "$php_path" ]] && "$php_path" -r "exit(extension_loaded('pdo_sqlite') ? 0 : 1);" 2>/dev/null; then
            CHOSEN_PHP="$php_path"
            break
        fi
    done
fi

if [[ -z "$CHOSEN_PHP" ]]; then
    fatal "PHP CLI (>= 8.0) with 'pdo_sqlite' extension is required. Please install php-cli and php-sqlite3."
fi

PHP_VER=$("$CHOSEN_PHP" -v | head -n 1)
log_ok "Selected PHP: ${CHOSEN_PHP} (${PHP_VER})"

# 4. Check stunnel, OpenSSL, and qrencode
log_info "Checking stunnel TLS terminator..."
if ! command -v stunnel >/dev/null 2>&1; then
    log_info "Attempting to install 'stunnel' package..."
    if command -v dnf >/dev/null 2>&1; then
        dnf install -y stunnel >/dev/null 2>&1 || true
    elif command -v yum >/dev/null 2>&1; then
        yum install -y stunnel >/dev/null 2>&1 || true
    elif command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y stunnel4 >/dev/null 2>&1 || true
    fi
fi

if ! command -v stunnel >/dev/null 2>&1; then
    fatal "'stunnel' utility not found. Please install stunnel via your package manager."
fi
log_ok "stunnel utility confirmed: $(command -v stunnel)"

if ! command -v openssl >/dev/null 2>&1; then
    fatal "'openssl' utility is required for self-signed TLS certificate generation."
fi
log_ok "OpenSSL found: $(command -v openssl)"

if ! command -v qrencode >/dev/null 2>&1; then
    log_info "Attempting to install 'qrencode'..."
    if command -v dnf >/dev/null 2>&1; then
        dnf install -y qrencode >/dev/null 2>&1 || true
    elif command -v yum >/dev/null 2>&1; then
        yum install -y qrencode >/dev/null 2>&1 || true
    elif command -v apt-get >/dev/null 2>&1; then
        DEBIAN_FRONTEND=noninteractive apt-get install -y qrencode >/dev/null 2>&1 || true
    fi
fi
if command -v qrencode >/dev/null 2>&1; then
    log_ok "qrencode found: $(command -v qrencode)"
else
    log_warn "qrencode binary not found. Mobile QR barcodes will be generated via fallback."
fi

# 5. Check port availability
log_info "Checking port availability for TLS frontend (:${TLS_PORT}) and loopback backend (127.0.0.1:${BACKEND_PORT})..."
if command -v ss >/dev/null 2>&1; then
    if ss -lnt | grep -E "(:${TLS_PORT}\b)" >/dev/null 2>&1; then
        if ! systemctl is-active --quiet "$TLS_SERVICE_NAME" 2>/dev/null; then
            fatal "Port ${TLS_PORT} is already occupied on this system. Cannot bind TLS frontend."
        fi
    fi
    if ss -lnt | grep -E "(127\.0\.0\.1|0\.0\.0\.0|::|\*):${BACKEND_PORT}\b" >/dev/null 2>&1; then
        if ! systemctl is-active --quiet "$SERVICE_NAME" 2>/dev/null; then
            fatal "Port ${BACKEND_PORT} is already occupied on loopback. Cannot bind PHP backend."
        fi
    fi
fi
log_ok "Network ports ${TLS_PORT} and ${BACKEND_PORT} are available."

# 6. Create Dedicated System User & Group
log_info "Configuring system user '${APP_USER}'..."
if ! getent group "$APP_GROUP" >/dev/null 2>&1; then
    groupadd -r "$APP_GROUP"
    log_ok "Created group ${APP_GROUP}."
fi

if ! getent passwd "$APP_USER" >/dev/null 2>&1; then
    useradd -r -g "$APP_GROUP" -d "$APP_DIR" -s /sbin/nologin -c "WireGuard Manager Service" "$APP_USER"
    log_ok "Created system user ${APP_USER}."
else
    log_ok "System user ${APP_USER} already exists."
fi

# 7. Prepare Application Directories
log_info "Creating application directories..."
mkdir -p "$APP_DIR"
mkdir -p "$DATA_DIR"
mkdir -p "$DATA_DIR/sessions"
mkdir -p "$DATA_DIR/updates"
mkdir -p "$DATA_DIR/backups"
mkdir -p "$LOG_DIR"
mkdir -p "$TLS_DIR"
mkdir -p "$APP_DIR/storage"

# Copy project files into APP_DIR
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
log_info "Installing application files from ${SCRIPT_DIR} to ${APP_DIR}..."

cp -r "${SCRIPT_DIR}/src" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/templates" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/public" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/database" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/bin" "$APP_DIR/" 2>/dev/null || true
cp "${SCRIPT_DIR}/VERSION" "$APP_DIR/" 2>/dev/null || echo "1.4.0" > "$APP_DIR/VERSION"

# 8. Install Privileged Helper and Password Management Tool
log_info "Installing privileged WireGuard helper to ${HELPER_BIN}..."
cp "${SCRIPT_DIR}/bin/wireguard-manager-helper" "$HELPER_BIN"
chown root:"$APP_GROUP" "$HELPER_BIN"
chmod 0750 "$HELPER_BIN"
log_ok "Privileged helper installed with permissions 0750 (root:${APP_GROUP})."

log_info "Installing password utility to ${PASSWD_BIN}..."
cp "${SCRIPT_DIR}/bin/wireguard-manager-passwd" "$PASSWD_BIN"
chown root:"$APP_GROUP" "$PASSWD_BIN"
chmod 0750 "$PASSWD_BIN"
log_ok "Password utility installed with permissions 0750 (root:${APP_GROUP})."

# 9. Install Sudoers Configuration
log_info "Configuring sudoers permissions..."
cat <<EOF > "$SUDOERS_FILE"
# /etc/sudoers.d/${APP_NAME}
# Dedicated privileges for ${APP_NAME} to run the hardened WireGuard helper
${APP_USER} ALL=(root) NOPASSWD: ${HELPER_BIN}
${APP_USER} ALL=(root) NOPASSWD: ${HELPER_BIN} *
EOF
chmod 0440 "$SUDOERS_FILE"
if visudo -cf "$SUDOERS_FILE" >/dev/null 2>&1; then
    log_ok "Sudoers rule validated successfully."
else
    rm -f "$SUDOERS_FILE"
    fatal "Sudoers syntax verification failed. Rule removed."
fi

# 10. Generate Self-Signed TLS Certificate
TLS_KEY="${TLS_DIR}/tls.key"
TLS_CERT="${TLS_DIR}/tls.crt"
if [[ ! -f "$TLS_KEY" || ! -f "$TLS_CERT" ]]; then
    log_info "Generating self-signed TLS certificate..."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$TLS_KEY" -out "$TLS_CERT" \
        -subj "/CN=WireGuard VPN Manager/O=WireGuard VPN Manager/OU=Admin" \
        -addext "subjectAltName=IP:10.50.0.1,IP:127.0.0.1,DNS:localhost" \
        -addext "basicConstraints=critical,CA:FALSE" \
        -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
        -addext "extendedKeyUsage=serverAuth" >/dev/null 2>&1
    log_ok "Generated self-signed TLS certificate at ${TLS_CERT}."
else
    log_ok "Existing TLS certificate preserved at ${TLS_CERT}."
fi
chown root:"$APP_GROUP" "$TLS_KEY" "$TLS_CERT"
chmod 0640 "$TLS_KEY" "$TLS_CERT"

# 11. Configure stunnel TLS Terminator
STUNNEL_CONF="${TLS_DIR}/stunnel.conf"
log_info "Configuring stunnel configuration at ${STUNNEL_CONF}..."
cat <<EOF > "$STUNNEL_CONF"
# /etc/wireguard-manager/stunnel.conf
# TLS Terminator for WireGuard Web Manager
foreground = yes
pid = /run/wireguard-manager-stunnel.pid
syslog = yes

[wireguard-manager-tls]
client = no
accept = ${TLS_PORT}
connect = 127.0.0.1:${BACKEND_PORT}
cert = ${TLS_CERT}
key = ${TLS_KEY}
EOF
chown root:root "$TLS_KEY" "$TLS_CERT" "$STUNNEL_CONF"
chmod 0600 "$TLS_KEY"
chmod 0644 "$TLS_CERT" "$STUNNEL_CONF"
log_ok "stunnel configuration configured (listening on port ${TLS_PORT})."

# 12. Initialize Database and Migrations
log_info "Initializing SQLite database at ${DATA_DIR}/wireguard.db..."
DB_FILE="${DATA_DIR}/wireguard.db"
if [[ ! -f "$DB_FILE" ]]; then
    touch "$DB_FILE"
    if command -v sqlite3 >/dev/null 2>&1; then
        sqlite3 "$DB_FILE" < "${APP_DIR}/database/schema.sql"
    else
        "$CHOSEN_PHP" -r '
            $pdo = new PDO("sqlite:'"${DB_FILE}"'");
            $sql = file_get_contents("'"${APP_DIR}"'/database/schema.sql");
            $pdo->exec($sql);
        '
    fi
    log_ok "Database schema initialized."
else
    log_ok "Existing database preserved."
fi

# Ensure schema migrations run
"$CHOSEN_PHP" -r '
    require_once "'"${APP_DIR}"'/src/autoload.php";
    \WireGuardManager\Database::setPath("'"${DB_FILE}"'");
    \WireGuardManager\Database::getConnection();
' 2>/dev/null || true

# Set correct permissions
chown -R "$APP_USER:$APP_GROUP" "$APP_DIR"
chown -R "$APP_USER:$APP_GROUP" "$DATA_DIR"
chown -R "$APP_USER:$APP_GROUP" "$LOG_DIR"
chown -R root:"$APP_GROUP" "$TLS_DIR"
chmod 0750 "$DATA_DIR"
chmod 0700 "$DATA_DIR/sessions"
chmod 0660 "$DB_FILE"

# 13. Install Systemd Services
log_info "Installing systemd unit '${SERVICE_NAME}'..."
cat <<EOF > "$SYSTEMD_FILE"
[Unit]
Description=WireGuard VPN Management Web Application
Documentation=https://github.com/manjoor8/WireGaurdManager
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${APP_USER}
Group=${APP_GROUP}
WorkingDirectory=${APP_DIR}
ExecStart=${CHOSEN_PHP} -S 127.0.0.1:${BACKEND_PORT} -t ${APP_DIR}/public ${APP_DIR}/public/router.php
Restart=on-failure
RestartSec=3s

# Security Hardening
NoNewPrivileges=false
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=${DATA_DIR} ${LOG_DIR} ${APP_DIR}

Environment=APP_ENV=production
Environment=DATABASE_PATH=${DB_FILE}
Environment=HELPER_BIN=${HELPER_BIN}
Environment=SESSION_DIR=${DATA_DIR}/sessions
Environment=WGM_TLS=1
Environment=PHP_CLI_SERVER_WORKERS=4

[Install]
WantedBy=multi-user.target
EOF
chmod 0644 "$SYSTEMD_FILE"

log_info "Installing systemd unit '${TLS_SERVICE_NAME}'..."
cat <<EOF > "$TLS_SYSTEMD_FILE"
[Unit]
Description=WireGuard VPN Manager TLS Terminator (stunnel)
Documentation=https://github.com/manjoor8/WireGaurdManager
After=network-online.target ${SERVICE_NAME}
Wants=network-online.target

[Service]
Type=simple
User=root
Group=root
ExecStart=/usr/bin/stunnel ${STUNNEL_CONF}
Restart=on-failure
RestartSec=3s
PrivateTmp=true

[Install]
WantedBy=multi-user.target
EOF
chmod 0644 "$TLS_SYSTEMD_FILE"

systemctl daemon-reload
log_ok "Systemd units installed and daemon reloaded."

# 14. Enable and Start Services
log_info "Enabling and starting services '${SERVICE_NAME}' and '${TLS_SERVICE_NAME}'..."
systemctl enable "$SERVICE_NAME" "$TLS_SERVICE_NAME" >/dev/null 2>&1 || true
systemctl restart "$SERVICE_NAME"
systemctl restart "$TLS_SERVICE_NAME"

sleep 2

# 15. Verify Services
log_info "Verifying service status..."
if ! systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1; then
    journalctl -u "$SERVICE_NAME" -n 20 --no-pager
    fatal "Application backend service failed to start! Check logs above."
fi
log_ok "Application backend (${SERVICE_NAME}) is running."

if ! systemctl is-active "$TLS_SERVICE_NAME" >/dev/null 2>&1; then
    journalctl -u "$TLS_SERVICE_NAME" -n 20 --no-pager
    fatal "TLS terminator service (${TLS_SERVICE_NAME}) failed to start! Check logs above."
fi
log_ok "TLS terminator (${TLS_SERVICE_NAME}) is running."

# 16. Detect Host / Public IP for the Web UI link
SERVER_IP=$(curl -s -4 --connect-timeout 2 https://api.ipify.org 2>/dev/null || curl -s -4 --connect-timeout 2 https://icanhazip.com 2>/dev/null || ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' || hostname -I 2>/dev/null | awk '{print $1}' || echo "127.0.0.1")

echo "=================================================================="
echo -e "${COLOR_GREEN} WireGuard VPN Manager Application Installed!${COLOR_RESET}"
echo "=================================================================="
echo " Web UI Setup URL:  https://${SERVER_IP}:${TLS_PORT}/setup.php"
echo " Local URL:         https://127.0.0.1:${TLS_PORT}/setup.php"
echo " Backend Service:   ${SERVICE_NAME} (127.0.0.1:${BACKEND_PORT})"
echo " TLS Terminator:    ${TLS_SERVICE_NAME} (Port ${TLS_PORT})"
echo " Privileged Helper: ${HELPER_BIN}"
echo " Database:          ${DB_FILE}"
echo ""
echo " Next Steps:"
echo "   1. Open the Web UI in your browser:"
echo "      https://${SERVER_IP}:${TLS_PORT}/"
echo "   2. Follow the First-Time Setup Wizard to:"
echo "      - Create your Administrator Password"
echo "      - Install WireGuard from the Web UI"
echo "      - Configure and Start your VPN Tunnel"
echo "=================================================================="
