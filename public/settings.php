<?php
declare(strict_types=1);

// bootstrap enforces Host allow-list, hardened session and CSRF on POST
require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuthService;
use WireGuardManager\ConfigService;
use WireGuardManager\AuditService;
use WireGuardManager\BackupService;
use WireGuardManager\WireGuardService;
use WireGuardManager\Database;
use WireGuardManager\Session;

AuthService::requireAuth();

$db = Database::getConnection();
$configService = new ConfigService($db);
$audit = new AuditService($db);

$settingsErrors = [];
$passwordErrors = [];

// Handle GET-based Configuration Export
if (isset($_GET['action']) && $_GET['action'] === 'export') {
    $backup = new BackupService($db);
    $json = $backup->exportJson();
    $filename = 'wireguard-manager-backup-' . date('Y-m-d-His') . '.json';

    header('Content-Type: application/json; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($json));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    echo $json;
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'update_settings');

    if ($action === 'export') {
        $backup = new BackupService($db);
        $json = $backup->exportJson();
        $filename = 'wireguard-manager-backup-' . date('Y-m-d-His') . '.json';

        header('Content-Type: application/json; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($json));
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Pragma: no-cache');
        echo $json;
        exit;
    }

    if ($action === 'import') {
        try {
            if (!isset($_FILES['backup_file']) || $_FILES['backup_file']['error'] !== UPLOAD_ERR_OK) {
                $uploadErr = $_FILES['backup_file']['error'] ?? UPLOAD_ERR_NO_FILE;
                throw new \InvalidArgumentException($uploadErr === UPLOAD_ERR_NO_FILE
                    ? 'Please select a JSON backup file to upload.'
                    : 'File upload failed with error code ' . $uploadErr);
            }

            $fileTmp = $_FILES['backup_file']['tmp_name'];
            $fileSize = $_FILES['backup_file']['size'];
            if ($fileSize > 10 * 1024 * 1024) {
                throw new \InvalidArgumentException('Backup file is too large (maximum 10MB).');
            }

            $content = file_get_contents($fileTmp);
            if ($content === false || trim($content) === '') {
                throw new \InvalidArgumentException('Uploaded file is empty.');
            }

            $data = json_decode($content, true);
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid backup file format: file must be valid JSON.');
            }

            $wg = new WireGuardService();
            $backup = new BackupService($db);
            $summary = $backup->import($data, $wg);

            // Refresh credential fingerprint for the current session so the admin stays signed in
            $_SESSION['credential_fp'] = hash('sha256', (string)AuthService::getHash());

            Session::flash('success', sprintf(
                'Configuration imported successfully! Restored %d client(s) (%d active in WireGuard), administrator credentials, and profile settings.',
                $summary['clients_count'],
                $summary['active_clients']
            ));
            header('Location: /settings.php');
            exit;
        } catch (\Throwable $e) {
            Session::flash('error', 'Import failed: ' . $e->getMessage());
            header('Location: /settings.php');
            exit;
        }
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $new = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        $err = AuthService::changePassword($current, $new, $confirm);
        if ($err === null) {
            Session::flash('success', 'Administrator password changed successfully.');
            header('Location: /settings.php');
            exit;
        } else {
            $passwordErrors[] = $err;
        }
    } else {
        $result = $configService->updateSettings($_POST);
        if ($result['success']) {
            $audit->log('UPDATE_SETTINGS', 'Settings updated');
            Session::flash('success', 'Settings saved successfully.');
            header('Location: /settings.php');
            exit;
        } else {
            $settingsErrors = $result['errors'];
        }
    }
}

$settings = $configService->getAll();
$pageTitle = 'Settings';
$activeNav = 'settings';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/settings.php';
require dirname(__DIR__) . '/templates/footer.php';
