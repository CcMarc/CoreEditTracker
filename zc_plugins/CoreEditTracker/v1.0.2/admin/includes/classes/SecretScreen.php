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
 * A screen for secrets in changed lines. It is a screen, not a guarantee.
 *
 * Rules:
 *  1. db_credential: a define of DB_SERVER_USERNAME, DB_SERVER_PASSWORD or DB_DATABASE with any value.
 *  2. name_value: a define, an assignment or an array key whose name holds password, passwd, pwd,
 *     secret, token, api key (apikey, api_key, api-key), private key or auth key, with a quoted
 *     value of 6 characters or more that has no spaces and holds a digit or a symbol, or a value of
 *     12 letters or more.
 *  3. private_key_block: a "BEGIN ... PRIVATE KEY" line.
 *  4. random_string: a run of 32 or more letters, digits and + = _ - that mixes upper case, lower
 *     case and digits and looks random (at least 4.3 bits of entropy per character). Subresource
 *     integrity hashes (sha256-, sha384-, sha512- values) are left out.
 *  5. hex_string: a run of 32 or more hexadecimal characters holding letters and digits, unless
 *     a word such as sha, md5, hash, checksum or commit stands just before it.
 * Whole lines are screened. A file whose changed lines are larger than the screen reads in one go
 * is flagged as it is, so nothing passes unread.
 */
class SecretScreen
{
    private const SECRET_WORD = '[A-Za-z0-9_\-]*(?:password|passwd|pwd|secret|token|api[_\- ]?key|private[_\- ]?key|auth[_\- ]?key)[A-Za-z0-9_\-]*';

    public static function rules(): array
    {
        $word = self::SECRET_WORD;
        return [
            'db_credential' => [
                "~define\(\s*['\"](?:DB_SERVER_USERNAME|DB_SERVER_PASSWORD|DB_DATABASE)['\"]\s*,\s*(['\"])(?<v>[^'\"]+)\\1~",
            ],
            'name_value' => [
                "~define\(\s*['\"]{$word}['\"]\s*,\s*(['\"])(?<v>[^'\"\s]{6,})\\1~i",
                "~\\$[A-Za-z0-9_]*(?:password|passwd|pwd|secret|token|api_?key|private_?key|auth_?key)[A-Za-z0-9_]*\s*(?:\[[^\]]*\])?\s*=\s*(['\"])(?<v>[^'\"\s]{6,})\\1~i",
                "~(['\"]){$word}\\1\s*(?:=>|:)\s*(['\"])(?<v>[^'\"\s]{6,})\\2~i",
            ],
            'private_key_block' => ['~-----BEGIN [A-Z ]*PRIVATE KEY-----~'],
            'random_string' => ['~(?<v>[A-Za-z0-9+=_\-]{32,})~'],
            'hex_string' => ['~(?<![A-Za-z0-9])(?<v>[0-9A-Fa-f]{32,})(?![A-Za-z0-9])~'],
        ];
    }

    public const MAX_SCREEN_BYTES = 8 * 1024 * 1024;

    /**
     * True when the lines hold something that looks like a secret, or are too large to screen.
     */
    public static function flags(array $lines): bool
    {
        $total = 0;
        foreach ($lines as $line) {
            $total += strlen((string)$line);
            if ($total > self::MAX_SCREEN_BYTES) {
                return true;
            }
        }
        foreach ($lines as $line) {
            if (self::scanLine((string)$line) !== []) {
                return true;
            }
        }
        return false;
    }

    /**
     * Finds matches in the given lines. Returns [lineIndex => [[offset, length, rule], ...]].
     */
    public static function scan(array $lines): array
    {
        $found = [];
        foreach ($lines as $idx => $line) {
            $hits = self::scanLine((string)$line);
            if ($hits !== []) {
                $found[$idx] = $hits;
            }
        }
        return $found;
    }

    public static function scanLine(string $line): array
    {
        $hits = [];
        // Integrity hashes are public, blank them out at the same length so offsets stay true.
        $plain = (string)preg_replace_callback('~sha(?:256|384|512)-[A-Za-z0-9+/=]+~i', static fn($m) => str_repeat(' ', strlen($m[0])), $line);
        foreach (self::rules() as $rule => $patterns) {
            foreach ($patterns as $pattern) {
                $subject = ($rule === 'random_string' || $rule === 'hex_string') ? $plain : $line;
                if (preg_match_all($pattern, $subject, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
                    continue;
                }
                foreach ($m as $set) {
                    if (isset($set['v'])) {
                        [$value, $offset] = $set['v'];
                    } else {
                        [$value, $offset] = $set[0];
                    }
                    if ($rule === 'random_string' && !self::looksRandom($value)) {
                        continue;
                    }
                    if ($rule === 'name_value' && preg_match('~[0-9\\W_]~', $value) !== 1 && strlen($value) < 12) {
                        continue;
                    }
                    if ($rule === 'hex_string' && !self::looksLikeHexKey($value, substr($subject, max(0, $offset - 24), min(24, $offset)))) {
                        continue;
                    }
                    $hits[] = [$offset, strlen($value), $rule];
                }
            }
        }
        return $hits;
    }

    public static function looksLikeHexKey(string $value, string $before): bool
    {
        if (preg_match('~[0-9]~', $value) !== 1 || preg_match('~[A-Fa-f]~', $value) !== 1) {
            return false;
        }
        return preg_match('~(sha-?(1|224|256|384|512)|md5|hash|checksum|commit|digest)\W{0,6}$~i', $before) !== 1;
    }

    public static function looksRandom(string $value): bool
    {
        if (preg_match('~[A-Z]~', $value) !== 1 || preg_match('~[a-z]~', $value) !== 1 || preg_match('~[0-9]~', $value) !== 1) {
            return false;
        }
        $len = strlen($value);
        $entropy = 0.0;
        foreach (count_chars($value, 1) as $count) {
            $p = $count / $len;
            $entropy -= $p * log($p, 2);
        }
        return $entropy >= 4.3;
    }

    /**
     * The line with every matched value replaced by asterisks.
     */
    public static function mask(string $line, array $hits): string
    {
        usort($hits, static fn($x, $y) => $x[0] <=> $y[0]);
        $spans = [];
        foreach ($hits as [$offset, $length]) {
            $end = $offset + $length;
            $last = count($spans) - 1;
            if ($last >= 0 && $offset <= $spans[$last][1]) {
                $spans[$last][1] = max($spans[$last][1], $end);
            } else {
                $spans[] = [$offset, $end];
            }
        }
        foreach (array_reverse($spans) as [$start, $end]) {
            $line = substr($line, 0, $start) . str_repeat('*', min(12, max(4, $end - $start))) . substr($line, $end);
        }
        return $line;
    }
}
