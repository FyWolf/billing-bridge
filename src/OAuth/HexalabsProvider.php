<?php

namespace Hexalabs\BillingBridge\OAuth;

use GuzzleHttp\RequestOptions;
use SocialiteProviders\Manager\OAuth2\AbstractProvider;
use SocialiteProviders\Manager\OAuth2\User;

/**
 * Socialite provider pointing at the HexaLabs billing app's Passport server.
 *
 * Pelican has no SSO handoff — you cannot mint a panel session from outside —
 * so the only supported direction is panel-as-OAuth-client. Plain OAuth2 is
 * enough here, which avoids having to implement OIDC discovery and JWKS on the
 * billing side.
 */
class HexalabsProvider extends AbstractProvider
{
    public const IDENTIFIER = 'HEXALABS';

    protected $scopes = ['*'];

    protected $scopeSeparator = ' ';

    private function baseUrl(): string
    {
        return rtrim($this->getConfig('base_url', ''), '/');
    }

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase($this->baseUrl() . '/oauth/authorize', $state);
    }

    protected function getTokenUrl(): string
    {
        return $this->baseUrl() . '/oauth/token';
    }

    protected function getUserByToken($token): array
    {
        $response = $this->getHttpClient()->get($this->baseUrl() . '/api/user', [
            RequestOptions::HEADERS => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ],
        ]);

        return json_decode((string) $response->getBody(), true);
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User())->setRaw($user)->map([
            'id'       => $user['id'],
            'nickname' => $user['username'] ?? null,
            'name'     => $user['name'] ?? null,
            'email'    => $user['email'] ?? null,
            'avatar'   => $user['avatar'] ?? null,
        ]);
    }

    public static function additionalConfigKeys(): array
    {
        return ['base_url'];
    }
}
