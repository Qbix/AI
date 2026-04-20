'use strict';
/**
 * AI/LLM — provider-agnostic LLM execution layer.
 *
 * This file holds:
 *   - AI_LLM class (base + factory)
 *   - AI_LLM.promptFromObservations() (observation → prompt+schema)
 *   - AI_LLM.prototype.process/summarize/keywords (higher-level helpers)
 *   - require hooks that load each adapter file so AI_LLM.create('openai')
 *     works without the caller needing to preload anything.
 *
 * Mirrors the PHP filesystem layout:
 *   classes/AI/LLM.php             (base + factory + helpers)
 *   classes/AI/LLM/<Adapter>.php   (one per provider)
 *
 * Core contract (same as PHP):
 *   executeModel(instructions, inputs, options) → Promise<string>
 *
 * @module AI
 */

var Q = require('Q');

// ── AI.LLM base ───────────────────────────────────────────────────────────────

function AI_LLM() {}
module.exports = AI_LLM;

/**
 * Factory — mirrors PHP AI_LLM::create().
 * @param {string|object} adapter  'openai' | 'google' | 'aws' | instance
 * @param {object} [options]
 * @returns {AI_LLM|null}
 */
AI_LLM.create = function(adapter, options) {
	if (!adapter) return null;
	if (typeof adapter === 'object') return adapter;
	var sanitized = adapter.replace(/[^a-z0-9]+/gi, ' ').trim();
	var suffix = sanitized.replace(/\s+(.)/g, function(_, c) { return c.toUpperCase(); });
	suffix = suffix.charAt(0).toUpperCase() + suffix.slice(1);
	var cls = AI_LLM[suffix];
	if (cls) return new cls(options);
	return null;
};

/**
 * Build prompt + schema from observation definitions.
 * Mirrors PHP AI_LLM::promptFromObservations().
 */
AI_LLM.promptFromObservations = function(observations) {
	var clauses = [];
	var schema  = {};
	Object.keys(observations).forEach(function(name) {
		var o = observations[name];
		if (!o.promptClause || !o.fieldNames) return;
		clauses.push('- ' + o.promptClause);
		if (!schema[name]) schema[name] = {};
		o.fieldNames.forEach(function(field) {
			schema[name][field] = (o.example && Object.prototype.hasOwnProperty.call(o.example, field))
				? o.example[field] : null;
		});
	});
	return { clauses: clauses, schema: schema };
};

/**
 * Run observation pipeline — exactly one model call.
 * Mirrors PHP AI_LLM::process().
 */
AI_LLM.prototype.process = function(inputs, observations, interpolate, options) {
	if (!observations || !Object.keys(observations).length) {
		return Promise.resolve({});
	}
	var o = AI_LLM.promptFromObservations(observations);
	if (!o.clauses.length) {
		return Promise.reject(new Error('No valid observation clauses generated'));
	}
	var prompt =
		'You are an automated semantic processor.\n\n' +
		'Rules:\n' +
		'- Output MUST be valid JSON\n' +
		'- Do not include comments or prose\n' +
		'- Do not omit fields\n' +
		'- Use null when uncertain\n' +
		'- Arrays must respect stated limits\n' +
		'- Numeric values must be within stated ranges\n' +
		'- If uncertainty is high for any field, lower the confidence score accordingly\n\n' +
		'Inputs are referenced ONLY by the names provided in the text.\n' +
		'Do not infer meaning from order, index, or file type.\n\n' +
		'OBSERVATIONS:\n' + o.clauses.join('\n') + '\n\n' +
		'Return ONLY valid JSON matching this schema exactly:\n' +
		JSON.stringify(o.schema, null, 2);

	if (interpolate && typeof interpolate === 'object') {
		prompt = prompt.replace(/\{\{(\w+)\}\}/g, function(_, k) {
			return Object.prototype.hasOwnProperty.call(interpolate, k) ? interpolate[k] : '';
		});
	}

	var opts = Object.assign({}, options || {});
	return this.executeModel(prompt, inputs, opts)
		.then(function(raw) {
			var text = typeof raw === 'string' ? raw : (raw && raw.content) || '';
			text = text.replace(/^```[a-z]*\n|\n```$/g, '').trim();
			var data = JSON.parse(text);
			if (typeof data !== 'object' || data === null) throw new Error('Model did not return valid JSON');
			return data;
		});
};

/**
 * Summarize text into {title, keywords, summary, speakers}.
 * Mirrors PHP AI_LLM::summarize().
 */
AI_LLM.prototype.summarize = function(text, options) {
	if (!text || !text.trim()) return Promise.resolve({});
	var opts = Object.assign({ temperature: 0, max_tokens: 1000 }, options || {});
	var instructions =
		'You are a language model tasked with extracting structured summaries for indexing, ' +
		'using clearly labeled XML-style tags.\n\n' +
		'Output exactly these sections:\n' +
		'<title> (under 200 characters)\n' +
		'<keywords> one line, max 400 characters\n' +
		'<summary> one paragraph, max 512 characters\n' +
		'<speakers> comma-separated names OR "no names"\n\n' +
		'Rules:\n- No extra text\n- No markdown\n- No explanations\n\n' +
		'Text to process:\n' + text;

	return this.executeModel(instructions, { text: text }, opts)
		.then(function(raw) {
			var content = typeof raw === 'string' ? raw : (raw && raw.content) || '';
			content = content.replace(/^```[a-z]*\n|\n```$/g, '').trim();
			var extract = function(tag) {
				var m = content.match(new RegExp('<' + tag + '>(.*?)<\\/' + tag + '>', 's'));
				return m ? m[1].trim() : '';
			};
			var title    = extract('title');
			var summary  = extract('summary');
			var speakers = extract('speakers');
			var kwStr    = extract('keywords');
			var keywords = kwStr ? kwStr.split(/\s*,\s*/).map(function(k) { return k.toLowerCase(); }) : [];
			if (speakers.toLowerCase() === 'no names') speakers = '';
			return { title: title, keywords: keywords, summary: summary, speakers: speakers };
		});
};

/**
 * Expand keywords for search indexing.
 * Mirrors PHP AI_LLM::keywords().
 */
AI_LLM.prototype.keywords = function(keywords, during, options) {
	if (!keywords || !keywords.length) return Promise.resolve([]);
	during   = during || 'insert';
	options  = options || {};
	var original    = keywords.join(', ');
	var temperature = during === 'query' ? 0.3 : 0.7;
	var language    = options.language || 'en';

	var prompt =
		'Expand the following canonical search keywords into useful query terms.\n\n' +
		'Input:\n' + original + '\n\n' +
		'Rules:\n' +
		'- Output lines exactly as specified below\n' +
		'- Comma-separated\n' +
		'- Max 1000 terms per line\n' +
		'- Each term must be 1 or 2 words\n' +
		'- No punctuation other than commas\n' +
		'- No duplicates\n' +
		'- No sentences\n' +
		'- Highly relevant only\n\n' +
		'Output:\nLine 1: English keywords\n';
	if (language && language.toLowerCase() !== 'en') {
		prompt += 'Line 2: ' + language + ' keywords (native language only)\n';
	}

	var opts = Object.assign({ temperature: temperature, max_tokens: 2000 }, options);

	return this.executeModel(prompt, { text: original }, opts)
		.then(function(raw) {
			var content = typeof raw === 'string' ? raw : (raw && raw.content) || '';
			content = content.replace(/^```[a-z]*\n|\n```$/g, '').trim();
			var lines   = content.split(/\r?\n/);
			var english = (lines[0] || '').split(/\s*,\s*/)
				.map(function(k) { return k.toLowerCase().trim(); })
				.filter(function(k) { return k; });
			english = Array.from(new Set(english));
			return english;
		});
};

// ── Auto-register all adapter files ───────────────────────────────────────────
require('AI/LLM/Openai');
require('AI/LLM/Google');
require('AI/LLM/Aws');
