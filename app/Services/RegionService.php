<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Country-wide geography: all regions of the Republic of Belarus.
 *
 * Region codes are stable API values stored in the database. Short codes are used
 * in volunteer badge numbers (e.g. BY-BRE-2026-0007).
 */
class RegionService
{
    /** @var array<string, array{name:string, short:string, center_lat:float, center_lon:float}> */
    public const REGIONS = [
        'MINSK_CITY' => [
            'name' => 'г. Минск',
            'short' => 'MSK-SOV',
            'center_lat' => 53.9168,
            'center_lon' => 27.5862,
        ],
        'MINSK_OBLAST' => [
            'name' => 'Минская область',
            'short' => 'MIN',
            'center_lat' => 53.9168,
            'center_lon' => 27.5862,
        ],
        'BREST' => [
            'name' => 'Брестская область',
            'short' => 'BRE',
            'center_lat' => 52.0976,
            'center_lon' => 23.7341,
        ],
        'VITEBSK' => [
            'name' => 'Витебская область',
            'short' => 'VIT',
            'center_lat' => 55.1904,
            'center_lon' => 30.2049,
        ],
        'GOMEL' => [
            'name' => 'Гомельская область',
            'short' => 'GOM',
            'center_lat' => 52.4345,
            'center_lon' => 30.9754,
        ],
        'GRODNO' => [
            'name' => 'Гродненская область',
            'short' => 'GRO',
            'center_lat' => 53.6667,
            'center_lon' => 23.8167,
        ],
        'MOGILEV' => [
            'name' => 'Могилёвская область',
            'short' => 'MOG',
            'center_lat' => 53.9006,
            'center_lon' => 30.3319,
        ],
    ];

    public const DEFAULT_CODE = 'MINSK_CITY';

    public static function all(): array
    {
        $result = [];
        foreach (self::REGIONS as $code => $region) {
            $result[] = [
                'code' => $code,
                'name' => $region['name'],
                'short' => $region['short'],
                'center_lat' => $region['center_lat'],
                'center_lon' => $region['center_lon'],
            ];
        }
        return $result;
    }

    public static function exists(?string $code): bool
    {
        return is_string($code) && isset(self::REGIONS[strtoupper($code)]);
    }

    public static function name(?string $code): string
    {
        $code = strtoupper((string)$code);
        return self::REGIONS[$code]['name'] ?? self::REGIONS[self::DEFAULT_CODE]['name'];
    }

    public static function short(?string $code): string
    {
        $code = strtoupper((string)$code);
        return self::REGIONS[$code]['short'] ?? self::REGIONS[self::DEFAULT_CODE]['short'];
    }

    /**
     * @return array{lat:float, lon:float}
     */
    public static function center(?string $code): array
    {
        $code = strtoupper((string)$code);
        $center = self::REGIONS[$code] ?? self::REGIONS[self::DEFAULT_CODE];
        return ['lat' => $center['center_lat'], 'lon' => $center['center_lon']];
    }

    /**
     * Resolve a region from an explicit code or from coordinates (nearest regional centre).
     */
    public static function normalize(?string $code, ?float $lat = null, ?float $lon = null): string
    {
        if (self::exists($code)) {
            return strtoupper((string)$code);
        }
        if ($lat !== null && $lon !== null) {
            return self::nearest($lat, $lon);
        }
        return self::DEFAULT_CODE;
    }

    public static function nearest(float $lat, float $lon): string
    {
        $best = self::DEFAULT_CODE;
        $bestDistance = PHP_FLOAT_MAX;
        foreach (self::REGIONS as $code => $region) {
            $distance = self::distanceKm($lat, $lon, $region['center_lat'], $region['center_lon']);
            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $code;
            }
        }
        return $best;
    }

    private static function distanceKm(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $earthRadius = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2
            + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
    }
}
