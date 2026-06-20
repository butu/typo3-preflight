<?php

declare(strict_types=1);

namespace WEBprofil\Typo3Preflight\Baseline;

/**
 * Loads baseline JSON files from the configured directory.
 *
 * Multiple *.baseline.json files are merged into one list of entries.
 */
final class BaselineLoader
{
    /**
     * Load all baseline entries from the given directory.
     *
     * @return BaselineEntry[]
     */
    public function load(string $baselinePath): array
    {
        if (!is_dir($baselinePath)) {
            return [];
        }

        $entries = [];
        $files = glob($baselinePath . '/*.baseline.json');

        if ($files === false) {
            return [];
        }

        foreach ($files as $file) {
            $loaded = $this->loadFile($file);
            $entries = array_merge($entries, $loaded);
        }

        return $entries;
    }

    /**
     * Load a single baseline JSON file.
     *
     * @return BaselineEntry[]
     */
    private function loadFile(string $path): array
    {
        if (!is_readable($path)) {
            return [];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return [];
        }

        $data = json_decode($content, true);
        if (!is_array($data)) {
            return [];
        }

        $entries = [];
        foreach ($data as $item) {
            if (!is_array($item) || !isset($item['fingerprint'])) {
                continue;
            }

            $entries[] = new BaselineEntry(
                $item['fingerprint'],
                $item['check'] ?? '',
                $item['message'] ?? '',
                $item['reason'] ?? '',
            );
        }

        return $entries;
    }
}
