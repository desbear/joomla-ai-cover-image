<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimageflexi
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImageFlexi\Extension;

\defined('_JEXEC') or die;

use Gss\Plugin\System\GssAiImage\Helper\AjaxRequest;
use Gss\Plugin\System\GssAiImage\Helper\Bootstrap;
use Gss\Plugin\System\GssAiImage\Service\AiImageException;
use Gss\Plugin\System\GssAiImage\Service\DraftStore;
use Gss\Plugin\System\GssAiImage\Service\Settings;
use Gss\Plugin\System\GssAiImageFlexi\Service\FlexiFieldWriter;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Database\DatabaseInterface;
use Joomla\CMS\Factory;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * AI cover images for FLEXIcontent image fields.
 *
 * Settings, category rules and the generator live in the core plugin (System - GSS AI Cover Image).
 */
final class GssAiImageFlexi extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeRender'        => 'onBeforeRender',
            'onAjaxGssaiimageflexi' => 'onAjax',
        ];
    }

    /**
     * Add the button to the FLEXIcontent item form.
     */
    public function onBeforeRender(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') && !$app->isClient('site')) {
            return;
        }

        if (!$this->params->get($app->isClient('site') ? 'flexi_site' : 'flexi_admin', 1)) {
            return;
        }

        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_flexicontent' || $input->getCmd('view') !== 'item') {
            return;
        }

        // On the site the same view also shows items to visitors. The form is the one with layout or task set.
        if ($app->isClient('site')) {
            $task = strtolower($input->getCmd('task', ''));

            if ($input->getCmd('layout') !== 'form' && !\in_array(preg_replace('/^.*\./', '', $task), ['add', 'edit'], true)) {
                return;
            }
        }

        if (!class_exists(Bootstrap::class) || !Bootstrap::canGenerate($app->getIdentity())) {
            return;
        }

        $settings = Bootstrap::settings();

        if ($settings === null) {
            return;
        }

        $app->getLanguage()->load('plg_system_gssaiimageflexi', JPATH_PLUGINS . '/system/gssaiimageflexi');

        Bootstrap::loadAssets($app, $settings, ['plg_system_gssaiimageflexi/flexi.js'], [
            'flexi' => [
                'extraFields' => Settings::splitNames((string) $this->params->get('extra_fields', '')),
                'contentIds'  => Settings::splitNames((string) $this->params->get('content_ids', 'jform_text')),
            ],
        ]);
    }

    /**
     * index.php?option=com_ajax&plugin=gssaiimageflexi&group=system&format=json
     */
    public function onAjax(Event $event): void
    {
        $app = $this->getApplication();

        if (!class_exists(Bootstrap::class)) {
            return;
        }

        Bootstrap::loadLanguage($app);
        $app->getLanguage()->load('plg_system_gssaiimageflexi', JPATH_PLUGINS . '/system/gssaiimageflexi');

        AjaxRequest::handle($app, $event, function () use ($app): array {
            $settings = Bootstrap::settings();
            $post     = $app->getInput()->post;

            if ($settings === null) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_CORE'), 'core');
            }

            if ($app->getInput()->getCmd('action') !== 'apply') {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REQUEST'), 'action');
            }

            if (!ComponentHelper::isEnabled('com_flexicontent')) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_COMPONENT'), 'component');
            }

            $user   = $app->getIdentity();
            $drafts = new DraftStore($settings, (int) $user->id);
            $ledger = Bootstrap::ledger($app, (int) $user->id);
            $token  = $post->getString('draft', '');

            // Make sure the draft is this editor's before anything is counted or written
            $drafts->path($token);
            $ledger->count('apply', $settings->rateLimitPerHour());

            $format = (string) $this->params->get('output_format', 'jpg');
            $writer = new FlexiFieldWriter(
                Factory::getContainer()->get(DatabaseInterface::class),
                $settings,
                $drafts,
                $user,
                $format === 'core' ? $settings->outputFormat() : $format
            );

            $result = $writer->apply(
                $token,
                $post->getString('field', ''),
                $post->getString('item', ''),
                $post->getString('title', '')
            );

            Bootstrap::settle(
                $drafts,
                $ledger,
                $token,
                (string) ($result['path'] ?? ''),
                $post->getString('replace', ''),
                (array) $post->get('keep', [], 'array')
            );

            return $result;
        });
    }
}
