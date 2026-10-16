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
 * Sources of image skills that an administrator can install with one click.
 *
 * Each entry names the documents to fetch from a public repository on GitHub, at one
 * exact commit. That commit was read before it was listed here, and what is fetched
 * cannot change afterwards. Only text is taken: no scripts, no images, no configuration.
 *
 * These skills were written for assistants that run tools. Here they serve as knowledge
 * about styles and prompt writing for the language model that writes the image prompt.
 */
final class SkillCatalog
{
    private const HOST = 'https://raw.githubusercontent.com/';

    /**
     * Characters of guidance a skill may bring. More would not reach the language model anyway.
     */
    private const MAX_CHARACTERS = 60000;

    /**
     * @var array<string, array{name: string, author: string, repo: string, commit: string, path: string, files: string[], license: string, kind: string}>
     */
    private const ENTRIES = [
        'src-glebis-gpt-image-2' => [
            'name'    => 'GPT Image 2 風格預設',
            'author'  => 'glebis',
            'repo'    => 'glebis/claude-skills',
            'commit'  => '7524dff0c54bb85645b6bb2b0c6c148f4f7c3e29',
            'path'    => 'gpt-image-2',
            'files'   => ['SKILL.md', 'presets.yaml'],
            'license' => 'MIT',
            'kind'    => 'STYLES',
        ],
        'src-conardli-gpt-image-2' => [
            'name'    => 'GPT Image 2 模板庫',
            'author'  => 'ConardLi',
            'repo'    => 'ConardLi/garden-skills',
            'commit'  => 'aaf9a82f5efd73e87cc0998edc398e75bfc35901',
            'path'    => 'skills/gpt-image-2',
            'files'   => [
                'SKILL.md',
                'references/prompt-writing.md',
                'references/poster-and-campaigns/editorial-cover.md',
                'references/poster-and-campaigns/banner-hero.md',
                'references/poster-and-campaigns/campaign-kv.md',
                'references/poster-and-campaigns/brand-poster.md',
                'references/scenes-and-illustrations/minimalist-mood-scene.md',
                'references/scenes-and-illustrations/concept-scene.md',
                'references/typography-and-text-layout/title-safe-poster.md',
            ],
            'license' => 'MIT',
            'kind'    => 'TEMPLATES',
        ],
        'src-agricidaniel-banana' => [
            'name'    => 'Banana 視覺簡報與提示詞方法',
            'author'  => 'AgriciDaniel',
            'repo'    => 'AgriciDaniel/banana-claude',
            'commit'  => '6a2b1b51fdcc35932184f06e513646a6f6f4f7d8',
            'path'    => 'skills/banana',
            'files'   => ['SKILL.md', 'references/prompt-engineering.md'],
            'license' => 'MIT',
            'kind'    => 'METHOD',
        ],
        'src-charlie947-whiteboard' => [
            'name'    => '白板手繪資訊圖',
            'author'  => 'charlie947',
            'repo'    => 'charlie947/social-media-skills',
            'commit'  => '8cefb5b6d03757885faa6918bd8bfaef202a83db',
            'path'    => 'skills/gemini-infographic',
            'files'   => ['SKILL.md'],
            'license' => 'MIT',
            'kind'    => 'STYLE',
        ],
        'src-davendra-go-bananas' => [
            'name'    => 'Go Bananas 攝影與行銷提示詞',
            'author'  => 'davendra',
            'repo'    => 'davendra/dev-marketplace',
            'commit'  => '47449c2bd2aec5b1ba577089dba6d0980727f41e',
            'path'    => 'skills/go-bananas',
            'files'   => ['SKILL.md', 'prompt-patterns.md', 'use-cases.md'],
            'license' => 'MIT',
            'kind'    => 'METHOD',
        ],
    ];

    public const MORE = 'https://github.com/BehiSecc/awesome-claude-skills';

    /**
     * @return string[]
     */
    public static function ids(): array
    {
        return array_keys(self::ENTRIES);
    }

    public static function has(string $id): bool
    {
        return isset(self::ENTRIES[$id]);
    }

    /**
     * What the settings screen shows of the sources.
     *
     * @param  string[]  $installed  Slugs of the skills that are installed
     *
     * @return array<int, array<string, mixed>>
     */
    public static function toPublic(array $installed = []): array
    {
        $list = [];

        foreach (self::ENTRIES as $id => $entry) {
            $list[] = [
                'id'          => $id,
                'name'        => $entry['name'],
                'author'      => $entry['author'],
                'description' => Text::_('PLG_SYSTEM_GSSAIIMAGE_CATALOG_' . strtoupper(str_replace('-', '_', substr($id, 4)))),
                'kind'        => Text::_('PLG_SYSTEM_GSSAIIMAGE_CATALOG_KIND_' . $entry['kind']),
                'license'     => $entry['license'],
                'documents'   => \count($entry['files']),
                'url'         => 'https://github.com/' . $entry['repo'] . '/tree/' . $entry['commit'] . '/' . $entry['path'],
                'installed'   => \in_array($id, $installed, true),
            ];
        }

        return $list;
    }

    /**
     * Fetch the documents of a source and keep them as a skill.
     */
    public static function install(string $id, HttpClient $http, SkillStore $store): Skill
    {
        if (!self::has($id)) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_SKILL_MISSING'), 'catalog');
        }

        $entry     = self::ENTRIES[$id];
        $documents = [];
        $total     = 0;

        foreach ($entry['files'] as $file) {
            // Every part of the address comes from the list above. Nothing in it comes from the request.
            $url    = self::HOST . $entry['repo'] . '/' . $entry['commit'] . '/' . $entry['path'] . '/' . $file;
            $result = $http->get($url, ['Accept' => 'text/plain'], 30);
            $text   = $result['body'];

            if ($result['status'] !== 200 || $text === '' || \strlen($text) > 300 * 1024 || !mb_check_encoding($text, 'UTF-8')) {
                throw new AiImageException(
                    Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_CATALOG_FETCH', $entry['name']),
                    'catalog_fetch',
                    0,
                    'GitHub HTTP ' . $result['status'] . ' for ' . $entry['repo'] . '/' . $entry['path'] . '/' . $file
                );
            }

            $total += mb_strlen($text);

            if ($total > self::MAX_CHARACTERS * 2) {
                break;
            }

            $documents[$file] = $text;
        }

        // Name and description of the skill itself, from the top of its main document
        $description = '';

        if (preg_match('/\A---\s*\R(.*?)\R---/s', (string) reset($documents), $m) && preg_match('/^description:\s*[>|]?-?\s*(.+?)(?=\R[A-Za-z_-]+:|\z)/sm', $m[1], $d)) {
            $description = trim(preg_replace('/\s+/', ' ', trim($d[1], " \t\n\r\"'")) ?? '');
        }

        return $store->installDocuments(
            $id,
            $entry['name'],
            $description,
            $documents,
            [
                'catalog' => $id,
                'repo'    => $entry['repo'],
                'commit'  => $entry['commit'],
                'path'    => $entry['path'],
                'author'  => $entry['author'],
                'license' => $entry['license'],
                'url'     => 'https://github.com/' . $entry['repo'] . '/tree/' . $entry['commit'] . '/' . $entry['path'],
            ]
        );
    }
}
