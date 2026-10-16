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
 * How large an upload this host accepts.
 */
final class SkillManagerLimits
{
    /**
     * @return int  Bytes
     */
    public static function uploadLimit(): int
    {
        $limits = array_filter([
            self::bytes((string) ini_get('upload_max_filesize')),
            self::bytes((string) ini_get('post_max_size')),
        ]);

        return $limits ? (int) min($limits) : 2 * 1024 * 1024;
    }

    public static function bytes(string $value): int
    {
        $value = trim($value);

        if ($value === '' || !preg_match('/^(\d+(?:\.\d+)?)\s*([KMG]?)/i', $value, $m)) {
            return 0;
        }

        return (int) round((float) $m[1] * [1, 'K' => 1024, 'M' => 1048576, 'G' => 1073741824][strtoupper($m[2]) ?: 0]);
    }
}
