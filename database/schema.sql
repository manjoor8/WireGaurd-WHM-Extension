-- WireGuard VPN Manager Schema
-- Location: /var/lib/wireguard-manager/wireguard.db

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS clients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    description TEXT,
    vpn_ip TEXT NOT NULL UNIQUE,
    public_key TEXT NOT NULL UNIQUE,
    private_key TEXT,
    state TEXT NOT NULL CHECK(state IN ('active', 'disabled', 'revoked')),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revoked_at DATETIME
);

CREATE INDEX IF NOT EXISTS idx_clients_vpn_ip ON clients(vpn_ip);
CREATE INDEX IF NOT EXISTS idx_clients_public_key ON clients(public_key);
CREATE INDEX IF NOT EXISTS idx_clients_state ON clients(state);

CREATE TABLE IF NOT EXISTS settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    action TEXT NOT NULL,
    actor TEXT NOT NULL DEFAULT 'local',
    details TEXT,
    ip_address TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at);

-- Default Settings
INSERT OR IGNORE INTO settings (key, value) VALUES
    ('interface', 'wg0'),
    ('vpn_network', '10.50.0.0/24'),
    ('server_vpn_ip', '10.50.0.1'),
    ('listen_port', '51820'),
    ('management_host', '10.50.0.1'),
    ('management_port', '5050'),
    ('dns', '1.1.1.1'),
    ('allowed_ips', '0.0.0.0/0'),
    ('persistent_keepalive', '25'),
    ('vpn_endpoint', '');
