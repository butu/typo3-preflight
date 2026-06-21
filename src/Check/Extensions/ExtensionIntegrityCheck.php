<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Extensions;

use Symfony\Component\Yaml\Yaml;
use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Validates local TYPO3 extension packages under packages/{name}/composer.json.
 *
 * Checks:
 *   - composer.json is parseable
 *   - type is typo3-cms-extension
 *   - extra.typo3/cms.extension-key is present and non-empty
 *   - PSR-4 autoload target paths exist
 *   - When Classes/ exists, Configuration/Services.yaml must exist
 *   - Services.yaml is parseable and referenced resource/exclude paths exist
 */
final class ExtensionIntegrityCheck implements CheckInterface
{
    private const CODE_JSON_PARSE = 'extension-composer-json-invalid';
    private const CODE_TYPE = 'extension-invalid-type';
    private const CODE_KEY = 'extension-missing-key';
    private const CODE_PSR4_PATH = 'extension-psr4-path-missing';
    private const CODE_SERVICES_MISSING = 'extension-services-missing';
    private const CODE_SERVICES_YAML = 'extension-services-yaml-invalid';
    private const CODE_SERVICES_PATH = 'extension-services-path-missing';

    public function name(): string
    {
        return 'extension-integrity';
    }

    public function suite(): string
    {
        return 'extensions';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $packageFiles = glob($context->projectRoot . '/packages/*/composer.json');

        if ($packageFiles === false || $packageFiles === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No local extension packages found under packages/.',
            );
        }

        $failures = [];
        $checkedPackages = 0;
        $skippedPackages = 0;

        foreach ($packageFiles as $composerJsonPath) {
            $packageDir = dirname($composerJsonPath);
            $packageName = basename($packageDir);
            $relativePath = 'packages/' . $packageName . '/composer.json';

            $data = $this->parseComposerJson($composerJsonPath);
            if ($data === null) {
                $failures[] = new Failure(
                    self::CODE_JSON_PARSE,
                    sprintf('Cannot parse composer.json of package "%s".', $packageName),
                    $relativePath,
                );
                continue;
            }

            if (!$this->shouldInspectAsTypo3Extension($data, $packageDir)) {
                $skippedPackages++;
                continue;
            }

            $checkedPackages++;

            $failures = [
                ...$failures,
                ...$this->checkType($data, $packageName, $relativePath),
            ];

            // Only proceed with further checks if type is valid
            if (($data['type'] ?? '') !== 'typo3-cms-extension') {
                continue;
            }

            $failures = [
                ...$failures,
                ...$this->checkExtensionKey($data, $relativePath),
                ...$this->checkPsr4Paths($data, $packageDir, $relativePath),
                ...$this->checkServices($packageDir, $relativePath),
            ];
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d extension integrity failure(s) found.', count($failures)),
                ['packages_checked' => (string) $checkedPackages, 'packages_skipped' => (string) $skippedPackages],
                $failures,
            );
        }

        if ($checkedPackages === 0) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No local TYPO3 extension packages found under packages/.',
                ['packages_skipped' => (string) $skippedPackages],
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            'All local extension packages passed integrity checks.',
            ['packages_checked' => (string) $checkedPackages, 'packages_skipped' => (string) $skippedPackages],
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseComposerJson(string $path): ?array
    {
        $content = file_get_contents($path);
        if ($content === false) {
            return null;
        }

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Failure>
     */
    private function checkType(array $data, string $packageName, string $relativePath): array
    {
        $type = $data['type'] ?? '';

        if ($type !== 'typo3-cms-extension') {
            return [
                new Failure(
                    self::CODE_TYPE,
                    sprintf(
                        'Package "%s" has type "%s", expected "typo3-cms-extension".',
                        $packageName,
                        $type !== '' ? $type : '(none)',
                    ),
                    $relativePath,
                    ['package' => $packageName, 'actual_type' => $type],
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function shouldInspectAsTypo3Extension(array $data, string $packageDir): bool
    {
        if (($data['type'] ?? '') === 'typo3-cms-extension') {
            return true;
        }

        $extensionKey = $data['extra']['typo3/cms']['extension-key'] ?? null;
        if (is_string($extensionKey) && $extensionKey !== '') {
            return true;
        }

        foreach (['ext_localconf.php', 'ext_tables.php', 'ext_emconf.php'] as $extensionFile) {
            if (file_exists($packageDir . '/' . $extensionFile)) {
                return true;
            }
        }

        return is_dir($packageDir . '/Configuration/TCA') || is_dir($packageDir . '/ContentBlocks');
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Failure>
     */
    private function checkExtensionKey(array $data, string $relativePath): array
    {
        $key = $data['extra']['typo3/cms']['extension-key'] ?? '';

        if (!is_string($key) || $key === '') {
            return [
                new Failure(
                    self::CODE_KEY,
                    'Missing or empty extra.typo3/cms.extension-key in composer.json.',
                    $relativePath,
                ),
            ];
        }

        return [];
    }

    /**
     * @param array<string, mixed> $data
     * @return list<Failure>
     */
    private function checkPsr4Paths(array $data, string $packageDir, string $relativePath): array
    {
        $psr4 = $data['autoload']['psr-4'] ?? [];

        if (!is_array($psr4)) {
            return [];
        }

        $failures = [];

        foreach ($psr4 as $namespace => $path) {
            if (!is_string($path)) {
                continue;
            }

            $absolutePath = $packageDir . '/' . rtrim($path, '/');

            if (!is_dir($absolutePath)) {
                // Many TYPO3 template/config-only extensions keep the conventional PSR-4
                // entry although they do not ship PHP classes. That should not fail preflight.
                if (rtrim($path, '/') === 'Classes') {
                    continue;
                }

                $failures[] = new Failure(
                    self::CODE_PSR4_PATH,
                    sprintf(
                        'PSR-4 autoload path "%s" for namespace "%s" does not exist.',
                        $path,
                        $namespace,
                    ),
                    $relativePath,
                    ['namespace' => $namespace, 'path' => $path],
                );
            }
        }

        return $failures;
    }

    /**
     * Check Services.yaml existence and validity.
     *
     * @return list<Failure>
     */
    private function checkServices(string $packageDir, string $relativePath): array
    {
        $classesDir = $packageDir . '/Classes';
        $servicesYaml = $packageDir . '/Configuration/Services.yaml';

        // Step 5: If Classes/ exists, Services.yaml must exist
        if (is_dir($classesDir) && !file_exists($servicesYaml)) {
            return [
                new Failure(
                    self::CODE_SERVICES_MISSING,
                    'Classes/ directory exists but Configuration/Services.yaml is missing.',
                    $relativePath,
                ),
            ];
        }

        // Step 6: If Services.yaml exists, validate it
        if (!file_exists($servicesYaml)) {
            return [];
        }

        return $this->validateServicesYaml($servicesYaml, $packageDir, $relativePath);
    }

    /**
     * @return list<Failure>
     */
    private function validateServicesYaml(string $servicesYamlPath, string $packageDir, string $relativePath): array
    {
        $configDir = $packageDir . '/Configuration';
        $failures = [];

        try {
            $yaml = Yaml::parseFile($servicesYamlPath);
        } catch (\Symfony\Component\Yaml\Exception\ParseException $e) {
            return [
                new Failure(
                    self::CODE_SERVICES_YAML,
                    sprintf('Configuration/Services.yaml is not valid YAML: %s', $e->getMessage()),
                    $relativePath,
                ),
            ];
        }

        if (!is_array($yaml)) {
            return [];
        }

        $services = $yaml['services'] ?? [];
        if (!is_array($services)) {
            return [];
        }

        foreach ($services as $serviceDefinition) {
            if (!is_array($serviceDefinition)) {
                continue;
            }

            $failures = [
                ...$failures,
                ...$this->checkServicePath($serviceDefinition, 'resource', $configDir, $relativePath),
                ...$this->checkServicePath($serviceDefinition, 'exclude', $configDir, $relativePath),
            ];
        }

        return $failures;
    }

    /**
     * @param array<string, mixed> $definition
     * @return list<Failure>
     */
    private function checkServicePath(array $definition, string $key, string $baseDir, string $relativePath): array
    {
        $value = $definition[$key] ?? null;

        if (is_array($value)) {
            $failures = [];
            foreach ($value as $singleValue) {
                if (!is_string($singleValue)) {
                    continue;
                }
                $failures = [
                    ...$failures,
                    ...$this->checkSingleServicePath($singleValue, $key, $baseDir, $relativePath),
                ];
            }

            return $failures;
        }

        if (!is_string($value) || $value === '') {
            return [];
        }

        return $this->checkSingleServicePath($value, $key, $baseDir, $relativePath);
    }

    /**
     * @return list<Failure>
     */
    private function checkSingleServicePath(string $value, string $key, string $baseDir, string $relativePath): array
    {
        // Only check paths that look like filesystem references
        if (!str_contains($value, '/')) {
            return [];
        }

        // Exclude globs may intentionally match zero files/directories.
        if ($key === 'exclude' && str_contains($value, '*')) {
            return [];
        }

        $pathToCheck = $this->pathBeforeFirstGlobToken($value);
        $pathToCheck = rtrim($pathToCheck, '/');

        $resolvedPath = $baseDir . '/' . $pathToCheck;

        if (!is_dir($resolvedPath) && !is_file($resolvedPath)) {
            return [
                new Failure(
                    self::CODE_SERVICES_PATH,
                    sprintf(
                        'Services.yaml references path "%s" in key "%s" that does not exist (resolved to "%s").',
                        $value,
                        $key,
                        $pathToCheck,
                    ),
                    $relativePath,
                    ['key' => $key, 'value' => $value, 'resolved_path' => $pathToCheck],
                ),
            ];
        }

        return [];
    }

    private function pathBeforeFirstGlobToken(string $path): string
    {
        $firstGlobPosition = null;
        foreach (['*', '?', '[', '{'] as $token) {
            $position = strpos($path, $token);
            if ($position !== false && ($firstGlobPosition === null || $position < $firstGlobPosition)) {
                $firstGlobPosition = $position;
            }
        }

        if ($firstGlobPosition === null) {
            return $path;
        }

        return dirname(substr($path, 0, $firstGlobPosition + 1));
    }
}
