"use strict";

/**
 * @module AI
 */

var Session = require('./Session');
var transcriptEmitter = require('../../../Streams/classes/Streams/TranscriptEmitter').transcriptEmitter;

/**
 * Lightweight stream proxy for ControlClassifier.
 * The classifier needs to emit ephemerals; we route those to all of the
 * user's /Q clients (who forward them to the presentation stream) and
 * keep server-side slide/reveal/zoom state in sync.
 *
 * @class AI.StreamProxy
 * @static
 */
function StreamProxy() {}

/**
 * Build a proxy bound to a session. The classifier calls
 * `proxy.ephemeral(type, payload)` — this implementation fans out + tracks state.
 * @method make
 * @static
 */
StreamProxy.make = function (session, Q, Users) {
    return {
        ephemeral: function (type, payload) {
            if (!session.publisherId) return;
            Users.Socket.emitToUser(session.userId, 'AI/ephemeral', {
                publisherId: session.publisherId,
                streamName:  session.streamName,
                type:        type,
                payload:     payload
            });

            if (type === 'Streams/slide' && payload.slideIndex != null) {
                session.slideIndex = payload.slideIndex;
                StreamProxy._postSlideRecord(session, payload.slideIndex, Q);
            }
            if (type === 'Streams/reveal' && payload.revealIndex != null) {
                session.revealIndex = payload.revealIndex;
            }
            if (type === 'Streams/zoom' && payload.scale != null) {
                session.zoomScale = payload.scale;
            }
        }
    };
};

/**
 * Post a durable Media/presentation/slide message + VTT NOTE.
 * @method _postSlideRecord
 * @private
 * @static
 */
StreamProxy._postSlideRecord = function (session, slideIndex, Q) {
    var slideRelSec = Session.relSec(session);
    var slideInstr  = JSON.stringify({ index: slideIndex, relSec: slideRelSec });
    Session.postMessage(Q, {
        publisherId:  session.publisherId,
        streamName:   session.streamName,
        byUserId:     session.userId,
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
};

module.exports = StreamProxy;
