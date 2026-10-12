const URL_STARTS = ['https://', 'http://'];
const TRAILING_SENTENCE_PUNCTUATION = '.,!?;:)]}';
const WORD_CHARACTER = /^[\p{L}\p{N}_]$/u;

export function parseOwnOriginLinks(text, origin) {
    let baseUrl;
    try {
        baseUrl = new URL(origin);
    } catch (error) {
        return [{ type: 'text', text }];
    }

    const segments = [];
    let position = 0;
    for (let index = 0; index < text.length;) {
        const candidateStart = findCandidateStart(text, index);
        if (null === candidateStart) {
            index++;
            continue;
        }

        const candidateEnd = findCandidateEnd(text, candidateStart);
        const rawCandidate = text.slice(candidateStart, candidateEnd);
        index = candidateEnd;

        if (isWordCharacterBefore(text, candidateStart)) {
            continue;
        }

        const candidate = trimTrailingSentencePunctuation(rawCandidate);
        if ('' === candidate) {
            continue;
        }

        let url;
        try {
            url = new URL(candidate, baseUrl);
        } catch (error) {
            continue;
        }
        if (url.origin !== baseUrl.origin) {
            continue;
        }

        if (candidateStart > position) {
            segments.push({ type: 'text', text: text.slice(position, candidateStart) });
        }
        segments.push({ type: 'link', text: candidate, href: url.href });
        position = candidateStart + candidate.length;
    }

    if (position < text.length) {
        segments.push({ type: 'text', text: text.slice(position) });
    }

    return segments;
}

function findCandidateStart(text, index) {
    if ('/' === text[index] && index + 1 < text.length && !isUrlDelimiter(text[index + 1])) {
        return index;
    }

    for (const urlStart of URL_STARTS) {
        if (text.startsWith(urlStart, index)) {
            return index;
        }
    }

    return null;
}

function findCandidateEnd(text, start) {
    let end = start;
    while (end < text.length && !isUrlDelimiter(text[end])) {
        end++;
    }

    return end;
}

function isUrlDelimiter(character) {
    return '<' === character
        || '>' === character
        || '"' === character
        || "'" === character
        || '' === character.trim();
}

function isWordCharacterBefore(text, index) {
    if (0 === index) {
        return false;
    }

    let characterStart = index - 1;
    if (isLowSurrogateCodePoint(text.codePointAt(characterStart)) && characterStart > 0 && text.codePointAt(characterStart - 1) > 0xFFFF) {
        characterStart--;
    }

    return WORD_CHARACTER.test(text.slice(characterStart, index));
}

function isLowSurrogateCodePoint(codePoint) {
    return codePoint >= 0xDC00 && codePoint <= 0xDFFF;
}

function trimTrailingSentencePunctuation(candidate) {
    let end = candidate.length;
    while (end > 0 && TRAILING_SENTENCE_PUNCTUATION.includes(candidate[end - 1])) {
        end--;
    }

    return candidate.slice(0, end);
}
