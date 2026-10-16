<?php

/**
 * @package     GSS AI Image
 * @subpackage  pkg_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

\defined('_JEXEC') or die;

use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Version;
use Joomla\Database\DatabaseInterface;

/**
 * Checks the host before installing, and switches the plugins on after a first install.
 */
class Pkg_GssaiimageInstallerScript
{
    private const MIN_PHP    = '8.1.0';
    private const MIN_JOOMLA = '4.2.0';

    public function preflight($type, $parent): bool
    {
        if ($type === 'uninstall') {
            return true;
        }

        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_GSSAIIMAGE_ERR_PHP', self::MIN_PHP, PHP_VERSION), 'error');

            return false;
        }

        if (version_compare((new Version())->getShortVersion(), self::MIN_JOOMLA, '<')) {
            Factory::getApplication()->enqueueMessage(Text::sprintf('PKG_GSSAIIMAGE_ERR_JOOMLA', self::MIN_JOOMLA), 'error');

            return false;
        }

        return true;
    }

    public function postflight($type, $parent): void
    {
        // Leave the choice alone on updates: an administrator may have switched a plugin off
        if ($type !== 'install') {
            return;
        }

        $db    = Factory::getContainer()->get(DatabaseInterface::class);
        $query = $db->getQuery(true)
            ->update($db->quoteName('#__extensions'))
            ->set($db->quoteName('enabled') . ' = 1')
            ->where($db->quoteName('type') . ' = ' . $db->quote('plugin'))
            ->where($db->quoteName('folder') . ' = ' . $db->quote('system'))
            ->whereIn($db->quoteName('element'), ['gssaiimage', 'gssaiimageflexi'], \Joomla\Database\ParameterType::STRING);

        $db->setQuery($query)->execute();
    }
}
