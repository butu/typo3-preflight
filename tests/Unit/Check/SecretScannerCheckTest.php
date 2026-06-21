<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Static\SecretScannerCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class SecretScannerCheckTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../Fixtures/secrets';
    }

    #[Test]
    public function files_with_secrets_fail(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/with-secret', []);
        $result = (new SecretScannerCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertNotEmpty($result->failures);
    }

    #[Test]
    public function files_without_secrets_pass(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/no-secrets', []);
        $result = (new SecretScannerCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
    }

    #[Test]
    public function allowlist_suppresses_matching_secrets(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/with-allowed-secret', [
            'secrets' => [
                'allowlist' => [
                    '/dev-password/',
                    '/test-key/',
                ],
            ],
        ]);
        $result = (new SecretScannerCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function no_scannable_files_skips(): void
    {
        $context = new ProjectContext('/tmp/nonexistent-project-secrets', []);
        $result = (new SecretScannerCheck())->run($context);

        $this->assertSame(CheckStatus::Skip, $result->status);
    }

    #[Test]
    public function empty_dir_without_secrets_passes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/no-secrets', []);
        $result = (new SecretScannerCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function failure_has_stable_code(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/with-secret', []);
        $result = (new SecretScannerCheck())->run($context);

        foreach ($result->failures as $failure) {
            $this->assertSame('secret', $failure->code);
            $this->assertNotEmpty($failure->file);
            $this->assertStringContainsString('***', $failure->message, 'Secrets should be masked in output');
        }
    }
}
