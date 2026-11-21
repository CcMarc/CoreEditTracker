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
    'HEADING_TITLE' => 'Core Edit Tracker',
    'CET_INTRO_TIP' => 'Compares this store with the official Zen Cart release it runs. It only reads files. It never changes a store file.',

    'CET_FIRST_RUN_TITLE' => 'Give the tracker the official Zen Cart %s zip',
    'CET_FIRST_RUN_TEXT' => 'Download the Zen Cart %s release zip from zen-cart.com and upload it here, or place it in the data folder and reload this page.',
    'CET_FIRST_RUN_OUTDATED' => 'This store now runs Zen Cart %1$s. The release zip the tracker holds is %2$s, so it can no longer be compared. Add the %1$s zip to carry on.',
    'CET_UPLOAD_LABEL' => 'Release zip',
    'CET_UPLOAD_BUTTON' => 'Upload and check',
    'CET_UPLOAD_LIMIT' => 'This server accepts uploads up to %s. A larger file can be placed in the data folder instead.',
    'CET_PLACED_TITLE' => 'Zip files found in the data folder',
    'CET_USE_PLACED' => 'Use this file',
    'CET_NO_PLACED' => 'No zip file in the data folder.',

    'CET_FOLDER_LABEL' => 'Data folder',
    'CET_FOLDER_INTRO' => 'Where the tracker keeps the Zen Cart release zip. Most stores never need to change this.',
    'CET_FOLDER_IN_USE' => 'In use now',
    'CET_FOLDER_KEPT_IN' => 'Release zip kept in',
    'CET_FOLDER_CHANGE' => 'Change folder',
    'CET_FOLDER_TIP' => 'The release zip is kept here as it is, under a name nobody can guess. It is never unpacked. Single files are read from it when needed.',
    'CET_FOLDER_OUTSIDE' => 'Outside the web root',
    'CET_FOLDER_WHERE' => 'Where to keep it',
    'CET_FOLDER_BESIDE' => 'Beside the web root',
    'CET_FOLDER_BESIDE_TEXT' => 'The web cannot reach this folder.',
    'CET_FOLDER_CACHE' => 'Store cache folder',
    'CET_FOLDER_CACHE_TEXT' => 'Closed by a deny file and tested from the web.',
    'CET_FOLDER_NOT_AVAILABLE' => 'Not available here. The web server cannot write to this location.',
    'CET_FOLDER_CUSTOM' => 'A folder I choose',
    'CET_FOLDER_CUSTOM_LABEL' => 'Folder path on the server',
    'CET_FOLDER_CUSTOM_TEXT' => 'It must exist, sit outside the web root and be writable by the web server. The tracker makes its own subfolder there.',
    'CET_FOLDER_MOVE' => 'Use this folder',
    'CET_FOLDER_MOVE_TIP' => 'The release zip moves to the folder you pick and the old folder is cleaned up. No store file is touched.',
    'CET_FOLDER_REASON_NOT_ABSOLUTE' => 'give the full path of the folder',
    'CET_FOLDER_REASON_NOT_A_FOLDER' => 'the folder does not exist',
    'CET_FOLDER_REASON_NOT_WRITABLE' => 'the web server cannot write to it',
    'CET_FOLDER_REASON_INSIDE_WEB_ROOT' => 'it is inside the web root',
    'CET_CHECK_PROTECTED' => 'Data folder closed to the web',
    'CET_CHECK_TESTED' => 'Tested %s. The web cannot fetch files in this folder.',
    'CET_CHECK_REACHABLE' => 'Data folder can be reached from the web. Deny web access to it as the README shows.',
    'CET_CHECK_UNTESTED' => 'Data folder not tested',
    'CET_CHECK_NONE' => 'Data folder not tested yet',
    'CET_CHECK_WHY_NOT_IN_STORE' => 'The folder is not inside the store, so it cannot be requested through the store address. Check it by hand as the README says.',
    'CET_CHECK_WHY_NO_STORE_URL' => 'The store address is not set. Check the folder by hand as the README says.',
    'CET_CHECK_WHY_NO_CURL' => 'PHP has no cURL on this server. Check the folder by hand as the README says.',
    'CET_CHECK_WHY_CANNOT_WRITE' => 'The test file could not be written. The next scan tries again.',
    'CET_CHECK_WHY_REQUEST_FAILED' => 'The test request did not get an answer (cURL error %s). The next scan tries again.',
    'CET_CHECK_WHY_ANSWERED' => 'The server answered the test request with status %s, which proves nothing either way. Check the folder by hand as the README says.',

    'CET_RELEASE_IN_USE' => 'Zen Cart %1$s, %2$s release files',
    'CET_REMOVE_RELEASE' => 'Remove release zip',
    'CET_REMOVE_CONFIRM' => 'Remove the release zip from the tracker?',
    'CET_LAST_SCAN_VALUE' => 'Scanned %1$s in %2$s ms',
    'CET_NO_SCAN' => 'Not scanned yet',
    'CET_KIND_QUICK' => 'quick',
    'CET_KIND_FULL' => 'full',
    'CET_SCAN_NOW' => 'Scan now',
    'CET_SCAN_TIP' => 'The last scan was a %1$s scan and read %2$s files. The page scans each time it opens. A quick scan reuses the last hash of a file whose size and date did not change.',
    'CET_RESCAN' => 'Rescan everything',
    'CET_RESCAN_TIP' => 'Reads every file again. Use it after an upload that kept the file dates.',
    'CET_SCANNING' => 'Scanning...',
    'CET_MORE' => 'More actions',

    'CET_TILE_EDITS' => 'Your core edits',
    'CET_TILE_EDITS_SUB' => 'Zen Cart files you changed',
    'CET_TILE_OVERRIDES' => 'Your template overrides',
    'CET_TILE_OVERRIDES_SUB' => '%1$s change a core file, %2$s yours only',
    'CET_TILE_OVERRIDES_SAME' => '%s exact copies',
    'CET_TILE_OVERRIDES_SAME_ONE' => '1 exact copy',
    'CET_TILE_OWNED' => 'Owned by a plugin',
    'CET_TILE_OWNED_SUB' => 'Kept out of your export',
    'CET_TILE_OWNED_SUB_EDITED' => '%s with your own edit, exported',
    'CET_TILE_MISSING' => 'Missing',
    'CET_TILE_MISSING_SUB' => 'Release files not on this store',
    'CET_TILE_CHANGED' => 'Changed since last look',
    'CET_TILE_CHANGED_SUB' => 'Against the scan before',

    'CET_FILTER_GROUP' => 'Show',
    'CET_FILTER_SEARCH' => 'Search file, plugin or note',
    'CET_FILTER_SORT' => 'Sort',
    'CET_SORT_FOLDER' => 'By folder',
    'CET_SORT_CHANGED' => 'Most changed',
    'CET_SORT_DATE' => 'Last modified',
    'CET_FILTER_HIDE_EOL' => 'Hide line ending only',
    'CET_FILTER_BUTTON' => 'Apply',
    'CET_SHOWN' => '%1$s of %2$s listed',

    'CET_COL_PATH' => 'File',
    'CET_COL_LINES' => 'Lines changed',
    'CET_COL_DATE' => 'Modified',
    'CET_COL_NOTES' => 'Notes',
    'CET_COMPARE' => 'Compare',
    'CET_PEEK' => 'Peek',
    'CET_PEEK_HIDE' => 'Hide',
    'CET_PEEK_FOOT' => 'First change. Zen Cart %s on the left, your store on the right.',
    'CET_PEEK_OPEN' => 'Open full compare',
    'CET_PEEK_SKIP' => '%s equal lines',
    'CET_NOTHING_LISTED' => 'Nothing to list.',
    'CET_ROOT_FOLDER' => 'Store root',
    'CET_COUNT_FILE' => '1 file',
    'CET_COUNT_FILES' => '%s files',
    'CET_OWNER_EQUAL' => '%s equal the plugin\'s copy',
    'CET_OWNER_DIFFER' => '%s edited on top',

    'CET_GROUP_EDIT' => 'Core edit',
    'CET_GROUP_OVERRIDE' => 'Template override',
    'CET_GROUP_MISSING' => 'Missing',
    'CET_GROUP_STOCK' => 'Stock',
    'CET_GROUP_OVERRIDE_GONE' => 'Override removed',
    'CET_GROUP_ADDED' => 'Added by a plugin',
    'CET_GROUP_ADDED_MISSING' => 'Declared by a plugin, not on the store',
    'CET_PAIRED_WITH' => 'replaces %s',
    'CET_SAME_COPY' => 'Exact copy',
    'CET_SAME_COPY_TIP' => 'This template file is an exact copy of the Zen Cart file it replaces. It holds no edit. After an upgrade it keeps the old version in use, so it should be replaced with the new release\'s file or removed.',
    'CET_TEMPLATE_ONLY' => 'Yours only',
    'CET_TEMPLATE_ONLY_TIP' => 'Zen Cart has no file by this name, so there is nothing to compare it with and it is not exported.',
    'CET_MARK_NEW' => 'Newly edited',
    'CET_MARK_CHANGED' => 'Edit changed',
    'CET_MARK_BACK' => 'Back to stock',
    'CET_MARK_GONE' => 'Newly missing',
    'CET_MARKER' => 'Marker %s',
    'CET_MARKER_EQUAL' => 'Marker %s (the release file)',
    'CET_MARKER_DIFFERS' => 'Marker %s (not the release file)',
    'CET_SECRET' => 'May hold a secret',
    'CET_BINARY' => 'Binary',
    'CET_EOL_ONLY' => 'Line endings only',
    'CET_TOO_LARGE' => 'Too large to compare',
    'CET_UNREADABLE' => 'Cannot be read',
    'CET_BASE_EQUAL' => 'Equals its plugin\'s copy',
    'CET_BASE_DIFFERS' => 'Edited on top of its plugin\'s copy',
    'CET_BASE_REFUSED' => 'Plugin\'s copy not verified',

    'CET_NOT_EXPECTED' => 'release files not expected on a live store',
    'CET_NOT_EXPECTED_TIP' => 'Release files Zen Cart tells owners to remove or not to upload. They are absent here and so not listed as missing. %1$s such files are on this store and are compared like any release file. Folders %2$s. Top level files %3$s. Anywhere: %4$s. The zc_plugins folder is outside the comparison.',
    'CET_CREDENTIALS' => 'credential files never opened',
    'CET_CREDENTIALS_TIP' => 'Configure files and any copy of one are never opened, shown or exported.',
    'CET_TEMPLATES' => 'Templates: %s',
    'CET_TEMPLATES_TIP' => 'Every template assigned in Tools > Template Selection. Files in their override folders are compared with the core file they replace.',
    'CET_COMPANION' => 'Companion plugins',
    'CET_COMPANION_OFF' => 'switched off in Configuration',
    'CET_COMPANION_VALUE' => '%1$s files declared, %2$s marker endings',
    'CET_COMPANION_REFUSED' => '%s entries refused',
    'CET_COMPANION_TIP' => 'Another plugin can tell the tracker which files it owns and which file endings are marker copies. See the README.',

    'CET_EXPORT_TITLE' => 'Export',
    'CET_EXPORT_INTRO' => 'One zip with every Zen Cart file you changed and the original beside it. Use it when you upgrade Zen Cart.',
    'CET_EXPORT_WHAT' => 'What goes in',
    'CET_EXPORT_EDITS' => 'core edits',
    'CET_EXPORT_EDITS_TEXT' => 'Zen Cart files you changed',
    'CET_EXPORT_OVERRIDES' => 'template overrides',
    'CET_EXPORT_OVERRIDES_TEXT' => 'Template files of yours that replace a Zen Cart file',
    'CET_EXPORT_ON_TOP' => 'plugin files you edited',
    'CET_EXPORT_ON_TOP_TEXT' => 'Files a plugin owns that you changed too',
    'CET_EXPORT_NOT_NEEDED' => 'Not needed in the zip',
    'CET_EXPORT_NOT_NEEDED_TIP' => 'A file that is yours alone has no Zen Cart file to merge with and stays on the store through an upgrade. An exact copy holds no edit and is replaced by the new release\'s file. A plugin updates its own files. All of them are still named in MANIFEST.json. Configure files are never opened.',
    'CET_EXPORT_OUT_OWNED' => '%s files that plugins own',
    'CET_EXPORT_OUT_ALONE' => '%s template files that are yours alone',
    'CET_EXPORT_OUT_MISSING' => '%s Zen Cart files your store does not have',
    'CET_EXPORT_OUT_SAME' => '%s template files that are exact copies of the Zen Cart file',
    'CET_EXPORT_OUT_SAME_ONE' => '1 template file that is an exact copy of the Zen Cart file',
    'CET_EXPORT_OUT_OWNED_ONE' => '1 file that a plugin owns',
    'CET_EXPORT_OUT_ALONE_ONE' => '1 template file that is yours alone',
    'CET_EXPORT_OUT_MISSING_ONE' => '1 Zen Cart file your store does not have',
    'CET_EXPORT_SECRETS' => 'Also include files that may hold a secret (%s left out)',
    'CET_EXPORT_SECRETS_TIP' => 'The screen for secrets is a screen, not a guarantee. Look at the files before you share the zip.',
    'CET_EXPORT_BUTTON' => 'Download zip',
    'CET_EXPORT_COUNT' => '%s files with their originals. Nothing on your store changes.',
    'CET_EXPORT_COUNT_ONE' => '1 file with its original. Nothing on your store changes.',
    'CET_EXPORT_INSIDE' => 'What is in the zip and why',
    'CET_ZIP_YOURS' => 'Your file.',
    'CET_ZIP_YOURS_TEXT' => 'What your store runs today.',
    'CET_ZIP_ORIGINAL' => 'The Zen Cart %s file.',
    'CET_ZIP_ORIGINAL_TEXT' => 'Comparing the two shows exactly what you changed. For a template file it is the Zen Cart file yours replaces.',
    'CET_ZIP_PLUGIN' => 'A plugin\'s own copy.',
    'CET_ZIP_PLUGIN_TEXT' => 'Only there when a plugin owns the file and you edited it too, so just your part is carried forward.',
    'CET_ZIP_CONFIGURE' => 'Zen Cart\'s sample configure file.',
    'CET_ZIP_CONFIGURE_TEXT' => 'Your own configure files hold your passwords and are never in the zip. This sample is enough to tell you which settings a new release adds.',
    'CET_ZIP_README_TEXT' => 'Step by step instructions for whoever does the upgrade.',
    'CET_ZIP_MANIFEST_TEXT' => 'The full list of files, including what was left out and why.',
    'CET_EXPORT_HOW' => 'When a new Zen Cart release comes out',
    'CET_EXPORT_STEP_1' => 'Download this zip and the new Zen Cart release zip.',
    'CET_EXPORT_STEP_2' => 'Give both to whoever does the upgrade and point them to the README.md inside.',
    'CET_EXPORT_STEP_3' => 'You get back each finished file, ready to add to the new release before you upgrade.',
    'CET_EXPORT_STEP_4' => 'Keep your own configure.php files. You are told which settings the new release adds, to put in by hand.',
    'CET_EXPORT_README' => '# Custom files of a Zen Cart %1$s store

This zip holds every Zen Cart file the store owner changed, as found by Core Edit Tracker. Use it to carry those changes to a newer Zen Cart release.

## What is in the zip and why

Everything is under `files/`, laid out like the store.

- `files/<path>` is the store\'s file. It is what the store runs today.
- `files/<path>%2$s` is the official Zen Cart %1$s file it came from. Comparing the two shows exactly what the owner changed. Every file has one.
- `MANIFEST.json` lists every file with its group, hashes and where its original came from (`original_from`). It also lists the installed plugins and every file that was left out and why.

Groups: `edit` is a Zen Cart file the store changed. `override` is a file in a template override folder that replaces a Zen Cart file. Its original is the Zen Cart file it replaces, which sits at another path in the release. That path is `original_from`.

Paths under `admin/` stand for the store\'s admin folder, whatever its name on the server.

## How to carry the edits to a newer release

1. For each pair, compare the `%2$s` file with the store\'s file. The difference is the owner\'s change.
2. Take the same file from the newer release. For an override, take the file named in `original_from`.
3. Apply the owner\'s change to it. Keep everything the newer release changed.
4. A pair that is not text (an image for example) cannot be merged. Keep the store\'s file.
5. Return each finished file under its path without the `files/` prefix, ready to place over the newer release. Report every file where the owner\'s change and the newer release touch the same lines.

{plugin_section}{configure_section}## What is not here

- Files with reason `identical_copy` in `left_out` are template files that are exact copies of the Zen Cart file named in `original_from`. They hold no edit, but the store would keep running the old copy. For each one, return the newer release\'s `original_from` file under the override\'s path, or tell the owner the override can be removed.
- Files with reason `no_original` in `left_out` exist only on the store. Zen Cart has no file by that name, so they stay as they are.
- Files with reason `not_on_store` are release files the store does not have.
- The store\'s configure files are never in this zip. Files flagged as possibly holding a secret are left out unless the owner chose to include them.

Do not unzip this over a store or over the newer release.
',
    'CET_EXPORT_README_CONFIGURE' => '## The configure files

The store\'s own `configure.php` files hold its passwords. They are never in this zip. Do not ask for them, and do not replace them with files from the newer release.

`%2$s` holds the sample configure files of Zen Cart %1$s (`dist-configure.php`). Compare them with the same files in the newer release. List every setting the newer release added, removed or renamed, with the line as the newer sample has it, so the owner can make those changes in their own configure files by hand.

',
    'CET_EXPORT_README_PLUGIN' => '## Files a plugin owns

Some files on this store belong to a plugin.

- `files/<path>%1$s` is that plugin\'s own copy of a file. It is there only when the owner also edited the file. Carry only the difference between the `%1$s` file and the store\'s file. The rest belongs to the plugin.
- Files with reason `owned` in `left_out` belong to a plugin and carry no edit of the owner. Do not carry them. Use the newer release\'s file and let the plugin bring its own.

',

    'CET_VIEW_BREADCRUMB' => 'You are here',
    'CET_VIEW_FILES' => 'Files in this list',
    'CET_VIEW_FILE_OF' => 'File %1$s of %2$s',
    'CET_VIEW_PREV_FILE' => 'Previous file',
    'CET_VIEW_NEXT_FILE' => 'Next file',
    'CET_VIEW_COPY_PATCH' => 'Copy as patch',
    'CET_VIEW_COPIED' => 'Copied',
    'CET_VIEW_CHANGES' => 'Changes only',
    'CET_VIEW_WHOLE' => 'Whole file',
    'CET_VIEW_SIDE' => 'Side by side',
    'CET_VIEW_INLINE' => 'Inline',
    'CET_VIEW_WS_SHOW' => 'Show whitespace',
    'CET_VIEW_WS_IGNORE' => 'Ignore whitespace',
    'CET_VIEW_RELEASE' => 'Against release',
    'CET_VIEW_BASE' => 'Against plugin\'s copy',
    'CET_VIEW_CHANGE_OF' => 'Change %1$s of %2$s',
    'CET_VIEW_CHANGE_N' => 'Change %s',
    'CET_VIEW_PREV_CHANGE' => 'Previous change',
    'CET_VIEW_NEXT_CHANGE' => 'Next change',
    'CET_VIEW_MAP' => 'Where the changes are',
    'CET_VIEW_LEFT_RELEASE' => 'Zen Cart %s release',
    'CET_VIEW_LEFT_BASE' => 'Plugin\'s copy',
    'CET_VIEW_LEFT_NONE' => 'No Zen Cart file',
    'CET_VIEW_RIGHT' => 'Your store',
    'CET_VIEW_SIDE_RELEASE' => 'Release',
    'CET_VIEW_SIDE_BASE' => 'Plugin',
    'CET_VIEW_SIDE_STORE' => 'Your store',
    'CET_VIEW_SELECTED' => 'Selected line',
    'CET_VIEW_SELECTED_TIP' => 'Click a changed line to see both versions here. The parts that differ are marked.',
    'CET_VIEW_SKIP' => 'Show %s equal lines',
    'CET_VIEW_TRUNCATED' => 'The difference is too long to show in full.',
    'CET_VIEW_SECRET' => 'This file may hold a secret. Values the screen finds are shown as asterisks.',
    'CET_VIEW_SECRET_SHOWN' => 'This file may hold a secret. Its values are shown.',
    'CET_VIEW_REVEAL' => 'Show the values',
    'CET_VIEW_BINARY' => 'Binary file. Not shown.',
    'CET_VIEW_IDENTICAL' => 'The two files are identical.',
    'CET_VIEW_EOL_ONLY' => 'Only the line endings differ (CRLF and LF). Every line reads the same.',
    'CET_VIEW_WS_ONLY' => 'Only whitespace differs.',
    'CET_VIEW_MISSING' => 'This file is not on the store.',
    'CET_VIEW_UNAVAILABLE' => 'This file cannot be shown.',
    'CET_VIEW_APPROXIMATE' => 'The files differ too much for an exact line match. Changed blocks are shown whole.',
    'CET_VIEW_ONLY' => 'View only. Nothing here changes a file.',
    'CET_LEGEND_CHANGED' => 'Changed line',
    'CET_LEGEND_ADDED' => 'You added it',
    'CET_LEGEND_REMOVED' => 'You removed it',
    'CET_LEGEND_NONE' => 'No line on this side',

    'CET_SIZE_B' => '%s B',
    'CET_SIZE_KB' => '%s KB',
    'CET_SIZE_MB' => '%s MB',

    'CET_MSG_RELEASE_OK' => 'Release accepted: Zen Cart %1$s, %2$s files.',
    'CET_MSG_RELEASE_REMOVED' => 'Release zip removed.',
    'CET_MSG_REFUSED' => 'The zip was refused: %s.',
    'CET_MSG_NOTHING_KEPT' => 'Nothing of it was kept.',
    'CET_MSG_PLACED_LEFT' => 'Your file was left where it is.',
    'CET_MSG_BAD_REQUEST' => 'The request was refused. Reload the page and try again.',
    'CET_MSG_UPLOAD_ERROR' => 'The upload did not arrive (code %s).',
    'CET_MSG_UPLOAD_TOO_LARGE' => 'The file is larger than this server accepts. Place it in the data folder instead.',
    'CET_MSG_EXPORT_FAILED' => 'The package could not be built.',
    'CET_MSG_NO_RELEASE' => 'Add the release zip first.',
    'CET_MSG_FOLDER_MOVED' => 'The data folder is now %s.',
    'CET_MSG_FOLDER_REFUSED' => 'The data folder was not changed: %s.',

    'CET_REASON_ZIP_EXTENSION' => 'PHP has no zip extension on this server',
    'CET_REASON_NOT_A_FILE' => 'the file is empty or missing',
    'CET_REASON_TOO_LARGE' => 'the zip is larger than 64 MB',
    'CET_REASON_NOT_A_ZIP' => 'it is not a zip file',
    'CET_REASON_ENTRY_COUNT' => 'it holds no files or too many',
    'CET_REASON_UNREADABLE_ENTRY' => 'an entry cannot be read',
    'CET_REASON_BAD_NAME' => 'an entry has an unsafe name (%s)',
    'CET_REASON_BAD_ENCODING' => 'an entry name is not valid UTF-8',
    'CET_REASON_ABSOLUTE_PATH' => 'an entry has an absolute path (%s)',
    'CET_REASON_PARENT_PATH' => 'an entry climbs out of its folder (%s)',
    'CET_REASON_SYMLINK' => 'an entry is a symbolic link (%s)',
    'CET_REASON_SPECIAL_FILE' => 'an entry is not a plain file (%s)',
    'CET_REASON_ENCRYPTED' => 'an entry is encrypted (%s)',
    'CET_REASON_ENTRY_TOO_LARGE' => 'an entry is larger than 16 MB (%s)',
    'CET_REASON_SEVERAL_TOP_FOLDERS' => 'it does not hold one single top folder (%s)',
    'CET_REASON_OUTSIDE_TOP_FOLDER' => 'a file sits outside the top folder (%s)',
    'CET_REASON_DUPLICATE_ENTRY' => 'an entry appears twice (%s)',
    'CET_REASON_NO_VERSION_FILE' => 'it has no includes/version.php',
    'CET_REASON_NOT_ZEN_CART' => 'it is not a Zen Cart release (no %s)',
    'CET_REASON_WRONG_VERSION' => 'it is Zen Cart %s, this store runs another version',
    'CET_REASON_CANNOT_STORE' => 'the data folder cannot be written',

    'CET_BASE_NO_HASH' => 'No SHA-256 given',
    'CET_BASE_BAD_PATH' => 'Path not allowed',
    'CET_BASE_UNKNOWN_PLUGIN' => 'Plugin not installed',
    'CET_BASE_OUTSIDE' => 'Outside the allowed folders',
    'CET_BASE_CREDENTIAL' => 'Credential file name',
    'CET_BASE_RUNNABLE_NAME' => 'Its name could run as a script',
    'CET_BASE_SYMLINK' => 'Symbolic link',
    'CET_BASE_NOT_FOUND' => 'File not found',
    'CET_BASE_TOO_LARGE' => 'Too large',
    'CET_BASE_NOT_TEXT' => 'Not text',
    'CET_BASE_HASH_MISMATCH' => 'Hash does not match',
];
return $define;
