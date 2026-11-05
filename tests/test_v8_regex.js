/**
 * Adversarial V8 / Node.js Stress Test for Voice Emergency Regex
 * File: public/js/speech.js line 89
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

// 1. Positive Test Suite (MUST return true)
const positiveSuite = [
    // Direct keywords
    'помощь',
    'помогите',
    'тревога',
    'сос',
    'sos',
    'спасите',
    'срочно помощь',
    'вызовите помощь',

    // Case insensitivity
    'ПОМОЩЬ',
    'ПОМОГИТЕ',
    'ТРЕВОГА',
    'СОС',
    'SOS',
    'СПАСИТЕ',
    'Помощь',
    'Помогите',
    'Тревога',
    'Спасите',
    'Срочно Помощь',
    'Вызовите Помощь',
    'Срочно помощь',
    'ВЫЗОВИТЕ ПОМОЩЬ',

    // Leading and trailing punctuation
    '!помощь!',
    '?помогите?',
    '...тревога...',
    ',,sos,,',
    ';спасите;',
    '---сос---',
    '#помощь#',
    '*тревога*',
    'помощь.',
    'помогите!',
    'sos?!',
    'помощь,',
    ':тревога:',
    '~спасите~',
    'помогите!!!',

    // Cyrillic & typographical quotes
    '«помощь»',
    '„помощь“',
    '“помогите”',
    '‘тревога’',
    '\'sos\'',
    '"спасите"',
    '««помощь»»',
    '‹тревога›',
    '»помощь«',

    // Parentheses, brackets, and braces
    '(помощь)',
    '[помогите]',
    '{тревога}',
    '(SOS)',
    '((спасите))',
    '<помощь>',
    '({[помогите]})',

    // Whitespace variants (tabs, newlines, non-breaking spaces)
    '   помощь   ',
    '\tтревога\t',
    '\nспасите\n',
    '\r\nпомогите\r\n',
    '\u00A0помогите\u00A0', // Non-breaking space
    '\u2003сос\u2003',     // Em space
    '\u2002тревога\u2002', // En space

    // Realistic speech recognition sentences
    'Мне нужна помощь, упал на пол',
    'Помогите мне пожалуйста',
    'Объявлена тревога',
    'Посылаю сигнал SOS, плохо с сердцем',
    'Люди добрые, спасите!',
    'Бабушке срочно помощь требуется',
    'Скорее вызовите помощь',
    'Внимание, (тревога) в квартире',
    'Кричу: «помогите»!',
    'Срочно, помощь нужна немедленно',
    'SOS-сигнал отправлен',
    'скорая-помощь',
    'вызовите помощь пожалуйста'
];

// 2. Negative Test Suite (False Positives - MUST return false)
const negativeSuite = [
    // Substrings of помощь
    'беспомощный',
    'беспомощная',
    'беспомощность',
    'беспомощное',
    'беспомощные',
    'помощник',
    'помощница',
    'помощники',
    'помощнику',
    'помощника',
    'помощником',
    'помощниках',
    'взаимопомощь',
    'самопомощь',
    'первопомощник',
    'скоропомощник',

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
    'спасительное',
    'спасение',

    // Substrings of сос / sos
    'соска',
    'сосочек',
    'насос',
    'пылесос',
    'засос',
    'осос',
    'сосна',
    'сосняк',
    'пососи',
    'sostav',
    'asos',
    'espresso',
    'visos',
    'mesos',
    'isosceles',
    'microdosos',

    // Ordinary non-emergency sentences
    'чистый воздух',
    'сегодня хорошая погода',
    'принесите хлеба и молока',
    'координатор ТЦСОН позвонил утром',
    'социальный работник пришёл вовремя',

    // Alphanumeric boundary collisions (embedded with letters or numbers)
    '1помощь',
    'помощь2',
    '123sos456',
    'aпомощь',
    'помощьb',
    'xсосy'
];

let totalPassed = 0;
let totalFailed = 0;
const failureDetails = [];

console.log('--- Testing Positive Cases (' + positiveSuite.length + ' cases) ---');
for (const phrase of positiveSuite) {
    const clean = phrase.toLowerCase();
    const isMatch = regex.test(clean);
    if (isMatch) {
        totalPassed++;
    } else {
        totalFailed++;
        const err = `[FAIL POSITIVE] Expected match for: "${phrase}" (clean: "${clean}")`;
        failureDetails.push(err);
        console.error('  ' + err);
    }
}

console.log('--- Testing Negative Cases (' + negativeSuite.length + ' cases) ---');
for (const phrase of negativeSuite) {
    const clean = phrase.toLowerCase();
    const isMatch = regex.test(clean);
    if (!isMatch) {
        totalPassed++;
    } else {
        totalFailed++;
        const err = `[FAIL NEGATIVE] False positive match for: "${phrase}" (clean: "${clean}")`;
        failureDetails.push(err);
        console.error('  ' + err);
    }
}

console.log('\n================================================================');
console.log(`V8 REGEX STRESS RESULTS: Total: ${positiveSuite.length + negativeSuite.length} | Passed: ${totalPassed} | Failed: ${totalFailed}`);
if (totalFailed > 0) {
    console.error('VERDICT: DEFECT_FOUND');
    process.exit(1);
} else {
    console.log('VERDICT: CONFIRM (Zero False Positives, Zero False Negatives)');
    process.exit(0);
}
