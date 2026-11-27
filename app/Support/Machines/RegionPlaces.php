<?php

namespace App\Support\Machines;

final class RegionPlaces
{
    /** @var array<string, array{0: string, 1: string}> */
    private const array CITIES = [
        'ashburn' => ['Ashburn', 'United States'],
        'amsterdam' => ['Amsterdam', 'Netherlands'],
        'atlanta' => ['Atlanta', 'United States'],
        'bangalore' => ['Bangalore', 'India'],
        'beauharnois' => ['Beauharnois', 'Canada'],
        'chicago' => ['Chicago', 'United States'],
        'dallas' => ['Dallas', 'United States'],
        'delhi' => ['Delhi', 'India'],
        'falkenstein' => ['Falkenstein', 'Germany'],
        'frankfurt' => ['Frankfurt', 'Germany'],
        'fremont' => ['Fremont', 'United States'],
        'gravelines' => ['Gravelines', 'France'],
        'helsinki' => ['Helsinki', 'Finland'],
        'hillsboro' => ['Hillsboro', 'United States'],
        'honolulu' => ['Honolulu', 'United States'],
        'iowa' => ['Council Bluffs', 'United States'],
        'ireland' => ['Dublin', 'Ireland'],
        'johannesburg' => ['Johannesburg', 'South Africa'],
        'london' => ['London', 'United Kingdom'],
        'losangeles' => ['Los Angeles', 'United States'],
        'madrid' => ['Madrid', 'Spain'],
        'melbourne' => ['Melbourne', 'Australia'],
        'mexico' => ['Mexico City', 'Mexico'],
        'miami' => ['Miami', 'United States'],
        'milan' => ['Milan', 'Italy'],
        'montreal' => ['Montreal', 'Canada'],
        'mumbai' => ['Mumbai', 'India'],
        'newark' => ['Newark', 'United States'],
        'newyork' => ['New York', 'United States'],
        'nuremberg' => ['Nuremberg', 'Germany'],
        'ohio' => ['Columbus', 'United States'],
        'oregon' => ['The Dalles', 'United States'],
        'osaka' => ['Osaka', 'Japan'],
        'paris' => ['Paris', 'France'],
        'roubaix' => ['Roubaix', 'France'],
        'santiago' => ['Santiago', 'Chile'],
        'saopaulo' => ['São Paulo', 'Brazil'],
        'seattle' => ['Seattle', 'United States'],
        'seoul' => ['Seoul', 'South Korea'],
        'sanfrancisco' => ['San Francisco', 'United States'],
        'sanjose' => ['San Jose', 'United States'],
        'singapore' => ['Singapore', 'Singapore'],
        'stockholm' => ['Stockholm', 'Sweden'],
        'strasbourg' => ['Strasbourg', 'France'],
        'sydney' => ['Sydney', 'Australia'],
        'telaviv' => ['Tel Aviv', 'Israel'],
        'tokyo' => ['Tokyo', 'Japan'],
        'toronto' => ['Toronto', 'Canada'],
        'vinthill' => ['Vint Hill', 'United States'],
        'virginia' => ['Ashburn', 'United States'],
        'warsaw' => ['Warsaw', 'Poland'],
        'zurich' => ['Zurich', 'Switzerland'],
    ];

    /** @var array<string, string> */
    private const array CODES = [
        'fsn1' => 'falkenstein',
        'nbg1' => 'nuremberg',
        'hel1' => 'helsinki',
        'ash' => 'ashburn',
        'hil' => 'hillsboro',
        'sin' => 'singapore',
        'nyc1' => 'newyork',
        'nyc2' => 'newyork',
        'nyc3' => 'newyork',
        'sfo1' => 'sanfrancisco',
        'sfo2' => 'sanfrancisco',
        'sfo3' => 'sanfrancisco',
        'ams1' => 'amsterdam',
        'ams2' => 'amsterdam',
        'ams3' => 'amsterdam',
        'sgp1' => 'singapore',
        'lon1' => 'london',
        'fra1' => 'frankfurt',
        'tor1' => 'toronto',
        'blr1' => 'bangalore',
        'syd1' => 'sydney',
        'atl1' => 'atlanta',
        'ewr' => 'newark',
        'ord' => 'chicago',
        'dfw' => 'dallas',
        'sea' => 'seattle',
        'lax' => 'losangeles',
        'atl' => 'atlanta',
        'ams' => 'amsterdam',
        'lhr' => 'london',
        'fra' => 'frankfurt',
        'sjc' => 'sanjose',
        'syd' => 'sydney',
        'nrt' => 'tokyo',
        'cdg' => 'paris',
        'icn' => 'seoul',
        'mia' => 'miami',
        'sgp' => 'singapore',
        'waw' => 'warsaw',
        'mad' => 'madrid',
        'sto' => 'stockholm',
        'bom' => 'mumbai',
        'jnb' => 'johannesburg',
        'mex' => 'mexico',
        'scl' => 'santiago',
        'tlv' => 'telaviv',
        'blr' => 'bangalore',
        'del' => 'delhi',
        'itm' => 'osaka',
        'mel' => 'melbourne',
        'yto' => 'toronto',
        'hnl' => 'honolulu',
        'sao' => 'saopaulo',
        'gra' => 'gravelines',
        'sbg' => 'strasbourg',
        'rbx' => 'roubaix',
        'bhs' => 'beauharnois',
        'vin' => 'vinthill',
        'lim' => 'frankfurt',
        'de1' => 'frankfurt',
        'uk1' => 'london',
        'gra1' => 'gravelines',
        'sbg1' => 'strasbourg',
        'bhs1' => 'beauharnois',
        'waw1' => 'warsaw',
        'us-east' => 'newark',
        'us-central' => 'dallas',
        'us-west' => 'fremont',
        'us-southeast' => 'atlanta',
        'us-ord' => 'chicago',
        'us-sea' => 'seattle',
        'us-iad' => 'ashburn',
        'us-lax' => 'losangeles',
        'us-mia' => 'miami',
        'ca-central' => 'toronto',
        'eu-west' => 'london',
        'eu-central' => 'frankfurt',
        'ap-south' => 'singapore',
        'ap-northeast' => 'tokyo',
        'ap-west' => 'mumbai',
        'ap-southeast' => 'sydney',
        'us-east-1' => 'virginia',
        'us-east-2' => 'ohio',
        'us-west-1' => 'sanfrancisco',
        'us-west-2' => 'oregon',
        'ca-central-1' => 'montreal',
        'eu-west-1' => 'ireland',
        'eu-west-2' => 'london',
        'eu-west-3' => 'paris',
        'eu-central-1' => 'frankfurt',
        'eu-central-2' => 'zurich',
        'eu-north-1' => 'stockholm',
        'eu-south-1' => 'milan',
        'eu-south-2' => 'madrid',
        'ap-southeast-1' => 'singapore',
        'ap-southeast-2' => 'sydney',
        'ap-northeast-1' => 'tokyo',
        'ap-northeast-2' => 'seoul',
        'ap-northeast-3' => 'osaka',
        'ap-south-1' => 'mumbai',
        'sa-east-1' => 'saopaulo',
        'af-south-1' => 'johannesburg',
        'il-central-1' => 'telaviv',
        'us-central1' => 'iowa',
        'europe-west1' => 'amsterdam',
        'europe-west2' => 'london',
        'europe-west3' => 'frankfurt',
        'europe-west4' => 'amsterdam',
        'europe-north1' => 'helsinki',
        'asia-southeast1' => 'singapore',
        'asia-northeast1' => 'tokyo',
    ];

    /** @return array{city: string, country: string}|null */
    public static function of(?string ...$codes): ?array
    {
        foreach ($codes as $code) {
            if ($code === null || trim($code) === '') {
                continue;
            }
            foreach (self::candidates($code) as $key) {
                $city = self::CODES[$key] ?? (isset(self::CITIES[$key]) ? $key : null);
                if ($city !== null) {
                    return ['city' => self::CITIES[$city][0], 'country' => self::CITIES[$city][1]];
                }
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function candidates(string $code): array
    {
        $clean = strtolower(trim($code));
        $first = explode('-dc', $clean)[0];
        $parts = [$clean, preg_split('/[\s\/]/', $clean)[0] ?? $clean, $first, (string) preg_replace('/[a-z]$/', '', $first), (string) preg_replace('/\d+$/', '', $first)];

        return array_values(array_unique(array_filter($parts, fn (string $part) => $part !== '')));
    }
}
