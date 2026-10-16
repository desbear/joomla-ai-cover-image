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

use Gss\Plugin\System\GssAiImage\Provider\ProviderInterface;

/**
 * Asks a language model to turn the article into one picture idea.
 *
 * Image models draw what they are told. Given a whole article they tend to
 * illustrate its words; given one metaphor they draw the metaphor.
 */
final class ConceptWriter
{
    /**
     * @param  string[]  $instructions  Requests the editor has made so far
     *
     * @return array{topic: string, subject: string, layout_mode: string}  Empty strings when no concept could be written
     */
    public function write(ProviderInterface $provider, string $articleBrief, array $instructions = []): array
    {
        $empty = ['topic' => '', 'subject' => '', 'layout_mode' => ''];
        $user  = $articleBrief;

        if ($instructions) {
            $user .= "\n\nThe editor also asked for:\n- " . implode("\n- ", $instructions);
        }

        try {
            $reply = $provider->text(StyleProfile::artDirectorBrief(), $user);
        } catch (\Throwable $e) {
            // The picture can still be made from the article itself
            return $empty;
        }

        $json = self::decode($reply);

        if (!$json) {
            return $empty;
        }

        $mode = strtolower(trim((string) ($json['layout_mode'] ?? '')));

        return [
            'topic'       => mb_substr(trim((string) ($json['topic'] ?? '')), 0, 200),
            'subject'     => mb_substr(trim((string) ($json['subject'] ?? '')), 0, 1500),
            'layout_mode' => \in_array($mode, ['light', 'collage', 'dark', 'geometric'], true) ? $mode : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function decode(string $reply): array
    {
        $reply = trim($reply);

        // Some models wrap JSON in a code fence
        if (preg_match('/\{.*\}/s', $reply, $m)) {
            $reply = $m[0];
        }

        $json = json_decode($reply, true);

        return \is_array($json) ? $json : [];
    }
}
