<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Check\Extensions;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Check\Extensions\ExtensionIntegrityCheck;
use WEBprofil\Typo3Preflight\CheckStatus;
use WEBprofil\Typo3Preflight\Project\ProjectContext;

final class ExtensionIntegrityCheckTest extends TestCase
{
    private function fixturePath(string $name): string
    {
        return __DIR__ . '/../../../Fixtures/extensions/' . $name;
    }

    #[Test]
    public function skipsWhenNoPackagesDirectoryExists(): void
    {
        $tmpDir = sys_get_temp_dir() . '/preflight-test-skip-' . uniqid();
        mkdir($tmpDir);

        try {
            $result = (new ExtensionIntegrityCheck())->run(
                new ProjectContext($tmpDir, []),
            );

            $this->assertSame(CheckStatus::Skip, $result->status);
            $this->assertStringContainsString('No local extension packages', $result->message);
        } finally {
            // Cleanup
            if (is_dir($tmpDir)) {
                rmdir($tmpDir);
            }
        }
    }

    #[Test]
    public function passesOnValidExtension(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('valid'), []),
        );

        $this->assertSame(CheckStatus::Pass, $result->status);
        $this->assertCount(0, $result->failures);
        $this->assertStringContainsString('passed integrity checks', $result->message);
    }

    #[Test]
    public function skipsPackagesThatAreNotTypo3Extensions(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('library-only'), []),
        );

        $this->assertSame(CheckStatus::Skip, $result->status);
        $this->assertStringContainsString('No local TYPO3 extension packages', $result->message);
    }

    #[Test]
    public function failsOnInvalidType(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('invalid-type'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-invalid-type', $result->failures[0]->code);
        $this->assertStringContainsString('bad_type', $result->failures[0]->message);
    }

    #[Test]
    public function failsOnMissingExtensionKey(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('missing-key'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-missing-key', $result->failures[0]->code);
    }

    #[Test]
    public function failsOnMissingPsr4Path(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('missing-psr4-path'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-psr4-path-missing', $result->failures[0]->code);
        $this->assertStringContainsString('NonExistent', $result->failures[0]->message);
    }

    #[Test]
    public function failsOnMissingServicesYaml(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('missing-services'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-services-missing', $result->failures[0]->code);
    }

    #[Test]
    public function failsOnInvalidServicesPath(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('invalid-services-path'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-services-path-missing', $result->failures[0]->code);
        $this->assertStringContainsString('NonExistent', $result->failures[0]->message);
    }

    #[Test]
    public function failsOnInvalidComposerJson(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('invalid-json'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-composer-json-invalid', $result->failures[0]->code);
    }

    #[Test]
    public function failsOnInvalidServicesYaml(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('invalid-services-yaml'), []),
        );

        $this->assertSame(CheckStatus::Fail, $result->status);
        $this->assertCount(1, $result->failures);
        $this->assertSame('extension-services-yaml-invalid', $result->failures[0]->code);
    }

    #[Test]
    public function serviceFileResourceCanPointToExistingFile(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('service-file-resource'), []),
        );

        $this->assertSame(CheckStatus::Pass, $result->status);
    }

    #[Test]
    public function failureCodesAreStable(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('missing-key'), []),
        );

        $this->assertSame('extension-missing-key', $result->failures[0]->code);
        $this->assertNotEmpty($result->failures[0]->message);
        $this->assertNotEmpty($result->failures[0]->file);
    }

    #[Test]
    public function failMessageReportsCount(): void
    {
        $result = (new ExtensionIntegrityCheck())->run(
            new ProjectContext($this->fixturePath('invalid-type'), []),
        );

        $this->assertStringContainsString('1 extension integrity failure', $result->message);
    }
}
