"use strict";

/**
 * AI/handlers/AI/commands/imageGenerate.js
 *
 * Q.handler for the 'image/generate' voice command intent.
 * Loaded automatically by Bootstrap.loadHandlers() into Q.handlers.AI.commands.imageGenerate.
 *
 * Called by ControlClassifier when it matches an image/generate intent.
 * Generates an image via Vertex AI Imagen (or falls back to a Pexels query)
 * and adds it to the presentation's background gallery.
 *
 * Config (AI/image/vertex in plugin.json or local/app.json):
 *   AI.image.provider          "vertex" | "pexels" (default: pexels if no key)
 *   AI.image.vertex.projectId  GCP project id
 *   AI.image.vertex.location   e.g. "us-central1"
 *   AI.image.vertex.model      e.g. "imagegeneration@006"
 *   AI.image.vertex.provisionedThroughput  true = use provisioned endpoint
 *   Q.internal.secret          Used to sign internal API calls
 *
 * @param {Object} captures   { prompt: String } from ControlClassifier
 * @param {Object} stream     Stream proxy with .ephemeral(type, payload)
 * @param {Object} state      Presentation state
 * @param {Object} Q          Server-side Q object
 */
module.exports = async function imageGenerate(captures, stream, state, Q) {
    const prompt = captures && captures.prompt;
    if (!prompt || !prompt.trim()) return;

    const provider = Q.Config
        ? Q.Config.get(['AI', 'image', 'provider'], 'pexels')
        : 'pexels';

    Q.log && Q.log('AI/imageGenerate: generating "' + prompt + '" via ' + provider);

    try {
        let imageUrl = null;

        if (provider === 'vertex') {
            imageUrl = await _generateVertex(prompt, Q);
        }

        if (!imageUrl) {
            // Fallback: fire a Pexels gallery query — fast, free, no 429 risk
            stream.ephemeral('Streams/gallery/query', { query: prompt });
            return;
        }

        // Add the generated image to the gallery as a new slide.
        // gallery/add payload is the image object directly (not wrapped in an array).
        stream.ephemeral('Streams/gallery/add', {
            src:                imageUrl,
            caption:            prompt,
            insertAfterCurrent: true,
            interval:           { type: 'kenburns', duration: 8000 }
        });

    } catch (e) {
        Q.log && Q.log('AI/imageGenerate error:', e.message);
        // Fallback to Pexels query silently
        stream.ephemeral('Streams/gallery/query', { query: prompt });
    }
};

async function _generateVertex(prompt, Q) {
    const cfg      = (Q.Config && Q.Config.get(['AI', 'image', 'vertex'], {})) || {};
    const project  = cfg.projectId;
    const location = cfg.location  || 'us-central1';
    const model    = cfg.model     || 'imagegeneration@006';

    if (!project) {
        Q.log && Q.log('AI/imageGenerate: no vertex projectId configured');
        return null;
    }

    // Use provisioned throughput endpoint to avoid 429s during live demos
    const provisioned = cfg.provisionedThroughput;
    // Provisioned throughput uses a dedicated endpoint URL that bypasses
    // quota limits. Non-provisioned uses the standard predict endpoint.
    const baseUrl = provisioned
        ? `https://${location}-aiplatform.googleapis.com/v1/projects/${project}`
          + `/locations/${location}/endpoints/openapi/chat/completions`
        : `https://${location}-aiplatform.googleapis.com/v1/projects/${project}`
          + `/locations/${location}/publishers/google/models/${model}:predict`;

    // Get access token via internal secret — same signing pattern as Q.Utils.signature
    const accessToken = await _getAccessToken(Q);
    if (!accessToken) return null;

    const body = JSON.stringify({
        instances: [{ prompt }],
        parameters: {
            sampleCount: 1,
            aspectRatio: '16:9',
            safetyFilterLevel: 'block_some',
            personGeneration: 'allow_adult'
        }
    });

    const resp = await fetch(baseUrl, {
        method: 'POST',
        headers: {
            'Authorization': 'Bearer ' + accessToken,
            'Content-Type':  'application/json'
        },
        body
    });

    if (!resp.ok) {
        Q.log && Q.log('AI/imageGenerate: Vertex responded', resp.status);
        return null;
    }

    const data = await resp.json();
    const b64 = data.predictions && data.predictions[0] && data.predictions[0].bytesBase64Encoded;
    if (!b64) return null;

    // Return as data URL — small images only, for demo use
    // Production: save to uploads/Streams/ and return a proper URL
    return 'data:image/png;base64,' + b64;
}

async function _getAccessToken(Q) {
    // In a real deployment this would use Application Default Credentials
    // or the internal secret to call the token endpoint.
    // For now: check config for a static token (useful during demos)
    // or return null to fall back to Pexels.
    const token = Q.Config && Q.Config.get(['AI', 'image', 'vertex', 'accessToken'], null);
    return token || null;
}
