<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Check\Runtime;

use WEBprofil\Typo3Preflight\Check\CheckInterface;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Http\HttpClientInterface;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

/**
 * Smoke-tests frontend URLs via HTTP.
 *
 * - Uses base_url from config or DDEV_PRIMARY_URL env var.
 * - Skips when no URLs are configured.
 */
final class FrontendSmokeCheck implements CheckInterface
{
    private const CODE = 'frontend-smoke';

    public function __construct(
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function name(): string
    {
        return 'frontend-smoke';
    }

    public function suite(): string
    {
        return 'runtime';
    }

    public function run(ProjectContext $context): CheckResult
    {
        $baseUrl = $context->baseUrl();
        $urls = $context->urls();

        if ($baseUrl === '' || $baseUrl === null) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No base URL configured (set base_url in wp-typo3-preflight.yml or ensure DDEV_PRIMARY_URL is set)',
            );
        }

        if ($urls === []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Skip,
                'No frontend smoke URLs configured (set urls in wp-typo3-preflight.yml)',
            );
        }

        $urlsToCheck = $urls;
        $failures = [];

        foreach ($urlsToCheck as $path) {
            $url = rtrim($baseUrl, '/') . '/' . ltrim($path, '/');
            $response = $this->httpClient->get($url);

            if ($response->statusCode === 0) {
                $failures[] = new Failure(
                    self::CODE,
                    'HTTP request failed: ' . $url,
                    $url,
                    ['error' => $this->truncate($response->body, 300)],
                );
            } elseif ($response->statusCode >= 500) {
                $failures[] = new Failure(
                    self::CODE,
                    sprintf('HTTP %d for %s', $response->statusCode, $url),
                    $url,
                    ['status_code' => (string) $response->statusCode],
                );
            }
        }

        if ($failures !== []) {
            return new CheckResult(
                $this->suite(),
                $this->name(),
                CheckStatus::Fail,
                sprintf('%d frontend URL(s) failed', count($failures)),
                ['checked_urls' => (string) count($urlsToCheck)],
                $failures,
            );
        }

        return new CheckResult(
            $this->suite(),
            $this->name(),
            CheckStatus::Pass,
            sprintf('%d frontend URL(s) responded OK', count($urlsToCheck)),
            ['checked_urls' => (string) count($urlsToCheck)],
        );
    }

    private function truncate(string $text, int $maxLen): string
    {
        if (strlen($text) <= $maxLen) {
            return $text;
        }
        return substr($text, 0, $maxLen) . '…';
    }
}
