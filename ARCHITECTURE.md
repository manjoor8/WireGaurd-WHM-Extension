# Architecture and Security Specification

## 1. Executive Summary

This document describes the architectural design and security posture of the standalone **WireGuard VPN Manager** on AlmaLinux 10.2.

The application replaces previous attempts to integrate WireGuard management into WHM/cPanel plugins. It functions completely independently of cPanel, WHM, and Apache, without requiring reverse proxies or altering existing firewall and NAT rules.

---

## 2. Security Boundaries & Threat Modeling

### 2.1 Interface Isolation (Kernel Socket Level)
- The application web process is launched with:
  ```bash
  /usr/bin/php -S 10.50.0.1:5050 -t /opt/wireguard-manager/public /opt/wireguard-manager/public/router.php
  ```
- By specifying `10.50.0.1:5050`, the Linux kernel creates a TCP socket bound exclusively to the IP address associated with the `wg0` network device.
- Traffic arriving on `eth0` (or any public WAN interface) with destination port `5050` is dropped or rejected at the network/socket layer because no process is listening on the public IP or wildcard (`0.0.0.0` / `::`).
- As an added defense-in-depth measure, administrators may optionally restrict port 5050 to `10.50.0.0/24` in iptables/nftables:
  ```bash
  iptables -I INPUT -p tcp --dport 5050 ! -i wg0 -j DROP
  ```
  *(Note: Not executed automatically by install.sh to preserve cPanel firewall integrity).*

### 2.2 Access Control in V1
- V1 intentionally implements zero application-level authentication (no passwords, sessions, or logins).
- Access control relies entirely on membership in the WireGuard cryptographic network:
  1. Only peers with valid WireGuard keys configured on `wg0` can establish an encrypted tunnel.
  2. Only machines inside the `10.50.0.0/24` subnet can route packets to `10.50.0.1`.
- Access to `http://10.50.0.1:5050` is granted immediately upon connection to the VPN.

### 2.3 Privilege Separation
- The web server process runs as user `wireguard-manager` (system user without login shell).
- WireGuard peer management (`wg set wg0 ...`) requires elevated root privileges.
- Rather than running PHP as root, the application delegates privileged operations to `/usr/local/bin/wireguard-manager-helper`.
- The helper script is:
  - Owned by `root:wireguard-manager` with mode `0750`.
  - Configured in `/etc/sudoers.d/wireguard-manager` with `NOPASSWD:` execution for only that specific path.
  - Hardened with strict regex validation for all parameters:
    - Public Keys: `^[A-Za-z0-9+/]{43}=$`
    - Allowed IPs: `^10\.50\.0\.(?:[2-9]|[1-9][0-9]|1[0-9]{2}|2[0-4][0-9]|25[0-4])$`
    - Commands: Whitelist of `status`, `list-peers`, `peer-info`, `add-peer`, `remove-peer`, `traffic`, `handshake`, `genkey`, `pubkey`.
  - Shell execution from arbitrary user input is strictly impossible.

### 2.4 Sensitive Key Protection
- Curve25519 private keys generated for client configuration are stored in SQLite database with file permissions `0660` inside `/var/lib/wireguard-manager/` (directory mode `0750`).
- The database is outside the web document root (`/opt/wireguard-manager/public`).
- Private keys are **never** logged to audit trails, debug output, or syslog.
- When a client is revoked, its private key is set to `NULL` permanently.

---

## 3. Component Architecture

```
+-------------------------------------------------------------------------+
|                              Linux Server                               |
|                                                                         |
|  +-----------------------------+     +-------------------------------+  |
|  |       Systemd Service       |     |     WireGuard Kernel/Tool     |  |
|  |  (wireguard-manager.service)|     |             (wg0)             |  |
|  +--------------+--------------+     +---------------+---------------+  |
|                 |                                    ^                  |
|                 v                                    |                  |
|  +-----------------------------+                     |                  |
|  |  PHP Built-in Server (5050) |                     |                  |
|  |     (User: wireguard-manager)                     |                  |
|  +--------------+--------------+                     |                  |
|                 |                                    |                  |
|                 v                                    |                  |
|  +-----------------------------+     sudo -n         |                  |
|  |    Privileged Helper Script | --------------------+                  |
|  | (wireguard-manager-helper)  |  (strictly validated parameters)       |
|  +-----------------------------+                                        |
|                 |                                                       |
|                 v                                                       |
|  +-----------------------------+                                        |
|  |     SQLite Database File    |                                        |
|  |  (/var/lib/.../wireguard.db)|                                        |
|  +-----------------------------+                                        |
+-------------------------------------------------------------------------+
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
