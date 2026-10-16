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

use Joomla\CMS\Language\Text;

/**
 * Reference images an editor sends along with one generation request.
 *
 * They exist on the server only while that request runs. Each one is redrawn
 * as a plain JPEG first, so nothing but pixels is passed on to the image service.
 */
final class UploadedReferences
{
    public const MAX_IMAGES = 3;
    public const MAX_BYTES  = 6 * 1024 * 1024;
    public const MAX_PIXELS = 12000000;
    public const MAX_SIDE   = 1536;

    /** @var string[] */
    private array $paths = [];

    /** @var callable(string): bool */
    private $isUploaded;

    public function __construct(private readonly string $tmpPath, ?callable $isUploaded = null)
    {
        $this->isUploaded = $isUploaded ?? 'is_uploaded_file';
    }

    /**
     * @param  array<int|string, mixed>  $files  Uploaded files as PHP describes them, one entry per file
     *
     * @return string[]  Paths of the prepared images
     */
    public function collect(array $files): array
    {
        if (isset($files['tmp_name'])) {
            $files = [$files];
        }

        $files = array_values(array_filter($files, static fn ($file) => \is_array($file) && (int) ($file['error'] ?? 4) !== UPLOAD_ERR_NO_FILE));

        if (\count($files) > self::MAX_IMAGES) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_REF_COUNT', self::MAX_IMAGES), 'ref_count');
        }

        $this->sweep();

        foreach ($files as $file) {
            $this->paths[] = $this->prepare($file);
        }

        return $this->paths;
    }

    /**
     * Remove what collect() wrote. Safe to call more than once.
     */
    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }

        $this->paths = [];
    }

    /**
     * Remove files a request left behind when it was cut short.
     */
    private function sweep(): void
    {
        foreach (glob($this->folder() . '/gssai-ref-*.jpg') ?: [] as $file) {
            if (preg_match('/^gssai-ref-[a-f0-9]{24}\.jpg$/', basename($file)) && @filemtime($file) < time() - 3600) {
                @unlink($file);
            }
        }
    }

    private function folder(): string
    {
        $folder = rtrim($this->tmpPath, '/\\');

        return $folder !== '' && is_dir($folder) && is_writable($folder) ? $folder : sys_get_temp_dir();
    }

    /**
     * @param  array<string, mixed>  $file
     */
    private function prepare(array $file): string
    {
        $source = (string) ($file['tmp_name'] ?? '');
        $error  = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);

        if ($error === UPLOAD_ERR_INI_SIZE || $error === UPLOAD_ERR_FORM_SIZE || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REF_SIZE'), 'ref_size');
        }

        if ($error !== UPLOAD_ERR_OK || $source === '' || !($this->isUploaded)($source)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REF_INVALID'), 'ref_invalid');
        }

        $info = @getimagesize($source);

        if ($info === false || !\in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REF_INVALID'), 'ref_invalid');
        }

        // Refuse sizes that would exhaust memory before GD gets to shrink them.
        // The panel sends images of 1536 px at most, so nothing honest comes near this.
        if ($info[0] * $info[1] > self::MAX_PIXELS || !ImageProcessor::fitsInMemory((int) $info[0], (int) $info[1])) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REF_SIZE'), 'ref_size');
        }

        if (!\function_exists('imagecreatefromstring')) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_GD'), 'gd');
        }

        $image = @imagecreatefromstring((string) file_get_contents($source));

        if ($image === false) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REF_INVALID'), 'ref_invalid');
        }

        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(1, self::MAX_SIDE / max($width, $height));
        $outW   = max(1, (int) round($width * $scale));
        $outH   = max(1, (int) round($height * $scale));

        // Transparent areas become white, as on the page the image came from
        $flat = imagecreatetruecolor($outW, $outH);
        imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
        imagecopyresampled($flat, $image, 0, 0, 0, 0, $outW, $outH, $width, $height);

        $folder = $this->folder();
        $path   = $folder . '/gssai-ref-' . bin2hex(random_bytes(12)) . '.jpg';

        if (!imagejpeg($flat, $path, 90)) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', $folder), 'write');
        }

        return $path;
    }
}
