"use strict";
/*jshint node:true */
/**
 * AI plugin
 * @module AI
 * @main AI
 */
var Q = require('Q');

/**
 * Static methods for the AI plugin.
 * @class AI
 * @static
 */
function AI() {}
module.exports = AI;

Q.makeEventEmitter(AI);

// Lazily loaded after Q is ready to require plugins.
var Transcript, VetoQueue, CardCommit, Session, StreamsTranscript;
var transcriptEmitter = null;  // hoisted at first AI.listen() — Streams plugin must be loaded first

/**
 * Start node-side AI listeners.
 *
 * Streams now owns the transcription session, the audio adapter, and
 * Streams/utterance ingestion (see Streams.listen). AI subscribes to that work:
 * it runs its LLM pipeline on each non-control utterance, and re-broadcasts
 * session lifecycle on its own event bus. The only client events it still wires
 * are its own — veto, card replay, narration.
 *
 * Idempotent. Call after Streams.listen() so the session lifecycle and
 * 'processed' event exist:
 *
 *   Q.init();
 *   Users.Socket.listen();
 *   Streams.listen();
 *   Media.listen();
 *   AI.listen();
 *
 * @method listen
 * @static
 */
AI.listen = function () {
    if (AI.listen.result) return AI.listen.result;

    Transcript        = require('./AI/Transcript');
    VetoQueue         = require('./AI/VetoQueue');
    CardCommit        = require('./AI/CardCommit');
    Session           = require('../../Streams/classes/Streams/Transcript/Session');
    StreamsTranscript = require('../../Streams/classes/Streams/Transcript');
    transcriptEmitter = require('../../Streams/classes/Streams/TranscriptEmitter').transcriptEmitter;
    var Users         = Q.require('Users');

    // ── Subscribe to Streams ingestion (once) ──────────────────────────

    // Every final utterance: run the AI pipeline for non-control narration.
    StreamsTranscript.on('processed', function (session, result, Q, Users) {
        Transcript.afterStreams(session, result, AI, Q, Users);
    });

    // Re-broadcast session lifecycle on the AI event bus for server plugins.
    transcriptEmitter.on('sessionStart', function (evt) {
        var s = Session.get(evt.sessionId);
        AI.emit('sessionStart', s && s.userId, evt.publisherId, evt.streamName,
            { role: evt.role, lang: evt.lang, ts: evt.ts });
    });
    transcriptEmitter.on('sessionEnd', function (evt) {
        var s = Session.get(evt.sessionId);
        AI.emit('sessionEnd', s && s.userId, evt.publisherId, evt.streamName,
            { transcriptFile: evt.transcriptFile, chunkCount: evt.chunkCount });
    });

    // ── AI-only client events ──────────────────────────────────────────

    var socket = Users.Socket.listen();
    var nsp = socket.io.of('/Q');

    nsp.on('connection', function (client) {
        if (client._aiRegistered) return;
        client._aiRegistered = true;

        var userId = client.capability && client.capability.userId;

        // Veto actions
        client.on('AI/veto/commit', function (data) {
            var session = Session.get(client.id);
            if (session) VetoQueue.commit(session, data && data.proposalId, AI, Q, Users);
        });
        client.on('AI/veto/cancel', function (data) {
            var session = Session.get(client.id);
            if (session) VetoQueue.cancel(session, data && data.proposalId, Users);
        });

        // Card replay (host clicks a historical card in chat)
        client.on('AI/card/replay', function (data) {
            var session = Session.get(client.id);
            if (session) CardCommit.replay(session, data, Users);
        });

        // Narration mode (script playback) — feeds lines as utterances into the
        // Streams pass, which fires 'processed' and runs the pipeline.
        // data: { lines: [...], msPerLine: 3000 }   host only
        client.on('AI/stream/narrate', function (data) {
            var session = Session.get(client.id);
            if (!session || session.role !== 'host') return;
            var lines     = (data && Array.isArray(data.lines)) ? data.lines : [];
            var msPerLine = (data && data.msPerLine) || 3000;
            if (!lines.length) return;
            session.mode = 'narration';
            var i = 0;
            (function feedNext() {
                if (i >= lines.length) return;
                var line = lines[i++].trim();
                if (line) {
                    StreamsTranscript.process(session, {
                        transcript: line, isFinal: true, confidence: 1, speaker: userId
                    }, Q, Users);
                }
                setTimeout(feedNext, msPerLine);
            })();
        });
    });

    return AI.listen.result = { socket: true };
};

/**
 * Events emitted on AI (Node-side, for server plugins):
 *
 *   AI.on('transcript',   function (userId, publisherId, streamName, chunk) {})
 *   AI.on('topicChange',  function (userId, publisherId, streamName, evt) {})
 *   AI.on('proposal',     function (userId, publisherId, streamName, proposal) {})
 *   AI.on('commit',       function (userId, publisherId, streamName, proposal) {})
 *   AI.on('sessionStart', function (userId, publisherId, streamName, data) {})
 *   AI.on('sessionEnd',   function (userId, publisherId, streamName, data) {})
 *
 * Socket events delivered to clients on the /Q namespace:
 *
 *   AI/veto/show       { proposal, windowMs }                     host only
 *   AI/coaching        { text, sourceUri }                        host only
 *   AI/proposal/show   { proposalId, visualizationType, visualizationData,
 *                        streamType, citations }
 *   AI/error           { message, code }
 */

/* * * */