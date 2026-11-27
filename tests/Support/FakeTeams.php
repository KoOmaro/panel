<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for a Teams workflow webhook: it takes messages with Adaptive
 * Card attachments, answers 202 like Power Automate does, keeps the cards
 * and can fail the next post.
 */
final class FakeTeams
{
    public const string URL = 'https://fake-env.01.environment.api.powerplatform.com:443/powerautomate/automations/direct/workflows/fakeworkflow/triggers/manual/paths/invoke?api-version=1&sp=%2Ftriggers%2Fmanual%2Frun&sv=1.0&sig=fake-teams-signature-for-tests';

    /** @var list<array<string, mixed>> what each post's card says */
    public array $cards = [];

    /** @var list<int> */
    private array $failures = [];

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['*.environment.api.powerplatform.com*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    public function failNext(int $status): self
    {
        $this->failures[] = $status;

        return $this;
    }

    /** @return list<string> each card's title */
    public function titles(): array
    {
        return array_map(fn (array $card) => (string) ($card['body'][0]['text'] ?? ''), $this->cards);
    }

    public function handle(Request $request): PromiseInterface
    {
        if (($status = array_shift($this->failures)) !== null) {
            return Http::response(['error' => ['code' => 'WorkflowNotFound', 'message' => 'The workflow could not be found.']], $status);
        }
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
        if (($query['sig'] ?? null) !== 'fake-teams-signature-for-tests') {
            return Http::response(['error' => ['code' => 'DirectApiAuthorizationRequired']], 401);
        }
        $body = (array) $request->data();
        $attachment = $body['attachments'][0] ?? null;
        if (($body['type'] ?? null) !== 'message' || ! is_array($attachment) || ($attachment['contentType'] ?? null) !== 'application/vnd.microsoft.card.adaptive') {
            return Http::response(['error' => ['code' => 'InvalidRequestContent']], 400);
        }
        $this->cards[] = (array) $attachment['content'];

        return Http::response('', 202);
    }
}
