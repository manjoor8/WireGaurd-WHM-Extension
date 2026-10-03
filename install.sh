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
SERVICE_NAME="${APP_NAME}.service"
SYSTEMD_FILE="/etc/systemd/system/${SERVICE_NAME}"
HELPER_BIN="/usr/local/bin/wireguard-manager-helper"
SUDOERS_FILE="/etc/sudoers.d/${APP_NAME}"

REQUIRED_IFACE="wg0"
REQUIRED_IP="10.50.0.1"
BIND_PORT="5050"
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
echo " Target: http://${REQUIRED_IP}:${BIND_PORT}"
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

# 7. Check if port 5050 is already occupied
log_info "Checking if port ${BIND_PORT} is available on ${REQUIRED_IP}..."
if command -v ss >/dev/null 2>&1; then
    if ss -lnt | grep -E "(${REQUIRED_IP}|0\.0\.0\.0|::|\*):${BIND_PORT}\b" >/dev/null 2>&1; then
        fatal "Port ${BIND_PORT} is already occupied on this system. Cannot bind."
    fi
elif command -v netstat >/dev/null 2>&1; then
    if netstat -lnt | grep -E "(${REQUIRED_IP}|0\.0\.0\.0|::|\*):${BIND_PORT}\b" >/dev/null 2>&1; then
        fatal "Port ${BIND_PORT} is already occupied on this system. Cannot bind."
    fi
fi
log_ok "Port ${BIND_PORT} is free."

# 8. Check qrencode
if ! command -v qrencode >/dev/null 2>&1; then
    log_warn "'qrencode' binary not found. QR code generation may be unavailable until 'dnf install qrencode' is run."
else
    log_ok "qrencode found: $(command -v qrencode)"
fi

# 9. Create Dedicated System User & Group
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

# 10. Prepare Directories
log_info "Creating application directories..."
mkdir -p "$APP_DIR"
mkdir -p "$DATA_DIR"
mkdir -p "$DATA_DIR/sessions"
mkdir -p "$LOG_DIR"
mkdir -p "$APP_DIR/storage"

# Copy project files into APP_DIR
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
log_info "Installing application files from ${SCRIPT_DIR} to ${APP_DIR}..."

cp -r "${SCRIPT_DIR}/src" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/templates" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/public" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/database" "$APP_DIR/"
cp -r "${SCRIPT_DIR}/bin" "$APP_DIR/" 2>/dev/null || true

# 11. Install Privileged Helper
log_info "Installing privileged WireGuard helper to ${HELPER_BIN}..."
cp "${SCRIPT_DIR}/bin/wireguard-manager-helper" "$HELPER_BIN"
chown root:"$APP_GROUP" "$HELPER_BIN"
chmod 0750 "$HELPER_BIN"
log_ok "Privileged helper installed with permissions 0750 (root:${APP_GROUP})."

# 12. Install Sudoers Configuration
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

# 13. Initialize Database
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

# Set correct permissions
chown -R "$APP_USER:$APP_GROUP" "$APP_DIR"
chown -R "$APP_USER:$APP_GROUP" "$DATA_DIR"
chown -R "$APP_USER:$APP_GROUP" "$LOG_DIR"
chmod 0750 "$DATA_DIR"
chmod 0700 "$DATA_DIR/sessions"
chmod 0660 "$DB_FILE"

# 14. Install Systemd Service
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
ExecStart=${CHOSEN_PHP} -S ${REQUIRED_IP}:${BIND_PORT} -t ${APP_DIR}/public ${APP_DIR}/public/router.php
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

[Install]
WantedBy=multi-user.target
EOF

chmod 0644 "$SYSTEMD_FILE"
systemctl daemon-reload
log_ok "Systemd unit installed and reloaded."

# 15. Enable and Start Service
log_info "Enabling and starting service '${SERVICE_NAME}'..."
systemctl enable "$SERVICE_NAME"
systemctl restart "$SERVICE_NAME"

sleep 2

# 16. Verify Service Status and Network Binding
log_info "Verifying service status..."
if ! systemctl is-active "$SERVICE_NAME" >/dev/null 2>&1; then
    journalctl -u "$SERVICE_NAME" -n 20 --no-pager
    fatal "Service failed to start! Check logs above."
fi
log_ok "Service is active and running."

# 17. Verify Network Socket Binding
log_info "Verifying network binding exclusively on ${REQUIRED_IP}:${BIND_PORT}..."
BIND_CHECK=$(ss -lntp 2>/dev/null | grep ":${BIND_PORT}\b" || true)

if echo "$BIND_CHECK" | grep -q "0\.0\.0\.0:${BIND_PORT}"; then
    fatal "SECURITY VIOLATION: Application is listening on 0.0.0.0:${BIND_PORT}!"
fi
if echo "$BIND_CHECK" | grep -q ":::${BIND_PORT}"; then
    fatal "SECURITY VIOLATION: Application is listening on IPv6 wildcard :::${BIND_PORT}!"
fi
if ! echo "$BIND_CHECK" | grep -q "${REQUIRED_IP}:${BIND_PORT}"; then
    fatal "Application is NOT listening on ${REQUIRED_IP}:${BIND_PORT}. Found: ${BIND_CHECK}"
fi
log_ok "Binding verified strictly on ${REQUIRED_IP}:${BIND_PORT}."

# 18. Local Loopback / Self Health Check
log_info "Performing HTTP health check on http://${REQUIRED_IP}:${BIND_PORT}..."
HTTP_CODE=$(curl -s -o /dev/null -w "%{http_code}" "http://${REQUIRED_IP}:${BIND_PORT}/" || true)
if [[ "$HTTP_CODE" == "200" ]]; then
    log_ok "Health check passed: HTTP 200 OK."
else
    log_warn "Health check returned HTTP code: ${HTTP_CODE}. Service may still be initializing."
fi

echo "=================================================================="
echo -e "${COLOR_GREEN} WireGuard VPN Manager successfully installed!${COLOR_RESET}"
echo "=================================================================="
echo " Management URL:    http://${REQUIRED_IP}:${BIND_PORT}"
echo " Interface:         ${REQUIRED_IFACE}"
echo " Service Name:      ${SERVICE_NAME}"
echo " Privileged Helper: ${HELPER_BIN}"
echo " Database:          ${DB_FILE}"
echo ""
echo " Verification Commands:"
echo "   1. Check socket binding: ss -lntp | grep ${BIND_PORT}"
echo "   2. From VPN client (10.50.0.2): curl http://${REQUIRED_IP}:${BIND_PORT}"
echo "   3. From Public Internet: curl http://PUBLIC_IP:${BIND_PORT} (Must FAIL / Timeout)"
echo "=================================================================="
