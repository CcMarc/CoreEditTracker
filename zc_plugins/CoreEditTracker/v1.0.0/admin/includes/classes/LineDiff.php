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
 * Line diff (Myers, linear space) with a work limit, and a token diff inside one changed line.
 */
class LineDiff
{
    public const MAX_LINES = 60000;
    private const WORK_LIMIT = 30000000;

    private array $a = [];
    private array $b = [];
    private array $modA = [];
    private array $modB = [];
    private array $down = [];
    private array $up = [];
    private int $work = 0;
    public bool $approximate = false;

    public static function split(string $text): array
    {
        if ($text === '') {
            return [];
        }
        $lines = explode("\n", $text);
        if (end($lines) === '') {
            array_pop($lines);
        }
        return $lines;
    }

    public static function isBinary(string $data): bool
    {
        return strpos(substr($data, 0, 8192), "\0") !== false;
    }

    /**
     * Returns ['added' => int, 'removed' => int, 'modA' => bool[], 'modB' => bool[], 'approximate' => bool].
     * Options: 'eol' treats CRLF and LF lines as equal, 'ws' ignores differences in whitespace.
     */
    public static function compare(array $left, array $right, array $options = []): array
    {
        $d = new self();
        return $d->run($left, $right, !empty($options['eol']), !empty($options['ws']));
    }

    private static function key(string $line, bool $eol, bool $ws): string
    {
        if ($ws) {
            return (string)preg_replace('~\s+~', ' ', trim($line));
        }
        return $eol ? rtrim($line, "\r") : $line;
    }

    /**
     * The parts of two versions of one line, each as [text, differs]. Returns null when the lines
     * are too long or too different for marks to help.
     */
    public static function intraline(string $left, string $right): ?array
    {
        if ($left === $right || strlen($left) > 2000 || strlen($right) > 2000) {
            return null;
        }
        $a = self::tokens($left);
        $b = self::tokens($right);
        if ($a === [] || $b === []) {
            return null;
        }
        $result = self::compare($a, $b);
        $same = 0;
        $total = 0;
        foreach ([[$a, $result['modA']], [$b, $result['modB']]] as [$tokens, $flags]) {
            foreach ($tokens as $i => $token) {
                if (trim($token) === '') {
                    continue;
                }
                $total += strlen($token);
                if (!$flags[$i]) {
                    $same += strlen($token);
                }
            }
        }
        if ($total === 0 || $same * 3 < $total) {
            return null;
        }
        return [self::merge($a, $result['modA']), self::merge($b, $result['modB'])];
    }

    private static function tokens(string $line): array
    {
        $parts = preg_split('~(\s+|[^\w\s])~u', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        if ($parts === false) {
            $parts = preg_split('~(\s+|[^\w\s])~', $line, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        }
        return is_array($parts) ? $parts : [];
    }

    private static function merge(array $tokens, array $flags): array
    {
        $out = [];
        foreach ($tokens as $i => $token) {
            $changed = (bool)$flags[$i];
            $last = count($out) - 1;
            if ($last >= 0 && $out[$last][1] === $changed) {
                $out[$last][0] .= $token;
            } else {
                $out[] = [$token, $changed];
            }
        }
        return $out;
    }

    private function run(array $left, array $right, bool $eol = false, bool $ws = false): array
    {
        $map = [];
        $this->a = [];
        foreach ($left as $line) {
            $line = ($eol || $ws) ? self::key((string)$line, $eol, $ws) : $line;
            $this->a[] = $map[$line] ??= count($map);
        }
        $this->b = [];
        foreach ($right as $line) {
            $line = ($eol || $ws) ? self::key((string)$line, $eol, $ws) : $line;
            $this->b[] = $map[$line] ??= count($map);
        }
        $n = count($this->a);
        $m = count($this->b);
        $this->modA = array_fill(0, $n + 1, false);
        $this->modB = array_fill(0, $m + 1, false);
        if ($n + $m > 2 * self::MAX_LINES) {
            $this->markAll(0, $n, 0, $m);
        } else {
            $size = 2 * ($n + $m + 1) + 2;
            $this->down = array_fill(0, $size, 0);
            $this->up = array_fill(0, $size, 0);
            $this->lcs(0, $n, 0, $m);
        }
        array_pop($this->modA);
        array_pop($this->modB);
        return [
            'added' => count(array_filter($this->modB)),
            'removed' => count(array_filter($this->modA)),
            'modA' => $this->modA,
            'modB' => $this->modB,
            'approximate' => $this->approximate,
        ];
    }

    private function markAll(int $lowA, int $upA, int $lowB, int $upB): void
    {
        for ($i = $lowA; $i < $upA; $i++) {
            $this->modA[$i] = true;
        }
        for ($j = $lowB; $j < $upB; $j++) {
            $this->modB[$j] = true;
        }
    }

    private function lcs(int $lowA, int $upA, int $lowB, int $upB): void
    {
        while ($lowA < $upA && $lowB < $upB && $this->a[$lowA] === $this->b[$lowB]) {
            $lowA++;
            $lowB++;
        }
        while ($lowA < $upA && $lowB < $upB && $this->a[$upA - 1] === $this->b[$upB - 1]) {
            $upA--;
            $upB--;
        }
        if ($lowA === $upA || $lowB === $upB) {
            $this->markAll($lowA, $upA, $lowB, $upB);
            return;
        }
        $snake = $this->approximate ? null : $this->middleSnake($lowA, $upA, $lowB, $upB);
        if ($snake === null) {
            $this->approximate = true;
            $this->markAll($lowA, $upA, $lowB, $upB);
            return;
        }
        [$x, $y] = $snake;
        $this->lcs($lowA, $x, $lowB, $y);
        $this->lcs($x, $upA, $y, $upB);
    }

    private function middleSnake(int $lowA, int $upA, int $lowB, int $upB): ?array
    {
        $max = count($this->a) + count($this->b) + 1;
        $downK = $lowA - $lowB;
        $upK = $upA - $upB;
        $delta = ($upA - $lowA) - ($upB - $lowB);
        $odd = ($delta & 1) !== 0;
        $downOff = $max - $downK;
        $upOff = $max - $upK;
        $maxD = intdiv(($upA - $lowA) + ($upB - $lowB), 2) + 1;
        $this->down[$downOff + $downK + 1] = $lowA;
        $this->up[$upOff + $upK - 1] = $upA;

        for ($d = 0; $d <= $maxD; $d++) {
            for ($k = $downK - $d; $k <= $downK + $d; $k += 2) {
                if ($k === $downK - $d) {
                    $x = $this->down[$downOff + $k + 1];
                } else {
                    $x = $this->down[$downOff + $k - 1] + 1;
                    if ($k < $downK + $d && $this->down[$downOff + $k + 1] >= $x) {
                        $x = $this->down[$downOff + $k + 1];
                    }
                }
                $y = $x - $k;
                while ($x < $upA && $y < $upB && $this->a[$x] === $this->b[$y]) {
                    $x++;
                    $y++;
                }
                $this->down[$downOff + $k] = $x;
                if ($odd && $upK - $d < $k && $k < $upK + $d && $this->up[$upOff + $k] <= $this->down[$downOff + $k]) {
                    return [$this->down[$downOff + $k], $this->down[$downOff + $k] - $k];
                }
            }
            for ($k = $upK - $d; $k <= $upK + $d; $k += 2) {
                if ($k === $upK + $d) {
                    $x = $this->up[$upOff + $k - 1];
                } else {
                    $x = $this->up[$upOff + $k + 1] - 1;
                    if ($k > $upK - $d && $this->up[$upOff + $k - 1] < $x) {
                        $x = $this->up[$upOff + $k - 1];
                    }
                }
                $y = $x - $k;
                while ($x > $lowA && $y > $lowB && $this->a[$x - 1] === $this->b[$y - 1]) {
                    $x--;
                    $y--;
                }
                $this->up[$upOff + $k] = $x;
                if (!$odd && $downK - $d <= $k && $k <= $downK + $d && $this->up[$upOff + $k] <= $this->down[$downOff + $k]) {
                    return [$this->down[$downOff + $k], $this->down[$downOff + $k] - $k];
                }
            }
            $this->work += 2 * $d + 2;
            if ($this->work > self::WORK_LIMIT) {
                return null;
            }
        }
        return null;
    }

    /**
     * Side by side rows: ['type' => same|change|del|add|skip, 'l' => ?int, 'r' => ?int, 'count' => int].
     * Line numbers are 0 based. $context < 0 keeps every line.
     */
    public static function rows(array $result, int $context = 4): array
    {
        $modA = $result['modA'];
        $modB = $result['modB'];
        $n = count($modA);
        $m = count($modB);
        $all = [];
        $i = 0;
        $j = 0;
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && !$modA[$i] && !$modB[$j]) {
                $all[] = ['type' => 'same', 'l' => $i++, 'r' => $j++];
                continue;
            }
            $dels = [];
            $adds = [];
            while ($i < $n && $modA[$i]) {
                $dels[] = $i++;
            }
            while ($j < $m && $modB[$j]) {
                $adds[] = $j++;
            }
            if ($dels === [] && $adds === []) {
                while ($i < $n) {
                    $dels[] = $i++;
                }
                while ($j < $m) {
                    $adds[] = $j++;
                }
            }
            $len = max(count($dels), count($adds));
            for ($k = 0; $k < $len; $k++) {
                $l = $dels[$k] ?? null;
                $r = $adds[$k] ?? null;
                $type = ($l !== null && $r !== null) ? 'change' : ($l !== null ? 'del' : 'add');
                $all[] = ['type' => $type, 'l' => $l, 'r' => $r];
            }
        }
        if ($context < 0) {
            return $all;
        }
        $keep = array_fill(0, count($all), false);
        foreach ($all as $idx => $row) {
            if ($row['type'] !== 'same') {
                for ($c = max(0, $idx - $context); $c <= min(count($all) - 1, $idx + $context); $c++) {
                    $keep[$c] = true;
                }
            }
        }
        $out = [];
        $skipped = 0;
        foreach ($all as $idx => $row) {
            if ($keep[$idx]) {
                if ($skipped > 0) {
                    $out[] = ['type' => 'skip', 'l' => null, 'r' => null, 'count' => $skipped];
                    $skipped = 0;
                }
                $out[] = $row;
            } else {
                $skipped++;
            }
        }
        if ($skipped > 0) {
            $out[] = ['type' => 'skip', 'l' => null, 'r' => null, 'count' => $skipped];
        }
        return $out;
    }
}
