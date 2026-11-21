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
    'ADMIN_PLUGIN_MANAGER_NAME_FOR_COREEDITTRACKER' => 'Core Edit Tracker',
    'ADMIN_PLUGIN_MANAGER_DESCRIPTION_FOR_COREEDITTRACKER' => 'Compares the store with the official Zen Cart release it runs. Lists every edited core file and template override, shows each difference side by side, marks what changed since the last look, and exports your files with their originals so your edits can be carried to the next release. View only.',
];
return $define;
