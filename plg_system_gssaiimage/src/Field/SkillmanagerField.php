<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Field;

\defined('_JEXEC') or die;

use Gss\Plugin\System\GssAiImage\Service\SkillCatalog;
use Gss\Plugin\System\GssAiImage\Service\SkillManagerLimits;
use Gss\Plugin\System\GssAiImage\Service\SkillStore;
use Joomla\CMS\Factory;
use Joomla\CMS\Form\FormField;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Session\Session;
use Joomla\CMS\Uri\Uri;

/**
 * Chooses the style: the built-in one, a skill from the list of sources, or a skill uploaded as a zip package.
 * Uploading and deleting happen right here, without saving the settings.
 */
class SkillmanagerField extends FormField
{
    protected $type = 'Skillmanager';

    protected function getInput()
    {
        $app   = Factory::getApplication();
        $store = new SkillStore();
        $root  = Uri::root();

        $wa = $app->getDocument()->getWebAssetManager();
        $wa->registerAndUseStyle('plg_system_gssaiimage.skills', 'plg_system_gssaiimage/skills.css', ['version' => 'auto']);
        $wa->registerAndUseScript('plg_system_gssaiimage.skills', 'plg_system_gssaiimage/skills.js', ['version' => 'auto'], ['defer' => true], ['core']);

        $strings = [];

        foreach ([
            'BUILTIN', 'BUILTIN_DESC', 'UPLOAD', 'UPLOAD_HINT', 'UPLOADING', 'DELETE', 'DELETE_CONFIRM', 'IN_USE', 'DOCUMENTS',
            'IMAGES', 'NO_IMAGES', 'UPLOADED', 'ADDED', 'REPLACED', 'DELETED', 'SAVE_REMINDER', 'NOT_ENABLED', 'ERROR', 'TOO_LARGE', 'NOT_ZIP', 'MISSING',
            'CHOOSE_TITLE', 'CATALOG_TITLE', 'CATALOG_HINT', 'CATALOG_MORE', 'INSTALL', 'REINSTALL', 'INSTALLING', 'INSTALLED', 'SOURCE', 'UPLOAD_TITLE',
        ] as $key) {
            $strings[$key] = Text::_('PLG_SYSTEM_GSSAIIMAGE_SKILL_' . $key);
        }

        $skills  = $store->all();
        $value   = SkillStore::isSlug((string) $this->value) ? (string) $this->value : '';
        $options = [
            'ajaxUrl'   => Uri::base() . 'index.php?option=com_ajax&group=system&plugin=gssaiimage&format=json',
            'tokenName' => Session::getFormToken(),
            'limit'     => min(SkillManagerLimits::uploadLimit(), 60 * 1024 * 1024),
            'builtin'   => [],
            'skills'    => array_map(static fn ($skill) => $skill->toPublic($root . SkillStore::FOLDER . '/'), $skills),
            'catalog'   => SkillCatalog::toPublic(array_map(static fn ($skill) => $skill->slug, $skills)),
            'more'      => SkillCatalog::MORE,
            'strings'   => $strings,
        ];

        return '<div class="gssai-skills" data-gssai-skills="' . htmlspecialchars((string) json_encode($options, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8') . '">'
            . '<input type="hidden" name="' . htmlspecialchars($this->name, ENT_QUOTES, 'UTF-8') . '" id="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8')
            . '" value="' . htmlspecialchars($value, ENT_QUOTES, 'UTF-8') . '">'
            . '<div class="gssai-skills-list" role="radiogroup" aria-labelledby="' . htmlspecialchars($this->id, ENT_QUOTES, 'UTF-8') . '-lbl"></div>'
            . '<div class="gssai-skills-upload"></div>'
            . '<div class="gssai-skills-message" role="status"></div>'
            . '<noscript>' . htmlspecialchars(Text::_('PLG_SYSTEM_GSSAIIMAGE_SKILL_NOSCRIPT'), ENT_QUOTES, 'UTF-8') . '</noscript>'
            . '</div>';
    }
}
