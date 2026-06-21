<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Static\ArchitectureSqlCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class ArchitectureSqlCheckTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../Fixtures/architecture';
    }

    #[Test]
    public function sql_in_model_and_controller_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/violation', []);
        $result = (new ArchitectureSqlCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertNotEmpty($result->failures);
        // Should find at least 2 violations (one in Model, one in Controller)
        $this->assertGreaterThanOrEqual(2, count($result->failures));
    }

    #[Test]
    public function clean_models_pass(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/clean', []);
        $result = (new ArchitectureSqlCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
    }

    #[Test]
    public function no_target_files_skips(): void
    {
        $context = new ProjectContext('/tmp/nonexistent-project-arch', []);
        $result = (new ArchitectureSqlCheck())->run($context);

        $this->assertSame(CheckStatus::Skip, $result->status);
    }

    #[Test]
    public function failures_have_stable_codes_and_files(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/violation', []);
        $result = (new ArchitectureSqlCheck())->run($context);

        foreach ($result->failures as $failure) {
            $this->assertSame('architecture-sql', $failure->code);
            $this->assertNotEmpty($failure->file);
            $this->assertNotEmpty($failure->message);
        }
    }
}
