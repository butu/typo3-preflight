<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Baseline;

/**
 * Computes stable fingerprints for check failures.
 *
 * Fingerprint = sha256(checkName | errorCode | filePath)
 * No line numbers or timestamps are included, so the fingerprint
 * remains stable across minor code movements and re-runs.
 */
final class Fingerprint
{
    /**
     * Compute a fingerprint for a check failure.
     */
    public function compute(string $checkName, string $errorCode, string $filePath = ''): string
    {
        $input = implode('|', [$checkName, $errorCode, $filePath]);
        return hash('sha256', $input);
    }

    /**
     * Compute a fingerprint for a named check (no file).
     */
    public function computeSimple(string $checkName, string $errorCode): string
    {
        return $this->compute($checkName, $errorCode, '');
    }
}
