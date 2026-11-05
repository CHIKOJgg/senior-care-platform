/**
 * Independent verification of Plan Suite and Zero-Emoji compliance
 */

const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const planDir = path.join(rootDir, 'plan');
const publicDir = path.join(rootDir, 'public');

const planFiles = [
    '01_SYSTEM_OVERVIEW.md',
    '02_GEOFENCING_AND_SECURITY.md',
    '03_VOLUNTEER_VERIFICATION_AND_BADGES.md',
    '04_DATA_PROTECTION_AND_PRIVACY.md',
    '05_EMERGENCY_SOS_AND_WATCHDOG.md',
    '06_DATABASE_MIGRATIONS.md',
    '07_REST_API_SPECIFICATION.md',
    '08_TESTING_AND_AUDIT.md'
];

let failed = 0;
let passed = 0;

function assert(condition, name, details) {
    if (condition) {
        passed++;
        console.log(`  [PASS] ${name} :: ${details}`);
    } else {
        failed++;
        console.error(`  [FAIL] ${name} :: ${details}`);
    }
}

console.log('=== 1. Verifying Plan Suite File Sizes (>5,000 bytes) ===');
for (const file of planFiles) {
    const fullPath = path.join(planDir, file);
    if (!fs.existsSync(fullPath)) {
        assert(false, `FILE-EXISTS: ${file}`, 'File does not exist');
        continue;
    }
    const stat = fs.statSync(fullPath);
    assert(stat.size > 5000, `FILE-SIZE: ${file}`, `Size: ${stat.size} bytes (min: 5001 bytes)`);
}

console.log('\n=== 2. Verifying plan/05 keywords (3600, 7200, 10800) ===');
const plan05Path = path.join(planDir, '05_EMERGENCY_SOS_AND_WATCHDOG.md');
const plan05Content = fs.readFileSync(plan05Path, 'utf8');
assert(plan05Content.includes('3600'), 'PLAN05-3600', 'Contains 3600 seconds timeout');
assert(plan05Content.includes('7200'), 'PLAN05-7200', 'Contains 7200 seconds timeout');
assert(plan05Content.includes('10800'), 'PLAN05-10800', 'Contains 10800 seconds timeout');

console.log('\n=== 3. Verifying plan/07 OpenAPI keyword ===');
const plan07Path = path.join(planDir, '07_REST_API_SPECIFICATION.md');
const plan07Content = fs.readFileSync(plan07Path, 'utf8');
assert(plan07Content.includes('OpenAPI'), 'PLAN07-OPENAPI', 'Contains OpenAPI specification reference');
assert(plan07Content.includes('openapi: 3.0.3'), 'PLAN07-OPENAPI-SPEC', 'Contains openapi: 3.0.3 YAML blueprint');

console.log('\n=== 4. Verifying Zero Emojis in public/ and plan/ ===');
const emojiRegex = /[\u{1F600}-\u{1F64F}\u{1F300}-\u{1F5FF}\u{1F680}-\u{1F6FF}\u{1F700}-\u{1F77F}\u{1F780}-\u{1F7FF}\u{1F800}-\u{1F8FF}\u{1F900}-\u{1F9FF}\u{1FA00}-\u{1FA6F}\u{1FA70}-\u{1FAFF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/u;

function checkEmojisInDir(dir, extFilter) {
    const entries = fs.readdirSync(dir, { withFileTypes: true, recursive: true });
    for (const entry of entries) {
        if (entry.isFile()) {
            const ext = path.extname(entry.name).toLowerCase();
            if (extFilter.includes(ext)) {
                const fullPath = path.join(entry.parentPath || dir, entry.name);
                const content = fs.readFileSync(fullPath, 'utf8');
                const matches = content.match(new RegExp(emojiRegex, 'gu'));
                const count = matches ? matches.length : 0;
                assert(count === 0, `ZERO-EMOJI: ${path.relative(rootDir, fullPath)}`, `Found ${count} emojis`);
            }
        }
    }
}

checkEmojisInDir(planDir, ['.md']);
checkEmojisInDir(publicDir, ['.php', '.html', '.css', '.js']);

console.log('\n================================================================');
console.log(`SUMMARY: Total: ${passed + failed} | Passed: ${passed} | Failed: ${failed}`);
if (failed > 0) {
    process.exit(1);
} else {
    console.log('VERDICT: CONFIRM (Plan Suite & Zero-Emoji Integrity 100% Verified)');
    process.exit(0);
}
