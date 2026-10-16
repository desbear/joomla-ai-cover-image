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
 * An error that is safe to show to the editor who pressed the button.
 *
 * The detail is what the other side really said. It goes to the log and to administrators,
 * because it can speak of the account, its quota, or its key.
 */
final class AiImageException extends \RuntimeException
{
    public function __construct(string $message, public readonly string $reason = '', int $code = 0, public readonly string $detail = '')
    {
        parent::__construct($message, $code);
    }
}
