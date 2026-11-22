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
 * The folder that holds the release zip and its manifest.
 *
 * By default it is a folder beside the web root, one subfolder per store, named from a hash of the
 * store's path so stores that share a web root never share a folder. When that cannot be written
 * it is a folder inside the store's cache folder, closed to the web by deny files and holding
 * unguessable file names. The owner can also pick a folder outside the web root.
 */
class DataFolder
{
    public const FOLDER_NAME = 'core_edit_tracker';
    private const OWN_PREFIX = 'release-';

    public string $path;
    public bool $outsideWebRoot;

    public function __construct(string $path, bool $outsideWebRoot)
    {
        $this->path = rtrim($path, '/') . '/';
        $this->outsideWebRoot = $outsideWebRoot;
    }

    /**
     * The two standard folders, as ['beside' => ..., 'cache' => ...], each
     * ['path' => string, 'outside' => bool, 'available' => bool]. 'beside' is absent when the store
     * does not sit under the document root.
     */
    public static function options(string $catalogDir, string $documentRoot, string $cacheDir): array
    {
        $out = [];
        $catalog = realpath($catalogDir);
        $docRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
        if ($catalog !== false && $docRoot !== false && $docRoot !== '/' && str_starts_with($catalog . '/', rtrim($docRoot, '/') . '/')) {
            $parent = dirname($docRoot);
            $shared = rtrim($parent, '/') . '/' . self::FOLDER_NAME . '_data';
            $candidate = $shared . '/' . self::storeKey($catalog);
            $writable = is_dir($candidate) ? is_writable($candidate) : (is_dir($shared) ? is_writable($shared) : is_writable($parent));
            $out['beside'] = ['path' => $candidate . '/', 'outside' => true, 'available' => self::allowedByBasedir($candidate) && $writable];
        }
        $cache = rtrim($cacheDir, '/') . '/' . self::FOLDER_NAME;
        $out['cache'] = [
            'path' => $cache . '/',
            'outside' => false,
            'available' => is_dir($cache) ? is_writable($cache) : is_writable(rtrim($cacheDir, '/')),
        ];
        return $out;
    }

    /**
     * Picks the folder. $saved is the folder chosen before, kept while it exists.
     */
    public static function choose(string $catalogDir, string $documentRoot, string $cacheDir, ?array $saved): self
    {
        if ($saved !== null && isset($saved['path']) && is_string($saved['path']) && is_dir($saved['path'])) {
            return new self($saved['path'], !empty($saved['outside']));
        }
        $options = self::options($catalogDir, $documentRoot, $cacheDir);
        if (isset($options['beside']) && $options['beside']['available']) {
            return new self($options['beside']['path'], true);
        }
        return new self($options['cache']['path'], false);
    }

    /**
     * A folder the owner names. It must exist, be writable and sit outside the web root. The tracker
     * keeps its files in its own subfolder there. Returns ['folder' => self] or ['reason' => string]:
     * not_absolute, not_a_folder, not_writable, inside_web_root.
     */
    public static function custom(string $path, string $catalogDir, string $documentRoot): array
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 1024 || strpbrk($path, "\0") !== false
            || ($path[0] !== '/' && preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1)) {
            return ['reason' => 'not_absolute'];
        }
        $real = realpath($path);
        if ($real === false || !is_dir($real)) {
            return ['reason' => 'not_a_folder'];
        }
        $real = rtrim(str_replace('\\', '/', $real), '/');
        foreach ([realpath($catalogDir), $documentRoot !== '' ? realpath($documentRoot) : false] as $root) {
            if ($root !== false && str_starts_with($real . '/', rtrim(str_replace('\\', '/', $root), '/') . '/')) {
                return ['reason' => 'inside_web_root'];
            }
        }
        $target = basename($real) === self::FOLDER_NAME ? $real : $real . '/' . self::FOLDER_NAME;
        $writable = is_dir($target) ? is_writable($target) : is_writable($real);
        if (!$writable || !self::allowedByBasedir($target)) {
            return ['reason' => 'not_writable'];
        }
        return ['folder' => new self($target, true)];
    }

    /**
     * A zip the owner already has on the server, by its full path. Only found, never changed.
     */
    public static function zipAt(string $path): array
    {
        $path = trim($path);
        if ($path === '' || strlen($path) > 1024 || strpbrk($path, "\0") !== false
            || ($path[0] !== '/' && preg_match('~^[A-Za-z]:[\\\\/]~', $path) !== 1)) {
            return ['reason' => 'not_absolute'];
        }
        if (!self::allowedByBasedir(str_replace('\\', '/', $path))) {
            return ['reason' => 'not_found'];
        }
        $real = realpath($path);
        if ($real === false || !is_file($real) || !is_readable($real) || preg_match('~\.zip$~i', $real) !== 1) {
            return ['reason' => 'not_found'];
        }
        return ['file' => $real];
    }

    /**
     * The name of a store's own subfolder: the start of the SHA-256 of its real path.
     */
    public static function storeKey(string $catalogRealPath): string
    {
        return 'store-' . substr(hash('sha256', rtrim($catalogRealPath, '/') . '/'), 0, 16);
    }

    private static function allowedByBasedir(string $path): bool
    {
        $basedir = (string)ini_get('open_basedir');
        if ($basedir === '') {
            return true;
        }
        foreach (explode(PATH_SEPARATOR, $basedir) as $allowed) {
            $allowed = rtrim($allowed, '/');
            if ($allowed !== '' && str_starts_with($path . '/', $allowed . '/')) {
                return true;
            }
        }
        return false;
    }

    /**
     * The storefront's own address, from the admin configure settings.
     */
    public static function storeUrl(): string
    {
        if (defined('ENABLE_SSL_CATALOG') && ENABLE_SSL_CATALOG === 'true' && defined('HTTPS_CATALOG_SERVER')) {
            return HTTPS_CATALOG_SERVER . (defined('DIR_WS_HTTPS_CATALOG') ? DIR_WS_HTTPS_CATALOG : '/');
        }
        return defined('HTTP_CATALOG_SERVER') ? HTTP_CATALOG_SERVER . (defined('DIR_WS_CATALOG') ? DIR_WS_CATALOG : '/') : '';
    }

    /**
     * Tests the folder inside the store: writes a probe file with random content, requests it
     * once through the store's own address (no redirects followed), then deletes it.
     * Returns ['result' => outside|protected|reachable|untested, 'detail' => string].
     */
    public function probe(string $catalogDir, string $storeUrl): array
    {
        if ($this->outsideWebRoot) {
            return ['result' => 'outside', 'detail' => ''];
        }
        $catalog = realpath($catalogDir);
        $real = realpath($this->path);
        if ($catalog === false || $real === false || !str_starts_with($real . '/', rtrim($catalog, '/') . '/')) {
            return ['result' => 'untested', 'detail' => 'not_in_store'];
        }
        if (preg_match('~^https?://[^/\s]+~i', $storeUrl) !== 1) {
            return ['result' => 'untested', 'detail' => 'no_store_url'];
        }
        if (!function_exists('curl_init')) {
            return ['result' => 'untested', 'detail' => 'no_curl'];
        }
        if (!is_writable($real)) {
            return ['result' => 'untested', 'detail' => 'cannot_write'];
        }
        $name = 'probe-' . bin2hex(random_bytes(12)) . '.txt';
        $content = bin2hex(random_bytes(16));
        $file = $real . '/' . $name;
        if (file_put_contents($file, $content) === false) {
            return ['result' => 'untested', 'detail' => 'cannot_write'];
        }
        $relative = substr($file, strlen(rtrim($catalog, '/')) + 1);
        $url = rtrim($storeUrl, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', $relative)));
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible) Core Edit Tracker folder check',
            CURLOPT_HTTPHEADER => ['Cache-Control: no-cache'],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
                if (strlen($body) < 65536) {
                    $body .= $chunk;
                }
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch);
        unlink($file);
        return self::verdict($ok !== false, $code, $error, strpos($body, $content) !== false);
    }

    /**
     * Protected only when the server refuses the probe (401, 403, 404, 410). Reachable when it
     * serves the probe's content. Anything else (a redirect, a server error, a timeout) proves
     * nothing and stays untested.
     */
    public static function verdict(bool $answered, int $code, int $error, bool $holdsContent): array
    {
        if (!$answered || $code === 0) {
            return ['result' => 'untested', 'detail' => 'request_failed_' . $error];
        }
        if (in_array($code, [401, 403, 404, 410], true)) {
            return ['result' => 'protected', 'detail' => (string)$code];
        }
        if ($code >= 200 && $code < 300 && $holdsContent) {
            return ['result' => 'reachable', 'detail' => (string)$code];
        }
        return ['result' => 'untested', 'detail' => 'answered_' . $code];
    }

    public function toState(): array
    {
        return ['path' => $this->path, 'outside' => $this->outsideWebRoot];
    }

    /**
     * Creates the folder and its deny files. Returns false when it cannot be written.
     */
    public function ensure(): bool
    {
        if (!is_dir($this->path)) {
            $parent = dirname(rtrim($this->path, '/'));
            if ($this->outsideWebRoot && !is_dir($parent)) {
                $grand = dirname($parent);
                if (!is_dir($grand) || !is_writable($grand) || !mkdir($parent, 0775)) {
                    return false;
                }
                chmod($parent, 0775);
            }
            if (!is_dir($parent) || !is_writable($parent) || !mkdir($this->path, 0775)) {
                return false;
            }
            // Group writable so an owner in the web server's group can place a zip here.
            chmod($this->path, 0775);
        }
        if (!is_writable($this->path)) {
            return false;
        }
        $files = [
            '.htaccess' => "# No web access to this folder\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n",
            'index.html' => '',
        ];
        foreach ($files as $name => $content) {
            if (!is_file($this->path . $name)) {
                file_put_contents($this->path . $name, $content);
            }
        }
        return true;
    }

    /**
     * Puts a checked zip under a new unguessable name. Returns the id, or null.
     */
    public function keepZip(string $source, bool $uploaded): ?string
    {
        if (!$this->ensure()) {
            return null;
        }
        $id = bin2hex(random_bytes(16));
        $target = $this->zipFile($id);
        if ($uploaded) {
            $ok = move_uploaded_file($source, $target);
        } else {
            // The owner's file is removed by the caller once the copy and its manifest are kept.
            $ok = copy($source, $target);
        }
        if (!$ok) {
            return null;
        }
        chmod($target, 0640);
        return $id;
    }

    public function zipFile(string $id): string
    {
        return $this->path . self::OWN_PREFIX . preg_replace('~[^0-9a-f]~', '', $id) . '.zip';
    }

    private function manifestFile(string $id): string
    {
        return $this->path . self::OWN_PREFIX . preg_replace('~[^0-9a-f]~', '', $id) . '.json';
    }

    public function writeManifest(string $id, array $manifest): bool
    {
        $file = $this->manifestFile($id);
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }
        $ok = file_put_contents($file, $json) === strlen($json);
        if ($ok) {
            chmod($file, 0640);
        } elseif (is_file($file)) {
            unlink($file);
        }
        return $ok;
    }

    public function readManifest(string $id): ?array
    {
        $file = $this->manifestFile($id);
        if (!is_file($file)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Copies a release's zip and manifest into another folder, then removes them here.
     * Nothing is removed unless both copies arrived whole.
     */
    public function moveRelease(string $id, self $target): bool
    {
        $pairs = [[$this->zipFile($id), $target->zipFile($id)], [$this->manifestFile($id), $target->manifestFile($id)]];
        $copied = [];
        foreach ($pairs as [$from, $to]) {
            if (!is_file($from) || !copy($from, $to) || filesize($from) !== filesize($to)) {
                foreach (array_merge($copied, [$to]) as $partial) {
                    if (is_file($partial)) {
                        unlink($partial);
                    }
                }
                return false;
            }
            chmod($to, 0640);
            $copied[] = $to;
        }
        foreach ($pairs as [$from]) {
            unlink($from);
        }
        return true;
    }

    public function remove(string $id): void
    {
        foreach ([$this->zipFile($id), $this->manifestFile($id)] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * Zips the owner placed in the folder, offered on the page. Only names, sizes and dates.
     */
    public function placedZips(): array
    {
        if (!is_dir($this->path) || !is_readable($this->path)) {
            return [];
        }
        $found = [];
        foreach ((array)scandir($this->path) as $name) {
            if (!is_string($name) || str_starts_with($name, self::OWN_PREFIX) || preg_match('~^[A-Za-z0-9._\- ]{1,120}\.zip$~i', $name) !== 1) {
                continue;
            }
            $file = $this->path . $name;
            if (is_file($file) && !is_link($file)) {
                $found[] = ['name' => $name, 'size' => (int)filesize($file), 'time' => (int)filemtime($file)];
            }
        }
        return $found;
    }

    public function placedFile(string $name): ?string
    {
        foreach ($this->placedZips() as $zip) {
            if ($zip['name'] === $name) {
                return $this->path . $name;
            }
        }
        return null;
    }

    /**
     * Removes what the tracker made here, then the folder when nothing else is left.
     */
    public function removeOwnFiles(): void
    {
        if (!is_dir($this->path)) {
            return;
        }
        foreach ((array)scandir($this->path) as $name) {
            if (is_string($name) && preg_match('~^' . self::OWN_PREFIX . '[0-9a-f]{32}\.(zip|json)$~', $name) === 1) {
                unlink($this->path . $name);
            }
        }
        $left = array_diff((array)scandir($this->path), ['.', '..', '.htaccess', 'index.html']);
        if ($left === []) {
            foreach (['.htaccess', 'index.html'] as $name) {
                if (is_file($this->path . $name)) {
                    unlink($this->path . $name);
                }
            }
            rmdir($this->path);
            // The shared folder beside the web root is removed once no store uses it.
            $parent = dirname(rtrim($this->path, '/'));
            if ($this->outsideWebRoot && basename($parent) === self::FOLDER_NAME . '_data' && array_diff((array)scandir($parent), ['.', '..']) === []) {
                rmdir($parent);
            }
        }
    }

    /**
     * Removes a zip the owner placed here, after the tracker has kept its own copy.
     */
    public function removePlaced(string $file): void
    {
        if (dirname($file) . '/' === $this->path && is_file($file) && !str_starts_with(basename($file), self::OWN_PREFIX)) {
            unlink($file);
        }
    }
}
