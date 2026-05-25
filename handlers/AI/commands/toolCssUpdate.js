"use strict";

/**
 * AI/handlers/AI/commands/toolCssUpdate.js
 *
 * Q.handler for CSS update commands on generated tools.
 * Loaded into Q.handlers.AI.commands.toolCssUpdate.
 *
 * When the host says "make it red and black" or "add shadows to the pieces",
 * the Pipeline returns action='ephemeral', ephemeralType='AI/tool/cssUpdate'.
 * This handler translates the natural language into a Q/style socket event
 * targeting the generated tool element by id.
 *
 * The Q/style event updates scoped CSS custom properties (--AI-accent,
 * --AI-bg, etc.) without re-evaluating or re-rendering the tool.
 * The listenForStyle handler on the client (Q/web/js/methods/Q/Socket/listenForStyle.js)
 * injects a scoped <style> tag replacing the previous one — idempotent.
 *
 * @param {Object} captures  { prompt, elementId, toolName }
 * @param {Object} stream    Stream proxy
 * @param {Object} state     Presentation state
 * @param {Object} Q         Server-side Q object
 */
module.exports = async function toolCssUpdate(captures, stream, state, Q) {
    const prompt    = captures && captures.prompt;
    const elementId = captures && captures.elementId;
    if (!prompt || !elementId) return;

    const apiKey = Q.Config && Q.Config.get(['AI', 'anthropic', 'key'], null);
    if (!apiKey) return;

    // Ask the LLM to translate the CSS request into variable updates
    const system = `You translate natural language CSS requests into JSON.
The tool has these CSS custom properties: --AI-bg, --AI-accent, --AI-accent2,
--AI-text, --AI-border, --AI-sq-light, --AI-sq-dark, --AI-sq-select.
Return ONLY a JSON object mapping property names to values.
Example: {"--AI-accent":"#ef4444","--AI-accent2":"#1a1a1a"}
No explanation. No markdown. Just the JSON object.`;

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
                max_tokens: 200,
                system,
                messages: [{ role: 'user', content: prompt }]
            })
        });

        if (!resp.ok) return;
        const data = await resp.json();
        const text = data.content && data.content[0] && data.content[0].text;
        if (!text) return;

        const vars = JSON.parse(text.trim());

        // Emit Q/style as a stream EPHEMERAL — not a socket event.
        // Socket events only reach the user's own clients; the presentation
        // stream ephemeral reaches ALL screens: shared ?f=1, audience phones,
        // host pane. Each screen calls Q.handle(Q.Socket.onEvent('Q/style'), ...)
        // which re-uses the listenForStyle injection logic uniformly.
        stream.ephemeral('Q/style', {
            elementId: elementId,
            selector:  '*',
            vars
        });

        Q.log && Q.log('AI/toolCssUpdate: patched', Object.keys(vars).length, 'vars on', elementId);

    } catch (e) {
        Q.log && Q.log('AI/toolCssUpdate: error', e.message);
    }
};
