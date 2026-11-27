<?php

namespace App\Support\Integrations\Aws;

use App\Support\Integrations\Servers\ServerLocation;

/**
 * The places AWS's commercial regions are in. DescribeRegions only gives
 * codes, so the city and country come from here; a code that isn't here
 * yet shows as itself.
 */
final class AwsRegions
{
    /** @var array<string, array{0: string, 1: string}> */
    private const array PLACES = [
        'us-east-1' => ['N. Virginia', 'US'],
        'us-east-2' => ['Ohio', 'US'],
        'us-west-1' => ['N. California', 'US'],
        'us-west-2' => ['Oregon', 'US'],
        'ca-central-1' => ['Montreal', 'CA'],
        'ca-west-1' => ['Calgary', 'CA'],
        'mx-central-1' => ['Querétaro', 'MX'],
        'sa-east-1' => ['São Paulo', 'BR'],
        'eu-west-1' => ['Ireland', 'IE'],
        'eu-west-2' => ['London', 'GB'],
        'eu-west-3' => ['Paris', 'FR'],
        'eu-central-1' => ['Frankfurt', 'DE'],
        'eu-central-2' => ['Zurich', 'CH'],
        'eu-north-1' => ['Stockholm', 'SE'],
        'eu-south-1' => ['Milan', 'IT'],
        'eu-south-2' => ['Spain', 'ES'],
        'il-central-1' => ['Tel Aviv', 'IL'],
        'me-south-1' => ['Bahrain', 'BH'],
        'me-central-1' => ['UAE', 'AE'],
        'af-south-1' => ['Cape Town', 'ZA'],
        'ap-south-1' => ['Mumbai', 'IN'],
        'ap-south-2' => ['Hyderabad', 'IN'],
        'ap-east-1' => ['Hong Kong', 'HK'],
        'ap-east-2' => ['Taipei', 'TW'],
        'ap-northeast-1' => ['Tokyo', 'JP'],
        'ap-northeast-2' => ['Seoul', 'KR'],
        'ap-northeast-3' => ['Osaka', 'JP'],
        'ap-southeast-1' => ['Singapore', 'SG'],
        'ap-southeast-2' => ['Sydney', 'AU'],
        'ap-southeast-3' => ['Jakarta', 'ID'],
        'ap-southeast-4' => ['Melbourne', 'AU'],
        'ap-southeast-5' => ['Malaysia', 'MY'],
        'ap-southeast-7' => ['Thailand', 'TH'],
    ];

    /** @var array<string, string> */
    private const array ZONES = [
        'us' => 'North America',
        'ca' => 'North America',
        'mx' => 'North America',
        'sa' => 'South America',
        'eu' => 'Europe',
        'il' => 'Middle East',
        'me' => 'Middle East',
        'af' => 'Africa',
        'ap' => 'Asia Pacific',
    ];

    public static function valid(string $code): bool
    {
        return preg_match('/^[a-z]{2}(-[a-z]+)+-\d{1,2}$/', $code) === 1;
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::PLACES);
    }

    public static function location(string $code): ServerLocation
    {
        [$city, $country] = self::PLACES[$code] ?? [$code, ''];

        return new ServerLocation($code, $city, $country, self::ZONES[substr($code, 0, 2)] ?? null);
    }

    public static function label(string $code): string
    {
        return isset(self::PLACES[$code]) ? self::PLACES[$code][0].' ('.$code.')' : $code;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $code) => ['value' => $code, 'label' => self::label($code)], self::codes());
    }

    /**
     * Known regions in this list's order, then any others by code.
     *
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function sort(array $codes): array
    {
        $order = array_flip(self::codes());
        usort($codes, fn (string $a, string $b) => [$order[$a] ?? PHP_INT_MAX, $a] <=> [$order[$b] ?? PHP_INT_MAX, $b]);

        return array_values(array_unique($codes));
    }
}
