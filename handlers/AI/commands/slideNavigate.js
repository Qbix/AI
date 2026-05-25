'use strict';
/**
 * AI/handlers/AI/commands/slideNavigate.js
 *
 * Server-side handler for slide/navigate intent.
 * Called when client sends a Streams/utterance with intent:'slide/navigate'
 * — meaning the client's local PDF corpus search found no match.
 *
 * Search order:
 *   1. Recent Media/presentation/card/show messages (last 200)
 *      Matches against visualizationData text (label, name, title, term, etc.)
 *      On match: emits AI/card/replay → replays card on canvas + new chat message
 *
 *   2. Related streams (existing behavior)
 *      Matches against stream title + key attributes
 *      On match: emits Streams/slide { slideIndex }
 *
 *   3. No match: silent (server tried, nothing found)
 *
 * The client already handled:
 *   - All exact navigation intents (next/prev/zoom/seek etc) — emitted direct ephemerals
 *   - PDF page search — searched local corpus, fell through if no match
 *
 * @module AI
 */
module.exports = async function slideNavigate(captures, stream, state, Q) {
    var query = (captures && captures.query) ? captures.query.toLowerCase().trim() : '';
    if (!query || !state || !state.publisherId || !state.streamName) return;

    var Users = state.Users;

    try {
        var Str = Q.require('Streams');
        if (!Str) return;

        // ── 1. Search recent card/show messages ───────────────────────────────
        var cardMatch = await _searchCardMessages(query, state, Str, Q);
        if (cardMatch) {
            Q.log && Q.log('slideNavigate: card match "' + query + '" → ' +
                cardMatch.visualizationType + ' "' + _cardSummary(cardMatch.visualizationData) + '"');
            Users && Users.Socket.emitToUser(state.userId, 'AI/card/replay', {
                visualizationType: cardMatch.visualizationType,
                visualizationData: cardMatch.visualizationData,
                streamType:        cardMatch.streamType
            });
            return;
        }

        // ── 2. Search related streams ─────────────────────────────────────────
        if (!Str.related) return;
        var rows = await new Promise(function (resolve, reject) {
            Str.related(
                state.userId || '',
                state.publisherId,
                state.streamName,
                { limit: 200 },
                function (err, result) {
                    if (err) return reject(err);
                    resolve((result && result.streams) || []);
                }
            );
        });

        if (!rows || !rows.length) {
            Q.log && Q.log('slideNavigate: no related streams, no card match for "' + query + '"');
            return;
        }

        var candidates = rows.map(function (s, idx) {
            var attrs = s.getAllAttributes ? s.getAllAttributes() : (s.attributes || {});
            var text = [
                s.fields && s.fields.title,
                attrs.term,
                attrs.label,
                attrs.name,
                attrs.keyClaim,
                attrs.definition && attrs.definition.slice(0, 100),
                attrs.quote      && attrs.quote.slice(0, 80),
            ].filter(Boolean).join(' ').toLowerCase();
            return { index: idx, text: text, stream: s };
        });

        var best = null, bestScore = Infinity;
        candidates.forEach(function (c) {
            var score = _matchScore(query, c.text);
            if (score < bestScore) { bestScore = score; best = c; }
        });

        var threshold = Math.max(3, Math.floor(query.length * 0.45));
        if (best && bestScore <= threshold) {
            Q.log && Q.log('slideNavigate: stream match "' + query + '" → index ' +
                best.index + ' "' + (best.stream.fields && best.stream.fields.title) + '"' +
                ' (score=' + bestScore + ')');
            stream.ephemeral('Streams/slide', { slideIndex: best.index });
        } else {
            Q.log && Q.log('slideNavigate: no match for "' + query +
                '" (best score=' + bestScore + ')');
        }

    } catch (e) {
        Q.log && Q.log('slideNavigate error:', e.message);
    }
};

// ── Card message search ───────────────────────────────────────────────────────

async function _searchCardMessages(query, state, Str, Q) {
    var messages = await new Promise(function (resolve) {
        Str.Message.get(
            state.publisherId,
            state.streamName,
            {
                type:  'Media/presentation/card/show',
                limit: 200,
                // most recent first — ordinal descending
            },
            function (err, msgs) {
                resolve((!err && msgs) ? msgs : {});
            }
        );
    });

    var best = null, bestScore = Infinity;
    var threshold = Math.max(3, Math.floor(query.length * 0.45));

    Q.each(messages, function (ordinal, message) {
        var instr = {};
        try { instr = JSON.parse(message.fields ? message.fields.instructions : message.instructions || '{}'); } catch (e) {}
        var data = instr.visualizationData || {};
        var text = _cardText(data).toLowerCase();
        if (!text) return;
        var score = _matchScore(query, text);
        if (score < bestScore) {
            bestScore = score;
            best = {
                visualizationType: instr.visualizationType,
                visualizationData: data,
                streamType:        instr.streamType,
                score:             score
            };
        }
    });

    return (best && bestScore <= threshold) ? best : null;
}

function _cardText(data) {
    return [
        data.label,
        data.term,
        data.title,
        data.name,
        data.keyClaim,
        data.speaker,
        data.publication,
        data.quote      && data.quote.slice(0, 80),
        data.definition && data.definition.slice(0, 80),
        data.left  && data.left.label,
        data.right && data.right.label,
        data.credit,
    ].filter(Boolean).join(' ');
}

function _cardSummary(data) {
    return data.label || data.term || data.title || data.name || data.speaker || '';
}

// ── Levenshtein ───────────────────────────────────────────────────────────────

function _matchScore(query, haystack) {
    if (!query || !haystack) return Infinity;
    if (haystack.indexOf(query) !== -1) return 0;
    var ql = query.length, hl = haystack.length;
    if (ql > hl) return _levenshtein(query, haystack);
    var best = Infinity;
    var step = Math.max(1, Math.floor(ql / 3));
    for (var i = 0; i <= hl - ql; i += step) {
        var d = _levenshtein(query, haystack.slice(i, i + ql));
        if (d < best) best = d;
        if (d === 0) return 0;
    }
    return best;
}

function _levenshtein(a, b) {
    var m = a.length, n = b.length;
    if (!m) return n; if (!n) return m;
    var prev = new Array(n+1), curr = new Array(n+1);
    for (var j = 0; j <= n; j++) prev[j] = j;
    for (var i = 1; i <= m; i++) {
        curr[0] = i;
        for (var jj = 1; jj <= n; jj++) {
            curr[jj] = a[i-1] === b[jj-1]
                ? prev[jj-1]
                : 1 + Math.min(prev[jj-1], prev[jj], curr[jj-1]);
        }
        var tmp = prev; prev = curr; curr = tmp;
    }
    return prev[n];
}
