#!/usr/bin/env bash
# ==============================================================================
# WireGuard VPN Manager - Uninstaller
# Safely uninstalls the standalone web application.
# DOES NOT modify or remove WireGuard, wg0, peers, NAT, or firewalls.
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

COLOR_RED="\033[0;31m"
COLOR_GREEN="\033[0;32m"
COLOR_YELLOW="\033[0;33m"
COLOR_RESET="\033[0m"

log_info() { echo -e "[INFO] $*"; }
log_ok()   { echo -e "${COLOR_GREEN}[OK]${COLOR_RESET} $*"; }
log_warn() { echo -e "${COLOR_YELLOW}[WARN]${COLOR_RESET} $*"; }

if [[ "$(id -u)" -ne 0 ]]; then
    echo -e "${COLOR_RED}Error: Must be run as root.${COLOR_RESET}" >&2
    exit 1
fi

echo "=================================================================="
echo " WireGuard VPN Manager - Uninstaller"
echo " (Preserves WireGuard interface wg0, existing peers, and network rules)"
echo "=================================================================="

# 1. Stop and Disable Service
if systemctl is-active --quiet "$SERVICE_NAME" 2>/dev/null; then
    log_info "Stopping ${SERVICE_NAME}..."
    systemctl stop "$SERVICE_NAME"
fi

if systemctl is-enabled --quiet "$SERVICE_NAME" 2>/dev/null; then
    log_info "Disabling ${SERVICE_NAME}..."
    systemctl disable "$SERVICE_NAME"
fi

# 2. Remove Systemd Unit
if [[ -f "$SYSTEMD_FILE" ]]; then
    log_info "Removing systemd service unit..."
    rm -f "$SYSTEMD_FILE"
    systemctl daemon-reload
    log_ok "Removed ${SYSTEMD_FILE}."
fi

# 3. Remove Sudoers File
if [[ -f "$SUDOERS_FILE" ]]; then
    log_info "Removing sudoers configuration..."
    rm -f "$SUDOERS_FILE"
    log_ok "Removed ${SUDOERS_FILE}."
fi

# 4. Remove Helper Binary
if [[ -f "$HELPER_BIN" ]]; then
    log_info "Removing privileged helper..."
    rm -f "$HELPER_BIN"
    log_ok "Removed ${HELPER_BIN}."
fi

# 5. Remove Application Code Directory
if [[ -d "$APP_DIR" ]]; then
    log_info "Removing application files from ${APP_DIR}..."
    rm -rf "$APP_DIR"
    log_ok "Removed ${APP_DIR}."
fi

# 6. Database preservation / removal
if [[ -d "$DATA_DIR" ]]; then
    echo ""
    read -r -p "Do you want to delete the database and client records at ${DATA_DIR}? [y/N]: " CONFIRM_DB || true
    if [[ "${CONFIRM_DB:-n}" =~ ^[Yy]$ ]]; then
        rm -rf "$DATA_DIR"
        log_ok "Removed database directory ${DATA_DIR}."
    else
        log_warn "Preserved database directory at ${DATA_DIR}."
    fi
fi

# 7. Remove Log Directory
if [[ -d "$LOG_DIR" ]]; then
    rm -rf "$LOG_DIR"
    log_ok "Removed ${LOG_DIR}."
fi

# 8. User removal prompt
if id -u "$APP_NAME" >/dev/null 2>&1; then
    read -r -p "Do you want to remove the dedicated system user '${APP_NAME}'? [y/N]: " CONFIRM_USER || true
    if [[ "${CONFIRM_USER:-n}" =~ ^[Yy]$ ]]; then
        userdel "$APP_NAME" 2>/dev/null || true
        log_ok "Removed user ${APP_NAME}."
    else
        log_warn "Preserved user ${APP_NAME}."
    fi
fi

echo ""
echo "=================================================================="
echo -e "${COLOR_GREEN} WireGuard VPN Manager has been successfully removed.${COLOR_RESET}"
echo " Note: WireGuard interface wg0 and active VPN peers are UNTOUCHED."
echo "=================================================================="
