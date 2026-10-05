<?php
declare(strict_types=1);

namespace WireGuardManager\Update;

/**
 * Update Provider Interface.
 * Allows pluggable update sources (GitHub, S3, custom API, local filesystem for testing).
 */
interface IUpdateProvider
{
    /**
     * Check for newer versions than $currentVersion.
     *
     * @param string $currentVersion
     * @return UpdateInfo|null Returns UpdateInfo if an update is available, null otherwise.
     */
    public function checkForUpdate(string $currentVersion): ?UpdateInfo;

    /**
     * Download the update package to the specified destination path.
     *
     * @param UpdateInfo $update
     * @param string $destinationPath
     * @return string Downloaded file path
     */
    public function download(UpdateInfo $update, string $destinationPath): string;
}
