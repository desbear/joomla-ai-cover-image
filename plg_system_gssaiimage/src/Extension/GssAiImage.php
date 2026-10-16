<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Extension;

\defined('_JEXEC') or die;

use Gss\Plugin\System\GssAiImage\Helper\AjaxRequest;
use Gss\Plugin\System\GssAiImage\Helper\Bootstrap;
use Gss\Plugin\System\GssAiImage\Service\AiImageException;
use Gss\Plugin\System\GssAiImage\Service\DraftStore;
use Gss\Plugin\System\GssAiImage\Service\ImageProcessor;
use Gss\Plugin\System\GssAiImage\Service\Settings;
use Gss\Plugin\System\GssAiImage\Service\HttpClient;
use Gss\Plugin\System\GssAiImage\Service\SkillCatalog;
use Gss\Plugin\System\GssAiImage\Service\SkillManagerLimits;
use Gss\Plugin\System\GssAiImage\Service\SkillStore;
use Gss\Plugin\System\GssAiImage\Service\UploadedReferences;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Uri\Uri;
use Joomla\Event\Event;
use Joomla\Event\SubscriberInterface;

/**
 * AI cover images for Joomla's own image fields.
 *
 * Also holds the settings, the generator and the panel that the FLEXIcontent plugin shares.
 */
final class GssAiImage extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onBeforeRender'   => 'onBeforeRender',
            'onAjaxGssaiimage' => 'onAjax',
        ];
    }

    /**
     * Add the button to the article form.
     */
    public function onBeforeRender(Event $event): void
    {
        $app = $this->getApplication();

        if (!$app->isClient('administrator') && !$app->isClient('site')) {
            return;
        }

        if (!$this->params->get($app->isClient('site') ? 'native_site' : 'native_admin', 1)) {
            return;
        }

        $input = $app->getInput();

        if ($input->getCmd('option') !== 'com_content' || $input->getCmd('layout') !== 'edit') {
            return;
        }

        if (!\in_array($input->getCmd('view'), ['article', 'form'], true) || !Bootstrap::canGenerate($app->getIdentity())) {
            return;
        }

        Bootstrap::loadAssets($app, new Settings($this->params), ['plg_system_gssaiimage/native.js']);
    }

    /**
     * index.php?option=com_ajax&plugin=gssaiimage&group=system&format=json
     */
    public function onAjax(Event $event): void
    {
        $app      = $this->getApplication();
        $settings = new Settings($this->params);

        Bootstrap::loadLanguage($app);

        AjaxRequest::handle($app, $event, function () use ($app, $settings): array {
            $input  = $app->getInput();
            $userId = (int) $app->getIdentity()->id;

            switch ($input->getCmd('action')) {
                case 'plan':
                    return $this->plan($settings, $userId);

                case 'generate':
                    return $this->generate($settings, $userId);

                case 'apply':
                    return $this->apply($settings, $userId);

                case 'skill_list':
                case 'skill_upload':
                case 'skill_install':
                case 'skill_delete':
                    return $this->skills($input->getCmd('action'));
            }

            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REQUEST'), 'action');
        });
    }

    /**
     * Tell the panel which rule, ratio and width apply to the chosen category.
     *
     * @return array<string, mixed>
     */
    private function plan(Settings $settings, int $userId): array
    {
        $generator = Bootstrap::generator($settings, $userId);
        $plan      = $generator->plan($this->request());

        return [
            'rule'        => $generator::publicRule($plan['rule']),
            'provider'    => $plan['provider'],
            'ratio'       => $plan['ratio'],
            'max_width'   => $plan['max_width'],
            'layout_mode' => $plan['layout_mode'],
            'text_mode'   => $plan['text_mode'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(Settings $settings, int $userId): array
    {
        $app    = $this->getApplication();
        $ledger = Bootstrap::ledger($app, $userId);

        // The language model gets a short time, the image model the time set in the plugin.
        // PHP gets both and a little more, and no longer.
        $budget = $settings->timeout() + 75;

        // One image at a time for each editor. The mark outlives a request that was cut short by a moment only.
        $ledger->start($budget + 15);

        $uploads = new UploadedReferences((string) $app->get('tmp_path', ''));

        try {
            $ledger->count('generate', $settings->rateLimitPerHour());

            if (\function_exists('set_time_limit')) {
                @set_time_limit($budget);
            }

            $request = $this->request();

            // Reference images from the editor live only as long as this request
            if ($settings->allowUserReferences()) {
                $request['user_references'] = $uploads->collect((array) $app->getInput()->files->get('refs', [], 'raw'));
            }

            $result = Bootstrap::generator($settings, $userId)->generate($request);
        } finally {
            $uploads->cleanup();
            $ledger->finish();
        }

        (new DraftStore($settings, $userId))->cleanup();

        $result['url'] = Uri::root() . $result['path'] . '?v=' . substr($result['draft'], 0, 8);

        return $result;
    }

    /**
     * Turn a draft into a file in the media folder and hand back the value for the image field.
     *
     * @return array<string, mixed>
     */
    private function apply(Settings $settings, int $userId): array
    {
        $app    = $this->getApplication();
        $input  = $app->getInput();
        $ledger = Bootstrap::ledger($app, $userId);
        $drafts = new DraftStore($settings, $userId);
        $token  = $input->post->getString('draft', '');
        $source = $drafts->path($token);

        $ledger->count('apply', $settings->rateLimitPerHour());

        $image = (new ImageProcessor())->convert($source, $settings->outputFormat(), $settings->outputQuality());
        $path  = $drafts->publish($image['data'], $image['ext'], $input->post->getString('title', ''));

        Bootstrap::settle($drafts, $ledger, $token, $path, $input->post->getString('replace', ''), (array) $input->post->get('keep', [], 'array'));

        return DraftStore::mediaValue($path, $image['width'], $image['height']);
    }

    /**
     * Style skills, managed from the settings of this plugin.
     *
     * @return array<string, mixed>
     */
    private function skills(string $action): array
    {
        $app = $this->getApplication();

        // Whoever may change these settings may change the skills
        if (!$app->isClient('administrator') || !$app->getIdentity()->authorise('core.edit', 'com_plugins')) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_PERMISSION'), 'permission');
        }

        $store   = new SkillStore();
        $changed = '';

        if ($action === 'skill_upload') {
            $file  = (array) $app->getInput()->files->get('package', [], 'raw');
            $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            $name  = (string) ($file['name'] ?? '');

            if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE) {
                throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_SIZE', (int) (SkillManagerLimits::uploadLimit() / 1048576)), 'size');
            }

            if ($error !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? '')) || strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_ZIP'), 'zip');
            }

            if (\function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            $changed = $store->install((string) $file['tmp_name'], $name)->slug;
        } elseif ($action === 'skill_install') {
            if (\function_exists('set_time_limit')) {
                @set_time_limit(120);
            }

            // Only what is on the list of sources can be installed this way. The request names an entry, never an address.
            $changed = SkillCatalog::install($app->getInput()->post->getCmd('id', ''), new HttpClient(30), $store)->slug;
        } elseif ($action === 'skill_delete') {
            $changed = $app->getInput()->post->getString('slug', '');

            if (!$store->delete($changed)) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_MISSING'), 'missing');
            }
        }

        $skills = $store->all();

        return [
            'changed' => $changed,
            'skills'  => array_map(static fn ($skill) => $skill->toPublic(Uri::root() . SkillStore::FOLDER . '/'), $skills),
            'catalog' => SkillCatalog::toPublic(array_map(static fn ($skill) => $skill->slug, $skills)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function request(): array
    {
        $post  = $this->getApplication()->getInput()->post;
        $extra = json_decode($post->get('extra', '{}', 'raw'), true);

        return [
            'context'      => $post->getCmd('context', 'native'),
            'title'        => $post->getString('title', ''),
            'content'      => (string) $post->get('content', '', 'raw'),
            'catid'        => $post->getInt('catid', 0),
            'style'        => $post->getCmd('style', ''),
            'field'        => preg_replace('/[^A-Za-z0-9_\-]/', '', substr($post->getString('field', ''), 0, 100)),
            'tags'         => array_slice((array) $post->get('tags', [], 'array'), 0, 40),
            'extra'        => \is_array($extra) ? array_slice(array_map('strval', array_filter($extra, 'is_scalar')), 0, 20, true) : [],
            'provider'     => $post->getCmd('provider', ''),
            'ratio'        => $post->getString('ratio', ''),
            'max_width'    => $post->getInt('max_width', 0),
            'layout_mode'  => $post->getCmd('layout_mode', ''),
            'text_mode'    => $post->getCmd('text_mode', ''),
            'instruction'  => mb_substr($post->getString('instruction', ''), 0, 1000),
            'instructions' => array_slice((array) $post->get('instructions', [], 'array'), -10),
            'base'         => $post->getString('base', ''),
        ];
    }
}
