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
 * The built-in style: a clean editorial illustration for article covers.
 *
 * It belongs to no brand. Brand colours and extra direction come from the settings,
 * and a brand's own visual language comes as an uploaded skill.
 */
final class StyleProfile
{
    public static function primaryRequest(string $mode, string $topic): string
    {
        if ($mode === 'dark') {
            return 'Create a formal announcement graphic about ' . $topic . '.';
        }

        return 'Create an editorial illustration for an article about ' . $topic . '.';
    }

    public static function backdrop(string $mode): string
    {
        return match ($mode) {
            'dark' => 'a deep, dark field with a soft gradient and fine, even noise, one restrained glow in an accent colour, '
                . 'and large quiet zones. Never flat black, never a generic dark interface.',
            default => 'a light, near-white field with one or two broad, heavily blurred gradient glows in the accent colours, '
                . 'fine premium noise, generous negative space, and at most one low-contrast support shape such as a dot grid or a soft halo. '
                . 'Keep the centre breathable.',
        };
    }

    public static function subjectConstruction(string $mode): string
    {
        return match ($mode) {
            'dark' => 'Anchor one composed object or small scene to the lower-left or right edge, built from flat shapes with soft, paper-like depth; '
                . 'do not scatter several vignettes.',
            'geometric' => 'Express the idea through abstract geometry only: overlapping sheets, strips, grid fragments, translucent rectangles, circles and colour fields. '
                . 'No documents, hands, devices or other recognisable objects. The shapes must organise meaning, not decorate.',
            'light' => 'Build every major object as a composed editorial illustration from two or more overlapping flat shapes with soft edges and subtle texture, '
                . 'never as a generic app icon. Keep meaning readable through silhouette, grouping and arrows. '
                . 'Reserve simple line icons for minor cues such as arrows, checks, nodes and controls; do not use standalone icons or rows of identical cards as the main visual.',
            default => 'Build every major object as cut-paper collage: overlapping sheets, torn-edge strips, grid fragments, translucent rectangles, circles and colour fields, '
                . 'or a cropped grayscale-photo cutout combined with such modules. Keep meaning readable through silhouette, grouping and arrows. '
                . 'Show a person, where one is needed, as one cropped grayscale hand or partial figure with a thin white cut edge, never as a full portrait. '
                . 'Reserve simple line icons for minor cues; do not use standalone icons or rows of identical cards as the main visual.',
        };
    }

    public static function medium(string $mode): string
    {
        return match ($mode) {
            'dark' => 'dark editorial mode: gradient hierarchy in the accent colours, fine texture, restrained dot grid or halftone support, '
                . 'shadows shallow and paper-like, no cinematic lighting.',
            'light', 'geometric' => 'modern editorial illustration: flat vector shapes with subtle texture, soft halo glows, shallow elevation, '
                . 'flat panels that never look like glass or polished 3D.',
            default => 'editorial paper collage layered on a light gradient field: cut-paper texture, faint photocopy grain, shallow paper-like shadows, '
                . 'without looking vintage.',
        };
    }

    public static function composition(string $mode, string $orientation): string
    {
        $support = ' Add restrained edge support graphics such as a soft halo, a halftone disc or a dot grid; one large support form plus two or three small accents, '
            . 'low contrast, occupying roughly 10-20% of the canvas, never behind the focal content.';

        if ($mode === 'dark') {
            return 'wide margins and large quiet zones; the illustration supports rather than competes with the open area.' . $support;
        }

        $frame = match ($orientation) {
            'portrait' => 'portrait; stack the story vertically with a clear directional flow, use one large central metaphor rather than many small elements, and keep the top and bottom safe zones open.',
            'square'   => 'square; one dominant illustration occupying roughly 55-70% of the canvas, with a large open zone, readable at thumbnail size.',
            default    => 'landscape article cover; one horizontal sequence. A three-stage story may use up to three separated vignette islands with generous gaps. '
                . 'Avoid tiny details; the image must remain legible around 600 px wide.',
        };

        return $frame . ' One dominant focal visual, clear directional flow, safe margins.' . $support;
    }

    /**
     * @param  string[]  $colors  Brand colours from the settings, as hex codes. Empty for a restrained palette of the model's choosing.
     */
    public static function palette(array $colors): string
    {
        if (!$colors) {
            return 'a restrained palette of one dominant hue, one accent and neutrals; no rainbow of colours, no neon.';
        }

        return 'use these brand colours and tints of them: ' . implode(', ', $colors) . '. Add no other hues beyond neutrals.';
    }

    /**
     * @param  bool  $ownTypography  False when an uploaded skill decides typeface and colour of the headline
     */
    public static function textRule(string $textMode, string $mode, string $headline, bool $ownTypography = true): string
    {
        $headline = trim($headline);

        if ($textMode !== 'title' || $headline === '') {
            return 'Text: absolutely no text, letters, numbers, labels, or readable handwriting. '
                . 'Use only non-verbal icons, marks, shapes, and document-line abstractions.';
        }

        $quoted = 'Text (verbatim): "' . str_replace('"', "'", $headline) . '". ';

        if (!$ownTypography) {
            return $quoted . 'Render this headline exactly as quoted, every character correct, in the typeface and colour the style guide prescribes, '
                . 'two or three lines with deliberate line breaks, placed in a large open zone. No other words anywhere in the image.';
        }

        $colour = $mode === 'dark' ? 'white or a very light tint of the accent colour' : 'dark charcoal';

        return $quoted
            . 'Render this headline exactly as quoted, every character correct, in a clean bold sans-serif, '
            . $colour . ', two or three lines with deliberate line breaks, placed in a large open zone. No other words anywhere in the image.';
    }

    public static function constraints(): string
    {
        return 'standalone image, not a contact sheet; one clear focal metaphor; every object must explain the idea of the article; '
            . 'handwriting is texture only and never readable; no logo, no watermark, no brand marks.';
    }

    public static function avoid(string $mode): string
    {
        if ($mode === 'dark') {
            return 'multiple scattered scenes, glossy 3D, glass panels, cyberpunk, neon, robots, AI brains, tiny body text, dense dashboards, unrequested logos.';
        }

        return 'glassmorphism, glossy 3D, oversized standalone icons, rows of identical icon cards, conventional app-style pictograms as the main visual, '
            . 'photorealistic office scenes, generic stock photography of people, cartoon characters, isometric art, robots, AI brains, '
            . 'cyberpunk, neon, dark interfaces, dense dashboards, excessive gradients, decorative elements without a narrative role.';
    }

    /**
     * A compact statement of the style, used when an existing image is being revised.
     */
    public static function guardrails(): string
    {
        return 'Editorial illustration: a light or dark gradient field with fine noise; a restrained palette; soft halos and dot grids as low-contrast support; '
            . 'major objects built as flat shapes or cut-paper collage; a person only as a cropped grayscale fragment; '
            . 'AI shown as connected nodes and information flow, never as a robot or a brain; generous whitespace; flat, paper-like shadows.';
    }

    /**
     * Brief for the language model that turns an article into a picture idea.
     */
    public static function artDirectorBrief(): string
    {
        return <<<'TXT'
You are an art director. You turn an article into ONE clear visual metaphor for its cover image.

The visual language:
- A light or dark gradient field, with editorial paper collage used for business-storytelling moments.
- Prefer visual metaphor over literal decoration. Every object must explain the idea of the article.
- Object vocabulary: documents, forms, sticky notes, envelopes, folders, stamps, pens, hands, magnifiers, phones, browser windows, simplified monitors, charts, coins, gears, location pins, approval nodes, arrows, tags, buildings, abstract geometry.
- Major objects are composed editorial objects made of overlapping flat shapes, torn strips, grid fragments, translucent blocks and circles, or a cropped grayscale-photo cutout combined with those modules. Never a standalone app icon.
- AI is a system layer: connected circular nodes, dotted paths, recommendation cards, selection marks, data grouping, a small spark where insight appears. Never a robot or a brain.
- The human is the decision layer: at most one cropped grayscale hand or partial figure, shown asking, reviewing, selecting or acting.
- When both appear the sequence reads: data, then the system organises or proposes, then a human understands or decides.
- Never propose: dashboards full of readable data, photorealistic office scenes, stock photography of people, cartoon characters, isometric art, glossy 3D, neon or cyberpunk.

Layout modes:
- "light": default for product education, thought leadership and most articles. Gradient field with light support graphics and composed objects.
- "collage": the message is about a human workflow, documents, data transformation, or a tangible before-and-after.
- "dark": corporate announcements, press or news, awards, institutional communications.
- "geometric": the topic is conceptual and gains nothing from documents, hands or devices.

Reply with JSON only, using these keys:
- "topic": the article's core idea in English, at most 12 words.
- "subject": 2 to 4 English sentences describing the single metaphor and its visual sequence from left to right (or top to bottom), naming the concrete objects to show. Do not mention any text, captions or labels.
- "layout_mode": one of "light", "collage", "dark", "geometric".
TXT;
    }

    /**
     * Names of the layout modes, as a style guide would call them.
     */
    public static function modeName(string $mode): string
    {
        return [
            'light'     => 'light gradient mode',
            'collage'   => 'editorial collage mode',
            'dark'      => 'dark mode',
            'geometric' => 'geometric abstract mode',
        ][$mode] ?? '';
    }
}
