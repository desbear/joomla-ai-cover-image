<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimageflexi
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImageFlexi\Service;

\defined('_JEXEC') or die;

use Gss\Plugin\System\GssAiImage\Service\AiImageException;
use Gss\Plugin\System\GssAiImage\Service\DraftStore;
use Gss\Plugin\System\GssAiImage\Service\ImageProcessor;
use Gss\Plugin\System\GssAiImage\Service\Settings;
use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Text;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;
use Joomla\Registry\Registry;

/**
 * Puts a finished image where a FLEXIcontent image field expects it.
 *
 * The image field keeps its originals in one of three places, set per field:
 *   - DB mode (0): the FLEXIcontent file manager folder, with a row in #__flexicontent_files
 *   - folder mode (1): a folder of its own per item and field
 *   - media manager mode (-2): anywhere in Joomla's media folders
 * FLEXIcontent makes the thumbnails itself when the item is saved or shown.
 */
final class FlexiFieldWriter
{
    public function __construct(
        private readonly DatabaseInterface $db,
        private readonly Settings $settings,
        private readonly DraftStore $drafts,
        private readonly User $user,
        private readonly string $format = 'jpg',
        private readonly string $root = JPATH_ROOT
    ) {
    }

    /**
     * @param  string  $fieldRef  Field id or field name
     * @param  string  $itemRef   Item id, or the temporary id FLEXIcontent gives a new item
     *
     * @return array<string, mixed>
     */
    public function apply(string $draft, string $fieldRef, string $itemRef, string $title): array
    {
        $field  = $this->field($fieldRef);
        $params = new Registry($field->attribs);
        $mode   = (int) $params->get('image_source', 0);
        $mode   = $mode > 1 ? 1 : $mode;
        $source = $this->drafts->path($draft);
        $format = \in_array($this->format, Settings::FORMATS, true) ? $this->format : $this->settings->outputFormat();
        $image  = (new ImageProcessor())->convert($source, $format, $this->settings->outputQuality());

        if ($mode === -1) {
            // The field shows the article's intro or full image and has no value of its own
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_INTROFULL'), 'intro_full');
        }

        if ($mode === -2) {
            $path = $this->drafts->publish($image['data'], $image['ext'], $title);

            return ['mode' => 'media', 'field_id' => (int) $field->id] + DraftStore::mediaValue($path, $image['width'], $image['height']);
        }

        $name = DraftStore::fileName($title, $image['ext']);

        if ($mode === 1) {
            $folder = $this->itemFolder($params, (int) $field->id, $itemRef);
        } else {
            $folder = $this->fileManagerFolder($params);
        }

        DraftStore::ensureFolder($folder);

        if (file_put_contents($folder . '/' . $name, $image['data']) === false) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', str_replace($this->root . '/', '', $folder)), 'write');
        }

        @chmod($folder . '/' . $name, 0644);

        if ($mode === 0) {
            $this->register($name, $title, $image, (int) $params->get('target_dir', 1) ? 1 : 0);
        }

        return [
            'mode'     => $mode === 0 ? 'db' : 'folder',
            'field_id' => (int) $field->id,
            'filename' => $name,
            'value'    => $name,
            // Originals sit in folders closed to the web and the draft is about to be removed,
            // so the form gets a small copy to show
            'preview'  => (new ImageProcessor())->preview($image['data']),
            'path'     => '',
            'width'    => $image['width'],
            'height'   => $image['height'],
        ];
    }

    private function field(string $ref): object
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName(['id', 'name', 'label', 'field_type', 'attribs']))
            ->from($db->quoteName('#__flexicontent_fields'))
            ->where($db->quoteName('field_type') . ' = ' . $db->quote('image'));

        if (ctype_digit($ref)) {
            $id = (int) $ref;
            $query->where($db->quoteName('id') . ' = :id')->bind(':id', $id, ParameterType::INTEGER);
        } elseif (preg_match('/^[A-Za-z0-9_\-]{1,100}$/', $ref)) {
            $query->where($db->quoteName('name') . ' = :name')->bind(':name', $ref);
        } else {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_FIELD'), 'field');
        }

        $field = $db->setQuery($query)->loadObject();

        if (!$field) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_FIELD'), 'field');
        }

        return $field;
    }

    /**
     * Folder of the FLEXIcontent file manager that this field reads its originals from.
     */
    private function fileManagerFolder(Registry $params): string
    {
        $component = ComponentHelper::getParams('com_flexicontent');
        $folder    = (int) $params->get('target_dir', 1)
            ? $component->get('file_path', 'components/com_flexicontent/uploads')
            : $component->get('media_path', 'components/com_flexicontent/medias');

        return $this->root . '/' . $this->safeRelative((string) $folder);
    }

    /**
     * Folder mode keeps originals in {dir}/item_{item}_field_{field}/original.
     */
    private function itemFolder(Registry $params, int $fieldId, string $itemRef): string
    {
        if (ctype_digit($itemRef) && (int) $itemRef > 0) {
            $this->assertCanEdit((int) $itemRef);

            // The folder is named by the number, without leading zeros
            $itemRef = (string) (int) $itemRef;
        } elseif (!self::isTemporaryItemId($itemRef)) {
            // A new item has a temporary id until it is saved for the first time
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_ITEM'), 'item');
        }

        $dir = $this->safeRelative((string) $params->get('dir', 'images/stories/flexicontent'));

        return $this->root . '/' . $dir . '/item_' . $itemRef . '_field_' . $fieldId . '/original';
    }

    /**
     * FLEXIcontent names the upload folder of an unsaved item by the moment its form was opened:
     * date('_Y_m_d_h_i_s_') followed by uniqid(true). Only that shape is accepted, and only a recent one.
     */
    public static function isTemporaryItemId(string $id, ?int $now = null): bool
    {
        if (!preg_match('/^_(\d{4})_(\d{2})_(\d{2})_\d{2}_\d{2}_\d{2}_[0-9a-f]{10,24}$/', $id, $m)) {
            return false;
        }

        $day = gmmktime(12, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);

        // A form left open for days is rare. A folder name made up from nothing has no date at all.
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && abs(($now ?? time()) - $day) <= 3 * 86400;
    }

    private function assertCanEdit(int $itemId): void
    {
        $db    = $this->db;
        $query = $db->getQuery(true)
            ->select($db->quoteName('created_by'))
            ->from($db->quoteName('#__content'))
            ->where($db->quoteName('id') . ' = :id')
            ->bind(':id', $itemId, ParameterType::INTEGER);
        $owner = $db->setQuery($query)->loadResult();
        $asset = 'com_content.article.' . $itemId;

        if ($owner === null) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_ITEM'), 'item');
        }

        if ($this->user->authorise('core.edit', $asset)) {
            return;
        }

        if ((int) $owner === (int) $this->user->id && $this->user->authorise('core.edit.own', $asset)) {
            return;
        }

        throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_PERMISSION'), 'permission');
    }

    /**
     * Add the file to the FLEXIcontent file manager, as its own uploader does.
     *
     * @param  array{data: string, width: int, height: int, ext: string, mime: string}  $image
     */
    private function register(string $name, string $title, array $image, int $secure): void
    {
        $columns = array_change_key_case($this->db->getTableColumns('#__flexicontent_files'), CASE_LOWER);
        $date    = Factory::getDate('now', Factory::getApplication()->get('offset', 'UTC'));
        $row     = [
            'filename'          => $name,
            'filename_original' => $name,
            'altname'           => trim($title) !== '' ? mb_substr(trim($title), 0, 200) : $name,
            'description'       => '',
            'url'               => 0,
            'secure'            => $secure,
            'ext'               => $image['ext'],
            'language'          => '*',
            'hits'              => 0,
            'size'              => \strlen($image['data']),
            'uploaded'          => $date->toSql(),
            'uploaded_by'       => (int) $this->user->id,
            'access'            => 1,
        ];

        // Older FLEXIcontent versions have fewer columns
        $object = (object) array_intersect_key($row, $columns);

        $this->db->insertObject('#__flexicontent_files', $object);
    }

    private function safeRelative(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', $folder), " /\t\n");

        if ($folder === '' || str_contains($folder, '..') || !preg_match('#^[A-Za-z0-9_\-./ ]+$#', $folder)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGEFLEXI_ERR_FOLDER'), 'folder');
        }

        return $folder;
    }
}
