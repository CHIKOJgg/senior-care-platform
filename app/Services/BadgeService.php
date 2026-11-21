<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use InvalidArgumentException;
use App\Models\User;
use App\Models\VolunteerProfile;
use App\Core\Logger;
use App\Services\RegionService;

require_once __DIR__ . '/RegionService.php';

class BadgeService
{
    public const DEFAULT_TTL = 3600;
    public const DEFAULT_ORG = 'ТЦСОН Республики Беларусь';

    private static ?string $customSecret = null;
    private static array $gfExp = [];
    private static array $gfLog = [];




    public static function setSecret(?string $secret): void
    {
        self::$customSecret = $secret;
    }




    public static function getSecret(): string
    {
        if (self::$customSecret !== null && self::$customSecret !== '') {
            return self::$customSecret;
        }

        $secret = getenv('BADGE_HMAC_SECRET') ?: getenv('APP_SECRET') ?: '';
        if ($secret === '') {
            if ((getenv('APP_ENV') ?: 'local') === 'production') {
                throw new \RuntimeException('BADGE_HMAC_SECRET (or APP_SECRET) must be set in production');
            }
            $secret = 'local_dev_only_badge_secret_' . md5(__DIR__);
        }

        return $secret;
    }




    private static function regionShortForUser(int $userId): string
    {
        try {
            if (class_exists(VolunteerProfile::class)) {
                $vp = VolunteerProfile::find($userId);
                if ($vp && !empty($vp['region_code'])) {
                    return RegionService::short((string)$vp['region_code']);
                }
            }
        } catch (\Throwable $e) {

        }
        return RegionService::short('MINSK_CITY');
    }







    public static function formatPublicVolunteerName(string $raw): array
    {
        $raw = trim($raw);
        $parts = preg_split('/\s+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $firstName = 'Волонтёр';
        $patronymic = '';
        $lastInitial = '';

        if (count($parts) === 1) {
            $firstName = $parts[0];
        } elseif (count($parts) === 2) {
            if (preg_match('/^[А-ЯЁA-Z]\.?$/u', $parts[1])) {
                $firstName = $parts[0];
                $lastInitial = rtrim($parts[1], '.') . '.';
            } elseif (preg_match('/^[А-ЯЁA-Z]\.?$/u', $parts[0])) {
                $firstName = $parts[1];
                $lastInitial = rtrim($parts[0], '.') . '.';
            } else {
                $firstName = $parts[0];
                $lastInitial = mb_substr($parts[1], 0, 1, 'UTF-8') . '.';
            }
        } elseif (count($parts) >= 3) {
            $isPatronymic = function(string $s): bool {
                $lower = mb_strtolower($s, 'UTF-8');
                return str_ends_with($lower, 'вич') || str_ends_with($lower, 'вна') ||
                       str_ends_with($lower, 'тич') || str_ends_with($lower, 'чна') ||
                       str_ends_with($lower, 'ич');
            };

            if ($isPatronymic($parts[2])) {

                $lastName = $parts[0];
                $firstName = $parts[1];
                $patronymic = $parts[2];
                $lastInitial = mb_substr($lastName, 0, 1, 'UTF-8') . '.';
            } elseif ($isPatronymic($parts[1])) {

                $firstName = $parts[0];
                $patronymic = $parts[1];
                $lastName = $parts[2];
                $lastInitial = mb_substr($lastName, 0, 1, 'UTF-8') . '.';
            } else {
                $firstName = $parts[0];
                $patronymic = $parts[1];
                $lastInitial = mb_substr($parts[2], 0, 1, 'UTF-8') . '.';
            }
        }

        $displayName = $patronymic ? "{$firstName} {$patronymic} {$lastInitial}" : "{$firstName} {$lastInitial}";

        return [
            'first_name' => $firstName,
            'patronymic' => $patronymic,
            'last_initial' => $lastInitial,
            'display_name' => trim($displayName)
        ];
    }










    public static function generateBadgeToken(
        int $volunteerUserId,
        ?string $name = null,
        ?string $org = null,
        int $ttlSeconds = self::DEFAULT_TTL
    ): string {
        $now = time();
        $exp = $now + $ttlSeconds;

        $displayName = $name;
        $firstName = 'Волонтёр';
        $patronymic = '';
        $lastInitial = '';

        if ($displayName === null || $displayName === '') {
            try {
                if (class_exists(User::class)) {
                    $user = User::find($volunteerUserId);
                    if ($user && !empty($user['name'])) {
                        $parsed = self::formatPublicVolunteerName($user['name']);
                        $displayName = $parsed['display_name'];
                        $firstName = $parsed['first_name'];
                        $patronymic = $parsed['patronymic'];
                        $lastInitial = $parsed['last_initial'];
                    }
                }
            } catch (\Throwable $e) {

            }
            if ($displayName === null || $displayName === '') {
                $displayName = 'Волонтёр #' . $volunteerUserId;
            }
        } else {
            $parsed = self::formatPublicVolunteerName($displayName);
            $firstName = $parsed['first_name'];
            $patronymic = $parsed['patronymic'];
            $lastInitial = $parsed['last_initial'];
        }

        $organization = $org ?: self::DEFAULT_ORG;
        $badgeId = sprintf('BY-%s-%s-%04d', self::regionShortForUser($volunteerUserId), date('Y'), $volunteerUserId);

        $payload = [
            'vid' => $volunteerUserId,
            'user_id' => $volunteerUserId,
            'bid' => $badgeId,
            'name' => $displayName,
            'org' => $organization,
            'status' => 'VERIFIED',
            'iat' => $now,
            'exp' => $exp
        ];

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $payloadEncoded = rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
        $rawSignature = hash_hmac('sha256', $payloadEncoded, self::getSecret(), true);
        $signatureEncoded = rtrim(strtr(base64_encode($rawSignature), '+/', '-_'), '=');

        $fullToken = $payloadEncoded . '.' . $signatureEncoded;


        try {
            if (class_exists(VolunteerProfile::class)) {
                $vp = VolunteerProfile::find($volunteerUserId);
                if ($vp) {
                    VolunteerProfile::update($volunteerUserId, [
                        'badge_token' => $fullToken,
                        'badge_expires_at' => date('Y-m-d H:i:s', $exp)
                    ]);
                }
            }
        } catch (\Throwable $e) {

        }

        return $fullToken;
    }







    public static function verifyBadgeToken(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signature] = $parts;


        $expectedRaw = hash_hmac('sha256', $payloadEncoded, self::getSecret(), true);
        $expectedBase64Url = rtrim(strtr(base64_encode($expectedRaw), '+/', '-_'), '=');
        $expectedHex = hash_hmac('sha256', $payloadEncoded, self::getSecret(), false);

        $validSignature = hash_equals($expectedBase64Url, $signature) ||
                          hash_equals($expectedHex, $signature);

        if (!$validSignature) {
            return null;
        }


        $normalizedB64 = strtr($payloadEncoded, '-_', '+/');
        $padding = strlen($normalizedB64) % 4;
        if ($padding > 0) {
            $normalizedB64 .= str_repeat('=', 4 - $padding);
        }

        $json = base64_decode($normalizedB64, true);
        if ($json === false) {
            return null;
        }

        $payload = json_decode($json, true);
        if (!is_array($payload) || empty($payload['exp']) || (!isset($payload['vid']) && !isset($payload['user_id']))) {
            return null;
        }


        if (time() > (int)$payload['exp']) {
            return null;
        }


        if (isset($payload['iat']) && (time() + 60) < (int)$payload['iat']) {
            return null;
        }

        return $payload;
    }







    public static function verifyVolunteerBadge(string $token): array
    {
        $payload = self::verifyBadgeToken($token);
        if ($payload === null) {
            return [
                'valid' => false,
                'error' => 'QR-код устарел или недействителен'
            ];
        }

        $userId = (int)($payload['vid'] ?? $payload['user_id'] ?? 0);


        try {
            if (class_exists(VolunteerProfile::class) && class_exists(User::class)) {
                $profile = VolunteerProfile::find($userId);
                $user = User::find($userId);

                if (!$profile || !$user) {
                    return [
                        'valid' => false,
                        'error' => 'Профиль волонтёра не найден в реестре ТЦСОН'
                    ];
                }

                $isVerified = (int)($profile['is_verified'] ?? 0);
                $status = strtoupper((string)($profile['verification_status'] ?? ''));

                if ($isVerified !== 1 || $status === 'REJECTED' || $status === 'SUSPENDED') {
                    return [
                        'valid' => false,
                        'error' => 'Аккредитация волонтёра отозвана или приостановлена координатором ТЦСОН',
                        'verification_status' => 'REJECTED',
                        'status' => 'SUSPENDED'
                    ];
                }


                $parsed = self::formatPublicVolunteerName($user['name']);
                $firstName = $parsed['first_name'];
                $patronymic = $parsed['patronymic'];
                $lastInitial = $parsed['last_initial'];
                $displayName = $parsed['display_name'];
            } else {
                $displayName = $payload['name'] ?? 'Волонтёр';
                $parsed = self::formatPublicVolunteerName($displayName);
                $firstName = $parsed['first_name'];
                $patronymic = $parsed['patronymic'];
                $lastInitial = $parsed['last_initial'];
                $displayName = $parsed['display_name'];
            }
        } catch (\Throwable $e) {
            $displayName = $payload['name'] ?? 'Волонтёр';
            $parsed = self::formatPublicVolunteerName($displayName);
            $firstName = $parsed['first_name'];
            $patronymic = $parsed['patronymic'];
            $lastInitial = $parsed['last_initial'];
            $displayName = $parsed['display_name'];
        }

        return [
            'valid' => true,
            'volunteer' => [
                'first_name' => $firstName,
                'patronymic' => $patronymic,
                'last_initial' => $lastInitial,
                'full_display_name' => $displayName,
                'organization' => $payload['org'] ?? self::DEFAULT_ORG,
                'verification_status' => 'VERIFIED',
                'badge_issued_at' => (int)($payload['iat'] ?? time()),
                'expires_at' => (int)$payload['exp'],
                'badge_expires_at' => (int)$payload['exp']
            ]
        ];
    }








    public static function generateQrSvg(string $text, array $options = []): string
    {
        $size = (int)($options['size'] ?? 240);
        $margin = (int)($options['margin'] ?? 4);
        $color = (string)($options['color'] ?? '#1A365D');
        $bgColor = (string)($options['bgColor'] ?? '#FFFFFF');
        $title = (string)($options['title'] ?? 'QR-код верификации волонтёра ТЦСОН');

        self::initGf();
        $textLen = strlen($text);



        $versions = [
            1  => [19,  7,  [[1, 19]], [], 0],
            2  => [34,  10, [[1, 34]], [6, 18], 0],
            3  => [55,  15, [[1, 55]], [6, 22], 0],
            4  => [80,  20, [[1, 80]], [6, 26], 0],
            5  => [108, 26, [[1, 108]], [6, 30], 0],
            6  => [136, 18, [[2, 68]], [6, 34], 0],
            7  => [156, 20, [[2, 78]], [6, 22, 38], 0x07C94],
            8  => [194, 24, [[2, 97]], [6, 24, 42], 0x085BC],
            9  => [232, 30, [[2, 116]], [6, 26, 46], 0x09A99],
            10 => [274, 18, [[2, 68], [2, 69]], [6, 28, 50], 0x0A4D3],
            11 => [324, 20, [[4, 81]], [6, 30, 54], 0x0BEBC],
            12 => [370, 24, [[2, 92], [2, 93]], [6, 32, 58], 0x0C135],
            13 => [428, 26, [[4, 107]], [6, 34, 62], 0x0D0C6],
            14 => [461, 30, [[3, 115], [1, 116]], [6, 26, 46, 66], 0x0E40A],
            15 => [520, 22, [[5, 87], [1, 88]], [6, 26, 48, 70], 0x0F4FC],
            16 => [586, 24, [[5, 98], [1, 99]], [6, 30, 54, 78], 0x100D1],
            17 => [644, 28, [[1, 107], [5, 108]], [6, 30, 56, 82], 0x11024],
            18 => [718, 30, [[5, 120], [1, 121]], [6, 30, 58, 86], 0x12777],
            19 => [792, 28, [[3, 113], [4, 114]], [6, 34, 62, 90], 0x13782],
            20 => [858, 28, [[3, 107], [5, 108]], [6, 28, 50, 72, 94], 0x14EC9],
        ];

        $ver = null;
        foreach ($versions as $v => $info) {
            $countBits = $v >= 10 ? 16 : 8;
            $maxData = $info[0] - (int)ceil((4 + $countBits) / 8);
            if ($textLen <= $maxData) {
                $ver = $v;
                break;
            }
        }

        if (!$ver) {
            throw new InvalidArgumentException("Payload exceeds capacity of QR versions 1-14 ({$textLen} bytes)");
        }

        $vInfo = $versions[$ver];
        $totalDataBytes = $vInfo[0];
        $ecCount = $vInfo[1];
        $blockInfo = $vInfo[2];
        $alignPos = $vInfo[3];
        $verBits = $vInfo[4];
        $dim = 17 + 4 * $ver;


        $bits = '0100';
        $countBits = $ver >= 10 ? 16 : 8;
        $bits .= str_pad(decbin($textLen), $countBits, '0', STR_PAD_LEFT);
        for ($i = 0; $i < $textLen; $i++) {
            $bits .= str_pad(decbin(ord($text[$i])), 8, '0', STR_PAD_LEFT);
        }


        $totalBits = $totalDataBytes * 8;
        $remain = $totalBits - strlen($bits);
        $bits .= str_repeat('0', min(4, $remain));


        if (strlen($bits) % 8 !== 0) {
            $bits .= str_repeat('0', 8 - (strlen($bits) % 8));
        }


        $padBytes = [0xEC, 0x11];
        $pIdx = 0;
        while (strlen($bits) < $totalBits) {
            $bits .= str_pad(decbin($padBytes[$pIdx % 2]), 8, '0', STR_PAD_LEFT);
            $pIdx++;
        }


        $allDataBytes = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $allDataBytes[] = bindec(substr($bits, $i, 8));
        }


        $dataBlocks = [];
        $ecBlocks = [];
        $offset = 0;
        foreach ($blockInfo as [$numBlocks, $blockSize]) {
            for ($b = 0; $b < $numBlocks; $b++) {
                $d = array_slice($allDataBytes, $offset, $blockSize);
                $offset += $blockSize;
                $dataBlocks[] = $d;
                $ecBlocks[] = self::calculateReedSolomon($d, $ecCount);
            }
        }


        $interleaved = [];
        $maxDataLen = 0;
        foreach ($dataBlocks as $b) {
            $maxDataLen = max($maxDataLen, count($b));
        }
        for ($i = 0; $i < $maxDataLen; $i++) {
            foreach ($dataBlocks as $b) {
                if ($i < count($b)) {
                    $interleaved[] = $b[$i];
                }
            }
        }
        for ($i = 0; $i < $ecCount; $i++) {
            foreach ($ecBlocks as $b) {
                $interleaved[] = $b[$i];
            }
        }

        $finalBits = '';
        foreach ($interleaved as $byte) {
            $finalBits .= str_pad(decbin($byte), 8, '0', STR_PAD_LEFT);
        }
        if ($ver >= 2 && $ver <= 6) {
            $finalBits .= str_repeat('0', 7);
        } elseif ($ver >= 14 && $ver <= 20) {
            $finalBits .= str_repeat('0', 3);
        }


        $matrix = array_fill(0, $dim, array_fill(0, $dim, null));
        $isFunction = array_fill(0, $dim, array_fill(0, $dim, false));

        $setFinder = function(int $r0, int $c0) use (&$matrix, &$isFunction, $dim) {
            for ($r = -1; $r <= 7; $r++) {
                for ($c = -1; $c <= 7; $c++) {
                    $row = $r0 + $r;
                    $col = $c0 + $c;
                    if ($row >= 0 && $row < $dim && $col >= 0 && $col < $dim) {
                        $isBlack = ($r >= 0 && $r <= 6 && $c >= 0 && $c <= 6) &&
                                   ($r === 0 || $r === 6 || $c === 0 || $c === 6 || ($r >= 2 && $r <= 4 && $c >= 2 && $c <= 4));
                        $matrix[$row][$col] = $isBlack ? 1 : 0;
                        $isFunction[$row][$col] = true;
                    }
                }
            }
        };

        $setFinder(0, 0);
        $setFinder(0, $dim - 7);
        $setFinder($dim - 7, 0);


        for ($i = 8; $i < $dim - 8; $i++) {
            $val = ($i % 2 === 0) ? 1 : 0;
            if (!$isFunction[6][$i]) {
                $matrix[6][$i] = $val;
                $isFunction[6][$i] = true;
            }
            if (!$isFunction[$i][6]) {
                $matrix[$i][6] = $val;
                $isFunction[$i][6] = true;
            }
        }


        if (!empty($alignPos)) {
            $coords = [];
            foreach ($alignPos as $r) {
                foreach ($alignPos as $c) {
                    if (($r < 9 && $c < 9) || ($r < 9 && $c > $dim - 10) || ($r > $dim - 10 && $c < 9)) {
                        continue;
                    }
                    $coords[] = [$r, $c];
                }
            }
            foreach ($coords as [$ar, $ac]) {
                for ($r = -2; $r <= 2; $r++) {
                    for ($c = -2; $c <= 2; $c++) {
                        $isBlack = (abs($r) === 2 || abs($c) === 2 || ($r === 0 && $c === 0));
                        $matrix[$ar + $r][$ac + $c] = $isBlack ? 1 : 0;
                        $isFunction[$ar + $r][$ac + $c] = true;
                    }
                }
            }
        }


        $darkRow = 4 * $ver + 9;
        $matrix[$darkRow][8] = 1;
        $isFunction[$darkRow][8] = true;


        for ($i = 0; $i < 9; $i++) {
            $isFunction[8][$i] = true;
            $isFunction[$i][8] = true;
        }
        for ($i = $dim - 8; $i < $dim; $i++) {
            $isFunction[8][$i] = true;
        }
        for ($i = $dim - 7; $i < $dim; $i++) {
            $isFunction[$i][8] = true;
        }


        if ($ver >= 7) {
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $isFunction[$i][$dim - 11 + $j] = true;
                    $isFunction[$dim - 11 + $j][$i] = true;
                }
            }
        }


        $bitIdx = 0;
        $bitLen = strlen($finalBits);
        $col = $dim - 1;
        $goingUp = true;

        while ($col > 0) {
            if ($col === 6) {
                $col--;
            }
            $rowRange = $goingUp ? range($dim - 1, 0, -1) : range(0, $dim - 1);
            foreach ($rowRange as $row) {
                foreach ([$col, $col - 1] as $c) {
                    if (!$isFunction[$row][$c]) {
                        $bit = ($bitIdx < $bitLen) ? (int)$finalBits[$bitIdx] : 0;
                        $bitIdx++;
                        $maskVal = (($row + $c) % 2 === 0) ? 1 : 0;
                        $matrix[$row][$c] = $bit ^ $maskVal;
                    }
                }
            }
            $goingUp = !$goingUp;
            $col -= 2;
        }


        $fiBits = sprintf('%015b', 0x77C4);
        $coords1 = [
            [8, 0], [8, 1], [8, 2], [8, 3], [8, 4], [8, 5],
            [8, 7], [8, 8], [7, 8],
            [5, 8], [4, 8], [3, 8], [2, 8], [1, 8], [0, 8]
        ];
        $coords2 = [
            [8, $dim - 1], [8, $dim - 2], [8, $dim - 3], [8, $dim - 4], [8, $dim - 5], [8, $dim - 6], [8, $dim - 7], [8, $dim - 8],
            [$dim - 7, 8], [$dim - 6, 8], [$dim - 5, 8], [$dim - 4, 8], [$dim - 3, 8], [$dim - 2, 8], [$dim - 1, 8]
        ];
        for ($i = 0; $i < 15; $i++) {
            $val = (int)$fiBits[$i];
            $matrix[$coords1[$i][0]][$coords1[$i][1]] = $val;
            $matrix[$coords2[$i][0]][$coords2[$i][1]] = $val;
        }


        if ($ver >= 7 && $verBits) {
            $vbStr = sprintf('%018b', $verBits);
            for ($i = 0; $i < 18; $i++) {
                $val = (int)$vbStr[17 - $i];
                $r = (int)floor($i / 3);
                $c = $dim - 11 + ($i % 3);
                $matrix[$r][$c] = $val;
                $matrix[$c][$r] = $val;
            }
        }


        $viewSize = $dim + 2 * $margin;
        $pathD = '';
        for ($r = 0; $r < $dim; $r++) {
            $c = 0;
            while ($c < $dim) {
                if ($matrix[$r][$c] === 1) {
                    $start = $c;
                    while ($c < $dim && $matrix[$r][$c] === 1) {
                        $c++;
                    }
                    $len = $c - $start;
                    $x = $start + $margin;
                    $y = $r + $margin;
                    $pathD .= "M{$x},{$y}h{$len}v1h-{$len}z ";
                } else {
                    $c++;
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $viewSize . ' ' . $viewSize . '" width="' . $size . '" height="' . $size . '" shape-rendering="crispEdges" role="img" aria-label="' . htmlspecialchars($title) . '">';
        $svg .= '<title>' . htmlspecialchars($title) . '</title>';
        $svg .= '<rect width="100%" height="100%" fill="' . htmlspecialchars($bgColor) . '"/>';
        $svg .= '<path fill="' . htmlspecialchars($color) . '" d="' . trim($pathD) . '"/>';
        $svg .= '</svg>';

        return $svg;
    }




    public static function generateBadgeQrSvg(string $badgeToken, array $options = []): string
    {
        $appUrl = getenv('APP_URL') ?: 'https://zabotaryadom.by';
        $verificationUrl = rtrim($appUrl, '/') . '/api/volunteers/badge/' . $badgeToken;
        return self::generateQrSvg($verificationUrl, $options);
    }




    public static function generateBadgeSvg(
        int $volunteerUserId,
        ?string $badgeToken = null,
        array $options = []
    ): string {
        $token = $badgeToken ?: self::generateBadgeToken($volunteerUserId);
        $payload = self::verifyBadgeToken($token);

        $name = 'Волонтёр ТЦСОН';
        $bid = sprintf('BY-%s-%s-%04d', self::regionShortForUser($volunteerUserId), date('Y'), $volunteerUserId);
        $org = self::DEFAULT_ORG;
        $status = 'ВЕРИФИЦИРОВАН ТЦСОН';
        $expTimestamp = time() + self::DEFAULT_TTL;

        if ($payload) {
            $name = $payload['name'] ?? $name;
            $bid = $payload['bid'] ?? $bid;
            $org = $payload['org'] ?? $org;
            $expTimestamp = (int)($payload['exp'] ?? $expTimestamp);
        }

        $expiresAtFormatted = date('H:i:s d.m.Y', $expTimestamp);


        $qrSvg = self::generateBadgeQrSvg($token, [
            'size' => 240,
            'margin' => 3,
            'color' => '#0F172A',
            'bgColor' => '#FFFFFF',
            'title' => 'QR код проверки волонтёра ' . $name
        ]);

        $qrClean = preg_replace('/<\?xml[^>]*\?>/i', '', $qrSvg);

        $badge = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $badge .= '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 420 640" width="420" height="640" role="img" aria-label="Цифровой аккредитационный бейдж волонтёра ТЦСОН">';
        $badge .= '<defs>';
        $badge .= '<linearGradient id="headerGrad" x1="0%" y1="0%" x2="100%" y2="100%"><stop offset="0%" stop-color="#1E3A8A"/><stop offset="100%" stop-color="#0F172A"/></linearGradient>';
        $badge .= '<filter id="cardShadow" x="-5%" y="-5%" width="110%" height="110%"><feDropShadow dx="0" dy="4" stdDeviation="6" flood-opacity="0.12"/></filter>';
        $badge .= '</defs>';


        $badge .= '<rect x="10" y="10" width="400" height="620" rx="16" fill="#FFFFFF" stroke="#CBD5E1" stroke-width="2" filter="url(#cardShadow)"/>';


        $badge .= '<path d="M10 26 Q10 10 26 10 L394 10 Q410 10 410 26 L410 110 L10 110 Z" fill="url(#headerGrad)"/>';
        $badge .= '<rect x="10" y="108" width="400" height="4" fill="#D97706"/>';


        $badge .= '<g transform="translate(30, 28)" fill="none" stroke="#F8FAFC" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">';
        $badge .= '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>';
        $badge .= '<path d="m9 12 2 2 4-4" stroke="#F59E0B" stroke-width="2.5"/>';
        $badge .= '</g>';


        $badge .= '<text x="68" y="42" font-family="system-ui, -apple-system, sans-serif" font-size="11" font-weight="700" fill="#F59E0B" letter-spacing="1">ГОСУДАРСТВЕННАЯ СЛУЖБА ПОДДЕРЖКИ</text>';
        $badge .= '<text x="68" y="62" font-family="system-ui, -apple-system, sans-serif" font-size="14" font-weight="800" fill="#FFFFFF">' . htmlspecialchars($org) . '</text>';
        $badge .= '<text x="68" y="80" font-family="system-ui, -apple-system, sans-serif" font-size="11" font-weight="500" fill="#E2E8F0">Платформа взаимной помощи «ЗаботаРядом»</text>';


        $badge .= '<g transform="translate(100, 126)">';
        $badge .= '<rect x="0" y="0" width="220" height="28" rx="14" fill="#DCFCE7" stroke="#16A34A" stroke-width="1.5"/>';
        $badge .= '<circle cx="16" cy="14" r="6" fill="#16A34A"/>';
        $badge .= '<path d="m13 14 2 2 4-4" fill="none" stroke="#FFFFFF" stroke-width="1.5" stroke-linecap="round"/>';
        $badge .= '<text x="32" y="19" font-family="system-ui, sans-serif" font-size="12" font-weight="700" fill="#166534">' . htmlspecialchars($status) . '</text>';
        $badge .= '</g>';


        $badge .= '<text x="210" y="186" text-anchor="middle" font-family="system-ui, sans-serif" font-size="20" font-weight="800" fill="#0F172A">' . htmlspecialchars($name) . '</text>';
        $badge .= '<text x="210" y="206" text-anchor="middle" font-family="monospace, monospace" font-size="13" font-weight="600" fill="#475569">№ ' . htmlspecialchars($bid) . '</text>';
        $badge .= '<text x="210" y="225" text-anchor="middle" font-family="system-ui, sans-serif" font-size="12" font-weight="500" fill="#64748B">Официальный волонтёр платформы «ЗаботаРядом»</text>';


        $badge .= '<rect x="85" y="240" width="250" height="250" rx="12" fill="#F8FAFC" stroke="#E2E8F0" stroke-width="2"/>';


        $badge .= '<path d="M90 255 L90 245 L100 245" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round"/>';
        $badge .= '<path d="M330 255 L330 245 L320 245" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round"/>';
        $badge .= '<path d="M90 475 L90 485 L100 485" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round"/>';
        $badge .= '<path d="M330 475 L330 485 L320 485" fill="none" stroke="#94A3B8" stroke-width="2.5" stroke-linecap="round"/>';


        $badge .= '<g transform="translate(90, 245)">' . $qrClean . '</g>';


        $badge .= '<text x="210" y="508" text-anchor="middle" font-family="system-ui, sans-serif" font-size="11" font-weight="600" fill="#334155">Отсканируйте камерой смартфона</text>';
        $badge .= '<text x="210" y="522" text-anchor="middle" font-family="system-ui, sans-serif" font-size="10" font-weight="400" fill="#64748B">для мгновенной проверки аккредитации на портале</text>';


        $badge .= '<line x1="30" y1="538" x2="390" y2="538" stroke="#E2E8F0" stroke-width="1"/>';


        $badge .= '<g transform="translate(36, 548)" fill="none" stroke="#2563EB" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
        $badge .= '<circle cx="8" cy="8" r="7"/><polyline points="8 4 8 8 11 9"/>';
        $badge .= '</g>';
        $badge .= '<text x="58" y="560" font-family="system-ui, sans-serif" font-size="11" font-weight="600" fill="#1E293B">Динамический QR (TTL: 1 ч)</text>';
        $badge .= '<text x="58" y="574" font-family="system-ui, sans-serif" font-size="10" font-weight="500" fill="#64748B">Действителен до: ' . htmlspecialchars($expiresAtFormatted) . '</text>';


        $badge .= '<g transform="translate(245, 548)" fill="none" stroke="#059669" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">';
        $badge .= '<rect x="3" y="7" width="10" height="8" rx="2"/><path d="M5 7V4a3 3 0 0 1 6 0v3"/>';
        $badge .= '</g>';
        $badge .= '<text x="265" y="560" font-family="system-ui, sans-serif" font-size="11" font-weight="600" fill="#1E293B">HMAC-SHA256 подпись</text>';
        $badge .= '<text x="265" y="574" font-family="system-ui, sans-serif" font-size="10" font-weight="500" fill="#64748B">Защищено от копирования</text>';


        $badge .= '<rect x="10" y="596" width="400" height="34" rx="0" fill="#F1F5F9"/>';
        $badge .= '<path d="M10 610 L10 618 Q10 630 26 630 L394 630 Q410 630 410 618 L410 610 Z" fill="#F1F5F9"/>';
        $badge .= '<text x="210" y="618" text-anchor="middle" font-family="system-ui, sans-serif" font-size="10" font-weight="600" fill="#475569">ТЦСОН: +375 (17) 377-00-00 • Единый номер 8-801-100-8-801</text>';

        $badge .= '</svg>';
        return $badge;
    }





    private static function initGf(): void
    {
        if (!empty(self::$gfExp)) {
            return;
        }

        self::$gfExp = array_fill(0, 512, 0);
        self::$gfLog = array_fill(0, 256, 0);
        $val = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$gfExp[$i] = $val;
            self::$gfExp[$i + 255] = $val;
            self::$gfLog[$val] = $i;
            $val = ($val << 1) ^ (($val & 0x80) ? 0x11D : 0);
        }
    }

    private static function getGeneratorPoly(int $ecCount): array
    {
        $gen = [1];
        for ($i = 0; $i < $ecCount; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            for ($j = 0; $j < count($gen); $j++) {
                $next[$j] ^= $gen[$j];
                $next[$j + 1] ^= ($gen[$j] === 0) ? 0 : self::$gfExp[self::$gfLog[$gen[$j]] + $i];
            }
            $gen = $next;
        }
        return $gen;
    }

    private static function calculateReedSolomon(array $data, int $ecCount): array
    {
        $gen = self::getGeneratorPoly($ecCount);
        $msg = array_merge($data, array_fill(0, $ecCount, 0));
        $genCount = count($gen);
        $dataCount = count($data);

        for ($i = 0; $i < $dataCount; $i++) {
            $lead = $msg[$i];
            if ($lead !== 0) {
                $logLead = self::$gfLog[$lead];
                for ($j = 0; $j < $genCount; $j++) {
                    if ($gen[$j] !== 0) {
                        $msg[$i + $j] ^= self::$gfExp[self::$gfLog[$gen[$j]] + $logLead];
                    }
                }
            }
        }

        return array_slice($msg, $dataCount);
    }
}
