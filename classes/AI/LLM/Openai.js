'use strict';
/**
 * AI_LLM_Openai → AI.LLM.Openai
 * OpenAI Responses API adapter (GPT-4.x family).
 * Mirrors PHP AI_LLM_Openai.
 *
 * @module AI
 */
var Q      = require('Q');
var AI_LLM = require('AI/LLM');
var Http   = require('AI/Http');
var _post  = Http._post;

// Wrap _post into the old (url, headers, body, timeoutMs)→res shape
// that this adapter was written against. _post already matches — good.
function _httpPost(url, headers, body, timeoutMs) {
	return _post(url, headers, body, timeoutMs);
}

AI_LLM.Openai = function(options) {
	this.model = Q.Config.get(['AI', 'llm', 'models', 'openai'], 'gpt-4.1-mini');
};
AI_LLM.Openai.prototype = Object.create(AI_LLM.prototype);

AI_LLM.Openai.prototype.executeModel = function(instructions, inputs, options) {
	options = options || {};
	var model      = options.model      || this.model;
	var apiKey     = options.apiKey     || Q.Config.get(['AI', 'openAI', 'key'], null);
	if (!apiKey) return Promise.reject(new Error('AI.LLM.Openai: missing API key (AI/openAI/key)'));
	var maxTokens   = options.max_tokens  || 3000;
	var temperature = options.temperature != null ? options.temperature : 0.5;
	var messages    = options.messages   || [];
	var content     = [];

	if (inputs && inputs.text) {
		content.push({ type: 'input_text', text: inputs.text });
	}
	// Images as base64 data URIs
	if (inputs && Array.isArray(inputs.images)) {
		inputs.images.forEach(function(img) {
			var b64 = Buffer.isBuffer(img) ? img.toString('base64') : img;
			var mime = 'image/jpeg';
			if (b64.startsWith('iVBORw0KGgo')) mime = 'image/png';
			content.push({ type: 'input_image', image_url: 'data:' + mime + ';base64,' + b64 });
		});
	}

	var input;
	if (messages.length) {
		input = messages.map(function(m) {
			var c = typeof m.content === 'string'
				? [{ type: 'input_text', text: m.content }] : m.content;
			return { role: m.role || 'user', content: c };
		});
	} else {
		input = [{ role: 'user', content: content }];
	}

	var payload = {
		model:              model,
		input:              input,
		max_output_tokens:  maxTokens,
		temperature:        temperature
	};
	if (instructions) payload.instructions = instructions;

	var rf = options.response_format, js = options.json_schema;
	if (rf === 'json_schema' && js) {
		payload.response_format = { type: 'json_schema', json_schema: js };
	} else if (rf === 'json') {
		payload.response_format = { type: 'json_object' };
	} else if (rf) {
		payload.response_format = rf;
	}

	return _httpPost(
		'https://api.openai.com/v1/responses',
		{ 'Authorization': 'Bearer ' + apiKey, 'Content-Type': 'application/json' },
		payload,
		(options.timeout || 300) * 1000
	).then(function(res) {
		if (res.status < 200 || res.status >= 300) {
			throw new Error('AI.LLM.Openai: HTTP ' + res.status + ': ' + res.body.slice(0, 400));
		}
		var data; try { data = JSON.parse(res.body); } catch(e) { throw new Error('AI.LLM.Openai: non-JSON response'); }
		// Responses API output normalisation
		if (data.output && Array.isArray(data.output)) {
			for (var i = 0; i < data.output.length; i++) {
				var item = data.output[i];
				if (item.type !== 'message' || !Array.isArray(item.content)) continue;
				var text = '';
				item.content.forEach(function(b) {
					if (b.type === 'output_text' && typeof b.text === 'string') text += b.text + '\n';
				});
				return text.trim();
			}
		}
		return '';
	});
};

module.exports = AI_LLM.Openai;
