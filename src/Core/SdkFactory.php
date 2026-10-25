<?php

declare(strict_types=1);

namespace Lunixi\WP\Core;

use Lunixi\Sdk\Exception\ConfigurationException;
use Lunixi\Sdk\LunixiClient;

defined('ABSPATH') || exit;

/**
 * Builds a configured {@see LunixiClient} from the stored settings, wired to the
 * WordPress HTTP transport and a transient-backed token store. One instance is
 * memoised per request.
 */
final class SdkFactory
{
    private Settings $settings;
    private ?LunixiClient $client = null;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    /**
     * @throws ConfigurationException when credentials are missing/invalid.
     */
    public function client(): LunixiClient
    {
        if ($this->client === null) {
            $creds = $this->settings->credentials();
            $this->client = LunixiClient::create(
                [
                    'baseUrl' => $creds['baseUrl'],
                    'keyId' => $creds['keyId'],
                    'privateKey' => $creds['privateKey'],
                    'environment' => $creds['environment'],
                    'userAgent' => 'lunixi-wp/' . LUNIXI_VERSION,
                ],
                new WpHttpClient(),
                new WpTransientTokenStore()
            );
        }

        return $this->client;
    }

    public function isReady(): bool
    {
        return $this->settings->isConfigured();
    }
}
