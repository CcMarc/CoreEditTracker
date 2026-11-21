<?php
/**
 * Module: CoreEditTracker
 *
 * @requires    Zen Cart 2.1.0 or later, PHP 8.0+ with the zip extension
 * @author      Marcopolo
 * @copyright   2026
 * @license     GNU General Public License (GPL) - https://www.zen-cart.com/license/2_0.txt
 * @version     1.0.0
 * @updated     11-21-2026
 * @github      https://github.com/CcMarc/CoreEditTracker
 */
namespace Zencart\Plugins\Admin\CoreEditTracker;

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

/**
 * Builds the export package in the system temp folder. The caller streams and deletes it.
 *
 * Every exported file is a pair under files/: the store's file at its own path and the release
 * file it came from beside it with the ORIGINAL ending. A plugin's file the owner edited also gets
 * the plugin's own copy with the PLUGIN ending. The release's sample configure files go under
 * reference/. The store's own configure files are never read. Files are added from disk, so memory does
 * not grow with the package.
 */
class ExportBuilder
{
    public const ORIGINAL_ENDING = '.ORIGINAL';
    public const PLUGIN_ENDING = '.PLUGIN';
    public const ROOT = 'files/';
    public const README_NAME = 'README.md';
    public const REFERENCE_ROOT = 'reference/';

    // The release's sample configure files. They hold no credentials and show which settings a
    // release expects, so a newer release's new settings can be found without the store's own files.
    public const REFERENCE_PATHS = ['includes/dist-configure.php', 'admin/includes/dist-configure.php'];

    /**
     * Decides for one scan row whether it is exported. Returns 'include', or the reason it is left
     * out: not_listed, credential, not_on_store, owned, no_original, identical_copy, may_hold_secret,
     * name_clash.
     */
    public static function decide(string $path, array $row, array $options): string
    {
        $group = $row['g'] ?? '';
        if ($group === 'missing') {
            return PathRules::isCredentialPath($path) ? 'credential' : 'not_on_store';
        }
        if ($group !== 'edit' && $group !== 'override') {
            return 'not_listed';
        }
        if (PathRules::isCredentialPath($path)) {
            return 'credential';
        }
        // A plugin's file is the plugin's to update. It is exported only when the store's file differs
        // from the plugin's own verified copy, because that difference is the owner's edit.
        $editedOnTop = ($row['bv'] ?? 0) === 1 && ($row['bs'] ?? '') === 'differs';
        if (isset($row['own']) && !$editedOnTop) {
            return 'owned';
        }
        if ($group === 'override' && ($row['def'] ?? '') === '') {
            return 'no_original';
        }
        // An override that is an exact copy of the release file holds no edit. It is listed so the
        // newer release's file can take its place, since the old copy would otherwise stay in use.
        if (!empty($row['same'])) {
            return 'identical_copy';
        }
        if (!empty($row['sec']) && empty($options['include_secrets'])) {
            return 'may_hold_secret';
        }
        foreach ([self::ORIGINAL_ENDING, self::PLUGIN_ENDING] as $ending) {
            if (strcasecmp(substr($path, -strlen($ending)), $ending) === 0) {
                return 'name_clash';
            }
        }
        return 'include';
    }

    /**
     * Counts what an export of this scan would hold, without building it. 'on_top' counts the plugin
     * files exported because the store's copy differs from the plugin's own.
     */
    public static function plan(array $scan, array $options): array
    {
        $plan = ['edit' => 0, 'override' => 0, 'on_top' => 0, 'owned' => 0, 'no_original' => 0, 'identical_copy' => 0, 'may_hold_secret' => 0, 'not_on_store' => 0, 'secret_paths' => []];
        foreach ($scan['rows'] ?? [] as $path => $row) {
            $decision = self::decide((string)$path, $row, $options);
            if ($decision === 'include') {
                // A plugin's file that carries the owner's edit is counted apart from the owner's own files.
                $plan[isset($row['own']) ? 'on_top' : $row['g']]++;
            } elseif (isset($plan[$decision])) {
                $plan[$decision]++;
                if ($decision === 'may_hold_secret' && count($plan['secret_paths']) < 20) {
                    $plan['secret_paths'][] = (string)$path;
                }
            }
        }
        return $plan;
    }

    /**
     * Options: include_secrets, readme (the README text), readme_plugin and readme_configure (its parts on plugin files and on
     * the configure files), plugins (installed plugin
     * key => version), templates. Returns ['file', 'name', 'listed', 'entries'] or null.
     */
    public static function build(array $scan, StoreLayout $layout, ReleaseZip $zip, array $companion, string $version, array $options, array $releaseFiles = []): ?array
    {
        $file = tempnam(sys_get_temp_dir(), 'cet');
        if ($file === false) {
            return null;
        }
        $out = new \ZipArchive();
        if ($out->open($file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            if (is_file($file)) {
                unlink($file);
            }
            return null;
        }
        $tmpDir = $file . '.d';
        if (!mkdir($tmpDir, 0700)) {
            $out->close();
            unlink($file);
            return null;
        }
        $tmpCount = 0;

        $entries = [];
        $listed = [];
        $leftOut = [];
        foreach ($scan['rows'] ?? [] as $path => $row) {
            $path = (string)$path;
            $decision = self::decide($path, $row, $options);
            if ($decision === 'not_listed' || $decision === 'credential') {
                continue;
            }
            if ($decision !== 'include') {
                $entry = ['path' => $path, 'group' => $row['g'], 'reason' => $decision, 'owner' => $row['own'] ?? null];
                if ($decision === 'identical_copy') {
                    $entry['original_from'] = (string)$row['def'];
                }
                $leftOut[] = $entry;
                continue;
            }
            $original = $row['g'] === 'override' ? (string)$row['def'] : $path;
            $check = $layout->storeFile($path);
            if (!isset($check['file']) || filesize($check['file']) > ReleaseZip::MAX_ENTRY_BYTES) {
                $leftOut[] = ['path' => $path, 'group' => $row['g'], 'reason' => 'unreadable', 'owner' => $row['own'] ?? null];
                continue;
            }
            $originalData = $zip->read($original);
            if ($originalData === null) {
                $leftOut[] = ['path' => $path, 'group' => $row['g'], 'reason' => 'unreadable', 'owner' => $row['own'] ?? null];
                continue;
            }
            $storeEntry = self::ROOT . $path;
            $originalEntry = $storeEntry . self::ORIGINAL_ENDING;
            $tmp = $tmpDir . '/' . (++$tmpCount);
            file_put_contents($tmp, $originalData);
            unset($originalData);
            $out->addFile($check['file'], $storeEntry);
            $out->addFile($tmp, $originalEntry);
            $entries[$storeEntry] = true;
            $entries[$originalEntry] = true;

            $item = [
                'path' => $path,
                'group' => $row['g'],
                'file' => $storeEntry,
                'original' => $originalEntry,
                'original_from' => $original,
                'sha256_file' => (string)hash_file('sha256', $check['file']),
                'sha256_original' => $releaseFiles[$original][1] ?? null,
                'lines_added' => $row['a'] ?? null,
                'lines_removed' => $row['d'] ?? null,
                'line_endings_only' => !empty($row['eol']),
            ];
            if (isset($row['own'])) {
                $item['owner'] = $row['own'];
                if (isset($row['note'])) {
                    $item['owner_note'] = $row['note'];
                }
                $base = $companion['owned'][$path]['base'] ?? null;
                if ($base !== null && $base['verified'] && hash_equals($base['sha256'], (string)hash_file('sha256', $base['file']))) {
                    $pluginEntry = $storeEntry . self::PLUGIN_ENDING;
                    $out->addFile($base['file'], $pluginEntry);
                    $entries[$pluginEntry] = true;
                    $item['plugin_copy'] = $pluginEntry;
                    $item['sha256_plugin_copy'] = $base['sha256'];
                }
            }
            if (isset($row['mk'])) {
                $item['markers'] = array_map(static fn($m) => $m['n'], $row['mk']);
            }
            $listed[] = $item;
        }

        $reference = [];
        foreach (self::REFERENCE_PATHS as $path) {
            $data = isset($releaseFiles[$path]) ? $zip->read($path) : null;
            if ($data !== null) {
                $tmp = $tmpDir . '/' . (++$tmpCount);
                file_put_contents($tmp, $data);
                $out->addFile($tmp, self::REFERENCE_ROOT . $path);
                $entries[self::REFERENCE_ROOT . $path] = true;
                $reference[] = self::REFERENCE_ROOT . $path;
            }
        }

        $manifest = [
            'package' => 'core-edit-tracker-export',
            'format' => 2,
            'zen_cart_version' => $version,
            'created' => gmdate('Y-m-d\TH:i:s\Z'),
            'templates' => array_values($options['templates'] ?? []),
            'plugins' => $options['plugins'] ?? [],
            'original_ending' => self::ORIGINAL_ENDING,
            'plugin_ending' => self::PLUGIN_ENDING,
            'files' => $listed,
            'left_out' => $leftOut,
            'reference' => $reference,
        ];
        $out->addFromString('MANIFEST.json', (string)json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // The part of the README about plugin files is only added when the package has any.
        $pluginFiles = array_filter($listed, static fn($item) => isset($item['owner']))
            || array_filter($leftOut, static fn($item) => $item['reason'] === 'owned');
        $readme = str_replace(
            ['{plugin_section}', '{configure_section}'],
            [$pluginFiles ? (string)($options['readme_plugin'] ?? '') : '', $reference !== [] ? (string)($options['readme_configure'] ?? '') : ''],
            (string)($options['readme'] ?? '')
        );
        if ($readme !== '') {
            $out->addFromString(self::README_NAME, $readme);
        }
        $closed = $out->close();
        foreach ((array)glob($tmpDir . '/*') as $tmp) {
            unlink($tmp);
        }
        rmdir($tmpDir);
        if (!$closed) {
            unlink($file);
            return null;
        }

        return [
            'file' => $file,
            'name' => 'core-edits-zen-cart-' . preg_replace('~[^0-9.]~', '', $version) . '-' . gmdate('Ymd-His') . '.zip',
            'listed' => $listed,
            'left_out' => $leftOut,
            'entries' => array_keys($entries),
        ];
    }
}
