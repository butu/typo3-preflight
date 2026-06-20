<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Static\ComposerCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;
use WEBprofil\Typo3Preflight\Runner\ProcessResult;
use WEBprofil\Typo3Preflight\Runner\ProcessRunner;

final class ComposerCheckTest extends TestCase
{
    #[Test]
    public function validate_warnings_are_reported_as_separate_failures(): void
    {
        $runner = new class implements ProcessRunner {
            public function run(string $command, ?string $cwd = null, int $timeout = 120): ProcessResult
            {
                if (str_contains($command, 'validate')) {
                    return new ProcessResult(1, '', "./composer.json is valid, but with warnings\n- require.foo/bar : unbound version constraints (@dev) should be avoided\n- require.baz/qux : unbound version constraints (@dev) should be avoided\n");
                }

                return new ProcessResult(0, 'ok', '');
            }
        };

        $result = (new ComposerCheck($runner))->run(new ProjectContext('/project', []));

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(2, $result->failures);
        $this->assertStringStartsWith('composer.json#validate-', $result->failures[0]->file);
        $this->assertNotSame($result->failures[0]->file, $result->failures[1]->file);
    }

    #[Test]
    public function successful_validate_and_dry_run_passes(): void
    {
        $runner = new class implements ProcessRunner {
            public function run(string $command, ?string $cwd = null, int $timeout = 120): ProcessResult
            {
                return new ProcessResult(0, 'ok', '');
            }
        };

        $result = (new ComposerCheck($runner))->run(new ProjectContext('/project', []));

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
    }
}
