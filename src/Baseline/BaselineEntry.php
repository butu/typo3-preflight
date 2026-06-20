<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Baseline;

/**
 * A single entry in a baseline file.
 */
final class BaselineEntry
{
    /**
     * @param string $fingerprint Stable fingerprint
     * @param string $check       Check name
     * @param string $message     Human-readable description of the known issue
     * @param string $reason      Optional reason why this is baselined
     */
    public function __construct(
        public readonly string $fingerprint,
        public readonly string $check,
        public readonly string $message = '',
        public readonly string $reason = '',
    ) {
    }
}
