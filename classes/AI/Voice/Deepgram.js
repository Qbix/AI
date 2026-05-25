"use strict";

/**
 * AI/classes/AI/Voice/Deepgram.js
 *
 * Deepgram streaming adapter for Q.Speech.Recognition.
 * Implements the { start, stop, abort } interface that
 * Q.Speech.Recognition.implement() expects.
 *
 * When loaded, this overrides the browser's SpeechRecognition backend.
 * The caller's Q.Speech.Recognition.onResult handler receives chunks
 * identical in shape to the browser API output — { transcript, isFinal,
 * confidence, speaker } — but now streamed from a server-side Deepgram
 * connection with server-side VAD.
 *
 * SPEAKER IDENTITY MODEL
 * ──────────────────────
 * Each participant's device captures their own microphone via getUserMedia
 * and streams audio over their own authenticated socket connection.
 * The server knows whose audio it is from the connection itself —
 * no acoustic speaker identification or diarization of a mixed stream needed.
 * The { speaker } field in onResult chunks carries the authenticated userId
 * from the socket session, set by the Node handler, not inferred from audio.
 *
 * This means access control decisions (host vs guest permissions) are based
 * on the authenticated socket identity, not on voice analysis. A guest cannot
 * gain host permissions by mimicking the host's voice.
 *
 * The browser still captures the audio via getUserMedia or
 * getDisplayMedia (for tab audio); the PCM stream is sent to the
 * Node socket which forwards to Deepgram's WebSocket API. Transcripts
 * come back through the socket and are delivered to Q.Speech.Recognition
 * event handlers on the client.
 *
 * INSTALL
 * ───────
 * In AI/socket.js (Node side), handle the audio chunk stream from the
 * client and forward to Deepgram. On transcript, emit back to the client:
 *   socket.emit('Streams/utterance', { transcript, isFinal, confidence, speaker });
 *
 * On the client (Intelligence Window page or dashboard):
 *   const deepgram = new DeepgramAdapter({ socketNs: '/Q', socketUrl: Q.info.nodeUrl });
 *   await deepgram.connect();
 *   Q.Speech.Recognition.implement(deepgram);
 *   Q.Speech.Recognition.start({ lang: 'en-US' });
 *
 * @module AI
 * @class DeepgramAdapter
 */

class DeepgramAdapter {

    /**
     * @param {Object} options
     * @param {String} [options.socketNs='/Q']      Socket.io namespace
     * @param {String} [options.socketUrl]          Node URL (defaults to Q.info.nodeUrl)
     * @param {Number} [options.sampleRate=16000]   PCM sample rate sent to Deepgram
     * @param {Number} [options.chunkMs=100]        Audio chunk interval in milliseconds
     */
    constructor(options = {}) {
        this.socketNs   = options.socketNs   || '/Q';
        this.socketUrl  = options.socketUrl  || (Q.info && Q.info.nodeUrl ? Q.info.nodeUrl : '');
        this.sampleRate = options.sampleRate || 16000;
        this.chunkMs    = options.chunkMs    || 100;

        this._socket       = null;
        this._mediaStream  = null;
        this._audioContext = null;
        this._processor    = null;
        this._active       = false;
    }

    // ── Q.Speech.Recognition interface ───────────────────────────────────────

    /**
     * Start audio capture and streaming.
     * Calls getUserMedia for microphone, connects to AI socket,
     * then calls Q.Speech.Recognition.implement(this).
     *
     * @param {Object} [options]
     * @param {String} [options.lang='en-US']
     * @param {String} [options.source='microphone']  'microphone' or 'tab'
     */
    async start(options = {}) {
        if (this._active) return;
        this._active = true;
        this._lang = options.lang || 'en-US';

        try {
            // 1. Connect socket
            this._socket = await this._connectSocket();

            // 2. Capture audio
            if (options.source === 'tab') {
                // Tab audio for headless listener page
                this._mediaStream = await navigator.mediaDevices.getDisplayMedia({
                    video: false,
                    audio: { echoCancellation: false, noiseSuppression: false }
                });
            } else {
                this._mediaStream = await navigator.mediaDevices.getUserMedia({
                    audio: { echoCancellation: true, noiseSuppression: true, sampleRate: this.sampleRate }
                });
            }

            // 3. Start PCM pipeline
            this._startAudioPipeline();

            // 4. Wire transcript events from socket to Q.Speech.Recognition
            this._socket.on('Streams/utterance', this._onTranscript.bind(this));
            this._socket.on('AI/vad/start',  () => Q.handle(Q.Speech.Recognition.onSpeechStart, Q.Speech.Recognition));
            this._socket.on('AI/vad/end',    () => Q.handle(Q.Speech.Recognition.onSpeechEnd,   Q.Speech.Recognition));

            // 5. Wire any on* handlers passed in options onto shared events
            // (same pattern as Q.Speech.Recognition.start does for browser path)
            var evtNames = ['onStart', 'onEnd', 'onResult', 'onError', 'onSpeechStart', 'onSpeechEnd'];
            evtNames.forEach(function (name) {
                if (options && options[name] && Q.Speech && Q.Speech.Recognition && Q.Speech.Recognition[name]) {
                    Q.Speech.Recognition[name].set(options[name], 'deepgramStart');
                }
            });

            // NOTE: AI/transcription/session/start is NOT emitted here.
            // control.js._connectAISocket() emits it with the full param set
            // (publisherId, streamName, role, mode, toolStreamName, etc.).
            // Emitting session/start here would create a bare second session
            // on the server, overwriting the fully-parameterised one.

            Q.handle(Q.Speech.Recognition.onStart, Q.Speech.Recognition);

        } catch (err) {
            this._active = false;
            Q.handle(Q.Speech.Recognition.onError, Q.Speech.Recognition,
                [{ error: err.name === 'NotAllowedError' ? 'not-allowed' : 'audio-capture' }]);
        }
    }

    /**
     * Stop cleanly — flushes pending audio, fires onend.
     */
    stop() {
        if (!this._active) return;
        this._teardown('stop');
    }

    /**
     * Abort immediately — discards pending audio, fires onend.
     */
    abort() {
        if (!this._active) return;
        this._teardown('abort');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    _connectSocket() {
        return new Promise((resolve, reject) => {
            if (typeof Q !== 'undefined' && Q.Socket) {
                Q.Socket.connect(this.socketNs, this.socketUrl, null, {
                    earlyCallback: function (qs) {
                        // If already connected, resolve immediately —
                        // the 'connect' event won't fire again on an
                        // already-open socket, so the Promise would hang.
                        if (qs.connected) {
                            resolve(qs.socket);
                            return;
                        }
                        qs.socket.on('connect', function () { resolve(qs.socket); });
                        qs.socket.on('connect_error', reject);
                    }
                });
            } else {
                // Fallback for headless listener page without Q loaded
                const io = window.io;
                if (!io) { reject(new Error('socket.io not loaded')); return; }
                const s = io(this.socketUrl + this.socketNs, { transports: ['websocket'] });
                s.on('connect', () => resolve(s));
                s.on('connect_error', reject);
            }
        });
    }

    _startAudioPipeline() {
        this._audioContext = new AudioContext({ sampleRate: this.sampleRate });
        const source = this._audioContext.createMediaStreamSource(this._mediaStream);
        // createScriptProcessor requires power-of-2 buffer size
        var rawSize = this.sampleRate * this.chunkMs / 1000;
        var bufferSize = 256;
        while (bufferSize < rawSize) bufferSize *= 2;  // e.g. 1600 → 2048

        this._processor = this._audioContext.createScriptProcessor(bufferSize, 1, 1);
        this._processor.onaudioprocess = (e) => {
            if (!this._active || !this._socket) return;
            // Convert Float32 to Int16 PCM
            const float32 = e.inputBuffer.getChannelData(0);
            const int16   = new Int16Array(float32.length);
            for (let i = 0; i < float32.length; i++) {
                int16[i] = Math.max(-32768, Math.min(32767, Math.round(float32[i] * 32767)));
            }
            this._socket.emit('AI/transcription/session/chunk', int16.buffer);
        };

        source.connect(this._processor);
        // Do NOT connect processor to destination — that would echo mic back through speakers.
        // The processor only needs to be in the graph to receive onaudioprocess events.
        // Connect to a silent gain node to satisfy some browser implementations.
        var silentGain = this._audioContext.createGain();
        silentGain.gain.value = 0;
        this._processor.connect(silentGain);
        silentGain.connect(this._audioContext.destination);
    }

    _onTranscript(data) {
        // data: { transcript, isFinal, confidence, speaker, words? }
        Q.handle(Q.Speech.Recognition.onResult, Q.Speech.Recognition, [{
            transcript: data.transcript,
            isFinal:    data.isFinal,
            confidence: data.confidence != null ? data.confidence : 1,
            speaker:    data.speaker    || null,
            words:      data.words      || null
        }]);
    }

    _teardown(how) {
        this._active = false;

        if (this._processor) {
            try { this._processor.disconnect(); } catch (e) {}
            this._processor = null;
        }
        if (this._audioContext) {
            try { this._audioContext.close(); } catch (e) {}
            this._audioContext = null;
        }
        if (this._mediaStream) {
            this._mediaStream.getTracks().forEach(t => t.stop());
            this._mediaStream = null;
        }
        if (this._socket) {
            this._socket.emit('AI/transcription/session/' + how); // 'AI/transcription/session/stop' or 'AI/transcription/session/abort'
        }

        Q.handle(Q.Speech.Recognition.onEnd, Q.Speech.Recognition);
    }
}

module.exports = DeepgramAdapter;
