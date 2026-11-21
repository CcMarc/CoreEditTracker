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

zen_define_default('FILENAME_CORE_EDIT_TRACKER', 'core_edit_tracker');
zen_define_default('TABLE_CORE_EDIT_TRACKER_STATE', DB_PREFIX . 'core_edit_tracker_state');
zen_define_default('TABLE_CORE_EDIT_TRACKER_SCANS', DB_PREFIX . 'core_edit_tracker_scans');
