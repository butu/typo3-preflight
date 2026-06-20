<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Project;

use Symfony\Component\Yaml\Yaml;

/**
 * Loads and merges wp-typo3-preflight.yml with dist defaults.
 */
final class ManifestLoader
{
    /**
     * Load the manifest for the given project root.
     *
     * @return array The resolved configuration array
     */
    public function load(string $projectRoot): array
    {
        $defaults = $this->loadDefaults();
        $project = $this->loadProjectConfig($projectRoot);

        return $this->merge($defaults, $project);
    }

    /**
     * Default configuration — used when no project config exists.
     */
    public function getDefaults(): array
    {
        return $this->loadDefaults();
    }

    private function loadDefaults(): array
    {
        return [
            'suites' => [
                'static' => ['enabled' => true],
                'runtime' => ['enabled' => true],
            ],
            'base_url' => $this->detectDdevUrl(),
            'urls' => [],
            'baseline' => [
                'path' => 'build/preflight',
            ],
        ];
    }

    private function loadProjectConfig(string $projectRoot): array
    {
        $projectConfigPath = $projectRoot . '/wp-typo3-preflight.yml';

        if (!file_exists($projectConfigPath)) {
            return [];
        }

        try {
            $parsed = Yaml::parseFile($projectConfigPath);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            return [];
        }

        if (!is_array($parsed)) {
            return [];
        }

        return $parsed;
    }

    /**
     * Detect DDEV primary URL from environment.
     */
    private function detectDdevUrl(): string
    {
        $url = getenv('DDEV_PRIMARY_URL');
        return is_string($url) && $url !== '' ? $url : '';
    }

    /**
     * Deep-merge two configuration arrays. Project values override defaults.
     */
    private function merge(array $defaults, array $overrides): array
    {
        foreach ($overrides as $key => $value) {
            if (is_array($value) && isset($defaults[$key]) && is_array($defaults[$key])) {
                $defaults[$key] = $this->merge($defaults[$key], $value);
            } else {
                $defaults[$key] = $value;
            }
        }

        return $defaults;
    }
}
