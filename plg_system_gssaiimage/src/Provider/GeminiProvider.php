<?php

/**
 * @package     GSS AI Image
 * @subpackage  plg_system_gssaiimage
 *
 * @copyright   (C) 2026 GSS
 * @license     GNU General Public License version 2 or later; see LICENSE
 */

namespace Gss\Plugin\System\GssAiImage\Provider;

\defined('_JEXEC') or die;

use Gss\Plugin\System\GssAiImage\Service\AiImageException;
use Gss\Plugin\System\GssAiImage\Service\HttpClient;
use Gss\Plugin\System\GssAiImage\Service\Ratio;
use Gss\Plugin\System\GssAiImage\Service\Settings;
use Joomla\CMS\Language\Text;

/**
 * Google Gemini image generation ("Nano Banana").
 */
final class GeminiProvider implements ProviderInterface
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/';

    /** Seconds a language model may take. The image itself gets the time set in the plugin. */
    private const TEXT_TIMEOUT = 45;

    /** Aspect ratios the Gemini image models draw */
    private const RATIOS = ['21:9', '16:9', '3:2', '4:3', '5:4', '1:1', '4:5', '3:4', '2:3', '9:16'];

    public function __construct(private readonly Settings $settings, private readonly HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function nearestRatio(Ratio $ratio): Ratio
    {
        return Ratio::parse($this->aspect($ratio));
    }

    public function generate(string $prompt, Ratio $ratio, array $referenceImages = [], ?string $baseImage = null): GeneratedImage
    {
        $model = $this->settings->imageModel('gemini');
        $parts = [['text' => $prompt]];

        foreach (array_filter(array_merge([$baseImage], $referenceImages)) as $path) {
            $data = is_file($path) ? file_get_contents($path) : false;

            if ($data !== false) {
                $parts[] = ['inlineData' => ['mimeType' => self::mime($path), 'data' => base64_encode($data)]];
            }
        }

        $imageConfig = ['aspectRatio' => $this->aspect($ratio)];

        // Only the Pro image models accept an output size
        if ($this->settings->geminiImageSize() !== '' && str_contains($model, 'pro')) {
            $imageConfig['imageSize'] = $this->settings->geminiImageSize();
        }

        $result = $this->http->postJson(
            self::ENDPOINT . rawurlencode($model) . ':generateContent',
            [
                'contents'         => [['role' => 'user', 'parts' => $parts]],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                    'imageConfig'        => $imageConfig,
                ],
            ],
            $this->headers()
        );

        $this->assertOk($result);

        $said = '';

        foreach ((array) ($result['json']['candidates'][0]['content']['parts'] ?? []) as $part) {
            $inline = $part['inlineData'] ?? $part['inline_data'] ?? null;

            if (\is_array($inline) && !empty($inline['data'])) {
                $data = base64_decode((string) $inline['data'], true);

                if ($data !== false && $data !== '') {
                    return new GeneratedImage($data, (string) ($inline['mimeType'] ?? $inline['mime_type'] ?? 'image/png'), $model);
                }
            }

            if (isset($part['text'])) {
                $said .= (string) $part['text'];
            }
        }

        $reason  = (string) ($result['json']['promptFeedback']['blockReason'] ?? $result['json']['candidates'][0]['finishReason'] ?? '');
        $blocked = $reason !== '' && preg_match('/SAFETY|PROHIBITED|BLOCK|RECITATION|SPII/i', $reason);

        throw new AiImageException(
            $blocked
                ? Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_BLOCKED', 'Gemini')
                : Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_IMAGE', 'Gemini'),
            $blocked ? 'blocked' : 'empty',
            0,
            trim('Gemini returned no image. ' . $reason . ' ' . mb_substr(trim($said), 0, 300))
        );
    }

    public function text(string $system, string $user): string
    {
        $result = $this->http->postJson(
            self::ENDPOINT . rawurlencode($this->settings->textModel('gemini')) . ':generateContent',
            [
                'systemInstruction' => ['parts' => [['text' => $system]]],
                'contents'          => [['role' => 'user', 'parts' => [['text' => $user]]]],
                'generationConfig'  => ['responseMimeType' => 'application/json'],
            ],
            $this->headers(),
            min(self::TEXT_TIMEOUT, $this->settings->timeout())
        );

        $this->assertOk($result);

        $text = '';

        foreach ((array) ($result['json']['candidates'][0]['content']['parts'] ?? []) as $part) {
            $text .= (string) ($part['text'] ?? '');
        }

        return $text;
    }

    private function aspect(Ratio $ratio): string
    {
        $best  = '16:9';
        $delta = INF;

        foreach (self::RATIOS as $candidate) {
            // Compare on a log scale so 2:1 is as far from 1:1 as 1:2 is
            $distance = abs(log(Ratio::parse($candidate)->value()) - log($ratio->value()));

            if ($distance < $delta) {
                $delta = $distance;
                $best  = $candidate;
            }
        }

        return $best;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['x-goog-api-key' => $this->settings->apiKey('gemini')];
    }

    /**
     * @param  array{status: int, json: array<string, mixed>, body: string}  $result
     */
    private function assertOk(array $result): void
    {
        if ($result['status'] >= 200 && $result['status'] < 300 && !isset($result['json']['error'])) {
            return;
        }

        $error   = (array) ($result['json']['error'] ?? []);
        $message = (string) ($error['message'] ?? '');
        $state   = (string) ($error['status'] ?? '');
        $status  = $result['status'];

        if (\in_array($status, [401, 403], true) || \in_array($state, ['UNAUTHENTICATED', 'PERMISSION_DENIED'], true) || str_contains($message, 'API key')) {
            $kind = 'AUTH';
        } elseif ($status === 429 || $state === 'RESOURCE_EXHAUSTED') {
            $kind = 'QUOTA';
        } else {
            $kind = 'FAILED';
        }

        throw new AiImageException(
            Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_' . $kind, 'Gemini'),
            $state ?: 'http_' . $status,
            0,
            'Gemini HTTP ' . $status . ($message !== '' ? ': ' . $message : '')
        );
    }

    private static function mime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png'   => 'image/png',
            'webp'  => 'image/webp',
            default => 'image/jpeg',
        };
    }
}
