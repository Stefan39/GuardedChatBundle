// True when neither the in-memory transcript nor the already-rendered DOM contain a message yet.
// Checking both matters: Turbo Drive restores a cached DOM snapshot (including a previously
// rendered greeting bubble) on a back/forward visit, reconnecting the controller with a fresh,
// empty in-memory transcript. Relying on the message count alone would show the greeting a
// second time next to the cached one.
export function shouldShowGreeting(messageCount, renderedChildCount) {
    return 0 === messageCount && 0 === renderedChildCount;
}

// Splits an SSE buffer into complete `\r?\n\r?\n`-terminated records plus whatever partial record
// is still waiting on more chunks.
export function splitSseRecords(buffer) {
    const records = [];
    for (;;) {
        const separator = buffer.match(/\r?\n\r?\n/);
        if (null === separator || undefined === separator.index) {
            return { records, rest: buffer };
        }

        records.push(buffer.slice(0, separator.index));
        buffer = buffer.slice(separator.index + separator[0].length);
    }
}

export function parseSsePayload(data) {
    try {
        return JSON.parse(data);
    } catch (error) {
        if (error instanceof SyntaxError) {
            return null;
        }

        throw error;
    }
}

export function isTextPayload(payload) {
    return hasOnlyKeys(payload, ['text'])
        && hasRequiredKeys(payload, ['text'])
        && 'string' === typeof payload.text;
}

// Action names are configured by the app (`guarded_chat.actions`), so any plain identifier is valid.
const ACTION_TYPE = /^[A-Za-z0-9_-]{1,64}$/;

export function isActionPayload(payload) {
    return hasOnlyKeys(payload, ['type'])
        && hasRequiredKeys(payload, ['type'])
        && 'string' === typeof payload.type
        && ACTION_TYPE.test(payload.type);
}

// Body of POST /session. `website` is the honeypot field and has to stay empty for humans.
export function sessionRequestBody(verificationToken, honeypot, locale) {
    return { verificationToken, website: honeypot, locale };
}

// Validates state read back from sessionStorage, which visitors can edit freely via devtools.
export function isStoredState(state) {
    return hasOnlyKeys(state, ['sessionStarted', 'messages', 'isOpen'])
        && hasRequiredKeys(state, ['sessionStarted', 'messages', 'isOpen'])
        && 'boolean' === typeof state.sessionStarted
        && Array.isArray(state.messages)
        && 'boolean' === typeof state.isOpen
        && state.messages.every((message) => isStoredMessage(message));
}

function isStoredMessage(message) {
    return hasOnlyKeys(message, ['role', 'text', 'action'])
        && hasRequiredKeys(message, ['role', 'text'])
        && ('user' === message.role || 'assistant' === message.role)
        && 'string' === typeof message.text
        && (!Object.hasOwn(message, 'action') || ('string' === typeof message.action && ACTION_TYPE.test(message.action)));
}

function hasOnlyKeys(value, allowedKeys) {
    return null !== value
        && 'object' === typeof value
        && !Array.isArray(value)
        && Object.keys(value).every((key) => allowedKeys.includes(key));
}

function hasRequiredKeys(value, requiredKeys) {
    return requiredKeys.every((key) => Object.hasOwn(value, key));
}
