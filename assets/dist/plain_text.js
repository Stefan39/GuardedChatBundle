// Safety net for Markdown the model emits despite the plain-text instruction in the system prompt
//. The widget renders model output as text nodes, never as HTML, so this only rewrites
// the text: emphasis markers are dropped, list markers become bullets, heading hashes go away.
// Paired underscores only, so PHP names such as __construct() survive. Runs on the accumulated
// text on every streamed chunk; an unclosed "**" is dropped too, so it never flashes up.
export function toPlainChatText(text) {
    return text
        .replace(/\*\*(\S(?:.*?\S)?)\*\*/g, '$1')
        .replace(/__(\S(?:.*?\S)?)__/g, '$1')
        .replaceAll('**', '')
        .replace(/^(\s*)[-*] +/gm, '$1• ')
        .replace(/^#{1,6} +/gm, '');
}
