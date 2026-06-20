<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Tests\Unit\Baseline;

use PHPUnit\Framework\TestCase;
use WEBprofil\Typo3Preflight\Baseline\Fingerprint;

final class FingerprintTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\Test]
    public function same_inputs_produce_same_fingerprint(): void
    {
        $fp = new Fingerprint();

        $a = $fp->compute('composer', 'composer-validate', 'composer.json');
        $b = $fp->compute('composer', 'composer-validate', 'composer.json');

        $this->assertSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function different_check_names_produce_different_fingerprints(): void
    {
        $fp = new Fingerprint();

        $a = $fp->compute('composer', 'code-x', 'composer.json');
        $b = $fp->compute('typo3-boot', 'code-x', 'composer.json');

        $this->assertNotSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function different_codes_produce_different_fingerprints(): void
    {
        $fp = new Fingerprint();

        $a = $fp->compute('check', 'error-1', 'file.php');
        $b = $fp->compute('check', 'error-2', 'file.php');

        $this->assertNotSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function different_files_produce_different_fingerprints(): void
    {
        $fp = new Fingerprint();

        $a = $fp->compute('check', 'code', 'file-a.php');
        $b = $fp->compute('check', 'code', 'file-b.php');

        $this->assertNotSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function empty_file_path_is_allowed(): void
    {
        $fp = new Fingerprint();

        $result = $fp->compute('check', 'code', '');
        $this->assertNotEmpty($result);
        $this->assertIsString($result);
        $this->assertSame(64, strlen($result)); // sha256 hex
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function computeSimple_is_equivalent_to_compute_with_empty_file(): void
    {
        $fp = new Fingerprint();

        $a = $fp->computeSimple('check', 'code');
        $b = $fp->compute('check', 'code', '');

        $this->assertSame($a, $b);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function fingerprints_are_stable_sha256_hex(): void
    {
        $fp = new Fingerprint();
        $result = $fp->compute('my-check', 'my-code', 'path/to/file.php');

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result);
    }
}
