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
 * Where the tracker keeps its state and scan history.
 */
interface Storage
{
    public function getState(string $key): ?array;

    public function setState(string $key, ?array $value): void;

    /**
     * Newest first: each ['id' => int, 'at' => int, 'kind' => string, 'data' => array].
     */
    public function latestScans(int $limit): array;

    public function saveScan(array $data): int;
}
