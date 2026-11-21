




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


const positiveSuite = [

    'помощь',
    'помогите',
    'тревога',
    'сос',
    'sos',
    'спасите',
    'срочно помощь',
    'вызовите помощь',


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


    '«помощь»',
    '„помощь“',
    '“помогите”',
    '‘тревога’',
    '\'sos\'',
    '"спасите"',
    '««помощь»»',
    '‹тревога›',
    '»помощь«',


    '(помощь)',
    '[помогите]',
    '{тревога}',
    '(SOS)',
    '((спасите))',
    '<помощь>',
    '({[помогите]})',


    '   помощь   ',
    '\tтревога\t',
    '\nспасите\n',
    '\r\nпомогите\r\n',
    '\u00A0помогите\u00A0',
    '\u2003сос\u2003',
    '\u2002тревога\u2002',


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


const negativeSuite = [

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


    'тревогатель',
    'тревожный',
    'тревожная',
    'тревожно',
    'растревожить',
    'встревоженный',
    'тревожность',


    'спаситель',
    'спасительница',
    'спасительный',
    'спасительное',
    'спасение',


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


    'чистый воздух',
    'сегодня хорошая погода',
    'принесите хлеба и молока',
    'координатор ТЦСОН позвонил утром',
    'социальный работник пришёл вовремя',


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
