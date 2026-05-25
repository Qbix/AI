"use strict";

/**
 * AI/handlers/socket.js
 *
 * Registers AI pipeline handlers on the platform's existing /Q socket
 * namespace. No new namespace — authentication and user mapping are
 * already handled by Users.Socket. We fan out to clients the same way
 * Streams does: Users.Socket.emitToUser(userId, eventName, data).
 *
 * Node-side events fire on the AI module object (Q.makeEventEmitter):
 *   AI.emit('transcript', userId, publisherId, streamName, chunk)
 *   AI.emit('topicChange', userId, publisherId, streamName, evt)
 *   AI.emit('proposal',    userId, publisherId, streamName, proposal)
 *   AI.emit('commit',      userId, publisherId, streamName, proposal)
 *   AI.emit('sessionStart', userId, publisherId, streamName, data)
 *   AI.emit('sessionEnd',   userId, publisherId, streamName, data)
 *
 * Socket events delivered to the user's /Q clients:
 *   Streams/utterance      { transcript, isFinal, confidence, speaker, relSec }
 *   AI/veto/show      { proposal, windowMs }
 *   AI/veto/commit    { proposalId }
 *   AI/veto/cancel    { proposalId }
 *   AI/coaching       { text, sourceUri }
 *   AI/proposal/show  { proposalId, visualizationType, visualizationData, streamType }
 *   AI/error          { message, code }
 *
 * Client subscribes with:
 *   Q.Socket.onEvent('Streams/utterance').set(function(data){...}, tool);
 *
 * Server bootstrap:
 *   const AI = require('./plugins/AI/classes/AI');
 *   require('./plugins/AI/handlers/socket').register(AI, Q, Users);
 *
 * Per-connection lifecycle:
 *   1. Client connects on /Q (already authenticated via capability)
 *   2. Client emits AI/transcription/session/start  → pipeline session created
 *   3. Client emits AI/transcription/session/chunk  → PCM forwarded to transcription adapter
 *   4. Adapter transcripts → _onTranscript → ControlClassifier → Pipeline
 *   5. Browser WebSpeech and typed text arrive as Streams/utterance events from client
 *   6. Client emits AI/transcription/session/stop or disconnects → session torn down
 */

const path                  = require('path');
const ControlClassifier     = require('../../Streams/classes/Streams/ControlClassifier');
const Pipeline              = require('../classes/AI/Pipeline');
const slideGenerate         = require('./AI/commands/slideGenerate');
const { transcriptEmitter } = require('../../Streams/classes/Streams/TranscriptEmitter');
const AI_Transcription      = require('../classes/AI/Transcription');


// Helper: post a durable message to the presentation stream.
// Fire-and-forget — errors are logged but never block the pipeline.
function _postMessage(Q, fields) {
    try {
        const Str = Q.require('Streams');
        Str.Message.post(Object.assign({
            byClientId: '',
            weight: 1
        }, fields), function (err) {
            if (err) Q.log && Q.log('AI: message post error', err.message || err);
        });
    } catch (e) {
        Q.log && Q.log('AI: _postMessage exception', e.message);
    }
}

// Per-session state keyed by socket.id
const sessions = new Map();

/**
 * Register AI pipeline event handlers on the /Q socket namespace.
 *
 * @param {Object} AI     The AI module (Q.makeEventEmitter already called)
 * @param {Object} Q      Server-side Q object
 * @param {Object} Users  Users module (for Users.Socket.emitToUser)
 */
function register(AI, Q, Users) {

    // Access the socket.io namespace via the Q server.
    // Q.listen().attached.socket is the Q.Socket instance created by Users.Socket.listen().
    // Falls back to Q.Socket._io if the platform sets it (some versions do).
    var _qServer = Q.listen && Q.listen();
    var _qSocketInst = _qServer && _qServer.attached && _qServer.attached.socket;
    var _io = (_qSocketInst && _qSocketInst.io) || (Q.Socket && Q.Socket._io);
    if (!_io) {
        Q.log && Q.log('AI/socket: could not find socket.io instance — register() called before Users.Socket.listen()?');
        return;
    }
    const nsp = _io.of('/Q');

    nsp.on('connection', function (client) {
        if (client._aiRegistered) return;
        client._aiRegistered = true;

        // userId comes from the capability that Users.Socket already verified
        const userId = client.capability && client.capability.userId;

        // ── Stream lifecycle ────────────────────────────────────────────

        // Toggle composition / navigation modes without restarting the session
        client.on('AI/session/modes', function (data) {
            var session = sessions.get(client.id);
            if (!session || !data) return;
            if (data.composition   !== undefined) session.modes.composition   = !!data.composition;
            if (data.navigation    !== undefined) session.modes.navigation    = !!data.navigation;
            if (data.transcription !== undefined) session.modes.transcription = !!data.transcription;
            Q.log && Q.log('AI: modes updated', session.modes);
        });

        // Client-classified navigation commands — client already emitted the ephemeral.
        // Server just updates slideIndex tracking and posts the durable VTT cue.
        client.on('Media/presentation/command', function (data) {
            var session = sessions.get(client.id);
            if (!session || !data || !data.intent) return;

            // Update server-side slide tracking so relative commands stay accurate
            if (data.slideIndex != null) session.slideIndex = data.slideIndex;
            if (data.revealIndex != null) session.revealIndex = data.revealIndex;

            // Post durable record + VTT cue (same as server-side classification path)
            if (session.publisherId && session.streamName) {
                var relSec = data.relSec || ((Date.now() - session.sessionStartMs) / 1000).toFixed(1);
                var Str = Q.require('Streams');
                if (data.intent === 'slide/navigate' || data.intent.indexOf('slide') === 0) {
                    var slideInstr = JSON.stringify({
                        index:  session.slideIndex,
                        relSec: relSec,
                        intent: data.intent,
                        query:  data.query || undefined
                    });
                    Str.Message.post({
                        publisherId:  session.publisherId,
                        streamName:   session.streamName,
                        byUserId:     session.userId,
                        byClientId:   '',
                        weight:       1,
                        type:         'Media/presentation/slide',
                        instructions: slideInstr,
                    }, function (err, message) {
                        if (!err && message) {
                            transcriptEmitter._appendVttEventNote(
                                session, 'Media/presentation/slide',
                                message.fields.ordinal, slideInstr, Q, message.fields.sentTime
                            );
                        }
                    });
                }
            }
        });

        // Chat card replay: host clicks a card in chat → re-show on canvas
        client.on('AI/card/replay', function (data) {
            if (!data || !data.visualizationData) return;
            var session = sessions.get(client.id);
            if (!session) return;
            // Reuse commit path: fan out AI/proposal/show directly (no veto)
            Users.Socket.emitToUser(session.userId, 'AI/proposal/show', {
                proposalId:        'replay_' + Date.now(),
                visualizationType: data.visualizationType,
                visualizationData: data.visualizationData,
                streamType:        data.streamType
            });
        });

        client.on('AI/transcription/session/start', function (data) {
            if (!userId) return;
            const lang        = (data && data.lang)        || 'en-US';
            const sampleRate  = (data && data.sampleRate)  || 16000;
            const publisherId = (data && data.publisherId) || null;
            const streamName  = (data && data.streamName)  || null;
            const role        = (data && data.role)        || 'participant';

            const mode  = (data && data.mode)  || 'live';  // live|narration
            const modes = {
                composition:  (data && data.modes && data.modes.composition  !== false),
                navigation:   (data && data.modes && data.modes.navigation   !== false),
                transcription:(data && data.modes && data.modes.transcription !== false),
            };

            const session = {
                userId,
                socketId:         client.id,
                socket:           client,
                role,
                lang,
                mode,
                modes,
                sampleRate,
                publisherId,
                streamName,
                toolStreamName:   (data && data.toolStreamName)  || null,
                toolPublisherId:  (data && data.toolPublisherId) || userId,
                slideIndex:       0,
                revealIndex:      0,
                zoomScale:        1,
                transcription:    null,  // AI_Transcription streaming adapter instance
                transcriptBuffer: [],
                transcriptFile:   null,
                _displayNames:    {},   // userId → display name cache for VTT <v> tags
                sessionStartMs:   Date.now(),
                isOwnLivestream:  !!(data && data.isOwnLivestream),
                classifier:       new ControlClassifier({ Q }),
                pipeline:         null,
                vetoQueue:        [],
                vetoTimers:       new Map(),
            };

            // Close any previous session on this socket before replacing —
            // clears the transcription adapter WebSocket and any pending veto timers
            const prevSession = sessions.get(client.id);
            if (prevSession) {
                _closeTranscription(prevSession);
                prevSession.vetoTimers.forEach(t => clearTimeout(t));
                prevSession.vetoTimers.clear();
            }

            sessions.set(client.id, session);
            session.classifier.locale = lang.split('-')[0];
            session.classifier.reload();

            // Open streaming transcription adapter if a provider is configured.
            // Checks AI/transcription/provider first, falls back to AI/deepgram/key
            // for backward compatibility with existing deployments.
            const txProvider = Q.Config && Q.Config.get(
                ['AI', 'transcription', 'provider'], null
            );
            // Legacy: if no provider configured but AI/deepgram/key exists, use deepgram
            const legacyDgKey = Q.Config && Q.Config.get(['AI', 'deepgram', 'key'], null);
            if (txProvider || legacyDgKey) {
                _openTranscription(session, AI, Q, Users);
            }

            transcriptEmitter.emitSessionStart(session, Q);
            AI.emit('sessionStart', userId, publisherId, streamName, {
                role, lang, ts: session.sessionStartMs
            });

            // Durable session start record
            if (publisherId && streamName) {
                _postMessage(Q, {
                    publisherId, streamName, byUserId: userId,
                    type:         'Media/presentation/start',
                    instructions: JSON.stringify({ role, lang, mode }),
                });
            }
        });

        client.on('AI/transcription/session/chunk', function (buffer) {
            const session = sessions.get(client.id);
            if (!session) return;
            if (session.transcription) {
                session.transcription.send(buffer);
            }
        });

        // Browser WebSpeech result — client sends final transcript to server
        // Single handler for all utterance sources — WebSpeech, Deepgram echo,
        // and typed text from chat. All must send the same shape:
        //   { transcript, isFinal, confidence, speaker }
        // isFinal:false chunks (Deepgram interim) are silently dropped by
        // _onTranscript — only final utterances go through the pipeline.
        //
        // Typed text path (not yet wired on client): emit Streams/utterance with
        //   { transcript: text, isFinal: true, confidence: 1, speaker: userId }
        // Do NOT send { text: '...' } — _onTranscript checks chunk.transcript.
        //
        // The AI plugin intercepts this event and upgrades the pipeline with
        // Deepgram (via AI/transcription/session/chunk), LLM proposals, and
        // veto queue. Without AI plugin, classifier + VTT still run.
        client.on('Streams/utterance', function (data) {
            const session = sessions.get(client.id);
            if (!session) return;
            _onTranscript(session, data, AI, Q, Users);
        });

        client.on('AI/transcription/session/stop',  function () {
            const session = sessions.get(client.id);
            if (session) _closeTranscription(session);
        });

        client.on('AI/transcription/session/abort', function () {
            const session = sessions.get(client.id);
            if (session) _closeTranscription(session);
        });

        // Narration mode: feed an array of pre-written lines through the pipeline
        // with simulated timing. Proposals auto-commit (mode: 'narration').
        // Host-only — participants cannot drive the narration pipeline.
        // data: { lines: ['text1','text2',...], msPerLine: 3000 }
        client.on('AI/stream/narrate', function (data) {
            const session = sessions.get(client.id);
            if (!session || session.role !== 'host') return;
            const lines     = (data && Array.isArray(data.lines)) ? data.lines : [];
            const msPerLine = (data && data.msPerLine) || 3000;
            if (!lines.length) return;

            // Switch session to script mode so proposals auto-commit
            session.mode = 'narration';

            let i = 0;
            function feedNext() {
                if (i >= lines.length) return;
                const line = lines[i++].trim();
                if (line) {
                    _onTranscript(session, {
                        transcript: line, isFinal: true, confidence: 1, speaker: userId
                    }, AI, Q, Users);
                }
                setTimeout(feedNext, msPerLine);
            }
            feedNext();
        });

        // Host veto actions
        client.on('AI/veto/commit', function (data) {
            const session = sessions.get(client.id);
            if (!session) return;
            _commitProposal(session, data && data.proposalId, AI, Q, Users);
        });

        client.on('AI/veto/cancel', function (data) {
            const session = sessions.get(client.id);
            if (!session) return;
            _cancelProposal(session, data && data.proposalId, Users);
        });

        // Client reports when a generated tool was committed and shown on screen
        client.on('AI/tool/committed', function (data) {
            const session = sessions.get(client.id);
            if (!session || !session.publisherId || !session.streamName) return;
            const toolName  = data && data.toolName;
            if (!toolName) return;
            const relSec    = ((Date.now() - session.sessionStartMs) / 1000).toFixed(1);
            // Store toolName + relSec. Code is NOT stored inline (can be large);
            // it lives in the tool's sessionStorage on the client and should be
            // saved to a Media/tool/generated stream for replay — future work.
            const toolInstr = JSON.stringify({ toolName: toolName, relSec: relSec });
            const Str = Q.require('Streams');
            Str.Message.post({
                publisherId:  session.publisherId,
                streamName:   session.streamName,
                byUserId:     session.userId,
                byClientId:   '',
                weight:       1,
                type:         'Media/presentation/tool/show',
                instructions: toolInstr,
            }, function (err, message) {
                if (!err && message) {
                    transcriptEmitter._appendVttEventNote(
                        session,
                        'Media/presentation/tool/show',
                        message.fields.ordinal,
                        toolInstr,
                        Q,
                        message.fields.sentTime
                    );
                }
            });
        });

        client.on('disconnect', function () {
            const session = sessions.get(client.id);
            if (!session) return;
            _closeTranscription(session);
            session.vetoTimers.forEach(t => clearTimeout(t));
            transcriptEmitter.emitSessionEnd(session);
            AI.emit('sessionEnd', userId,
                session.publisherId, session.streamName, {
                    transcriptFile: session.transcriptFile,
                    chunkCount:     session.transcriptBuffer.length
                });

            // Durable session end record
            if (session.publisherId && session.streamName) {
                _postMessage(Q, {
                    publisherId: session.publisherId,
                    streamName:  session.streamName,
                    byUserId:    userId,
                    type:        'Media/presentation/end',
                    instructions: JSON.stringify({
                        relSec:                 ((Date.now() - session.sessionStartMs) / 1000).toFixed(1),
                        transcriptMessageCount: session.transcriptBuffer.length,
                    }),
                });
            }
            sessions.delete(client.id);
            Q.log && Q.log('AI session ended', userId, client.id);
        });
    });
}

// ── Transcription adapter ────────────────────────────────────────────────────

function _openTranscription(session, AI, Q, Users) {
    // Resolve provider: explicit config wins, legacy AI/deepgram/key falls back to deepgram
    const provider = (Q.Config && Q.Config.get(['AI', 'transcription', 'provider'], null))
        || (Q.Config && Q.Config.get(['AI', 'deepgram', 'key'], null) ? 'deepgram' : null);
    if (!provider) return;

    const adapter = AI_Transcription.create(provider);
    if (!adapter) {
        Q.log && Q.log('AI: unknown transcription provider:', provider);
        return;
    }

    session.transcription = adapter;

    adapter.open(session, {
        Q,
        onUtterance: function (chunk) {
            // Interim chunks: echo immediately for live caption display.
            // Final chunks: _onTranscript re-emits with relSec after pipeline runs.
            if (!chunk.isFinal) {
                Users.Socket.emitToUser(session.userId, 'Streams/utterance', chunk);
            }
            _onTranscript(session, chunk, AI, Q, Users);
        },
        onError: function (e) {
            Users.Socket.emitToUser(session.userId, 'AI/error', {
                message: (adapter.platform || 'Transcription') + ' error: ' + e.message,
                code: 502
            });
        }
    });
}

function _closeTranscription(session) {
    if (session.transcription) {
        session.transcription.close();
        session.transcription = null;
    }
}

// ── Transcript pipeline ───────────────────────────────────────────────────────

async function _onTranscript(session, chunk, AI, Q, Users) {
    if (!chunk.isFinal || !chunk.transcript || !chunk.transcript.trim()) return;

    const text  = chunk.transcript.trim();
    const nowMs = Date.now();

    // Append to rolling buffer
    const entry = {
        text,
        ts:      nowMs,
        relSec:  ((nowMs - session.sessionStartMs) / 1000).toFixed(1),
        speaker: chunk.speaker || session.userId,
        isFinal: true,
    };
    session.transcriptBuffer.push(entry);
    if (session.transcriptBuffer.length > 8) session.transcriptBuffer.shift();

    // Post durable transcript message to stream, then write VTT cue with the ordinal.
    // Ordering: Node events (AI.emit, socket fan-out) fire immediately.
    // VTT write is deferred to the message post callback so the ordinal is known.
    // If no stream, write VTT immediately with no ordinal.
    // Resolve public display name for the speaker lazily (once per userId per session).
    // Stored in session._displayNames so TranscriptEmitter can write it in the <v> tag.
    // Uses a direct DB query via Streams_Avatar — fetchByPrefix with exact userId as prefix
    // returns the single matching row (same logic as PHP Streams_Avatar::fetch).
    const speakerUserId = entry.speaker || session.userId;
    if (speakerUserId && session._displayNames
    && !(speakerUserId in session._displayNames)) {
        // Placeholder immediately — prevents duplicate fetches on concurrent utterances
        session._displayNames[speakerUserId] = speakerUserId;
        try {
            const Db      = Q.require('Db');
            const Streams = Q.require('Streams');
            const Avatar  = Streams && Streams.Avatar;
            if (Avatar && Avatar.SELECT) {
                Avatar.SELECT('*')
                    .where({ toUserId: ['', speakerUserId], publisherId: speakerUserId })
                    .limit(1)
                    .execute(function (err, rows) {
                        if (err || !rows || !rows.length) return;
                        const f = rows[0].fields;
                        const name = [f.firstName, f.lastName].filter(Boolean).join(' ').trim()
                                   || f.username || speakerUserId;
                        if (name && session._displayNames) {
                            session._displayNames[speakerUserId] = name;
                        }
                    });
            }
        } catch (e) {
            // Streams.Avatar not available — userId stays as fallback, fine for demo
        }
    }

    // Rolling context: last 3 chunks joined (catches split control commands)
    const recent3 = session.transcriptBuffer.slice(-3).map(e => e.text).join(' ');

    // 1. Control classifier — instant, zero cost, runs FIRST so the flag is
    //    available when we write the transcript message and VTT cue.
    let isControl = false;
    if (session.role === 'host') {
        const classifyState = {
            slideIndex:      session.slideIndex,
            revealIndex:     session.revealIndex,
            zoomScale:       session.zoomScale,
            userId:          session.userId,
            publisherId:     session.publisherId,
            streamName:      session.streamName,
            toolStreamName:  session.toolStreamName || null,
            toolPublisherId: session.userId,
            Q,
            Users,
        };
        const streamProxy = session.publisherId
            ? _makeStreamProxy(session, Q, Users)
            : null;
        if (streamProxy && session.modes.navigation !== false
        && session.classifier.classify(recent3, streamProxy, classifyState)) {
            isControl = true;
        }
    }

    // 2. Post durable transcript message, then write VTT cue with ordinal.
    if (session.publisherId && session.streamName) {
        const Str = Q.require('Streams');
        Str.Message.post({
            publisherId:  session.publisherId,
            streamName:   session.streamName,
            byUserId:     entry.speaker || session.userId,
            byClientId:   '',
            weight:       1,
            type:         'Media/presentation/transcript',
            content:      entry.text,
            instructions: JSON.stringify({
                speaker:    entry.speaker || session.userId,
                relSec:     entry.relSec,
                isFinal:    true,
                confidence: chunk.confidence || 1,
                control:    isControl || undefined,
            }),
        }, function (err, message) {
            const ordinal = (!err && message) ? message.fields.ordinal : null;
            transcriptEmitter.emitChunk(session, entry, ordinal,
                { control: isControl });
            if (ordinal != null && session.transcriptFile) {
                _generateCueAudio(session, entry, ordinal, Q);
            }
        });
    } else {
        transcriptEmitter.emitChunk(session, entry, null,
            { control: isControl });
    }

    // 3. Post to Streams/chat as a chat message when transcription mode is on.
    //    Each person posts on their own behalf via their own socket/userId.
    //    No diarization needed — each session is one person.
    if (session.modes.transcription !== false && session.publisherId && session.streamName) {
        const StrChat = Q.require('Streams');
        StrChat.Message.post({
            publisherId:  session.publisherId,
            streamName:   session.streamName,
            byUserId:     entry.speaker || session.userId,
            byClientId:   '',
            weight:       1,
            type:         'Streams/chat/message',
            content:      entry.text,
            instructions: JSON.stringify({
                isTranscript: true,
                relSec:   entry.relSec,
                control:  isControl || undefined,
            }),
        }, function (err) {
            if (err) Q.log && Q.log('transcript chat post error:', err.message || err);
        });
    }

    AI.emit('transcript',
        session.userId, session.publisherId, session.streamName,
        Object.assign({}, entry)
    );
    Users.Socket.emitToUser(session.userId, 'Streams/utterance', {
        transcript: entry.text,
        isFinal:    true,
        confidence: chunk.confidence,
        speaker:    entry.speaker,
        relSec:     entry.relSec,
    });

    // 3. LLM pipeline — only if not a control command
    if (!isControl && session.role === 'host' && session.modes.composition !== false) {
        await _processChunk(session, text, AI, Q, Users);
    }
}

async function _processChunk(session, text, AI, Q, Users) {
    if (!session.pipeline) {
        session.pipeline = new Pipeline({
            Q,
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
                Q.log && Q.log('AI: topic shift', fromTopic, '->', toTopic);
                const topicRelSec = ((Date.now() - session.sessionStartMs) / 1000).toFixed(1);
                transcriptEmitter.emitTopicChange(session, fromTopic, toTopic, topicRelSec);
                AI.emit('topicChange',
                    session.userId, session.publisherId, session.streamName,
                    { from: fromTopic, to: toTopic,
                      isOwnLivestream: !!session.isOwnLivestream }
                );
                // Fan out topic change to all /Q clients
                Users.Socket.emitToUser(session.userId, 'AI/topicChange', {
                    from: fromTopic, to: toTopic, relSec: topicRelSec
                });
                // Durable topic record
                if (session.publisherId && session.streamName) {
                    _postMessage(Q, {
                        publisherId:  session.publisherId,
                        streamName:   session.streamName,
                        byUserId:     session.userId,
                        type:         'Media/presentation/topic',
                        instructions: JSON.stringify({ from: fromTopic, to: toTopic, relSec: topicRelSec }),
                    });
                }
            }
        });
    }

    let result = null;
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
            // Relay to all /Q clients — they forward to the presentation stream
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
            session.userId, session.publisherId, session.streamName, result
        );
        _enqueueVeto(session, result, AI, Q, Users);
    }
}

// ── Veto window ───────────────────────────────────────────────────────────────

function _enqueueVeto(session, proposal, AI, Q, Users) {
    const proposalId = proposal.proposalId || ('prop_' + Date.now());
    proposal.proposalId = proposalId;

    // In script/tts mode, auto-commit immediately — no human veto needed
    if (session.mode === 'narration') {
        session.vetoQueue.push(proposal);
        _commitProposal(session, proposalId, AI, Q, Users);
        return;
    }

    const windowMs = proposal.confidence > 0.9 ? 3000 : 5000;

    session.vetoQueue.push(proposal);

    // Show proposal card on host's control page
    Users.Socket.emitToUser(session.userId, 'AI/veto/show', { proposal, windowMs });

    const timer = setTimeout(() => {
        _commitProposal(session, proposalId, AI, Q, Users);
    }, windowMs);
    session.vetoTimers.set(proposalId, timer);
}

function _commitProposal(session, proposalId, AI, Q, Users) {
    const timer = session.vetoTimers.get(proposalId);
    if (timer) { clearTimeout(timer); session.vetoTimers.delete(proposalId); }

    const proposal = session.vetoQueue.find(p => p.proposalId === proposalId);
    if (!proposal) return;
    session.vetoQueue = session.vetoQueue.filter(p => p.proposalId !== proposalId);

    _createAndShowStream(session, proposal, AI, Q, Users);

    AI.emit('commit',
        session.userId, session.publisherId, session.streamName, proposal
    );
    Users.Socket.emitToUser(session.userId, 'AI/veto/commit', { proposalId });
}

function _cancelProposal(session, proposalId, Users) {
    const timer = session.vetoTimers.get(proposalId);
    if (timer) { clearTimeout(timer); session.vetoTimers.delete(proposalId); }
    session.vetoQueue = session.vetoQueue.filter(p => p.proposalId !== proposalId);
    Users.Socket.emitToUser(session.userId, 'AI/veto/cancel', { proposalId });
}

// ── Stream creation ───────────────────────────────────────────────────────────

async function _createAndShowStream(session, proposal, AI, Q, Users) {
    const typeMap = {
        stat:       'Media/card/stat',
        glossary:   'Media/card/glossary',
        profile:    'Media/card/profile',
        quote:      'Media/card/quote',
        article:    'Media/card/article',
        comparison: 'Media/card/comparison',
        barChart:   'Media/chart/bar',
        lineChart:  'Media/chart/line',
        map:        'Media/card/map',
        slide:      'Media/card/slide',
    };

    // Graph and table are not card proposals — they go through the ephemeral
    // update path (Media/presentation/graph/update, Media/presentation/table/update)
    // which fan out to the Q/visualization/graph and Q/visualization/table tools.
    const vizType = proposal.visualizationType;
    if (vizType === 'graph' || vizType === 'table') {
        const ephType = vizType === 'graph'
            ? 'Media/presentation/graph/update'
            : 'Media/presentation/table/update';
        const data = proposal.visualizationData || {};
        Users.Socket.emitToUser(session.userId, 'AI/ephemeral', {
            publisherId: session.publisherId,
            streamName:  session.streamName,
            type:        ephType,
            payload:     data,
        });
        return;
    }

    // Slide: AI-composed HTML — create a Media/slide stream then fan out
    if (vizType === 'slide') {
        _createAndShowSlide(session, proposal, AI, Q, Users);
        return;
    }

    const streamType = typeMap[vizType] || 'Media/card/glossary';

    Q.log && Q.log('AI: committing proposal', streamType, proposal.proposalId);

    const cardRelSec = ((Date.now() - session.sessionStartMs) / 1000).toFixed(1);

    // Fan out AI/proposal/show to all /Q clients — control.js relays this
    // to the presentation stream, which the ?f=1 screen renders.
    Users.Socket.emitToUser(session.userId, 'AI/proposal/show', {
        proposalId:        proposal.proposalId,
        visualizationType: proposal.visualizationType,
        visualizationData: proposal.visualizationData,
        streamType,
    });

    // Durable card record — part of the presentation recording
    if (session.publisherId && session.streamName) {
        const cardInstructions = JSON.stringify({
            visualizationType: proposal.visualizationType,
            visualizationData: proposal.visualizationData,
            streamType,
            proposalId:        proposal.proposalId,
            relSec:            cardRelSec,
        });
        const Str = Q.require('Streams');
        Str.Message.post({
            publisherId:  session.publisherId,
            streamName:   session.streamName,
            byUserId:     session.userId,
            byClientId:   '',
            weight:       1,
            type:         'Media/presentation/card/show',
            instructions: cardInstructions,
        }, function (err, message) {
            // Write VTT NOTE so standalone readers know a card appeared
            if (!err && message) {
                transcriptEmitter._appendVttEventNote(
                    session,
                    'Media/presentation/card/show',
                    message.fields.ordinal,
                    cardInstructions,
                    Q,
                    message.fields.sentTime
                );
            }
        });
    }
}

// ── Helpers ───────────────────────────────────────────────────────────────────

/**
 * Lightweight stream proxy for ControlClassifier.
 * Emits ephemerals by fanning out to all /Q clients,
 * who forward them to the presentation stream.
 */
function _makeStreamProxy(session, Q, Users) {
    return {
        ephemeral: function (type, payload) {
            if (!session.publisherId) return;
            Users.Socket.emitToUser(session.userId, 'AI/ephemeral', {
                publisherId: session.publisherId,
                streamName:  session.streamName,
                type,
                payload
            });
            // Track state for relative commands
            if (type === 'Streams/slide' && payload.slideIndex != null) {
                session.slideIndex = payload.slideIndex;
                // Post durable slide message + VTT NOTE
                const slideRelSec = ((Date.now() - session.sessionStartMs) / 1000).toFixed(1);
                const slideInstr  = JSON.stringify({ index: payload.slideIndex, relSec: slideRelSec });
                const Str = Q.require('Streams');
                Str.Message.post({
                    publisherId:  session.publisherId,
                    streamName:   session.streamName,
                    byUserId:     session.userId,
                    byClientId:   '',
                    weight:       1,
                    type:         'Media/presentation/slide',
                    instructions: slideInstr,
                }, function (err, message) {
                    if (!err && message) {
                        transcriptEmitter._appendVttEventNote(
                            session,
                            'Media/presentation/slide',
                            message.fields.ordinal,
                            slideInstr,
                            Q,
                            message.fields.sentTime
                        );
                    }
                });
            }
            if (type === 'Streams/reveal' && payload.revealIndex != null) {
                session.revealIndex = payload.revealIndex;
            }
            if (type === 'Streams/zoom' && payload.scale != null) {
                session.zoomScale = payload.scale;
            }
        }
    };
}

/**
 * Handle a slide proposal — delegates to slideGenerate command handler.
 */
function _createAndShowSlide(session, proposal, AI, Q, Users) {
    slideGenerate(session, proposal, AI, Q, Users).catch(function (e) {
        Q.log && Q.log('slideGenerate error:', e.message);
    });
}

module.exports = { register };

// ── TTS audio file generation ─────────────────────────────────────────────────

/**
 * Pre-generate a TTS audio file for a transcript cue.
 * Saved alongside the VTT file as cue-{ordinal}.mp3 for replay.
 * Uses the Voice provider configured at AI/voice/provider.
 * Fire-and-forget — never blocks the transcript pipeline.
 *
 * @param {Object} session
 * @param {Object} entry   { text, speaker, relSec }
 * @param {Number} ordinal
 * @param {Object} Q
 */
async function _generateCueAudio(session, entry, ordinal, Q) {
    const AI_Voice = require('../classes/AI/Voice');
    try {
        const provider = Q.Config && Q.Config.get(['AI', 'voice', 'provider'], null);
        if (!provider) return;
        const voice = AI_Voice.create(provider);
        if (!voice) return;
        const audioB64 = await voice.speak(entry.text, { Q });
        if (!audioB64) return;

        // Write cue-{ordinal}.mp3 alongside transcript.vtt
        const fs   = require('fs');
        const path = require('path');
        const dir  = path.dirname(session.transcriptFile);
        const file = path.join(dir, 'cue-' + ordinal + '.mp3');
        const buf  = Buffer.from(audioB64, 'base64');

        fs.writeFile(file, buf, (err) => {
            if (err) Q.log && Q.log('AI: cue audio write error', err.message);
        });
    } catch (e) {
        Q.log && Q.log('AI: _generateCueAudio error', e.message);
    }
}
