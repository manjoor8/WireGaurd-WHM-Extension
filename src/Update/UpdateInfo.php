<?php
declare(strict_types=1);

namespace WireGuardManager\Update;

/**
 * Data Transfer Object for release/update metadata.
 */
class UpdateInfo
{
    public string $version;
    public string $downloadUrl;
    public string $releaseNotes;
    public ?string $publishedAt;
    public bool $mandatory;
    public ?string $checksum;
    public ?string $minAppVersion;

    public function __construct(
        string $version,
        string $downloadUrl,
        string $releaseNotes = '',
        ?string $publishedAt = null,
        bool $mandatory = false,
        ?string $checksum = null,
        ?string $minAppVersion = null
    ) {
        $this->version = trim($version);
        $this->downloadUrl = trim($downloadUrl);
        $this->releaseNotes = trim($releaseNotes);
        $this->publishedAt = $publishedAt;
        $this->mandatory = $mandatory;
        $this->checksum = $checksum !== null ? trim($checksum) : null;
        $this->minAppVersion = $minAppVersion;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['version'] ?? ''),
            (string)($data['downloadUrl'] ?? $data['download_url'] ?? ''),
            (string)($data['releaseNotes'] ?? $data['release_notes'] ?? $data['body'] ?? ''),
            isset($data['publishedAt']) ? (string)$data['publishedAt'] : (isset($data['published_at']) ? (string)$data['published_at'] : null),
            (bool)($data['mandatory'] ?? false),
            isset($data['checksum']) ? (string)$data['checksum'] : (isset($data['sha256']) ? (string)$data['sha256'] : null),
            isset($data['minAppVersion']) ? (string)$data['minAppVersion'] : null
        );
    }

    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'downloadUrl' => $this->downloadUrl,
            'releaseNotes' => $this->releaseNotes,
            'publishedAt' => $this->publishedAt,
            'mandatory' => $this->mandatory,
            'checksum' => $this->checksum,
            'minAppVersion' => $this->minAppVersion,
        ];
    }
}
