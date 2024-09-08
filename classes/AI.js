"use strict";
/*jshint node:true */

/**
 * AI plugin
 * @module AI
 * @main AI
 */
var Q = require('Q');
var Streams = require('Streams');
var Db = Q.require('Db');

/**
 * AI methods for the AI model
 * @class AI
 * @static
 */
function AI() { }
module.exports = AI;

var Users = Q.plugins.Users;
var socket = null;

AI.Image = Q.require('AI/Image');

Q.makeEventEmitter(AI);

/**
 * Start internal listener for AI plugin. Accepts messages such as<br/>
 * "AI/Image/estimateFaces",
 * @method listen
 * @static
 * @param {Object} options={} So far no options are implemented.
 * @return {Object} Object with any servers that have been started, "internal" or "socket"
 */
AI.listen = function (options) {
    if (AI.listen.result) {
        return AI.listen.result;
    }

    // Start internal server
    var server = Q.listen();
    server.attached.express.post('/Q/node', function (req, res, next) {
        var parsed = req.body;
        if (!parsed || !parsed['Q/method']
            || !req.internal || !req.validated) {
            return next();
        }

        switch (parsed['Q/method']) {
            case 'AI/Image/estimateFaces':
                AI.Image.estimateFaces(Q.getObject("imagePath", parsed), function (predictions) {
                    var publisherId = Q.getObject("publisherId", parsed);
                    var streamName = Q.getObject("streamName", parsed);
                    if (!publisherId || !streamName) {
                        return console.warn("AI.Image.estimateFaces: stream not found");
                    }
                    Streams.fetchOne(publisherId, publisherId, streamName, function (err, stream) {
                        if (err || !stream) {
                            return;
                        }

                        stream.setAttribute("predictions", predictions);
                        stream.save();
                    });
                });
                //AI.Image.emit('post/'+msg.fields.type, stream, msg);
                break;
            default:
                break;
        }
        return next();
    });

    // Start external socket server
    var node = Q.Config.get(['Q', 'node']);
    if (!node) {
        return false;
    }
    var pubHost = Q.Config.get(['AI', 'node', 'host'], Q.Config.get(['Q', 'node', 'host'], null));
    var pubPort = Q.Config.get(['AI', 'node', 'port'], Q.Config.get(['Q', 'node', 'port'], null));

    if (pubHost === null) {
        throw new Q.Exception("AI: Missing config field: AI/node/host");
    }
    if (pubPort === null) {
        throw new Q.Exception("AI: Missing config field: AI/node/port");
    }

    /**
     * @property socketServer
     * @type {SocketNamespace}
     * @private
     */
    socket = Users.Socket.listen({
        host: pubHost,
        port: pubPort,
        https: Q.Config.get(['Q', 'node', 'https'], false) || {},
    });

    socket.io.of('/Q').on('connection', function(client) {
        if (client.alreadyListeningAI) {
            return;
        }
        client.alreadyListeningAI = true;
        client.on('disconnect', function () {

        });
    });

    return AI.listen.result = {
        internal: server,
        socket: socket
    };
};
