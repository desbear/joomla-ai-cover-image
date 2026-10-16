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
use Gss\Plugin\System\GssAiImage\Service\ImageProcessor;
use Gss\Plugin\System\GssAiImage\Service\Ratio;
use Gss\Plugin\System\GssAiImage\Service\Settings;
use Joomla\CMS\Language\Text;

/**
 * Image generation through an Open WebUI server.
 *
 * Open WebUI sits in front of the image models an organisation has set up. It answers
 * with the address of a file on the server, which is then fetched with the same key.
 *
 *   POST /api/v1/images/generations   prompt only
 *   POST /api/v1/images/edit          prompt with one image, as a data URL
 *   GET  /api/v1/files/{id}/content   the image
 *   POST /api/v1/chat/completions     language model
 */
final class OpenWebUiProvider implements ProviderInterface
{
    /** Seconds a language model may take. The image itself gets the time set in the plugin. */
    private const TEXT_TIMEOUT = 45;

    /** Seconds to fetch the finished image, which is already made by then */
    private const FETCH_TIMEOUT = 60;

    /** Sizes the gpt-image models behind the server draw */
    private const SIZES = ['1536x1024' => [1536, 1024], '1024x1024' => [1024, 1024], '1024x1536' => [1024, 1536]];

    public function __construct(private readonly Settings $settings, private readonly HttpClient $http)
    {
    }

    public function name(): string
    {
        return 'openwebui';
    }

    public function nearestRatio(Ratio $ratio): Ratio
    {
        [$w, $h] = self::SIZES[$this->size($ratio)];

        return new Ratio($w, $h);
    }

    public function generate(string $prompt, Ratio $ratio, array $referenceImages = [], ?string $baseImage = null): GeneratedImage
    {
        $model   = $this->settings->imageModel('openwebui');
        $payload = ['prompt' => $prompt, 'size' => $this->size($ratio), 'n' => 1] + ($model !== '' ? ['model' => $model] : []);

        // The edit route is given exactly one image. Open WebUI itself takes a list, but passes it on
        // in a form that image backends refuse (seen on a live server: HTTP 400 for any list, even
        // of one). One image as a plain value works everywhere.
        [$image, $prefix, $used] = $this->oneImage($referenceImages, $baseImage);

        if ($image !== null) {
            $result = $this->http->postJson(
                $this->url('/api/v1/images/edit'),
                ['image' => $image, 'prompt' => $prefix . $prompt] + $payload,
                $this->headers()
            );

            // The server may have image editing switched off, or be too old to have it
            if (\in_array($result['status'], [403, 404, 405, 501], true)) {
                if ($baseImage !== null) {
                    throw new AiImageException(
                        Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_EDIT'),
                        'no_edit',
                        0,
                        'Open WebUI HTTP ' . $result['status'] . ' on /api/v1/images/edit: ' . self::detail($result)
                    );
                }

                // A new image can still be made from the prompt alone
                $result = $this->http->postJson($this->url('/api/v1/images/generations'), $payload, $this->headers());
                $used   = false;
            }
        } else {
            $result = $this->http->postJson($this->url('/api/v1/images/generations'), $payload, $this->headers());
            $used   = false;
        }

        $this->assertOk($result);

        // A list of files, or the same inside "data"
        $items = $result['json'];
        $items = isset($items['data']) && \is_array($items['data']) ? $items['data'] : $items;
        $item  = \is_array($items[0] ?? null) ? $items[0] : [];

        if (!empty($item['b64_json'])) {
            $data = base64_decode((string) $item['b64_json'], true);
        } elseif (!empty($item['url'])) {
            $data = $this->fetch((string) $item['url']);
            $this->forget($item);
        } else {
            $data = false;
        }

        if ($data === false || $data === '') {
            throw new AiImageException(Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_IMAGE', 'Open WebUI'), 'empty', 0, 'Open WebUI answered without an image: ' . mb_substr($result['body'], 0, 300));
        }

        return new GeneratedImage($data, (string) ($item['content_type'] ?? 'image/png'), $model !== '' ? $model : 'Open WebUI', $used);
    }

    public function text(string $system, string $user): string
    {
        $model = $this->settings->textModel('openwebui');

        if ($model === '') {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_NO_TEXT_MODEL'), 'no_text_model');
        }

        $result = $this->http->postJson(
            $this->url('/api/v1/chat/completions'),
            [
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                // No response_format: what the server passes it on to differs by model, and some refuse it
                // (seen on a live server: HTTP 400 from an Azure model). The answer is read as JSON anyway.
                'stream'   => false,
            ],
            $this->headers(),
            min(self::TEXT_TIMEOUT, $this->settings->timeout())
        );

        $this->assertOk($result);

        return (string) ($result['json']['choices'][0]['message']['content'] ?? '');
    }

    /**
     * The one image to send, as a data URL.
     *
     * Revising: the image to revise, alone. Further images cannot go along.
     * New image: the reference image, or several of them laid out on one sheet.
     *
     * @param  string[]  $referenceImages
     *
     * @return array{0: string|null, 1: string, 2: bool}  The image, words to put before the prompt, whether every image is in it
     */
    private function oneImage(array $referenceImages, ?string $baseImage): array
    {
        $references = array_values(array_filter($referenceImages, 'is_file'));

        if ($baseImage !== null && is_file($baseImage)) {
            return [self::dataUrl((string) file_get_contents($baseImage), self::mime($baseImage)), '', !$references];
        }

        if (!$references) {
            return [null, '', false];
        }

        if (\count($references) > 1) {
            $sheet = (new ImageProcessor())->sheet($references);

            if ($sheet !== null) {
                $prefix = 'Input image: the one supplied image is a sheet that holds ' . $sheet['count'] . ' separate reference images, '
                    . ($sheet['layout'] === 'stacked' ? 'one under the other from top to bottom' : 'side by side from left to right')
                    . ', with white gaps between them. Where the text below speaks of the first, the remaining or the supplied images, '
                    . 'it means these pictures in that order. The sheet is only a way to hand the references over: '
                    . "do not draw a sheet, panels, gaps or a collage of the references.\n";

                return [self::dataUrl($sheet['data'], 'image/jpeg'), $prefix, $sheet['count'] === \count($references)];
            }
        }

        return [self::dataUrl((string) file_get_contents($references[0]), self::mime($references[0])), '', \count($references) === 1];
    }

    private static function dataUrl(string $binary, string $mime): string
    {
        return 'data:' . $mime . ';base64,' . base64_encode($binary);
    }

    /**
     * Fetch the image the server made. The key goes to the configured server only.
     */
    private function fetch(string $url): string
    {
        $address = $this->sameServer($url);

        if ($address === null) {
            throw new AiImageException(
                Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_FAILED', 'Open WebUI'),
                'foreign_url',
                0,
                'Open WebUI pointed to a file on another server, which is not fetched: ' . mb_substr($url, 0, 200)
            );
        }

        $result = $this->http->get($address, $this->headers(), self::FETCH_TIMEOUT);

        $this->assertOk($result);

        return $result['body'];
    }

    /**
     * Remove the file from the account on the server, where every generated image would otherwise pile up.
     *
     * @param  array<string, mixed>  $item
     */
    private function forget(array $item): void
    {
        $id = (string) ($item['id'] ?? '');

        if (!$this->settings->openWebUiDeleteFiles() || !preg_match('/^[A-Za-z0-9-]{8,64}$/', $id)) {
            return;
        }

        try {
            $this->http->delete($this->url('/api/v1/files/' . $id), $this->headers(), 20);
        } catch (\Throwable $e) {
            // The image is already here. A file left on the server is no reason to fail.
        }
    }

    /**
     * The address of a file as the server gave it, when it is on the configured server.
     */
    private function sameServer(string $url): ?string
    {
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return $this->url($url);
        }

        $base   = parse_url($this->settings->openWebUiBaseUrl());
        $target = parse_url($url);
        $port   = static fn (array $parts): int => (int) ($parts['port'] ?? (($parts['scheme'] ?? '') === 'https' ? 443 : 80));

        if (!$base || !$target || empty($target['host']) || isset($target['user']) || isset($target['pass'])) {
            return null;
        }

        $same = strtolower((string) ($target['scheme'] ?? '')) === strtolower((string) ($base['scheme'] ?? ''))
            && strtolower($target['host']) === strtolower((string) ($base['host'] ?? ''))
            && $port($target) === $port($base);

        return $same ? $url : null;
    }

    private function url(string $path): string
    {
        return $this->settings->openWebUiBaseUrl() . $path;
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
        return ['Authorization' => 'Bearer ' . $this->settings->apiKey('openwebui')];
    }

    /**
     * @param  array{status: int, json: array<string|int, mixed>, body: string}  $result
     */
    private function assertOk(array $result): void
    {
        $status = $result['status'];

        if ($status >= 200 && $status < 300) {
            return;
        }

        if ($status === 401) {
            $kind = 'AUTH';
        } elseif ($status === 403) {
            $kind = 'DENIED';
        } elseif ($status === 429) {
            $kind = 'QUOTA';
        } else {
            $kind = 'FAILED';
        }

        throw new AiImageException(
            Text::sprintf('PLG_SYSTEM_GSSAIIMAGE_ERR_PROVIDER_' . $kind, 'Open WebUI'),
            'http_' . $status,
            0,
            'Open WebUI HTTP ' . $status . ': ' . self::detail($result)
        );
    }

    /**
     * @param  array{status: int, json: array<string|int, mixed>, body: string}  $result
     */
    private static function detail(array $result): string
    {
        $detail = $result['json']['detail'] ?? $result['json']['error'] ?? '';
        $detail = \is_string($detail) ? $detail : (string) json_encode($detail, JSON_UNESCAPED_UNICODE);

        return $detail !== '' && $detail !== '""' ? mb_substr($detail, 0, 500) : mb_substr(strip_tags($result['body']), 0, 200);
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
