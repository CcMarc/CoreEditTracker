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
 * Ties the release, the scan, the scan history and the export together. It makes no Zen Cart calls,
 * so it runs the same in the admin and from the command line.
 */
class Tracker
{
    private Storage $storage;
    public StoreLayout $layout;
    private array $pluginDirs;
    private string $major;
    private string $minor;
    public DataFolder $folder;
    private ?array $companion = null;
    public string $storeUrl = '';
    public bool $acceptCompanions = true;
    public array $pluginVersions = [];

    public function __construct(Storage $storage, StoreLayout $layout, array $pluginDirs, string $major, string $minor, DataFolder $folder)
    {
        $this->storage = $storage;
        $this->layout = $layout;
        $this->pluginDirs = $pluginDirs;
        $this->major = $major;
        $this->minor = $minor;
        $this->folder = $folder;
    }

    public function storeVersion(): string
    {
        return $this->major . '.' . $this->minor;
    }

    /**
     * The release in use, or null when there is none or it is for another Zen Cart version than
     * the store runs now.
     */
    public function release(): ?array
    {
        $release = $this->savedRelease();
        if ($release === null || ($release['version'] ?? '') !== $this->storeVersion()) {
            return null;
        }
        return $release;
    }

    /**
     * The release kept from before a Zen Cart upgrade, or null. It is replaced when the owner
     * adds the zip of the version the store runs now.
     */
    public function outdatedRelease(): ?array
    {
        $release = $this->savedRelease();
        return ($release !== null && ($release['version'] ?? '') !== $this->storeVersion()) ? $release : null;
    }

    private function savedRelease(): ?array
    {
        $release = $this->storage->getState('release');
        if ($release === null || !isset($release['id']) || !is_file($this->folder->zipFile((string)$release['id']))) {
            return null;
        }
        return $release;
    }

    /**
     * Checks a zip and keeps it as the release. Nothing is kept when the check fails.
     */
    public function useZip(string $file, bool $uploaded, string $name): array
    {
        $check = ReleaseZip::inspect($file, $this->major, $this->minor);
        if (!$check['ok']) {
            return $check;
        }
        $id = $this->folder->keepZip($file, $uploaded);
        if ($id === null) {
            return ['ok' => false, 'reason' => 'cannot_store', 'detail' => ''];
        }
        if (!$this->folder->writeManifest($id, ['top' => $check['top'], 'version' => $check['version'], 'files' => $check['files']])) {
            $this->folder->remove($id);
            return ['ok' => false, 'reason' => 'cannot_store', 'detail' => ''];
        }
        if (!$uploaded) {
            $this->folder->removePlaced($file);
        }
        $old = $this->storage->getState('release');
        if ($old !== null && isset($old['id']) && $old['id'] !== $id) {
            $this->folder->remove((string)$old['id']);
        }
        $this->storage->setState('folder', $this->folder->toState());
        $this->testFolder(true);
        $this->storage->setState('release', [
            'id' => $id,
            'top' => $check['top'],
            'version' => $check['version'],
            'name' => substr(preg_replace('~[^A-Za-z0-9._\- ]~', '', basename($name)), 0, 120),
            'files' => count($check['files']),
            'not_expected' => $check['not_expected'],
            'added_at' => time(),
        ]);
        return ['ok' => true, 'version' => $check['version'], 'files' => count($check['files'])];
    }

    public function folderCheck(): ?array
    {
        $check = $this->storage->getState('folder_check');
        return ($check !== null && ($check['path'] ?? '') === $this->folder->path) ? $check : null;
    }

    /**
     * Runs the folder self-test unless the folder is known to be protected or outside the web root.
     * Called from POST actions only (install, adding a release, a scan), never from a plain GET.
     */
    public function testFolder(bool $force): array
    {
        $last = $this->folderCheck();
        if (!$force && $last !== null) {
            $result = $last['result'] ?? '';
            if ($result === 'protected' || $result === 'outside') {
                return $last;
            }
        }
        $check = $this->folder->probe($this->layout->catalog, $this->storeUrl) + ['path' => $this->folder->path, 'at' => time()];
        $this->storage->setState('folder_check', $check);
        return $check;
    }

    /**
     * Moves the release to another data folder and makes that folder the one in use.
     * Returns false and changes nothing when the new folder cannot be written.
     */
    public function moveFolder(DataFolder $target): bool
    {
        if ($target->path === $this->folder->path) {
            return true;
        }
        if (!$target->ensure()) {
            return false;
        }
        $release = $this->storage->getState('release');
        $id = ($release !== null && isset($release['id'])) ? (string)$release['id'] : null;
        if ($id !== null && is_file($this->folder->zipFile($id)) && !$this->folder->moveRelease($id, $target)) {
            return false;
        }
        $this->folder->removeOwnFiles();
        $this->folder = $target;
        $this->storage->setState('folder', $target->toState());
        $this->testFolder(true);
        return true;
    }

    public function removeRelease(): void
    {
        $release = $this->storage->getState('release');
        if ($release !== null && isset($release['id'])) {
            $this->folder->remove((string)$release['id']);
        }
        $this->storage->setState('release', null);
    }

    /**
     * What companion plugins declare: the files they own and the endings of marker copies.
     */
    public function companion(): array
    {
        if (!$this->acceptCompanions) {
            return $this->companion ??= ['present' => false, 'off' => true, 'owned' => [], 'endings' => [], 'refused' => []];
        }
        return $this->companion ??= Companion::read($this->layout->catalog, $this->pluginDirs);
    }

    public function releaseZip(): ?ReleaseZip
    {
        $release = $this->release();
        return $release === null ? null : new ReleaseZip($this->folder->zipFile((string)$release['id']), (string)$release['top']);
    }

    /**
     * Newest scan first, at most two.
     */
    public function lastScans(): array
    {
        return $this->storage->latestScans(2);
    }

    /**
     * An export's rescan is saved for its hashes, but the change marks of later scans are still
     * counted from the last scan the owner looked at.
     */
    public function scan(bool $full, bool $forExport = false): ?array
    {
        $release = $this->release();
        if ($release === null) {
            return null;
        }
        $manifest = $this->folder->readManifest((string)$release['id']);
        if ($manifest === null) {
            return null;
        }
        $this->testFolder(false);
        $scans = $this->storage->latestScans(10);
        $last = $scans[0]['data'] ?? null;
        $looked = null;
        foreach ($scans as $scan) {
            if (empty($scan['data']['export'])) {
                $looked = $scan['data'];
                break;
            }
        }
        $hashes = is_array($last) ? ($last['rows'] ?? []) : [];
        $previous = is_array($looked) ? ($looked['rows'] ?? []) : $hashes;
        $sameRelease = is_array($last) && ($last['release'] ?? '') === $release['id'];
        $scanner = new Scanner($this->layout, $manifest['files'], $this->releaseZip(), $this->companion(), $previous, $full, $sameRelease, $hashes);
        $data = $scanner->run();
        $data['release'] = $release['id'];
        $data['version'] = $release['version'];
        $data['export'] = $forExport;
        $this->storage->saveScan($data);
        return $data;
    }

    public function export(array $options): ?array
    {
        $scan = $this->scan(true, true);
        $zip = $this->releaseZip();
        $release = $this->release();
        $manifest = $release === null ? null : $this->folder->readManifest((string)$release['id']);
        if ($scan === null || $zip === null || $manifest === null) {
            return null;
        }
        $options += ['templates' => $this->layout->templates, 'plugins' => $this->pluginVersions];
        return ExportBuilder::build($scan, $this->layout, $zip, $this->companion(), (string)$scan['version'], $options, $manifest['files']);
    }
}
