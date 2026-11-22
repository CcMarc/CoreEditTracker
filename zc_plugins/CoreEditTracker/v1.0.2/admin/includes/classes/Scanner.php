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
 * Compares the store with the release manifest. Reads files, never writes them.
 *
 * Row keys: g group (stock, edit, override, missing, override_gone), s size, t mtime, h sha256, a/d lines
 * added and removed, def the release file an override replaces ('' when it replaces none), own owner
 * label, note owner note, same an override that is an exact copy of the file it replaces, mk markers, sec may hold a secret, bin binary, big too large to compare,
 * ur unreadable, bv/br/bs base verified, reason and state, chg change since the previous scan.
 */
class Scanner
{
    private StoreLayout $layout;
    private array $manifest;
    private ReleaseZip $zip;
    private array $companion;
    private array $previous;
    private array $hashes;
    private bool $full;
    private bool $sameRelease;
    private array $dirCache = [];
    private int $filesRead = 0;
    private ?array $shipped = null;

    /**
     * $previous holds the rows of the last scan the owner looked at (for the change marks), $hashes the
     * rows of the newest saved scan (for reusing hashes). They differ only after an export's rescan.
     */
    public function __construct(StoreLayout $layout, array $manifest, ReleaseZip $zip, array $companion, array $previous, bool $full, bool $sameRelease, ?array $hashes = null)
    {
        $this->layout = $layout;
        $this->manifest = $manifest;
        $this->zip = $zip;
        $this->companion = $companion + ['owned' => [], 'endings' => [], 'present' => false, 'refused' => []];
        $this->previous = $previous;
        $this->hashes = $hashes ?? $previous;
        $this->full = $full;
        $this->sameRelease = $sameRelease;
    }

    public function run(): array
    {
        $start = microtime(true);
        $rows = [];
        $notExpected = ['absent' => 0, 'present' => 0];
        $owned = $this->companion['owned'];

        foreach ($this->manifest as $path => [$releaseSize, $releaseSha]) {
            $path = (string)$path;
            if (PathRules::isUnderPlugins($path)) {
                continue;
            }
            $abs = $this->layout->absolute($path);
            if (PathRules::isNotExpected($path)) {
                if (!file_exists($abs) && !is_link($abs)) {
                    $notExpected['absent']++;
                    continue;
                }
                $notExpected['present']++;
            }
            if (PathRules::isCredentialPath($path)) {
                continue;
            }
            $row = $this->fileRow($path, $abs);
            if ($row['g'] === 'present') {
                if ($row['h'] === $releaseSha) {
                    $row['g'] = 'stock';
                } else {
                    $row['g'] = 'edit';
                    $row += $this->diffInfo($path, $abs, $row, $this->zip->read($path));
                }
            }
            $this->addOwner($row, $path, $owned);
            $this->addMarkers($row, $path, $releaseSha);
            $rows[$path] = $row;
        }

        foreach ($this->layout->overrideFolders() as [$dir, $defaultDir, $template]) {
            foreach ($this->layout->walk($dir) as $path) {
                if (isset($this->manifest[$path]) || isset($rows[$path]) || PathRules::isCredentialPath($path) || $this->isMarkerFile($path)) {
                    continue;
                }
                $abs = $this->layout->absolute($path);
                $row = $this->fileRow($path, $abs);
                if ($row['g'] !== 'present') {
                    continue;
                }
                $row['g'] = 'override';
                $row['def'] = $this->originalOf($path, $abs, $row, $dir, $defaultDir, $template);
                if ($row['def'] !== '' && $this->manifest[$row['def']][1] === $row['h']) {
                    $row['same'] = 1;
                }
                $row += $this->diffInfo($path, $abs, $row, $row['def'] === '' ? '' : $this->zip->read($row['def']));
                $this->addOwner($row, $path, $owned);
                $this->addMarkers($row, $path, null);
                $rows[$path] = $row;
            }
        }

        $added = [];
        foreach ($owned as $path => $info) {
            if (isset($rows[$path]) || isset($this->manifest[$path])) {
                continue;
            }
            $abs = $this->layout->absolute($path);
            $row = $this->fileRow($path, $abs);
            $row['own'] = $info['label'];
            if (!empty($info['note'])) {
                $row['note'] = $info['note'];
            }
            if ($row['g'] === 'present') {
                $row['g'] = 'added';
                $base = $info['base'] ?? null;
                $left = '';
                if ($base !== null && $base['verified']) {
                    $row['bv'] = 1;
                    $row['bs'] = ($row['h'] === $base['sha256']) ? 'equal' : 'differs';
                    $left = (string)file_get_contents($base['file']);
                } elseif ($base !== null) {
                    $row['bv'] = 0;
                    $row['br'] = $base['reason'];
                }
                $row += $this->diffInfo($path, $abs, $row, $left);
            } else {
                $row['g'] = 'added_missing';
            }
            $added[$path] = $row;
        }

        $this->markChanges($rows);
        $credentials = $this->layout->credentialFiles();

        $counts = [
            'edit_yours' => 0, 'edit_owned' => 0, 'override_yours' => 0, 'override_owned' => 0,
            'missing_yours' => 0, 'missing_owned' => 0, 'stock' => 0, 'changes' => 0, 'override_alone' => 0, 'override_same' => 0,
        ];
        foreach ($rows as $row) {
            $who = isset($row['own']) ? '_owned' : '_yours';
            if ($row['g'] === 'stock') {
                $counts['stock']++;
            } elseif (isset($counts[$row['g'] . $who])) {
                $counts[$row['g'] . $who]++;
            }
            if (isset($row['chg'])) {
                $counts['changes']++;
            }
            if ($row['g'] === 'override' && !isset($row['own']) && ($row['def'] ?? '') === '') {
                $counts['override_alone']++;
            }
            if ($row['g'] === 'override' && !isset($row['own']) && !empty($row['same'])) {
                $counts['override_same']++;
            }
        }
        $counts['edit'] = $counts['edit_yours'] + $counts['edit_owned'];
        $counts['override'] = $counts['override_yours'] + $counts['override_owned'];
        $counts['missing'] = $counts['missing_yours'] + $counts['missing_owned'];
        $counts['added'] = count($added);
        $this->zip->close();

        return [
            'kind' => $this->full ? 'full' : 'quick',
            'at' => time(),
            'ms' => (int)round((microtime(true) - $start) * 1000),
            'files_read' => $this->filesRead,
            'template' => $this->layout->template,
            'templates' => $this->layout->templates,
            'counts' => $counts,
            'not_expected' => $notExpected,
            'credentials' => $credentials,
            'companion' => [
                'present' => $this->companion['present'],
                'off' => !empty($this->companion['off']),
                'endings' => $this->companion['endings'],
                'owned' => count($owned),
                'refused' => array_slice($this->companion['refused'], 0, 200),
            ],
            'rows' => $rows,
            'added' => $added,
        ];
    }

    /**
     * Size, time and hash of a store file, or group "missing".
     */
    private function fileRow(string $path, string $abs): array
    {
        $check = $this->layout->storeFile($path);
        if (isset($check['missing'])) {
            return ['g' => 'missing'];
        }
        if (!isset($check['file'])) {
            return ['g' => 'missing', 'ur' => 1];
        }
        clearstatcache(true, $abs);
        $size = (int)filesize($abs);
        $mtime = (int)filemtime($abs);
        $prev = $this->hashes[$path] ?? null;
        if (!$this->full && $prev !== null && isset($prev['h']) && ($prev['s'] ?? -1) === $size && ($prev['t'] ?? -1) === $mtime) {
            $hash = $prev['h'];
        } else {
            $this->filesRead++;
            $hash = hash_file('sha256', $abs);
            if ($hash === false) {
                return ['g' => 'missing', 'ur' => 1];
            }
        }
        return ['g' => 'present', 's' => $size, 't' => $mtime, 'h' => $hash];
    }

    /**
     * The release file an override replaces, or '' when it replaces none. Zen Cart falls back to the
     * default folder, but a template is often built from a copy of another template the release ships.
     * When both hold the file, the one closer to the override is its original.
     */
    private function originalOf(string $path, string $abs, array $row, string $dir, string $defaultDir, string $template): string
    {
        $prev = $this->hashes[$path] ?? null;
        if ($prev !== null && $this->sameRelease && ($prev['g'] ?? '') === 'override' && ($prev['h'] ?? '') === $row['h']
            && !empty($prev['def']) && isset($this->manifest[$prev['def']])) {
            return (string)$prev['def'];
        }
        $rest = substr($path, strlen($dir));
        $candidates = [];
        if (isset($this->manifest[$defaultDir . $rest])) {
            $candidates[] = $defaultDir . $rest;
        }
        $needle = '/' . $template . '/';
        $at = strpos('/' . $dir, $needle);
        if ($at !== false) {
            foreach ($this->shippedTemplates() as $other) {
                if ($other === $template) {
                    continue;
                }
                $candidate = substr($dir, 0, $at) . $other . '/' . substr($dir, $at + strlen($needle) - 1) . $rest;
                if (isset($this->manifest[$candidate]) && !in_array($candidate, $candidates, true)) {
                    $candidates[] = $candidate;
                }
            }
        }
        if (count($candidates) < 2) {
            return $candidates[0] ?? '';
        }
        foreach ($candidates as $candidate) {
            if ($this->manifest[$candidate][1] === $row['h']) {
                return $candidate;
            }
        }
        if ($row['s'] > ReleaseZip::MAX_ENTRY_BYTES) {
            return $candidates[0];
        }
        $store = file_get_contents($abs);
        if (!is_string($store) || LineDiff::isBinary($store)) {
            return $candidates[0];
        }
        $right = LineDiff::split($store);
        $best = $candidates[0];
        $bestCost = null;
        foreach ($candidates as $candidate) {
            $left = $this->zip->read($candidate);
            if ($left === null || LineDiff::isBinary($left)) {
                continue;
            }
            $result = LineDiff::compare(LineDiff::split($left), $right, ['eol' => true]);
            $cost = $result['added'] + $result['removed'];
            if ($bestCost === null || $cost < $bestCost) {
                $best = $candidate;
                $bestCost = $cost;
            }
        }
        return $best;
    }

    /**
     * The templates the release ships besides template_default.
     */
    private function shippedTemplates(): array
    {
        if ($this->shipped === null) {
            $found = [];
            foreach ($this->manifest as $path => $unused) {
                if (preg_match('~^includes/templates/([^/]+)/~', (string)$path, $m) === 1 && $m[1] !== 'template_default') {
                    $found[$m[1]] = true;
                }
            }
            $this->shipped = array_keys($found);
        }
        return $this->shipped;
    }

    /**
     * Lines added and removed against $left, and the secret screen on the added lines.
     */
    private function diffInfo(string $path, string $abs, array $row, ?string $left): array
    {
        $prev = $this->hashes[$path] ?? null;
        if ($prev !== null && $this->sameRelease && ($prev['h'] ?? '') === $row['h'] && ($prev['g'] ?? '') !== 'stock'
            && ($prev['def'] ?? '') === ($row['def'] ?? '') && (isset($prev['a']) || isset($prev['bin']) || isset($prev['big']))) {
            return array_intersect_key($prev, ['a' => 1, 'd' => 1, 'sec' => 1, 'bin' => 1, 'big' => 1, 'eol' => 1]);
        }
        if ($row['s'] > ReleaseZip::MAX_ENTRY_BYTES || $left === null) {
            return ['big' => 1];
        }
        $store = file_get_contents($abs);
        if (!is_string($store)) {
            return ['big' => 1];
        }
        if (LineDiff::isBinary($store) || LineDiff::isBinary($left)) {
            return ['bin' => 1];
        }
        $right = LineDiff::split($store);
        $result = LineDiff::compare(LineDiff::split($left), $right);
        $addedLines = [];
        foreach ($result['modB'] as $i => $changed) {
            if ($changed) {
                $addedLines[] = $right[$i];
            }
        }
        $info = ['a' => $result['added'], 'd' => $result['removed']];
        if ($left !== '' && str_replace("\r\n", "\n", $left) === str_replace("\r\n", "\n", $store)) {
            $info['eol'] = 1;
        }
        if (SecretScreen::flags($addedLines)) {
            $info['sec'] = 1;
        }
        return $info;
    }

    private function addOwner(array &$row, string $path, array $owned): void
    {
        if (!isset($owned[$path])) {
            return;
        }
        $row['own'] = $owned[$path]['label'];
        if (!empty($owned[$path]['note'])) {
            $row['note'] = $owned[$path]['note'];
        }
        $base = $owned[$path]['base'] ?? null;
        if ($base === null) {
            return;
        }
        if ($base['verified']) {
            $row['bv'] = 1;
            if (isset($row['h'])) {
                $row['bs'] = ($row['h'] === $base['sha256']) ? 'equal' : 'differs';
            }
        } else {
            $row['bv'] = 0;
            $row['br'] = $base['reason'];
        }
    }

    private function addMarkers(array &$row, string $path, ?string $releaseSha): void
    {
        if ($this->companion['endings'] === []) {
            return;
        }
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '' : substr($path, 0, $slash + 1);
        $name = $slash === false ? $path : substr($path, $slash + 1);
        $map = $this->dirMarkers($dir);
        if (!isset($map['bases'][$name])) {
            return;
        }
        $prevMarkers = [];
        foreach ($this->hashes[$path]['mk'] ?? [] as $old) {
            $prevMarkers[$old['n']] = $old;
        }
        $list = [];
        foreach ($map['bases'][$name] as $markerName) {
            $abs = $this->layout->absolute($dir . $markerName);
            if (!is_file($abs) || is_link($abs) || !is_readable($abs)) {
                continue;
            }
            $marker = ['n' => $markerName, 's' => (int)filesize($abs), 't' => (int)filemtime($abs)];
            if ($releaseSha !== null && !PathRules::isCredentialName($name)) {
                $old = $prevMarkers[$markerName] ?? null;
                if (!$this->full && $old !== null && $old['s'] === $marker['s'] && $old['t'] === $marker['t'] && isset($old['h'])) {
                    $marker['h'] = $old['h'];
                } else {
                    $this->filesRead++;
                    $marker['h'] = (string)hash_file('sha256', $abs);
                }
                $marker['eq'] = ($marker['h'] === $releaseSha) ? 1 : 0;
            }
            $list[] = $marker;
        }
        if ($list !== []) {
            $row['mk'] = $list;
        }
    }

    private function isMarkerFile(string $path): bool
    {
        if ($this->companion['endings'] === []) {
            return false;
        }
        $slash = strrpos($path, '/');
        $dir = $slash === false ? '' : substr($path, 0, $slash + 1);
        $name = $slash === false ? $path : substr($path, $slash + 1);
        return isset($this->dirMarkers($dir)['markers'][$name]);
    }

    /**
     * For one folder: which names are markers of which sibling, by the declared endings.
     */
    private function dirMarkers(string $dir): array
    {
        if (isset($this->dirCache[$dir])) {
            return $this->dirCache[$dir];
        }
        $abs = $this->layout->absolute($dir === '' ? '' : $dir);
        $names = (is_dir($abs) && is_readable($abs)) ? scandir($abs) : false;
        $present = [];
        foreach (is_array($names) ? $names : [] as $n) {
            if ($n !== '.' && $n !== '..') {
                $present[$n] = true;
            }
        }
        $bases = [];
        $markers = [];
        foreach ($present as $n => $unused) {
            foreach ($this->companion['endings'] as $ending) {
                foreach ($this->basesFor((string)$n, $ending) as $base) {
                    if ($base !== '' && isset($present[$base])) {
                        $bases[$base][] = (string)$n;
                        $markers[$n] = true;
                    }
                }
            }
        }
        foreach ($bases as $b => $list) {
            $bases[$b] = array_values(array_unique($list));
        }
        if (count($this->dirCache) > 2000) {
            $this->dirCache = [];
        }
        return $this->dirCache[$dir] = ['bases' => $bases, 'markers' => $markers];
    }

    private function basesFor(string $name, string $ending): array
    {
        if (substr($ending, -1) !== '*') {
            $len = strlen($ending);
            if (strlen($name) > $len && strcasecmp(substr($name, -$len), $ending) === 0) {
                return [substr($name, 0, -$len)];
            }
            return [];
        }
        $prefix = substr($ending, 0, -1);
        $found = [];
        $offset = 1;
        while (($pos = stripos($name, $prefix, $offset)) !== false) {
            $found[] = substr($name, 0, $pos);
            $offset = $pos + 1;
        }
        return $found;
    }

    private function markChanges(array &$rows): void
    {
        if ($this->previous === []) {
            return;
        }
        foreach ($rows as $path => &$row) {
            $prev = $this->previous[$path] ?? null;
            $was = $prev['g'] ?? null;
            $mark = null;
            switch ($row['g']) {
                case 'edit':
                    if ($was === null || $was === 'stock' || $was === 'missing') {
                        $mark = 'new';
                    } elseif (($prev['h'] ?? '') !== $row['h']) {
                        $mark = 'changed';
                    }
                    break;
                case 'stock':
                    if ($was === 'edit' || $was === 'missing') {
                        $mark = 'back';
                    }
                    break;
                case 'missing':
                    if ($was === 'stock' || $was === 'edit') {
                        $mark = 'gone';
                    }
                    break;
                case 'override':
                    if ($was === null || $was === 'override_gone') {
                        $mark = 'new';
                    } elseif (($prev['h'] ?? '') !== $row['h']) {
                        $mark = 'changed';
                    }
                    break;
            }
            if ($mark !== null) {
                $row['chg'] = $mark;
            }
        }
        unset($row);
        // An override whose file was removed is listed once so the owner sees it went away.
        foreach ($this->previous as $path => $prev) {
            if (($prev['g'] ?? '') === 'override' && !isset($rows[$path]) && !file_exists($this->layout->absolute((string)$path))) {
                $rows[$path] = ['g' => 'override_gone', 'def' => $prev['def'] ?? '', 'chg' => 'back'];
            }
        }
    }
}
