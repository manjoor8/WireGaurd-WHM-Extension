<?php
declare(strict_types=1);

namespace WireGuardManager;

/**
 * Global application metadata and environment helper.
 */
class App
{
    public const NAME = 'WireGuard VPN Manager';
    public const DEFAULT_VERSION = '1.4.0';

    public static function getVersion(): string
    {
        $versionFile = dirname(__DIR__) . '/VERSION';
        if (file_exists($versionFile)) {
            $ver = trim((string)file_get_contents($versionFile));
            if ($ver !== '') {
                return $ver;
            }
        }
        return self::DEFAULT_VERSION;
    }

    public static function getBuild(): string
    {
        $versionFile = dirname(__DIR__) . '/VERSION';
        if (file_exists($versionFile)) {
            $mtime = filemtime($versionFile);
            return date('Ymd.1', $mtime ?: time());
        }
        return date('Ymd.1');
    }

    public static function getRuntime(): string
    {
        return 'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ')';
    }

    public static function getOperatingSystem(): string
    {
        if (file_exists('/etc/os-release')) {
            $osRelease = parse_ini_file('/etc/os-release');
            if (is_array($osRelease) && !empty($osRelease['PRETTY_NAME'])) {
                return (string)$osRelease['PRETTY_NAME'];
            }
        }
        return PHP_OS_FAMILY . ' ' . php_uname('r');
    }

    /**
     * Create structured API error array.
     *
     * @param string $code Machine-readable error code (e.g. 'WIREGUARD_INSTALL_FAILED')
     * @param string $message Administrator-friendly message
     * @param string|null $details Technical details or sanitized technical error
     * @param bool $retryable Whether the operation can be retried
     * @param array $extra Optional extra context
     * @return array
     */
    public static function formatError(
        string $code,
        string $message,
        ?string $details = null,
        bool $retryable = true,
        array $extra = []
    ): array {
        return array_merge([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'details' => $details,
            'retryable' => $retryable,
        ], $extra);
    }

    /**
     * Create structured API success array.
     *
     * @param array $data Data to return
     * @param string|null $message Optional success message
     * @return array
     */
    public static function formatSuccess(array $data = [], ?string $message = null): array
    {
        $res = ['success' => true];
        if ($message !== null) {
            $res['message'] = $message;
        }
        return array_merge($res, $data);
    }
}
