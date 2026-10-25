<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

use Lunixi\Sdk\Exception\ApiException;
use Lunixi\Sdk\Http\HttpClientInterface;
use Lunixi\Sdk\Http\HttpResponse;

defined('ABSPATH') || exit;

/**
 * Routes SDK HTTP calls through the WordPress HTTP API (`wp_remote_request`), so
 * the gateway requests honour the site's configured transport, proxy, SSL and
 * filters. Redirects are never followed (payment APIs must not auto-redirect).
 */
final class WpHttpClient implements HttpClientInterface
{
    public function send(string $method, string $url, array $headers, ?string $body, float $timeout): HttpResponse
    {
        $args = [
            'method' => strtoupper($method),
            'headers' => $headers,
            'timeout' => $timeout,
            'redirection' => 0,
            'sslverify' => true,
            'user-agent' => $headers['User-Agent'] ?? 'lunixi-wp',
        ];
        if ($body !== null) {
            $args['body'] = $body;
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            throw new ApiException('HTTP transport error: ' . $response->get_error_message());
        }

        $status = (int) wp_remote_retrieve_response_code($response);

        $responseHeaders = [];
        $rawHeaders = wp_remote_retrieve_headers($response);
        if (is_object($rawHeaders) && method_exists($rawHeaders, 'getAll')) {
            $rawHeaders = $rawHeaders->getAll();
        }
        foreach ((array) $rawHeaders as $name => $value) {
            $responseHeaders[strtolower((string) $name)] = is_array($value) ? implode(', ', $value) : (string) $value;
        }

        return new HttpResponse($status, $responseHeaders, (string) wp_remote_retrieve_body($response));
    }
}
