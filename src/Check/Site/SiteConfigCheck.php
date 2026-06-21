<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Site;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Validates TYPO3 site configuration YAML files.
 *
 * Checks:
 * - YAML is valid
 * - rootPageId is present
 * - base is present (either string or per-language array)
 * - No duplicate base values across languages
 * - errorHandling entries have errorCode (optional, but flagged)
 */
final class SiteConfigCheck implements CheckInterface
{
    private const CODE_YAML_INVALID = 'site-yaml-invalid';
    private const CODE_MISSING_ROOT_PAGE_ID = 'site-missing-rootPageId';
    private const CODE_MISSING_BASE = 'site-missing-base';
    private const CODE_DUPLICATE_BASE = 'site-duplicate-base';
    private const CODE_ERROR_HANDLING_MISSING_CODE = 'site-errorHandling-missing-code';

    public function name(): string
    {
        return 'site-config';
    }

    public function suite(): string
    {
        return 'site';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $sitesDir = $context->projectRoot . '/config/sites';
        if (!is_dir($sitesDir)) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No config/sites directory found',
            );
        }

        $files = glob($sitesDir . '/*/config.yaml');
        if ($files === false || $files === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No site config.yaml files found',
            );
        }

        $failures = [];
        foreach ($files as $file) {
            $relativeFile = $this->relativePath($file, $context->projectRoot);
            $failures = [...$failures, ...$this->checkSiteConfig($file, $relativeFile)];
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d site config issue(s) found', count($failures)),
                ['files_checked' => (string) count($files)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d site config(s) valid', count($files)),
            ['files_checked' => (string) count($files)],
        );
    }

    /**
     * @return list<Failure>
     */
    private function checkSiteConfig(string $file, string $relativeFile): array
    {
        $failures = [];

        // Parse YAML
        try {
            $config = Yaml::parseFile($file);
        } catch (ParseException $e) {
            return [
                new Failure(
                    self::CODE_YAML_INVALID,
                    sprintf('Invalid YAML in %s: %s', $relativeFile, $e->getMessage()),
                    $relativeFile,
                ),
            ];
        }

        if (!is_array($config)) {
            return [
                new Failure(
                    self::CODE_YAML_INVALID,
                    sprintf('YAML in %s does not produce an array', $relativeFile),
                    $relativeFile,
                ),
            ];
        }

        // Check rootPageId
        if (!isset($config['rootPageId'])) {
            $failures[] = new Failure(
                self::CODE_MISSING_ROOT_PAGE_ID,
                sprintf('Missing rootPageId in %s', $relativeFile),
                $relativeFile,
            );
        }

        // Check top-level base
        $base = $config['base'] ?? null;
        if ($base === null) {
            $failures[] = new Failure(
                self::CODE_MISSING_BASE,
                sprintf('Missing base in %s', $relativeFile),
                $relativeFile,
            );
        } elseif (is_array($base) && !$this->hasStringKeys($base)) {
            // base is a list — could be per-language?
            $failures[] = new Failure(
                self::CODE_MISSING_BASE,
                sprintf('Invalid base format in %s (expected string or language map)', $relativeFile),
                $relativeFile,
            );
        } elseif (is_array($base) && $this->hasStringKeys($base)) {
            $failures = [...$failures, ...$this->checkLanguageBases($base, $relativeFile)];
        }

        // Check TYPO3 language base values for duplicates.
        $languages = $config['languages'] ?? [];
        if (is_array($languages)) {
            $languageBases = [];
            foreach ($languages as $index => $language) {
                if (!is_array($language)) {
                    continue;
                }

                $languageTitle = (string) ($language['title'] ?? $language['languageId'] ?? $index);
                $languageBase = (string) ($language['base'] ?? '');
                if ($languageBase === '') {
                    continue;
                }

                $languageBases[$languageTitle] = $languageBase;
            }

            $failures = [...$failures, ...$this->checkLanguageBases($languageBases, $relativeFile)];
        }

        // Check errorHandling entries
        $errorHandling = $config['errorHandling'] ?? [];
        if (is_array($errorHandling)) {
            foreach ($errorHandling as $index => $handler) {
                if (is_array($handler) && !isset($handler['errorCode'])) {
                    $failures[] = new Failure(
                        self::CODE_ERROR_HANDLING_MISSING_CODE,
                        sprintf(
                            'errorHandling entry %d in %s is missing errorCode (has: %s)',
                            $index,
                            $relativeFile,
                            implode(', ', array_keys($handler)),
                        ),
                        $relativeFile,
                        ['handler_index' => (string) $index],
                    );
                }
            }
        }

        return $failures;
    }

    /**
     * Check per-language base values for duplicates.
     *
     * @param array<string, string> $languageBases language => base URL
     * @return list<Failure>
     */
    private function checkLanguageBases(array $languageBases, string $relativeFile): array
    {
        $failures = [];
        $seen = [];

        foreach ($languageBases as $language => $baseValue) {
            $baseValue = (string) $baseValue;
            if ($baseValue === '') {
                $failures[] = new Failure(
                    self::CODE_MISSING_BASE,
                    sprintf('Empty base for language "%s" in %s', $language, $relativeFile),
                    $relativeFile,
                    ['language' => $language],
                );
                continue;
            }

            if (isset($seen[$baseValue])) {
                $failures[] = new Failure(
                    self::CODE_DUPLICATE_BASE,
                    sprintf(
                        'Duplicate base "%s" for languages "%s" and "%s" in %s',
                        $baseValue,
                        $seen[$baseValue],
                        $language,
                        $relativeFile,
                    ),
                    $relativeFile,
                    ['base' => $baseValue, 'language1' => $seen[$baseValue], 'language2' => $language],
                );
            } else {
                $seen[$baseValue] = $language;
            }
        }

        return $failures;
    }

    /**
     * Check if array has string keys (associative).
     */
    private function hasStringKeys(array $array): bool
    {
        foreach (array_keys($array) as $key) {
            if (is_string($key)) {
                return true;
            }
        }
        return false;
    }

    private function relativePath(string $absolutePath, string $projectRoot): string
    {
        $projectRoot = rtrim($projectRoot, '/') . '/';
        if (str_starts_with($absolutePath, $projectRoot)) {
            return substr($absolutePath, strlen($projectRoot));
        }
        return $absolutePath;
    }
}
