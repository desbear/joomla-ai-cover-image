<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Helper;

\defined('_JEXEC') or die;

use Gss\Plugin\System\GssAiImage\Service\AiImageException;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Event\Result\ResultAwareInterface;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Log\Log;
use Joomla\CMS\Session\Session;
use Joomla\Event\Event;

/**
 * What every AJAX action of these plugins needs: a logged-in editor, a valid
 * token, and an answer in one shape whether things went well or not.
 */
final class AjaxRequest
{
    /**
     * Run an action and put its answer on the event.
     *
     * @param  callable(): array<string, mixed>  $action
     */
    public static function handle(CMSApplicationInterface $app, Event $event, callable $action): void
    {
        try {
            if ($app->getInput()->getMethod() !== 'POST' || !Session::checkToken('post')) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_TOKEN'), 'token');
            }

            if (!Bootstrap::canGenerate($app->getIdentity())) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_PERMISSION'), 'permission');
            }

            $answer = ['ok' => true] + $action();
        } catch (AiImageException $e) {
            $answer = ['ok' => false, 'error' => $e->getMessage(), 'reason' => $e->reason];

            if ($e->detail !== '') {
                self::log($e->reason . ': ' . $e->detail, Log::WARNING);

                // Whoever may change the plugin settings may read what the service said
                $user = $app->getIdentity();

                if ($user !== null && $user->authorise('core.edit', 'com_plugins')) {
                    $answer['error'] .= ' ' . Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_DETAIL', mb_substr($e->detail, 0, 500));
                }
            }
        } catch (\Throwable $e) {
            // Details belong in the log, not on the editor's screen
            self::log($e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), Log::ERROR);

            $answer = ['ok' => false, 'error' => Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_UNEXPECTED'), 'reason' => 'unexpected'];
        }

        if ($event instanceof ResultAwareInterface) {
            $event->addResult($answer);

            return;
        }

        $result   = (array) $event->getArgument('result', []);
        $result[] = $answer;
        $event->setArgument('result', $result);
    }

    /**
     * Write to the plugin's own log file in the site's log folder.
     */
    private static function log(string $message, int $priority): void
    {
        static $registered = false;

        try {
            if (!$registered) {
                Log::addLogger(['text_file' => 'plg_system_gssaiimage.php'], Log::ALL, ['plg_system_gssaiimage']);
                $registered = true;
            }

            Log::add(preg_replace('/\s+/', ' ', $message) ?? $message, $priority, 'plg_system_gssaiimage');
        } catch (\Throwable $e) {
            // A log that cannot be written must not break the answer
        }
    }
}
