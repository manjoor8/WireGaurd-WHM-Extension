# Architecture and Security Specification

## 1. Executive Summary

This document describes the architectural design and security posture of the standalone **WireGuard VPN Manager** on AlmaLinux 10.2 / RHEL systems.

The application functions completely independently of cPanel, WHM, and Apache, without requiring reverse proxies or altering existing firewall and NAT rules. It serves traffic over encrypted HTTPS strictly on the private WireGuard management interface (`10.50.0.1:5443`).

---

## 2. Security Boundaries & Threat Modeling

### 2.1 Interface Isolation & Dual-Socket Model
- The application separates TLS termination from application runtime:
  1. **TLS Terminator (`stunnel`)**:
     - Listens strictly on `10.50.0.1:5443` (the private WireGuard IP address assigned to `wg0`).
     - Serves TLS 1.2 / 1.3 with a 2048-bit RSA self-signed certificate containing Subject Alternative Name `IP:10.50.0.1`.
     - Rejects any direct WAN requests because no listener binds to public interfaces (`eth0`, `0.0.0.0`, or `::`).
  2. **Application Backend (`php -S`)**:
     - Binds strictly to `127.0.0.1:5050` (IPv4 loopback only).
     - Never accepts connections from remote IPs or interfaces directly.
     - Spawns concurrent workers via `PHP_CLI_SERVER_WORKERS=4`.

### 2.2 Database-Backed Authentication & Lockout Protection
- Administrator credentials are stored as a bcrypt hash (`PASSWORD_BCRYPT` with cost 12) in SQLite (`admin_auth` table).
- Passwords must be at least 12 characters and max 72 bytes.
- If no password is configured in the database, the application fails closed: login is blocked, displaying an informative message directing the admin to run `/usr/local/bin/wireguard-manager-passwd`.
- **Brute-Force Rate Limiting**:
  - Failed login attempts are recorded in `login_attempts` with client IP and timestamps.
  - 5 failed attempts within 15 minutes trigger a 15-minute IP lockout.
  - Artificial sleep delays (`usleep(300000)`) dampen online timing attacks.
  - Verification uses `password_verify` and constant-time dummy comparisons when users or hashes are missing.

### 2.3 Session Hardening & Invalidation
- Dedicated session cookie `WGMSESSID` with flags:
  - `HttpOnly`: Prevents JavaScript reading the cookie.
  - `SameSite=Strict`: Protects against cross-site request forgery.
  - `Secure`: Enabled automatically when TLS is active (`WGM_TLS=1`).
- Rolling inactivity timeout of 30 minutes; absolute session ceiling of 12 hours.
- Session IDs are regenerated upon successful authentication.
- A cryptographic credential fingerprint (`hash('sha256', password_hash)`) is bound to the session: changing the admin password automatically invalidates all other concurrent active sessions.

### 2.4 CSRF & Defense-in-Depth HTTP Headers
- Every POST request must supply a valid `csrf_token` validated via `hash_equals()`.
- Central HTTP headers emitted on every response:
  - `Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; connect-src 'self'; form-action 'self'; frame-ancestors 'none';`
  - `X-Frame-Options: DENY` (anti-clickjacking)
  - `X-Content-Type-Options: nosniff` (MIME sniffing defense)
  - `Referrer-Policy: strict-origin-when-cross-origin`
- Host header enforcement: `Security::enforceHost()` strictly rejects requests with spoofed `Host` headers not matching `10.50.0.1:5443` or `127.0.0.1:5050`.

### 2.5 Privilege Separation
- The web server process runs as user `wireguard-manager` (system user without login shell).
- WireGuard peer management (`wg set wg0 ...`) requires elevated root privileges.
- Rather than running PHP as root, the application delegates privileged operations to `/usr/local/bin/wireguard-manager-helper`.
- The helper script is:
  - Owned by `root:wireguard-manager` with mode `0750`.
  - Configured in `/etc/sudoers.d/wireguard-manager` with `NOPASSWD:` execution for only that specific path.
  - Hardened with strict regex validation for all parameters:
    - Public Keys: `^[A-Za-z0-9+/]{43}=$`
    - Allowed IPs: `^10\.50\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$`
    - Actions: `status`, `dump`, `list-peers`, `peer-info`, `add-peer`, `remove-peer`, `traffic`, `handshake`, `genkey`, `pubkey`.
  - Sensitive private keys are read strictly through standard input (stdin) rather than command-line arguments to prevent leakage in process tables (`/proc/<pid>/cmdline`).

### 2.6 Sensitive Key Protection
- Curve25519 private keys generated for client configuration are stored in SQLite database with file permissions `0660` inside `/var/lib/wireguard-manager/` (directory mode `0750`).
- The database is outside the web document root (`/opt/wireguard-manager/public`).
- Private keys are **never** logged to audit trails, debug output, or syslog.
- When a client is revoked, its private key is set to `NULL` permanently.

---

## 3. Component Architecture

```
+----------------------------------------------------------------------------------+
|                                  Linux Server                                    |
|                                                                                  |
|  +-----------------------------+         +------------------------------------+  |
|  |   stunnel TLS Terminator    |         |       WireGuard Kernel/Tool        |  |
|  |   (wireguard-manager-tls)   |         |               (wg0)                |  |
|  |     (10.50.0.1:5443)        |         +-----------------+------------------+  |
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
|  +-----------------------------+                                                 |
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

## 4. IP Allocation Lifecycle

- VPN Subnet: `10.50.0.0/24`
- Reserved Server IP: `10.50.0.1` (never allocated to clients)
- Client Pool: `10.50.0.2` to `10.50.0.254`
- IP Allocation Policy:
  1. Sequential lowest-available IP search within `10.50.0.2` - `10.50.0.254`.
  2. Never reallocates an IP currently held by an active or disabled client.
  3. Deprioritizes recently revoked IPs unless the subnet pool is near exhaustion.

---

## 5. Configuration Backup & Disaster Recovery

- **Full JSON Backup**: `BackupService` exports all client records (including Curve25519 private/public keys, IPs, and states), network profile defaults, audit logs, and the bcrypt administrator password hash into a portable JSON document.
- **Atomic Import & Runtime Sync**: On import, operations are executed within an atomic database transaction (`BEGIN ... COMMIT`). Active clients are immediately synchronized with the active WireGuard interface (`wg0`) via privileged helper calls, and the administrator session credential fingerprint is refreshed to maintain seamless access.
