import assert from 'node:assert/strict';
import test from 'node:test';

import { toPlainChatText } from '../../assets/dist/plain_text.js';

test('toPlainChatText leaves plain prose untouched', () => {
    assert.equal(toPlainChatText('Stefan entwickelt Symfony-Anwendungen.'), 'Stefan entwickelt Symfony-Anwendungen.');
});

test('toPlainChatText removes double-asterisk emphasis', () => {
    assert.equal(toPlainChatText('Das ist **wichtig** und **auch das**.'), 'Das ist wichtig und auch das.');
});

test('toPlainChatText removes paired double-underscore emphasis', () => {
    assert.equal(toPlainChatText('Das ist __wichtig__.'), 'Das ist wichtig.');
});

test('toPlainChatText keeps PHP magic method names', () => {
    assert.equal(toPlainChatText('Im __construct() und in __invoke() wird injiziert.'), 'Im __construct() und in __invoke() wird injiziert.');
});

test('toPlainChatText drops a stray unclosed double asterisk', () => {
    assert.equal(toPlainChatText('Ein **halber Satz'), 'Ein halber Satz');
});

test('toPlainChatText turns dash and asterisk list markers into bullets', () => {
    assert.equal(
        toPlainChatText('Leistungen:\n- **Integration**: APIs\n* Wartung\n  - Updates'),
        'Leistungen:\n• Integration: APIs\n• Wartung\n  • Updates',
    );
});

test('toPlainChatText strips heading hashes at line start', () => {
    assert.equal(toPlainChatText('## Leistungen\nText mit #hashtag'), 'Leistungen\nText mit #hashtag');
});

test('toPlainChatText keeps a hyphen inside a line', () => {
    assert.equal(toPlainChatText('Symfony - und Laravel'), 'Symfony - und Laravel');
});
