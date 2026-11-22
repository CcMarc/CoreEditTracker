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

$define = [
    'BOX_TOOLS_CORE_EDIT_TRACKER' => 'Core Edit Tracker',
    'BOX_CONFIGURATION_CORE_EDIT_TRACKER' => 'Core Edit Tracker',
    'CFG_GRP_TITLE_CORE_EDIT_TRACKER' => 'Core Edit Tracker',
    'CFGTITLE_CORE_EDIT_TRACKER_ACCEPT_COMPANIONS' => 'Accept companion plugins',
    'CFGDESC_CORE_EDIT_TRACKER_ACCEPT_COMPANIONS' => 'Let other plugins tell the tracker which files they own and which file endings are marker copies. Set to false to ignore them.',
];
return $define;
