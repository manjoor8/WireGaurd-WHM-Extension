<?php
declare(strict_types=1);

namespace WireGuardManager\Update;

use InvalidArgumentException;

/**
 * Semantic Version comparator.
 * Accurately parses and compares versions according to SemVer 2.0 specs.
 * Ensures e.g. 1.10.0 > 1.9.0, 1.0.1 > 1.0.0, 2.0.0 > 1.99.99.
 */
class SemVer
{
    /**
     * Compare two version strings.
     *
     * @return int -1 if $v1 < $v2, 0 if $v1 == $v2, 1 if $v1 > $v2
     */
    public static function compare(string $v1, string $v2): int
    {
        $parsed1 = self::parse($v1);
        $parsed2 = self::parse($v2);

        // Compare Major
        if ($parsed1['major'] !== $parsed2['major']) {
            return $parsed1['major'] <=> $parsed2['major'];
        }

        // Compare Minor
        if ($parsed1['minor'] !== $parsed2['minor']) {
            return $parsed1['minor'] <=> $parsed2['minor'];
        }

        // Compare Patch
        if ($parsed1['patch'] !== $parsed2['patch']) {
            return $parsed1['patch'] <=> $parsed2['patch'];
        }

        // Compare Pre-release
        // A version without pre-release has higher precedence than with pre-release
        // e.g. 1.0.0 > 1.0.0-rc1
        $hasPre1 = ($parsed1['prerelease'] !== null && $parsed1['prerelease'] !== '');
        $hasPre2 = ($parsed2['prerelease'] !== null && $parsed2['prerelease'] !== '');

        if (!$hasPre1 && $hasPre2) {
            return 1;
        }
        if ($hasPre1 && !$hasPre2) {
            return -1;
        }
        if ($hasPre1 && $hasPre2) {
            return strcmp((string)$parsed1['prerelease'], (string)$parsed2['prerelease']);
        }

        return 0;
    }

    /**
     * Check if candidate version is strictly newer than current version.
     */
    public static function isNewer(string $candidate, string $current): bool
    {
        return self::compare($candidate, $current) > 0;
    }

    /**
     * Normalize and parse a version string.
     *
     * @return array{major: int, minor: int, patch: int, prerelease: ?string, build: ?string}
     */
    public static function parse(string $version): array
    {
        $v = trim($version);
        // Strip leading 'v' or 'V'
        if (str_starts_with($v, 'v') || str_starts_with($v, 'V')) {
            $v = substr($v, 1);
        }

        $pattern = '/^([0-9]+)(?:\.([0-9]+))?(?:\.([0-9]+))?(?:-([0-9A-Za-z.-]+))?(?:\+([0-9A-Za-z.-]+))?$/';
        if (!preg_match($pattern, $v, $matches)) {
            // Fallback: extract leading digits if possible
            if (preg_match('/^([0-9]+)/', $v, $m)) {
                return [
                    'major' => (int)$m[1],
                    'minor' => 0,
                    'patch' => 0,
                    'prerelease' => null,
                    'build' => null,
                ];
            }
            throw new InvalidArgumentException("Invalid semantic version format: '$version'");
        }

        return [
            'major' => (int)$matches[1],
            'minor' => isset($matches[2]) && $matches[2] !== '' ? (int)$matches[2] : 0,
            'patch' => isset($matches[3]) && $matches[3] !== '' ? (int)$matches[3] : 0,
            'prerelease' => $matches[4] ?? null,
            'build' => $matches[5] ?? null,
        ];
    }
}
