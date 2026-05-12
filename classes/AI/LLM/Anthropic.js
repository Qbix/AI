"use strict";

/**
 * AI.LLM.Anthropic — direct api.anthropic.com adapter (JS).
 *
 * Parallel implementation to AI_LLM_Anthropic.php for use from Safebox
 * capabilities and Node-side tools. Same routing config; same
 * interface contract (executeModel + Advanced cache methods).
 *
 * Imported by AI.LLM.js router. Usage from a capability:
 *   var llm = AI.LLM.route('smart');  // returns instance
 *   var result = await llm.executeModel(instructions, inputs, options);
 *
 * Or directly:
 *   var llm = new AI.LLM.Anthropic({ apiKey: '...' });
 *   await llm.executeWithCachedPrefix('cache-key-1', prefix, inputs, options);
 *
 * Config (read from Q.Config when not overridden in constructor):
 *   AI/anthropic/apiKey
 *   AI/anthropic/baseUrl       (default https://api.anthropic.com)
 *   AI/anthropic/version       (default 2023-06-01)
 *   AI/llm/models/anthropic    (default claude-sonnet-4-6)
 *   AI/llm/maxTokens           (default 4096)
 */
var Q = require('Q');
var https = require('https');

function Anthropic(options) {
	options = options || {};
	this.apiKey = options.apiKey
		|| Q.Config.get(['AI', 'anthropic', 'apiKey'], null);
	if (!this.apiKey) {
		throw new Error('AI.LLM.Anthropic: apiKey required (AI/anthropic/apiKey)');
	}
	this.baseUrl = (options.baseUrl
		|| Q.Config.get(['AI', 'anthropic', 'baseUrl'], 'https://api.anthropic.com'))
		.replace(/\/$/, '');
	this.apiVersion = options.version
		|| Q.Config.get(['AI', 'anthropic', 'version'], '2023-06-01');
	this.defaultModel = options.model
		|| Q.Config.get(['AI', 'llm', 'models', 'anthropic'], 'claude-sonnet-4-6');
}

Anthropic.prototype.supportsPrefixCache = function () { return true; };

Anthropic.prototype.executeModel = function (instructions, inputs, options) {
	return this._execute(instructions, inputs || {}, options || {}, null);
};

Anthropic.prototype.executeWithCachedPrefix = function (cacheKey, systemPrefix, inputs, options) {
	options = options || {};
	options.__cachePrefix    = systemPrefix;
	options.__cachePrefixKey = cacheKey;
	var additional = options.additionalInstructions || '';
	return this._execute(additional, inputs || {}, options, null);
};

Anthropic.prototype.prewarmPrefix = function () {
	return Promise.reject(_notSupported(
		'prewarmPrefix not supported by Anthropic API. First call with cacheKey establishes cache.'));
};

Anthropic.prototype.listCachedPrefixes = function () {
	return Promise.reject(_notSupported(
		'listCachedPrefixes not supported (Anthropic caches are opaque).'));
};

Anthropic.prototype.dropCachedPrefix = function () {
	return Promise.reject(_notSupported(
		'dropCachedPrefix not supported. Anthropic caches expire automatically.'));
};

Anthropic.prototype._execute = function (instructions, inputs, options) {
	var self = this;
	var model = options.model || this.defaultModel;
	var maxTokens   = options.max_tokens   || options.maxTokens
		|| Q.Config.get(['AI', 'llm', 'maxTokens'], 4096);
	var temperature = options.temperature != null ? options.temperature : 0.5;
	var timeout = (options.timeout || 120) * 1000;

	var responseFormat = options.response_format || null;
	var schema = options.json_schema || null;

	var systemBlocks = [];
	var cachePrefix = options.__cachePrefix || null;
	if (cachePrefix !== null && cachePrefix !== undefined) {
		systemBlocks.push({
			type: 'text',
			text: cachePrefix,
			cache_control: { type: 'ephemeral' }
		});
		if (instructions) {
			systemBlocks.push({ type: 'text', text: instructions });
		}
	} else if (instructions) {
		systemBlocks.push({ type: 'text', text: instructions });
	}

	if (responseFormat === 'json_schema' && schema) {
		systemBlocks.push({
			type: 'text',
			text: 'Respond ONLY with valid JSON conforming to this schema:\n'
				+ JSON.stringify(schema, null, 2)
				+ '\nNo prose, no markdown fences.'
		});
	} else if (responseFormat === 'json') {
		systemBlocks.push({
			type: 'text',
			text: 'Respond ONLY with valid JSON. No prose, no markdown fences.'
		});
	}

	var messages = this._buildMessages(options, inputs);

	var body = {
		model:       model,
		max_tokens:  maxTokens,
		temperature: temperature,
		messages:    messages
	};
	if (systemBlocks.length) body.system = systemBlocks;
	if (options.tools) body.tools = options.tools;
	if (options.tool_choice) body.tool_choice = options.tool_choice;

	return new Promise(function (resolve, reject) {
		var data = JSON.stringify(body);
		var u = new URL(self.baseUrl + '/v1/messages');
		var req = https.request({
			method: 'POST',
			hostname: u.hostname,
			port:     u.port || 443,
			path:     u.pathname + (u.search || ''),
			headers: {
				'Content-Type':      'application/json',
				'Content-Length':    Buffer.byteLength(data),
				'x-api-key':         self.apiKey,
				'anthropic-version': self.apiVersion
			},
			timeout: timeout
		}, function (res) {
			var chunks = [];
			res.on('data', function (c) { chunks.push(c); });
			res.on('end', function () {
				var raw = Buffer.concat(chunks).toString('utf8');
				var parsed;
				try { parsed = JSON.parse(raw); }
				catch (e) {
					return reject(new Error('AI.LLM.Anthropic: non-JSON response (status '
						+ res.statusCode + '): ' + raw.slice(0, 500)));
				}
				if (parsed.error) {
					var msg = (typeof parsed.error === 'object' && parsed.error.message)
						? parsed.error.message
						: JSON.stringify(parsed.error);
					return reject(new Error('AI.LLM.Anthropic error: ' + msg));
				}
				resolve({
					text:  self._extractText(parsed),
					raw:   parsed,
					usage: parsed.usage || null,
					model: parsed.model || model
				});
			});
		});
		req.on('error', reject);
		req.on('timeout', function () {
			req.destroy();
			reject(new Error('AI.LLM.Anthropic: request timeout after ' + timeout + 'ms'));
		});
		req.write(data);
		req.end();
	});
};

Anthropic.prototype._buildMessages = function (options, inputs) {
	if (options.messages && Array.isArray(options.messages)) {
		var out = [];
		for (var i = 0; i < options.messages.length; i++) {
			var m = options.messages[i];
			var role = m.role || 'user';
			if (role === 'system') continue;
			if (role === 'tool') {
				out.push({
					role: 'user',
					content: [{
						type: 'tool_result',
						tool_use_id: m.tool_use_id || '',
						content: m.content || ''
					}]
				});
				continue;
			}
			out.push(typeof m.content === 'string'
				? { role: role, content: m.content }
				: m);
		}
		if (!out.length) out.push({ role: 'user', content: '' });
		return out;
	}

	// Legacy: assemble from options.user + inputs.
	var content = [];
	if (options.user) {
		content.push({ type: 'text', text: String(options.user) });
	}
	if (inputs.images && Array.isArray(inputs.images)) {
		for (var j = 0; j < inputs.images.length; j++) {
			var img = inputs.images[j];
			content.push({
				type: 'image',
				source: {
					type: 'base64',
					media_type: 'image/png',
					data: Buffer.isBuffer(img) ? img.toString('base64')
						: typeof img === 'string' ? Buffer.from(img).toString('base64')
						: ''
				}
			});
		}
	}
	if (inputs.pdfs && Array.isArray(inputs.pdfs)) {
		for (var k = 0; k < inputs.pdfs.length; k++) {
			var pdf = inputs.pdfs[k];
			content.push({
				type: 'document',
				source: {
					type: 'base64',
					media_type: 'application/pdf',
					data: Buffer.isBuffer(pdf) ? pdf.toString('base64')
						: typeof pdf === 'string' ? Buffer.from(pdf).toString('base64')
						: ''
				}
			});
		}
	}
	if (!content.length) content.push({ type: 'text', text: '' });
	return [{ role: 'user', content: content }];
};

Anthropic.prototype._extractText = function (response) {
	if (!response || !response.content) return '';
	var out = '';
	for (var i = 0; i < response.content.length; i++) {
		var block = response.content[i];
		if (block && block.type === 'text' && block.text) out += block.text;
	}
	return out;
};

function _notSupported(msg) {
	var err = new Error('AI.LLM.Anthropic: ' + msg);
	err.code = 'NOT_SUPPORTED';
	return err;
}

module.exports = Anthropic;
