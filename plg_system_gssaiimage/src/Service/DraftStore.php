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
use Joomla\CMS\Uri\Uri;

/**
 * Generated images wait here until the editor applies one to a field.
 * Applying one removes the editor's drafts. Drafts nobody applied expire.
 *
 * Each user has a folder of their own, and a draft is known by a random name,
 * so one editor cannot apply or overwrite another editor's drafts.
 */
final class DraftStore
{
    private const TOKEN = '/^[a-f0-9]{32}\.(webp|jpg|png)$/';

    public function __construct(
        private readonly Settings $settings,
        private readonly int $userId,
        private readonly string $root = JPATH_ROOT
    ) {
    }

    public function save(string $binary, string $ext): string
    {
        $token = bin2hex(random_bytes(16)) . '.' . $ext;
        $dir   = $this->dir();

        self::ensureFolder($dir);

        if (file_put_contents($dir . '/' . $token, $binary) === false) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', $this->relativeDir()), 'write');
        }

        @chmod($dir . '/' . $token, 0644);

        return $token;
    }

    public function isToken(string $token): bool
    {
        return (bool) preg_match(self::TOKEN, $token);
    }

    /**
     * Full path of a draft that belongs to the current user.
     */
    public function path(string $token): string
    {
        if (!$this->isToken($token)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_DRAFT_MISSING'), 'draft');
        }

        $path = $this->dir() . '/' . $token;

        if (!is_file($path)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_DRAFT_MISSING'), 'draft');
        }

        return $path;
    }

    /**
     * Path relative to the site root, for building a URL.
     */
    public function relativePath(string $token): string
    {
        return $this->relativeDir() . '/' . $token;
    }

    /**
     * Remove drafts nobody applied. Runs now and then, as part of a generation request.
     */
    public function cleanup(): int
    {
        $base    = $this->root . '/' . $this->settings->saveFolder() . '/_drafts';
        $expired = time() - $this->settings->draftTtlHours() * 3600;
        $removed = 0;

        // No GLOB_BRACE: it does not exist on every platform
        foreach (glob($base . '/u*/*.*') ?: [] as $file) {
            if (preg_match(self::TOKEN, basename($file)) && @filemtime($file) < $expired && @unlink($file)) {
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Remove the current user's drafts once one of them has been applied to a field.
     *
     * @param  string[]  $keep     Drafts still shown in another panel on the editor's page
     * @param  string    $applied  The draft that was applied. It goes whatever the keep list says,
     *                             so one draft can never be applied twice.
     *
     * @return int  Number of drafts removed
     */
    public function purge(array $keep = [], string $applied = ''): int
    {
        $keep    = array_flip(array_filter(array_map('strval', $keep), fn ($token) => $this->isToken($token) && $token !== $applied));
        $dir     = $this->dir();
        $removed = 0;
        $left    = 0;

        foreach (glob($dir . '/*.*') ?: [] as $file) {
            $name = basename($file);

            if (!preg_match(self::TOKEN, $name)) {
                continue;
            }

            if (isset($keep[$name]) || !@unlink($file)) {
                $left++;

                continue;
            }

            $removed++;
        }

        // Nothing of this user is waiting any more: the folder can go too
        if ($left === 0 && is_dir($dir) && array_map('basename', glob($dir . '/*') ?: []) === ['index.html']) {
            @unlink($dir . '/index.html');
            @rmdir($dir);
        }

        return $removed;
    }

    /**
     * Store the final image and return its path relative to the site root.
     *
     * @return string  For example images/ai-generated/2026/09/my-article-1a2b3c4d.webp
     */
    public function publish(string $binary, string $ext, string $title): string
    {
        $relative = $this->settings->saveFolder() . '/' . date('Y') . '/' . date('m');
        $dir      = $this->root . '/' . $relative;

        self::ensureFolder($dir);

        $name = self::fileName($title, $ext);

        if (file_put_contents($dir . '/' . $name, $binary) === false) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', $relative), 'write');
        }

        @chmod($dir . '/' . $name, 0644);

        return $relative . '/' . $name;
    }

    /**
     * The value Joomla's media field stores for an image.
     *
     * @return array{path: string, value: string, url: string, width: int, height: int}
     */
    public static function mediaValue(string $path, int $width, int $height): array
    {
        $parts   = explode('/', $path, 2);
        $adapter = 'local-' . $parts[0];

        return [
            'path'   => $path,
            'value'  => $path . '#joomlaImage://' . $adapter . '/' . ($parts[1] ?? '') . '?width=' . $width . '&height=' . $height,
            'url'    => Uri::root() . $path,
            'width'  => $width,
            'height' => $height,
        ];
    }

    /**
     * Remove an image this plugin stored a moment ago, when the editor applies another one in its place.
     *
     * Only files with the name pattern of publish(), inside the save folder, and younger than a day are touched.
     */
    public function discard(string $relativePath): bool
    {
        $pattern = '#^' . preg_quote($this->settings->saveFolder(), '#') . '/\d{4}/\d{2}/[a-z0-9-]+-[a-f0-9]{8}\.(webp|jpg|png)$#';

        if (!preg_match($pattern, $relativePath)) {
            return false;
        }

        $path = $this->root . '/' . $relativePath;

        return is_file($path) && @filemtime($path) > time() - 86400 && @unlink($path);
    }

    /**
     * A file name that is safe on any file system and hard to guess.
     */
    public static function fileName(string $title, string $ext): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', self::toAscii($title)) ?? '', '-'));
        $slug = substr($slug, 0, 60);
        $slug = trim($slug, '-');

        return ($slug !== '' ? $slug : 'ai-image') . '-' . bin2hex(random_bytes(4)) . '.' . $ext;
    }

    public static function ensureFolder(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', $dir), 'mkdir');
        }

        if (!is_file($dir . '/index.html')) {
            @file_put_contents($dir . '/index.html', '<!DOCTYPE html><title></title>');
        }
    }

    private static function toAscii(string $text): string
    {
        if (\function_exists('transliterator_transliterate')) {
            $ascii = @transliterator_transliterate('Any-Latin; Latin-ASCII', $text);

            if (\is_string($ascii) && $ascii !== '') {
                return $ascii;
            }
        }

        return $text;
    }

    private function dir(): string
    {
        return $this->root . '/' . $this->relativeDir();
    }

    private function relativeDir(): string
    {
        return $this->settings->saveFolder() . '/_drafts/u' . $this->userId;
    }
}
