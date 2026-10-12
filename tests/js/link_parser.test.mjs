import assert from 'node:assert/strict';
import test from 'node:test';

import { parseOwnOriginLinks } from '../../assets/dist/link_parser.js';

const ORIGIN = 'https://example.com';

test('linkifies a root-relative path', () => {
    assert.deepEqual(parseOwnOriginLinks('Mehr unter /contact', ORIGIN), [
        { type: 'text', text: 'Mehr unter ' },
        { type: 'link', text: '/contact', href: 'https://example.com/contact' },
    ]);
});

test('linkifies a same-origin absolute URL', () => {
    assert.deepEqual(parseOwnOriginLinks('Öffne https://example.com/en/contact', ORIGIN), [
        { type: 'text', text: 'Öffne ' },
        {
            type: 'link',
            text: 'https://example.com/en/contact',
            href: 'https://example.com/en/contact',
        },
    ]);
});

test('linkifies multiple internal links in their original order', () => {
    assert.deepEqual(parseOwnOriginLinks('Mehr unter /contact und https://example.com/en/team.', ORIGIN), [
        { type: 'text', text: 'Mehr unter ' },
        { type: 'link', text: '/contact', href: 'https://example.com/contact' },
        { type: 'text', text: ' und ' },
        { type: 'link', text: 'https://example.com/en/team', href: 'https://example.com/en/team' },
        { type: 'text', text: '.' },
    ]);
});

test('keeps an external URL as plain text', () => {
    assert.deepEqual(parseOwnOriginLinks('Extern https://example.org/contact', ORIGIN), [
        { type: 'text', text: 'Extern https://example.org/contact' },
    ]);
});

test('keeps a slash-separated technology name as plain text', () => {
    assert.deepEqual(parseOwnOriginLinks('Ich arbeite mit PHP/Symfony.', ORIGIN), [
        { type: 'text', text: 'Ich arbeite mit PHP/Symfony.' },
    ]);
});

test('keeps a slash-separated date as plain text', () => {
    assert.deepEqual(parseOwnOriginLinks('Heute ist der 28/07/2026.', ORIGIN), [
        { type: 'text', text: 'Heute ist der 28/07/2026.' },
    ]);
});

test('keeps an isolated slash in prose as plain text', () => {
    assert.deepEqual(parseOwnOriginLinks('A / B', ORIGIN), [
        { type: 'text', text: 'A / B' },
    ]);
});

test('keeps a path after an astral Unicode letter as plain text', () => {
    assert.deepEqual(parseOwnOriginLinks('𐐀/path', ORIGIN), [
        { type: 'text', text: '𐐀/path' },
    ]);
});

test('keeps trailing sentence punctuation outside the link', () => {
    assert.deepEqual(parseOwnOriginLinks('Grüße 👋: /contact.', ORIGIN), [
        { type: 'text', text: 'Grüße 👋: ' },
        { type: 'link', text: '/contact', href: 'https://example.com/contact' },
        { type: 'text', text: '.' },
    ]);
});

test('keeps URL delimiter characters around internal links as text', () => {
    assert.deepEqual(parseOwnOriginLinks('Siehe </contact> und \'/en/team\'!', ORIGIN), [
        { type: 'text', text: 'Siehe <' },
        { type: 'link', text: '/contact', href: 'https://example.com/contact' },
        { type: 'text', text: '> und \'' },
        { type: 'link', text: '/en/team', href: 'https://example.com/en/team' },
        { type: 'text', text: '\'!' },
    ]);
});

test('keeps a long slash-heavy prose string inert', () => {
    const text = `${'PHP/Symfony/'.repeat(20_000)}Ende`;

    assert.deepEqual(parseOwnOriginLinks(text, ORIGIN), [
        { type: 'text', text },
    ]);
});
