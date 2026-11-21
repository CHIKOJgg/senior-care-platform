const fs = require('fs');
const path = require('path');

const rootDir = path.resolve(__dirname, '..');
const adrDir = path.join(rootDir, 'docs', 'adr');
const publicDir = path.join(rootDir, 'public');

const adrFiles = [
    'README.md',
    '0001-php82-custom-mvc.md',
    '0002-vanilla-js-frontend-wcag.md',
    '0003-database-sqlite-mysql.md',
    '0004-pdo-idempotent-migrations.md',
    '0005-hmac-auth-and-rate-limiting.md',
    '0006-zero-composer-custom-libraries.md',
    '0007-aes256gcm-and-data-masking.md',
    '0008-identity-provider-adapters.md',
    '0009-emergency-112-adapter.md',
    '0010-observability-stack.md',
    '0011-docker-caddy-nginx-phpfpm.md',
    '0012-custom-test-harness.md',
    '0013-backups-secrets-healthchecks.md',
    '0014-web-speech-and-audio-api.md',
    '0015-anti-fraud-keyword-ai-fallback.md'
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

console.log('=== 1. Verifying ADR File Sizes (>500 bytes) ===');
for (const file of adrFiles) {
    const fullPath = path.join(adrDir, file);
    if (!fs.existsSync(fullPath)) {
        assert(false, `FILE-EXISTS: ${file}`, 'File does not exist');
        continue;
    }
    const stat = fs.statSync(fullPath);
    assert(stat.size > 500, `FILE-SIZE: ${file}`, `Size: ${stat.size} bytes (min: 501 bytes)`);
}

console.log('\n=== 2. Verifying key ADR content ===');
const adr3 = fs.readFileSync(path.join(adrDir, '0003-database-sqlite-mysql.md'), 'utf8');
const adr5 = fs.readFileSync(path.join(adrDir, '0005-hmac-auth-and-rate-limiting.md'), 'utf8');
assert(adr3.includes('SQLite'), 'ADR03-SQLITE', 'Contains SQLite');
assert(adr3.includes('MySQL'), 'ADR03-MYSQL', 'Contains MySQL');
assert(adr5.includes('HMAC'), 'ADR05-HMAC', 'Contains HMAC');
assert(adr5.includes('token_version'), 'ADR05-REVOCATION', 'Contains token_version revocation');
assert(adr5.includes('RBAC'), 'ADR05-RBAC', 'Contains RBAC');
const adr15 = fs.readFileSync(path.join(adrDir, '0015-anti-fraud-keyword-ai-fallback.md'), 'utf8');
assert(adr15.includes('OpenRouter'), 'ADR15-OPENROUTER', 'Contains OpenRouter');
assert(adr15.includes('fallback'), 'ADR15-FALLBACK', 'Contains keyword fallback');

console.log('\n=== 3. Verifying Zero Emojis in public/ and docs/adr/ ===');
const emojiRegex = /[\u{1F600}-\u{1F64F}\u{1F300}-\u{1F5FF}\u{1F680}-\u{1F6FF}\u{1F700}-\u{1F77F}\u{1F780}-\u{1F7FF}\u{1F800}-\u{1F8FF}\u{1F900}-\u{1F9FF}\u{1FA00}-\u{1FA6F}\u{1FA70}-\u{1FAFF}\u{2600}-\u{26FF}\u{2700}-\u{27BF}]/u;

function checkEmojisInDir(dir, extFilter) {
    const stack = [dir];
    while (stack.length > 0) {
        const current = stack.pop();
        for (const entry of fs.readdirSync(current, { withFileTypes: true })) {
            const fullPath = path.join(current, entry.name);
            if (entry.isDirectory()) {
                stack.push(fullPath);
                continue;
            }
            const ext = path.extname(entry.name).toLowerCase();
            if (extFilter.includes(ext)) {
                const content = fs.readFileSync(fullPath, 'utf8');
                const matches = content.match(new RegExp(emojiRegex, 'gu'));
                const count = matches ? matches.length : 0;
                assert(count === 0, `ZERO-EMOJI: ${path.relative(rootDir, fullPath)}`, `Found ${count} emojis`);
            }
        }
    }
}

checkEmojisInDir(adrDir, ['.md']);
checkEmojisInDir(publicDir, ['.php', '.html', '.css', '.js']);

console.log('\n================================================================');
console.log(`SUMMARY: Total: ${passed + failed} | Passed: ${passed} | Failed: ${failed}`);
if (failed > 0) {
    process.exit(1);
} else {
    console.log('VERDICT: CONFIRM (ADR Suite & Zero-Emoji Integrity 100% Verified)');
    process.exit(0);
}
