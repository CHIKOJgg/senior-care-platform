/**
 * Adversarial V8 / Node.js Stress Test for Voice Emergency Regex.
 *
 * Safety rule: ordinary words "помощь"/"помогите" must NOT trigger SOS by
 * themselves (a senior dictating "нужна помощь купить лекарства" would send a
 * false emergency). Only explicit emergency phrases may match.
 */

const fs = require('fs');
const path = require('path');

const speechJsPath = path.join(__dirname, '..', 'public', 'js', 'speech.js');
const speechJs = fs.readFileSync(speechJsPath, 'utf8');

const regexMatch = speechJs.match(/const\s+emergencyRegex\s*=\s*\/(.*?)\/([a-z]*);/);
if (!regexMatch) {
    console.error('FAIL: Could not locate emergencyRegex in ' + speechJsPath);
    process.exit(1);
}

const pattern = regexMatch[1];
const flags = regexMatch[2];
console.log(`[INFO] Extracted regex: /${pattern}/${flags}`);

const regex = new RegExp(pattern, flags);

// 1. Positive Test Suite (MUST return true) — explicit emergency phrases only.
const positiveSuite = [
    'тревога',
    'сос',
    'sos',
    'спасите',
    'вызовите помощь',
    'вызов помощи',
    'экстренная помощь',
    'срочная помощь',

    // Case insensitivity
    'ТРЕВОГА',
    'СОС',
    'SOS',
    'СПАСИТЕ',
    'Вызовите Помощь',
    'ЭКСТРЕННАЯ ПОМОЩЬ',

    // Leading and trailing punctuation
    '...тревога...',
    ',,sos,,',
    ';спасите;',
    '---сос---',
    '*тревога*',
    'sos?!',
    ':тревога:',

    // Cyrillic & typographical quotes
    '«тревога»',
    '“спасите”',
    '\'sos\'',
    '"спасите"',
    '(SOS)',
    '<тревога>',

    // Whitespace variants
    '\tтревога\t',
    '\nспасите\n',
    '\u00A0сос\u00A0',
    '\u2003тревога\u2003',

    // Realistic speech recognition sentences
    'Объявлена тревога',
    'Посылаю сигнал SOS, плохо с сердцем',
    'Люди добрые, спасите!',
    'Скорее вызовите помощь',
    'Внимание, (тревога) в квартире',
    'SOS-сигнал отправлен',
    'вызовите помощь пожалуйста',
    'Нужна экстренная помощь',
    'Это срочная помощь'
];

// 2. Negative Test Suite (False Positives - MUST return false)
const negativeSuite = [
    // Ordinary requests containing "помощь" must not trigger SOS.
    'помощь',
    'помогите',
    'Помощь',
    'Помогите',
    'Мне нужна помощь, упал на пол',
    'Помогите мне пожалуйста',
    'Бабушке срочно помощь требуется',
    'Кричу: «помогите»!',
    'Срочно, помощь нужна немедленно',
    'скорая-помощь',
    'нужна помощь купить лекарства',

    // Substrings of помощь
    'беспомощный',
    'беспомощная',
    'беспомощность',
    'помощник',
    'помощница',
    'взаимопомощь',
    'самопомощь',

    // Substrings of тревога
    'тревогатель',
    'тревожный',
    'тревожная',
    'тревожно',
    'растревожить',
    'встревоженный',
    'тревожность',

    // Substrings of спасите
    'спаситель',
    'спасительница',
    'спасительный',
    'спасение',

    // Substrings of сос / sos
    'соска',
    'сосочек',
    'насос',
    'пылесос',
    'засос',
    'сосна',
    'сосняк',
    'sostav',
    'asos',
    'espresso',

    // Ordinary non-emergency sentences
    'чистый воздух',
    'сегодня хорошая погода',
    'принесите хлеба и молока',
    'координатор ТЦСОН позвонил утром',
    'социальный работник пришёл вовремя',

    // Alphanumeric boundary collisions
    '1помощь',
    'помощь2',
    '123sos456',
    'aпомощь',
    'помощьb',
    'xсосy'
];

let totalPassed = 0;
let totalFailed = 0;

console.log('--- Testing Positive Cases (' + positiveSuite.length + ' cases) ---');
for (const phrase of positiveSuite) {
    const clean = phrase.toLowerCase();
    regex.lastIndex = 0;
    if (regex.test(clean)) {
        totalPassed++;
    } else {
        totalFailed++;
        console.error(`  [FAIL POSITIVE] Expected match for: "${phrase}" (clean: "${clean}")`);
    }
}

console.log('--- Testing Negative Cases (' + negativeSuite.length + ' cases) ---');
for (const phrase of negativeSuite) {
    const clean = phrase.toLowerCase();
    regex.lastIndex = 0;
    if (!regex.test(clean)) {
        totalPassed++;
    } else {
        totalFailed++;
        console.error(`  [FAIL NEGATIVE] False positive match for: "${phrase}" (clean: "${clean}")`);
    }
}

console.log('\n================================================================');
console.log(`V8 REGEX STRESS RESULTS: Total: ${positiveSuite.length + negativeSuite.length} | Passed: ${totalPassed} | Failed: ${totalFailed}`);
if (totalFailed > 0) {
    console.error('VERDICT: DEFECT_FOUND');
    process.exit(1);
}
console.log('VERDICT: CONFIRM (Zero False Positives, Zero False Negatives)');
process.exit(0);
