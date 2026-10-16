<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Provider;

\defined('_JEXEC') or die;

final class GeneratedImage
{
    /**
     * @param  bool  $imagesUsed  False when the service could not take the reference images and drew from the prompt alone
     */
    public function __construct(
        public readonly string $data,
        public readonly string $mime,
        public readonly string $model,
        public readonly bool $imagesUsed = true
    ) {
    }
}
