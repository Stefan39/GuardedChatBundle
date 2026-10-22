import { Controller } from '@hotwired/stimulus';
import { parseOwnOriginLinks } from './link_parser.js';
import { toPlainChatText } from './plain_text.js';
import {
    isActionPayload,
    isStoredState,
    isTextPayload,
    parseSsePayload,
    sessionRequestBody,
    shouldShowGreeting,
    splitSseRecords,
    verificationErrorText,
} from './state.js';

class ChatSessionError extends Error {
    constructor(message, sessionExpired = false) {
        super(message);
        this.sessionExpired = sessionExpired;
    }
}

/*
 * Chat widget for GuardedChatBundle. The markup comes from @GuardedChat/widget.html.twig (or your
 * override of it); this controller only needs the targets and values below.
 *
 * Human verification: before a session starts, the controller dispatches a `verify` event. A
 * listener may call `event.detail.provide(tokenOrPromise)` to hand over a token (for example a
 * Turnstile or reCAPTCHA response), which is sent as `verificationToken` to POST /session.
 * Without a listener the static `verification-token` value (empty by default) is sent. If the
 * promise rejects, the widget shows the rejection reason when it is a string or carries a
 * `userMessage` string, otherwise the `error-verification` value.
 */
export default class extends Controller {
    static targets = ['launcher', 'panel', 'messages', 'input', 'typing', 'honeypot', 'action'];
    static values = {
        sessionUrl: String,
        messageUrl: String,
        locale: String,
        greeting: String,
        storageKey: { type: String, default: 'guarded-chat.widget.v1' },
        verificationToken: { type: String, default: '' },
        userClass: { type: String, default: 'guarded-chat__message guarded-chat__message--user' },
        assistantClass: { type: String, default: 'guarded-chat__message guarded-chat__message--assistant' },
        errorGeneric: String,
        errorVerification: String,
        errorSessionExpired: String,
        errorUnavailable: String,
    };

    connect() {
        this.sessionStarted = false;
        this.messages = [];
        // In-flight session request, shared by concurrent ensureSession() callers so two fast
        // submissions don't trigger two POST /session calls (and two verification rounds).
        this.sessionRequest = null;
        this.restoreState();
        this.maybeShowGreeting();
    }

    // Discloses that this is an AI system (Art. 50 EU AI Act) as the first assistant message,
    // shown before the visitor can type anything. It is rendered transiently: never sent to the
    // server and never persisted as part of the conversation.
    //
    // Checks the live DOM as well as the in-memory transcript: Turbo Drive restores a cached DOM
    // snapshot (including an earlier greeting bubble) on back/forward visits and reconnects this
    // controller with an empty `this.messages`, which would otherwise add a second greeting.
    maybeShowGreeting() {
        if ('' !== this.greetingValue
            && shouldShowGreeting(this.messages.length, this.messagesTarget.childElementCount)) {
            this.renderTransientMessage('assistant', this.greetingValue);
        }
    }

    toggle() {
        const opening = !this.panelTarget.open;
        if (opening) {
            this.panelTarget.show();
        } else {
            this.panelTarget.close();
        }
        this.launcherTarget.setAttribute('aria-expanded', opening ? 'true' : 'false');
        if (opening) {
            this.inputTarget.focus();
        }
        this.saveState();
    }

    async submit(event) {
        event.preventDefault();
        const text = this.inputTarget.value.trim();
        if ('' === text) {
            return;
        }
        this.inputTarget.value = '';
        this.appendMessage('user', text);

        try {
            await this.ensureSession();
            this.showTyping();
            const response = await fetch(this.messageUrlValue, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: text, locale: this.localeValue }),
            });
            this.hideTyping();

            if (!response.ok) {
                const errorText = await this.errorText(response);
                if (401 === response.status) {
                    this.restoreDraft(text);
                    this.renderTransientMessage('assistant', errorText);

                    return;
                }
                this.appendMessage('assistant', errorText);

                return;
            }

            await this.streamReply(response);
        } catch (error) {
            this.hideTyping();
            const errorText = error instanceof ChatSessionError
                ? error.message
                : this.errorGenericValue;
            if (error instanceof ChatSessionError && error.sessionExpired) {
                this.restoreDraft(text);
                this.renderTransientMessage('assistant', errorText);
            } else {
                this.appendMessage('assistant', errorText);
            }
            console.error('[guarded-chat]', error);
        }
    }

    async ensureSession() {
        if (this.sessionStarted) {
            return;
        }
        if (null === this.sessionRequest) {
            this.sessionRequest = this.startSession().finally(() => {
                this.sessionRequest = null;
            });
        }

        return this.sessionRequest;
    }

    async startSession() {
        const verificationToken = await this.collectVerificationToken();
        const response = await fetch(this.sessionUrlValue, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(sessionRequestBody(verificationToken, this.honeypotTarget.value, this.localeValue)),
        });
        if (!response.ok) {
            throw new ChatSessionError(await this.errorText(response), 401 === response.status);
        }
        this.sessionStarted = true;
        this.saveState();
    }

    async collectVerificationToken() {
        let provided = null;
        this.dispatch('verify', {
            detail: {
                provide: (tokenOrPromise) => {
                    provided = tokenOrPromise;
                },
            },
        });

        if (null === provided) {
            return this.verificationTokenValue;
        }

        try {
            return await provided;
        } catch (error) {
            console.error('[guarded-chat] verification failed', error);
            // Templates from 1.0 have no error-verification value; they keep the generic text.
            throw new ChatSessionError(verificationErrorText(error, this.errorVerificationValue || this.errorGenericValue));
        }
    }

    async streamReply(response) {
        const { message, bubble } = this.appendMessage('assistant', '');
        const reader = response.body.getReader();
        const decoder = new TextDecoder();
        let buffer = '';
        for (;;) {
            const { done, value } = await reader.read();
            if (done) {
                break;
            }
            buffer += decoder.decode(value, { stream: true });
            buffer = this.consumeSseRecords(buffer, message, bubble);
        }
        buffer += decoder.decode();
        this.consumeSseRecords(buffer, message, bubble);
    }

    // The server answers errors with a JSON body carrying a translated `message` and a stable
    // `error` code; that text wins, the values are only the fallback for non-JSON failures.
    async errorText(response) {
        if (401 === response.status) {
            this.resetExpiredSession();
        }

        try {
            const data = await response.json();
            if ('message_limit_reached' === data.error) {
                this.startFreshSessionOnNextMessage();
            }
            if ('string' === typeof data.message && '' !== data.message) {
                return data.message;
            }
        } catch (error) {
            // A non-JSON body is expected for errors that didn't come from the chat controller
            // (e.g. an upstream 5xx HTML page), so this falls through to the generic text below.
            console.debug('[guarded-chat] error response body was not JSON', error);
        }

        if (401 === response.status) {
            return this.errorSessionExpiredValue;
        }
        if (404 === response.status) {
            return this.errorUnavailableValue;
        }

        return this.errorGenericValue;
    }

    appendMessage(role, text) {
        const message = { role, text };
        this.messages.push(message);

        const bubble = this.renderMessage(message);
        this.saveState();

        return { message, bubble };
    }

    renderTransientMessage(role, text) {
        this.renderMessage({ role, text });
    }

    renderMessage(message) {
        const bubble = document.createElement('div');
        bubble.className = 'user' === message.role ? this.userClassValue : this.assistantClassValue;
        this.renderMessageContents(bubble, message);
        this.messagesTarget.appendChild(bubble);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;

        return bubble;
    }

    renderMessageContents(bubble, message) {
        const content = document.createDocumentFragment();
        this.appendTextWithOwnOriginLinks(content, 'assistant' === message.role ? toPlainChatText(message.text) : message.text);

        if (undefined !== message.action) {
            const action = this.renderAction(message.action);
            if (null !== action) {
                content.appendChild(action);
            }
        }

        bubble.replaceChildren(content);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }

    // Actions come from <template data-...-target="action" data-action-type="contact"> elements in
    // the widget markup; the clone is shown under the answer. Unknown action types render nothing.
    renderAction(type) {
        const template = this.actionTargets.find((candidate) => candidate.dataset.actionType === type);
        if (undefined === template) {
            return null;
        }

        return template.content.firstElementChild?.cloneNode(true) ?? null;
    }

    appendTextWithOwnOriginLinks(container, text) {
        for (const segment of parseOwnOriginLinks(text, window.location.origin)) {
            if ('text' === segment.type) {
                container.appendChild(document.createTextNode(segment.text));
                continue;
            }

            const link = document.createElement('a');
            link.href = segment.href;
            link.className = 'guarded-chat__link';
            link.appendChild(document.createTextNode(segment.text));
            container.appendChild(link);
        }
    }

    consumeSseRecords(buffer, message, bubble) {
        const { records, rest } = splitSseRecords(buffer);
        for (const record of records) {
            this.consumeSseRecord(record, message, bubble);
        }

        return rest;
    }

    consumeSseRecord(record, message, bubble) {
        let eventName = '';
        let data = '';
        for (const line of record.split(/\r?\n/)) {
            if (line.startsWith('event:')) {
                eventName = line.slice('event:'.length).trim();
            }
            if (line.startsWith('data:')) {
                data += line.slice('data:'.length).replace(/^ /, '');
            }
        }

        if (('text' !== eventName && 'action' !== eventName) || '' === data) {
            return;
        }

        const payload = parseSsePayload(data);
        if (null === payload) {
            return;
        }

        if ('text' === eventName && isTextPayload(payload)) {
            message.text += payload.text;
            this.renderMessageContents(bubble, message);
            this.saveState();

            return;
        }
        if ('action' === eventName && isActionPayload(payload) && message.action !== payload.type) {
            message.action = payload.type;
            this.renderMessageContents(bubble, message);
            this.saveState();
        }
    }

    restoreState() {
        let rawState;
        try {
            rawState = window.sessionStorage.getItem(this.storageKeyValue);
        } catch {
            return;
        }
        if (null === rawState) {
            return;
        }

        try {
            const state = JSON.parse(rawState);
            if (!isStoredState(state)) {
                this.clearStoredState();

                return;
            }

            this.messagesTarget.replaceChildren();
            this.sessionStarted = state.sessionStarted;
            this.messages = state.messages.map((message) => ({ ...message }));
            for (const message of this.messages) {
                this.renderMessage(message);
            }
            if (state.isOpen) {
                this.panelTarget.show();
                this.launcherTarget.setAttribute('aria-expanded', 'true');
            }
        } catch {
            this.clearStoredState();
        }
    }

    saveState() {
        try {
            window.sessionStorage.setItem(this.storageKeyValue, JSON.stringify({
                sessionStarted: this.sessionStarted,
                messages: this.messages,
                isOpen: this.panelTarget.open,
            }));
        } catch {
            // Storage can be disabled by browser privacy settings; chat remains usable in this tab.
        }
    }

    clearStoredState() {
        try {
            window.sessionStorage.removeItem(this.storageKeyValue);
        } catch {
            // Storage can be disabled by browser privacy settings; there is nothing to remove then.
        }
    }

    resetExpiredSession() {
        this.sessionStarted = false;
        this.messages = [];
        this.messagesTarget.replaceChildren();
        this.clearStoredState();
    }

    // An expired session wipes the transcript, so the message the visitor just typed would be gone
    // from both the conversation and the input. Only fills an input the visitor has not typed into
    // again.
    restoreDraft(text) {
        if ('' === this.inputTarget.value) {
            this.inputTarget.value = text;
        }
    }

    // The per-session message cap is final on the server, and the stored token keeps pointing at
    // the exhausted session. The transcript stays visible; only the next message opens a fresh one.
    startFreshSessionOnNextMessage() {
        this.sessionStarted = false;
        this.saveState();
    }

    showTyping() {
        this.typingTarget.hidden = false;
    }

    hideTyping() {
        this.typingTarget.hidden = true;
    }
}
