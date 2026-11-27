<?php

namespace App\Support\Integrations\Slack;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Slack's Web API (docs.slack.dev/reference/methods) with the bot token.
 * Slack answers 200 with "ok": false when a call didn't work, so decode()
 * turns its error code into words.
 */
final class SlackClient extends ProviderClient
{
    public const string URL = 'https://slack.com/api';

    public function __construct(private readonly string $token = '') {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Slack';
    }

    protected function headers(): array
    {
        return $this->token === '' ? [] : ['Authorization' => 'Bearer '.$this->token];
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    public function call(string $method, array $args = []): array
    {
        return $this->post($method, $args);
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function read(string $method, array $query = []): array
    {
        return $this->get($method, $query);
    }

    /**
     * Swaps the code Slack sent back for the workspace's bot token
     * (oauth.v2.access, with the app's id and secret as HTTP Basic auth).
     *
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function exchange(string $code, string $redirect): array
    {
        try {
            $response = Http::asForm()->acceptJson()
                ->withBasicAuth(SlackApp::clientId(), SlackApp::clientSecret())
                ->timeout(self::TIMEOUT)->connectTimeout(self::CONNECT_TIMEOUT)
                ->post(self::URL.'/oauth.v2.access', ['code' => $code, 'redirect_uri' => $redirect]);
        } catch (ConnectionException) {
            throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }
        if ($response->failed()) {
            throw new IntegrationFailed($this->t(':provider isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE, $response->status());
        }

        return $this->decode($response);
    }

    /**
     * Answers an interaction or a command through its response_url, which
     * Slack keeps for 30 minutes and five uses. Only Slack's own address is
     * ever posted to. Returns whether Slack took it.
     *
     * @param  array<string, mixed>  $message
     */
    public static function respond(string $url, array $message): bool
    {
        if (! self::isResponseUrl($url)) {
            return false;
        }
        try {
            return Http::timeout(8)->withOptions(['allow_redirects' => false])->asJson()->post($url, $message)->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public static function isResponseUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts) && ($parts['scheme'] ?? null) === 'https' && strtolower((string) ($parts['host'] ?? '')) === 'hooks.slack.com'
            && ! isset($parts['port']) && ! isset($parts['user']) && str_starts_with((string) ($parts['path'] ?? ''), '/');
    }

    protected function decode(Response $response): array
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];
        if (($json['ok'] ?? false) !== true) {
            throw self::refused((string) ($json['error'] ?? 'unknown_error'), is_string($json['needed'] ?? null) ? $json['needed'] : null);
        }

        return $json;
    }

    /** Slack's error code, in words a person can act on. */
    public static function refused(string $error, ?string $needed = null): IntegrationFailed
    {
        $t = fn (string $text, array $replace = []): string => is_string($line = __($text, $replace)) ? $line : $text;

        return match ($error) {
            'invalid_auth', 'not_authed', 'account_inactive', 'token_revoked', 'token_expired', 'no_permission', 'team_access_not_granted' => new IntegrationFailed($t('Slack turned the app’s token down. The app may have been removed from Slack. Connect Slack again.'), IntegrationFailed::CREDENTIALS),
            'missing_scope' => new IntegrationFailed($needed === null
                ? $t('The ServerOS app in Slack is missing a permission it needs. Connect Slack again to grant it.')
                : $t('The ServerOS app in Slack is missing a permission it needs (:scope). Connect Slack again to grant it.', ['scope' => $needed]), IntegrationFailed::PERMISSION),
            'not_in_channel' => new IntegrationFailed($t('The ServerOS app isn’t in that channel. Invite it with /invite @ServerOS in the channel, then try again.'), IntegrationFailed::INVALID, null, 'channel'),
            'channel_not_found', 'is_archived', 'is_inactive' => new IntegrationFailed($t('Slack can’t find that channel any more. It may have been archived or deleted. Pick another one.'), IntegrationFailed::NOT_FOUND, null, 'channel'),
            'message_not_found', 'cant_update_message', 'edit_window_closed' => new IntegrationFailed($t('Slack can’t change that message any more. It may have been deleted.'), IntegrationFailed::NOT_FOUND),
            'ratelimited' => new IntegrationFailed($t('Slack is limiting requests from this app right now. Try again in a minute.'), IntegrationFailed::RATE_LIMITED),
            'fatal_error', 'internal_error', 'service_unavailable', 'request_timeout' => new IntegrationFailed($t('Slack isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE),
            'invalid_code', 'bad_redirect_uri', 'oauth_authorization_url_mismatch' => new IntegrationFailed($t('Slack didn’t accept the sign-in. Start connecting again.'), IntegrationFailed::CREDENTIALS),
            'invalid_client_id', 'bad_client_secret' => new IntegrationFailed($t('Slack doesn’t know this install’s app. Whoever runs ServerOS should check SLACK_CLIENT_ID and SLACK_CLIENT_SECRET.'), IntegrationFailed::CREDENTIALS),
            default => new IntegrationFailed($t('Slack said :error.', ['error' => $error]), IntegrationFailed::INVALID),
        };
    }
}
