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

require 'includes/application_top.php';

use Zencart\Plugins\Admin\CoreEditTracker\DataFolder;
use Zencart\Plugins\Admin\CoreEditTracker\DbStorage;
use Zencart\Plugins\Admin\CoreEditTracker\DiffView;
use Zencart\Plugins\Admin\CoreEditTracker\ExportBuilder;
use Zencart\Plugins\Admin\CoreEditTracker\LineDiff;
use Zencart\Plugins\Admin\CoreEditTracker\PathRules;
use Zencart\Plugins\Admin\CoreEditTracker\ReleaseZip;
use Zencart\Plugins\Admin\CoreEditTracker\StoreLayout;
use Zencart\Plugins\Admin\CoreEditTracker\Tracker;

$cetEsc = static fn($text): string => DiffView::esc((string)$text);
$cetTip = static fn(string $text): string => ' <span class="cet-tip" tabindex="0" role="note" title="' . DiffView::esc($text) . '" aria-label="' . DiffView::esc($text) . '">i</span>';
$cetUrl = static fn(string $params = ''): string => zen_href_link(FILENAME_CORE_EDIT_TRACKER, $params);
$cetBytes = static function ($bytes): string {
    $bytes = (int)$bytes;
    if ($bytes >= 1048576) {
        return sprintf(CET_SIZE_MB, number_format($bytes / 1048576, 1));
    }
    return $bytes >= 1024 ? sprintf(CET_SIZE_KB, number_format($bytes / 1024, 1)) : sprintf(CET_SIZE_B, $bytes);
};
$cetDate = static fn($time): string => $time ? date('Y-m-d H:i', (int)$time) : '';
$cetText = static function (string $prefix, string $code, string $fallback = ''): string {
    $key = $prefix . strtoupper(preg_replace('~[^A-Za-z0-9]+~', '_', $code));
    return defined($key) ? (string)constant($key) : ($fallback !== '' ? $fallback : $code);
};

// Every template the store has assigned, the default language's template first, chosen the way
// includes/init_includes/init_templates.php chooses it.
$cetLanguageId = 0;
$cetLang = $db->Execute("SELECT languages_id FROM " . TABLE_LANGUAGES . " WHERE code = '" . zen_db_input(DEFAULT_LANGUAGE) . "' LIMIT 1");
if (!$cetLang->EOF) {
    $cetLanguageId = (int)$cetLang->fields['languages_id'];
}
$cetTemplates = [];
$cetTpl = $db->Execute(
    "SELECT template_dir, template_language = " . $cetLanguageId . " AS choice1, template_language = 0 AS choice2
     FROM " . TABLE_TEMPLATE_SELECT . " ORDER BY choice1 DESC, choice2 DESC, template_language"
);
foreach ($cetTpl as $cetTplRow) {
    $cetTemplates[] = (string)$cetTplRow['template_dir'];
}
if ($cetTemplates === []) {
    $cetTemplates[] = 'template_default';
}

$cetPluginDirs = [];
$cetPluginVersions = [];
foreach ($installedPlugins as $cetKey => $cetPlugin) {
    $cetPluginDirs[(string)$cetKey] = DIR_FS_CATALOG . 'zc_plugins/' . $cetKey . '/' . $cetPlugin['version'] . '/';
    $cetPluginVersions[(string)$cetKey] = (string)$cetPlugin['version'];
}

$cetDocRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
$cetStorage = new DbStorage($db);
$cetFolder = DataFolder::choose(DIR_FS_CATALOG, $cetDocRoot, DIR_FS_SQL_CACHE, $cetStorage->getState('folder'));
$cetLayout = new StoreLayout(DIR_FS_CATALOG, DIR_FS_ADMIN, $cetTemplates);
$cetTracker = new Tracker($cetStorage, $cetLayout, $cetPluginDirs, PROJECT_VERSION_MAJOR, PROJECT_VERSION_MINOR, $cetFolder);
$cetTracker->storeUrl = DataFolder::storeUrl();
$cetTracker->pluginVersions = $cetPluginVersions;
$cetTracker->acceptCompanions = !(defined('CORE_EDIT_TRACKER_ACCEPT_COMPANIONS') && CORE_EDIT_TRACKER_ACCEPT_COMPANIONS === 'false');
$cetVersion = PROJECT_VERSION_MAJOR . '.' . PROJECT_VERSION_MINOR;

// Every change of state is a POST with the admin security token.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cetToken = $_POST['securityToken'] ?? '';
    if ($_POST === [] && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $messageStack->add_session(CET_MSG_UPLOAD_TOO_LARGE, 'error');
        zen_redirect($cetUrl('done=1'));
    }
    if (!is_string($cetToken) || !isset($_SESSION['securityToken']) || !hash_equals((string)$_SESSION['securityToken'], $cetToken)) {
        $messageStack->add_session(CET_MSG_BAD_REQUEST, 'error');
        zen_redirect($cetUrl('done=1'));
    }
    $cetAction = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $cetReturn = 'done=1';
    $cetRefused = static fn(array $result): string => sprintf(
        $cetText('CET_REASON_', (string)$result['reason']),
        (string)($result['detail'] ?? '')
    );
    switch ($cetAction) {
        case 'upload':
            $cetFile = $_FILES['release_zip'] ?? null;
            if (!is_array($cetFile) || !isset($cetFile['error']) || is_array($cetFile['error'])) {
                $messageStack->add_session(CET_MSG_BAD_REQUEST, 'error');
            } elseif ($cetFile['error'] === UPLOAD_ERR_INI_SIZE || $cetFile['error'] === UPLOAD_ERR_FORM_SIZE) {
                $messageStack->add_session(CET_MSG_UPLOAD_TOO_LARGE, 'error');
            } elseif ($cetFile['error'] !== UPLOAD_ERR_OK) {
                $messageStack->add_session(sprintf(CET_MSG_UPLOAD_ERROR, (int)$cetFile['error']), 'error');
            } elseif (!is_uploaded_file($cetFile['tmp_name'])) {
                $messageStack->add_session(CET_MSG_BAD_REQUEST, 'error');
            } else {
                $cetResult = $cetTracker->useZip($cetFile['tmp_name'], true, (string)$cetFile['name']);
                if ($cetResult['ok']) {
                    $messageStack->add_session(sprintf(CET_MSG_RELEASE_OK, $cetEsc($cetResult['version']), (int)$cetResult['files']), 'success');
                    $cetReturn = '';
                } else {
                    if (is_file($cetFile['tmp_name'])) {
                        unlink($cetFile['tmp_name']);
                    }
                    $messageStack->add_session(sprintf(CET_MSG_REFUSED, $cetEsc($cetRefused($cetResult))) . ' ' . CET_MSG_NOTHING_KEPT, 'error');
                }
            }
            break;

        case 'use_placed':
            $cetName = is_string($_POST['placed'] ?? null) ? $_POST['placed'] : '';
            $cetPlaced = $cetFolder->placedFile($cetName);
            if ($cetPlaced === null) {
                $messageStack->add_session(CET_MSG_BAD_REQUEST, 'error');
                break;
            }
            $cetResult = $cetTracker->useZip($cetPlaced, false, $cetName);
            if ($cetResult['ok']) {
                $messageStack->add_session(sprintf(CET_MSG_RELEASE_OK, $cetEsc($cetResult['version']), (int)$cetResult['files']), 'success');
                $cetReturn = '';
            } else {
                $messageStack->add_session(sprintf(CET_MSG_REFUSED, $cetEsc($cetRefused($cetResult))) . ' ' . CET_MSG_NOTHING_KEPT . ' ' . CET_MSG_PLACED_LEFT, 'error');
            }
            break;

        case 'remove_release':
            $cetTracker->removeRelease();
            $messageStack->add_session(CET_MSG_RELEASE_REMOVED, 'success');
            break;

        case 'move_folder':
            $cetChoice = is_string($_POST['folder_choice'] ?? null) ? $_POST['folder_choice'] : '';
            $cetOptions = DataFolder::options(DIR_FS_CATALOG, $cetDocRoot, DIR_FS_SQL_CACHE);
            $cetTarget = null;
            $cetWhy = 'not_writable';
            if ($cetChoice === 'custom') {
                $cetCustom = DataFolder::custom(is_string($_POST['folder_path'] ?? null) ? $_POST['folder_path'] : '', DIR_FS_CATALOG, $cetDocRoot);
                $cetTarget = $cetCustom['folder'] ?? null;
                $cetWhy = $cetCustom['reason'] ?? $cetWhy;
            } elseif (isset($cetOptions[$cetChoice]) && $cetOptions[$cetChoice]['available']) {
                $cetTarget = new DataFolder($cetOptions[$cetChoice]['path'], $cetOptions[$cetChoice]['outside']);
            }
            if ($cetTarget !== null && $cetTracker->moveFolder($cetTarget)) {
                $messageStack->add_session(sprintf(CET_MSG_FOLDER_MOVED, $cetEsc($cetTarget->path)), 'success');
            } else {
                $messageStack->add_session(sprintf(CET_MSG_FOLDER_REFUSED, $cetEsc($cetText('CET_FOLDER_REASON_', $cetWhy))), 'error');
            }
            $cetReturn = 'done=1&view=folder';
            break;

        case 'scan':
        case 'rescan':
            if ($cetTracker->scan($cetAction === 'rescan') === null) {
                $messageStack->add_session(CET_MSG_NO_RELEASE, 'error');
            }
            foreach (['group', 'q', 'sort'] as $cetKeep) {
                if (isset($_POST[$cetKeep]) && is_string($_POST[$cetKeep]) && $_POST[$cetKeep] !== '') {
                    $cetReturn .= '&' . $cetKeep . '=' . rawurlencode(substr($_POST[$cetKeep], 0, 200));
                }
            }
            break;

        case 'export':
            $cetExport = $cetTracker->export([
                'include_secrets' => !empty($_POST['include_secrets']),
                'readme' => sprintf(CET_EXPORT_README, $cetVersion, ExportBuilder::ORIGINAL_ENDING),
                'readme_plugin' => sprintf(CET_EXPORT_README_PLUGIN, ExportBuilder::PLUGIN_ENDING),
                'readme_configure' => sprintf(CET_EXPORT_README_CONFIGURE, $cetVersion, ExportBuilder::REFERENCE_ROOT),
            ]);
            if ($cetExport === null) {
                $messageStack->add_session(CET_MSG_EXPORT_FAILED, 'error');
                $cetReturn = 'done=1&view=export';
                break;
            }
            $cetExportFile = $cetExport['file'];
            ignore_user_abort(true);
            register_shutdown_function(static function () use ($cetExportFile): void {
                if (is_file($cetExportFile)) {
                    unlink($cetExportFile);
                }
            });
            while (ob_get_level() > 0) {
                ob_end_clean();
            }
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $cetExport['name'] . '"');
            header('Content-Length: ' . filesize($cetExport['file']));
            header('Cache-Control: no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($cetExport['file']);
            unlink($cetExport['file']);
            exit;

        default:
            $messageStack->add_session(CET_MSG_BAD_REQUEST, 'error');
    }
    zen_redirect($cetUrl($cetReturn));
}

// From here on the page only reads.
$cetRelease = $cetTracker->release();
$cetScans = $cetRelease === null ? [] : $cetTracker->lastScans();
$cetScan = $cetScans[0]['data'] ?? null;
$cetView = (isset($_GET['view']) && in_array($_GET['view'], ['compare', 'export', 'folder'], true)) ? $_GET['view'] : 'list';
$cetAutoScan = $cetRelease !== null && $cetView === 'list' && !isset($_GET['done']);
$cetGroups = ['edit', 'override', 'owned', 'missing', 'changed', 'all'];
$cetGroup = (isset($_GET['group']) && in_array($_GET['group'], $cetGroups, true)) ? $_GET['group'] : 'edit';
$cetQuery = (isset($_GET['q']) && is_string($_GET['q'])) ? substr(trim($_GET['q']), 0, 200) : '';
$cetSort = (isset($_GET['sort']) && in_array($_GET['sort'], ['changed', 'date'], true)) ? $_GET['sort'] : 'folder';
$cetHideEol = !empty($_GET['hide_eol']);
$cetPeek = (isset($_GET['peek']) && is_string($_GET['peek'])) ? $_GET['peek'] : '';
$cetListParams = 'done=1' . ($cetGroup !== 'edit' ? '&group=' . $cetGroup : '') . ($cetQuery !== '' ? '&q=' . rawurlencode($cetQuery) : '')
    . ($cetSort !== 'folder' ? '&sort=' . $cetSort : '') . ($cetHideEol ? '&hide_eol=1' : '');

$cetFolderStatus = static function () use ($cetTracker, $cetFolder, $cetEsc, $cetDate, $cetText): string {
    if ($cetFolder->outsideWebRoot) {
        return '<span class="cet-chip cet-chip-ok">' . $cetEsc(CET_FOLDER_OUTSIDE) . '</span>';
    }
    $check = $cetTracker->folderCheck();
    $result = $check['result'] ?? '';
    if ($result === 'protected') {
        return '<span class="cet-chip cet-chip-ok" title="' . $cetEsc(sprintf(CET_CHECK_TESTED, $cetDate($check['at']))) . '">' . $cetEsc(CET_CHECK_PROTECTED) . '</span>';
    }
    if ($result === 'reachable') {
        return '<span class="cet-chip cet-chip-bad">' . $cetEsc(CET_CHECK_REACHABLE) . '</span>';
    }
    if ($result === 'untested') {
        $detail = (string)($check['detail'] ?? '');
        $why = preg_match('~^(request_failed|answered)_(\d+)$~', $detail, $m) === 1
            ? sprintf($cetText('CET_CHECK_WHY_', $m[1]), $m[2])
            : $cetText('CET_CHECK_WHY_', $detail);
        return '<span class="cet-chip cet-chip-warn" title="' . $cetEsc($why) . '">' . $cetEsc(CET_CHECK_UNTESTED) . '</span>';
    }
    return '<span class="cet-chip">' . $cetEsc(CET_CHECK_NONE) . '</span>';
};

/**
 * Who a row belongs to in the lists: the store owner, or the plugin that owns the file.
 */
$cetInGroup = static function (array $row, string $group): bool {
    $owned = isset($row['own']);
    switch ($group) {
        case 'edit':
        case 'override':
        case 'missing':
            return $row['g'] === $group && !$owned;
        case 'owned':
            return $owned && in_array($row['g'], ['edit', 'override', 'missing'], true);
        case 'changed':
            return isset($row['chg']);
        default:
            return $row['g'] !== 'stock' || isset($row['chg']);
    }
};

$cetChips = static function (array $row, bool $withOwner) use ($cetEsc, $cetText): string {
    $chips = [];
    if (isset($row['chg'])) {
        $chips[] = '<span class="cet-chip cet-chip-new">' . $cetEsc($cetText('CET_MARK_', (string)$row['chg'])) . '</span>';
    }
    if ($withOwner && isset($row['own'])) {
        $chips[] = '<span class="cet-chip cet-chip-plugin"' . (isset($row['note']) ? ' title="' . $cetEsc($row['note']) . '"' : '') . '>' . $cetEsc($row['own']) . '</span>';
    }
    if (isset($row['bs'])) {
        $chips[] = $row['bs'] === 'equal'
            ? '<span class="cet-chip cet-chip-ok">' . $cetEsc(CET_BASE_EQUAL) . '</span>'
            : '<span class="cet-chip cet-chip-warn">' . $cetEsc(CET_BASE_DIFFERS) . '</span>';
    } elseif (isset($row['br'])) {
        $chips[] = '<span class="cet-chip" title="' . $cetEsc($cetText('CET_BASE_', (string)$row['br'])) . '">' . $cetEsc(CET_BASE_REFUSED) . '</span>';
    }
    if (!empty($row['same'])) {
        $chips[] = '<span class="cet-chip" title="' . $cetEsc(CET_SAME_COPY_TIP) . '">' . $cetEsc(CET_SAME_COPY) . '</span>';
    }
    if (($row['g'] ?? '') === 'override' && ($row['def'] ?? '') === '') {
        $chips[] = '<span class="cet-chip" title="' . $cetEsc(CET_TEMPLATE_ONLY_TIP) . '">' . $cetEsc(CET_TEMPLATE_ONLY) . '</span>';
    }
    foreach (['sec' => ['CET_SECRET', 'cet-chip-warn'], 'eol' => ['CET_EOL_ONLY', ''], 'bin' => ['CET_BINARY', ''], 'big' => ['CET_TOO_LARGE', ''], 'ur' => ['CET_UNREADABLE', 'cet-chip-bad']] as $flag => [$text, $class]) {
        if (!empty($row[$flag])) {
            $chips[] = '<span class="cet-chip ' . $class . '">' . $cetEsc(constant($text)) . '</span>';
        }
    }
    foreach ($row['mk'] ?? [] as $marker) {
        $text = !isset($marker['eq']) ? CET_MARKER : ($marker['eq'] ? CET_MARKER_EQUAL : CET_MARKER_DIFFERS);
        $chips[] = '<span class="cet-chip">' . $cetEsc(sprintf($text, $marker['n'])) . '</span>';
    }
    return implode(' ', $chips);
};

$cetLines = static function (array $row) use ($cetEsc): string {
    if (!isset($row['a'])) {
        return '';
    }
    $a = (int)$row['a'];
    $d = (int)$row['d'];
    $scale = static fn(int $n): int => $n === 0 ? 0 : (int)min(48, max(3, round(9 * log($n + 1, 4))));
    return '<span class="cet-bar" aria-hidden="true"><span class="cet-bar-a" style="width:' . $scale($a) . 'px"></span><span class="cet-bar-d" style="width:' . $scale($d) . 'px"></span></span>'
        . '<span class="cet-mono"><b class="cet-plus">+' . $a . '</b> <b class="cet-minus">&minus;' . $d . '</b></span>';
};

/**
 * The two versions of a file for the difference views. $against is 'release' or 'base'.
 * Returns ['state' => ok|missing|unavailable|binary] and, when ok, left, right and left_name.
 */
$cetPair = static function (string $path, array $row, string $against) use ($cetTracker, $cetLayout): array {
    if ($row['g'] === 'missing' || $row['g'] === 'override_gone' || $row['g'] === 'added_missing') {
        return ['state' => 'missing'];
    }
    $left = null;
    $name = '';
    if ($against === 'base') {
        $base = $cetTracker->companion()['owned'][$path]['base'] ?? null;
        if ($base !== null && $base['verified']) {
            $data = (string)file_get_contents($base['file']);
            if (hash_equals($base['sha256'], hash('sha256', $data))) {
                $left = $data;
                $name = (string)$base['shown'];
            }
        }
    } elseif ($row['g'] === 'override') {
        $name = (string)($row['def'] ?? '');
        $left = $name === '' ? '' : $cetTracker->releaseZip()->read($name);
    } elseif ($row['g'] === 'added') {
        $left = '';
    } else {
        $name = $path;
        $left = $cetTracker->releaseZip()->read($path);
    }
    $check = $cetLayout->storeFile($path);
    if ($left === null || !isset($check['file']) || filesize($check['file']) > ReleaseZip::MAX_ENTRY_BYTES) {
        return ['state' => 'unavailable'];
    }
    $right = (string)file_get_contents($check['file']);
    if (LineDiff::isBinary($right) || LineDiff::isBinary($left)) {
        return ['state' => 'binary'];
    }
    return ['state' => 'ok', 'left' => $left, 'right' => $right, 'left_name' => $name];
};

$cetFolderPanel = static function (bool $wide = false) use ($cetFolder, $cetFolderStatus, $cetEsc, $cetTip, $cetDocRoot): void {
    $options = DataFolder::options(DIR_FS_CATALOG, $cetDocRoot, DIR_FS_SQL_CACHE);
    $current = 'custom';
    foreach ($options as $key => $option) {
        if ($option['path'] === $cetFolder->path) {
            $current = $key;
        }
    }
    $labels = ['beside' => [CET_FOLDER_BESIDE, CET_FOLDER_BESIDE_TEXT], 'cache' => [CET_FOLDER_CACHE, CET_FOLDER_CACHE_TEXT]];
    $tag = $wide ? 'section' : 'aside';
    ?>
    <<?= $tag ?> class="cet-card cet-folder" aria-labelledby="cet-folder-title">
        <h2 class="cet-card-head" id="cet-folder-title"><?= $cetEsc($wide ? CET_FOLDER_IN_USE : CET_FOLDER_LABEL) ?><?= $cetTip(CET_FOLDER_TIP) ?></h2>
        <div class="cet-card-body">
            <p class="cet-mono cet-strong"><?= $cetEsc($cetFolder->path) ?></p>
            <p><?= $cetFolderStatus() ?></p>
            <?= zen_draw_form('cet_folder', FILENAME_CORE_EDIT_TRACKER) ?>
                <input type="hidden" name="action" value="move_folder">
                <fieldset class="cet-choices<?= $wide ? ' cet-choices-wide' : '' ?>">
                    <legend><?= $cetEsc(CET_FOLDER_WHERE) ?></legend>
                    <?php foreach ($labels as $key => [$title, $text]) {
                        if (!isset($options[$key])) {
                            continue;
                        }
                        $option = $options[$key];
                        $usable = $option['available'] || $current === $key;
                        ?>
                        <label class="cet-choice<?= $current === $key ? ' cet-choice-on' : '' ?>">
                            <input type="radio" name="folder_choice" value="<?= $key ?>"<?= $current === $key ? ' checked' : '' ?><?= $usable ? '' : ' disabled' ?>>
                            <span>
                                <b><?= $cetEsc($title) ?></b>
                                <span class="cet-mono cet-small"><?= $cetEsc($option['path']) ?></span>
                                <span class="cet-small <?= $usable ? 'cet-muted' : 'cet-warn-text' ?>"><?= $cetEsc($usable ? $text : CET_FOLDER_NOT_AVAILABLE) ?></span>
                            </span>
                        </label>
                    <?php } ?>
                    <label class="cet-choice<?= $current === 'custom' ? ' cet-choice-on' : '' ?>">
                        <input type="radio" name="folder_choice" value="custom"<?= $current === 'custom' ? ' checked' : '' ?>>
                        <span>
                            <b><?= $cetEsc(CET_FOLDER_CUSTOM) ?></b>
                            <input type="text" name="folder_path" class="cet-input cet-mono" aria-label="<?= $cetEsc(CET_FOLDER_CUSTOM_LABEL) ?>" value="<?= $current === 'custom' ? $cetEsc(dirname(rtrim($cetFolder->path, '/')) . '/') : '' ?>" placeholder="/path/outside/the/web/root/">
                            <span class="cet-small cet-muted"><?= $cetEsc(CET_FOLDER_CUSTOM_TEXT) ?></span>
                        </span>
                    </label>
                </fieldset>
                <button type="submit" class="cet-btn"><?= $cetEsc(CET_FOLDER_MOVE) ?></button>
                <?php if ($wide) { ?>
                    <span class="cet-muted cet-small" style="display: inline; margin-left: 10px"><?= $cetEsc(CET_FOLDER_MOVE_TIP) ?></span>
                <?php } else { ?>
                    <?= $cetTip(CET_FOLDER_MOVE_TIP) ?>
                <?php } ?>
            </form>
        </div>
    </<?= $tag ?>>
    <?php
};
?>
<!doctype html>
<html <?= HTML_PARAMS ?>>
<head>
    <?php require DIR_WS_INCLUDES . 'admin_html_head.php'; ?>
    <style>
        .cet { max-width: 1440px; margin: 0 auto; padding: 8px 16px 32px; color: #1b2430; font-size: 14px; --cet-mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace; }
        .cet *, .cet *::before, .cet *::after { box-sizing: border-box; }
        .cet a { color: #0b4fa8; }
        .cet h1 { margin: 0; font-size: 24px; line-height: 32px; font-weight: 700; }
        .cet h1.cet-path { font-size: 19px; line-height: 28px; font-variant: normal; text-transform: none; }
        .cet p { margin: 0 0 8px; }
        .cet-mono { font-family: var(--cet-mono); font-size: 12.5px; overflow-wrap: anywhere; }
        .cet-small { font-size: 12px; display: block; }
        .cet-strong { font-weight: 700; }
        .cet-muted, .cet-dir { color: #5a6675; }
        .cet-warn-text { color: #7a3a06; }
        .cet-plus { color: #0a3f86; }
        .cet-minus { color: #7a3a06; }
        .cet-tip { display: inline-block; width: 16px; height: 16px; line-height: 16px; border-radius: 50%; background: #5b7da8; color: #fff; font-size: 11px; font-weight: 700; text-align: center; cursor: help; font-style: normal; text-transform: none; letter-spacing: 0; vertical-align: 1px; }
        .cet-head { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 16px; margin: 8px 0 16px; }
        .cet-meta { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; font-size: 13px; color: #4d5968; margin-top: 6px; }
        .cet-actions { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; }
        .cet-actions form { margin: 0; }
        .cet-btn { display: inline-flex; align-items: center; gap: 6px; min-height: 36px; padding: 0 14px; border: 1px solid #c5ccd6; border-radius: 6px; background: #fff; color: #1b2430; font-size: 13px; font-weight: 600; text-decoration: none; cursor: pointer; }
        .cet a.cet-btn { color: #1b2430; }
        .cet-btn:hover, .cet-btn:focus { background: #f4f6f8; text-decoration: none; }
        .cet-btn-primary, .cet a.cet-btn-primary { background: #1f5fbf; border-color: #1f5fbf; color: #fff; }
        .cet-btn-primary:hover, .cet-btn-primary:focus { background: #184c99; color: #fff; }
        .cet-btn-sm { min-height: 30px; padding: 0 10px; font-size: 12px; }
        .cet-btn[disabled] { opacity: .55; cursor: default; }
        .cet-seg { display: inline-flex; border: 1px solid #c5ccd6; border-radius: 6px; overflow: hidden; }
        .cet-seg a { padding: 0 12px; line-height: 34px; font-size: 13px; color: #3f4b5a; background: #fff; text-decoration: none; border-left: 1px solid #c5ccd6; }
        .cet-seg a:first-child { border-left: 0; }
        .cet-seg a.cet-on { background: #1b2430; color: #fff; font-weight: 600; }
        .cet-chip { display: inline-block; padding: 1px 8px; border-radius: 4px; background: #eef1f5; color: #3f4b5a; font-size: 12px; font-weight: 600; white-space: nowrap; }
        .cet-chip-ok { background: #e5f3ea; color: #1a5e33; }
        .cet-chip-warn { background: #fdf0e6; color: #7a3a06; }
        .cet-chip-bad { background: #fde8e8; color: #8a1c1c; }
        .cet-chip-new { background: #e8f1fe; color: #0a3f86; }
        .cet-chip-plugin { background: #eee9f8; color: #4b3a8c; }
        .cet-card { background: #fff; border: 1px solid #d9dee5; border-radius: 8px; overflow: hidden; margin: 0 0 16px; }
        .cet-card-head { margin: 0; padding: 10px 14px; background: #f4f6f8; font-size: 12px; line-height: 18px; font-weight: 600; color: #3f4b5a; text-transform: uppercase; letter-spacing: .04em; }
        .cet-card-body { padding: 14px; }
        .cet-first { border-color: #1f6feb; }
        .cet-tiles { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 12px; margin: 0 0 16px; }
        .cet-tile { display: flex; flex-direction: column; gap: 2px; padding: 13px 15px; border: 1px solid #d9dee5; border-radius: 8px; background: #fff; text-decoration: none; }
        .cet a.cet-tile { color: #1b2430; }
        .cet-tile:hover, .cet-tile:focus { border-color: #1f6feb; text-decoration: none; }
        .cet-tile b { font-size: 28px; line-height: 34px; }
        .cet-tile span { font-size: 13px; font-weight: 600; }
        .cet-tile small { font-size: 12px; color: #4d5968; }
        .cet-tile.cet-on { border: 2px solid #1f6feb; padding: 12px 14px; }
        .cet-tile.cet-on b { color: #0a3f86; }
        .cet-filter { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 12px; font-size: 13px; }
        .cet-filter label { margin: 0; font-weight: 400; color: #3f4b5a; }
        .cet-input, .cet-select { height: 36px; border: 1px solid #c5ccd6; border-radius: 6px; padding: 0 10px; font-size: 13px; background: #fff; color: #1b2430; max-width: 100%; }
        .cet-scroll { overflow-x: auto; }
        .cet-list { min-width: 860px; }
        .cet-row { display: grid; grid-template-columns: minmax(0, 1fr) 170px 140px minmax(0, 260px) 150px; align-items: center; gap: 0 12px; min-height: 40px; padding: 4px 12px; border-top: 1px solid #edf0f4; font-size: 13px; }
        .cet-row-head { min-height: 36px; border-top: 0; background: #f4f6f8; font-size: 12px; font-weight: 600; color: #3f4b5a; text-transform: uppercase; letter-spacing: .04em; }
        .cet-row-on { background: #f2f7ff; }
        .cet-row-end { text-align: right; white-space: nowrap; }
        .cet-row-end a { font-size: 12px; font-weight: 600; text-decoration: none; margin-left: 10px; }
        .cet-lines { display: flex; align-items: center; gap: 10px; }
        .cet-bar { display: inline-flex; width: 56px; height: 6px; border-radius: 3px; background: #e4e8ee; overflow: hidden; flex: 0 0 56px; }
        .cet-bar-a { background: #1f6feb; }
        .cet-bar-d { background: #d9730d; }
        .cet-group { display: flex; gap: 8px; padding: 7px 12px; border-top: 1px solid #d9dee5; background: #fafbfc; font-size: 12px; color: #3f4b5a; }
        .cet-peek { padding: 0 12px 12px; background: #f2f7ff; }
        .cet-peek-box { border: 1px solid #d9dee5; border-radius: 6px; overflow: hidden; background: #fff; }
        .cet-peek-foot { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 12px; padding: 8px 12px; border-top: 1px solid #e1e5eb; font-size: 12px; color: #4d5968; }
        .cet details > summary { cursor: pointer; }
        .cet-owner > summary { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; padding: 12px; border-top: 1px solid #d9dee5; font-size: 13px; list-style-position: inside; }
        .cet-foot { display: flex; flex-wrap: wrap; gap: 8px 24px; font-size: 12px; color: #4d5968; padding: 0 4px; }
        .cet-two { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-start; }
        .cet-two > .cet-main { flex: 999 1 560px; min-width: 0; }
        .cet-two > aside { flex: 1 1 280px; max-width: 100%; }
        .cet-two > .cet-side { flex: 0 1 290px; }
        .cet.cet-wide { max-width: none; }
        .cet-side-list { max-height: 720px; overflow-y: auto; padding: 6px 0; }
        .cet-side-list a { display: flex; justify-content: space-between; gap: 8px; padding: 6px 12px; text-decoration: none; font-family: var(--cet-mono); font-size: 12px; line-height: 18px; border-left: 3px solid transparent; }
        .cet .cet-side-list a { color: #1b2430; }
        .cet-side-list a.cet-on { border-left-color: #1f6feb; background: #e8f1fe; font-weight: 700; }
        .cet-side-list a span:first-child { min-width: 0; overflow-wrap: anywhere; display: flex; flex-direction: column; }
        .cet-side-list a small { font-size: 11px; font-weight: 400; color: #5a6675; }
        .cet-side-list a span:last-child { white-space: nowrap; font-weight: 400; }
        .cet-bar-tools { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 12px; padding: 8px 12px; }
        .cet-bar-tools > div { display: flex; flex-wrap: wrap; align-items: center; gap: 12px; }
        .cet-diffwrap { display: flex; }
        .cet-diffwrap > .cet-scroll { flex: 1 1 auto; min-width: 0; }
        .cet-diff { min-width: 820px; }
        .cet-panes { display: grid; grid-template-columns: minmax(0, 1fr) 26px minmax(0, 1fr); border-bottom: 1px solid #d9dee5; font-size: 13px; }
        .cet-panes > div { padding: 9px 12px; overflow-wrap: anywhere; }
        .cet-pane-l { border-top: 3px solid #d9730d; }
        .cet-pane-l b { color: #7a3a06; }
        .cet-pane-r { border-top: 3px solid #1f6feb; }
        .cet-pane-r b { color: #0a3f86; }
        .cet-pane-g { background: #eef1f5; }
        .cet-r { display: grid; grid-template-columns: 52px minmax(0, 1fr) 26px 52px minmax(0, 1fr); font-family: var(--cet-mono); font-size: 12px; line-height: 20px; }
        .cet-r.cet-i { grid-template-columns: 52px 52px 26px minmax(0, 1fr); }
        .cet-n { text-align: right; padding: 0 8px 0 0; color: #5f6b7a; background: #eef1f5; user-select: none; }
        .cet-c { padding: 0 10px; white-space: pre-wrap; overflow-wrap: anywhere; color: #1b2430; background: #fff; min-height: 20px; }
        .cet-g { background: #eef1f5; text-align: center; color: #3f4b5a; font-weight: 700; user-select: none; }
        .cet-change .cet-nl, .cet-del .cet-nl { background: #f8d9bc; color: #7a3a06; }
        .cet-change .cet-nr, .cet-add .cet-nr { background: #c9defc; color: #0a3f86; }
        .cet-change .cet-cl, .cet-del .cet-cl { background: #fdf0e6; }
        .cet-change .cet-cr, .cet-add .cet-cr { background: #e8f1fe; }
        .cet-cl .cet-hl { background: #f5be8e; border-radius: 2px; }
        .cet-cr .cet-hl { background: #9dc3f9; border-radius: 2px; }
        .cet-c.cet-x { background: repeating-linear-gradient(135deg, #f1f3f6 0 6px, #e4e8ee 6px 7px); }
        .cet-r[tabindex] { cursor: pointer; }
        .cet-r.cet-sel { outline: 2px solid #1b2430; outline-offset: -2px; position: relative; }
        .cet-skip { text-align: center; background: #f4f6f8; border-top: 1px dashed #c5ccd6; border-bottom: 1px dashed #c5ccd6; font-size: 12px; line-height: 28px; color: #4d5968; }
        .cet-skip a { font-weight: 600; text-decoration: none; }
        .cet-map { flex: 0 0 18px; position: relative; background: #eef1f5; border-left: 1px solid #d9dee5; }
        .cet-map a { position: absolute; left: 3px; right: 3px; min-height: 3px; border-radius: 1px; background: #1f6feb; }
        .cet-map a.cet-map-del { background: #d9730d; }
        .cet-map a.cet-map-change { background: linear-gradient(90deg, #d9730d 50%, #1f6feb 50%); }
        .cet-detail-rows { min-width: 640px; font-family: var(--cet-mono); font-size: 13px; line-height: 26px; }
        .cet-detail-rows > div { display: grid; grid-template-columns: 110px 60px minmax(0, 1fr); }
        .cet-detail-rows > div > span:first-child { padding: 0 12px; font-size: 12px; font-weight: 700; }
        .cet-detail-rows > div > span:nth-child(2) { text-align: right; padding-right: 10px; }
        .cet-detail-rows > div > span:last-child { white-space: pre; color: #1b2430; }
        .cet-detail-l { background: #fdf0e6; color: #7a3a06; }
        .cet-detail-r { background: #e8f1fe; color: #0a3f86; }
        .cet-detail-l .cet-hl { background: #f5be8e; border-radius: 2px; }
        .cet-detail-r .cet-hl { background: #9dc3f9; border-radius: 2px; }
        .cet-legend { display: flex; flex-wrap: wrap; align-items: center; gap: 8px 18px; font-size: 12px; color: #3f4b5a; padding: 0 4px; }
        .cet-legend i { display: inline-block; width: 18px; height: 14px; border-radius: 2px; border: 1px solid #c5ccd6; font: 700 11px/12px var(--cet-mono); text-align: center; vertical-align: -2px; margin-right: 6px; }
        .cet-key-l { background: #fdf0e6; border-color: #f5be8e !important; color: #7a3a06; }
        .cet-key-r { background: #e8f1fe; border-color: #9dc3f9 !important; color: #0a3f86; }
        .cet-key-x { background: repeating-linear-gradient(135deg, #f1f3f6 0 6px, #e4e8ee 6px 7px); }
        .cet-what { display: grid; grid-template-columns: 210px minmax(0, 1fr); gap: 12px; padding: 9px 14px; border-top: 1px solid #edf0f4; font-size: 13px; align-items: baseline; }
        .cet-what:first-of-type { border-top: 0; }
        .cet-what b { font-size: 18px; color: #0a3f86; }
        .cet-ending { font-weight: 700; color: #7a3a06; background: #fdf0e6; border-radius: 2px; }
        .cet-choices { margin: 12px 0; padding: 0; border: 0; display: flex; flex-direction: column; gap: 8px; }
        .cet-choices legend { padding: 0 0 8px; margin: 0; border: 0; font-size: 13px; font-weight: 600; }
        .cet-choice { display: flex; gap: 10px; align-items: flex-start; padding: 11px 13px; border: 1px solid #d9dee5; border-radius: 8px; margin: 0; font-weight: 400; }
        .cet-choice-on { border: 2px solid #1f6feb; padding: 10px 12px; background: #f2f7ff; }
        .cet-choice > span { display: flex; flex-direction: column; gap: 3px; min-width: 0; flex: 1 1 auto; }
        .cet-choice .cet-input { width: 100%; }
        .cet-check { display: flex; gap: 10px; align-items: flex-start; margin: 0 0 8px; font-weight: 400; font-size: 13px; }
        .cet-steps { margin: 0; padding: 12px 14px 12px 36px; font-size: 13px; line-height: 22px; }
        .cet-scanning { display: none; font-weight: 600; }
        .cet-narrow { max-width: 980px; margin: 0 auto; }
        .cet-lead { margin: 6px 0 0; font-size: 14px; line-height: 22px; color: #3f4b5a; }
        .cet-btn-lg { min-height: 44px; padding: 0 20px; font-size: 14px; }
        .cet-card-foot { border-top: 1px solid #d9dee5; background: #fafbfc; }
        .cet-what-out { font-size: 13px; }
        .cet-zip { display: grid; grid-template-columns: max-content minmax(260px, 1fr); gap: 10px 28px; padding: 14px; font-size: 13px; line-height: 20px; align-items: baseline; color: #3f4b5a; }
        .cet-ending-plugin { color: #4b3a8c; background: #eee9f8; }
        .cet-plugin-text { color: #4b3a8c; }
        .cet-choices-wide { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
        .cet-more { position: relative; }
        .cet-more > summary { list-style: none; }
        .cet-more > summary::-webkit-details-marker { display: none; }
        .cet-more > div { position: absolute; right: 0; z-index: 5; flex-wrap: nowrap; padding: 8px; background: #fff; border: 1px solid #d9dee5; border-radius: 8px; box-shadow: 0 4px 12px rgba(27, 36, 48, .12); }
        .cet-sr { position: absolute; width: 1px; height: 1px; overflow: hidden; clip-path: inset(50%); }
    </style>
</head>
<body>
<?php require DIR_WS_INCLUDES . 'header.php'; ?>
<div class="cet<?= ($cetView === 'compare' && $cetRelease !== null) ? ' cet-wide' : '' ?>">
<?php
if ($cetRelease === null) {
    $cetPlaced = $cetFolder->placedZips();
    ?>
    <header class="cet-head"><div><h1><?= $cetEsc(HEADING_TITLE) ?><?= $cetTip(CET_INTRO_TIP) ?></h1></div></header>
    <div class="cet-two">
        <div class="cet-main">
            <section class="cet-card cet-first">
                <h2 class="cet-card-head"><?= $cetEsc(sprintf(CET_FIRST_RUN_TITLE, $cetVersion)) ?></h2>
                <div class="cet-card-body">
                    <?php $cetOutdated = $cetTracker->outdatedRelease(); ?>
                    <?php if ($cetOutdated !== null) { ?>
                        <p class="cet-warn-text cet-strong"><?= $cetEsc(sprintf(CET_FIRST_RUN_OUTDATED, $cetVersion, $cetOutdated['version'])) ?></p>
                    <?php } ?>
                    <p><?= $cetEsc(sprintf(CET_FIRST_RUN_TEXT, $cetVersion)) ?></p>
                    <?= zen_draw_form('cet_upload', FILENAME_CORE_EDIT_TRACKER, '', 'post', 'enctype="multipart/form-data"') ?>
                        <input type="hidden" name="action" value="upload">
                        <p><label for="cet-release-zip"><?= $cetEsc(CET_UPLOAD_LABEL) ?></label>
                            <input type="file" id="cet-release-zip" name="release_zip" accept=".zip,application/zip" required></p>
                        <p class="cet-muted cet-small"><?= $cetEsc(sprintf(CET_UPLOAD_LIMIT, (string)ini_get('upload_max_filesize'))) ?></p>
                        <button type="submit" class="cet-btn cet-btn-primary"><?= $cetEsc(CET_UPLOAD_BUTTON) ?></button>
                    </form>
                </div>
            </section>
            <section class="cet-card">
                <h2 class="cet-card-head"><?= $cetEsc(CET_PLACED_TITLE) ?></h2>
                <?php if ($cetPlaced === []) { ?>
                    <div class="cet-card-body cet-muted"><?= $cetEsc(CET_NO_PLACED) ?></div>
                <?php } ?>
                <?php foreach ($cetPlaced as $cetZip) { ?>
                    <div class="cet-row" style="grid-template-columns: minmax(0, 1fr) 110px 150px 130px">
                        <span class="cet-mono"><?= $cetEsc($cetZip['name']) ?></span>
                        <span><?= $cetEsc($cetBytes($cetZip['size'])) ?></span>
                        <span class="cet-muted"><?= $cetEsc($cetDate($cetZip['time'])) ?></span>
                        <span class="cet-row-end">
                            <?= zen_draw_form('cet_use_' . md5($cetZip['name']), FILENAME_CORE_EDIT_TRACKER) ?>
                                <input type="hidden" name="action" value="use_placed">
                                <input type="hidden" name="placed" value="<?= $cetEsc($cetZip['name']) ?>">
                                <button type="submit" class="cet-btn cet-btn-sm"><?= $cetEsc(CET_USE_PLACED) ?></button>
                            </form>
                        </span>
                    </div>
                <?php } ?>
            </section>
        </div>
        <?php $cetFolderPanel(); ?>
    </div>
<?php
} elseif ($cetView === 'compare') {
    $cetPath = (isset($_GET['path']) && is_string($_GET['path'])) ? $_GET['path'] : '';
    $cetRow = $cetScan['rows'][$cetPath] ?? ($cetScan['added'][$cetPath] ?? null);
    $cetAgainst = (isset($_GET['against']) && $_GET['against'] === 'base') ? 'base' : 'release';
    $cetFull = isset($_GET['full']);
    $cetInline = isset($_GET['inline']);
    $cetWs = isset($_GET['ws']);
    $cetReveal = isset($_GET['reveal']);
    $cetBack = (isset($_GET['from']) && in_array($_GET['from'], $cetGroups, true)) ? $_GET['from'] : 'edit';
    ?>
    <?php
    if ($cetRow === null || PathRules::isCredentialPath($cetPath) || PathRules::cleanRelative($cetPath) === null) {
        echo '<p><a href="' . $cetUrl('done=1') . '">' . $cetEsc(HEADING_TITLE) . '</a></p><div class="cet-card"><div class="cet-card-body">' . $cetEsc(CET_VIEW_UNAVAILABLE) . '</div></div>';
    } else {
        $cetBase = $cetTracker->companion()['owned'][$cetPath]['base'] ?? null;
        $cetHasBase = $cetBase !== null && $cetBase['verified'];
        if (($cetAgainst === 'base' && !$cetHasBase) || ($cetRow['g'] === 'added' && $cetHasBase)) {
            $cetAgainst = $cetHasBase ? 'base' : 'release';
        }
        $cetLink = static function (array $change = []) use ($cetUrl, $cetPath, $cetAgainst, $cetFull, $cetInline, $cetWs, $cetReveal, $cetBack): string {
            $state = $change + ['path' => $cetPath, 'against' => $cetAgainst, 'full' => $cetFull, 'inline' => $cetInline, 'ws' => $cetWs, 'reveal' => $cetReveal];
            $params = 'done=1&view=compare&path=' . rawurlencode((string)$state['path']) . '&from=' . $cetBack;
            foreach (['full', 'inline', 'ws', 'reveal'] as $flag) {
                $params .= $state[$flag] ? '&' . $flag . '=1' : '';
            }
            return $cetUrl($params . ($state['against'] === 'base' ? '&against=base' : ''));
        };

        // The files in the same list as this one, for the sidebar and the previous and next links.
        $cetSiblings = [];
        foreach ($cetScan['rows'] as $cetP => $cetR) {
            if ($cetInGroup($cetR, $cetBack) && !in_array($cetR['g'], ['missing', 'override_gone', 'stock'], true) && empty($cetR['bin']) && empty($cetR['big']) && empty($cetR['ur'])) {
                $cetSiblings[] = (string)$cetP;
            }
        }
        if ($cetBack !== 'owned') {
            // Same order as the list: files in the store root first.
            $cetSiblings = array_merge(
                array_values(array_filter($cetSiblings, static fn($p) => strpos($p, '/') === false)),
                array_values(array_filter($cetSiblings, static fn($p) => strpos($p, '/') !== false))
            );
        }
        $cetAt = array_search($cetPath, $cetSiblings, true);
        $cetPrevFile = ($cetAt !== false && $cetAt > 0) ? $cetSiblings[$cetAt - 1] : null;
        $cetNextFile = ($cetAt !== false && $cetAt < count($cetSiblings) - 1) ? $cetSiblings[$cetAt + 1] : null;
        $cetSlash = strrpos($cetPath, '/');

        $cetData = $cetPair($cetPath, $cetRow, $cetAgainst);
        $cetSecretHidden = $cetData['state'] === 'ok' && !empty($cetRow['sec']) && !$cetReveal;
        $cetDiff = null;
        $cetShown = null;
        if ($cetData['state'] === 'ok') {
            $cetLeftLines = LineDiff::split($cetData['left']);
            $cetRightLines = LineDiff::split($cetData['right']);
            $cetDiff = LineDiff::compare($cetLeftLines, $cetRightLines, ['eol' => true, 'ws' => $cetWs]);
            $cetShown = DiffView::render($cetLeftLines, $cetRightLines, [
                'result' => $cetDiff,
                'context' => $cetFull ? -1 : 4,
                'mask' => $cetSecretHidden,
                'inline' => $cetInline,
                'skip_text' => CET_VIEW_SKIP,
                'skip_url' => $cetLink(['full' => true]),
            ]);
        }
        ?>
        <header class="cet-head">
            <div>
                <nav class="cet-small cet-muted" aria-label="<?= $cetEsc(CET_VIEW_BREADCRUMB) ?>"><a href="<?= $cetUrl('done=1' . ($cetBack !== 'edit' ? '&group=' . $cetBack : '')) ?>"><?= $cetEsc(HEADING_TITLE) ?></a> / <?= $cetEsc(CET_COMPARE) ?></nav>
                <h1 class="cet-mono cet-path"><span class="cet-dir" style="font-weight: 400"><?= $cetEsc($cetSlash === false ? '' : substr($cetPath, 0, $cetSlash + 1)) ?></span><?= $cetEsc($cetSlash === false ? $cetPath : substr($cetPath, $cetSlash + 1)) ?></h1>
                <div class="cet-meta">
                    <span class="cet-chip"><?= $cetEsc($cetText('CET_GROUP_', (string)$cetRow['g'])) ?></span>
                    <?= $cetChips($cetRow, true) ?>
                    <?php if (isset($cetRow['s'])) { ?><span><?= $cetEsc($cetBytes($cetRow['s'])) ?></span><span><?= $cetEsc($cetDate($cetRow['t'])) ?></span><?php } ?>
                    <?php if (isset($cetRow['a'])) { ?><span class="cet-mono"><b class="cet-plus">+<?= (int)$cetRow['a'] ?></b> <b class="cet-minus">&minus;<?= (int)$cetRow['d'] ?></b></span><?php } ?>
                </div>
            </div>
            <div class="cet-actions">
                <?php if ($cetShown !== null && $cetShown['hunks'] > 0) { ?>
                    <button type="button" class="cet-btn" id="cet-copy" data-done="<?= $cetEsc(CET_VIEW_COPIED) ?>"><?= $cetEsc(CET_VIEW_COPY_PATCH) ?></button>
                <?php } ?>
                <?php if ($cetAt !== false) { ?>
                    <span class="cet-seg">
                        <?php if ($cetPrevFile !== null) { ?><a href="<?= $cetLink(['path' => $cetPrevFile, 'against' => 'release']) ?>" aria-label="<?= $cetEsc(CET_VIEW_PREV_FILE) ?>" title="<?= $cetEsc(CET_VIEW_PREV_FILE) ?>">&lsaquo;</a><?php } ?>
                        <a class="cet-on" aria-current="page"><?= $cetEsc(sprintf(CET_VIEW_FILE_OF, $cetAt + 1, count($cetSiblings))) ?></a>
                        <?php if ($cetNextFile !== null) { ?><a href="<?= $cetLink(['path' => $cetNextFile, 'against' => 'release']) ?>" aria-label="<?= $cetEsc(CET_VIEW_NEXT_FILE) ?>" title="<?= $cetEsc(CET_VIEW_NEXT_FILE) ?>">&rsaquo;</a><?php } ?>
                    </span>
                <?php } ?>
            </div>
        </header>
        <div class="cet-two">
            <aside class="cet-card cet-side" aria-label="<?= $cetEsc(CET_VIEW_FILES) ?>">
                <div class="cet-card-body" style="padding: 10px 12px; border-bottom: 1px solid #e1e5eb">
                    <label for="cet-side-q" class="cet-sr"><?= $cetEsc(CET_FILTER_SEARCH) ?></label>
                    <input type="search" id="cet-side-q" class="cet-input" style="width: 100%" placeholder="<?= $cetEsc(CET_FILTER_SEARCH) ?>">
                </div>
                <nav class="cet-side-list" id="cet-side-list">
                    <?php foreach ($cetSiblings as $cetP) {
                        $cetR = $cetScan['rows'][$cetP]; ?>
                        <a href="<?= $cetLink(['path' => $cetP, 'against' => 'release']) ?>"<?= $cetP === $cetPath ? ' class="cet-on" aria-current="true"' : '' ?>><span><?= $cetEsc(basename($cetP)) ?><?php if (strpos($cetP, '/') !== false) { ?><small><?= $cetEsc(dirname($cetP) . '/') ?></small><?php } ?></span><?php if (isset($cetR['a'])) { ?><span><b class="cet-plus">+<?= (int)$cetR['a'] ?></b> <b class="cet-minus">&minus;<?= (int)$cetR['d'] ?></b></span><?php } ?></a>
                    <?php } ?>
                </nav>
            </aside>
            <div class="cet-main">
                <div class="cet-card cet-bar-tools">
                    <div>
                        <span class="cet-seg">
                            <a href="<?= $cetLink(['full' => false]) ?>"<?= $cetFull ? '' : ' class="cet-on"' ?>><?= $cetEsc(CET_VIEW_CHANGES) ?></a>
                            <a href="<?= $cetLink(['full' => true]) ?>"<?= $cetFull ? ' class="cet-on"' : '' ?>><?= $cetEsc(CET_VIEW_WHOLE) ?></a>
                        </span>
                        <span class="cet-seg">
                            <a href="<?= $cetLink(['inline' => false]) ?>"<?= $cetInline ? '' : ' class="cet-on"' ?>><?= $cetEsc(CET_VIEW_SIDE) ?></a>
                            <a href="<?= $cetLink(['inline' => true]) ?>"<?= $cetInline ? ' class="cet-on"' : '' ?>><?= $cetEsc(CET_VIEW_INLINE) ?></a>
                        </span>
                        <span class="cet-seg">
                            <a href="<?= $cetLink(['ws' => false]) ?>"<?= $cetWs ? '' : ' class="cet-on"' ?>><?= $cetEsc(CET_VIEW_WS_SHOW) ?></a>
                            <a href="<?= $cetLink(['ws' => true]) ?>"<?= $cetWs ? ' class="cet-on"' : '' ?>><?= $cetEsc(CET_VIEW_WS_IGNORE) ?></a>
                        </span>
                        <?php if ($cetHasBase && $cetRow['g'] !== 'added') { ?>
                            <span class="cet-seg">
                                <a href="<?= $cetLink(['against' => 'release']) ?>"<?= $cetAgainst === 'release' ? ' class="cet-on"' : '' ?>><?= $cetEsc(CET_VIEW_RELEASE) ?></a>
                                <a href="<?= $cetLink(['against' => 'base']) ?>"<?= $cetAgainst === 'base' ? ' class="cet-on"' : '' ?>><?= $cetEsc(CET_VIEW_BASE) ?></a>
                            </span>
                        <?php } ?>
                    </div>
                    <?php if ($cetShown !== null && $cetShown['hunks'] > 0) { ?>
                        <div>
                            <span><?= sprintf($cetEsc(CET_VIEW_CHANGE_OF), '<b id="cet-at">1</b>', (int)$cetShown['hunks']) ?></span>
                            <button type="button" class="cet-btn cet-btn-sm" id="cet-prev" aria-label="<?= $cetEsc(CET_VIEW_PREV_CHANGE) ?>" title="<?= $cetEsc(CET_VIEW_PREV_CHANGE) ?>">&uarr;</button>
                            <button type="button" class="cet-btn cet-btn-sm" id="cet-next" aria-label="<?= $cetEsc(CET_VIEW_NEXT_CHANGE) ?>" title="<?= $cetEsc(CET_VIEW_NEXT_CHANGE) ?>">&darr;</button>
                        </div>
                    <?php } ?>
                </div>
                <?php if ($cetData['state'] !== 'ok') { ?>
                    <div class="cet-card"><div class="cet-card-body"><?= $cetEsc($cetText('CET_VIEW_', $cetData['state'])) ?></div></div>
                <?php } else { ?>
                    <?php if (!empty($cetRow['sec'])) { ?>
                        <p class="cet-warn-text"><?= $cetEsc($cetSecretHidden ? CET_VIEW_SECRET : CET_VIEW_SECRET_SHOWN) ?>
                            <?php if ($cetSecretHidden) { ?><a href="<?= $cetLink(['reveal' => true]) ?>"><?= $cetEsc(CET_VIEW_REVEAL) ?></a><?php } ?></p>
                    <?php } ?>
                    <?php if ($cetDiff['approximate']) { ?><p class="cet-muted"><?= $cetEsc(CET_VIEW_APPROXIMATE) ?></p><?php } ?>
                    <?php if ($cetShown['hunks'] === 0) { ?>
                        <div class="cet-card"><div class="cet-card-body"><?= $cetEsc($cetData['left'] === $cetData['right'] ? CET_VIEW_IDENTICAL : ($cetWs ? CET_VIEW_WS_ONLY : CET_VIEW_EOL_ONLY)) ?></div></div>
                    <?php } else { ?>
                        <div class="cet-card cet-diffwrap">
                            <div class="cet-scroll">
                                <div class="cet-diff">
                                    <?php if (!$cetInline) { ?>
                                        <div class="cet-panes">
                                            <div class="cet-pane-l"><b><?= $cetEsc($cetAgainst === 'base' ? CET_VIEW_LEFT_BASE : ($cetData['left_name'] === '' ? CET_VIEW_LEFT_NONE : sprintf(CET_VIEW_LEFT_RELEASE, $cetRelease['version']))) ?></b>
                                                <?php if ($cetData['left_name'] !== '' && $cetData['left_name'] !== $cetPath) { ?><span class="cet-mono cet-small cet-muted"><?= $cetEsc($cetData['left_name']) ?></span><?php } ?></div>
                                            <div class="cet-pane-g"></div>
                                            <div class="cet-pane-r"><b><?= $cetEsc(CET_VIEW_RIGHT) ?></b></div>
                                        </div>
                                    <?php } ?>
                                    <?= $cetShown['html'] ?>
                                    <?php if ($cetShown['truncated']) { ?><div class="cet-skip"><?= $cetEsc(CET_VIEW_TRUNCATED) ?></div><?php } ?>
                                </div>
                            </div>
                            <nav class="cet-map" aria-label="<?= $cetEsc(CET_VIEW_MAP) ?>">
                                <?php foreach ($cetShown['marks'] as $cetI => [$cetTop, $cetHeight, $cetType]) { ?>
                                    <a href="#cet-h<?= $cetI + 1 ?>" class="cet-map-<?= $cetType ?>" style="top: <?= round(min(99.2, $cetTop), 2) ?>%; height: <?= round($cetHeight, 2) ?>%" aria-label="<?= $cetEsc(sprintf(CET_VIEW_CHANGE_N, $cetI + 1)) ?>" title="<?= $cetEsc(sprintf(CET_VIEW_CHANGE_N, $cetI + 1)) ?>"></a>
                                <?php } ?>
                            </nav>
                        </div>
                        <section class="cet-card" id="cet-detail" hidden>
                            <h2 class="cet-card-head"><?= $cetEsc(CET_VIEW_SELECTED) ?><?= $cetTip(CET_VIEW_SELECTED_TIP) ?></h2>
                            <div class="cet-scroll"><div class="cet-detail-rows">
                                <div class="cet-detail-l"><span><?= $cetEsc($cetAgainst === 'base' ? CET_VIEW_SIDE_BASE : CET_VIEW_SIDE_RELEASE) ?></span><span id="cet-detail-ln"></span><span id="cet-detail-l"></span></div>
                                <div class="cet-detail-r"><span><?= $cetEsc(CET_VIEW_SIDE_STORE) ?></span><span id="cet-detail-rn"></span><span id="cet-detail-r"></span></div>
                            </div></div>
                        </section>
                        <div class="cet-legend">
                            <span><i class="cet-key-l">&ne;</i><i class="cet-key-r">&ne;</i><?= $cetEsc(CET_LEGEND_CHANGED) ?></span>
                            <span><i class="cet-key-r">+</i><?= $cetEsc(CET_LEGEND_ADDED) ?></span>
                            <span><i class="cet-key-l">&minus;</i><?= $cetEsc(CET_LEGEND_REMOVED) ?></span>
                            <?php if (!$cetInline) { ?><span><i class="cet-key-x"></i><?= $cetEsc(CET_LEGEND_NONE) ?></span><?php } ?>
                            <span class="cet-muted" style="margin-left: auto"><?= $cetEsc(CET_VIEW_ONLY) ?></span>
                        </div>
                        <textarea id="cet-patch" class="cet-sr" readonly tabindex="-1" aria-hidden="true"><?= $cetEsc(DiffView::patch($cetLeftLines, $cetRightLines, $cetDiff, 'a/' . ($cetData['left_name'] !== '' ? $cetData['left_name'] : $cetPath), 'b/' . $cetPath, $cetSecretHidden)) ?></textarea>
                    <?php } ?>
                <?php } ?>
            </div>
        </div>
        <?php
    }
} elseif ($cetView === 'folder') {
    ?>
    <div class="cet-narrow">
        <header class="cet-head">
            <div>
                <nav class="cet-small cet-muted" aria-label="<?= $cetEsc(CET_VIEW_BREADCRUMB) ?>"><a href="<?= $cetUrl('done=1') ?>"><?= $cetEsc(HEADING_TITLE) ?></a> / <?= $cetEsc(CET_FOLDER_LABEL) ?></nav>
                <h1><?= $cetEsc(CET_FOLDER_LABEL) ?></h1>
                <p class="cet-lead"><?= $cetEsc(CET_FOLDER_INTRO) ?></p>
            </div>
        </header>
        <?php $cetFolderPanel(true); ?>
    </div>
<?php
} elseif ($cetView === 'export') {
    $cetPlan = $cetScan === null ? null : ExportBuilder::plan($cetScan, []);
    // A real file from this store shows what the zip holds: one of the owner's own text files when there is one.
    $cetSample = null;
    foreach ($cetScan['rows'] ?? [] as $cetP => $cetR) {
        if (ExportBuilder::decide((string)$cetP, $cetR, []) !== 'include' || !empty($cetR['bin'])) {
            continue;
        }
        if ($cetR['g'] === 'edit' && !isset($cetR['own'])) {
            $cetSample = (string)$cetP;
            break;
        }
        $cetSample ??= (string)$cetP;
    }
    $cetSample ??= 'includes/classes/order.php';
    $cetPluginSample = null;
    foreach ($cetScan['rows'] ?? [] as $cetP => $cetR) {
        if (isset($cetR['own']) && ExportBuilder::decide((string)$cetP, $cetR, []) === 'include') {
            $cetPluginSample = ExportBuilder::ROOT . $cetP;
            break;
        }
    }
    $cetSlash = strrpos($cetSample, '/');
    $cetSampleDir = ExportBuilder::ROOT . ($cetSlash === false ? '' : substr($cetSample, 0, $cetSlash + 1));
    $cetSampleName = $cetSlash === false ? $cetSample : substr($cetSample, $cetSlash + 1);
    $cetNotNeeded = [];
    foreach (['owned' => 'CET_EXPORT_OUT_OWNED', 'no_original' => 'CET_EXPORT_OUT_ALONE', 'identical_copy' => 'CET_EXPORT_OUT_SAME', 'not_on_store' => 'CET_EXPORT_OUT_MISSING'] as $cetKey => $cetLabel) {
        $cetCount = (int)($cetPlan[$cetKey] ?? 0);
        if ($cetCount > 0) {
            $cetNotNeeded[] = $cetCount === 1 ? constant($cetLabel . '_ONE') : sprintf(constant($cetLabel), number_format($cetCount));
        }
    }
    $cetTotal = $cetPlan === null ? 0 : (int)$cetPlan['edit'] + (int)$cetPlan['override'] + (int)$cetPlan['on_top'];
    ?>
    <div class="cet-narrow">
        <header class="cet-head">
            <div>
                <nav class="cet-small cet-muted" aria-label="<?= $cetEsc(CET_VIEW_BREADCRUMB) ?>"><a href="<?= $cetUrl('done=1') ?>"><?= $cetEsc(HEADING_TITLE) ?></a> / <?= $cetEsc(CET_EXPORT_TITLE) ?></nav>
                <h1><?= $cetEsc(CET_EXPORT_TITLE) ?></h1>
                <p class="cet-lead"><?= $cetEsc(CET_EXPORT_INTRO) ?></p>
            </div>
        </header>
        <?php if ($cetPlan === null) { ?>
            <div class="cet-card"><div class="cet-card-body"><?= $cetEsc(CET_NO_SCAN) ?></div></div>
        <?php } else { ?>
            <?= zen_draw_form('cet_export', FILENAME_CORE_EDIT_TRACKER) ?>
                <input type="hidden" name="action" value="export">
                <section class="cet-card">
                    <h2 class="cet-card-head"><?= $cetEsc(CET_EXPORT_WHAT) ?></h2>
                    <div class="cet-what"><span><b><?= number_format((int)$cetPlan['edit']) ?></b> <?= $cetEsc(CET_EXPORT_EDITS) ?></span><span class="cet-muted"><?= $cetEsc(CET_EXPORT_EDITS_TEXT) ?></span></div>
                    <div class="cet-what"><span><b><?= number_format((int)$cetPlan['override']) ?></b> <?= $cetEsc(CET_EXPORT_OVERRIDES) ?></span><span class="cet-muted"><?= $cetEsc(CET_EXPORT_OVERRIDES_TEXT) ?></span></div>
                    <?php if ($cetPlan['on_top'] > 0) { ?>
                        <div class="cet-what"><span><b><?= number_format((int)$cetPlan['on_top']) ?></b> <?= $cetEsc(CET_EXPORT_ON_TOP) ?></span><span class="cet-muted"><?= $cetEsc(CET_EXPORT_ON_TOP_TEXT) ?></span></div>
                    <?php } ?>
                    <?php if ($cetNotNeeded !== []) { ?>
                        <div class="cet-what cet-what-out"><span class="cet-strong"><?= $cetEsc(CET_EXPORT_NOT_NEEDED) ?><?= $cetTip(CET_EXPORT_NOT_NEEDED_TIP) ?></span><span class="cet-muted"><?= $cetEsc(implode(', ', $cetNotNeeded)) ?></span></div>
                    <?php } ?>
                    <div class="cet-card-body cet-card-foot">
                        <?php if ($cetPlan['may_hold_secret'] > 0) { ?>
                            <label class="cet-check"><input type="checkbox" name="include_secrets" value="1"> <span><?= $cetEsc(sprintf(CET_EXPORT_SECRETS, number_format((int)$cetPlan['may_hold_secret']))) ?><?= $cetTip(CET_EXPORT_SECRETS_TIP) ?>
                                <span class="cet-mono cet-small cet-muted"><?= $cetEsc(implode(', ', $cetPlan['secret_paths'])) ?></span></span></label>
                        <?php } ?>
                        <button type="submit" class="cet-btn cet-btn-primary cet-btn-lg"<?= ($cetTotal + $cetPlan['may_hold_secret']) === 0 ? ' disabled' : '' ?>><?= $cetEsc(CET_EXPORT_BUTTON) ?></button>
                        <span class="cet-muted cet-small" style="display: inline; margin-left: 10px"><?= $cetEsc($cetTotal === 1 ? CET_EXPORT_COUNT_ONE : sprintf(CET_EXPORT_COUNT, number_format($cetTotal))) ?></span>
                    </div>
                </section>
            </form>
            <section class="cet-card">
                <h2 class="cet-card-head"><?= $cetEsc(CET_EXPORT_INSIDE) ?></h2>
                <div class="cet-scroll"><div class="cet-zip">
                    <span class="cet-mono"><span class="cet-dir"><?= $cetEsc($cetSampleDir) ?></span><b><?= $cetEsc($cetSampleName) ?></b></span>
                    <span><b class="cet-plus"><?= $cetEsc(CET_ZIP_YOURS) ?></b> <?= $cetEsc(CET_ZIP_YOURS_TEXT) ?></span>
                    <span class="cet-mono"><span class="cet-dir"><?= $cetEsc($cetSampleDir) ?></span><?= $cetEsc($cetSampleName) ?>.<span class="cet-ending"><?= $cetEsc(ltrim(ExportBuilder::ORIGINAL_ENDING, '.')) ?></span></span>
                    <span><b class="cet-minus"><?= $cetEsc(sprintf(CET_ZIP_ORIGINAL, $cetVersion)) ?></b> <?= $cetEsc(CET_ZIP_ORIGINAL_TEXT) ?></span>
                    <?php if ($cetPlan['on_top'] > 0 && $cetPluginSample !== null) { ?>
                        <?php $cetCut = (int)strrpos($cetPluginSample, '/'); ?>
                        <span class="cet-mono"><span class="cet-dir"><?= $cetEsc(substr($cetPluginSample, 0, $cetCut + 1)) ?></span><?= $cetEsc(substr($cetPluginSample, $cetCut + 1)) ?>.<span class="cet-ending cet-ending-plugin"><?= $cetEsc(ltrim(ExportBuilder::PLUGIN_ENDING, '.')) ?></span></span>
                        <span><b class="cet-plugin-text"><?= $cetEsc(CET_ZIP_PLUGIN) ?></b> <?= $cetEsc(CET_ZIP_PLUGIN_TEXT) ?></span>
                    <?php } ?>
                    <span class="cet-mono"><span class="cet-dir"><?= $cetEsc(ExportBuilder::REFERENCE_ROOT . 'includes/') ?></span><b>dist-configure.php</b></span>
                    <span><b><?= $cetEsc(CET_ZIP_CONFIGURE) ?></b> <?= $cetEsc(CET_ZIP_CONFIGURE_TEXT) ?></span>
                    <span class="cet-mono cet-strong"><?= $cetEsc(ExportBuilder::README_NAME) ?></span><span><?= $cetEsc(CET_ZIP_README_TEXT) ?></span>
                    <span class="cet-mono cet-strong">MANIFEST.json</span><span><?= $cetEsc(CET_ZIP_MANIFEST_TEXT) ?></span>
                </div></div>
            </section>
            <section class="cet-card">
                <h2 class="cet-card-head"><?= $cetEsc(CET_EXPORT_HOW) ?></h2>
                <ol class="cet-steps"><li><?= $cetEsc(CET_EXPORT_STEP_1) ?></li><li><?= $cetEsc(CET_EXPORT_STEP_2) ?></li><li><?= $cetEsc(CET_EXPORT_STEP_3) ?></li><li><?= $cetEsc(CET_EXPORT_STEP_4) ?></li></ol>
            </section>
        <?php } ?>
        <div class="cet-foot">
            <span><?= $cetEsc(CET_FOLDER_KEPT_IN) ?></span>
            <span class="cet-mono"><?= $cetEsc($cetFolder->path) ?></span>
            <?= $cetFolderStatus() ?>
            <a href="<?= $cetUrl('done=1&view=folder') ?>"><b><?= $cetEsc(CET_FOLDER_CHANGE) ?></b></a>
        </div>
    </div>
<?php
} else {
    $cetCounts = $cetScan['counts'] ?? null;
    ?>
    <header class="cet-head">
        <div>
            <h1><?= $cetEsc(HEADING_TITLE) ?><?= $cetTip(CET_INTRO_TIP) ?></h1>
            <div class="cet-meta">
                <span title="<?= $cetEsc($cetRelease['name'] . ', ' . $cetDate($cetRelease['added_at'])) ?>"><?= $cetEsc(sprintf(CET_RELEASE_IN_USE, $cetRelease['version'], number_format((int)$cetRelease['files']))) ?></span>
                <?php if ($cetScan !== null) { ?>
                    <span><?= $cetEsc(sprintf(CET_LAST_SCAN_VALUE, $cetDate($cetScan['at']), (int)$cetScan['ms'])) ?><?= $cetTip(sprintf(CET_SCAN_TIP, $cetScan['kind'] === 'full' ? CET_KIND_FULL : CET_KIND_QUICK, (int)$cetScan['files_read'])) ?></span>
                <?php } else { ?>
                    <span><?= $cetEsc(CET_NO_SCAN) ?></span>
                <?php } ?>
                <?= $cetFolderStatus() ?>
                <span id="cet-scanning" class="cet-scanning"><?= $cetEsc(CET_SCANNING) ?></span>
            </div>
        </div>
        <div class="cet-actions">
            <?= zen_draw_form('cet_scan', FILENAME_CORE_EDIT_TRACKER, '', 'post', 'id="cet-scan-form"') ?>
                <input type="hidden" name="action" value="scan">
                <input type="hidden" name="group" value="<?= $cetEsc($cetGroup) ?>">
                <input type="hidden" name="q" value="<?= $cetEsc($cetQuery) ?>">
                <input type="hidden" name="sort" value="<?= $cetEsc($cetSort) ?>">
                <button type="submit" class="cet-btn"><?= $cetEsc(CET_SCAN_NOW) ?></button>
            </form>
            <a class="cet-btn cet-btn-primary" href="<?= $cetUrl('done=1&view=export') ?>"><?= $cetEsc(CET_EXPORT_TITLE) ?></a>
            <details class="cet-more">
                <summary class="cet-btn" aria-label="<?= $cetEsc(CET_MORE) ?>" title="<?= $cetEsc(CET_MORE) ?>">&hellip;</summary>
                <div class="cet-actions" style="margin-top: 8px">
                    <a class="cet-btn cet-btn-sm" href="<?= $cetUrl('done=1&view=folder') ?>"><?= $cetEsc(CET_FOLDER_LABEL) ?></a>
                    <?= zen_draw_form('cet_rescan', FILENAME_CORE_EDIT_TRACKER) ?>
                        <input type="hidden" name="action" value="rescan">
                        <button type="submit" class="cet-btn cet-btn-sm" title="<?= $cetEsc(CET_RESCAN_TIP) ?>"><?= $cetEsc(CET_RESCAN) ?></button>
                    </form>
                    <?= zen_draw_form('cet_remove', FILENAME_CORE_EDIT_TRACKER, '', 'post', 'onsubmit="return confirm(' . $cetEsc((string)json_encode(CET_REMOVE_CONFIRM)) . ')"') ?>
                        <input type="hidden" name="action" value="remove_release">
                        <button type="submit" class="cet-btn cet-btn-sm"><?= $cetEsc(CET_REMOVE_RELEASE) ?></button>
                    </form>
                </div>
            </details>
        </div>
    </header>
    <?php if ($cetCounts !== null) {
        $cetAlone = (int)($cetCounts['override_alone'] ?? 0);
        $cetOnTop = 0;
        foreach ($cetScan['rows'] as $cetR) {
            $cetOnTop += (isset($cetR['own']) && ($cetR['bs'] ?? '') === 'differs') ? 1 : 0;
        }
        $cetTiles = [
            'edit' => [(int)$cetCounts['edit_yours'], CET_TILE_EDITS, CET_TILE_EDITS_SUB],
            'override' => [(int)$cetCounts['override_yours'], CET_TILE_OVERRIDES, sprintf(CET_TILE_OVERRIDES_SUB, (int)$cetCounts['override_yours'] - $cetAlone - (int)($cetCounts['override_same'] ?? 0), $cetAlone) . ((int)($cetCounts['override_same'] ?? 0) > 0 ? ', ' . ((int)$cetCounts['override_same'] === 1 ? CET_TILE_OVERRIDES_SAME_ONE : sprintf(CET_TILE_OVERRIDES_SAME, (int)$cetCounts['override_same'])) : '')],
            'owned' => [(int)$cetCounts['edit_owned'] + (int)$cetCounts['override_owned'] + (int)$cetCounts['missing_owned'], CET_TILE_OWNED, $cetOnTop > 0 ? sprintf(CET_TILE_OWNED_SUB_EDITED, $cetOnTop) : CET_TILE_OWNED_SUB],
            'missing' => [(int)$cetCounts['missing_yours'], CET_TILE_MISSING, CET_TILE_MISSING_SUB],
            'changed' => [(int)$cetCounts['changes'], CET_TILE_CHANGED, CET_TILE_CHANGED_SUB],
        ];
        ?>
        <nav class="cet-tiles" aria-label="<?= $cetEsc(CET_FILTER_GROUP) ?>">
            <?php foreach ($cetTiles as $cetKey => [$cetNumber, $cetLabel, $cetSub]) {
                if ($cetKey === 'owned' && $cetNumber === 0 && $cetGroup !== 'owned') {
                    continue;
                } ?>
                <a class="cet-tile<?= $cetGroup === $cetKey ? ' cet-on' : '' ?>" href="<?= $cetUrl('done=1' . ($cetKey !== 'edit' ? '&group=' . $cetKey : '')) ?>"<?= $cetGroup === $cetKey ? ' aria-current="true"' : '' ?>>
                    <b><?= number_format($cetNumber) ?></b><span><?= $cetEsc($cetLabel) ?></span><small><?= $cetEsc($cetSub) ?></small>
                </a>
            <?php } ?>
        </nav>
        <?php
        $cetList = [];
        $cetInGroupTotal = 0;
        foreach ($cetScan['rows'] as $cetP => $cetR) {
            $cetP = (string)$cetP;
            if (!$cetInGroup($cetR, $cetGroup)) {
                continue;
            }
            $cetInGroupTotal++;
            if ($cetHideEol && !empty($cetR['eol'])) {
                continue;
            }
            if ($cetQuery === '' || stripos($cetP, $cetQuery) !== false || stripos((string)($cetR['own'] ?? ''), $cetQuery) !== false || stripos((string)($cetR['note'] ?? ''), $cetQuery) !== false) {
                $cetList[$cetP] = $cetR;
            }
        }
        if ($cetGroup === 'owned') {
            foreach ($cetScan['added'] ?? [] as $cetP => $cetR) {
                $cetInGroupTotal++;
                if ($cetQuery === '' || stripos((string)$cetP, $cetQuery) !== false || stripos((string)($cetR['own'] ?? ''), $cetQuery) !== false || stripos((string)($cetR['note'] ?? ''), $cetQuery) !== false) {
                    $cetList[(string)$cetP] = $cetR;
                }
            }
        }
        if ($cetSort === 'changed') {
            uasort($cetList, static fn($x, $y) => (($y['a'] ?? 0) + ($y['d'] ?? 0)) <=> (($x['a'] ?? 0) + ($x['d'] ?? 0)));
        } elseif ($cetSort === 'date') {
            uasort($cetList, static fn($x, $y) => ($y['t'] ?? 0) <=> ($x['t'] ?? 0));
        }
        // Rows are grouped by plugin in the plugin list, and by top folder when sorted by folder.
        $cetSections = [];
        foreach ($cetList as $cetP => $cetR) {
            if ($cetGroup === 'owned') {
                $cetSection = (string)($cetR['own'] ?? '');
            } elseif ($cetSort === 'folder') {
                $cetCut = strpos($cetP, '/');
                $cetSection = $cetCut === false ? '' : substr($cetP, 0, $cetCut + 1);
            } else {
                $cetSection = '';
            }
            $cetSections[$cetSection][$cetP] = $cetR;
        }
        if ($cetGroup !== 'owned' && $cetSort === 'folder' && isset($cetSections[''])) {
            $cetSections = ['' => $cetSections['']] + $cetSections;
        }
        $cetRowHtml = static function (string $path, array $row, string $prefix) use ($cetEsc, $cetUrl, $cetBytes, $cetDate, $cetLines, $cetChips, $cetGroup, $cetListParams, $cetPeek, $cetPair, $cetRelease, $cetText): void {
            $open = $cetPeek === $path;
            $canCompare = in_array($row['g'], ['edit', 'override', 'added'], true) && empty($row['bin']) && empty($row['big']) && empty($row['ur']);
            $canPeek = $canCompare && isset($row['a']) && empty($row['sec']) && empty($row['same']) && ((int)$row['a'] + (int)$row['d']) > 0;
            $anchor = 'cet-f' . substr(md5($path), 0, 10);
            $shown = ($prefix !== '' && str_starts_with($path, $prefix)) ? substr($path, strlen($prefix)) : $path;
            $slash = strrpos($shown, '/');
            $notes = $cetChips($row, $cetGroup === 'changed' || $cetGroup === 'all');
            if ($row['g'] === 'override' && ($row['def'] ?? '') !== '') {
                $notes .= ' <span class="cet-muted cet-small" title="' . $cetEsc($row['def']) . '" style="overflow: hidden; text-overflow: ellipsis; white-space: nowrap">' . $cetEsc(sprintf(CET_PAIRED_WITH, $row['def'])) . '</span>';
            }
            if ($cetGroup === 'owned' && isset($row['note'])) {
                $notes .= ' <span class="cet-muted cet-small">' . $cetEsc($row['note']) . '</span>';
            }
            if ($row['g'] === 'missing' || $row['g'] === 'override_gone' || $row['g'] === 'added_missing') {
                $notes = '<span class="cet-chip">' . $cetEsc($cetText('CET_GROUP_', (string)$row['g'])) . '</span> ' . $notes;
            }
            ?>
            <div class="cet-row<?= $open ? ' cet-row-on' : '' ?>" id="<?= $anchor ?>">
                <div class="cet-mono"><span class="cet-dir"><?= $cetEsc($slash === false ? '' : substr($shown, 0, $slash + 1)) ?></span><b><?= $cetEsc($slash === false ? $shown : substr($shown, $slash + 1)) ?></b></div>
                <div class="cet-lines"><?= $cetLines($row) ?></div>
                <div class="cet-muted"><?= isset($row['t']) ? $cetEsc($cetDate($row['t'])) : '' ?><?= isset($row['s']) ? '<span class="cet-small">' . $cetEsc($cetBytes($row['s'])) . '</span>' : '' ?></div>
                <div><?= $notes ?></div>
                <div class="cet-row-end">
                    <?php if ($canPeek) { ?><a href="<?= $cetUrl($cetListParams . ($open ? '' : '&peek=' . rawurlencode($path))) ?>#<?= $anchor ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>"><?= $cetEsc($open ? CET_PEEK_HIDE : CET_PEEK) ?></a><?php } ?>
                    <?php if ($canCompare) { ?><a href="<?= $cetUrl('done=1&view=compare&path=' . rawurlencode($path) . '&from=' . $cetGroup . (!empty($row['bv']) && $row['g'] === 'added' ? '&against=base' : '')) ?>"><?= $cetEsc(CET_COMPARE) ?></a><?php } ?>
                </div>
            </div>
            <?php
            if ($open && $canPeek) {
                $data = $cetPair($path, $row, 'release');
                if ($data['state'] === 'ok') {
                    $left = LineDiff::split($data['left']);
                    $right = LineDiff::split($data['right']);
                    $peek = DiffView::render($left, $right, ['result' => LineDiff::compare($left, $right, ['eol' => true]), 'context' => 2, 'mask' => true, 'limit' => 16, 'skip_text' => CET_PEEK_SKIP]);
                    ?>
                    <div class="cet-peek"><div class="cet-peek-box">
                        <div class="cet-scroll"><div class="cet-diff"><?= $peek['html'] ?></div></div>
                        <div class="cet-peek-foot"><span><?= $cetEsc(sprintf(CET_PEEK_FOOT, $cetRelease['version'])) ?></span>
                            <a href="<?= $cetUrl('done=1&view=compare&path=' . rawurlencode($path) . '&from=' . $cetGroup) ?>"><b><?= $cetEsc(CET_PEEK_OPEN) ?></b></a></div>
                    </div></div>
                    <?php
                }
            }
        };
        ?>
        <form class="cet-filter" method="get" action="<?= $cetUrl() ?>">
            <input type="hidden" name="cmd" value="<?= $cetEsc(FILENAME_CORE_EDIT_TRACKER) ?>">
            <input type="hidden" name="done" value="1">
            <input type="hidden" name="group" value="<?= $cetEsc($cetGroup) ?>">
            <label for="cet-q" class="cet-sr"><?= $cetEsc(CET_FILTER_SEARCH) ?></label>
            <input type="search" id="cet-q" name="q" value="<?= $cetEsc($cetQuery) ?>" class="cet-input" style="width: 260px" placeholder="<?= $cetEsc(CET_FILTER_SEARCH) ?>">
            <label for="cet-sort"><?= $cetEsc(CET_FILTER_SORT) ?></label>
            <select id="cet-sort" name="sort" class="cet-select">
                <?php foreach (['folder' => CET_SORT_FOLDER, 'changed' => CET_SORT_CHANGED, 'date' => CET_SORT_DATE] as $cetValue => $cetLabel) { ?>
                    <option value="<?= $cetValue ?>"<?= $cetSort === $cetValue ? ' selected' : '' ?>><?= $cetEsc($cetLabel) ?></option>
                <?php } ?>
            </select>
            <label><input type="checkbox" name="hide_eol" value="1"<?= $cetHideEol ? ' checked' : '' ?>> <?= $cetEsc(CET_FILTER_HIDE_EOL) ?></label>
            <button type="submit" class="cet-btn cet-btn-sm"><?= $cetEsc(CET_FILTER_BUTTON) ?></button>
            <span class="cet-muted" style="margin-left: auto"><?= $cetEsc(sprintf(CET_SHOWN, number_format(count($cetList)), number_format($cetInGroupTotal))) ?></span>
        </form>
        <section class="cet-card">
            <div class="cet-scroll"><div class="cet-list">
                <div class="cet-row cet-row-head"><div><?= $cetEsc(CET_COL_PATH) ?></div><div><?= $cetEsc(CET_COL_LINES) ?></div><div><?= $cetEsc(CET_COL_DATE) ?></div><div><?= $cetEsc(CET_COL_NOTES) ?></div><div></div></div>
                <?php if ($cetList === []) { ?>
                    <div class="cet-row"><div class="cet-muted"><?= $cetEsc(CET_NOTHING_LISTED) ?></div></div>
                <?php } ?>
                <?php foreach ($cetSections as $cetSection => $cetRows) {
                    if ($cetGroup === 'owned') {
                        $cetEqual = 0;
                        $cetDiffer = 0;
                        foreach ($cetRows as $cetR) {
                            $cetEqual += (($cetR['bs'] ?? '') === 'equal') ? 1 : 0;
                            $cetDiffer += (($cetR['bs'] ?? '') === 'differs') ? 1 : 0;
                        }
                        ?>
                        <details class="cet-owner"<?= ($cetQuery !== '' || $cetPeek !== '' || count($cetSections) === 1) ? ' open' : '' ?>>
                            <summary>
                                <b><?= $cetEsc($cetSection) ?></b>
                                <span class="cet-muted"><?= $cetEsc(count($cetRows) === 1 ? CET_COUNT_FILE : sprintf(CET_COUNT_FILES, number_format(count($cetRows)))) ?></span>
                                <?php if ($cetEqual > 0) { ?><span class="cet-chip cet-chip-ok"><?= $cetEsc(sprintf(CET_OWNER_EQUAL, $cetEqual)) ?></span><?php } ?>
                                <?php if ($cetDiffer > 0) { ?><span class="cet-chip cet-chip-warn"><?= $cetEsc(sprintf(CET_OWNER_DIFFER, $cetDiffer)) ?></span><?php } ?>
                            </summary>
                            <?php foreach ($cetRows as $cetP => $cetR) {
                                $cetRowHtml((string)$cetP, $cetR, '');
                            } ?>
                        </details>
                        <?php
                        continue;
                    }
                    if ($cetSort === 'folder') { ?>
                        <div class="cet-group"><b class="cet-mono"><?= $cetEsc($cetSection === '' ? CET_ROOT_FOLDER : $cetSection) ?></b><span><?= $cetEsc(count($cetRows) === 1 ? CET_COUNT_FILE : sprintf(CET_COUNT_FILES, number_format(count($cetRows)))) ?></span></div>
                    <?php }
                    foreach ($cetRows as $cetP => $cetR) {
                        $cetRowHtml((string)$cetP, $cetR, (string)$cetSection);
                    }
                } ?>
            </div></div>
        </section>
        <div class="cet-foot">
            <span><b><?= (int)$cetScan['not_expected']['absent'] ?></b> <?= $cetEsc(CET_NOT_EXPECTED) ?><?php $cetRules = PathRules::notExpectedSummary(); ?><?= $cetTip(sprintf(CET_NOT_EXPECTED_TIP, (int)$cetScan['not_expected']['present'], implode(', ', $cetRules['folders']), implode(', ', $cetRules['top_files']), implode(', ', $cetRules['any_name']))) ?></span>
            <span><b><?= count($cetScan['credentials']) ?></b> <?= $cetEsc(CET_CREDENTIALS) ?><?= $cetTip(CET_CREDENTIALS_TIP . ' ' . implode(', ', $cetScan['credentials'])) ?></span>
            <span><?= $cetEsc(sprintf(CET_TEMPLATES, implode(', ', $cetScan['templates'] ?? [$cetScan['template']]))) ?><?= $cetTip(CET_TEMPLATES_TIP) ?></span>
            <?php $cetCompanion = $cetScan['companion'] ?? []; ?>
            <?php if (!empty($cetCompanion['off']) || !empty($cetCompanion['present'])) { ?>
            <span><?= $cetEsc(CET_COMPANION) ?>:
                <?php if (!empty($cetCompanion['off'])) { ?>
                    <?= $cetEsc(CET_COMPANION_OFF) ?>
                <?php } else { ?>
                    <?= $cetEsc(sprintf(CET_COMPANION_VALUE, number_format((int)$cetCompanion['owned']), count($cetCompanion['endings']))) ?>
                    <?php if (!empty($cetCompanion['refused'])) { ?><span class="cet-warn-text"><?= $cetEsc(sprintf(CET_COMPANION_REFUSED, count($cetCompanion['refused']))) ?></span><?php } ?>
                <?php } ?><?= $cetTip(CET_COMPANION_TIP) ?></span>
            <?php } ?>
        </div>
    <?php } ?>
<?php
}
?>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var page = document.querySelector('.cet');
    <?php if ($cetAutoScan) { ?>
    var form = document.getElementById('cet-scan-form');
    if (form) {
        var note = document.getElementById('cet-scanning');
        if (note) {
            note.style.display = 'inline';
        }
        page.querySelectorAll('button, input[type=submit]').forEach(function (b) {
            b.disabled = true;
        });
        form.submit();
        return;
    }
    <?php } ?>
    var hunks = page.querySelectorAll('[id^="cet-h"]');
    var at = 0;
    var counter = document.getElementById('cet-at');
    var detail = document.getElementById('cet-detail');
    function select(row) {
        if (!detail || !row) {
            return;
        }
        var old = page.querySelector('.cet-r.cet-sel');
        if (old) {
            old.classList.remove('cet-sel');
        }
        row.classList.add('cet-sel');
        var left = row.querySelector('.cet-cl');
        var right = row.querySelector('.cet-cr');
        var leftNo = row.querySelector('.cet-nl');
        var rightNo = row.querySelector('.cet-nr');
        if (row.classList.contains('cet-i')) {
            var pair = row.classList.contains('cet-del') ? row.nextElementSibling : row.previousElementSibling;
            if (pair && pair.classList.contains('cet-i') && pair.dataset.hunk === row.dataset.hunk) {
                left = left || pair.querySelector('.cet-cl');
                right = right || pair.querySelector('.cet-cr');
                leftNo = leftNo || pair.querySelector('.cet-nl');
                rightNo = rightNo || pair.querySelector('.cet-nr');
            }
        }
        document.getElementById('cet-detail-l').innerHTML = left && !left.classList.contains('cet-x') ? left.innerHTML : '';
        document.getElementById('cet-detail-r').innerHTML = right && !right.classList.contains('cet-x') ? right.innerHTML : '';
        document.getElementById('cet-detail-ln').textContent = leftNo ? leftNo.textContent : '';
        document.getElementById('cet-detail-rn').textContent = rightNo ? rightNo.textContent : '';
        detail.hidden = false;
        if (counter && row.dataset.hunk) {
            at = parseInt(row.dataset.hunk, 10) - 1;
            counter.textContent = at + 1;
        }
    }
    function go(step) {
        if (!hunks.length) {
            return;
        }
        at = (at + step + hunks.length) % hunks.length;
        hunks[at].scrollIntoView({block: 'center'});
        select(hunks[at]);
    }
    page.querySelectorAll('.cet-r[data-hunk]').forEach(function (row) {
        row.addEventListener('click', function () {
            select(row);
        });
        row.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                select(row);
            }
        });
    });
    var prev = document.getElementById('cet-prev');
    var next = document.getElementById('cet-next');
    if (prev && next) {
        prev.addEventListener('click', function () {
            go(-1);
        });
        next.addEventListener('click', function () {
            go(1);
        });
        select(hunks[0]);
    }
    var copy = document.getElementById('cet-copy');
    var patch = document.getElementById('cet-patch');
    if (copy && patch) {
        copy.addEventListener('click', function () {
            var label = copy.textContent;
            var done = function () {
                copy.textContent = copy.dataset.done;
                setTimeout(function () {
                    copy.textContent = label;
                }, 1500);
            };
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(patch.value).then(done);
            } else {
                patch.select();
                document.execCommand('copy');
                done();
            }
        });
    }
    var sideQuery = document.getElementById('cet-side-q');
    var sideList = document.getElementById('cet-side-list');
    if (sideQuery && sideList) {
        sideQuery.addEventListener('input', function () {
            var q = sideQuery.value.toLowerCase();
            sideList.querySelectorAll('a').forEach(function (a) {
                a.style.display = a.textContent.toLowerCase().indexOf(q) === -1 ? 'none' : '';
            });
        });
        var current = sideList.querySelector('.cet-on');
        if (current) {
            sideList.scrollTop = Math.max(0, current.offsetTop - sideList.offsetTop - 120);
        }
    }
});
</script>
<?php require DIR_WS_INCLUDES . 'footer.php'; ?>
</body>
</html>
<?php
require DIR_WS_INCLUDES . 'application_bottom.php';
