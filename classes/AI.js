"use strict";
/**
 * AI plugin — Node.js module
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

/**
 * Events emitted on the AI object (Node-side, for server plugins):
 *
 *   AI.on('transcript', function (userId, publisherId, streamName, chunk) {})
 *     chunk: { transcript, isFinal, confidence, speaker, relSec }
 *     Fires on every final utterance. Use for server-side keyword hooks,
 *     logging, feeding other pipelines.
 *
 *   AI.on('topicChange', function (userId, publisherId, streamName, evt) {})
 *     evt: { from, to, relSec, isOwnLivestream }
 *     Fires when the LLM pipeline detects a topic shift.
 *
 *   AI.on('proposal', function (userId, publisherId, streamName, proposal) {})
 *     Fires when a proposal enters the veto queue.
 *
 *   AI.on('commit', function (userId, publisherId, streamName, proposal) {})
 *     Fires when a proposal is committed (approved by silence or explicit commit).
 *
 *   AI.on('sessionStart', function (userId, publisherId, streamName, data) {})
 *   AI.on('sessionEnd',   function (userId, publisherId, streamName, data) {})
 *
 * Socket events delivered to the user's clients on /Q namespace
 * (via Users.Socket.emitToUser — same pattern as Streams/join etc.):
 *
 *   Streams/utterance  { transcript, isFinal, confidence, speaker, relSec }
 *   AI/veto/show      { proposal, windowMs }     — host only
 *   AI/veto/commit    { proposalId }
 *   AI/veto/cancel    { proposalId }
 *   AI/coaching       { text, sourceUri }         — host only
 *   AI/proposal/show  { proposalId, visualizationType, visualizationData, streamType }
 *   AI/error          { message, code }
 *
 * Client subscribes with:
 *   Q.Socket.onEvent('Streams/utterance').set(function (data) { ... }, tool);
 */

/* * * */
