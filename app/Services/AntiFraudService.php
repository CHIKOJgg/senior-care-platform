<?php

namespace App\Services;

use App\Core\Logger;

class AntiFraudService
{
    private static array $stopPatterns = [
        'danger' => [
            'код из смс' => 'Запрос одноразового СМС-пароля. Настоящие сотрудники банков НИКОГДА его не спрашивают!',
            'код из sms' => 'Запрос одноразового СМС-пароля. Настоящие сотрудники банков НИКОГДА его не спрашивают!',
            'смс код' => 'Запрос одноразового СМС-пароля. Настоящие сотрудники банков НИКОГДА его не спрашивают!',
            'смс-код' => 'Запрос одноразового СМС-пароля. Настоящие сотрудники банков НИКОГДА его не спрашивают!',
            'sms код' => 'Запрос одноразового СМС-пароля. Настоящие сотрудники банков НИКОГДА его не спрашивают!',
            'код подтверждения' => 'Запрос проверочного кода. Никому не сообщайте коды из сообщений!',
            'три цифры' => 'Запрос CVV/CVC кода с обратной стороны карты. Это конфиденциальные данные для списания средств!',
            'cvv' => 'Попытка узнать защитный код банковской карты.',
            'номер карты' => 'Запрос полных реквизитов платёжной карты.',
            'безопасный счет' => 'Классическая фраза мошенников: «переведите деньги на безопасный счёт». Таких счетов не существует!',
            'безопасный счёт' => 'Классическая фраза мошенников: «переведите деньги на безопасный счёт». Таких счетов не существует!',
            'следственный комитет' => 'Запугивание проверками и уголовными делами. Спецслужбы не звонят гражданам в мессенджерах!',
            'депозит под высокий' => 'Финансовая пирамида или мошенническая схема.'
        ],
        'warning' => [
            'viber' => 'Звонок якобы от банка через Вайбер. Официальные банки никогда не звонят через Viber!',
            'вайбер' => 'Звонок якобы от банка через Вайбер. Официальные банки никогда не звонят через Viber!',
            'telegram' => 'Звонок или сообщение в мессенджере с логотипом банка или госорганов.',
            'телеграм' => 'Звонок или сообщение в мессенджере с логотипом банка или госорганов.',
            'проверка счетчик' => 'Навязывание сомнительных платных услуг по замене или поверке приборов.',
            'проверка счётчик' => 'Навязывание сомнительных платных услуг по замене или поверке приборов.',
            'срочно переведите' => 'Искусственное создание паники, спешки и эмоционального давления.'
        ]
    ];

    public static function analyze(string $text): array
    {
        $apiKey = trim((string)(getenv('OPENROUTER_API_KEY') ?: ''));
        if ($apiKey !== '') {
            try {
                $ai = self::analyzeWithOpenRouter($text, $apiKey);
                if ($ai !== null) {
                    return $ai;
                }
                Logger::warning('AntiFraud: OpenRouter returned invalid response, falling back to keywords');
            } catch (\Throwable $e) {
                Logger::warning('AntiFraud: OpenRouter analysis failed, falling back to keywords', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return self::analyzeWithKeywords($text);
    }

    public static function analyzeWithKeywords(string $text): array
    {
        $lower = mb_strtolower($text);
        $foundDangers = [];
        $foundWarnings = [];

        foreach (self::$stopPatterns['danger'] as $keyword => $advice) {
            if (mb_strpos($lower, $keyword) !== false) {
                $foundDangers[] = [
                    'pattern' => $keyword,
                    'advice' => $advice
                ];
            }
        }

        foreach (self::$stopPatterns['warning'] as $keyword => $advice) {
            if (mb_strpos($lower, $keyword) !== false) {
                $foundWarnings[] = [
                    'pattern' => $keyword,
                    'advice' => $advice
                ];
            }
        }

        $riskLevel = 'safe';
        $summary = 'Подозрительных признаков мошенничества не обнаружено. Однако всегда сохраняйте бдительность!';

        if (!empty($foundDangers)) {
            $riskLevel = 'danger';
            $summary = 'ВНИМАНИЕ! Обнаружены признаки опасного мошенничества! Немедленно положите трубку и ни в коем случае не передавайте данные!';
        } elseif (!empty($foundWarnings)) {
            $riskLevel = 'warning';
            $summary = 'Будьте осторожны! В сообщении обнаружены подозрительные фразы. Перепроверьте информацию в официальном банке или соцслужбе.';
        }

        return [
            'risk_level' => $riskLevel,
            'summary' => $summary,
            'danger_matches' => $foundDangers,
            'warning_matches' => $foundWarnings
        ];
    }

    private static function analyzeWithOpenRouter(string $text, string $apiKey): ?array
    {
        $baseUrl = rtrim((string)(getenv('OPENROUTER_BASE_URL') ?: 'https://openrouter.ai/api/v1'), '/');
        $model = trim((string)(getenv('OPENROUTER_MODEL') ?: 'openai/gpt-4o-mini'));
        $endpoint = $baseUrl . '/chat/completions';

        $systemPrompt = <<<PROMPT
Ты — эксперт по защите пожилых людей от телефонного и интернет-мошенничества в Беларуси и СНГ.
Проанализируй текст сообщения/звонка и оцени риск мошенничества.
Ответь ТОЛЬКО валидным JSON без markdown и пояснений вне JSON.
Формат ответа строго такой:
{
  "risk_level": "safe|warning|danger",
  "summary": "Короткий понятный итог для пожилого человека на русском языке",
  "danger_matches": [{"pattern": "фраза/признак", "advice": "почему опасно и что делать"}],
  "warning_matches": [{"pattern": "фраза/признак", "advice": "на что обратить внимание"}]
}
Правила:
- risk_level: danger при явных признаках мошенничества (запрос кодов/карты/CVV, "безопасный счет", давление, выдача за банк/МВД); warning при подозрительных признаках; safe если признаков нет.
- summary должен быть кратким, спокойным и понятным пожилому человеку.
- danger_matches и warning_matches должны быть массивами (можно пустыми).
- Не выдумывай факты, которых нет в тексте; опирайся на текст.
PROMPT;

        $payload = [
            'model' => $model,
            'temperature' => 0.1,
            'max_tokens' => 700,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => "Текст для анализа:\n" . mb_substr($text, 0, 4000)],
            ],
        ];

        $ch = curl_init($endpoint);
        if ($ch === false) {
            throw new \RuntimeException('Failed to initialize curl');
        }

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'HTTP-Referer: ' . (getenv('APP_URL') ?: 'http://localhost'),
            'X-Title: Senior Care Platform Anti-Fraud',
        ];

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            throw new \RuntimeException('OpenRouter request failed: ' . $err);
        }
        if ($status < 200 || $status >= 300) {
            Logger::warning('AntiFraud: OpenRouter HTTP error', ['status' => $status, 'body' => mb_substr((string)$raw, 0, 500)]);
            return null;
        }

        $decoded = json_decode((string)$raw, true);
        if (!is_array($decoded)) {
            return null;
        }

        $content = $decoded['choices'][0]['message']['content'] ?? null;
        if (is_array($content)) {

            $parts = [];
            foreach ($content as $part) {
                if (is_string($part)) {
                    $parts[] = $part;
                } elseif (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $parts[] = $part['text'];
                }
            }
            $content = implode("\n", $parts);
        }
        if (!is_string($content) || trim($content) === '') {
            return null;
        }

        return self::parseAiResult($content);
    }

    private static function parseAiResult(string $content): ?array
    {
        $content = trim($content);

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/si', $content, $m)) {
            $content = $m[1];
        }
        $json = json_decode($content, true);
        if (!is_array($json)) {

            if (preg_match('/(\{(?:[^{}]|(?1))*\})/s', $content, $m)) {
                $json = json_decode($m[1], true);
            }
        }
        if (!is_array($json)) {
            return null;
        }

        $risk = $json['risk_level'] ?? null;
        if (!in_array($risk, ['safe', 'warning', 'danger'], true)) {
            return null;
        }

        $summary = trim((string)($json['summary'] ?? ''));
        if ($summary === '') {
            $summary = self::defaultSummary($risk);
        }

        $dangerMatches = self::normalizeMatches($json['danger_matches'] ?? []);
        $warningMatches = self::normalizeMatches($json['warning_matches'] ?? []);


        if ($risk === 'safe' && (!empty($dangerMatches) || !empty($warningMatches))) {
            $risk = !empty($dangerMatches) ? 'danger' : 'warning';
        }

        return [
            'risk_level' => $risk,
            'summary' => $summary,
            'danger_matches' => $dangerMatches,
            'warning_matches' => $warningMatches,
        ];
    }

    private static function normalizeMatches(mixed $matches): array
    {
        if (!is_array($matches)) {
            return [];
        }
        $out = [];
        foreach ($matches as $m) {
            if (is_string($m)) {
                $m = ['pattern' => $m, 'advice' => 'Будьте осторожны, это может быть признаком мошенничества.'];
            }
            if (!is_array($m)) {
                continue;
            }
            $pattern = trim((string)($m['pattern'] ?? $m['match'] ?? $m['text'] ?? ''));
            $advice = trim((string)($m['advice'] ?? $m['reason'] ?? $m['description'] ?? ''));
            if ($pattern === '') {
                continue;
            }
            if ($advice === '') {
                $advice = 'Будьте осторожны, это может быть признаком мошенничества.';
            }
            $out[] = [
                'pattern' => mb_substr($pattern, 0, 120),
                'advice' => mb_substr($advice, 0, 300),
            ];
            if (count($out) >= 8) {
                break;
            }
        }
        return $out;
    }

    private static function defaultSummary(string $risk): string
    {
        return match ($risk) {
            'danger' => 'ВНИМАНИЕ! Обнаружены признаки опасного мошенничества! Немедленно прекратите разговор и не передавайте данные.',
            'warning' => 'Будьте осторожны! В сообщении есть подозрительные признаки. Перепроверьте информацию через официальный источник.',
            default => 'Подозрительных признаков мошенничества не обнаружено. Однако всегда сохраняйте бдительность!',
        };
    }
}
