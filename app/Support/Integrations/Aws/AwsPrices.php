<?php

namespace App\Support\Integrations\Aws;

use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;

/**
 * On-demand Linux prices of EC2 instance types, from the Price List Query
 * API (GetProducts), filtered down to the types asked for in one region.
 * Prices are public and the same for every account, so they're cached by
 * region for a day. Without pricing:GetProducts there are simply no prices.
 */
final class AwsPrices
{
    public const int HOURS_A_MONTH = 730;

    private const int VALUE_LIMIT = 1000;

    private const int MAX_PAGES = 10;

    /**
     * @param  list<string>  $types
     * @return array<string, array{monthly: float, hourly: float}>
     */
    public static function in(AwsClient $client, string $region, array $types): array
    {
        if ($types === []) {
            return [];
        }
        sort($types);
        $key = 'integrations:aws:prices:'.$region.':'.md5(implode(',', $types));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $prices = self::fetch($client, $region, $types);
        } catch (IntegrationFailed) {
            return [];
        }
        Cache::put($key, $prices, now()->addDay());

        return $prices;
    }

    /**
     * @param  list<string>  $types
     * @return array<string, array{monthly: float, hourly: float}>
     */
    private static function fetch(AwsClient $client, string $region, array $types): array
    {
        $prices = [];
        foreach (self::chunks($types) as $chunk) {
            $token = null;
            for ($page = 0; $page < self::MAX_PAGES; $page++) {
                $body = $client->pricing('GetProducts', array_filter([
                    'ServiceCode' => 'AmazonEC2',
                    'FormatVersion' => 'aws_v1',
                    'MaxResults' => 100,
                    'NextToken' => $token,
                    'Filters' => [
                        ['Type' => 'TERM_MATCH', 'Field' => 'regionCode', 'Value' => $region],
                        ['Type' => 'TERM_MATCH', 'Field' => 'operatingSystem', 'Value' => 'Linux'],
                        ['Type' => 'TERM_MATCH', 'Field' => 'operation', 'Value' => 'RunInstances'],
                        ['Type' => 'TERM_MATCH', 'Field' => 'tenancy', 'Value' => 'Shared'],
                        ['Type' => 'TERM_MATCH', 'Field' => 'preInstalledSw', 'Value' => 'NA'],
                        ['Type' => 'TERM_MATCH', 'Field' => 'capacitystatus', 'Value' => 'Used'],
                        ['Type' => 'ANY_OF', 'Field' => 'instanceType', 'Value' => implode(',', $chunk)],
                    ],
                ], fn ($value) => $value !== null));
                foreach ((array) ($body['PriceList'] ?? []) as $item) {
                    $product = is_string($item) ? json_decode($item, true) : $item;
                    $found = is_array($product) ? self::hourly($product) : null;
                    if ($found !== null && in_array($found[0], $types, true) && ! isset($prices[$found[0]])) {
                        $prices[$found[0]] = ['monthly' => round($found[1] * self::HOURS_A_MONTH, 2), 'hourly' => $found[1]];
                    }
                }
                $next = $body['NextToken'] ?? null;
                if (! is_string($next) || $next === '' || $next === $token) {
                    break;
                }
                $token = $next;
            }
        }

        return $prices;
    }

    /**
     * The type and its on-demand price an hour in USD, from one price list item.
     *
     * @param  array<string, mixed>  $product
     * @return array{0: string, 1: float}|null
     */
    private static function hourly(array $product): ?array
    {
        $type = $product['product']['attributes']['instanceType'] ?? null;
        if (! is_string($type)) {
            return null;
        }
        foreach ((array) ($product['terms']['OnDemand'] ?? []) as $term) {
            foreach ((array) ($term['priceDimensions'] ?? []) as $dimension) {
                $usd = $dimension['pricePerUnit']['USD'] ?? null;
                if (($dimension['unit'] ?? null) === 'Hrs' && is_numeric($usd) && (float) $usd > 0) {
                    return [$type, (float) $usd];
                }
            }
        }

        return null;
    }

    /**
     * Type names in comma-joined runs that fit in one filter value.
     *
     * @param  list<string>  $types
     * @return list<list<string>>
     */
    private static function chunks(array $types): array
    {
        $chunks = [];
        $current = [];
        foreach ($types as $type) {
            if ($current !== [] && strlen(implode(',', [...$current, $type])) > self::VALUE_LIMIT) {
                $chunks[] = $current;
                $current = [];
            }
            $current[] = $type;
        }
        if ($current !== []) {
            $chunks[] = $current;
        }

        return $chunks;
    }
}
