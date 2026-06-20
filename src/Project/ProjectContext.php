<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Project;

/**
 * Immutable value object holding the project root and resolved configuration.
 */
final class ProjectContext
{
    /**
     * @param string $projectRoot Absolute path to the project root (contains composer.json)
     * @param array  $config      Resolved configuration array
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly array $config,
    ) {
    }

    /** Absolute path to the composer.json file. */
    public function composerJsonPath(): string
    {
        return $this->projectRoot . '/composer.json';
    }

    /** Base directory for vendor binaries. */
    public function vendorBinDir(): string
    {
        return $this->projectRoot . '/vendor/bin';
    }

    /** Resolved base URL for frontend smoke checks (from config or DDEV_PRIMARY_URL). */
    public function baseUrl(): ?string
    {
        return $this->config['base_url'] ?? null;
    }

    /** Additional frontend URLs to smoke. */
    public function urls(): array
    {
        return $this->config['urls'] ?? [];
    }

    /** Absolute path to the baseline directory. */
    public function baselinePath(): string
    {
        $relative = $this->config['baseline']['path'] ?? 'build/preflight';
        return $this->projectRoot . '/' . ltrim($relative, '/');
    }

    /** Whether a suite is enabled in config (default: true). */
    public function isSuiteEnabled(string $suite): bool
    {
        return $this->config['suites'][$suite]['enabled'] ?? true;
    }

    /** Whether the command is running inside a DDEV web container. */
    public function isDdevEnvironment(): bool
    {
        return getenv('DDEV_SITENAME') !== false || getenv('DDEV_PRIMARY_URL') !== false;
    }
}
