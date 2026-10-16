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
 * Crops a generated image to the wanted ratio, limits its width and encodes it.
 */
final class ImageProcessor
{
    /**
     * @return array{data: string, width: int, height: int, ext: string, mime: string}
     */
    public function process(string $binary, Ratio $ratio, int $maxWidth, string $format, int $quality): array
    {
        if (!\function_exists('imagecreatefromstring')) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_GD'), 'gd');
        }

        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_DECODE'), 'decode');
        }

        $srcW = imagesx($source);
        $srcH = imagesy($source);

        // Centre crop to the wanted ratio
        $cropW = $srcW;
        $cropH = $srcH;

        if ($srcW / $srcH > $ratio->value()) {
            $cropW = (int) round($srcH * $ratio->value());
        } else {
            $cropH = (int) round($srcW / $ratio->value());
        }

        $cropW = max(1, min($srcW, $cropW));
        $cropH = max(1, min($srcH, $cropH));
        $cropX = (int) floor(($srcW - $cropW) / 2);
        $cropY = (int) floor(($srcH - $cropH) / 2);

        // Never enlarge: the maximum width is a ceiling
        $outW = min($cropW, max(1, $maxWidth));
        $outH = max(1, (int) round($outW / $ratio->value()));

        $target = imagecreatetruecolor($outW, $outH);
        imagealphablending($target, false);
        imagesavealpha($target, true);
        imagefill($target, 0, 0, imagecolorallocatealpha($target, 255, 255, 255, 127));
        imagecopyresampled($target, $source, 0, 0, $cropX, $cropY, $outW, $outH, $cropW, $cropH);

        $encoded = $this->encode($target, $format, $quality);

        return $encoded + ['width' => $outW, 'height' => $outH];
    }

    /**
     * Several images laid out as one, for image services that take a single image only.
     *
     * Wide images are stacked, others stand side by side, with white gaps so they read as separate pictures.
     *
     * @param  string[]  $paths
     *
     * @return array{data: string, layout: string, count: int}|null  Null when fewer than two images could be read
     */
    public function sheet(array $paths, int $maxSide = 1600, int $quality = 84): ?array
    {
        $tiles = [];

        foreach ($paths as $path) {
            $info = is_file($path) ? @getimagesize($path) : false;

            if ($info === false || $info[0] * $info[1] > 24000000 || !self::fitsInMemory((int) $info[0], (int) $info[1])) {
                continue;
            }

            $image = @imagecreatefromstring((string) file_get_contents($path));

            if ($image !== false) {
                $tiles[] = ['image' => $image, 'w' => imagesx($image), 'h' => imagesy($image)];
            }
        }

        if (\count($tiles) < 2) {
            return null;
        }

        $gap     = 32;
        $ratios  = array_map(static fn ($tile) => $tile['w'] / $tile['h'], $tiles);
        $stacked = array_sum($ratios) / \count($ratios) >= 1.4;
        $boxes   = [];

        if ($stacked) {
            // Same width for all, one under the other
            $width  = 1280;
            $height = $gap;

            foreach ($tiles as $tile) {
                $h       = max(1, (int) round($width * $tile['h'] / $tile['w']));
                $boxes[] = [$gap, $height, $width, $h];
                $height += $h + $gap;
            }

            $width += 2 * $gap;
        } else {
            // Same height for all, one next to the other
            $height = 1024;
            $width  = $gap;

            foreach ($tiles as $tile) {
                $w       = max(1, (int) round($height * $tile['w'] / $tile['h']));
                $boxes[] = [$width, $gap, $w, $height];
                $width  += $w + $gap;
            }

            $height += 2 * $gap;
        }

        $scale = min(1, $maxSide / max($width, $height));
        $sheet = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
        imagefill($sheet, 0, 0, imagecolorallocate($sheet, 255, 255, 255));

        foreach ($tiles as $i => $tile) {
            [$x, $y, $w, $h] = $boxes[$i];
            imagecopyresampled(
                $sheet,
                $tile['image'],
                (int) round($x * $scale),
                (int) round($y * $scale),
                0,
                0,
                max(1, (int) round($w * $scale)),
                max(1, (int) round($h * $scale)),
                $tile['w'],
                $tile['h']
            );
        }

        ob_start();
        imagejpeg($sheet, null, $quality);

        return ['data' => (string) ob_get_clean(), 'layout' => $stacked ? 'stacked' : 'row', 'count' => \count($tiles)];
    }

    /**
     * Can an image of this size be decoded without running out of memory?
     *
     * A picture a few hundred kilobytes large on the wire can need hundreds of megabytes once unpacked.
     */
    public static function fitsInMemory(int $width, int $height): bool
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || (int) $limit <= 0) {
            return true;
        }

        $bytes = SkillManagerLimits::bytes($limit);

        // Five bytes a pixel for the bitmap, half as much again for the copy that is drawn from it
        $needed = (int) ($width * $height * 5 * 1.5) + 16 * 1048576;

        return $bytes <= 0 || memory_get_usage(true) + $needed < $bytes;
    }

    /**
     * A small copy of an image as a data URI, to show in a form without keeping a file for it.
     */
    public function preview(string $binary, int $maxWidth = 480): string
    {
        $size = @getimagesizefromstring($binary);

        if ($size === false) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_DECODE'), 'decode');
        }

        $small = $this->process($binary, new Ratio($size[0], $size[1]), $maxWidth, 'jpg', 80);

        return 'data:image/jpeg;base64,' . base64_encode($small['data']);
    }

    /**
     * Re-encode a stored image in another format, keeping its size.
     *
     * @return array{data: string, width: int, height: int, ext: string, mime: string}
     */
    public function convert(string $path, string $format, int $quality): array
    {
        $binary = is_file($path) ? file_get_contents($path) : false;

        if ($binary === false) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_DRAFT_MISSING'), 'draft');
        }

        $size = @getimagesizefromstring($binary);

        if ($size === false) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_DECODE'), 'decode');
        }

        $current = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $current = $current === 'jpeg' ? 'jpg' : $current;

        if ($current === $format) {
            return ['data' => $binary, 'width' => (int) $size[0], 'height' => (int) $size[1], 'ext' => $format, 'mime' => (string) $size['mime']];
        }

        return $this->process($binary, new Ratio($size[0], $size[1]), (int) $size[0], $format, $quality);
    }

    /**
     * @param  \GdImage|resource  $image
     *
     * @return array{data: string, ext: string, mime: string}
     */
    private function encode($image, string $format, int $quality): array
    {
        if ($format === 'webp' && !\function_exists('imagewebp')) {
            $format = 'jpg';
        }

        ob_start();

        try {
            switch ($format) {
                case 'png':
                    $ok = imagepng($image, null, 6);
                    break;

                case 'webp':
                    $ok = imagewebp($image, null, $quality);
                    break;

                default:
                    $format = 'jpg';
                    $flat   = imagecreatetruecolor(imagesx($image), imagesy($image));
                    imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
                    imagecopy($flat, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));
                    imageinterlace($flat, true);
                    $ok = imagejpeg($flat, null, $quality);
            }
        } finally {
            $data = (string) ob_get_clean();
        }

        if (!$ok || $data === '') {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_ENCODE'), 'encode');
        }

        return [
            'data' => $data,
            'ext'  => $format,
            'mime' => ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$format],
        ];
    }
}
