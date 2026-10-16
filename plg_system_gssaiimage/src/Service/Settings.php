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

use Joomla\Registry\Registry;

/**
 * Typed access to the plugin parameters.
 */
final class Settings
{
    public const PROVIDERS    = ['openai', 'gemini', 'openwebui'];
    public const LAYOUT_MODES = ['auto', 'light', 'collage', 'dark', 'geometric'];
    public const TEXT_MODES   = ['none', 'title'];
    public const FORMATS      = ['webp', 'jpg', 'png'];

    public function __construct(private readonly Registry $params)
    {
    }

    public function params(): Registry
    {
        return $this->params;
    }

    public function defaultProvider(): string
    {
        $provider = (string) $this->params->get('provider_default', 'openai');

        if (!\in_array($provider, self::PROVIDERS, true) || !$this->providerReady($provider)) {
            foreach (self::PROVIDERS as $candidate) {
                if ($this->providerReady($candidate)) {
                    return $candidate;
                }
            }
        }

        return \in_array($provider, self::PROVIDERS, true) ? $provider : 'openai';
    }

    public function providerReady(string $provider): bool
    {
        if ($provider === 'openwebui' && $this->openWebUiBaseUrl() === '') {
            return false;
        }

        return \in_array($provider, self::PROVIDERS, true) && $this->apiKey($provider) !== '';
    }

    /**
     * Address of the Open WebUI server, without a path. Empty when it is not set or not usable.
     */
    public function openWebUiBaseUrl(): string
    {
        $url = trim((string) $this->params->get('openwebui_base_url', ''));

        // People paste the address of the API as often as that of the server
        $url   = preg_replace('#/api(/v1)?/?$#i', '', rtrim($url, '/')) ?? '';
        $parts = parse_url($url);

        if (!$parts || !\in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true) || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        return rtrim($url, '/');
    }

    /**
     * Remove a generated image from the account on the Open WebUI server once it has been fetched?
     */
    public function openWebUiDeleteFiles(): bool
    {
        return (bool) $this->params->get('openwebui_delete_files', 1);
    }

    public function apiKey(string $provider): string
    {
        return trim((string) $this->params->get($provider . '_api_key', ''));
    }

    public function allowProviderSwitch(): bool
    {
        return (bool) $this->params->get('allow_provider_switch', 1);
    }

    public function allowSizeOverride(): bool
    {
        return (bool) $this->params->get('allow_size_override', 1);
    }

    public function openAiBaseUrl(): string
    {
        $url = trim((string) $this->params->get('openai_base_url', ''));

        return rtrim($url !== '' ? $url : 'https://api.openai.com/v1', '/');
    }

    public function imageModel(string $provider): string
    {
        // An Open WebUI server has an image model of its own set up. Empty leaves the choice to it.
        $default = ['gemini' => 'gemini-2.5-flash-image', 'openwebui' => ''][$provider] ?? 'gpt-image-1';
        $model   = trim((string) $this->params->get($provider . '_image_model', ''));

        return $model !== '' ? $model : $default;
    }

    public function textModel(string $provider): string
    {
        // Which language models an Open WebUI server offers is up to whoever runs it, so there is no default
        $default = ['gemini' => 'gemini-2.5-flash', 'openwebui' => ''][$provider] ?? 'gpt-4.1-mini';
        $model   = trim((string) $this->params->get($provider . '_text_model', ''));

        return $model !== '' ? $model : $default;
    }

    public function openAiQuality(): string
    {
        $quality = (string) $this->params->get('openai_quality', 'medium');

        return \in_array($quality, ['auto', 'low', 'medium', 'high'], true) ? $quality : 'medium';
    }

    public function geminiImageSize(): string
    {
        $size = (string) $this->params->get('gemini_image_size', '');

        return \in_array($size, ['1K', '2K', '4K'], true) ? $size : '';
    }

    public function timeout(): int
    {
        return max(30, min(300, (int) $this->params->get('timeout', 180)));
    }

    public function layoutMode(): string
    {
        $mode = (string) $this->params->get('layout_mode', 'auto');

        return \in_array($mode, self::LAYOUT_MODES, true) ? $mode : 'auto';
    }

    public function textMode(): string
    {
        $mode = (string) $this->params->get('text_mode', 'none');

        return \in_array($mode, self::TEXT_MODES, true) ? $mode : 'none';
    }

    public function customStylePrompt(): string
    {
        return trim((string) $this->params->get('style_prompt_custom', ''));
    }

    /**
     * Brand colours for the built-in style, as hex codes.
     *
     * @return string[]
     */
    public function styleColors(): array
    {
        preg_match_all('/#[0-9A-Fa-f]{6}\b|#[0-9A-Fa-f]{3}\b/', (string) $this->params->get('style_colors', ''), $m);

        return \array_slice(array_values(array_unique(array_map('strtoupper', $m[0]))), 0, 8);
    }

    public function customAvoid(): string
    {
        return trim((string) $this->params->get('avoid_custom', ''));
    }

    public function useReferenceImages(): bool
    {
        return (bool) $this->params->get('use_reference_images', 1);
    }

    public function maxReferenceImages(): int
    {
        return max(0, min(6, (int) $this->params->get('max_reference_images', 2)));
    }

    public function referenceFolder(): string
    {
        return trim(str_replace('\\', '/', (string) $this->params->get('reference_folder', '')), " /\t\n");
    }

    /**
     * May editors send reference images of their own with a request?
     */
    public function allowUserReferences(): bool
    {
        return (bool) $this->params->get('allow_user_references', 1);
    }

    /**
     * The uploaded skill that sets the style, or an empty string for the built-in style.
     */
    public function styleSkill(): string
    {
        $slug = trim((string) $this->params->get('style_skill', ''));

        return SkillStore::isSlug($slug) ? $slug : '';
    }

    /**
     * May editors choose among the installed styles for one image?
     */
    public function allowStyleSwitch(): bool
    {
        return (bool) $this->params->get('allow_style_switch', 1);
    }

    public function useConcept(): bool
    {
        return (bool) $this->params->get('use_concept', 1);
    }

    /**
     * Provider used to distil the article into a visual concept. "same" follows the image provider.
     */
    public function conceptProvider(string $imageProvider): string
    {
        $provider = (string) $this->params->get('concept_provider', 'same');

        if (\in_array($provider, self::PROVIDERS, true) && $this->providerReady($provider)) {
            return $provider;
        }

        return $imageProvider;
    }

    public function maxContentChars(): int
    {
        return max(300, min(20000, (int) $this->params->get('max_content_chars', 3000)));
    }

    public function defaultRatio(): string
    {
        $ratio = trim((string) $this->params->get('default_ratio', '16:9'));

        return Ratio::tryParse($ratio) ? $ratio : '16:9';
    }

    public function defaultMaxWidth(): int
    {
        return self::clampWidth((int) $this->params->get('default_max_width', 1200), 1200);
    }

    public static function clampWidth(int $width, int $fallback): int
    {
        if ($width <= 0) {
            $width = $fallback;
        }

        return max(200, min(4096, $width));
    }

    public function outputFormat(): string
    {
        $format = (string) $this->params->get('output_format', 'webp');

        return \in_array($format, self::FORMATS, true) ? $format : 'webp';
    }

    public function outputQuality(): int
    {
        return max(40, min(100, (int) $this->params->get('output_quality', 85)));
    }

    /**
     * Folder relative to the site root, always inside "images".
     */
    public function saveFolder(): string
    {
        $folder = trim(str_replace('\\', '/', (string) $this->params->get('save_folder', 'images/ai-generated')), " /\t\n");
        $folder = preg_replace('#/+#', '/', $folder);

        if ($folder === '' || str_contains($folder, '..') || !preg_match('#^[A-Za-z0-9_\-./]+$#', $folder)) {
            return 'images/ai-generated';
        }

        // Generated files are media. Nowhere else on the site may they be written.
        if ($folder !== 'images' && !str_starts_with($folder, 'images/')) {
            return 'images/ai-generated';
        }

        return $folder;
    }

    public function draftTtlHours(): int
    {
        return max(1, min(720, (int) $this->params->get('draft_ttl_hours', 24)));
    }

    public function rateLimitPerHour(): int
    {
        return max(0, (int) $this->params->get('rate_limit', 30));
    }

    /**
     * Names of additional form fields (product tags and the like) whose values feed the prompt.
     *
     * @return string[]
     */
    public function extraFieldNames(): array
    {
        return self::splitNames((string) $this->params->get('extra_fields', ''));
    }

    /**
     * @return string[]
     */
    public static function splitNames(string $text): array
    {
        $names = preg_split('/[\s,;，；、]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $names = array_filter($names, static fn ($name) => (bool) preg_match('/^[A-Za-z0-9_\-]+$/', $name));

        return array_values(array_unique($names));
    }

    /**
     * Settings per image field, in the order the administrator listed them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldRules(): array
    {
        $rules = [];

        foreach ($this->rows('field_rules') as $row) {
            $fields = self::splitNames((string) ($row['fields'] ?? ''));

            if ($fields) {
                $rules[] = ['fields' => $fields] + self::overrides($row);
            }
        }

        return $rules;
    }

    /**
     * Category rules in the order the administrator listed them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rules(): array
    {
        $rules = [];

        foreach ($this->rows('rules') as $row) {
            $catids = array_values(array_filter(array_map('intval', (array) ($row['catid'] ?? []))));

            if (!$catids || (isset($row['enabled']) && !(int) $row['enabled'])) {
                continue;
            }

            $rules[] = [
                'catids'           => $catids,
                'include_children' => (bool) ($row['include_children'] ?? 1),
                // Empty means the rule is for every image field
                'fields'           => self::splitNames((string) ($row['fields'] ?? '')),
            ] + self::overrides($row);
        }

        return $rules;
    }

    /**
     * Rows of a repeatable setting, as plain arrays.
     *
     * @return array<int, array<string, mixed>>
     */
    private function rows(string $name): array
    {
        $raw = $this->params->get($name);

        if (\is_string($raw)) {
            $raw = json_decode($raw, true);
        }

        $raw = json_decode(json_encode($raw ?? []), true) ?: [];

        return array_values(array_filter($raw, 'is_array'));
    }

    /**
     * The values a layer may set. An empty value means: leave what the layer below decided.
     *
     * @param  array<string, mixed>  $row
     *
     * @return array<string, mixed>
     */
    private static function overrides(array $row): array
    {
        $provider = (string) ($row['provider'] ?? '');
        $layout   = (string) ($row['layout_mode'] ?? '');
        $textMode = (string) ($row['text_mode'] ?? '');
        $ratio    = trim((string) ($row['ratio'] ?? ''));

        return [
            'prompt'      => trim((string) ($row['prompt'] ?? '')),
            'ratio'       => Ratio::tryParse($ratio) ? $ratio : '',
            'max_width'   => (int) ($row['max_width'] ?? 0) > 0 ? self::clampWidth((int) $row['max_width'], 1200) : 0,
            'provider'    => \in_array($provider, self::PROVIDERS, true) ? $provider : '',
            'layout_mode' => \in_array($layout, self::LAYOUT_MODES, true) ? $layout : '',
            'text_mode'   => \in_array($textMode, self::TEXT_MODES, true) ? $textMode : '',
        ];
    }
}
