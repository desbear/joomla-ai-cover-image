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

use Joomla\CMS\Http\HttpFactory;
use Joomla\CMS\Language\Text;

/**
 * Small wrapper around Joomla's HTTP client, so the site's proxy settings apply.
 */
class HttpClient
{
    public function __construct(private readonly int $timeout = 180)
    {
    }

    /**
     * @param  array<string, mixed>   $payload
     * @param  array<string, string>  $headers
     *
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    public function postJson(string $url, array $payload, array $headers = [], ?int $timeout = null): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($body === false) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_REQUEST'), 'encode');
        }

        return $this->post($url, $body, $headers + ['Content-Type' => 'application/json'], $timeout);
    }

    /**
     * @param  array<string, string>                                        $fields
     * @param  array<int, array{name: string, filename: string, mime: string, data: string}>  $files
     * @param  array<string, string>                                        $headers
     *
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    public function postMultipart(string $url, array $fields, array $files, array $headers = []): array
    {
        $boundary = '----GssAiImage' . bin2hex(random_bytes(12));
        $body     = '';

        foreach ($fields as $name => $value) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $name . '"' . "\r\n\r\n"
                . $value . "\r\n";
        }

        foreach ($files as $file) {
            $body .= '--' . $boundary . "\r\n"
                . 'Content-Disposition: form-data; name="' . $file['name'] . '"; filename="' . $file['filename'] . '"' . "\r\n"
                . 'Content-Type: ' . $file['mime'] . "\r\n\r\n"
                . $file['data'] . "\r\n";
        }

        $body .= '--' . $boundary . "--\r\n";

        return $this->post($url, $body, $headers + ['Content-Type' => 'multipart/form-data; boundary=' . $boundary]);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    public function get(string $url, array $headers = [], ?int $timeout = null): array
    {
        return $this->send('get', $url, null, $headers, $timeout);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    public function delete(string $url, array $headers = [], ?int $timeout = null): array
    {
        return $this->send('delete', $url, null, $headers, $timeout);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    protected function post(string $url, string $body, array $headers, ?int $timeout = null): array
    {
        return $this->send('post', $url, $body, $headers, $timeout);
    }

    /**
     * @param  array<string, string>  $headers
     *
     * @return array{status: int, json: array<string, mixed>, body: string}
     */
    protected function send(string $method, string $url, ?string $body, array $headers, ?int $timeout = null): array
    {
        try {
            $http     = HttpFactory::getHttp();
            $response = $method === 'post'
                ? $http->post($url, (string) $body, $headers, $timeout ?? $this->timeout)
                : $http->$method($url, $headers, $timeout ?? $this->timeout);
        } catch (\Throwable $e) {
            throw new AiImageException(Text::_('PLG_SYSTEM_GSSAIIMAGE_ERR_CONNECTION'), 'connection', 0, $e->getMessage());
        }

        $text = (string) $response->getBody();
        $json = json_decode($text, true);

        return [
            'status' => (int) $response->getStatusCode(),
            'json'   => \is_array($json) ? $json : [],
            'body'   => $text,
        ];
    }
}
