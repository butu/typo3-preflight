<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight;

enum CheckStatus: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Skip = 'skip';
    case Error = 'error';

    /**
     * True when this status counts as a successful outcome.
     */
    public function isOk(): bool
    {
        return $this === self::Pass || $this === self::Skip;
    }
}
