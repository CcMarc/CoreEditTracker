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
 * Maps release paths to this store's files and lists the override folders of its templates.
 */
class StoreLayout
{
    public string $catalog;
    public string $admin;
    public string $template;
    public array $templates;

    /**
     * $templates lists every template the store has assigned. The first one is the store's main template.
     */
    public function __construct(string $catalogDir, string $adminDir, array $templates)
    {
        $this->catalog = rtrim($catalogDir, '/') . '/';
        $this->admin = rtrim($adminDir, '/') . '/';
        $clean = [];
        foreach ($templates as $name) {
            $name = (string)$name;
            if ($name !== '' && strpos($name, '/') === false && PathRules::cleanRelative($name) !== null) {
                $clean[$name] = true;
            }
        }
        $this->templates = array_keys($clean);
        $this->template = $this->templates[0] ?? 'template_default';
    }

    /**
     * The store file of a release path when it may be read: not a credential file, a regular
     * readable file, and when it is a symbolic link, one that ends inside the store or its
     * admin folder (wherever the admin folder sits) and is not a credential file either.
     * Returns ['file' => absolute path] or ['missing' => true] or ['unreadable' => true].
     */
    public function storeFile(string $releasePath): array
    {
        if (PathRules::cleanRelative($releasePath) === null || PathRules::isCredentialPath($releasePath)) {
            return ['unreadable' => true];
        }
        $abs = $this->absolute($releasePath);
        if (is_link($abs)) {
            $target = realpath($abs);
            if ($target === false || !is_file($target) || PathRules::isCredentialName(basename($target)) || !$this->inStore($target)) {
                return ['unreadable' => true];
            }
        }
        if (!is_file($abs)) {
            return ['missing' => true];
        }
        if (!is_readable($abs)) {
            return ['unreadable' => true];
        }
        return ['file' => $abs];
    }

    private function inStore(string $real): bool
    {
        foreach ([$this->catalog, $this->admin] as $root) {
            $r = realpath($root);
            if ($r !== false && str_starts_with($real, rtrim($r, '/') . '/')) {
                return true;
            }
        }
        return false;
    }

    public function absolute(string $releasePath): string
    {
        if (str_starts_with($releasePath, 'admin/')) {
            return $this->admin . substr($releasePath, 6);
        }
        return $this->catalog . $releasePath;
    }

    /**
     * Override folders as [store folder, default folder, template], the folders as release paths with a
     * trailing slash. Taken from Zen Cart's own lookups:
     *  - PageLoader::getTemplateDirectory (template_func::get_template_dir): includes/templates/<tpl>/
     *  - zen_get_module_directory: includes/modules/<tpl>/
     *  - SideboxFinder and zen_get_module_sidebox_directory: includes/modules/sideboxes/<tpl>/
     *  - zen_get_index_filters_directory: includes/index_filters/<tpl>/
     *  - the catalog language loaders: includes/languages/<tpl>/, includes/languages/<lang>/<tpl>/,
     *    <lang>/extra_definitions/<tpl>/ and <lang>/modules/<type>/<tpl>/
     *  - zen_get_file_directory for the define pages: <lang>/html_includes/<tpl>/
     */
    public function overrideFolders(): array
    {
        $out = [];
        $languages = $this->subfolders('includes/languages/');
        foreach ($this->templates as $t) {
            $folders = [];
            if ($t !== 'template_default') {
                $folders[] = ['includes/templates/' . $t . '/', 'includes/templates/template_default/'];
            }
            $folders[] = ['includes/modules/' . $t . '/', 'includes/modules/'];
            $folders[] = ['includes/modules/sideboxes/' . $t . '/', 'includes/modules/sideboxes/'];
            $folders[] = ['includes/index_filters/' . $t . '/', 'includes/index_filters/'];
            $folders[] = ['includes/languages/' . $t . '/', 'includes/languages/'];
            foreach ($languages as $lang) {
                if (in_array($lang, $this->templates, true)) {
                    continue;
                }
                $base = 'includes/languages/' . $lang . '/';
                $folders[] = [$base . $t . '/', $base];
                $folders[] = [$base . 'extra_definitions/' . $t . '/', $base . 'extra_definitions/'];
                $folders[] = [$base . 'html_includes/' . $t . '/', $base . 'html_includes/'];
                foreach ($this->subfolders($base . 'modules/') as $type) {
                    $folders[] = [$base . 'modules/' . $type . '/' . $t . '/', $base . 'modules/' . $type . '/'];
                }
            }
            foreach ($folders as $pair) {
                if (is_dir($this->catalog . $pair[0]) && !is_link(rtrim($this->catalog . $pair[0], '/'))) {
                    $out[] = [$pair[0], $pair[1], $t];
                }
            }
        }
        return $out;
    }

    private function subfolders(string $releaseDir): array
    {
        $dir = $this->catalog . $releaseDir;
        if (!is_dir($dir) || !is_readable($dir)) {
            return [];
        }
        $names = [];
        foreach ((array)scandir($dir) as $name) {
            if (!is_string($name) || $name === '' || $name[0] === '.') {
                continue;
            }
            if (is_dir($dir . $name) && !is_link($dir . $name)) {
                $names[] = $name;
            }
        }
        return $names;
    }

    /**
     * Every regular file under a release folder, as release paths. Symbolic links are not followed.
     */
    public function walk(string $releaseDir, int $limit = 50000): array
    {
        $found = [];
        $stack = [$releaseDir];
        while ($stack !== [] && count($found) < $limit) {
            $rel = array_pop($stack);
            $abs = $this->catalog . $rel;
            $names = (is_dir($abs) && is_readable($abs)) ? scandir($abs) : false;
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $path = $abs . $name;
                if (is_link($path)) {
                    continue;
                }
                if (is_dir($path)) {
                    $stack[] = $rel . $name . '/';
                } elseif (is_file($path)) {
                    $found[] = $rel . $name;
                }
            }
        }
        sort($found, SORT_STRING);
        return $found;
    }

    /**
     * The configure files present on this store (names only, never opened).
     */
    public function credentialFiles(): array
    {
        $found = [];
        foreach (['includes/', 'includes/local/', 'admin/includes/', 'admin/includes/local/'] as $rel) {
            $abs = $this->absolute($rel);
            $names = (is_dir($abs) && is_readable($abs)) ? scandir($abs) : false;
            if (!is_array($names)) {
                continue;
            }
            foreach ($names as $name) {
                if (PathRules::isCredentialName($name) && !is_dir($abs . $name)) {
                    $found[] = $rel . $name;
                }
            }
        }
        return $found;
    }
}
