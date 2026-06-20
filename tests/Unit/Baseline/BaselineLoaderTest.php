<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Baseline;

use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Baseline\BaselineLoader;

final class BaselineLoaderTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function loads_baseline_entries_from_json_file(): void
    {
        $loader = new BaselineLoader();
        $entries = $loader->load(__DIR__ . '/../../Fixtures/baseline');

        $this->assertCount(2, $entries);

        $this->assertSame('abc123def456', $entries[0]->fingerprint);
        $this->assertSame('composer', $entries[0]->check);
        $this->assertSame('composer validate failed', $entries[0]->message);
        $this->assertSame('known dependency issue', $entries[0]->reason);

        $this->assertSame('789ghi012jkl', $entries[1]->fingerprint);
        $this->assertSame('typo3-boot', $entries[1]->check);
        $this->assertSame('', $entries[1]->reason);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returns_empty_array_for_non_existent_directory(): void
    {
        $loader = new BaselineLoader();
        $entries = $loader->load(__DIR__ . '/../../Fixtures/non-existent');

        $this->assertSame([], $entries);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function returns_empty_array_for_empty_directory(): void
    {
        $loader = new BaselineLoader();
        $entries = $loader->load(__DIR__ . '/../../Fixtures/configs/empty-project');

        $this->assertSame([], $entries);
    }
}
