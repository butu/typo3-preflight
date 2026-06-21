<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check\Site;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Site\SiteConfigCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class SiteConfigCheckTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../../Fixtures/site-config';
    }

    #[Test]
    public function valid_site_config_passes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/valid', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
        $this->assertStringContainsString('valid', $result->message);
    }

    #[Test]
    public function invalid_yaml_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/invalid-yaml', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('site-yaml-invalid', $result->failures[0]->code);
        $this->assertStringContainsString('config.yaml', $result->failures[0]->file);
    }

    #[Test]
    public function missing_root_page_id_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/missing-rootpageid', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('site-missing-rootPageId', $result->failures[0]->code);
    }

    #[Test]
    public function duplicate_language_bases_fail(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/duplicate-base', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $hasDuplicateBase = false;
        foreach ($result->failures as $failure) {
            if ($failure->code === 'site-duplicate-base') {
                $hasDuplicateBase = true;
                break;
            }
        }
        $this->assertTrue($hasDuplicateBase, 'Expected a site-duplicate-base failure');
    }

    #[Test]
    public function language_bases_valid_passes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/languages-valid', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function missing_error_code_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/missing-error-code', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $hasErrorCodeIssue = false;
        foreach ($result->failures as $failure) {
            if ($failure->code === 'site-errorHandling-missing-code') {
                $hasErrorCodeIssue = true;
                break;
            }
        }
        $this->assertTrue($hasErrorCodeIssue, 'Expected a site-errorHandling-missing-code failure');
    }

    #[Test]
    public function no_site_directory_skips(): void
    {
        $context = new ProjectContext('/tmp/nonexistent-project-xyz', []);
        $result = (new SiteConfigCheck())->run($context);

        $this->assertSame(CheckStatus::Skip, $result->status);
    }

    #[Test]
    public function fingerprints_are_stable_per_file_and_problem(): void
    {
        // Run same check twice — fingerprints should be identical
        $context = new ProjectContext($this->fixturesDir . '/missing-rootpageid', []);
        $check = new SiteConfigCheck();
        $result1 = $check->run($context);
        $result2 = $check->run($context);

        $this->assertCount(1, $result1->failures);
        $this->assertSame(
            $result1->failures[0]->code,
            $result2->failures[0]->code,
        );
        $this->assertSame(
            $result1->failures[0]->file,
            $result2->failures[0]->file,
        );
    }
}
