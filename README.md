# WireGuard VPN Manager (Standalone Edition)

A secure, lightweight standalone web management application for an existing WireGuard VPN server on AlmaLinux 10.2 / RHEL systems.

> **IMPORTANT ARCHITECTURAL NOTICE:**
> This application is **NOT** a WHM or cPanel plugin. It does not use WHM plugin APIs, cPanel hooks, or Apache reverse proxies. It runs as an independent, unprivileged systemd service bound exclusively to the private WireGuard VPN interface (`10.50.0.1:5050`).

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
       +--- eth0 (Public IP - No port 5050 listener)
       |
       +--- wg0 (WireGuard Interface: 10.50.0.1)
                 |
                 +=============================+
                 | WireGuard VPN Network       |
                 | Subnet: 10.50.0.0/24        |
                 +=============================+
                 |
        Connected VPN Client
            (10.50.0.2)
                 |
                 v HTTP GET http://10.50.0.1:5050
          [WireGuard Manager]
          (10.50.0.1:5050 ONLY)
```

### Security Boundary Model
- **Exclusively Bound Socket**: The application binds strictly to `10.50.0.1:5050`. It never binds to `0.0.0.0`, `*`, `::`, or the public IP.
- **Physical Unreachability**: Because no process listens on the public interface on port 5050, external traffic from the public Internet cannot reach the web application.
- **Password Protected**: Web interface access requires administrator password authentication (`[REDACTED]`, customizable via `ADMIN_PASSWORD` environment variable in the systemd service).
- **Privilege Separation**: The web application runs under an unprivileged system user (`wireguard-manager`). WireGuard operations are executed via a dedicated, strictly validated helper script (`/usr/local/bin/wireguard-manager-helper`) via restricted sudo rules.
- **Sensitive Key Protection**: Private keys are never logged in application logs, audit logs, or system journals.

---

## 2. Prerequisites

The server must already have:
1. **AlmaLinux 10.2** (or compatible RHEL-family Linux)
2. **WireGuard** installed (`wg` CLI available)
3. **Active WireGuard interface `wg0`** with IP `10.50.0.1/24` assigned
4. **PHP CLI** (>= 8.0) with `pdo_sqlite` support
5. **qrencode** (recommended for mobile QR code generation: `dnf install qrencode`)

---

## 3. Installation

Clone or copy the repository onto the server and run `install.sh` as `root`:

```bash
# Clone the repository
git clone https://github.com/manjoor8/WireGaurd-WHM-Extension.git /root/wireguard-manager-src
cd /root/wireguard-manager-src

# Execute installer
chmod +x install.sh uninstall.sh bin/wireguard-manager-helper
sudo ./install.sh
```

### What `install.sh` Does:
1. Detects OS and verifies AlmaLinux / RHEL compatibility.
2. Checks that `wg` binary exists, `wg0` interface is active, and `10.50.0.1` is assigned to `wg0`.
3. Verifies that port `5050` is not already occupied.
4. Detects PHP CLI and ensures `pdo_sqlite` extension is loaded.
5. Creates dedicated system user and group `wireguard-manager`.
6. Copies application code to `/opt/wireguard-manager`.
7. Installs the hardened helper to `/usr/local/bin/wireguard-manager-helper` (`0750`, `root:wireguard-manager`).
8. Configures sudoers at `/etc/sudoers.d/wireguard-manager` (validating with `visudo -cf`).
9. Initializes SQLite database at `/var/lib/wireguard-manager/wireguard.db` (`0660`).
10. Installs, enables, and starts systemd service `wireguard-manager.service`.
11. Performs automated socket binding and loopback health checks.

---

## 4. Verification Procedures

### Test 1: Verify Socket Binding Exclusivity
Run the following command on the server:

```bash
ss -lntp | grep 5050
```

**Expected Output:**
```
LISTEN 0 128 10.50.0.1:5050 0.0.0.0:* users:(("php",pid=...,fd=...))
```

> **CRITICAL CHECK**: Ensure that `0.0.0.0:5050`, `:::5050`, or `<PUBLIC_IP>:5050` are **NOT** listed.

---

### Test 2: Access from VPN Client
Connect your client machine to WireGuard (e.g., client IP `10.50.0.2`), then run:

```bash
curl -I http://10.50.0.1:5050
```

**Expected Output:**
```
HTTP/1.1 200 OK
```
Or open `http://10.50.0.1:5050` in your web browser. The WireGuard VPN Manager dashboard will load directly without login.

---

### Test 3: Access from Server Host
Run locally on the server:

```bash
curl -I http://10.50.0.1:5050
```

**Expected Output:**
```
HTTP/1.1 200 OK
```

---

### Test 4: Access from Public Internet (Negative Test)
From an external computer or phone **disconnected** from the VPN:

```bash
curl --connect-timeout 5 http://<SERVER_PUBLIC_IP>:5050
```

**Expected Output:**
```
curl: (7) Failed to connect to <SERVER_PUBLIC_IP> port 5050: Connection refused
# or Timeout (depending on host firewall)
```

---

## 5. Privileged Helper Architecture

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
              | 3. Executes specific WireGuard command
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
- `pubkey`: Derives public key from private key.

---

## 6. Directory Structure

```
/opt/wireguard-manager/
├── database/
│   └── schema.sql                # SQLite schema
├── public/                       # Web Document Root
│   ├── index.php                 # Dashboard controller
│   ├── clients.php               # Client list & actions
│   ├── client.php                # Client details, .conf download & QR
│   ├── add-client.php            # Add client form & keygen
│   ├── settings.php              # Network & profile settings
│   ├── logs.php                  # Audit log viewer
│   ├── router.php                # Built-in PHP server router
│   └── assets/
│       ├── css/
│       │   └── app.css           # Clean, responsive CSS styling
│       └── js/
│           └── app.js            # Vanilla JS (modals, copy, QR)
├── src/                          # Application Services (Private)
│   ├── bootstrap.php             # PSR-4 autoloader & view helpers
│   ├── Database.php              # SQLite singleton & migrations
│   ├── WireGuardService.php      # Helper bridge & parser
│   ├── ClientService.php         # Client lifecycle & IP allocator
│   ├── ConfigService.php         # Settings management
│   ├── QRService.php             # In-memory qrencode Data URI generator
│   └── AuditService.php          # Security audit trail
└── templates/                    # HTML UI Templates
    ├── header.php
    ├── footer.php
    ├── dashboard.php
    ├── clients.php
    ├── client.php
    ├── add-client.php
    ├── settings.php
    └── logs.php
```

---

## 7. Systemd Service Management

To check service status:
```bash
systemctl status wireguard-manager.service
```

To view live service logs:
```bash
journalctl -u wireguard-manager.service -f
```

To restart the application:
```bash
systemctl restart wireguard-manager.service
```

---

## 8. Uninstallation

To safely remove the management application without affecting WireGuard, `wg0`, active peers, NAT, or firewalls:

```bash
cd /root/wireguard-manager-src
sudo ./uninstall.sh
```

---

## 9. Future Roadmap / Authentication Notice

> **TODO (Security):**
> Authentication (passwords, 2FA, OAuth) MUST be added before exposing the management interface to any network beyond the trusted VPN interface.
