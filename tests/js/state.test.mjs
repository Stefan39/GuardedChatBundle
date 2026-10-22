import assert from 'node:assert/strict';
import test from 'node:test';

import {
    isActionPayload,
    sessionRequestBody,
    isStoredState,
    isTextPayload,
    parseSsePayload,
    shouldShowGreeting,
    splitSseRecords,
    verificationErrorText,
} from '../../assets/dist/state.js';

test('shouldShowGreeting is true when both memory and DOM are empty', () => {
    assert.equal(shouldShowGreeting(0, 0), true);
});

test('shouldShowGreeting is false when messages are already in memory', () => {
    assert.equal(shouldShowGreeting(2, 0), false);
});

test('shouldShowGreeting is false when the DOM already holds a bubble despite an empty store', () => {
    assert.equal(shouldShowGreeting(0, 1), false);
});

test('shouldShowGreeting is false when both memory and DOM already hold messages', () => {
    assert.equal(shouldShowGreeting(3, 3), false);
});

test('splitSseRecords returns a single complete record and an empty rest', () => {
    assert.deepEqual(splitSseRecords('event: text\ndata: hi\n\n'), {
        records: ['event: text\ndata: hi'],
        rest: '',
    });
});

test('splitSseRecords returns two complete records in order', () => {
    assert.deepEqual(splitSseRecords('event: text\ndata: one\n\nevent: text\ndata: two\n\n'), {
        records: ['event: text\ndata: one', 'event: text\ndata: two'],
        rest: '',
    });
});

test('splitSseRecords keeps an incomplete trailing record as rest', () => {
    assert.deepEqual(splitSseRecords('event: text\ndata: one\n\nevent: text\ndata: par'), {
        records: ['event: text\ndata: one'],
        rest: 'event: text\ndata: par',
    });
});

test('splitSseRecords accepts \\r\\n\\r\\n as a record separator', () => {
    assert.deepEqual(splitSseRecords('event: text\r\ndata: hi\r\n\r\n'), {
        records: ['event: text\r\ndata: hi'],
        rest: '',
    });
});

test('splitSseRecords handles an empty buffer', () => {
    assert.deepEqual(splitSseRecords(''), { records: [], rest: '' });
});

test('parseSsePayload parses valid JSON', () => {
    assert.deepEqual(parseSsePayload('{"text":"hi"}'), { text: 'hi' });
});

test('parseSsePayload returns null for invalid JSON', () => {
    assert.equal(parseSsePayload('{not json'), null);
});

test('isTextPayload accepts a payload with only a string text field', () => {
    assert.equal(isTextPayload({ text: 'hi' }), true);
});

test('isTextPayload rejects a payload missing the text field', () => {
    assert.equal(isTextPayload({}), false);
});

test('isTextPayload rejects a payload with an unexpected extra key', () => {
    assert.equal(isTextPayload({ text: 'hi', extra: 'nope' }), false);
});

test('isTextPayload rejects a payload whose text is not a string', () => {
    assert.equal(isTextPayload({ text: 42 }), false);
});

test('isActionPayload accepts a payload with type contact', () => {
    assert.equal(isActionPayload({ type: 'contact' }), true);
});

test('isActionPayload rejects a payload missing the type field', () => {
    assert.equal(isActionPayload({}), false);
});

test('isActionPayload rejects a payload with an unexpected extra key', () => {
    assert.equal(isActionPayload({ type: 'contact', extra: 'nope' }), false);
});

test('isActionPayload accepts any configured action name', () => {
    assert.equal(isActionPayload({ type: 'book-demo' }), true);
});

test('isActionPayload rejects names that are not plain identifiers', () => {
    assert.equal(isActionPayload({ type: '<script>' }), false);
    assert.equal(isActionPayload({ type: '' }), false);
    assert.equal(isActionPayload({ type: 42 }), false);
});

const VALID_STATE = {
    sessionStarted: true,
    messages: [
        { role: 'user', text: 'hi' },
        { role: 'assistant', text: 'hello', action: 'contact' },
    ],
    isOpen: false,
};

test('isStoredState accepts a well-formed state', () => {
    assert.equal(isStoredState(VALID_STATE), true);
});

test('isStoredState rejects a state missing sessionStarted', () => {
    assert.equal(isStoredState({ messages: [], isOpen: false }), false);
});

test('isStoredState rejects a state missing messages', () => {
    assert.equal(isStoredState({ sessionStarted: true, isOpen: false }), false);
});

test('isStoredState rejects a state missing isOpen', () => {
    assert.equal(isStoredState({ sessionStarted: true, messages: [] }), false);
});

test('isStoredState rejects a state whose sessionStarted is not a boolean', () => {
    assert.equal(isStoredState({ sessionStarted: 'yes', messages: [], isOpen: false }), false);
});

test('isStoredState rejects a state whose messages is not an array', () => {
    assert.equal(isStoredState({ sessionStarted: true, messages: {}, isOpen: false }), false);
});

test('isStoredState rejects a state whose isOpen is not a boolean', () => {
    assert.equal(isStoredState({ sessionStarted: true, messages: [], isOpen: 'no' }), false);
});

test('isStoredState rejects a state with an unexpected extra key', () => {
    assert.equal(isStoredState({ sessionStarted: true, messages: [], isOpen: false, extra: 'nope' }), false);
});

test('isStoredState rejects null', () => {
    assert.equal(isStoredState(null), false);
});

test('isStoredState rejects a non-object', () => {
    assert.equal(isStoredState('not an object'), false);
});

test('isStoredState rejects a state whose messages array contains an entry missing text', () => {
    assert.equal(isStoredState({ sessionStarted: true, messages: [{ role: 'user' }], isOpen: false }), false);
});

test('isStoredState rejects a state whose messages array contains an entry with an invalid role', () => {
    assert.equal(
        isStoredState({ sessionStarted: true, messages: [{ role: 'system', text: 'hi' }], isOpen: false }),
        false,
    );
});

test('isStoredState rejects a state whose messages array contains an entry with an invalid action', () => {
    assert.equal(
        isStoredState({
            sessionStarted: true,
            messages: [{ role: 'assistant', text: 'hi', action: '<img onerror=x>' }],
            isOpen: false,
        }),
        false,
    );
});

test('isStoredState rejects a state whose messages array contains an entry with an unexpected extra key', () => {
    assert.equal(
        isStoredState({ sessionStarted: true, messages: [{ role: 'user', text: 'hi', extra: 'nope' }], isOpen: false }),
        false,
    );
});

test('sessionRequestBody carries the verification token, honeypot and locale', () => {
    assert.deepEqual(sessionRequestBody('tok', '', 'de'), { verificationToken: 'tok', website: '', locale: 'de' });
});

test('verificationErrorText shows a string the verify listener rejected with', () => {
    assert.equal(verificationErrorText('Bitte Werbeblocker deaktivieren.', 'fallback'), 'Bitte Werbeblocker deaktivieren.');
});

test('verificationErrorText shows the userMessage of a rejection reason', () => {
    const reason = Object.assign(new Error('turnstile script failed'), { userMessage: 'Die Prüfung konnte nicht geladen werden.' });
    assert.equal(verificationErrorText(reason, 'fallback'), 'Die Prüfung konnte nicht geladen werden.');
});

test('verificationErrorText never shows a technical Error message', () => {
    assert.equal(verificationErrorText(new Error('turnstile script failed'), 'fallback'), 'fallback');
});

test('verificationErrorText falls back for empty or non-string messages', () => {
    assert.equal(verificationErrorText('', 'fallback'), 'fallback');
    assert.equal(verificationErrorText({ userMessage: '' }, 'fallback'), 'fallback');
    assert.equal(verificationErrorText({ userMessage: 42 }, 'fallback'), 'fallback');
    assert.equal(verificationErrorText(undefined, 'fallback'), 'fallback');
    assert.equal(verificationErrorText(null, 'fallback'), 'fallback');
});
