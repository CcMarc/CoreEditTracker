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
 * Renders the difference between two versions of a file as HTML, side by side or inline, and as a
 * unified patch. Every line is escaped, so file content is never run by the browser.
 */
class DiffView
{
    private const SIGN = ['change' => '≠', 'add' => '+', 'del' => '−', 'same' => ''];

    public static function esc(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Options: context (lines kept around a change, below zero keeps the whole file), mask (replace
     * values the secret screen finds on changed lines), inline (one column instead of two), result
     * (a LineDiff result to reuse), skip_text (sprintf text for a run of equal lines), skip_url (where
     * a run of equal lines links to), limit (stop after this many rows).
     * Returns ['html' => string, 'hunks' => int, 'marks' => [[top %, height %, type]], 'truncated' => bool].
     */
    public static function render(array $left, array $right, array $options = []): array
    {
        $result = $options['result'] ?? LineDiff::compare($left, $right);
        $rows = LineDiff::rows($result, (int)($options['context'] ?? 4));
        $mask = !empty($options['mask']);
        $inline = !empty($options['inline']);
        $limit = (int)($options['limit'] ?? 20000);
        $total = max(1, count($rows));
        $html = '';
        $hunks = 0;
        $marks = [];
        $inHunk = false;
        $truncated = false;

        foreach ($rows as $index => $row) {
            if ($index >= $limit) {
                $truncated = true;
                break;
            }
            $type = $row['type'];
            if ($type === 'skip') {
                $inHunk = false;
                $text = self::esc(sprintf((string)($options['skip_text'] ?? '%s'), $row['count']));
                $html .= '<div class="cet-skip">' . (isset($options['skip_url'])
                    ? '<a href="' . self::esc((string)$options['skip_url']) . '">' . $text . '</a>'
                    : $text) . '</div>';
                continue;
            }
            $id = '';
            if ($type === 'same') {
                $inHunk = false;
            } else {
                if (!$inHunk) {
                    $hunks++;
                    $inHunk = true;
                    $id = ' id="cet-h' . $hunks . '"';
                    $marks[] = [100 * $index / $total, 0.0, $type];
                }
                $last = count($marks) - 1;
                $marks[$last][1] += 100 / $total;
                if ($marks[$last][2] !== $type) {
                    $marks[$last][2] = 'change';
                }
            }
            $l = $row['l'];
            $r = $row['r'];
            $leftText = $l === null ? null : self::visible((string)$left[$l]);
            $rightText = null;
            if ($r !== null) {
                $line = (string)$right[$r];
                if ($mask && $type !== 'same') {
                    $hits = SecretScreen::scanLine($line);
                    if ($hits !== []) {
                        $line = SecretScreen::mask($line, $hits);
                    }
                }
                $rightText = self::visible($line);
            }
            $parts = ($type === 'change') ? LineDiff::intraline((string)$leftText, (string)$rightText) : null;
            $leftHtml = $leftText === null ? '' : self::code($leftText, $parts[0] ?? null);
            $rightHtml = $rightText === null ? '' : self::code($rightText, $parts[1] ?? null);
            $focus = $type === 'same' ? '' : ' tabindex="0" data-hunk="' . $hunks . '"';
            $ln = $l === null ? '' : (string)($l + 1);
            $rn = $r === null ? '' : (string)($r + 1);

            if ($inline) {
                if ($type === 'same') {
                    $html .= '<div class="cet-r cet-i cet-same"><span class="cet-n">' . $ln . '</span><span class="cet-n">' . $rn
                        . '</span><span class="cet-g"></span><span class="cet-c">' . $leftHtml . '</span></div>';
                    continue;
                }
                if ($l !== null) {
                    $html .= '<div class="cet-r cet-i cet-del"' . $id . $focus . '><span class="cet-n cet-nl">' . $ln
                        . '</span><span class="cet-n"></span><span class="cet-g">' . self::SIGN['del'] . '</span><span class="cet-c cet-cl">' . $leftHtml . '</span></div>';
                    $id = '';
                }
                if ($r !== null) {
                    $html .= '<div class="cet-r cet-i cet-add"' . $id . $focus . '><span class="cet-n"></span><span class="cet-n cet-nr">' . $rn
                        . '</span><span class="cet-g">' . self::SIGN['add'] . '</span><span class="cet-c cet-cr">' . $rightHtml . '</span></div>';
                }
                continue;
            }
            $html .= '<div class="cet-r cet-' . $type . '"' . $id . $focus . '>'
                . '<span class="cet-n cet-nl">' . $ln . '</span>'
                . '<span class="cet-c cet-cl' . ($l === null ? ' cet-x' : '') . '">' . $leftHtml . '</span>'
                . '<span class="cet-g">' . self::SIGN[$type] . '</span>'
                . '<span class="cet-n cet-nr">' . $rn . '</span>'
                . '<span class="cet-c cet-cr' . ($r === null ? ' cet-x' : '') . '">' . $rightHtml . '</span>'
                . '</div>';
        }
        return ['html' => $html, 'hunks' => $hunks, 'marks' => $marks, 'truncated' => $truncated];
    }

    /**
     * The difference as a unified patch with three lines of context.
     */
    public static function patch(array $left, array $right, array $result, string $fromName, string $toName, bool $mask): string
    {
        $ops = [];
        $pendingAdds = [];
        foreach (LineDiff::rows($result, -1) as $row) {
            if ($row['type'] === 'same') {
                foreach ($pendingAdds as $add) {
                    $ops[] = $add;
                }
                $pendingAdds = [];
                $ops[] = [' ', rtrim((string)$left[$row['l']], "\r")];
                continue;
            }
            if ($row['l'] !== null) {
                $ops[] = ['-', rtrim((string)$left[$row['l']], "\r")];
            }
            if ($row['r'] !== null) {
                $line = (string)$right[$row['r']];
                if ($mask) {
                    $hits = SecretScreen::scanLine($line);
                    if ($hits !== []) {
                        $line = SecretScreen::mask($line, $hits);
                    }
                }
                $pendingAdds[] = ['+', rtrim($line, "\r")];
            }
        }
        foreach ($pendingAdds as $add) {
            $ops[] = $add;
        }
        $count = count($ops);
        $keep = array_fill(0, $count, false);
        foreach ($ops as $i => $op) {
            if ($op[0] !== ' ') {
                for ($c = max(0, $i - 3); $c <= min($count - 1, $i + 3); $c++) {
                    $keep[$c] = true;
                }
            }
        }
        $out = '--- ' . $fromName . "\n" . '+++ ' . $toName . "\n";
        $a = 1;
        $b = 1;
        $i = 0;
        while ($i < $count) {
            if (!$keep[$i]) {
                $a++;
                $b++;
                $i++;
                continue;
            }
            $startA = $a;
            $startB = $b;
            $body = '';
            $lenA = 0;
            $lenB = 0;
            while ($i < $count && $keep[$i]) {
                [$sign, $text] = $ops[$i];
                $body .= $sign . $text . "\n";
                if ($sign !== '+') {
                    $lenA++;
                    $a++;
                }
                if ($sign !== '-') {
                    $lenB++;
                    $b++;
                }
                $i++;
            }
            $out .= '@@ -' . ($lenA === 0 ? $startA - 1 : $startA) . ',' . $lenA . ' +' . ($lenB === 0 ? $startB - 1 : $startB) . ',' . $lenB . " @@\n" . $body;
        }
        return $out;
    }

    private static function code(string $text, ?array $parts): string
    {
        if ($parts === null) {
            return self::esc($text);
        }
        $html = '';
        foreach ($parts as [$part, $changed]) {
            $html .= $changed ? '<span class="cet-hl">' . self::esc($part) . '</span>' : self::esc($part);
        }
        return $html;
    }

    private static function visible(string $line): string
    {
        $line = rtrim($line, "\r");
        if (!mb_check_encoding($line, 'UTF-8')) {
            $line = mb_convert_encoding($line, 'UTF-8', 'ISO-8859-1');
        }
        return str_replace("\t", '    ', $line);
    }
}
