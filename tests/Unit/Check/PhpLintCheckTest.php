<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Static\PhpLintCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\SymfonyProcessRunner;

final class PhpLintCheckTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../Fixtures/php-files';
    }

    #[Test]
    public function valid_php_passes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/valid', []);
        $check = new PhpLintCheck(new SymfonyProcessRunner());
        $result = $check->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
    }

    #[Test]
    public function syntax_error_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/syntax-error', []);
        $check = new PhpLintCheck(new SymfonyProcessRunner());
        $result = $check->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertNotEmpty($result->failures);
    }

    #[Test]
    public function no_php_files_skips(): void
    {
        $context = new ProjectContext('/tmp/nonexistent-project-php-lint', []);
        $check = new PhpLintCheck(new SymfonyProcessRunner());
        $result = $check->run($context);

        $this->assertSame(CheckStatus::Skip, $result->status);
    }
}
