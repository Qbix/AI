"use strict";

/**
 * AI/handlers/AI/commands/toolGenerate.js
 *
 * Q.handler for the 'tool/generate' voice command intent.
 * Loaded automatically into Q.handlers.AI.commands.toolGenerate.
 *
 * THE CONCEPT (v2 feature)
 * ─────────────────────────
 * Host says "build a chess board" or "create a countdown timer".
 * The LLM generates a complete Q.Tool.define() implementation.
 * The control page evaluates it, activates it in host mode.
 * The shared screen activates the same tool in broadcast mode.
 *
 * TWO MODES of the generated tool (same Q.Tool.define, different options):
 *   mode: 'host'      — interactive, in the host's control pane
 *   mode: 'broadcast' — view-only or limited, on the shared ?f=1 screen
 *
 * This works without Safebots because:
 *   - Generated code runs in the browser, not on the server
 *   - No external API access from the tool itself
 *   - Host's own session, host's own browser
 *
 * For v2.1 with Safebots: generated tools that call external APIs,
 * persist state, or run server-side logic go through the capability
 * manifest and governed execution environment.
 *
 * SYSTEM PROMPT TEMPLATE
 * ───────────────────────
 * Loaded from AI/data/toolGenerate.prompt.txt (included in zip).
 * Contains the Q.Tool.define() signature with examples from the docs.
 * The LLM is asked to produce a self-contained tool definition as a
 * JS string that can be eval()'d safely in the browser.
 *
 * @param {Object} captures  { prompt: String }
 * @param {Object} stream    Stream proxy
 * @param {Object} state     Presentation state
 * @param {Object} Q         Server-side Q object
 */
const path = require('path');
const fs   = require('fs');

module.exports = async function toolGenerate(captures, stream, state, Q) {
    const prompt = captures && captures.prompt;
    if (!prompt || !prompt.trim()) return;

    Q.log && Q.log('AI/toolGenerate: generating tool for "' + prompt + '"');

    // Load system prompt template
    const templatePath = path.join(__dirname, '../../../data/toolGenerate.prompt.txt');
    let systemPrompt;
    try {
        systemPrompt = fs.readFileSync(templatePath, 'utf8');
    } catch (e) {
        Q.log && Q.log('AI/toolGenerate: could not load prompt template', e.message);
        return;
    }

    const apiKey = Q.Config && Q.Config.get(['AI', 'anthropic', 'key'], null);
    if (!apiKey) {
        Q.log && Q.log('AI/toolGenerate: no Anthropic key configured');
        return;
    }

    try {
        const resp = await fetch('https://api.anthropic.com/v1/messages', {
            method: 'POST',
            headers: {
                'x-api-key':         apiKey,
                'anthropic-version': '2023-06-01',
                'content-type':      'application/json'
            },
            body: JSON.stringify({
                model:      Q.Config.get(['AI', 'anthropic', 'model'], 'claude-sonnet-4-20250514'),
                max_tokens: 4000,
                system:     systemPrompt,
                messages: [{
                    role:    'user',
                    content: 'Build a Qbix tool for: ' + prompt.trim()
                            + '\n\nReturn ONLY the Q.Tool.define() call as a self-contained '
                            + 'JavaScript string. No explanation, no markdown fences.'
                }]
            })
        });

        if (!resp.ok) {
            Q.log && Q.log('AI/toolGenerate: LLM error', resp.status);
            return;
        }

        const data  = await resp.json();
        const code  = data.content && data.content[0] && data.content[0].text;
        if (!code || !code.trim()) return;

        // Sanitize: must start with Q.Tool.define(
        const cleaned = code.trim();
        if (!cleaned.startsWith('Q.Tool.define(') && !cleaned.startsWith('(function')) {
            Q.log && Q.log('AI/toolGenerate: LLM output did not look like a tool definition');
            return;
        }

        // Send directly to the host's clients via Users.Socket.emitToUser.
        // toolGenerate bypasses the stream-ephemeral path (which is for presentation
        // content visible to all) and goes socket-only to the host.
        // control.js listens for Q.Socket.onEvent('AI/tool/generated').
        const Users = state && state.Users;
        const userId = state && state.userId;
        if (Users && userId && typeof Users.Socket === 'object'
        && typeof Users.Socket.emitToUser === 'function') {
            Users.Socket.emitToUser(userId, 'AI/tool/generated', {
                toolName: 'AI/generated/' + prompt.trim()
                    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''),
                prompt:   prompt,
                code:     cleaned,
            });
        } else {
            // Fallback: send as stream ephemeral (reaches everyone, not ideal)
            stream.ephemeral('AI/tool/generated', {
                toolName: 'AI/generated/' + prompt.trim()
                    .toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''),
                prompt:   prompt,
                code:     cleaned,
            });
        }

        Q.log && Q.log('AI/toolGenerate: tool code sent to veto queue');

    } catch (e) {
        Q.log && Q.log('AI/toolGenerate: error', e.message);
    }
};
