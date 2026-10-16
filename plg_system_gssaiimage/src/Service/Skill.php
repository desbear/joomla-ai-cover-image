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

/**
 * A visual style skill an administrator uploaded: its written guidance and its reference images.
 */
final class Skill
{
    /**
     * @param  array<string, string>  $documents  Text of each document by its path in the package, SKILL.md first
     * @param  string[]               $images     File names of the reference images
     * @param  array<string, string>  $source     For a skill installed from the list of sources: catalog id, repository, commit, licence, address
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $description,
        public readonly array $documents,
        public readonly array $images,
        public readonly string $folder,
        public readonly string $uploaded = '',
        public readonly string $package = '',
        public readonly array $source = []
    ) {
    }

    /**
     * The guidance as one text for a language model, the main document first.
     */
    public function guide(int $limit = 60000): string
    {
        $text = '';

        foreach ($this->documents as $path => $body) {
            $part = '## ' . $path . "\n\n" . trim($body) . "\n\n";

            if (mb_strlen($text) + mb_strlen($part) > $limit) {
                $room = $limit - mb_strlen($text);

                // Better part of a document than a hole where it should be
                if ($room > 500) {
                    $text .= rtrim(mb_substr($part, 0, $room)) . "…\n\n";
                }

                break;
            }

            $text .= $part;
        }

        return trim($text);
    }

    /**
     * The main document only, for prompts that have little room.
     */
    public function summary(int $limit = 4000): string
    {
        $body = trim((string) (array_values($this->documents)[0] ?? ''));

        return mb_strlen($body) > $limit ? rtrim(mb_substr($body, 0, $limit)) . '…' : $body;
    }

    /**
     * @return string[]  Full paths of the reference images
     */
    public function imagePaths(): array
    {
        return array_map(fn ($name) => $this->folder . '/assets/' . $name, $this->images);
    }

    /**
     * What the settings screen shows of a skill.
     *
     * @return array<string, mixed>
     */
    public function toPublic(string $baseUrl): array
    {
        return [
            'slug'        => $this->slug,
            'name'        => $this->name,
            'description' => $this->description,
            'documents'   => array_keys($this->documents),
            'characters'  => array_sum(array_map('mb_strlen', $this->documents)),
            'images'      => array_map(fn ($name) => $baseUrl . $this->slug . '/assets/' . rawurlencode($name), $this->images),
            'uploaded'    => $this->uploaded,
            'package'     => $this->package,
            'source'      => $this->source,
        ];
    }
}
