<?php
/**
 * Module: CoreEditTracker
 *
 * @requires    Zen Cart 2.1.0 or later, PHP 8.0+ with the zip extension
 * @author      Marcopolo
 * @copyright   2026
 * @license     GNU General Public License (GPL) - https://www.zen-cart.com/license/2_0.txt
 * @version     1.0.2
 * @updated     11-22-2026
 * @github      https://github.com/CcMarc/CoreEditTracker
 */
namespace Zencart\Plugins\Admin\CoreEditTracker;

if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

/**
 * Reads an official Zen Cart zip without unpacking it.
 */
class ReleaseZip
{
    public const MAX_ZIP_BYTES = 64 * 1024 * 1024;
    public const MAX_ENTRY_BYTES = 16 * 1024 * 1024;
    public const MAX_ENTRIES = 20000;
    // Zen Cart 2.x release zips are 9 to 10 MB. An upload limit below this is treated as too small.
    public const EXPECTED_ZIP_BYTES = 12 * 1024 * 1024;

    // Files every Zen Cart release has had. The version file is checked apart from these.
    private const SIGNATURE_PATHS = [
        'index.php',
        'includes/application_top.php',
        'admin/index.php',
    ];

    private string $file;
    private string $top;
    private ?\ZipArchive $zip = null;

    public function __construct(string $file, string $top)
    {
        $this->file = $file;
        $this->top = $top;
    }

    /**
     * Checks the zip and builds its manifest.
     * Returns ['ok' => true, 'top', 'version', 'files' => [path => [size, sha256]], 'not_expected']
     * or ['ok' => false, 'reason' => string].
     */
    public static function inspect(string $file, string $storeMajor, string $storeMinor): array
    {
        if (!class_exists('ZipArchive')) {
            return self::refuse('zip_extension');
        }
        if (!is_file($file) || filesize($file) === 0) {
            return self::refuse('not_a_file');
        }
        if (filesize($file) > self::MAX_ZIP_BYTES) {
            return self::refuse('too_large');
        }
        $zip = new \ZipArchive();
        if ($zip->open($file, \ZipArchive::RDONLY) !== true) {
            return self::refuse('not_a_zip');
        }
        $count = $zip->numFiles;
        if ($count < 1 || $count > self::MAX_ENTRIES) {
            $zip->close();
            return self::refuse('entry_count');
        }

        $top = null;
        $names = [];
        for ($i = 0; $i < $count; $i++) {
            $stat = $zip->statIndex($i, \ZipArchive::FL_UNCHANGED);
            if ($stat === false) {
                $zip->close();
                return self::refuse('unreadable_entry');
            }
            $name = (string)$stat['name'];
            $check = self::checkEntry($zip, $i, $name, $stat);
            if ($check !== '') {
                $zip->close();
                return self::refuse($check, $name);
            }
            $first = explode('/', $name, 2)[0];
            if ($top === null) {
                $top = $first;
            } elseif ($first !== $top) {
                $zip->close();
                return self::refuse('several_top_folders', $name);
            }
            if (substr($name, -1) === '/') {
                continue;
            }
            if (strpos($name, '/') === false) {
                $zip->close();
                return self::refuse('outside_top_folder', $name);
            }
            if (isset($names[$name])) {
                $zip->close();
                return self::refuse('duplicate_entry', $name);
            }
            $names[$name] = $i;
        }

        $prefix = $top . '/';
        $versionFile = $prefix . 'includes/version.php';
        if (!isset($names[$versionFile])) {
            $zip->close();
            return self::refuse('no_version_file');
        }
        foreach (self::SIGNATURE_PATHS as $path) {
            if (!isset($names[$prefix . $path])) {
                $zip->close();
                return self::refuse('not_zen_cart', $path);
            }
        }
        $versionText = self::readIndex($zip, $names[$versionFile], 65536);
        $major = self::defineValue((string)$versionText, 'PROJECT_VERSION_MAJOR');
        $minor = self::defineValue((string)$versionText, 'PROJECT_VERSION_MINOR');
        if ($major === null || $minor === null) {
            $zip->close();
            return self::refuse('no_version_file');
        }
        if ($major !== $storeMajor || $minor !== $storeMinor) {
            $zip->close();
            return self::refuse('wrong_version', $major . '.' . $minor);
        }

        $files = [];
        $notExpected = 0;
        foreach ($names as $name => $index) {
            $path = substr($name, strlen($prefix));
            $hash = self::hashIndex($zip, $index);
            if ($hash === null) {
                $zip->close();
                return self::refuse('entry_too_large', $path);
            }
            $files[$path] = $hash;
            if (PathRules::isNotExpected($path)) {
                $notExpected++;
            }
        }
        $zip->close();
        ksort($files, SORT_STRING);

        return [
            'ok' => true,
            'top' => $top,
            'version' => $major . '.' . $minor,
            'files' => $files,
            'not_expected' => $notExpected,
        ];
    }

    /**
     * One entry of the release as a string, or null when it is absent or too large.
     */
    public function read(string $path, int $maxBytes = self::MAX_ENTRY_BYTES): ?string
    {
        if (PathRules::cleanRelative($path) === null || PathRules::isCredentialPath($path)) {
            return null;
        }
        if ($this->zip === null) {
            $zip = new \ZipArchive();
            if ($zip->open($this->file, \ZipArchive::RDONLY) !== true) {
                return null;
            }
            $this->zip = $zip;
        }
        $index = $this->zip->locateName($this->top . '/' . $path);
        return ($index === false) ? null : self::readIndex($this->zip, $index, $maxBytes);
    }

    public function close(): void
    {
        if ($this->zip !== null) {
            $this->zip->close();
            $this->zip = null;
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private static function checkEntry(\ZipArchive $zip, int $index, string $name, array $stat): string
    {
        if ($name === '' || strlen($name) > 1024 || strpbrk($name, "\\\0") !== false) {
            return 'bad_name';
        }
        $raw = $zip->getNameIndex($index, \ZipArchive::FL_ENC_RAW);
        if (!is_string($raw) || !mb_check_encoding($raw, 'UTF-8')) {
            return 'bad_encoding';
        }
        if ($name[0] === '/' || preg_match('~^[A-Za-z]:~', $name) === 1) {
            return 'absolute_path';
        }
        $trimmed = rtrim($name, '/');
        foreach (explode('/', $trimmed) as $part) {
            if ($part === '..') {
                return 'parent_path';
            }
            if ($part === '' || $part === '.') {
                return 'bad_name';
            }
        }
        if (!empty($stat['encryption_method'])) {
            return 'encrypted';
        }
        $opsys = 0;
        $attr = 0;
        if ($zip->getExternalAttributesIndex($index, $opsys, $attr, \ZipArchive::FL_UNCHANGED)) {
            if ($opsys === \ZipArchive::OPSYS_UNIX) {
                $type = ($attr >> 16) & 0170000;
                if ($type === 0120000) {
                    return 'symlink';
                }
                if ($type !== 0 && $type !== 0100000 && $type !== 0040000) {
                    return 'special_file';
                }
            }
        }
        if ((int)$stat['size'] > self::MAX_ENTRY_BYTES) {
            return 'entry_too_large';
        }
        return '';
    }

    private static function readIndex(\ZipArchive $zip, int $index, int $maxBytes): ?string
    {
        $stream = $zip->getStreamIndex($index, \ZipArchive::FL_UNCHANGED);
        if ($stream === false) {
            return null;
        }
        $data = '';
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $data .= $chunk;
            if (strlen($data) > $maxBytes) {
                fclose($stream);
                return null;
            }
        }
        fclose($stream);
        return $data;
    }

    private static function hashIndex(\ZipArchive $zip, int $index): ?array
    {
        $stream = $zip->getStreamIndex($index, \ZipArchive::FL_UNCHANGED);
        if ($stream === false) {
            return null;
        }
        $ctx = hash_init('sha256');
        $size = 0;
        while (!feof($stream)) {
            $chunk = fread($stream, 65536);
            if ($chunk === false) {
                break;
            }
            $size += strlen($chunk);
            if ($size > self::MAX_ENTRY_BYTES) {
                fclose($stream);
                return null;
            }
            hash_update($ctx, $chunk);
        }
        fclose($stream);
        return [$size, hash_final($ctx)];
    }

    public static function defineValue(string $php, string $name): ?string
    {
        if (preg_match("~define\(\s*'" . preg_quote($name, '~') . "'\s*,\s*'([^']*)'\s*\)~", $php, $m) === 1) {
            return $m[1];
        }
        return null;
    }

    private static function refuse(string $reason, string $detail = ''): array
    {
        return ['ok' => false, 'reason' => $reason, 'detail' => $detail];
    }
}
