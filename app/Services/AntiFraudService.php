<?php

namespace App\Services;

class AntiFraudService
{
    private static array $stopPatterns = [
        'danger' => [
            'код из смс' => 'Запрос одноразового СМС-пароля. Настоящие сотрудники банков НИКОГДА его не спрашивают!',
            'три цифры' => 'Запрос CVV/CVC кода с обратной стороны карточки. Это данные для кражи ваших денег!',
            'cvv' => 'Попытка узнать защитный код банковской карты.',
            'номер карты' => 'Запрос полных данных платёжной карты.',
            'безопасный счет' => 'Классическая фраза мошенников: «переведите деньги на безопасный счёт». Таких счетов не существует!',
            'следственный комитет' => 'Запугивание уголовным делом и проверкой счетов. Спецслужбы не звонят по видеосвязи.',
            'депозит под высокий' => 'Финансовая пирамида или мошенническая схема.'
        ],
        'warning' => [
            'viber' => 'Звонок якобы от банка в мессенджере. Банки не звонят через Вайбер!',
            'телеграм' => 'Звонок от незнакомцев в Telegram с логотипом госорганов.',
            'проверка счетчиков' => 'Навязывание ненужных платных услуг по завышенным ценам.',
            'срочно переведите' => 'Искусственное создание паники и спешки.'
        ]
    ];

    public static function analyze(string $text): array
    {
        $lower = mb_strtolower($text);
        $foundDangers = [];
        $foundWarnings = [];

        foreach (self::$stopPatterns['danger'] as $keyword => $reason) {
            if (mb_strpos($lower, $keyword) !== false) {
                $foundDangers[] = [
                    'pattern' => $keyword,
                    'advice' => $reason
                ];
            }
        }

        foreach (self::$stopPatterns['warning'] as $keyword => $reason) {
            if (mb_strpos($lower, $keyword) !== false) {
                $foundWarnings[] = [
                    'pattern' => $keyword,
                    'advice' => $reason
                ];
            }
        }

        $riskLevel = 'safe';
        $summary = 'Подозрительных признаков мошенничества не обнаружено. Однако сохраняйте бдительность!';

        if (!empty($foundDangers)) {
            $riskLevel = 'danger';
            $summary = 'ВНИМАНИЕ! Обнаружены признаки опасного мошенничества! Немедленно прекратите диалог и ни в коем случае не передавайте данные!';
        } elseif (!empty($foundWarnings)) {
            $riskLevel = 'warning';
            $summary = 'Будьте осторожны! Сообщение содержит подозрительные фразы. Перепроверьте информацию в официальной соцслужбе или банке.';
        }

        return [
            'risk_level' => $riskLevel,
            'summary' => $summary,
            'danger_matches' => $foundDangers,
            'warning_matches' => $foundWarnings
        ];
    }
}
