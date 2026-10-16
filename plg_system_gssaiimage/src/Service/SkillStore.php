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
 * Keeps the style skills administrators upload as zip packages.
 *
 * Only two kinds of content are taken from a package: text documents and images.
 * Text is kept inside a PHP file that refuses to be opened from the web. Images are
 * redrawn before they are stored. Everything else in a package is ignored.
 */
final class SkillStore
{
    public const FOLDER = 'media/gssaiimage-skills';

    private const MAX_ZIP_BYTES      = 60 * 1024 * 1024;
    private const MAX_DOCUMENTS      = 40;
    private const MAX_DOCUMENT_BYTES = 300 * 1024;
    private const MAX_IMAGES         = 24;
    private const MAX_IMAGE_BYTES    = 20 * 1024 * 1024;
    private const MAX_IMAGE_PIXELS   = 24000000;
    private const MAX_IMAGE_SIDE     = 1280;
    private const GUARD              = "<?php defined('_JEXEC') or die; ?>\n";

    public function __construct(private readonly string $root = JPATH_ROOT)
    {
    }

    /**
     * @return Skill[]  By name
     */
    public function all(): array
    {
        $skills = [];

        foreach (glob($this->base() . '/*/skill.php') ?: [] as $file) {
            $skill = $this->read(basename(\dirname($file)));

            if ($skill !== null) {
                $skills[] = $skill;
            }
        }

        usort($skills, static fn (Skill $a, Skill $b) => strnatcasecmp($a->name, $b->name));

        return $skills;
    }

    public function get(string $slug): ?Skill
    {
        return self::isSlug($slug) ? $this->read($slug) : null;
    }

    public function delete(string $slug): bool
    {
        if (!self::isSlug($slug) || !is_dir($this->base() . '/' . $slug)) {
            return false;
        }

        self::removeFolder($this->base() . '/' . $slug);

        return !is_dir($this->base() . '/' . $slug);
    }

    /**
     * Read a zip package and keep what a skill is made of. A skill of the same name is replaced.
     *
     * @param  string  $zipPath       The uploaded file
     * @param  string  $originalName  Its name on the administrator's computer
     */
    public function install(string $zipPath, string $originalName = ''): Skill
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_ZIP_EXT'), 'zip_ext');
        }

        if (!is_file($zipPath) || filesize($zipPath) > self::MAX_ZIP_BYTES) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_SIZE', (int) (self::MAX_ZIP_BYTES / 1048576)), 'size');
        }

        $zip = new \ZipArchive();

        if ($zip->open($zipPath) !== true) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_ZIP'), 'zip');
        }

        try {
            $entries = $this->entries($zip);
            $main    = $this->mainDocument($entries);

            if ($main === null) {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_NO_MAIN'), 'no_main');
            }

            // Everything is read relative to the folder SKILL.md is in
            $prefix    = \dirname($main) === '.' ? '' : \dirname($main) . '/';
            $documents = [];
            $pictures  = [];

            foreach ($entries as $path => $size) {
                if ($prefix !== '' && !str_starts_with($path, $prefix)) {
                    continue;
                }

                $relative = substr($path, \strlen($prefix));
                $ext      = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

                if (\in_array($ext, ['md', 'markdown', 'txt', 'yaml', 'yml'], true)) {
                    if ($size <= self::MAX_DOCUMENT_BYTES && \count($documents) < self::MAX_DOCUMENTS) {
                        $text = self::cleanText((string) $zip->getFromName($path));

                        if ($text !== '') {
                            $documents[$relative] = $text;
                        }
                    }
                } elseif (\in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                    if ($size <= self::MAX_IMAGE_BYTES && \count($pictures) < self::MAX_IMAGES) {
                        $pictures[$relative] = $path;
                    }
                }
            }

            $mainName = basename($main);
            [$meta, $body] = self::frontMatter($documents[$mainName] ?? '');

            if (trim($body) === '') {
                throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_NO_MAIN'), 'no_main');
            }

            $name = trim((string) ($meta['name'] ?? '')) ?: pathinfo($originalName, PATHINFO_FILENAME) ?: 'skill';
            $slug = self::slug($name);

            // The main document leads, then the documents it refers to, by path
            unset($documents[$mainName]);
            ksort($documents, SORT_NATURAL | SORT_FLAG_CASE);
            $documents = [$mainName => $body] + $documents;

            $this->write(
                $slug,
                $name,
                (string) ($meta['description'] ?? ''),
                $documents,
                basename($originalName),
                [],
                fn (string $assets): array => $this->storeImages($zip, $pictures, $assets)
            );
        } finally {
            $zip->close();
        }

        $skill = $this->read($slug);

        if ($skill === null) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', self::FOLDER), 'write');
        }

        return $skill;
    }

    /**
     * Keep a skill whose documents were fetched one by one, from the list of sources.
     *
     * @param  array<string, string>  $documents  Text by path, the main document first
     * @param  array<string, string>  $source     Where it came from
     */
    public function installDocuments(string $slug, string $name, string $description, array $documents, array $source): Skill
    {
        if (!self::isSlug($slug)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_MISSING'), 'slug');
        }

        $clean = [];

        foreach ($documents as $path => $text) {
            $text = self::cleanText((string) $text);

            if ($text !== '' && \strlen($text) <= self::MAX_DOCUMENT_BYTES && \count($clean) < self::MAX_DOCUMENTS) {
                $clean[(string) $path] = $text;
            }
        }

        $first = array_key_first($clean);

        if ($first === null) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_NO_MAIN'), 'no_main');
        }

        // The settings at the top of the main document are not guidance
        [, $body]      = self::frontMatter($clean[$first]);
        $clean[$first] = trim($body) !== '' ? $body : $clean[$first];

        $this->write($slug, $name, $description, $clean, '', $source, null);

        $skill = $this->read($slug);

        if ($skill === null) {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', self::FOLDER), 'write');
        }

        return $skill;
    }

    /**
     * Write a skill next to the others and swap it in as one step, so a failure never leaves half a skill behind.
     *
     * @param  array<string, string>             $documents
     * @param  array<string, string>             $source
     * @param  (callable(string): string[])|null $images     Stores the reference images in the folder it is given and returns their names
     */
    private function write(string $slug, string $name, string $description, array $documents, string $package, array $source, ?callable $images): void
    {
        $folder  = $this->base() . '/' . $slug;
        $staging = $this->base() . '/.incoming-' . bin2hex(random_bytes(6));

        DraftStore::ensureFolder($this->base());
        DraftStore::ensureFolder($staging);
        DraftStore::ensureFolder($staging . '/assets');

        try {
            $data = [
                'slug'        => $slug,
                'name'        => mb_substr($name, 0, 120),
                'description' => mb_substr(trim($description), 0, 1000),
                'documents'   => $documents,
                'images'      => $images !== null ? $images($staging . '/assets') : [],
                'uploaded'    => gmdate('Y-m-d\TH:i:s\Z'),
                'package'     => mb_substr($package, 0, 200),
                'source'      => array_map(static fn ($value) => mb_substr((string) $value, 0, 300), $source),
            ];
            // Angle brackets are written as escapes, so the stored text can never hold a PHP open tag
            $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_HEX_TAG);

            if ($json === false || file_put_contents($staging . '/skill.php', self::GUARD . $json) === false) {
                throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', self::FOLDER), 'write');
            }

            if (is_dir($folder)) {
                self::removeFolder($folder);
            }

            if (!@rename($staging, $folder)) {
                throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_WRITE', self::FOLDER), 'write');
            }
        } catch (\Throwable $e) {
            self::removeFolder($staging);

            throw $e;
        }
    }

    public static function isSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,79}$/', $slug);
    }

    public static function slug(string $name): string
    {
        $ascii = $name;

        if (\function_exists('transliterator_transliterate')) {
            $converted = @transliterator_transliterate('Any-Latin; Latin-ASCII', $name);
            $ascii     = \is_string($converted) && $converted !== '' ? $converted : $name;
        }

        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ascii)) ?? '', '-');
        $slug = trim(substr($slug, 0, 80), '-');

        return $slug !== '' ? $slug : 'skill-' . substr(md5($name), 0, 8);
    }

    /**
     * Paths and sizes of the files in a package that are safe to look at.
     *
     * @return array<string, int>
     */
    private function entries(\ZipArchive $zip): array
    {
        $entries = [];
        $total   = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);

            if ($stat === false) {
                continue;
            }

            $path = str_replace('\\', '/', (string) $stat['name']);
            $size = (int) $stat['size'];

            // Folders, hidden files, macOS resource forks, and anything that tries to leave the package
            if ($path === '' || str_ends_with($path, '/') || str_contains($path, "\0") || str_starts_with($path, '/')
                || preg_match('#(^|/)\.\.?(/|$)#', $path) || preg_match('#(^|/)(\.|__MACOSX/)#', $path)) {
                continue;
            }

            $total += $size;

            // A small file that unpacks to something huge is not a skill
            if ($total > self::MAX_ZIP_BYTES * 4) {
                throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_SIZE', (int) (self::MAX_ZIP_BYTES / 1048576)), 'size');
            }

            $entries[$path] = $size;
        }

        ksort($entries, SORT_NATURAL | SORT_FLAG_CASE);

        return $entries;
    }

    /**
     * The SKILL.md nearest to the top of the package.
     *
     * @param  array<string, int>  $entries
     */
    private function mainDocument(array $entries): ?string
    {
        $found = null;

        foreach (array_keys($entries) as $path) {
            if (strcasecmp(basename($path), 'SKILL.md') !== 0) {
                continue;
            }

            if ($found === null || substr_count($path, '/') < substr_count($found, '/')) {
                $found = $path;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, string>  $pictures  Path in the package by path relative to the skill
     *
     * @return string[]  File names as stored
     */
    private function storeImages(\ZipArchive $zip, array $pictures, string $folder): array
    {
        if (!$pictures || !\function_exists('imagecreatefromstring')) {
            return [];
        }

        $stored = [];

        foreach ($pictures as $relative => $path) {
            $binary = (string) $zip->getFromName($path);
            $info   = @getimagesizefromstring($binary);

            if ($info === false || $info[0] * $info[1] > self::MAX_IMAGE_PIXELS || !ImageProcessor::fitsInMemory((int) $info[0], (int) $info[1])) {
                continue;
            }

            $image = @imagecreatefromstring($binary);

            if ($image === false) {
                continue;
            }

            $scale = min(1, self::MAX_IMAGE_SIDE / max($info[0], $info[1]));
            $w     = max(1, (int) round($info[0] * $scale));
            $h     = max(1, (int) round($info[1] * $scale));
            $flat  = imagecreatetruecolor($w, $h);

            imagefill($flat, 0, 0, imagecolorallocate($flat, 255, 255, 255));
            imagecopyresampled($flat, $image, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);

            $base = trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', pathinfo($relative, PATHINFO_FILENAME)) ?? '', '-') ?: 'image';
            $name = $base . '.jpg';

            for ($n = 2; \in_array($name, $stored, true); $n++) {
                $name = $base . '-' . $n . '.jpg';
            }

            if (imagejpeg($flat, $folder . '/' . $name, 84)) {
                $stored[] = $name;
            }
        }

        return $stored;
    }

    private function read(string $slug): ?Skill
    {
        $folder = $this->base() . '/' . $slug;
        $file   = $folder . '/skill.php';

        if (!is_file($file)) {
            return null;
        }

        $raw  = (string) file_get_contents($file);
        $data = json_decode(substr($raw, (int) strpos($raw, "\n") + 1), true);

        if (!\is_array($data) || empty($data['documents']) || !\is_array($data['documents'])) {
            return null;
        }

        $images = array_values(array_filter(
            array_map('strval', (array) ($data['images'] ?? [])),
            static fn ($name) => preg_match('/^[A-Za-z0-9_-]+\.jpg$/', $name) && is_file($folder . '/assets/' . $name)
        ));

        return new Skill(
            $slug,
            (string) ($data['name'] ?? $slug),
            (string) ($data['description'] ?? ''),
            array_map('strval', $data['documents']),
            $images,
            $folder,
            (string) ($data['uploaded'] ?? ''),
            (string) ($data['package'] ?? ''),
            array_map('strval', array_filter((array) ($data['source'] ?? []), 'is_scalar'))
        );
    }

    /**
     * Split the block of settings at the top of a Markdown file from its text.
     *
     * @return array{0: array<string, string>, 1: string}
     */
    private static function frontMatter(string $text): array
    {
        if (!preg_match('/\A---\s*\R(.*?)\R---\s*(?:\R|\z)(.*)\z/s', $text, $m)) {
            return [[], $text];
        }

        $meta = [];

        foreach (preg_split('/\R/', $m[1]) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_-]*)\s*:\s*(.*)$/', $line, $pair)) {
                $meta[strtolower($pair[1])] = trim($pair[2], " \t\"'");
            }
        }

        return [$meta, ltrim($m[2])];
    }

    private static function cleanText(string $text): string
    {
        // Byte order mark, then anything that is not text
        $text = preg_replace('/\A\xEF\xBB\xBF/', '', $text) ?? $text;

        if (!mb_check_encoding($text, 'UTF-8') || str_contains($text, "\0")) {
            return '';
        }

        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    private static function removeFolder(string $folder): void
    {
        if (!is_dir($folder) || is_link($folder)) {
            return;
        }

        foreach (scandir($folder) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $folder . '/' . $item;

            if (is_dir($path) && !is_link($path)) {
                self::removeFolder($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($folder);
    }

    private function base(): string
    {
        return $this->root . '/' . self::FOLDER;
    }
}
