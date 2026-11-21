<?php

declare(strict_types=1);

namespace App\Services;

class GeoMatchingService
{
    public const EARTH_RADIUS_KM = 6371.0;
    public const EARTH_RADIUS_METERS = 6371000.0;
    public const DEFAULT_GEOFENCE_RADIUS_METERS = 150.0;





    public static function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);


        $a = max(0.0, min(1.0, $a));

        $c = 2 * atan2(sqrt($a), sqrt(1.0 - $a));

        return round(self::EARTH_RADIUS_KM * $c, 4);
    }




    public static function calculateDistanceMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);

        $a = max(0.0, min(1.0, $a));

        $c = 2 * atan2(sqrt($a), sqrt(1.0 - $a));

        return round(self::EARTH_RADIUS_METERS * $c, 2);
    }




    public static function isWithinGeofence(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2,
        float $radiusMeters = self::DEFAULT_GEOFENCE_RADIUS_METERS
    ): bool {
        return self::calculateDistanceMeters($lat1, $lon1, $lat2, $lon2) <= $radiusMeters;
    }




    public static function isWithinRadius(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2,
        float $radiusMeters = self::DEFAULT_GEOFENCE_RADIUS_METERS
    ): bool {
        return self::isWithinGeofence($lat1, $lon1, $lat2, $lon2, $radiusMeters);
    }




    public static function validateSecretWord(string $entered, string $expected): bool
    {
        return SecurityService::verifyCode($entered, $expected);
    }




    public static function rankVolunteers(array $request, array $volunteers, float $maxRadiusKm = 5.0): array
    {
        $ranked = [];

        foreach ($volunteers as $v) {
            $dist = self::calculateDistance(
                (float)$request['latitude'],
                (float)$request['longitude'],
                (float)$v['latitude'],
                (float)$v['longitude']
            );

            if ($dist > $maxRadiusKm) {
                continue;
            }


            $wDist = 0.5;
            $wRating = 0.3;
            $wKarma = 0.2;

            $distScore = max(0, 1 - ($dist / $maxRadiusKm));
            $ratingScore = min(1.0, ((float)$v['rating']) / 5.0);
            $karmaScore = min(1.0, ((int)$v['points']) / 500.0);

            $totalScore = ($wDist * $distScore) + ($wRating * $ratingScore) + ($wKarma * $karmaScore);

            $v['distance_km'] = $dist;
            $v['distance_meters'] = (int)($dist * 1000);
            $v['match_score'] = round($totalScore * 100, 1);

            $ranked[] = $v;
        }

        usort($ranked, fn($a, $b) => $b['match_score'] <=> $a['match_score']);

        return $ranked;
    }
}
