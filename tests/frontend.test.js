const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const script = fs.readFileSync(path.join(__dirname, '..', 'assets', 'js', 'website-watchdog-frontend.js'), 'utf8');

function captureError(message, stack, url = 'https://example.test/') {
    let body;
    const window = { location: { href: url } };
    const context = {
        window,
        location: window.location,
        navigator: { sendBeacon: (_endpoint, data) => { body = data; return true; } },
        Blob,
    };
    vm.runInNewContext(script, context);
    window.onerror(message, 'app.js', 1, 2, { stack });
    return { body, payload: JSON.parse(body) };
}

test('preserves long messages and stack lines when the request fits', () => {
    const message = 'm'.repeat(1000);
    const stack = Array.from({ length: 20 }, (_, i) => `frame-${i}`).join('\n');
    const { payload } = captureError(message, stack);

    assert.equal(payload.message, message);
    assert.equal(payload.stack, stack);
});

test('keeps the first ten and last five frames when the request is oversized', () => {
    const frames = Array.from({ length: 30 }, (_, i) => `frame-${i}-${'x'.repeat(600)}`);
    const message = 'm'.repeat(5500);
    const { body, payload } = captureError(message, frames.join('\n'));

    assert.ok(Buffer.byteLength(body) <= 16384);
    assert.equal(payload.message, message);
    assert.deepEqual(payload.stack.split('\n'), [...frames.slice(0, 10), '.........', ...frames.slice(-5)]);
});

test('bounds escaped fields and each retained frame for oversized requests', () => {
    const frames = Array.from({ length: 30 }, (_, i) => `frame-${i}-${'😀'.repeat(1000)}`);
    const { body, payload } = captureError('"'.repeat(20000), frames.join('\n'), 'https://example.test/' + '\\'.repeat(20000));
    const sentFrames = payload.stack.split('\n');

    assert.ok(Buffer.byteLength(body) <= 16384);
    assert.equal(sentFrames.length, 16);
    assert.equal(sentFrames[10], '.........');
    assert.ok(sentFrames[0].startsWith('frame-0-'));
    assert.ok(sentFrames[15].startsWith('frame-29-'));
    assert.ok(sentFrames[0].endsWith('...[truncated]'));
    assert.ok(payload.message.endsWith('...[truncated]'));
    assert.ok(payload.url.endsWith('...[truncated]'));
});
