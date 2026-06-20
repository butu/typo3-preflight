<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Output;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Output\JsonFormatter;

final class JsonFormatterTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function formats_results_as_json_array(): void
    {
        $results = [
            new CheckResult('static', 'composer', CheckStatus::Pass, 'all ok'),
            new CheckResult('runtime', 'typo3-boot', CheckStatus::Fail, 'boot failed', ['detail' => 'value'], [
                new Failure('code-x', 'msg', 'file.php', ['stderr' => 'error output']),
            ]),
        ];

        $output = new BufferedOutput();
        $formatter = new JsonFormatter();
        $formatter->format($results, $output);

        $json = $output->fetch();
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertCount(2, $data);

        // First result: pass
        $this->assertSame('static', $data[0]['suite']);
        $this->assertSame('composer', $data[0]['check']);
        $this->assertSame('pass', $data[0]['status']);
        $this->assertSame('all ok', $data[0]['message']);
        $this->assertArrayNotHasKey('failures', $data[0]);

        // Second result: fail with failures
        $this->assertSame('runtime', $data[1]['suite']);
        $this->assertSame('fail', $data[1]['status']);
        $this->assertSame('value', $data[1]['details']['detail']);
        $this->assertCount(1, $data[1]['failures']);
        $this->assertSame('code-x', $data[1]['failures'][0]['code']);
        $this->assertSame('msg', $data[1]['failures'][0]['message']);
        $this->assertSame('file.php', $data[1]['failures'][0]['file']);
        $this->assertSame('error output', $data[1]['failures'][0]['context']['stderr']);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function empty_results_produce_empty_json_array(): void
    {
        $results = [];

        $output = new BufferedOutput();
        $formatter = new JsonFormatter();
        $formatter->format($results, $output);

        $json = $output->fetch();
        $data = json_decode($json, true);

        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function output_is_valid_json(): void
    {
        $results = [
            new CheckResult('suite', 'check', CheckStatus::Skip, 'skipped', [], []),
        ];

        $output = new BufferedOutput();
        $formatter = new JsonFormatter();
        $formatter->format($results, $output);

        $json = $output->fetch();
        $data = json_decode($json, true);

        $this->assertNotNull($data, 'Output is valid JSON');
        $this->assertSame('skip', $data[0]['status']);
    }
}
