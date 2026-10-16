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

use Gss\Plugin\System\GssAiImage\Provider\GeminiProvider;
use Gss\Plugin\System\GssAiImage\Provider\OpenAiProvider;
use Gss\Plugin\System\GssAiImage\Provider\OpenWebUiProvider;
use Gss\Plugin\System\GssAiImage\Provider\ProviderInterface;
use Joomla\CMS\Language\Text;

/**
 * Runs one generation request from article to stored draft.
 */
final class Generator
{
    /** @var callable(string): ProviderInterface */
    private $providerFactory;

    private readonly SkillStore $skills;

    private readonly SkillPromptWriter $skillWriter;

    public function __construct(
        private readonly Settings $settings,
        private readonly RuleResolver $rules,
        private readonly DraftStore $drafts,
        private readonly PromptBuilder $prompts,
        private readonly ImageProcessor $images,
        private readonly ConceptWriter $concepts,
        ?callable $providerFactory = null,
        private readonly string $root = JPATH_ROOT,
        ?SkillStore $skills = null,
        ?SkillPromptWriter $skillWriter = null
    ) {
        $this->providerFactory = $providerFactory ?? fn (string $name): ProviderInterface => self::provider($name, $this->settings);
        $this->skills          = $skills ?? new SkillStore($root);
        $this->skillWriter     = $skillWriter ?? new SkillPromptWriter();
    }

    /**
     * The uploaded skill that sets the style, or null for the built-in style.
     * A skill that was chosen and later deleted counts as not chosen.
     */
    public function skill(string $asked = ''): ?Skill
    {
        // What the editor chose for this image, where editors may choose
        if ($asked !== '' && $this->settings->allowStyleSwitch()) {
            if ($asked === 'builtin') {
                return null;
            }

            $chosen = $this->skills->get($asked);

            if ($chosen !== null) {
                return $chosen;
            }
        }

        $slug = $this->settings->styleSkill();

        return $slug !== '' ? $this->skills->get($slug) : null;
    }

    public static function provider(string $name, Settings $settings): ProviderInterface
    {
        $http = new HttpClient($settings->timeout());

        return match ($name) {
            'gemini'    => new GeminiProvider($settings, $http),
            'openwebui' => new OpenWebUiProvider($settings, $http),
            default     => new OpenAiProvider($settings, $http),
        };
    }

    /**
     * What will apply to an article in this category, before anything is generated.
     *
     * @param  array<string, mixed>  $request
     *
     * @return array<string, mixed>
     */
    public function plan(array $request): array
    {
        $rule     = $this->rules->resolve((int) ($request['catid'] ?? 0), (string) ($request['field'] ?? ''));
        $provider = $rule['provider'];
        $asked    = (string) ($request['provider'] ?? '');

        if ($asked !== '' && $this->settings->allowProviderSwitch() && $this->settings->providerReady($asked)) {
            $provider = $asked;
        }

        $ratio    = $rule['ratio'];
        $maxWidth = (int) $rule['max_width'];

        if ($this->settings->allowSizeOverride()) {
            $askedRatio = trim((string) ($request['ratio'] ?? ''));

            if ($askedRatio !== '' && Ratio::tryParse($askedRatio)) {
                $ratio = $askedRatio;
            }

            if ((int) ($request['max_width'] ?? 0) > 0) {
                $maxWidth = Settings::clampWidth((int) $request['max_width'], $maxWidth);
            }
        }

        $layout = (string) ($request['layout_mode'] ?? '');
        $text   = (string) ($request['text_mode'] ?? '');

        return [
            'rule'        => $rule,
            'provider'    => $provider,
            'ratio'       => $ratio,
            'max_width'   => $maxWidth,
            'layout_mode' => \in_array($layout, Settings::LAYOUT_MODES, true) ? $layout : $rule['layout_mode'],
            'text_mode'   => \in_array($text, Settings::TEXT_MODES, true) ? $text : $rule['text_mode'],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     *
     * @return array<string, mixed>
     */
    public function generate(array $request): array
    {
        $plan = $this->plan($request);

        if (!$this->settings->providerReady($plan['provider'])) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_KEY'), 'no_key');
        }

        $provider  = ($this->providerFactory)($plan['provider']);
        $ratio     = Ratio::parse($plan['ratio'], $this->settings->defaultRatio());
        $drawn     = $provider->nearestRatio($ratio);
        $rule      = $plan['rule'];
        $layout    = $plan['layout_mode'];
        $title     = trim((string) ($request['title'] ?? ''));
        $base      = trim((string) ($request['base'] ?? ''));
        $latest    = trim((string) ($request['instruction'] ?? ''));
        $requests  = array_values(array_filter(array_map('trim', array_map('strval', (array) ($request['instructions'] ?? [])))));
        $concept   = ['topic' => '', 'subject' => '', 'layout_mode' => ''];
        $skill     = $this->skill((string) ($request['style'] ?? ''));
        $skillLayout = '';
        $fromUser  = $this->settings->allowUserReferences()
            ? \array_slice(array_values(array_filter(array_map('strval', (array) ($request['user_references'] ?? [])), 'is_file')), 0, UploadedReferences::MAX_IMAGES)
            : [];
        $article   = [
            'title'    => $title,
            'content'  => (string) ($request['content'] ?? ''),
            'tags'     => (array) ($request['tags'] ?? []),
            'extra'    => (array) ($request['extra'] ?? []),
            'category' => $rule['category_title'],
        ];

        if ($title === '' && trim(strip_tags($article['content'])) === '') {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_CONTENT'), 'no_content');
        }

        if ($base !== '' && $latest !== '') {
            // Revise the image the editor is looking at
            $layout     = $layout === 'auto' && $skill === null ? 'light' : $layout;
            $prompt     = $this->prompts->buildRevision($latest, $layout, $plan['text_mode'], $ratio, $title, $rule['prompt'], $drawn, \count($fromUser), $skill);
            $image      = $provider->generate($prompt, $ratio, $fromUser, $this->drafts->path($base));
            $references = [];
            $revised    = true;
        } else {
            if ($latest !== '') {
                $requests[] = $latest;
            }

            if ($skill !== null) {
                [$prompt, $references, $skillLayout] = $this->bySkill($skill, $provider, $plan, $article, $ratio, $drawn, $requests, \count($fromUser));
            } else {
                if ($this->settings->useConcept()) {
                    $writer  = $this->settings->conceptProvider($plan['provider']);
                    $concept = $this->concepts->write(
                        $writer === $plan['provider'] ? $provider : ($this->providerFactory)($writer),
                        $this->prompts->articleBrief($article, $rule['prompt']),
                        $requests
                    );
                }

                if ($layout === 'auto') {
                    $layout = $concept['layout_mode'] !== '' ? $concept['layout_mode'] : 'light';
                }

                $references = $this->references($layout);
                $prompt     = $this->prompts->build(
                    $article,
                    $concept,
                    $layout,
                    $plan['text_mode'],
                    $ratio,
                    $rule['prompt'],
                    $requests,
                    $drawn,
                    (bool) $references,
                    \count($fromUser)
                );
            }

            // The editor's images go first, so the prompt can refer to them by position
            $image   = $provider->generate($prompt, $ratio, array_merge($fromUser, $references));
            $revised = false;
        }

        $final = $this->images->process(
            $image->data,
            $ratio,
            $plan['max_width'],
            $this->settings->outputFormat(),
            $this->settings->outputQuality()
        );
        $token = $this->drafts->save($final['data'], $final['ext']);

        // The service drew from the prompt alone, though images were sent along
        $ignored = !$image->imagesUsed && ($references || $fromUser);

        if ($ignored) {
            $references = [];
            $fromUser   = [];
        }

        return [
            'draft'       => $token,
            'path'        => $this->drafts->relativePath($token),
            'width'       => $final['width'],
            'height'      => $final['height'],
            'bytes'       => \strlen($final['data']),
            'provider'    => $plan['provider'],
            'model'       => $image->model,
            'ratio'       => $ratio->label(),
            'max_width'   => $plan['max_width'],
            'layout_mode' => $layout,
            'text_mode'   => $plan['text_mode'],
            'revised'     => $revised,
            'references'  => array_map('basename', $references),
            'editor_references' => \count($fromUser),
            'concept'     => $concept['subject'],
            'style'       => $skill !== null ? $skill->name : '',
            'style_id'    => $skill !== null ? $skill->slug : 'builtin',
            'style_layout' => $skillLayout,
            'notice'      => $ignored ? Text::_('PLG_SYSTEM_GSSAIIMAGE_NOTICE_IMAGES_IGNORED') : '',
            'prompt'      => $prompt,
            'rule'        => self::publicRule($rule),
        ];
    }

    /**
     * The part of the settings shown in the panel. Editors can read the extra prompts there but not change them.
     *
     * @param  array<string, mixed>  $rule
     *
     * @return array<string, mixed>
     */
    public static function publicRule(array $rule): array
    {
        return [
            'matched'         => (bool) $rule['matched'],
            'category_title'  => (string) $rule['category_title'],
            'rule_category'   => (string) $rule['rule_category'],
            'category_prompt' => (string) $rule['category_prompt'],
            'field_key'       => (string) $rule['field_key'],
            'field_matched'   => (bool) $rule['field_matched'],
            'field_prompt'    => (string) $rule['field_prompt'],
            'prompt'          => (string) $rule['prompt'],
            'sources'         => (array) $rule['sources'],
        ];
    }

    /**
     * Write the prompt and choose the reference images by an uploaded skill.
     *
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $article
     * @param  string[]              $requests
     *
     * @return array{0: string, 1: string[], 2: string}  The prompt, the paths of the reference images, the layout mode the skill chose
     */
    private function bySkill(Skill $skill, ProviderInterface $provider, array $plan, array $article, Ratio $ratio, Ratio $drawn, array $requests, int $editorImages): array
    {
        $max    = $this->settings->useReferenceImages() ? $this->settings->maxReferenceImages() : 0;
        $custom = $max > 0 ? $this->customReferences($max) : [];
        $names  = $max > 0 && !$custom ? $skill->images : [];
        $rule   = $plan['rule'];
        $answer = ['prompt' => '', 'references' => [], 'layout_mode' => ''];

        if ($this->settings->useConcept()) {
            $writer = $this->settings->conceptProvider($plan['provider']);
            $answer = $this->skillWriter->write(
                $writer === $plan['provider'] ? $provider : ($this->providerFactory)($writer),
                $skill,
                $this->prompts->skillBrief($article, $plan['layout_mode'], $plan['text_mode'], $ratio, $rule['prompt'], $requests, $names, $editorImages),
                $names,
                $max
            );
        }

        // Without a choice by the model, the first images of the skill set the style
        $chosen     = $answer['references'] ?: \array_slice($names, 0, $max);
        $references = $custom ?: array_map(static fn ($name) => $skill->folder . '/assets/' . $name, $chosen);
        $references = array_values(array_filter($references, 'is_file'));

        $prompt = $this->prompts->buildFromSkill(
            $skill,
            $article,
            $answer['prompt'],
            $plan['layout_mode'],
            $plan['text_mode'],
            $ratio,
            $rule['prompt'],
            $requests,
            $drawn,
            (bool) $references,
            $editorImages
        );

        return [$prompt, $references, $answer['layout_mode']];
    }

    /**
     * @return string[]  Images of the folder the administrator named for references, or none
     */
    private function customReferences(int $max): array
    {
        $custom = $this->settings->referenceFolder();

        if ($custom === '' || str_contains($custom, '..')) {
            return [];
        }

        $files = array_values(array_filter(
            glob($this->root . '/' . $custom . '/*.*') ?: [],
            static fn ($file) => \in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['jpg', 'jpeg', 'png', 'webp'], true)
        ));
        sort($files);

        return \array_slice($files, 0, $max);
    }

    /**
     * @return string[]  Paths of the style reference images: those in the folder the administrator named
     */
    private function references(string $mode): array
    {
        $max = $this->settings->maxReferenceImages();

        if (!$this->settings->useReferenceImages() || $max === 0) {
            return [];
        }

        return $this->customReferences($max);
    }
}
