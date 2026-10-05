# WireGuard VPN Manager (Standalone Edition)

A secure, lightweight standalone web management appliance for WireGuard VPN servers on AlmaLinux 10.2 / 9 / 8, RHEL, Rocky, Fedora, Debian, and Ubuntu Linux systems.

> **IMPORTANT ARCHITECTURAL NOTICE:**
> This application is a self-contained VPN management appliance. The installer is a **thin bootstrap installer** that installs only our application and launches it. After installation, all system configuration, WireGuard setup, and application updates are managed directly from the **Web UI**.

---

## 1. Network Architecture & Security Boundary

```
Internet (Public)
       |
  [Public IP]
       |
  Linux Server
       |
       +--- eth0 (Public IP / WAN)
       |
       +--- wg0 (WireGuard Interface: 10.50.0.1)
                 |
                 +========================================+
                 | WireGuard VPN Network (10.50.0.0/24)   |
                 +========================================+
                 |
        Connected VPN Client (10.50.0.2)
                 |
                 v HTTPS GET https://10.50.0.1:5443 or https://<SERVER_IP>:5443
          [stunnel TLS Terminator]
          (Port 5443 - Self-Signed Cert with SAN)
                 |
                 v Internal Forward (127.0.0.1:5050 ONLY)
          [WireGuard Manager PHP Backend]
          (Unprivileged user: wireguard-manager)
```

### Security Boundary Model
- **Exclusively Bound Socket & TLS**: `stunnel` terminates TLS on port `5443`. The PHP backend server binds strictly to loopback (`127.0.0.1:5050`). The PHP process never listens directly on public interfaces.
- **Database-Backed Authentication**: All admin credentials are encrypted using standard `PASSWORD_BCRYPT` (cost 12) in SQLite (`admin_auth` table). No plain-text passwords or default hardcoded credentials exist.
- **Brute-Force Rate Limiting**: Failed sign-in attempts are tracked per IP in `login_attempts`. Five failed attempts within 15 minutes trigger an automated 15-minute lockout with exponential sleep timing.
- **CSRF & Security Headers**: Strict CSRF tokens protect all state-modifying requests (including disconnect, kick, revoke, enable, rekey, password changes, and updates). Central security headers include `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin`.
- **Privilege Separation**: The web application runs under an unprivileged system user (`wireguard-manager`). WireGuard operations, package installations, sysctl forwarding, firewall configuration, and application updates are executed via a dedicated, strictly validated helper script (`/usr/local/bin/wireguard-manager-helper`) using restricted sudoers rules. No arbitrary shell commands are permitted.
- **Sensitive Key Protection**: Private keys are never logged in application logs, audit logs, or system journals. Helper utilities read private keys strictly via stdin to avoid exposing keys in `/proc/<pid>/cmdline`.

---

## 2. Prerequisites

The server requires:
1. **AlmaLinux 10.2 / 9 / 8** (or compatible RHEL, Rocky, Fedora, Debian, or Ubuntu Linux)
2. **Root privileges** (for running the thin bootstrap installer)
3. **Internet access** (for package dependencies and web updates)

*Note: WireGuard does NOT need to be installed prior to running the installer. You can install and configure WireGuard directly from the Web UI Setup Wizard.*

---

## 3. Installation (Thin Bootstrap Installer)

Clone or copy the repository onto the server and run `install.sh` as `root`:

```bash
# Clone the repository
git clone https://github.com/manjoor8/WireGaurdManager.git /root/wireguard-manager-src
cd /root/wireguard-manager-src

# Execute thin bootstrap installer
chmod +x install.sh uninstall.sh bin/wireguard-manager-helper bin/wireguard-manager-passwd
sudo ./install.sh
```

### What `install.sh` Does:
1. Detects OS and verifies system compatibility.
2. Checks port availability for TLS frontend (`:5443`) and loopback backend (`127.0.0.1:5050`).
3. Detects PHP CLI (>= 8.0) and ensures `pdo_sqlite` extension is loaded.
4. Installs `stunnel`, `openssl`, and `qrencode` packages if missing.
5. Creates dedicated unprivileged system user and group `wireguard-manager`.
6. Prepares application directories (`/opt/wireguard-manager`, `/var/lib/wireguard-manager`, `/var/log/wireguard-manager`, `/etc/wireguard-manager`).
7. Copies application code to `/opt/wireguard-manager`.
8. Installs the hardened helper to `/usr/local/bin/wireguard-manager-helper` (`0750`, `root:wireguard-manager`).
9. Installs the password management CLI to `/usr/local/bin/wireguard-manager-passwd` (`0750`, `root:wireguard-manager`).
10. Configures sudoers at `/etc/sudoers.d/wireguard-manager` (validating with `visudo -cf`).
11. Generates a self-signed TLS certificate with Subject Alternative Name `IP:10.50.0.1,IP:127.0.0.1,DNS:localhost` at `/etc/wireguard-manager/tls.crt`.
12. Configures `stunnel` at `/etc/wireguard-manager/stunnel.conf`.
13. Initializes SQLite database at `/var/lib/wireguard-manager/wireguard.db`.
14. Installs, enables, and starts systemd services:
    - `wireguard-manager.service` (PHP backend on `127.0.0.1:5050`)
    - `wireguard-manager-tls.service` (stunnel TLS frontend on port `5443`)
15. Displays the Web UI setup URL (`https://<SERVER_IP>:5443/setup.php`).

---

## 4. First-Time Setup Wizard

When you open the Web UI for the first time, the application detects that it has not been initialized and guides you through the **First-Time Setup Wizard**:

### Step 1 – Application Security
- Create your initial administrator password (minimum 12 characters).
- Credentials are hashed securely using bcrypt in SQLite.
- After password creation, authentication is required for all subsequent steps.

### Step 2 – WireGuard Installation
- The system checks whether WireGuard (`wg`) is installed.
- If already installed: displays version and proceeds to Step 3 without reinstalling.
- If not installed: click **[Install WireGuard]** to install `wireguard-tools` and `iptables` directly from the Web UI with live progress indicators.

### Step 3 – WireGuard Configuration
- Configure the VPN tunnel:
  - Interface Name (`wg0`)
  - Server VPN Address (`10.50.0.1`)
  - Subnet (`10.50.0.0/24`)
  - Listen Port (`51820` UDP)
  - Public Endpoint (auto-detects server public IP)
  - Client DNS (`1.1.1.1`)
- Click **[Configure & Start WireGuard VPN]**. The backend generates Curve25519 server keys, writes `/etc/wireguard/wg0.conf`, enables sysctl packet forwarding, opens firewall ports, and starts the service.

### Step 4 – System Status Verification
- Displays verification of all components (Application, Authentication, WireGuard, VPN Interface, VPN Service, Firewall).
- Click **[Go to Dashboard]** to start adding clients.

---

## 5. In-App Application Updates

Application updates are managed entirely from the Web UI under **Settings → Application Updates**:

- **Update Check**: Automatically or on-demand checks for newer releases using semantic versioning comparison (`1.10.0 > 1.9.0`).
- **Release Information**: Displays new version number, release date, and release notes.
- **Update Process**:
  1. Click **[Update Now]** to open confirmation modal.
  2. The application verifies package integrity (SHA-256 checksum).
  3. Creates an automatic pre-update backup in `/var/lib/wireguard-manager/backups/`.
  4. Safely applies new files to `/opt/wireguard-manager/` and restarts the service.
  5. Performs post-update health verification.
  6. If verification fails, automatically rolls back to the previous version.
- **Zero Configuration Loss**: Active WireGuard tunnels, server keys, client peers, firewall rules, and SQLite database data are completely preserved across updates.

---

## 6. System Status & Health

Navigate to **Status** (`/status.php`) in the top navigation to view the operational status of all subsystems:

- **Application**: Version, build number, PHP runtime, host OS.
- **Authentication**: Bcrypt credential status.
- **WireGuard**: Utility path and version.
- **VPN Interface**: Active IP and listen port.
- **VPN Service**: Running state.
- **Firewall & NAT**: UDP port status and IPv4 packet forwarding.
- **Technical Diagnostics**: Raw environment details.

---

## 7. Password Management

### Change Password via Web Interface
1. Sign in to the portal.
2. Navigate to **Settings** (`/settings.php`).
3. Under **Change Administrator Password**, enter your current password and new password (min. 12 characters).
4. Click **Update Password**. All other existing sessions are immediately invalidated.

### Reset Password from Server Terminal
If you forget your password or need emergency recovery via SSH:

```bash
# Interactive prompt
sudo /usr/local/bin/wireguard-manager-passwd

# Or generate a new random password:
sudo /usr/local/bin/wireguard-manager-passwd --random
```

---

## 8. Configuration Backup & Restore (Export / Import)

You can export and import the entire system configuration directly from **Settings → Backup & Restore Configuration**:

- **Export Configuration**: Downloads a JSON file containing all client profiles (public/private keys, allocated VPN IPs, active/disabled states), profile defaults, and the hashed administrator password.
- **Import Configuration**: Restores all clients, settings, and credentials from a JSON backup and synchronizes active peers with `wg0`.

---

## 9. Directory Structure

```
/opt/wireguard-manager/
├── VERSION                       # Authoritative version string (e.g. 1.4.0)
├── database/
│   └── schema.sql                # SQLite schema
├── public/                       # Web Document Root
│   ├── index.php                 # Dashboard controller
│   ├── setup.php                 # First-Time Setup Wizard controller
│   ├── status.php                # Central System Status controller
│   ├── login.php                 # Rate-limited authentication
│   ├── logout.php                # POST-only sign out
│   ├── clients.php               # Client list & actions
│   ├── client.php                # Client details, .conf download, kick & QR
│   ├── add-client.php            # Add client form & keygen
│   ├── settings.php              # Settings, Updates, & About
│   ├── logs.php                  # Audit log viewer
│   ├── router.php                # Hardened built-in PHP router
│   ├── api/                      # Structured JSON endpoints
│   │   ├── setup.php             # Setup lifecycle API
│   │   ├── update.php            # Application update API
│   │   └── health.php            # System health API
│   └── assets/                   # CSS and JS assets
├── src/                          # Application Services (Private)
│   ├── App.php                   # Version, build, runtime, and structured errors
│   ├── SetupService.php          # First-run detection & setup execution
│   ├── HealthService.php         # System health checks
│   ├── SystemService.php         # OS inspection & capability checks
│   ├── WireGuardService.php      # Helper bridge & parser
│   ├── ClientService.php         # Client lifecycle & IP allocator
│   ├── ConfigService.php         # Settings management
│   ├── AuthService.php           # DB bcrypt verification & rate limiting
│   ├── Database.php              # SQLite singleton & migrations
│   ├── BackupService.php         # Full configuration export and import
│   ├── Security.php              # Security headers, CSRF, & Host validation
│   ├── Session.php               # Hardened cookie session management
│   └── Update/                   # In-App Update System
│       ├── IUpdateProvider.php   # Update provider interface
│       ├── JsonUpdateProvider.php# JSON release endpoint provider
│       ├── UpdateInfo.php        # Release metadata DTO
│       ├── UpdateService.php     # Download, backup, apply, & rollback
│       └── SemVer.php            # Semantic version comparator
└── templates/                    # HTML UI Templates
    ├── header.php
    ├── footer.php
    ├── setup.php                 # Setup wizard template
    ├── status.php                # System status template
    ├── login.php
    ├── dashboard.php
    ├── clients.php
    ├── client.php
    ├── add-client.php
    ├── settings.php              # Settings with Updates & About
    └── logs.php
```

---

## 10. Service Management

Check service statuses:
```bash
systemctl status wireguard-manager.service
systemctl status wireguard-manager-tls.service
```

Restart application services:
```bash
systemctl restart wireguard-manager.service wireguard-manager-tls.service
```

View live service logs:
```bash
journalctl -u wireguard-manager.service -f
journalctl -u wireguard-manager-tls.service -f
```

---

## 11. Uninstallation

To safely remove the management application without affecting WireGuard, `wg0`, active peers, NAT, or firewalls:

```bash
cd /root/wireguard-manager-src
sudo ./uninstall.sh
```
