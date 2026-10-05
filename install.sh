#!/usr/bin/env bash
# ==============================================================================
# WireGuard VPN Manager - Production Installer
# AlmaLinux 10.2 / WireGuard Standalone Web Application
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

REQUIRED_IFACE="wg0"
REQUIRED_IP="10.50.0.1"
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
echo " WireGuard VPN Manager - Standalone Application Installer"
echo " Target: https://${REQUIRED_IP}:${TLS_PORT}"
echo "=================================================================="

# 1. Verify Root Privileges
if [[ "$(id -u)" -ne 0 ]]; then
    fatal "This installer must be run as root."
fi

# 2. Detect OS (AlmaLinux / RHEL Family)
log_info "Checking operating system..."
if [[ -f /etc/os-release ]]; then
    # shellcheck disable=SC1091
    source /etc/os-release
    log_info "Detected OS: ${NAME:-Unknown} ${VERSION:-}"
    if [[ "${ID:-}" != "almalinux" && "${ID_LIKE:-}" != *"rhel"* && "${ID_LIKE:-}" != *"fedora"* && "${ID:-}" != "rocky" && "${ID:-}" != "centos" ]]; then
        log_warn "System is not identified as AlmaLinux/RHEL. Proceeding with caution."
    else
        log_ok "OS compatibility confirmed."
    fi
else
    log_warn "Cannot read /etc/os-release."
fi

# 3. Detect WireGuard tools
log_info "Checking WireGuard utility..."
if ! command -v wg >/dev/null 2>&1; then
    fatal "WireGuard utility ('wg') is not found or not in PATH. Please ensure WireGuard is installed."
fi
log_ok "WireGuard utility found: $(command -v wg)"

# 4. Check interface wg0 exists
log_info "Checking WireGuard interface '${REQUIRED_IFACE}'..."
if ! ip link show "$REQUIRED_IFACE" >/dev/null 2>&1; then
    fatal "Required interface '${REQUIRED_IFACE}' does not exist. WireGuard VPN must be pre-configured."
fi
log_ok "Interface '${REQUIRED_IFACE}' exists."

# 5. Verify 10.50.0.1 is assigned to wg0
log_info "Verifying IP address '${REQUIRED_IP}' on '${REQUIRED_IFACE}'..."
if ! ip addr show dev "$REQUIRED_IFACE" | grep -qw "$REQUIRED_IP"; then
    fatal "IP address '${REQUIRED_IP}' is NOT assigned to interface '${REQUIRED_IFACE}'. Management application requires this binding."
fi
log_ok "IP '${REQUIRED_IP}' confirmed on '${REQUIRED_IFACE}'."

# 6. Detect PHP CLI and SQLite3 / PDO SQLite support
log_info "Detecting PHP CLI with PDO SQLite support..."
PHP_CANDIDATES=(
    "/usr/bin/php"
    "/usr/local/bin/php"
    "$(command -v php 2>/dev/null || true)"
)

# Also check cPanel ea-php binaries if present
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
    fatal "No suitable PHP CLI with 'pdo_sqlite' extension found. Please install php-pdo and php-sqlite3."
fi

PHP_VER=$("$CHOSEN_PHP" -v | head -n 1)
log_ok "Selected PHP: ${CHOSEN_PHP} (${PHP_VER})"

# 7. Check and install stunnel
log_info "Checking stunnel TLS terminator..."
if ! command -v stunnel >/dev/null 2>&1; then
    log_info "Attempting to install 'stunnel' package..."
    dnf install -y stunnel >/dev/null 2>&1 || true
fi

if ! command -v stunnel >/dev/null 2>&1; then
    fatal "'stunnel' utility not found. Please install stunnel via 'dnf install stunnel'."
fi
log_ok "stunnel utility confirmed: $(command -v stunnel)"

# 8. Check OpenSSL
if ! command -v openssl >/dev/null 2>&1; then
    fatal "'openssl' utility is required for self-signed TLS certificate generation."
fi
log_ok "OpenSSL found: $(command -v openssl)"

# 9. Check and install qrencode
if ! command -v qrencode >/dev/null 2>&1; then
    log_info "Attempting to install 'qrencode' for mobile barcode generation..."
    dnf install -y qrencode >/dev/null 2>&1 || true
fi

if ! command -v qrencode >/dev/null 2>&1; then
    log_warn "'qrencode' binary not found. Install manually via 'dnf install qrencode' to generate QR barcodes."
else
    log_ok "qrencode found: $(command -v qrencode)"
fi

# 10. Check port availability
log_info "Checking port availability for TLS frontend (${REQUIRED_IP}:${TLS_PORT}) and loopback backend (127.0.0.1:${BACKEND_PORT})..."
if command -v ss >/dev/null 2>&1; then
    if ss -lnt | grep -E "(${REQUIRED_IP}|0\.0\.0\.0|::|\*):${TLS_PORT}\b" >/dev/null 2>&1; then
        fatal "Port ${TLS_PORT} is already occupied on this system. Cannot bind TLS frontend."
    fi
    if ss -lnt | grep -E "(127\.0\.0\.1|0\.0\.0\.0|::|\*):${BACKEND_PORT}\b" >/dev/null 2>&1; then
        # Check if the process occupying it is our own existing service
        if ! systemctl is-active --quiet "$SERVICE_NAME" 2>/dev/null; then
            fatal "Port ${BACKEND_PORT} is already occupied on loopback. Cannot bind PHP backend."
        fi
    fi
fi
log_ok "Network ports ${TLS_PORT} and ${BACKEND_PORT} are available."

# 11. Create Dedicated System User & Group
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

# 12. Prepare Directories
log_info "Creating application directories..."
mkdir -p "$APP_DIR"
mkdir -p "$DATA_DIR"
mkdir -p "$DATA_DIR/sessions"
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

# 13. Install Privileged Helper and Password Management Tool
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

# 14. Install Sudoers Configuration
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

# 15. Generate Self-Signed TLS Certificate with SAN for 10.50.0.1
TLS_KEY="${TLS_DIR}/tls.key"
TLS_CERT="${TLS_DIR}/tls.crt"
if [[ ! -f "$TLS_KEY" || ! -f "$TLS_CERT" ]]; then
    log_info "Generating self-signed TLS certificate with Subject Alternative Name (IP:${REQUIRED_IP})..."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "$TLS_KEY" -out "$TLS_CERT" \
        -subj "/CN=${REQUIRED_IP}/O=WireGuard VPN Manager/OU=Admin" \
        -addext "subjectAltName=IP:${REQUIRED_IP}" \
        -addext "basicConstraints=critical,CA:FALSE" \
        -addext "keyUsage=critical,digitalSignature,keyEncipherment" \
        -addext "extendedKeyUsage=serverAuth" >/dev/null 2>&1
    log_ok "Generated self-signed TLS certificate valid for 10 years at ${TLS_CERT}."
else
    log_ok "Existing TLS certificate preserved at ${TLS_CERT}."
fi
chown root:"$APP_GROUP" "$TLS_KEY" "$TLS_CERT"
chmod 0640 "$TLS_KEY" "$TLS_CERT"

# 16. Configure stunnel TLS Terminator
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
accept = ${REQUIRED_IP}:${TLS_PORT}
connect = 127.0.0.1:${BACKEND_PORT}
cert = ${TLS_CERT}
key = ${TLS_KEY}
EOF
chown root:root "$TLS_KEY" "$TLS_CERT" "$STUNNEL_CONF"
chmod 0600 "$TLS_KEY"
chmod 0644 "$TLS_CERT" "$STUNNEL_CONF"
log_ok "stunnel configuration configured."

# 17. Initialize Database and Migrations
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

# Auto-detect Server Public IP for client endpoints
log_info "Detecting server public IP for WireGuard client profiles..."
DETECTED_PUBLIC_IP=$(curl -s -4 --connect-timeout 2 https://api.ipify.org 2>/dev/null || curl -s -4 --connect-timeout 2 https://icanhazip.com 2>/dev/null || ip route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<=NF;i++) if($i=="src") print $(i+1)}' || true)

if [[ -n "$DETECTED_PUBLIC_IP" ]]; then
    log_ok "Detected Public IP: ${DETECTED_PUBLIC_IP}"
    "$CHOSEN_PHP" -r '
        $pdo = new PDO("sqlite:'"${DB_FILE}"'");
        $stmt = $pdo->prepare("UPDATE settings SET value = :ip WHERE key = '\''vpn_endpoint'\'' AND (value = '\'''\'' OR value LIKE '\''%SERVER_PUBLIC_IP%'\'')");
        $stmt->execute(['"':ip'"' => "'"${DETECTED_PUBLIC_IP}"'"]);
    ' 2>/dev/null || true
fi

# Set correct permissions
chown -R "$APP_USER:$APP_GROUP" "$APP_DIR"
chown -R "$APP_USER:$APP_GROUP" "$DATA_DIR"
chown -R "$APP_USER:$APP_GROUP" "$LOG_DIR"
chown -R root:"$APP_GROUP" "$TLS_DIR"
chmod 0750 "$DATA_DIR"
chmod 0700 "$DATA_DIR/sessions"
chmod 0660 "$DB_FILE"

# 18. Administrator Password Configuration
log_info "Checking administrator credential configuration..."
export DATABASE_PATH="${DB_FILE}"

NEED_PASSWORD=1
if "$CHOSEN_PHP" "$PASSWD_BIN" --check >/dev/null 2>&1; then
    NEED_PASSWORD=0
fi

if [[ $NEED_PASSWORD -eq 1 ]]; then
    if [[ -n "${INITIAL_ADMIN_PASSWORD:-}" ]]; then
        log_info "Configuring administrator password from environment variable..."
        "$CHOSEN_PHP" "$PASSWD_BIN" --password "$INITIAL_ADMIN_PASSWORD"
    elif [ -t 0 ] || [ -e /dev/tty ]; then
        echo ""
        log_warn "No administrator password is set in the database."
        log_info "Please choose an administrator password (minimum 12 characters):"

        PASSWORD_SET=0
        while [[ $PASSWORD_SET -eq 0 ]]; do
            P1=""
            P2=""
            if [ -e /dev/tty ]; then
                read -r -s -p "Enter new administrator password: " P1 </dev/tty
                echo ""
                read -r -s -p "Confirm administrator password: " P2 </dev/tty
                echo ""
            else
                read -r -s -p "Enter new administrator password: " P1
                echo ""
                read -r -s -p "Confirm administrator password: " P2
                echo ""
            fi

            if [[ ${#P1} -lt 12 ]]; then
                log_error "Password must be at least 12 characters long. Please try again."
                continue
            fi

            if [[ "$P1" != "$P2" ]]; then
                log_error "Passwords do not match. Please try again."
                continue
            fi

            if "$CHOSEN_PHP" "$PASSWD_BIN" --password "$P1"; then
                PASSWORD_SET=1
                log_ok "Administrator password configured successfully."
            else
                log_error "Failed to set administrator password in database. Please try again."
            fi
        done
    else
        log_warn "Non-interactive installation detected and no administrator password configured."
        log_info "Generating a cryptographically secure random password..."
        "$CHOSEN_PHP" "$PASSWD_BIN" --random
    fi
else
    log_ok "Administrator credentials already configured in database."
fi

# 19. Install Systemd Services
log_info "Installing systemd unit '${SERVICE_NAME}'..."
cat <<EOF > "$SYSTEMD_FILE"
[Unit]
Description=WireGuard VPN Management Web Application
Documentation=https://github.com/manjoor8/WireGaurd-WHM-Extension
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
ReadWritePaths=${DATA_DIR} ${LOG_DIR} ${APP_DIR}/storage

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
Documentation=https://github.com/manjoor8/WireGaurd-WHM-Extension
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

# 20. Enable and Start Services
log_info "Enabling and starting services '${SERVICE_NAME}' and '${TLS_SERVICE_NAME}'..."
systemctl enable "$SERVICE_NAME" "$TLS_SERVICE_NAME"
systemctl restart "$SERVICE_NAME"
systemctl restart "$TLS_SERVICE_NAME"

sleep 2

# 21. Verify Service Status and Network Bindings
log_info "Verifying service status..."
if ! systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1; then
    journalctl -u "$SERVICE_NAME" -n 20 --no-pager
    fatal "Application backend service failed to start! Check logs above."
fi
log_ok "Application backend (${SERVICE_NAME}) is active and running."

if ! systemctl is-active "$TLS_SERVICE_NAME" >/dev/null 2>&1; then
    journalctl -u "$TLS_SERVICE_NAME" -n 20 --no-pager
    fatal "TLS terminator service (${TLS_SERVICE_NAME}) failed to start! Check logs above."
fi
log_ok "TLS terminator (${TLS_SERVICE_NAME}) is active and running."

# Verify Backend Binding (Must be 127.0.0.1:5050 ONLY)
log_info "Verifying PHP backend binding strictly on 127.0.0.1:${BACKEND_PORT}..."
BACKEND_CHECK=$(ss -lntp 2>/dev/null | grep ":${BACKEND_PORT}\b" || true)
if echo "$BACKEND_CHECK" | grep -q "0\.0\.0\.0:${BACKEND_PORT}"; then
    fatal "SECURITY VIOLATION: PHP backend is listening on 0.0.0.0:${BACKEND_PORT}!"
fi
if echo "$BACKEND_CHECK" | grep -q "${REQUIRED_IP}:${BACKEND_PORT}"; then
    fatal "SECURITY VIOLATION: PHP backend is exposed directly without TLS on ${REQUIRED_IP}:${BACKEND_PORT}!"
fi
if ! echo "$BACKEND_CHECK" | grep -q "127\.0\.0\.1:${BACKEND_PORT}"; then
    fatal "PHP backend is NOT listening on 127.0.0.1:${BACKEND_PORT}. Found: ${BACKEND_CHECK}"
fi
log_ok "PHP backend verified strictly on 127.0.0.1:${BACKEND_PORT}."

# Verify TLS Frontend Binding (Must be 10.50.0.1:5443 ONLY)
log_info "Verifying TLS frontend binding strictly on ${REQUIRED_IP}:${TLS_PORT}..."
TLS_CHECK=$(ss -lntp 2>/dev/null | grep ":${TLS_PORT}\b" || true)
if echo "$TLS_CHECK" | grep -q "0\.0\.0\.0:${TLS_PORT}"; then
    fatal "SECURITY VIOLATION: TLS frontend is listening on 0.0.0.0:${TLS_PORT}!"
fi
if ! echo "$TLS_CHECK" | grep -q "${REQUIRED_IP}:${TLS_PORT}"; then
    fatal "TLS frontend is NOT listening on ${REQUIRED_IP}:${TLS_PORT}. Found: ${TLS_CHECK}"
fi
log_ok "TLS frontend verified strictly on ${REQUIRED_IP}:${TLS_PORT}."

# 22. HTTPS Health Check
log_info "Performing HTTPS health check on https://${REQUIRED_IP}:${TLS_PORT}/login.php..."
HTTP_CODE=$(curl -k -s -o /dev/null -w "%{http_code}" "https://${REQUIRED_IP}:${TLS_PORT}/login.php" || true)
if [[ "$HTTP_CODE" == "200" ]]; then
    log_ok "HTTPS health check passed: HTTP 200 OK."
else
    log_warn "Health check returned HTTP code: ${HTTP_CODE}."
    log_warn "Run 'journalctl -u ${TLS_SERVICE_NAME} -n 20' and 'journalctl -u ${SERVICE_NAME} -n 20' for details."
fi

# 23. Synchronize Pre-Existing WireGuard Peers
log_info "Synchronizing existing WireGuard peers from ${REQUIRED_IFACE}..."
"$CHOSEN_PHP" -r '
    require_once "'"${APP_DIR}"'/src/bootstrap.php";
    \WireGuardManager\Database::setPath("'"${DB_FILE}"'");
    $db = \WireGuardManager\Database::getConnection();
    $wg = new \WireGuardManager\WireGuardService("'"${HELPER_BIN}"'");
    $config = new \WireGuardManager\ConfigService($db);
    $cs = new \WireGuardManager\ClientService($db, $wg, $config);
    $count = $cs->syncExistingPeers();
    if ($count > 0) {
        echo "Successfully imported $count existing WireGuard peer(s).\n";
    }
' 2>/dev/null || true
chown "$APP_USER:$APP_GROUP" "$DB_FILE" 2>/dev/null || true
chmod 0660 "$DB_FILE" 2>/dev/null || true

echo "=================================================================="
echo -e "${COLOR_GREEN} WireGuard VPN Manager successfully installed!${COLOR_RESET}"
echo "=================================================================="
echo " Management URL:    https://${REQUIRED_IP}:${TLS_PORT}"
echo " Interface:         ${REQUIRED_IFACE}"
echo " Backend Service:   ${SERVICE_NAME} (127.0.0.1:${BACKEND_PORT})"
echo " TLS Terminator:    ${TLS_SERVICE_NAME} (${REQUIRED_IP}:${TLS_PORT})"
echo " Privileged Helper: ${HELPER_BIN}"
echo " Password Tool:     ${PASSWD_BIN}"
echo " Database:          ${DB_FILE}"
echo ""
echo " Security Notes:"
echo "   - The web manager is strictly accessible over HTTPS at https://${REQUIRED_IP}:${TLS_PORT}."
echo "   - Self-signed TLS certificate is located at ${TLS_CERT}."
echo "   - To reset/change the admin password from CLI: ${PASSWD_BIN}"
echo ""
echo " Verification Commands:"
echo "   1. Check socket bindings: ss -lntp | grep -E ':(5050|5443)'"
echo "   2. From VPN client (10.50.0.2): curl -k https://${REQUIRED_IP}:${TLS_PORT}/"
echo "   3. From Public Internet: curl -k https://PUBLIC_IP:${TLS_PORT}/ (Must FAIL / Connection refused)"
echo "=================================================================="
