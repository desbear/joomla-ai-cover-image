<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Service;

\defined('_JEXEC') or die;

/**
 * An aspect ratio written as "16:9", "1200x630", "1.91" or "1.91:1".
 */
final class Ratio
{
    public function __construct(public readonly float $width, public readonly float $height)
    {
    }

    public static function parse(string $text, string $fallback = '16:9'): self
    {
        $parsed = self::tryParse($text) ?? self::tryParse($fallback);

        return $parsed ?? new self(16, 9);
    }

    public static function tryParse(string $text): ?self
    {
        $text = trim(str_replace(['：', '×', 'X', '*', '/'], [':', 'x', 'x', 'x', ':'], $text));

        if ($text === '') {
            return null;
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*[:x]\s*(\d+(?:\.\d+)?)$/', $text, $m)) {
            $w = (float) $m[1];
            $h = (float) $m[2];
        } elseif (is_numeric($text)) {
            $w = (float) $text;
            $h = 1.0;
        } else {
            return null;
        }

        if ($w <= 0 || $h <= 0) {
            return null;
        }

        // Keep the ratio inside what image models and page layouts can sensibly deliver
        $value = $w / $h;

        if ($value > 4 || $value < 0.25) {
            return null;
        }

        return new self($w, $h);
    }

    public function value(): float
    {
        return $this->width / $this->height;
    }

    /**
     * A short human label, "16:9" rather than "1920:1080".
     */
    public function label(): string
    {
        $w = $this->width;
        $h = $this->height;

        if (floor($w) == $w && floor($h) == $h) {
            // Small numbers are already how people write a ratio: 21:9 stays 21:9, not 7:3
            if ($w > 32 || $h > 32) {
                $gcd = self::gcd((int) $w, (int) $h);
                $w /= $gcd;
                $h /= $gcd;
            }

            // 1200x630 reduces to 40:21, which nobody recognises. Show a decimal instead.
            if ($w > 32 || $h > 32) {
                return rtrim(rtrim(number_format($this->value(), 2, '.', ''), '0'), '.') . ':1';
            }

            return (int) $w . ':' . (int) $h;
        }

        return rtrim(rtrim(number_format($this->value(), 2, '.', ''), '0'), '.') . ':1';
    }

    public function orientation(): string
    {
        $value = $this->value();

        if ($value >= 1.2) {
            return 'landscape';
        }

        return $value <= 0.85 ? 'portrait' : 'square';
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return max(1, $a);
    }
}
