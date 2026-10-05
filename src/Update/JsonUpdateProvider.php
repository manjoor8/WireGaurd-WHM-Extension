<?php
declare(strict_types=1);

namespace WireGuardManager\Update;

use RuntimeException;
use InvalidArgumentException;

/**
 * Update provider that reads release information from a JSON endpoint.
 */
class JsonUpdateProvider implements IUpdateProvider
{
    private string $endpointUrl;
    private int $timeoutSeconds;

    public function __construct(string $endpointUrl, int $timeoutSeconds = 5)
    {
        $this->endpointUrl = trim($endpointUrl);
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function getEndpointUrl(): string
    {
        return $this->endpointUrl;
    }

    public function checkForUpdate(string $currentVersion): ?UpdateInfo
    {
        if (empty($this->endpointUrl)) {
            return null;
        }

        $content = $this->fetchUrl($this->endpointUrl);
        if ($content === null || trim($content) === '') {
            return null;
        }

        $data = json_decode($content, true);
        if (!is_array($data) || empty($data['version'])) {
            return null;
        }

        $latestVersion = (string)$data['version'];
        if (SemVer::isNewer($latestVersion, $currentVersion)) {
            return UpdateInfo::fromArray($data);
        }

        return null;
    }

    public function download(UpdateInfo $update, string $destinationPath): string
    {
        if (empty($update->downloadUrl)) {
            throw new InvalidArgumentException("Download URL is missing in update metadata.");
        }

        $dir = dirname($destinationPath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }

        // Support local file path for offline testing / installations
        if (file_exists($update->downloadUrl)) {
            if (!copy($update->downloadUrl, $destinationPath)) {
                throw new RuntimeException("Failed to copy update package from local source: {$update->downloadUrl}");
            }
            return $destinationPath;
        }

        // Download via HTTP/HTTPS
        $fp = fopen($destinationPath, 'w+');
        if (!$fp) {
            throw new RuntimeException("Cannot open destination path for writing: $destinationPath");
        }

        $ch = curl_init($update->downloadUrl);
        curl_setopt($ch, CURLOPT_FILE, $fp);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        curl_setopt($ch, CURLOPT_USERAGENT, 'WireGuardManager-Updater/' . $update->version);
        curl_setopt($ch, CURLOPT_FAILONERROR, true);

        $success = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        fclose($fp);

        if (!$success || $httpCode >= 400) {
            @unlink($destinationPath);
            throw new RuntimeException("Failed to download update package: HTTP $httpCode - $error");
        }

        return $destinationPath;
    }

    private function fetchUrl(string $url): ?string
    {
        // Support local files for unit testing
        if (file_exists($url) || str_starts_with($url, 'file://')) {
            $path = str_starts_with($url, 'file://') ? substr($url, 7) : $url;
            return file_get_contents($path) ?: null;
        }

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
            curl_setopt($ch, CURLOPT_USERAGENT, 'WireGuardManager-UpdateChecker');
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

            $result = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($result !== false && $httpCode >= 200 && $httpCode < 300) {
                return (string)$result;
            }
            return null;
        }

        $ctx = stream_context_create([
            'http' => [
                'timeout' => $this->timeoutSeconds,
                'header' => "User-Agent: WireGuardManager-UpdateChecker\r\n",
            ],
        ]);
        $result = @file_get_contents($url, false, $ctx);
        return $result !== false ? $result : null;
    }
}
