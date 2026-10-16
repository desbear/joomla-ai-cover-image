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

use Gss\Plugin\System\GssAiImage\Service\Ratio;

interface ProviderInterface
{
    public function name(): string;

    /**
     * The ratio this provider will actually draw when asked for the given one.
     */
    public function nearestRatio(Ratio $ratio): Ratio;

    /**
     * @param  string[]     $referenceImages  Paths of style reference images
     * @param  string|null  $baseImage        Path of the image to revise, when revising
     */
    public function generate(string $prompt, Ratio $ratio, array $referenceImages = [], ?string $baseImage = null): GeneratedImage;

    /**
     * Ask the provider's language model a question and get JSON text back.
     */
    public function text(string $system, string $user): string;
}
