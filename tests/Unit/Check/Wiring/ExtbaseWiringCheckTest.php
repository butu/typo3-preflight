<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check\Wiring;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Wiring\ExtbaseWiringCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class ExtbaseWiringCheckTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/../../../Fixtures/wiring';
    }

    #[Test]
    public function valid_wiring_passes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/valid', []);
        $result = (new ExtbaseWiringCheck())->run($context);

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertSame([], $result->failures);
    }

    #[Test]
    public function unregistered_action_fails(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/mismatch', []);
        $result = (new ExtbaseWiringCheck())->run($context);

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertNotEmpty($result->failures);
        $this->assertStringContainsString('detail', $result->failures[0]->message);
    }

    #[Test]
    public function failures_have_stable_codes(): void
    {
        $context = new ProjectContext($this->fixturesDir . '/mismatch', []);
        $result = (new ExtbaseWiringCheck())->run($context);

        foreach ($result->failures as $failure) {
            $this->assertSame('wiring-unregistered-action', $failure->code);
            $this->assertNotEmpty($failure->file);
        }
    }
}
