<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Output\TextFormatter;

final class TextFormatterTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function formats_multiple_results_with_suite_grouping(): void
    {
        $results = [
            new CheckResult('static', 'composer', CheckStatus::Pass, 'composer ok'),
            new CheckResult('runtime', 'typo3-boot', CheckStatus::Fail, 'typo3 failed', [], [
                new Failure('boot-error', 'TYPO3 crash', ''),
            ]),
        ];

        $output = new BufferedOutput();
        $formatter = new TextFormatter();
        $formatter->format($results, $output);

        $text = $output->fetch();

        $this->assertStringContainsString('wp-typo3-preflight', $text);
        $this->assertStringContainsString('Suite: static', $text);
        $this->assertStringContainsString('Suite: runtime', $text);
        $this->assertStringContainsString('[PASS]', $text);
        $this->assertStringContainsString('[FAIL]', $text);
        $this->assertStringContainsString('Exit code:', $text);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function all_pass_results_show_exit_code_0(): void
    {
        $results = [
            new CheckResult('static', 'check-a', CheckStatus::Pass, 'good'),
            new CheckResult('runtime', 'check-b', CheckStatus::Pass, 'good'),
        ];

        $output = new BufferedOutput();
        $formatter = new TextFormatter();
        $formatter->format($results, $output);

        $text = $output->fetch();

        $this->assertStringContainsString('Exit code: 0', $text);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fail_results_show_exit_code_1(): void
    {
        $results = [
            new CheckResult('static', 'check-a', CheckStatus::Fail, 'bad'),
        ];

        $output = new BufferedOutput();
        $formatter = new TextFormatter();
        $formatter->format($results, $output);

        $text = $output->fetch();

        $this->assertStringContainsString('Exit code: 1', $text);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function error_results_show_exit_code_2_when_no_fails(): void
    {
        $results = [
            new CheckResult('static', 'check-a', CheckStatus::Error, 'env issue'),
        ];

        $output = new BufferedOutput();
        $formatter = new TextFormatter();
        $formatter->format($results, $output);

        $text = $output->fetch();

        $this->assertStringContainsString('Exit code: 2', $text);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fail_dominates_error_in_exit_code(): void
    {
        $results = [
            new CheckResult('static', 'check-a', CheckStatus::Fail, 'bad'),
            new CheckResult('runtime', 'check-b', CheckStatus::Error, 'env issue'),
        ];

        $output = new BufferedOutput();
        $formatter = new TextFormatter();
        $formatter->format($results, $output);

        $text = $output->fetch();

        $this->assertStringContainsString('Exit code: 1', $text);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function skip_results_are_shown_with_skip_icon(): void
    {
        $results = [
            new CheckResult('runtime', 'frontend-smoke', CheckStatus::Skip, 'no URLs configured'),
        ];

        $output = new BufferedOutput();
        $formatter = new TextFormatter();
        $formatter->format($results, $output);

        $text = $output->fetch();

        $this->assertStringContainsString('[SKIP]', $text);
        $this->assertStringContainsString('Exit code: 0', $text);
    }
}
