<?php

declare(strict_types=1);

namespace App\Services;

class DataMaskingService
{
    /**
     * Mask Belarusian and international phone numbers per Law 99-Z
     * Belarus: +375 (XX) ***-**-YY
     * Russia:  +7 (XXX) ***-**-YY
     * Other:   +CC (AA) ***-**-YY
     */
    public static function maskPhone(?string $phone): string
    {
        if ($phone === null) {
            return '';
        }

        $trimmed = trim($phone);
        if ($trimmed === '') {
            return '';
        }

        $digits = preg_replace('/\D/', '', $trimmed);

        // Normalize Belarusian domestic '80' prefix to '375'
        if (str_starts_with($digits, '80') && strlen($digits) === 11) {
            $digits = '375' . substr($digits, 2);
        } elseif (strlen($digits) === 9 && in_array(substr($digits, 0, 2), ['29', '33', '44', '25', '17'], true)) {
            $digits = '375' . $digits;
        }

        // Belarusian phone: 375 + 2-digit operator/city code + 7 digits
        if (str_starts_with($digits, '375') && strlen($digits) === 12) {
            $code = substr($digits, 3, 2);
            $last2 = substr($digits, -2);
            return "+375 ({$code}) ***-**-{$last2}";
        }

        // Russian / Kazakhstan numbers
        if (str_starts_with($digits, '8') && strlen($digits) === 11 && !str_starts_with($digits, '80')) {
            $digits = '7' . substr($digits, 1);
        }
        if (str_starts_with($digits, '7') && strlen($digits) === 11) {
            $code = substr($digits, 1, 3);
            $last2 = substr($digits, -2);
            return "+7 ({$code}) ***-**-{$last2}";
        }

        // General international
        if (strlen($digits) >= 10) {
            $countryLen = (str_starts_with($digits, '1') || str_starts_with($digits, '7')) ? 1 : (strlen($digits) > 11 ? 3 : 2);
            $cc = substr($digits, 0, $countryLen);
            $area = substr($digits, $countryLen, 2);
            $last2 = substr($digits, -2);
            return "+{$cc} ({$area}) ***-**-{$last2}";
        }

        if (strlen($digits) >= 4) {
            return substr($digits, 0, 2) . '***' . substr($digits, -2);
        }

        return '***';
    }

    /**
     * Mask apartment number and interior access info in address string
     * Preserves street and building number for routing/geofencing.
     */
    public static function maskAddress(?string $address): string
    {
        if ($address === null) {
            return '';
        }

        $trimmed = trim($address);
        if ($trimmed === '' || str_contains($trimmed, 'кв. ***')) {
            return $trimmed;
        }

        // Pattern 1: Entrance/floor/intercom + apartment (e.g. 'под. 2, эт. 4, кв. 12', 'квартира 105', 'комн. 5')
        $patternApt = '/(?:,?\s*(?:под(?:ъезд|\.)?\s*\d+|эт(?:аж|\.)?\s*\d+|дом(?:офон|\.)?\s*[^,]+))*,?\s*(?:кв(?:артира|\.)?|комн(?:ата|\.)?)\s*\d+(?:,?\s*дом(?:офон|\.)?\s*[^,]+)*/iu';
        if (preg_match($patternApt, $trimmed)) {
            $masked = preg_replace($patternApt, ', кв. ***', $trimmed);
            return self::cleanPunctuation((string)$masked);
        }

        // Pattern 2: Hyphenated house-apartment format (e.g. '15-42', '45-12', '29/2-88')
        $patternHyphen = '/(\b\d+[a-zA-Zа-яА-Я\/\d]*)\s*-\s*(\d+)\b/u';
        if (preg_match($patternHyphen, $trimmed)) {
            $masked = preg_replace_callback($patternHyphen, fn($m) => $m[1] . ', кв. ***', $trimmed);
            return self::cleanPunctuation((string)$masked);
        }

        return $trimmed;
    }

    private static function cleanPunctuation(string $text): string
    {
        $text = (string)preg_replace('/,\s*,+/', ',', $text);
        $text = (string)preg_replace('/\s+/', ' ', $text);
        return trim($text, " \t\n\r\0\x0B,");
    }

    /**
     * Authorization check: can current user view unmasked request details?
     */
    public static function canViewUnmasked(array $request, ?array $currentUser): bool
    {
        if (!$currentUser) {
            return false;
        }

        $roleCode = $currentUser['role_code'] ?? 'user';

        // 1. Coordinators and Admins
        if (in_array($roleCode, ['coordinator', 'admin'], true)) {
            return true;
        }

        $userId = (int)($currentUser['user_id'] ?? $currentUser['id'] ?? 0);

        // 2. Author of the request (elderly user)
        if ($roleCode === 'elderly' && isset($request['elderly_id']) && (int)$request['elderly_id'] === $userId) {
            return true;
        }

        // 3. Assigned volunteer in active in_progress task
        if ($roleCode === 'volunteer' &&
            isset($request['volunteer_id']) &&
            (int)$request['volunteer_id'] === $userId &&
            isset($request['status']) &&
            $request['status'] === 'in_progress'
        ) {
            return true;
        }

        return false;
    }

    /**
     * Mask sensitive fields in request array if viewer is unauthorized
     */
    public static function maskRequest(array $request, ?array $currentUser): array
    {
        if (self::canViewUnmasked($request, $currentUser)) {
            return $request;
        }

        if (isset($request['elderly_phone'])) {
            $request['elderly_phone'] = self::maskPhone((string)$request['elderly_phone']);
        }

        if (isset($request['address'])) {
            $request['address'] = self::maskAddress((string)$request['address']);
        }

        if (isset($request['volunteer_phone']) && !empty($request['volunteer_phone'])) {
            $request['volunteer_phone'] = self::maskPhone((string)$request['volunteer_phone']);
        }

        return $request;
    }
}
