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
 * Writes the image prompt from the article, the category rule and the style.
 */
final class PromptBuilder
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * Article HTML to plain text, trimmed to the configured length.
     */
    public function excerpt(string $html, ?int $limit = null): string
    {
        $limit ??= $this->settings->maxContentChars();

        $text = preg_replace('#<(script|style|noscript|iframe|svg)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = preg_replace('#\{[a-z][^{}]{0,200}\}.*?\{/[a-z][^{}]{0,60}\}#is', ' ', $text) ?? $text;   // plugin shortcodes with a body
        $text = preg_replace('#\{/?[a-z][a-z0-9_-]{2,30}(\s[^{}]{0,200})?\}#i', ' ', $text) ?? $text;                      // and those without
        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr)\b[^>]*>#i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[ \t\x{00A0}\x{3000}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        $text = trim($text);

        if (mb_strlen($text) > $limit) {
            $text = rtrim(mb_substr($text, 0, $limit)) . '…';
        }

        return $text;
    }

    /**
     * The article as the language model and the image model will see it.
     *
     * @param  array{title?: string, content?: string, tags?: string[], extra?: array<string, string>, category?: string}  $article
     */
    public function articleBrief(array $article, string $rulePrompt = '', ?int $contentLimit = null): string
    {
        $lines = [];
        $title = trim((string) ($article['title'] ?? ''));

        if ($title !== '') {
            $lines[] = 'Title: ' . $title;
        }

        if (!empty($article['category'])) {
            $lines[] = 'Category: ' . $article['category'];
        }

        $tags = self::cleanList((array) ($article['tags'] ?? []));

        if ($tags) {
            $lines[] = 'Product tags: ' . implode(', ', $tags);
        }

        foreach ((array) ($article['extra'] ?? []) as $name => $value) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

            if ($value !== '') {
                $lines[] = ucfirst((string) $name) . ': ' . mb_substr($value, 0, 400);
            }
        }

        if ($rulePrompt !== '') {
            $lines[] = 'Art direction for this image:' . self::direction($rulePrompt);
        }

        $excerpt = $this->excerpt((string) ($article['content'] ?? ''), $contentLimit);

        if ($excerpt !== '') {
            $lines[] = "Article text:\n" . $excerpt;
        }

        return implode("\n", $lines);
    }

    /**
     * Prompt for a new image.
     *
     * @param  array{title?: string, content?: string, tags?: string[], extra?: array<string, string>, category?: string}  $article
     * @param  array{subject?: string, topic?: string}                                                                  $concept
     * @param  string[]                                                                                                  $instructions  Requests the editor has made so far, oldest first
     */
    public function build(
        array $article,
        array $concept,
        string $layoutMode,
        string $textMode,
        Ratio $ratio,
        string $rulePrompt = '',
        array $instructions = [],
        ?Ratio $generatedRatio = null,
        bool $hasReferences = false,
        int $editorImages = 0
    ): string {
        $mode  = \in_array($layoutMode, ['light', 'collage', 'dark', 'geometric'], true) ? $layoutMode : 'light';
        $title = trim((string) ($article['title'] ?? ''));
        $topic = trim((string) ($concept['topic'] ?? '')) ?: ($title !== '' ? '"' . $title . '"' : 'the article below');

        $lines   = [];
        $lines[] = 'Use case: ads-marketing';
        $lines[] = 'Asset type: ' . ($mode === 'dark' ? 'corporate news banner' : 'article cover image')
            . ', ' . $ratio->orientation() . ', final aspect ratio ' . $ratio->label();
        $lines[] = 'Primary request: ' . StyleProfile::primaryRequest($mode, $topic);

        if ($editorImages > 0) {
            $lines[] = 'Input images from the editor: '
                . ($hasReferences ? 'the first ' : 'the supplied ') . self::imagesCome($editorImages)
                . ' from the editor as reference for this article. ' . self::editorImageRule();
        }

        if ($hasReferences) {
            $lines[] = 'Input images: ' . ($editorImages > 0 ? 'the remaining images are' : 'the supplied images are')
                . ' style references only, not edit targets. '
                . 'Take material, texture, palette discipline and object construction from them. '
                . 'Do not copy their text, logos, subjects or exact layout. '
                . 'What the image shows comes from the subject below, never from a reference.';
        }

        $lines[] = 'Scene/backdrop: ' . StyleProfile::backdrop($mode);
        $lines[] = 'Subject: ' . $this->subject($article, $concept, $rulePrompt) . ' ' . StyleProfile::subjectConstruction($mode);
        $lines[] = 'Style/medium: ' . StyleProfile::medium($mode);

        if ($this->settings->customStylePrompt() !== '') {
            $lines[] = 'Additional brand direction: ' . $this->settings->customStylePrompt();
        }

        $lines[] = 'Composition/framing: ' . StyleProfile::composition($mode, $ratio->orientation())
            . $this->cropNote($ratio, $generatedRatio);
        $lines[] = 'Color palette: ' . StyleProfile::palette($this->settings->styleColors());

        if ($rulePrompt !== '') {
            $lines[] = 'Direction for this image (must follow):' . self::direction($rulePrompt);
        }

        $instructions = self::cleanList($instructions);

        if ($instructions) {
            $lines[] = "Editor requests (must follow, later requests override earlier ones):\n- " . implode("\n- ", $instructions);
        }

        $lines[] = StyleProfile::textRule($textMode, $mode, $title);
        $lines[] = 'Constraints: ' . StyleProfile::constraints();
        $lines[] = 'Avoid: ' . StyleProfile::avoid($mode)
            . ($this->settings->customAvoid() !== '' ? ' Also avoid: ' . $this->settings->customAvoid() : '');

        return implode("\n", $lines);
    }

    /**
     * What a language model needs to know to write the prompt by an uploaded skill.
     *
     * @param  array{title?: string, content?: string, tags?: string[], extra?: array<string, string>, category?: string}  $article
     * @param  string[]                                                                                                  $instructions
     * @param  string[]                                                                                                  $imageNames    Style reference images of the skill
     */
    public function skillBrief(
        array $article,
        string $layoutMode,
        string $textMode,
        Ratio $ratio,
        string $rulePrompt,
        array $instructions,
        array $imageNames,
        int $editorImages
    ): string {
        $title   = trim((string) ($article['title'] ?? ''));
        $lines   = [];
        $lines[] = 'Write the prompt for this image.';
        $lines[] = '';
        $lines[] = 'Asset: article cover image, ' . $ratio->orientation() . ', final aspect ratio ' . $ratio->label() . '.';
        $lines[] = $textMode === 'title' && $title !== ''
            ? 'Text in the image: the headline only, verbatim: "' . str_replace('"', "'", $title) . '".'
            : 'Text in the image: none at all. Use the text-free variant of the skill if it has one.';

        if (StyleProfile::modeName($layoutMode) !== '') {
            $lines[] = 'Layout mode asked for: ' . StyleProfile::modeName($layoutMode) . ', or the nearest the skill has.';
        } else {
            $lines[] = 'Layout mode: choose the one that suits the article.';
        }

        if ($rulePrompt !== '') {
            $lines[] = 'Direction for this image (must follow):' . self::direction($rulePrompt);
        }

        $instructions = self::cleanList($instructions);

        if ($instructions) {
            $lines[] = "Editor requests (must follow, later requests override earlier ones):\n- " . implode("\n- ", $instructions);
        }

        if ($editorImages > 0) {
            $lines[] = 'The editor attaches ' . ($editorImages === 1 ? 'one image' : $editorImages . ' images')
                . ' of their own as reference for what to show. You cannot see them. Say in the prompt that the image model takes the subject from them and redraws it in the style.';
        }

        $lines[] = $imageNames
            ? 'Style reference images to choose from: ' . implode(', ', $imageNames)
            : 'Style reference images to choose from: none.';

        if ($this->settings->customStylePrompt() !== '') {
            $lines[] = 'Additional brand direction: ' . $this->settings->customStylePrompt();
        }

        if ($this->settings->customAvoid() !== '') {
            $lines[] = 'Also avoid: ' . $this->settings->customAvoid();
        }

        $lines[] = '';
        $lines[] = "Article:\n" . $this->articleBrief($article);

        return implode("\n", $lines);
    }

    /**
     * Prompt for a new image in the style of an uploaded skill.
     *
     * The rules that must hold whatever the language model wrote are added after its prompt.
     *
     * @param  array{title?: string, content?: string, tags?: string[], extra?: array<string, string>, category?: string}  $article
     * @param  string                                                                                                    $written       The prompt a language model wrote by the skill, or empty
     * @param  string[]                                                                                                  $instructions
     */
    public function buildFromSkill(
        Skill $skill,
        array $article,
        string $written,
        string $layoutMode,
        string $textMode,
        Ratio $ratio,
        string $rulePrompt = '',
        array $instructions = [],
        ?Ratio $generatedRatio = null,
        bool $hasReferences = false,
        int $editorImages = 0
    ): string {
        $title = trim((string) ($article['title'] ?? ''));
        $lines = [];

        if ($written !== '') {
            $lines[] = $written;
            $lines[] = '';
            $lines[] = 'Requirements that hold whatever is said above:';
        } else {
            // No language model to write by the skill: the image model gets the skill itself
            $lines[] = 'Asset type: article cover image, ' . $ratio->orientation() . ', final aspect ratio ' . $ratio->label();
            $lines[] = 'Primary request: create the cover image for the article below, in the visual style this guide describes.';
            $lines[] = "Style guide \"" . $skill->name . "\":\n<<<\n" . $skill->guide(12000) . "\n>>>";

            if (StyleProfile::modeName($layoutMode) !== '') {
                $lines[] = 'Layout mode: ' . StyleProfile::modeName($layoutMode) . ', or the nearest the style guide has.';
            }

            $lines[] = 'Subject: find one clear visual idea for this article and show only that. '
                . 'The article is background for you; none of its words may appear in the image. '
                . "Article brief:\n<<<\n" . $this->articleBrief($article, '', 700) . "\n>>>";
        }

        if ($editorImages > 0) {
            $lines[] = 'Input images from the editor: '
                . ($hasReferences ? 'the first ' : 'the supplied ') . self::imagesCome($editorImages)
                . ' from the editor as reference for this article. ' . self::editorImageRule('the style of the guide');
        }

        if ($hasReferences) {
            $lines[] = 'Input images: ' . ($editorImages > 0 ? 'the remaining images are' : 'the supplied images are')
                . ' style references only, not edit targets. '
                . 'Take material, texture, palette discipline and object construction from them. '
                . 'Do not copy their text, logos, subjects or exact layout. '
                . 'What the image shows comes from the article, never from a reference.';
        }

        $lines[] = 'Composition/framing: ' . $ratio->orientation() . ', final aspect ratio ' . $ratio->label() . '.'
            . $this->cropNote($ratio, $generatedRatio);

        if ($this->settings->customStylePrompt() !== '') {
            $lines[] = 'Additional brand direction: ' . $this->settings->customStylePrompt();
        }

        if ($rulePrompt !== '') {
            $lines[] = 'Direction for this image (must follow):' . self::direction($rulePrompt);
        }

        $instructions = self::cleanList($instructions);

        if ($instructions) {
            $lines[] = "Editor requests (must follow, later requests override earlier ones):\n- " . implode("\n- ", $instructions);
        }

        $lines[] = StyleProfile::textRule($textMode, 'light', $title, false);
        $lines[] = 'Constraints: standalone image, not a contact sheet; no logo, no watermark, no brand marks.';

        if ($this->settings->customAvoid() !== '') {
            $lines[] = 'Also avoid: ' . $this->settings->customAvoid();
        }

        return implode("\n", $lines);
    }

    /**
     * Prompt for revising the image the editor is looking at.
     *
     * @param  Skill|null  $skill  The uploaded skill that sets the style, or null for the built-in style
     */
    public function buildRevision(
        string $instruction,
        string $layoutMode,
        string $textMode,
        Ratio $ratio,
        string $title = '',
        string $rulePrompt = '',
        ?Ratio $generatedRatio = null,
        int $editorImages = 0,
        ?Skill $skill = null
    ): string {
        $mode = \in_array($layoutMode, ['light', 'collage', 'dark', 'geometric'], true) ? $layoutMode : 'light';

        $lines   = [];
        $lines[] = $editorImages > 0
            ? 'Revise the first supplied image. It is the edit target.'
            : 'Revise the supplied image. It is the edit target.';

        if ($editorImages > 0) {
            $lines[] = 'Input images from the editor: the other ' . self::imagesCome($editorImages)
                . ' from the editor as reference for the change. ' . self::editorImageRule('the style of the image being revised');
        }

        $lines[] = 'Change requested by the editor: ' . trim($instruction);
        $lines[] = 'Keep everything the request does not mention: the subject, composition, palette and materials stay as they are.';
        $lines[] = $skill
            ? "Style guardrails, from the style guide \"" . $skill->name . "\":\n<<<\n" . $skill->summary() . "\n>>>"
            : 'Style guardrails: ' . StyleProfile::guardrails()
                . ($this->settings->styleColors() ? ' Brand colours: ' . implode(', ', $this->settings->styleColors()) . '.' : '');

        if ($this->settings->customStylePrompt() !== '') {
            $lines[] = 'Additional brand direction: ' . $this->settings->customStylePrompt();
        }

        if ($rulePrompt !== '') {
            $lines[] = 'Direction for this image:' . self::direction($rulePrompt);
        }

        $lines[] = 'Composition/framing: ' . $ratio->orientation() . ', final aspect ratio ' . $ratio->label() . '.'
            . $this->cropNote($ratio, $generatedRatio);
        $lines[] = StyleProfile::textRule($textMode, $mode, $title, $skill === null);

        if ($skill === null) {
            $lines[] = 'Avoid: ' . StyleProfile::avoid($mode)
                . ($this->settings->customAvoid() !== '' ? ' Also avoid: ' . $this->settings->customAvoid() : '');
        } elseif ($this->settings->customAvoid() !== '') {
            $lines[] = 'Also avoid: ' . $this->settings->customAvoid();
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array{title?: string, content?: string, tags?: string[], extra?: array<string, string>}  $article
     * @param  array{subject?: string}                                                                  $concept
     */
    private function subject(array $article, array $concept, string $rulePrompt): string
    {
        $subject = trim((string) ($concept['subject'] ?? ''));

        if ($subject !== '') {
            return rtrim($subject, '. ') . '.';
        }

        // No concept from a language model: hand the image model a short brief and let it find the metaphor
        $brief = $this->articleBrief($article, '', 700);

        return 'Find one clear business metaphor, with a visual sequence, for this article and show only that. '
            . 'The article is background for you; none of its words may appear in the image. '
            . "Article brief:\n<<<\n" . $brief . "\n>>>\n";
    }

    /**
     * Models offer a handful of sizes. When the wanted ratio is not one of them the
     * result is cropped afterwards, so the model must keep the edges expendable.
     */
    private function cropNote(Ratio $target, ?Ratio $generated): string
    {
        if ($generated === null || abs($generated->value() - $target->value()) < 0.02) {
            return '';
        }

        if ($target->value() > $generated->value()) {
            $keep = (int) round($generated->value() / $target->value() * 100);

            return ' The image will be center-cropped to ' . $target->label() . ': keep every essential element inside the central '
                . $keep . '% of the height, and let only background texture reach the top and bottom edges.';
        }

        $keep = (int) round($target->value() / $generated->value() * 100);

        return ' The image will be center-cropped to ' . $target->label() . ': keep every essential element inside the central '
            . $keep . '% of the width, and let only background texture reach the left and right edges.';
    }

    /**
     * The extra prompts of the image field and of the category, one per line when there are several.
     */
    private static function direction(string $text): string
    {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\R/u', $text) ?: [])));

        return \count($lines) > 1 ? "\n- " . implode("\n- ", $lines) : ' ' . ($lines[0] ?? '');
    }

    private static function imagesCome(int $count): string
    {
        return $count === 1 ? 'image comes' : $count . ' images come';
    }

    /**
     * What an editor's own reference images are for, and what they are not for.
     */
    private static function editorImageRule(string $style = 'the style described here'): string
    {
        return 'Use this reference for what to show: the objects, product screens, people or arrangement the editor points to. '
            . 'Redraw that content in ' . $style . ' rather than pasting it in, and keep the palette of that style whatever colours the reference has. '
            . 'Do not reproduce text, logos or watermarks from the reference unless an editor request asks for it.';
    }

    /**
     * @param  array<int, mixed>  $items
     *
     * @return string[]
     */
    private static function cleanList(array $items): array
    {
        $clean = [];

        foreach ($items as $item) {
            $item = trim(preg_replace('/\s+/u', ' ', (string) $item) ?? '');

            if ($item !== '') {
                $clean[] = mb_substr($item, 0, 500);
            }
        }

        return array_values(array_unique($clean));
    }
}
