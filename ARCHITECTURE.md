# Architecture and Security Specification

## 1. Executive Summary

This document describes the architectural design, lifecycle, and security posture of the standalone **WireGuard VPN Manager** on AlmaLinux 10.2 / RHEL / CentOS / Rocky / Debian / Ubuntu systems.

The application functions completely independently of cPanel, WHM, and Apache. It features a **thin bootstrap installer** that installs only the management application runtime and starts the services. After installation, all system configuration, WireGuard setup, VPN interface provisioning, firewall management, and application updates are handled entirely through the **application's Web UI**.

---

## 2. In-App Lifecycle & Bootstrap Installer Architecture

```
                  THIN BOOTSTRAP INSTALLER
                             |
                             v
               +---------------------------+
               | 1. Install App Files      |
               | 2. Create System User     |
               | 3. Register Systemd Units |
               | 4. Start Web Application  |
               +---------------------------+
                             |
                             v
                 Web UI Setup URL Provided
                             |
                             v
                  FIRST-TIME SETUP WIZARD
                             |
        +--------------------+--------------------+
        |                    |                    |
        v                    v                    v
  Step 1: Security     Step 2: Install      Step 3: Configure
 (Admin Password)         WireGuard             & Start VPN
        |                    |                    |
        +--------------------+--------------------+
                             |
                             v
                    NORMAL DASHBOARD
                             |
        +--------------------+--------------------+
        |                    |                    |
        v                    v                    v
   System Status        Peer Lifecycle       Settings: Updates
  & Health Service       Management         (Check/Update/Rollback)
```

---

## 3. Security Boundaries & Threat Modeling

### 3.1 Interface Isolation & Dual-Socket Model
- The application separates TLS termination from application runtime:
  1. **TLS Terminator (`stunnel`)**:
     - Listens on `5443` over encrypted TLS 1.2 / 1.3 with a 2048-bit RSA self-signed certificate containing Subject Alternative Name `IP:10.50.0.1,IP:127.0.0.1,DNS:localhost`.
     - Terminates TLS encryption before forwarding plain HTTP traffic strictly to loopback (`127.0.0.1:5050`).
  2. **Application Backend (`php -S`)**:
     - Binds strictly to `127.0.0.1:5050` (IPv4 loopback only).
     - Never accepts connections from remote IPs or external interfaces directly.
     - Spawns concurrent workers via `PHP_CLI_SERVER_WORKERS=4`.

### 3.2 Database-Backed Authentication & Lockout Protection
- Administrator credentials are stored as a bcrypt hash (`PASSWORD_BCRYPT` with cost 12) in SQLite (`admin_auth` table).
- Passwords must be at least 12 characters and max 72 bytes.
- Passwords are never stored in plaintext, never logged, and never returned in API responses.
- **Brute-Force Rate Limiting**:
  - Failed login attempts are recorded in `login_attempts` with client IP and timestamps.
  - 5 failed attempts within 15 minutes trigger a 15-minute IP lockout.
  - Artificial sleep delays (`usleep(300000)`) dampen online timing attacks.
  - Verification uses `password_verify` and constant-time dummy comparisons when users or hashes are missing.

### 3.3 Session Hardening & Invalidation
- Dedicated session cookie `WGMSESSID` with flags:
  - `HttpOnly`: Prevents JavaScript reading the cookie.
  - `SameSite=Strict`: Protects against cross-site request forgery.
  - `Secure`: Enabled automatically when TLS is active (`WGM_TLS=1`).
- Rolling inactivity timeout of 30 minutes; absolute session ceiling of 12 hours.
- Session IDs are regenerated upon successful authentication.
- A cryptographic credential fingerprint (`hash('sha256', password_hash)`) is bound to the session: changing the admin password automatically invalidates all other concurrent active sessions.

### 3.4 CSRF & Defense-in-Depth HTTP Headers
- Every POST request must supply a valid `csrf_token` validated via `hash_equals()`.
- Central HTTP headers emitted on every response:
  - `Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none';`
  - `X-Frame-Options: DENY` (anti-clickjacking)
  - `X-Content-Type-Options: nosniff` (MIME sniffing defense)
  - `Referrer-Policy: strict-origin-when-cross-origin`
- Host header enforcement: `Security::enforceHost()` strictly rejects foreign domain headers to prevent DNS rebinding attacks while permitting direct IP access.

### 3.5 Privilege Separation & Controlled Helper
- The web server process runs as user `wireguard-manager` (unprivileged system user without login shell).
- WireGuard peer management (`wg set wg0 ...`), package installation, sysctl packet forwarding, firewall rules, and application updates require elevated root privileges.
- Rather than running PHP as root, the application delegates privileged operations to `/usr/local/bin/wireguard-manager-helper`.
- The helper script is:
  - Owned by `root:wireguard-manager` with mode `0750`.
  - Configured in `/etc/sudoers.d/wireguard-manager` with `NOPASSWD:` execution for only that specific path.
  - Hardened with strict regex validation for all parameters:
    - Public Keys: `^[A-Za-z0-9+/]{43}=$`
    - Allowed IPs: `^10\.50\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$`
    - Interfaces: `^[a-zA-Z0-9_-]{1,15}$`
    - Ports: `^[0-9]{2,5}$`
    - Actions: `check-privileges`, `check-wireguard`, `install-wireguard`, `check-sysctl`, `enable-sysctl`, `check-interface`, `create-interface`, `start-interface`, `stop-interface`, `restart-interface`, `configure-firewall`, `check-firewall`, `apply-update`, `rollback-update`, `status`, `dump`, `list-peers`, `peer-info`, `add-peer`, `remove-peer`, `traffic`, `handshake`, `genkey`, `pubkey`.
  - Sensitive private keys are read strictly through standard input (stdin) rather than command-line arguments to prevent leakage in process tables (`/proc/<pid>/cmdline`).
  - No generic shell execution endpoint (`ExecuteCommand`) exists.

### 3.6 Sensitive Key Protection
- Curve25519 private keys generated for client configuration are stored in SQLite database with file permissions `0660` inside `/var/lib/wireguard-manager/` (directory mode `0750`).
- The database is outside the web document root (`/opt/wireguard-manager/public`).
- Private keys are **never** logged to audit trails, debug output, or syslog.
- When a client is revoked, its private key is set to `NULL` permanently.

---

## 4. Application Component Architecture

```
+----------------------------------------------------------------------------------+
|                                  Linux Server                                    |
|                                                                                  |
|  +-----------------------------+         +------------------------------------+  |
|  |   stunnel TLS Terminator    |         |       WireGuard Kernel/Tool        |  |
|  |   (wireguard-manager-tls)   |         |               (wg0)                |  |
|  |     (Port 5443 HTTPS)       |         +-----------------+------------------+  |
|  +--------------+--------------+                           ^                     |
|                 | (Forwarded loopback traffic)             |                     |
|                 v                                          |                     |
|  +-----------------------------+                           |                     |
|  |   PHP Built-in Server       |                           |                     |
|  |  (wireguard-manager.service)|                           |                     |
|  |      (127.0.0.1:5050)       |                           |                     |
|  |   (User: wireguard-manager) |                           |                     |
|  +--------------+--------------+                           |                     |
|                 |                                          |                     |
|                 v                                          |                     |
|  +-----------------------------+         sudo -n           |                     |
|  |   Privileged Helper Script  | --------------------------+                     |
|  | (wireguard-manager-helper)  |  (strictly validated parameters, stdin keys)    |
|  +--------------+--------------+                                                 |
|                 |                                                                |
|                 v                                                                |
|  +-----------------------------+                                                 |
|  |     SQLite Database File    |                                                 |
|  |  (/var/lib/.../wireguard.db)|                                                 |
|  |   (clients, admin_auth,     |                                                 |
|  |    login_attempts, audit)   |                                                 |
|  +-----------------------------+                                                 |
+----------------------------------------------------------------------------------+
```

---

## 5. In-App Update System Architecture

```
[Web UI (Settings -> Updates)]
             |
             v
   [UpdateService]  <--- IUpdateProvider (e.g. JsonUpdateProvider)
             |
             +---> 1. Check for Update (SemVer Comparison e.g. 1.10.0 > 1.9.0)
             |
             +---> 2. Download Package & Verify SHA-256 Checksum
             |
             +---> 3. Create Pre-Update Backup (/var/lib/.../backups/)
             |
             +---> 4. Delegate to Privileged Helper (apply-update <pkg>)
             |           |
             |           v
             |        Extract safely to /opt/wireguard-manager
             |        Restart wireguard-manager.service
             |
             +---> 5. Post-Update Health Verification (verifyHealth)
             |
             +---> 6. Automatic Rollback if verification fails (rollback-update)
```

### Data Preserved During Updates:
- WireGuard interface configuration (`/etc/wireguard/wg0.conf`)
- Server private/public keys
- All active client tunnels and peers
- Application database (`/var/lib/wireguard-manager/wireguard.db`)
- Administrator credentials and sessions
- Firewall rules and sysctl forwarding
- TLS certificates (`/etc/wireguard-manager/`)

---

## 6. IP Allocation Lifecycle

- VPN Subnet: `10.50.0.0/24`
- Reserved Server IP: `10.50.0.1` (never allocated to clients)
- Client Pool: `10.50.0.2` to `10.50.0.254`
- IP Allocation Policy:
  1. Sequential lowest-available IP search within `10.50.0.2` - `10.50.0.254`.
  2. Never reallocates an IP currently held by an active or disabled client.
  3. Deprioritizes recently revoked IPs unless the subnet pool is near exhaustion.

---

## 7. Disaster Recovery & Backup

- **Full JSON Backup**: `BackupService` exports all client records, network profile defaults, audit logs, and the bcrypt administrator password hash into a portable JSON document.
- **In-App Application Backups**: Prior to any application update, `UpdateService` archives the current codebase to `/var/lib/wireguard-manager/backups/`. If an update fails health verification, the previous version is restored automatically.
