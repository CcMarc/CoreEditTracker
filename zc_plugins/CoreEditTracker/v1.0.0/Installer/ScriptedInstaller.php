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
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}

use Zencart\PluginSupport\ScriptedInstaller as ScriptedInstallBase;

class ScriptedInstaller extends ScriptedInstallBase
{
    private const CONFIG_GROUP = 'Core Edit Tracker';

    protected function executeInstall()
    {
        return $this->installOrUpgrade();
    }

    protected function executeUpgrade($oldVersion)
    {
        return $this->installOrUpgrade();
    }

    protected function executeUninstall()
    {
        $this->defineTables();
        if ($this->tableExists(TABLE_CORE_EDIT_TRACKER_STATE)) {
            $folder = $this->readState('folder');
            if ($folder !== null && isset($folder['path']) && is_string($folder['path']) && $this->loadDataFolder()) {
                (new \Zencart\Plugins\Admin\CoreEditTracker\DataFolder($folder['path'], !empty($folder['outside'])))->removeOwnFiles();
            }
        }
        zen_deregister_admin_pages(['toolsCoreEditTracker', 'configCoreEditTracker']);
        $this->deleteConfigurationKeys(['CORE_EDIT_TRACKER_ACCEPT_COMPANIONS']);
        if ($this->getConfigurationGroupDetails(self::CONFIG_GROUP, true) !== false) {
            $this->deleteConfigurationGroup(self::CONFIG_GROUP);
        }
        $this->executeInstallerSql("DROP TABLE IF EXISTS " . TABLE_CORE_EDIT_TRACKER_SCANS);
        $this->executeInstallerSql("DROP TABLE IF EXISTS " . TABLE_CORE_EDIT_TRACKER_STATE);
        return true;
    }

    private function installOrUpgrade(): bool
    {
        $this->defineTables();
        $ok = $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . TABLE_CORE_EDIT_TRACKER_STATE . " (
                state_key VARCHAR(64) NOT NULL,
                state_value MEDIUMTEXT NOT NULL,
                PRIMARY KEY (state_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        ) && $this->executeInstallerSql(
            "CREATE TABLE IF NOT EXISTS " . TABLE_CORE_EDIT_TRACKER_SCANS . " (
                scan_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
                scanned_at DATETIME NOT NULL,
                scan_kind VARCHAR(8) NOT NULL,
                scan_data MEDIUMTEXT NOT NULL,
                PRIMARY KEY (scan_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
        );
        if (!$ok) {
            return false;
        }
        if (!zen_page_key_exists('toolsCoreEditTracker')) {
            zen_register_admin_page('toolsCoreEditTracker', 'BOX_TOOLS_CORE_EDIT_TRACKER', 'FILENAME_CORE_EDIT_TRACKER', '', 'tools', 'Y');
        }
        // Zen Cart stores these titles in the database. Each has a language constant that overrides it
        // (CFG_GRP_TITLE_, CFGTITLE_, CFGDESC_) in the extra_definitions language file.
        $gid = $this->getOrCreateConfigGroupId(self::CONFIG_GROUP, 'Core Edit Tracker settings', 0);
        $this->addConfigurationKey('CORE_EDIT_TRACKER_ACCEPT_COMPANIONS', [
            'configuration_title' => 'Accept companion plugins',
            'configuration_value' => 'true',
            'configuration_description' => 'Let other plugins tell the tracker which files they own and which file endings are marker copies. Set to false to ignore them.',
            'configuration_group_id' => $gid,
            'sort_order' => 10,
            'set_function' => "zen_cfg_select_option(['true', 'false'], ",
        ]);
        if (!zen_page_key_exists('configCoreEditTracker')) {
            zen_register_admin_page('configCoreEditTracker', 'BOX_CONFIGURATION_CORE_EDIT_TRACKER', 'FILENAME_CONFIGURATION', 'gID=' . $gid, 'configuration', 'Y', $gid);
        }
        $this->prepareDataFolder();
        return true;
    }

    // The data folder is made here, so the page itself never writes on a plain visit.
    private function prepareDataFolder(): void
    {
        if (!$this->loadDataFolder()) {
            return;
        }
        $folder = \Zencart\Plugins\Admin\CoreEditTracker\DataFolder::choose(DIR_FS_CATALOG, (string)($_SERVER['DOCUMENT_ROOT'] ?? ''), DIR_FS_SQL_CACHE, $this->readState('folder'));
        if ($folder->ensure()) {
            $this->writeState('folder', $folder->toState());
            $check = $folder->probe(DIR_FS_CATALOG, $folder::storeUrl()) + ['path' => $folder->path, 'at' => time()];
            $this->writeState('folder_check', $check);
        }
    }

    private function writeState(string $key, array $value): void
    {
        $json = (string)json_encode($value, JSON_UNESCAPED_SLASHES);
        $this->executeInstallerSql(
            "INSERT INTO " . TABLE_CORE_EDIT_TRACKER_STATE . " (state_key, state_value)
             VALUES ('" . zen_db_input($key) . "', '" . zen_db_input($json) . "')
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)"
        );
    }

    private function loadDataFolder(): bool
    {
        $class = 'Zencart\Plugins\Admin\CoreEditTracker\DataFolder';
        if (!class_exists($class, false)) {
            $file = dirname(__DIR__) . '/admin/includes/classes/DataFolder.php';
            if (!is_file($file)) {
                return false;
            }
            require_once $file;
        }
        return class_exists($class, false);
    }

    private function readState(string $key): ?array
    {
        $result = $this->dbConn->Execute(
            "SELECT state_value FROM " . TABLE_CORE_EDIT_TRACKER_STATE . " WHERE state_key = '" . zen_db_input($key) . "' LIMIT 1"
        );
        if ($result->EOF) {
            return null;
        }
        $value = json_decode((string)$result->fields['state_value'], true);
        return is_array($value) ? $value : null;
    }

    private function tableExists(string $table): bool
    {
        $result = $this->dbConn->Execute("SHOW TABLES LIKE '" . zen_db_input($table) . "'");
        return !$result->EOF;
    }

    private function defineTables(): void
    {
        zen_define_default('TABLE_CORE_EDIT_TRACKER_STATE', DB_PREFIX . 'core_edit_tracker_state');
        zen_define_default('TABLE_CORE_EDIT_TRACKER_SCANS', DB_PREFIX . 'core_edit_tracker_scans');
    }
}
