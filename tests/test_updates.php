<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/autoload.php';

use WireGuardManager\Update\SemVer;
use WireGuardManager\Update\UpdateInfo;
use WireGuardManager\Update\JsonUpdateProvider;
use WireGuardManager\Update\UpdateService;
use WireGuardManager\Database;

$passed = 0;
$failed = 0;

function it(string $desc, bool $condition): void {
    global $passed, $failed;
    if ($condition) {
        echo "[PASS] $desc\n";
        $passed++;
    } else {
        echo "[FAIL] $desc\n";
        $failed++;
    }
}

echo "=== Running Semantic Versioning & Update Tests ===\n\n";

// 1. SemVer comparison tests (Section 28 requirements)
it('1.9.0 < 1.10.0', SemVer::compare('1.9.0', '1.10.0') === -1);
it('1.10.0 > 1.9.0', SemVer::compare('1.10.0', '1.9.0') === 1);
it('1.0.1 > 1.0.0', SemVer::compare('1.0.1', '1.0.0') === 1);
it('1.0.0 == 1.0.0', SemVer::compare('1.0.0', '1.0.0') === 0);
it('2.0.0 > 1.99.99', SemVer::compare('2.0.0', '1.99.99') === 1);
it('v1.5.0 parses with leading v', SemVer::compare('v1.5.0', '1.4.0') === 1);
it('1.5.0 is newer than 1.4.0', SemVer::isNewer('1.5.0', '1.4.0') === true);
it('1.4.0 is not newer than 1.4.0', SemVer::isNewer('1.4.0', '1.4.0') === false);
it('1.3.9 is not newer than 1.4.0', SemVer::isNewer('1.3.9', '1.4.0') === false);

// 2. UpdateInfo DTO tests
$infoData = [
    'version' => '1.5.0',
    'downloadUrl' => 'https://example.com/wgm-1.5.0.zip',
    'releaseNotes' => '• Improved peer management\n• Fixed VPN status',
    'publishedAt' => '2026-10-06T12:00:00Z',
    'mandatory' => false,
    'checksum' => 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
];
$info = UpdateInfo::fromArray($infoData);
it('UpdateInfo parses version', $info->version === '1.5.0');
it('UpdateInfo parses downloadUrl', $info->downloadUrl === 'https://example.com/wgm-1.5.0.zip');
it('UpdateInfo parses checksum', $info->checksum === 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855');

// 3. JsonUpdateProvider test with mock local JSON
$tempJson = sys_get_temp_dir() . '/wgm_test_release_' . uniqid() . '.json';
file_put_contents($tempJson, json_encode([
    'version' => '1.5.0',
    'downloadUrl' => 'file://' . $tempJson,
    'releaseNotes' => 'Test release notes',
    'publishedAt' => '2026-10-06T00:00:00Z',
    'mandatory' => false,
]));

$provider = new JsonUpdateProvider($tempJson);
$checkResult = $provider->checkForUpdate('1.4.0');
it('Provider detects update when version is newer', $checkResult !== null && $checkResult->version === '1.5.0');

$noUpdate = $provider->checkForUpdate('1.5.0');
it('Provider reports no update when version is identical', $noUpdate === null);

$olderRelease = $provider->checkForUpdate('2.0.0');
it('Provider reports no update when current version is higher', $olderRelease === null);

@unlink($tempJson);

// 4. UpdateService backup and health verification
$testDb = sys_get_temp_dir() . '/wgm_update_test_' . uniqid() . '.db';
Database::setPath($testDb);
$db = Database::getConnection();

$testStorage = sys_get_temp_dir() . '/wgm_storage_' . uniqid();
@mkdir($testStorage, 0750, true);

$service = new UpdateService($db, null, $testStorage, dirname(__DIR__));
$health = $service->verifyHealth();
it('verifyHealth returns healthy for current app codebase', $health['healthy'] === true);

if (class_exists('ZipArchive')) {
    $backupZip = $service->backupCurrentVersion();
    it('backupCurrentVersion creates backup file', file_exists($backupZip) && filesize($backupZip) > 0);
    @unlink($backupZip);
} else {
    echo "[SKIP] ZipArchive extension not installed for backup test\n";
}

@unlink($testDb);
@unlink($testStorage . '/backups/latest_backup.json');
@rmdir($testStorage . '/backups');
@rmdir($testStorage);

echo "\nSummary: $passed passed, $failed failed.\n";
if ($failed > 0) {
    exit(1);
}
