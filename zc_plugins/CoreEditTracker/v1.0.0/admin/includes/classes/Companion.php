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
 * Reads what other plugins declare. A companion plugin defines a global class named
 * CoreEditTrackerHelper with two optional static methods:
 *   getOwnedPaths(): array    list of ['path', 'label', optional 'note', 'base', 'base_sha256', 'base_plugin']
 *   getMarkerEndings(): array list of endings such as '.orig' or '.bak*'
 * Every value it returns is validated here. Without a helper the tracker works the same.
 */
class Companion
{
    public const HELPER = 'CoreEditTrackerHelper';
    public const ADMIN_PLACEHOLDER = '{admin}';
    public const MAX_PATHS = 50000;
    public const MAX_ENDINGS = 50;
    public const MAX_BASE_BYTES = 4 * 1024 * 1024;

    /**
     * Returns ['present' => bool, 'owned' => [releasePath => ['label', 'note' => ?string, 'base' => ?array]], 'endings' => [], 'refused' => [[item, reason]]].
     * $pluginDirs maps an installed plugin key to its version folder.
     */
    public static function read(string $catalogDir, array $pluginDirs): array
    {
        $out = ['present' => false, 'owned' => [], 'endings' => [], 'refused' => []];
        if (!class_exists(self::HELPER)) {
            return $out;
        }
        $out['present'] = true;

        if (method_exists(self::HELPER, 'getMarkerEndings')) {
            $endings = self::call('getMarkerEndings', $out);
            foreach (array_slice($endings, 0, self::MAX_ENDINGS) as $ending) {
                if (is_string($ending) && self::validEnding($ending)) {
                    $out['endings'][strtolower($ending)] = $ending;
                } else {
                    $out['refused'][] = [is_scalar($ending) ? (string)$ending : gettype($ending), 'bad_ending'];
                }
            }
            $out['endings'] = array_values($out['endings']);
        }

        if (method_exists(self::HELPER, 'getOwnedPaths')) {
            $items = self::call('getOwnedPaths', $out);
            foreach (array_slice($items, 0, self::MAX_PATHS) as $item) {
                if (!is_array($item)) {
                    $out['refused'][] = [gettype($item), 'bad_item'];
                    continue;
                }
                $path = self::ownedPath($item['path'] ?? null);
                $label = self::cleanLabel($item['label'] ?? null);
                if ($path === null || $label === null) {
                    $out['refused'][] = [is_string($item['path'] ?? null) ? $item['path'] : '?', $path === null ? 'bad_path' : 'bad_label'];
                    continue;
                }
                $base = null;
                if (isset($item['base'])) {
                    $base = self::verifyBase(
                        $item['base'],
                        $item['base_sha256'] ?? null,
                        $item['base_plugin'] ?? null,
                        $catalogDir,
                        $pluginDirs
                    );
                }
                $note = null;
                if (isset($item['note'])) {
                    $note = self::cleanLabel($item['note'], 120);
                    if ($note === null) {
                        $out['refused'][] = [$path, 'bad_note'];
                    }
                }
                $out['owned'][$path] = ['label' => $label, 'note' => $note, 'base' => $base];
            }
        }
        return $out;
    }

    private static function call(string $method, array &$out): array
    {
        try {
            $value = call_user_func([self::HELPER, $method]);
        } catch (\Throwable $e) {
            $out['refused'][] = [$method, 'helper_error'];
            return [];
        }
        if (!is_array($value)) {
            $out['refused'][] = [$method, 'not_a_list'];
            return [];
        }
        return array_values($value);
    }

    public static function ownedPath($path): ?string
    {
        if (!is_string($path)) {
            return null;
        }
        if (str_starts_with($path, self::ADMIN_PLACEHOLDER . '/')) {
            $path = 'admin/' . substr($path, strlen(self::ADMIN_PLACEHOLDER) + 1);
        } elseif (str_starts_with($path, 'admin/') || strpos($path, '{') !== false) {
            return null;
        }
        $clean = PathRules::cleanRelative($path);
        if ($clean === null || PathRules::isUnderPlugins($clean) || PathRules::isCredentialPath($clean)) {
            return null;
        }
        return $clean;
    }

    private static function cleanLabel($label, int $max = 80): ?string
    {
        if (!is_string($label)) {
            return null;
        }
        $label = trim(preg_replace('~[\x00-\x1f\x7f]~', '', $label));
        if ($label === '' || mb_strlen($label) > $max || !mb_check_encoding($label, 'UTF-8')) {
            return null;
        }
        return $label;
    }

    public static function validEnding(string $ending): bool
    {
        return preg_match('/^[._\-~#][A-Za-z0-9._\-~#]{0,38}\*?$/', $ending) === 1 && $ending !== '.' && $ending !== '.*';
    }

    /**
     * Checks a declared base. Returns ['verified' => bool, 'reason' => string, 'file' => ?string,
     * 'sha256' => ?string, 'shown' => string]. Nothing is read until the name and path rules pass.
     * The base is never requested over the web and only ever read as text.
     */
    public static function verifyBase($base, $sha, $plugin, string $catalogDir, array $pluginDirs): array
    {
        $shown = is_string($base) ? $base : '?';
        if (is_string($plugin) && $plugin !== '') {
            $shown = $plugin . ': ' . $shown;
        }
        $fail = static fn(string $reason) => ['verified' => false, 'reason' => $reason, 'file' => null, 'sha256' => null, 'shown' => $shown];

        if (!is_string($base)) {
            return $fail('bad_path');
        }
        if (!is_string($sha) || preg_match('~^[0-9a-fA-F]{64}$~', $sha) !== 1) {
            return $fail('no_hash');
        }
        $sha = strtolower($sha);
        $clean = PathRules::cleanRelative($base);
        if ($clean === null) {
            return $fail('bad_path');
        }
        if ($plugin !== null && $plugin !== '') {
            if (!is_string($plugin) || !isset($pluginDirs[$plugin])) {
                return $fail('unknown_plugin');
            }
            $root = realpath($pluginDirs[$plugin]);
        } else {
            $root = realpath($catalogDir);
        }
        if ($root === false) {
            return $fail('outside');
        }
        $name = basename($clean);
        if (PathRules::isCredentialName($name) || PathRules::isCredentialPath($clean)) {
            return $fail('credential');
        }
        if (PathRules::hasRunnablePart($name)) {
            return $fail('runnable_name');
        }
        $candidate = $root;
        foreach (explode('/', $clean) as $part) {
            $candidate .= '/' . $part;
            if (is_link($candidate)) {
                return $fail('symlink');
            }
        }
        $real = realpath($candidate);
        if ($real === false || $real !== $candidate || !str_starts_with($real, $root . '/')) {
            return $fail('outside');
        }
        if (!is_file($real) || !is_readable($real)) {
            return $fail('not_found');
        }
        $size = filesize($real);
        if ($size === false || $size > self::MAX_BASE_BYTES) {
            return $fail('too_large');
        }
        $data = file_get_contents($real, false, null, 0, self::MAX_BASE_BYTES + 1);
        if (!is_string($data) || strlen($data) > self::MAX_BASE_BYTES) {
            return $fail('too_large');
        }
        if (!self::isText($data)) {
            return $fail('not_text');
        }
        if (!hash_equals($sha, hash('sha256', $data))) {
            return $fail('hash_mismatch');
        }
        return ['verified' => true, 'reason' => 'verified', 'file' => $real, 'sha256' => $sha, 'shown' => $shown];
    }

    public static function isText(string $data): bool
    {
        return preg_match('~[\x00-\x08\x0E-\x1A\x1C-\x1F]~', $data) !== 1;
    }
}
