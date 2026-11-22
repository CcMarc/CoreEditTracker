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
 * Path rules shared by the scan, the companion reader and the export.
 * Paths are release paths: relative, forward slashes, the admin folder as "admin/".
 */
class PathRules
{
    // The four configure files and any copy of one (configure.php.bak, configure.php.old ...).
    private const CREDENTIAL_PATH = '~^(admin/)?includes/(local/)?configure\.php[^/]*$~i';
    private const CREDENTIAL_NAME = '~^configure\.php~i';

    // Extensions a web server may run, matched on every part of a file name.
    private const RUNNABLE_PART = '~^(php[3-8]?|phtml|pht|phps|phar)$~i';

    // Release paths a live store is meant not to have, from Zen Cart's own install
    // instructions (zc_install is deleted after installing, dist- files are samples to
    // copy, docs and the development files of the source archive are not uploaded).
    private const NOT_EXPECTED_FOLDERS = ['zc_install/', 'docs/', 'not_for_release/', '.github/', 'testFramework/'];
    private const NOT_EXPECTED_TOP_FILES = [
        'README.md', 'CONTRIBUTING.md', 'install.txt', 'robots_example.txt', 'composer.json', 'composer.lock',
        'phpunit.xml', 'phpunit.xml.dist', 'crowdin.yml', '.editorconfig', '.gitattributes', '.gitignore',
    ];
    private const NOT_EXPECTED_ANY_NAME = ['.gitignore', '.gitattributes', 'not_for_release.php'];

    public static function isCredentialPath(string $releasePath): bool
    {
        return preg_match(self::CREDENTIAL_PATH, $releasePath) === 1;
    }

    public static function isCredentialName(string $fileName): bool
    {
        return preg_match(self::CREDENTIAL_NAME, $fileName) === 1;
    }

    public static function hasRunnablePart(string $fileName): bool
    {
        $parts = explode('.', $fileName);
        array_shift($parts);
        foreach ($parts as $part) {
            if (preg_match(self::RUNNABLE_PART, $part) === 1) {
                return true;
            }
        }
        return false;
    }

    public static function isNotExpected(string $releasePath): bool
    {
        $lower = strtolower($releasePath);
        foreach (self::NOT_EXPECTED_FOLDERS as $folder) {
            if (str_starts_with($lower, strtolower($folder))) {
                return true;
            }
        }
        $name = basename($lower);
        if (strpos($lower, '/') === false && in_array($lower, array_map('strtolower', self::NOT_EXPECTED_TOP_FILES), true)) {
            return true;
        }
        return in_array($name, self::NOT_EXPECTED_ANY_NAME, true) || str_starts_with($name, 'dist-');
    }

    public static function notExpectedSummary(): array
    {
        return [
            'folders' => self::NOT_EXPECTED_FOLDERS,
            'top_files' => self::NOT_EXPECTED_TOP_FILES,
            'any_name' => array_merge(self::NOT_EXPECTED_ANY_NAME, ['dist-*']),
        ];
    }

    /**
     * A clean relative path, or null when it is absolute, climbs, or carries tricks.
     */
    public static function cleanRelative(string $path): ?string
    {
        if ($path === '' || strlen($path) > 1024 || strpbrk($path, "\\\0") !== false) {
            return null;
        }
        if ($path[0] === '/' || preg_match('~^[A-Za-z]:~', $path) === 1 || preg_match('~[\x00-\x1f\x7f]~', $path) === 1) {
            return null;
        }
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '' || $part === '.' || $part === '..') {
                return null;
            }
        }
        return $path;
    }

    public static function isUnderPlugins(string $releasePath): bool
    {
        return str_starts_with($releasePath, 'zc_plugins/') || $releasePath === 'zc_plugins';
    }
}
