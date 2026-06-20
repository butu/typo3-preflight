<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Baseline;

use WEBprofil\Typo3Preflight\Check\CheckResult;
use WEBprofil\Typo3Preflight\Check\Failure;
use WEBprofil\Typo3Preflight\CheckStatus;

/**
 * Compares check results against baselined entries.
 *
 * - Failures with a matching baseline fingerprint are reclassified to skip/baselined.
 * - Baseline entries not matched by any current failure are reported as stale (info).
 */
final class BaselineComparator
{
    public function __construct(
        private readonly Fingerprint $fingerprint = new Fingerprint(),
    ) {
    }

    /**
     * Compare results against baseline entries.
     *
     * Returns a modified list of CheckResult objects where baselined failures
     * are reclassified and stale baseline entries are appended as info results.
     *
     * @param CheckResult[]    $results
     * @param BaselineEntry[]  $baselineEntries
     * @return CheckResult[]
     */
    public function compare(array $results, array $baselineEntries): array
    {
        if ($baselineEntries === []) {
            return $results;
        }

        // Build a lookup: fingerprint -> BaselineEntry
        $baselineByFingerprint = [];
        foreach ($baselineEntries as $entry) {
            $baselineByFingerprint[$entry->fingerprint] = $entry;
        }

        // Track which baseline entries are matched (to detect stale later)
        $matchedFingerprints = [];

        $processed = [];
        foreach ($results as $result) {
            if ($result->status !== CheckStatus::Fail || $result->failures === []) {
                $processed[] = $result;
                continue;
            }

            $unmatched = [];
            $hasBaselinedFailures = false;

            foreach ($result->failures as $failure) {
                $fp = $this->fingerprint->compute($result->check, $failure->code, $failure->file);

                if (isset($baselineByFingerprint[$fp])) {
                    $matchedFingerprints[] = $fp;
                    $hasBaselinedFailures = true;
                } else {
                    $unmatched[] = $failure;
                }
            }

            if ($unmatched === []) {
                // All failures baselined — skip this check
                $processed[] = new CheckResult(
                    $result->suite,
                    $result->check,
                    CheckStatus::Skip,
                    'All failures baselined.',
                    ['baselined' => 'true'],
                    [],
                );
            } elseif ($hasBaselinedFailures) {
                // Some baselined, some not — keep as fail with only unmatched
                $processed[] = new CheckResult(
                    $result->suite,
                    $result->check,
                    CheckStatus::Fail,
                    $result->message . ' (some failures baselined)',
                    array_merge($result->details, ['baselined_count' => (string) (count($result->failures) - count($unmatched))]),
                    $unmatched,
                );
            } else {
                // No baselining applied
                $processed[] = $result;
            }
        }

        // Report stale baseline entries
        foreach ($baselineEntries as $entry) {
            if (!in_array($entry->fingerprint, $matchedFingerprints, true)) {
                $processed[] = new CheckResult(
                    'baseline',
                    'baseline-stale',
                    CheckStatus::Skip,
                    'Stale baseline entry: ' . ($entry->message ?: $entry->fingerprint),
                    [
                        'fingerprint' => $entry->fingerprint,
                        'check' => $entry->check,
                        'reason' => $entry->reason,
                    ],
                    [],
                );
            }
        }

        return $processed;
    }
}
