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
 * OpenAI image generation (gpt-image family).
 */
final class OpenAiProvider implements ProviderInterface
{
    /** Seconds a language model may take. The image itself gets the time set in the plugin. */
    private const TEXT_TIMEOUT = 45;

    /** Sizes the gpt-image models draw */
    private const SIZES = ['1536x1024' => [1536, 1024], '1024x1024' => [1024, 1024], '1024x1536' => [1024, 1536]];

    public function __construct(private readonly Settings $settings, private readonly HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'openai';
    }

    public function nearestRatio(Ratio $ratio): Ratio
    {
        [$w, $h] = self::SIZES[$this->size($ratio)];

        return new Ratio($w, $h);
    }

    public function generate(string $prompt, Ratio $ratio, array $referenceImages = [], ?string $baseImage = null): GeneratedImage
    {
        $model  = $this->settings->imageModel('openai');
        $images = array_values(array_filter(array_merge([$baseImage], $referenceImages)));
        $fields = [
            'model'   => $model,
            'prompt'  => $prompt,
            'size'    => $this->size($ratio),
            'quality' => $this->settings->openAiQuality(),
            'n'       => '1',
        ];

        if ($images) {
            $files = [];

            foreach ($images as $i => $path) {
                $data = is_file($path) ? file_get_contents($path) : false;

                if ($data === false) {
                    continue;
                }

                $files[] = [
                    'name'     => 'image[]',
                    'filename' => 'image-' . $i . '.' . pathinfo($path, PATHINFO_EXTENSION),
                    'mime'     => self::mime($path),
                    'data'     => $data,
                ];
            }

            $result = $files
                ? $this->http->postMultipart($this->settings->openAiBaseUrl() . '/images/edits', $fields, $files, $this->headers())
                : $this->http->postJson($this->settings->openAiBaseUrl() . '/images/generations', ['n' => 1] + $fields, $this->headers());
        } else {
            $result = $this->http->postJson($this->settings->openAiBaseUrl() . '/images/generations', ['n' => 1] + $fields, $this->headers());
        }

        $this->assertOk($result);

        $item = $result['json']['data'][0] ?? [];
        $data = isset($item['b64_json']) ? base64_decode((string) $item['b64_json'], true) : false;

        if ($data === false || $data === '') {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_IMAGE', 'OpenAI'), 'empty');
        }

        return new GeneratedImage($data, 'image/' . (string) ($result['json']['output_format'] ?? 'png'), $model);
    }

    public function text(string $system, string $user): string
    {
        $result = $this->http->postJson(
            $this->settings->openAiBaseUrl() . '/chat/completions',
            [
                'model'           => $this->settings->textModel('openai'),
                'messages'        => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
            ],
            $this->headers(),
            min(self::TEXT_TIMEOUT, $this->settings->timeout())
        );

        $this->assertOk($result);

        return (string) ($result['json']['choices'][0]['message']['content'] ?? '');
    }

    private function size(Ratio $ratio): string
    {
        return match ($ratio->orientation()) {
            'landscape' => '1536x1024',
            'portrait'  => '1024x1536',
            default     => '1024x1024',
        };
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->settings->apiKey('openai')];
    }

    /**
     * @param  array{status: int, json: array<string, mixed>, body: string}  $result
     */
    private function assertOk(array $result): void
    {
        if ($result['status'] >= 200 && $result['status'] < 300 && !isset($result['json']['error'])) {
            return;
        }

        $error   = $result['json']['error'] ?? [];
        $message = \is_array($error) ? (string) ($error['message'] ?? '') : (string) $error;
        $code    = \is_array($error) ? (string) ($error['code'] ?? $error['type'] ?? '') : '';
        $type    = \is_array($error) ? (string) ($error['type'] ?? '') : '';
        $status  = $result['status'];

        if ($status === 401 || $code === 'invalid_api_key' || $type === 'authentication_error') {
            $kind = 'AUTH';
        } elseif ($status === 429 || \in_array($code, ['insufficient_quota', 'rate_limit_exceeded', 'billing_hard_limit_reached'], true)) {
            $kind = 'QUOTA';
        } elseif (\in_array($code, ['moderation_blocked', 'content_policy_violation'], true) || str_contains($message, 'safety system')) {
            $kind = 'BLOCKED';
        } else {
            $kind = 'FAILED';
        }

        throw new AiImageException(
            Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_' . $kind, 'OpenAI'),
            $code ?: 'http_' . $status,
            0,
            'OpenAI HTTP ' . $status . ($message !== '' ? ': ' . $message : '')
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
