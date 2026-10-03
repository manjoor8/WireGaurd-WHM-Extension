<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\WireGuardService;

function assertTest(bool $condition, string $testName): void {
    if (!$condition) {
        echo "[FAIL] $testName\n";
        exit(1);
    }
    echo "[PASS] $testName\n";
}

$wg = new WireGuardService();

// Test 1: Byte formatting
assertTest(WireGuardService::formatBytes(0) === '0 B', 'Format 0 bytes');
assertTest(WireGuardService::formatBytes(1023) === '1023 B', 'Format 1023 bytes');
assertTest(WireGuardService::formatBytes(1024) === '1.0 KB', 'Format 1024 bytes');
assertTest(WireGuardService::formatBytes(1048576) === '1.0 MB', 'Format 1 MB');
assertTest(WireGuardService::formatBytes(1073741824) === '1.0 GB', 'Format 1 GB');

// Test 2: Handshake formatting
$now = time();
$recent = WireGuardService::formatHandshake($now - 30);
assertTest($recent['is_online'] === true, 'Recent handshake (< 3m) is online');
assertTest($recent['text'] === '30s ago', 'Recent handshake text');

$old = WireGuardService::formatHandshake($now - 600);
assertTest($old['is_online'] === false, 'Old handshake (> 3m) is offline');
assertTest($old['text'] === '10m ago', 'Old handshake text');

$never = WireGuardService::formatHandshake(0);
assertTest($never['is_online'] === false && $never['text'] === 'Never', 'Zero timestamp handshake is Never');

// Test 3: Public key validation
$validKey = 'YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=';
$invalidKey1 = 'too-short';
$invalidKey2 = 'has invalid characters!===';
$invalidKey3 = 'YWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXoxMjM0NTY=='; // too long

$validException = false;
try {
    $wg->validatePublicKey($validKey);
} catch (\Throwable $e) {
    $validException = true;
}
assertTest(!$validException, 'Valid base64 44-char key accepted');

$caughtInvalid = false;
try {
    $wg->validatePublicKey($invalidKey1);
} catch (\InvalidArgumentException $e) {
    $caughtInvalid = true;
}
assertTest($caughtInvalid, 'Short key rejected');

$caughtInvalidChar = false;
try {
    $wg->validatePublicKey($invalidKey2);
} catch (\InvalidArgumentException $e) {
    $caughtInvalidChar = true;
}
assertTest($caughtInvalidChar, 'Invalid characters in key rejected');

// Test 4: VPN IP validation (must be 10.50.0.2 - 10.50.0.254)
$validIp = '10.50.0.2';
$validIpMax = '10.50.0.254';
$invalidServerIp = '10.50.0.1'; // reserved for server
$invalidSubnet = '10.50.0.0';
$invalidBroadcast = '10.50.0.255';
$invalidExternal = '192.168.1.5';

$validIpPassed = true;
try {
    $wg->validateVpnIp($validIp);
    $wg->validateVpnIp($validIpMax);
} catch (\Throwable $e) {
    $validIpPassed = false;
}
assertTest($validIpPassed, 'Valid VPN IPs (10.50.0.2, 10.50.0.254) accepted');

$caughtServerIp = false;
try {
    $wg->validateVpnIp($invalidServerIp);
} catch (\InvalidArgumentException $e) {
    $caughtServerIp = true;
}
assertTest($caughtServerIp, 'Server IP 10.50.0.1 rejected for client allocation');

$caughtSubnet = false;
try {
    $wg->validateVpnIp($invalidSubnet);
} catch (\InvalidArgumentException $e) {
    $caughtSubnet = true;
}
assertTest($caughtSubnet, 'Subnet network IP 10.50.0.0 rejected');

$caughtBroadcast = false;
try {
    $wg->validateVpnIp($invalidBroadcast);
} catch (\InvalidArgumentException $e) {
    $caughtBroadcast = true;
}
assertTest($caughtBroadcast, 'Broadcast IP 10.50.0.255 rejected');

$caughtExternal = false;
try {
    $wg->validateVpnIp($invalidExternal);
} catch (\InvalidArgumentException $e) {
    $caughtExternal = true;
}
assertTest($caughtExternal, 'Non-10.50.0.X IP rejected');

echo "\nAll validation tests passed successfully!\n";
