"use strict";

/**
 * AI/classes/AI/Transcript.js
 *
 * The AI side of transcript ingestion. Streams owns the session, the
 * transcription adapter, and ingestion (Streams.Transcript.process). This layer
 * subscribes to that pass and adds what only the AI plugin owns: TTS cue audio
 * for the durable record, the AI event bus, the LLM pipeline for non-control
 * narration, and topic-shift posting under the AI namespace.
 *
 * AI.listen wires afterStreams onto Streams.Transcript's 'processed' event, so
 * this runs right after Streams finishes each final utterance.
 *
 * @class AI.Transcript
 * @static
 */
var Session = Q.require('Streams/Transcript/Session');
var Pipeline = Q.require('AI/Pipeline');
var VetoQueue = Q.require('AI/VetoQueue');
var CueAudio = Q.require('AI/CueAudio');
var transcriptEmitter = Q.require('Streams/TranscriptEmitter').transcriptEmitter;

function Transcript() {}

/**
 * Run the AI layer after Streams has ingested a final utterance. Subscribed to
 * Streams.Transcript's 'processed' event in AI.listen.
 *
 * @method afterStreams
 * @static
 * @param {Object} session
 * @param {Object} result   { isControl, entry, ordinal } from Streams.Transcript
 * @param {Object} AI
 * @param {Object} Q
 * @param {Object} Users
 */
Transcript.afterStreams = async function (session, result, AI, Q, Users) {
    if (!result) return;

    // TTS cue audio for the durable record — keyed off the message ordinal.
    if (result.ordinal != null && session.transcriptFile) {
        CueAudio.generate(session, result.entry, result.ordinal, Q);
    }

    // AI event bus — internal listeners that want every utterance.
    AI.emit('transcript',
        session.userId, session.publisherId, session.streamName,
        Object.assign({}, result.entry));

    // LLM pipeline — only for non-control narration, with composition on, host.
    if (!result.isControl && session.role === 'host' && session.modes.composition !== false) {
        await Transcript._processChunk(session, result.entry.text, AI, Q, Users);
    }
};

/**
 * Drive the LLM pipeline for a non-control utterance.
 * @method _processChunk
 * @private
 * @static
 */
Transcript._processChunk = async function (session, text, AI, Q, Users) {
    if (!session.pipeline) {
        session.pipeline = new Pipeline({
            Q: Q,
            session: {
                role:        session.role,
                publisherId: session.publisherId,
                streamName:  session.streamName,
                userId:      session.userId,
                socket:      session.socket
            },
            emitToUser: function (userId, event, data) {
                Users.Socket.emitToUser(userId, event, data);
            },
            onTopicChange: function (fromTopic, toTopic) {
                Transcript._onTopicChange(session, fromTopic, toTopic, AI, Q, Users);
            }
        });
    }

    var result = null;
    try {
        result = await session.pipeline.run(text);
    } catch (e) {
        Users.Socket.emitToUser(session.userId, 'AI/error', {
            message: e.message, code: 500
        });
        return;
    }
    if (!result) return;

    if (result.action === 'ephemeral') {
        if (session.publisherId && result.ephemeralType) {
            Users.Socket.emitToUser(session.userId, 'AI/ephemeral', {
                publisherId: session.publisherId,
                streamName:  session.streamName,
                type:        result.ephemeralType,
                payload:     result.ephemeralPayload || {}
            });
        }
        return;
    }
    if (result.action === 'coaching' || result.routing === 'privateOnly') {
        Users.Socket.emitToUser(session.userId, 'AI/coaching', {
            text:      result.coachingText,
            sourceUri: result.sourceUri
        });
        return;
    }
    if (result.action === 'propose') {
        AI.emit('proposal',
            session.userId, session.publisherId, session.streamName, result);
        VetoQueue.enqueue(session, result, AI, Q, Users);
    }
};

/**
 * Topic-shift callback wired into the pipeline. The shift is detected by the
 * LLM, so it posts under the AI namespace — AI/topic. The VTT NOTE marker still
 * goes through the shared TranscriptEmitter.
 *
 * @method _onTopicChange
 * @private
 * @static
 */
Transcript._onTopicChange = function (session, fromTopic, toTopic, AI, Q, Users) {
    Q.log && Q.log('AI: topic shift', fromTopic, '->', toTopic);
    var topicRelSec = Session.relSec(session);
    transcriptEmitter.emitTopicChange(session, fromTopic, toTopic, topicRelSec);
    AI.emit('topicChange',
        session.userId, session.publisherId, session.streamName,
        { from: fromTopic, to: toTopic,
          isOwnLivestream: !!session.isOwnLivestream }
    );
    Users.Socket.emitToUser(session.userId, 'AI/topicChange', {
        from: fromTopic, to: toTopic, relSec: topicRelSec
    });
    if (session.publisherId && session.streamName) {
        Session.postMessage(Q, {
            publisherId:  session.publisherId,
            streamName:   session.streamName,
            byUserId:     session.userId,
            type:         'AI/topic',
            instructions: JSON.stringify({ from: fromTopic, to: toTopic, relSec: topicRelSec })
        });
    }
};

module.exports = Transcript;