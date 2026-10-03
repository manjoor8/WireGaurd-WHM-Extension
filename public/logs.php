<?php
declare(strict_types=1);

session_start();

require_once dirname(__DIR__) . '/src/bootstrap.php';

use WireGuardManager\AuditService;
use WireGuardManager\Database;

$db = Database::getConnection();
$audit = new AuditService($db);
$logs = $audit->getRecentLogs(100);

$pageTitle = 'Audit Logs';
$activeNav = 'logs';

require dirname(__DIR__) . '/templates/header.php';
require dirname(__DIR__) . '/templates/logs.php';
require dirname(__DIR__) . '/templates/footer.php';
