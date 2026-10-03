<?php
declare(strict_types=1);

namespace WireGuardManager;

use RuntimeException;

class QRService
{
    private string $qrencodeBin;

    public function __construct(?string $qrencodeBin = null)
    {
        $this->qrencodeBin = $qrencodeBin ?: '/usr/bin/qrencode';
    }

    /**
     * Generate an inline Data URI image for the given text.
     * Returns a Data URI (e.g. data:image/svg+xml;base64,...)
     */
    public function generateDataUri(string $content): string
    {
        if (trim($content) === '') {
            return '';
        }

        // Try SVG format first (vector, sharp, lightweight)
        $svgData = $this->runQrencode($content, 'SVG');
        if (!empty($svgData)) {
            return 'data:image/svg+xml;base64,' . base64_encode($svgData);
        }

        // Fallback to PNG format
        $pngData = $this->runQrencode($content, 'PNG');
        if (!empty($pngData)) {
            return 'data:image/png;base64,' . base64_encode($pngData);
        }

        return '';
    }

    private function runQrencode(string $content, string $type = 'SVG'): ?string
    {
        if (!is_executable($this->qrencodeBin)) {
            // Check PATH if default path is not executable
            $which = trim((string)@shell_exec('which qrencode 2>/dev/null'));
            if ($which !== '' && is_executable($which)) {
                $this->qrencodeBin = $which;
            } else {
                return null;
            }
        }

        $descriptors = [
            0 => ["pipe", "r"], // stdin
            1 => ["pipe", "w"], // stdout
            2 => ["pipe", "w"], // stderr
        ];

        $cmd = escapeshellcmd($this->qrencodeBin) . ' -t ' . escapeshellarg($type) . ' -o -';
        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            return null;
        }

        fwrite($pipes[0], $content);
        fclose($pipes[0]);

        $output = stream_get_contents($pipes[1]);
        fclose($pipes[1]);

        $error = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        if ($exitCode === 0 && !empty($output)) {
            return $output;
        }

        return null;
    }
}
