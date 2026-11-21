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
 * Storage in the tracker's own tables.
 */
class DbStorage implements Storage
{
    public const KEEP_SCANS = 10;

    private $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    public function getState(string $key): ?array
    {
        $result = $this->db->Execute(
            "SELECT state_value FROM " . TABLE_CORE_EDIT_TRACKER_STATE . " WHERE state_key = '" . zen_db_input($key) . "' LIMIT 1"
        );
        if ($result->EOF) {
            return null;
        }
        $value = json_decode((string)$result->fields['state_value'], true);
        return is_array($value) ? $value : null;
    }

    public function setState(string $key, ?array $value): void
    {
        if ($value === null) {
            $this->db->Execute("DELETE FROM " . TABLE_CORE_EDIT_TRACKER_STATE . " WHERE state_key = '" . zen_db_input($key) . "'");
            return;
        }
        $json = (string)json_encode($value, JSON_UNESCAPED_SLASHES);
        $this->db->Execute(
            "INSERT INTO " . TABLE_CORE_EDIT_TRACKER_STATE . " (state_key, state_value)
             VALUES ('" . zen_db_input($key) . "', '" . zen_db_input($json) . "')
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value)"
        );
    }

    public function latestScans(int $limit): array
    {
        $result = $this->db->Execute(
            "SELECT scan_id, scanned_at, scan_kind, scan_data FROM " . TABLE_CORE_EDIT_TRACKER_SCANS . "
             ORDER BY scan_id DESC LIMIT " . max(1, $limit)
        );
        $scans = [];
        foreach ($result as $row) {
            $raw = base64_decode((string)$row['scan_data'], true);
            $json = ($raw === false) ? false : gzuncompress($raw);
            $data = ($json === false) ? null : json_decode($json, true);
            if (!is_array($data)) {
                continue;
            }
            $scans[] = ['id' => (int)$row['scan_id'], 'at' => strtotime((string)$row['scanned_at']), 'kind' => (string)$row['scan_kind'], 'data' => $data];
        }
        return $scans;
    }

    public function saveScan(array $data): int
    {
        $blob = base64_encode((string)gzcompress((string)json_encode($data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), 6));
        $this->db->Execute(
            "INSERT INTO " . TABLE_CORE_EDIT_TRACKER_SCANS . " (scanned_at, scan_kind, scan_data)
             VALUES (now(), '" . zen_db_input((string)$data['kind']) . "', '" . $blob . "')"
        );
        $id = (int)$this->db->Insert_ID();
        $old = $this->db->Execute(
            "SELECT scan_id FROM " . TABLE_CORE_EDIT_TRACKER_SCANS . " ORDER BY scan_id DESC LIMIT " . self::KEEP_SCANS . ", 1"
        );
        if (!$old->EOF) {
            $this->db->Execute("DELETE FROM " . TABLE_CORE_EDIT_TRACKER_SCANS . " WHERE scan_id <= " . (int)$old->fields['scan_id']);
        }
        return $id;
    }
}
