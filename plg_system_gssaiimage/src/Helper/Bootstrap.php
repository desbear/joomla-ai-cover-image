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

use Gss\Plugin\System\GssAiImage\Service\ConceptWriter;
use Gss\Plugin\System\GssAiImage\Service\DraftStore;
use Gss\Plugin\System\GssAiImage\Service\Generator;
use Gss\Plugin\System\GssAiImage\Service\ImageProcessor;
use Gss\Plugin\System\GssAiImage\Service\PromptBuilder;
use Gss\Plugin\System\GssAiImage\Service\RuleResolver;
use Gss\Plugin\System\GssAiImage\Service\Settings;
use Gss\Plugin\System\GssAiImage\Service\SkillStore;
use Gss\Plugin\System\GssAiImage\Service\UsageLedger;
use Gss\Plugin\System\GssAiImage\Service\UploadedReferences;
use Joomla\CMS\Application\CMSApplicationInterface;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Plugin\PluginHelper;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Registry\Registry;

/**
 * Shared entry points. The FLEXIcontent plugin uses these too, so both
 * plugins read the same settings and show the same panel.
 */
final class Bootstrap
{
    public const RATIOS = ['16:9', '3:2', '4:3', '1:1', '4:5', '3:4', '9:16', '21:9', '1200x630'];

    /**
     * Settings of the core plugin, or null when it is disabled.
     */
    public static function settings(): ?Settings
    {
        $plugin = PluginHelper::getPlugin('system', 'gssaiimage');

        if (!\is_object($plugin) || !isset($plugin->params)) {
            return null;
        }

        return new Settings(new Registry($plugin->params));
    }

    public static function loadLanguage(CMSApplicationInterface $app): void
    {
        $language = $app->getLanguage();

        $language->load('plg_system_gssaiimage', JPATH_PLUGINS . '/system/gssaiimage')
            || $language->load('plg_system_gssaiimage', JPATH_ADMINISTRATOR);
    }

    /**
     * Anyone who may write or edit articles may generate images for them.
     */
    public static function canGenerate(?User $user): bool
    {
        if ($user === null || $user->guest) {
            return false;
        }

        foreach (['core.create', 'core.edit', 'core.edit.own'] as $action) {
            if ($user->authorise($action, 'com_content')) {
                return true;
            }
        }

        return false;
    }

    public static function generator(Settings $settings, int $userId): Generator
    {
        return new Generator(
            $settings,
            new RuleResolver($settings, Factory::getContainer()->get(DatabaseInterface::class)),
            new DraftStore($settings, $userId),
            new PromptBuilder($settings),
            new ImageProcessor(),
            new ConceptWriter()
        );
    }

    public static function ledger(CMSApplicationInterface $app, int $userId): UsageLedger
    {
        return new UsageLedger(Factory::getContainer()->get(DatabaseInterface::class), $userId, $app->getSession());
    }

    /**
     * What follows every apply, whichever kind of field the image went to.
     *
     * @param  string    $token    The draft that was applied
     * @param  string    $path     The file it became, when that file is in the media folder
     * @param  string    $replace  The file the editor applied to this field before, in the same sitting
     * @param  string[]  $keep     Drafts still shown in another panel on the editor's page
     */
    public static function settle(DraftStore $drafts, UsageLedger $ledger, string $token, string $path, string $replace, array $keep): void
    {
        // The editor changed their mind: the image applied before is no longer used.
        // Only a file this editor applied may go. A path alone proves nothing.
        if ($replace !== '' && $replace !== $path && $ledger->owns($replace)) {
            $drafts->discard($replace);
            $ledger->forget($replace);
        }

        $ledger->remember($path);

        // The chosen image has its place now. Its draft goes in any case, the others unless still in use.
        $drafts->purge($keep, $token);
    }

    /**
     * Put the panel on the page. Each adapter script (native, flexi) registers itself with it.
     *
     * @param  string[]              $adapterScripts  Web asset paths such as "plg_system_gssaiimage/native.js"
     * @param  array<string, mixed>  $adapterOptions  Options for the adapters, by adapter name
     */
    public static function loadAssets(CMSApplicationInterface $app, Settings $settings, array $adapterScripts, array $adapterOptions = []): void
    {
        $document = $app->getDocument();

        if ($document->getType() !== 'html') {
            return;
        }

        self::loadLanguage($app);

        $wa = $document->getWebAssetManager();

        if (!$wa->assetExists('style', 'plg_system_gssaiimage.panel')) {
            $wa->registerStyle('plg_system_gssaiimage.panel', 'plg_system_gssaiimage/panel.css', ['version' => 'auto']);
            $wa->registerScript('plg_system_gssaiimage.panel', 'plg_system_gssaiimage/panel.js', ['version' => 'auto'], ['defer' => true], ['core']);
        }

        $wa->useStyle('plg_system_gssaiimage.panel')->useScript('plg_system_gssaiimage.panel');

        foreach ($adapterScripts as $script) {
            $name = 'gssaiimage.adapter.' . preg_replace('/[^a-z0-9]+/', '-', strtolower($script));

            if (!$wa->assetExists('script', $name)) {
                $wa->registerScript($name, $script, ['version' => 'auto'], ['defer' => true], ['plg_system_gssaiimage.panel']);
            }

            $wa->useScript($name);
        }

        $providers = [];

        foreach (Settings::PROVIDERS as $provider) {
            $providers[] = [
                'id'    => $provider,
                'label' => Text::_('PLG_SYSTEM_GSSAIIMAGE_PROVIDER_' . strtoupper($provider)),
                'model' => $settings->imageModel($provider),
                'ready' => $settings->providerReady($provider),
            ];
        }

        $existing = (array) $document->getScriptOptions('plg_system_gssaiimage');

        $document->addScriptOptions('plg_system_gssaiimage', [
            'ajaxUrl'             => Uri::base() . 'index.php?option=com_ajax&group=system&format=json',
            'rootUrl'             => Uri::root(),
            'tokenName'           => Session::getFormToken(),
            'providers'           => $providers,
            'defaultProvider'     => $settings->defaultProvider(),
            'allowProviderSwitch' => $settings->allowProviderSwitch(),
            'allowSizeOverride'   => $settings->allowSizeOverride(),
            'allowUserReferences' => $settings->allowUserReferences(),
            'styleName'           => self::styleName($settings),
            'styles'              => self::styles(),
            'defaultStyle'        => self::defaultStyle($settings),
            'allowStyleSwitch'    => $settings->allowStyleSwitch(),
            'maxUserReferences'   => UploadedReferences::MAX_IMAGES,
            'defaults'            => [
                'ratio'      => $settings->defaultRatio(),
                'maxWidth'   => $settings->defaultMaxWidth(),
                'layoutMode' => $settings->layoutMode(),
                'textMode'   => $settings->textMode(),
            ],
            'ratios'              => self::RATIOS,
            'extraFields'         => $settings->extraFieldNames(),
            'adapters'            => array_merge((array) ($existing['adapters'] ?? []), $adapterOptions),
            'strings'             => self::strings(),
        ]);
    }

    /**
     * The styles an editor can choose from: the built-in one, then the installed skills.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public static function styles(): array
    {
        $styles = [['id' => 'builtin', 'name' => Text::_('PLG_SYSTEM_GSSAIIMAGE_STYLE_BUILTIN')]];

        foreach ((new SkillStore())->all() as $skill) {
            $styles[] = ['id' => $skill->slug, 'name' => $skill->name];
        }

        return $styles;
    }

    public static function defaultStyle(Settings $settings): string
    {
        $slug = $settings->styleSkill();

        return $slug !== '' && (new SkillStore())->get($slug) !== null ? $slug : 'builtin';
    }

    /**
     * Name of the style in use, to show editors what they will get.
     */
    public static function styleName(Settings $settings): string
    {
        $slug  = $settings->styleSkill();
        $skill = $slug !== '' ? (new SkillStore())->get($slug) : null;

        return $skill !== null ? $skill->name : Text::_('PLG_SYSTEM_GSSAIIMAGE_STYLE_BUILTIN');
    }

    /**
     * @return array<string, string>
     */
    private static function strings(): array
    {
        $keys = [
            'BUTTON', 'BUTTON_TITLE', 'PANEL_TITLE', 'PANEL_SUBTITLE', 'CLOSE', 'RULE_FROM', 'RULE_PROMPT',
            'PROVIDER', 'RATIO', 'MAX_WIDTH', 'LAYOUT', 'LAYOUT_AUTO', 'LAYOUT_LIGHT', 'LAYOUT_COLLAGE', 'LAYOUT_DARK', 'LAYOUT_GEOMETRIC',
            'TEXT_MODE', 'TEXT_NONE', 'TEXT_TITLE', 'OPTIONS', 'REQUEST_LABEL', 'REQUEST_PLACEHOLDER', 'REVISE_LABEL', 'REVISE_PLACEHOLDER',
            'GENERATE', 'REGENERATE', 'MODE_REVISE', 'MODE_REVISE_HINT', 'MODE_FRESH', 'MODE_FRESH_HINT', 'APPLY', 'APPLIED', 'APPLYING',
            'WORKING', 'WORKING_CONCEPT', 'WORKING_DRAW', 'WORKING_SLOW', 'SECONDS', 'VERSIONS', 'VERSION', 'SHOW_PROMPT', 'HISTORY',
            'EMPTY_HINT', 'NO_PROVIDER', 'NEED_TITLE', 'NEED_REQUEST', 'ERROR_GENERIC', 'ERROR_TIMEOUT', 'ERROR_SESSION', 'SOURCE_SUMMARY',
            'SOURCE_TITLE', 'SOURCE_CONTENT', 'SOURCE_TAGS', 'SOURCE_NONE', 'CHARS', 'KEEP_OPEN', 'NOT_CONFIGURED',
            'REFS_LABEL', 'REFS_HINT', 'REFS_ADD', 'REFS_REMOVE', 'REFS_FULL', 'REFS_BAD', 'REFS_USED', 'REFS_ITEM',
            'STYLE', 'STYLE_BUILTIN', 'APPLIES', 'FIELD', 'FIELD_NONE', 'FIELD_HINT', 'FIELD_PROMPT', 'CATEGORY', 'CATEGORY_NONE', 'FROM_DEFAULT', 'FROM_FIELD', 'FROM_CATEGORY', 'FROM_YOU',
        ];

        $strings = [];

        foreach ($keys as $key) {
            $strings[$key] = Text::_('PLG_SYSTEM_GSSAIIMAGE_UI_' . $key);
        }

        return $strings;
    }
}
