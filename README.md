# WireGuard VPN Manager (Standalone Edition)

A secure, lightweight standalone web management application for an existing WireGuard VPN server on AlmaLinux 10.2 / RHEL systems.

> **IMPORTANT ARCHITECTURAL NOTICE:**
> This application is **NOT** a WHM or cPanel plugin. It does not use WHM plugin APIs, cPanel hooks, or Apache reverse proxies. It runs as independent systemd services serving encrypted HTTPS bound exclusively to the private WireGuard VPN interface (`https://10.50.0.1:5443`).

---

## 1. Network Architecture & Security Boundary

```
Internet (Public)
       |
       X  <--- CONNECTION REFUSED / TIMEOUT (No socket listening on public IP)
       |
  [Public IP]
       |
  Linux Server (AlmaLinux 10.2)
       |
       +--- eth0 (Public IP - No management listener)
       |
       +--- wg0 (WireGuard Interface: 10.50.0.1)
                 |
                 +========================================+
                 | WireGuard VPN Network                  |
                 | Subnet: 10.50.0.0/24                   |
                 +========================================+
                 |
        Connected VPN Client
            (10.50.0.2)
                 |
                 v HTTPS GET https://10.50.0.1:5443
          [stunnel TLS Terminator]
          (10.50.0.1:5443 ONLY - Self-Signed Cert with SAN)
                 |
                 v Internal Forward (127.0.0.1:5050 ONLY)
          [WireGuard Manager PHP Backend]
          (Unprivileged user: wireguard-manager)
```

### Security Boundary Model
- **Exclusively Bound Socket & TLS**: `stunnel` terminates TLS bound strictly to `10.50.0.1:5443`. The PHP backend server binds strictly to loopback (`127.0.0.1:5050`). Neither service ever listens on `0.0.0.0`, `*`, `::`, or the public IP.
- **Physical WAN Unreachability**: Because no listener exists on public WAN interfaces, external traffic from the public Internet cannot reach the management portal.
- **Database-Backed Authentication**: All admin credentials are encrypted using standard `PASSWORD_BCRYPT` (cost 12) in SQLite (`admin_auth` table). No plain-text passwords or default hardcoded credentials exist.
- **Brute-Force Rate Limiting**: Failed sign-in attempts are tracked per IP in `login_attempts`. Five failed attempts within 15 minutes trigger an automated 15-minute lockout with exponential sleep timing.
- **CSRF & Security Headers**: Strict CSRF tokens protect all state-modifying requests (including disconnect, kick, revoke, enable, rekey, and password changes). Central security headers include `Content-Security-Policy`, `X-Frame-Options: DENY`, `X-Content-Type-Options: nosniff`, and `Referrer-Policy: strict-origin-when-cross-origin`.
- **Privilege Separation**: The web application runs under an unprivileged system user (`wireguard-manager`). WireGuard operations are executed via a dedicated, strictly validated helper script (`/usr/local/bin/wireguard-manager-helper`) using restricted sudoers rules.
- **Sensitive Key Protection**: Private keys are never logged in application logs, audit logs, or system journals. Helper utilities read private keys strictly via stdin to avoid exposing keys in `/proc/<pid>/cmdline`.

---

## 2. Prerequisites

The server requires:
1. **AlmaLinux 10.2 / 9 / 8** (or compatible RHEL, Rocky, Fedora, Debian, or Ubuntu Linux)
2. **Root privileges** (for running the installer and managing systemd units)
3. **WireGuard**: If not already installed, `install.sh` will automatically install `wireguard-tools`, enable IPv4 kernel forwarding, configure `wg0` with IP `10.50.0.1/24` (listening on UDP `51820`), and enable `wg-quick@wg0`. If WireGuard is already installed, your existing configuration and keys are preserved untouched.
4. **PHP CLI** (>= 8.0) with `pdo_sqlite` support
5. **stunnel** (automatically installed by `install.sh` if missing)
6. **qrencode** (recommended for mobile QR code generation: automatically installed if missing)
7. **OpenSSL** (for self-signed TLS certificate generation)

---

## 3. Installation

Clone or copy the repository onto the server and run `install.sh` as `root`:

```bash
# Clone the repository
git clone https://github.com/manjoor8/WireGaurdManager.git /root/wireguard-manager-src
cd /root/wireguard-manager-src

# Execute installer
chmod +x install.sh uninstall.sh bin/wireguard-manager-helper bin/wireguard-manager-passwd
sudo ./install.sh
```

### What `install.sh` Does:
1. Detects OS and verifies system compatibility.
2. **WireGuard Detection & Setup**: Checks if WireGuard tools are installed. If missing, installs `wireguard-tools` and `iptables`. Enables IPv4 packet forwarding in sysctl. If `wg0` is not yet configured, automatically creates `/etc/wireguard/wg0.conf`, detects the primary egress interface for NAT masquerade, generates server keypair, configures firewall (`firewalld`/`ufw`), and enables `wg-quick@wg0`. If `wg0` already exists, preserves it completely.
3. Verifies that ports `5443` (TLS) and `5050` (backend) are available.
4. Detects PHP CLI and ensures `pdo_sqlite` extension is loaded.
5. Installs `stunnel` and `qrencode` packages if missing.
6. Creates dedicated system user and group `wireguard-manager`.
7. Copies application code to `/opt/wireguard-manager`.
8. Installs the hardened helper to `/usr/local/bin/wireguard-manager-helper` (`0750`, `root:wireguard-manager`).
9. Installs the password management CLI to `/usr/local/bin/wireguard-manager-passwd` (`0750`, `root:wireguard-manager`).
10. Configures sudoers at `/etc/sudoers.d/wireguard-manager` (validating with `visudo -cf`).
11. Generates a self-signed TLS certificate with Subject Alternative Name `IP:10.50.0.1` at `/etc/wireguard-manager/tls.crt`.
12. Configures `stunnel` at `/etc/wireguard-manager/stunnel.conf`.
13. Initializes SQLite database at `/var/lib/wireguard-manager/wireguard.db` and runs automated migrations.
14. Prompts the administrator to securely set an initial password (or generates a random 20-character password in non-interactive environments).
15. Installs, enables, and starts systemd services:
    - `wireguard-manager.service` (PHP backend on `127.0.0.1:5050`)
    - `wireguard-manager-tls.service` (stunnel TLS frontend on `10.50.0.1:5443`)
16. Performs automated socket binding and TLS health checks.

---

## 4. Verification Procedures

### Test 1: Verify Socket Binding Exclusivity
Run the following command on the server:

```bash
ss -lntp | grep -E ':(5050|5443)'
```

**Expected Output:**
```
LISTEN 0 128 127.0.0.1:5050 0.0.0.0:* users:(("php",pid=...,fd=...))
LISTEN 0 128 10.50.0.1:5443 0.0.0.0:* users:(("stunnel",pid=...,fd=...))
```

> **CRITICAL CHECK**: Ensure that `0.0.0.0:5050`, `0.0.0.0:5443`, `:::5050`, or `<PUBLIC_IP>` are **NOT** listed.

---

### Test 2: Access from VPN Client
Connect your client machine to WireGuard (e.g., client IP `10.50.0.2`), then run:

```bash
curl -k -I https://10.50.0.1:5443/login.php
```

**Expected Output:**
```
HTTP/1.1 200 OK
```
Or open `https://10.50.0.1:5443` in your web browser. Accept the self-signed certificate warning to view the secure sign-in page.

---

### Test 3: Access from Server Host
Run locally on the server:

```bash
curl -k -I https://10.50.0.1:5443/login.php
```

**Expected Output:**
```
HTTP/1.1 200 OK
```

---

### Test 4: Access from Public Internet (Negative Test)
From an external computer or phone **disconnected** from the VPN:

```bash
curl -k --connect-timeout 5 https://<SERVER_PUBLIC_IP>:5443
```

**Expected Output:**
```
curl: (7) Failed to connect to <SERVER_PUBLIC_IP> port 5443: Connection refused
# or Timeout (depending on host firewall)
```

---

## 5. Password Management

### Change Password via Web Interface
1. Sign in at `https://10.50.0.1:5443`.
2. Navigate to **Settings** (`/settings.php`).
3. Under **Change Administrator Password**, enter your current password, new password (min. 12 characters), and confirm.
4. Click **Update Password**. All other existing sessions are immediately invalidated.

### Reset Password from Server Terminal
If you forget the password or need to reset it from SSH:

```bash
# Interactive prompt
sudo /usr/local/bin/wireguard-manager-passwd

# Or generate a new random password:
sudo /usr/local/bin/wireguard-manager-passwd --random
```

---

## 6. Configuration Backup & Restore (Export / Import)

You can export and import the entire system configuration directly from the web interface at **Settings -> Backup & Restore Configuration**:

- **Export Configuration**: Downloads a JSON file containing all client profiles (public/private keys, allocated VPN IPs, active/disabled states), profile defaults, and the hashed administrator password.
- **Import Configuration**: Uploads a previously exported JSON backup.
  - Automatically restores all client records and settings in a single transaction.
  - Automatically restores the administrator credentials.
  - Automatically synchronizes the active WireGuard interface (`wg0`), registering active peers immediately without requiring service restarts.

---

## 7. Privileged Helper Architecture

The web process runs under the unprivileged `wireguard-manager` user. To perform WireGuard peer configuration without granting the web process root access, a controlled helper script is used:

```
[Web Process (wireguard-manager)]
              |
              | sudo -n /usr/local/bin/wireguard-manager-helper <action> <args>
              v
[/usr/local/bin/wireguard-manager-helper (root:wireguard-manager 0750)]
              |
              | 1. Strict regex input validation on public keys, IPs, and actions
              | 2. Rejects arbitrary commands / shells
              | 3. Reads sensitive keys strictly via stdin
              | 4. Executes specific WireGuard command
              v
[/usr/bin/wg set wg0 ...]
```

### Allowed Helper Actions:
- `status`: Reads WireGuard interface state (`wg show wg0 dump`).
- `list-peers`: Parses active runtime peers and transfer statistics.
- `peer-info <public-key>`: Retrieves status for a single peer.
- `add-peer <public-key> <allowed-ip>`: Strictly validates base64 key and IP in `10.50.0.2-254`, then adds peer to `wg0`.
- `remove-peer <public-key>`: Validates key and removes peer from `wg0`.
- `traffic <public-key>`: Returns RX/TX counters.
- `handshake <public-key>`: Returns last handshake timestamp.
- `genkey`: Generates Curve25519 private key.
- `pubkey`: Derives public key from private key piped via stdin.

---

## 7. Directory Structure

```
/opt/wireguard-manager/
├── database/
│   └── schema.sql                # SQLite schema (clients, settings, audit_log, admin_auth, login_attempts)
├── public/                       # Web Document Root
│   ├── index.php                 # Dashboard controller
│   ├── login.php                 # Rate-limited authentication
│   ├── logout.php                # POST-only sign out
│   ├── clients.php               # Client list & actions
│   ├── client.php                # Client details, .conf download, kick & QR
│   ├── add-client.php            # Add client form & keygen
│   ├── settings.php              # Network settings & admin password change
│   ├── logs.php                  # Audit log viewer
│   ├── router.php                # Hardened built-in PHP router
│   └── assets/
│       ├── css/
│       │   └── app.css           # Clean, mobile-friendly CSS styling
│       └── js/
│           └── app.js            # Vanilla JS (modals, copy, QR, event delegation)
├── src/                          # Application Services (Private)
│   ├── autoload.php              # Clean autoloader & HTML escaping helper
│   ├── bootstrap.php             # Request guards (headers, Host check, session, CSRF)
│   ├── Security.php              # Security headers, CSRF tokens, Host validation
│   ├── Session.php               # Hardened cookie session management
│   ├── AuthService.php           # DB bcrypt verification, lockout rate limiting
│   ├── Database.php              # SQLite singleton & migrations
│   ├── WireGuardService.php      # Helper bridge & parser
│   ├── ClientService.php         # Client lifecycle & IP allocator
│   ├── ConfigService.php         # Settings management
│   ├── BackupService.php         # Full configuration export and import
│   ├── QRService.php             # In-memory qrencode Data URI generator
│   └── AuditService.php          # Security audit trail
└── templates/                    # HTML UI Templates
    ├── header.php
    ├── footer.php
    ├── login.php
    ├── dashboard.php
    ├── clients.php
    ├── client.php
    ├── add-client.php
    ├── settings.php
    └── logs.php
```

---

## 8. Service Management

To check service statuses:
```bash
systemctl status wireguard-manager.service
systemctl status wireguard-manager-tls.service
```

To view live service logs:
```bash
journalctl -u wireguard-manager.service -f
journalctl -u wireguard-manager-tls.service -f
```

To restart the application:
```bash
systemctl restart wireguard-manager.service wireguard-manager-tls.service
```

---

## 9. Uninstallation

To safely remove the management application without affecting WireGuard, `wg0`, active peers, NAT, or firewalls:

```bash
cd /root/wireguard-manager-src
sudo ./uninstall.sh
```
