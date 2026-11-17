<?php

// ═══════════════════════════════════════════════════════════════════
// AI plugin — LLM access
// File: plugins/AI/handlers/Q/SafeScript/scope.php
// ═══════════════════════════════════════════════════════════════════

function AI_before_Q_SafeScript_scope($params)
{
	$scope = &$params['scope'];

	// Run an LLM completion
	$scope['AI_LLM_create'] = function ($adapter, $options = array()) {
		$llm = AI_LLM::create($adapter, $options);
		// Return a callable handle, not the object itself
		return array(
			'adapter' => $adapter,
			'id' => spl_object_id($llm)
		);
	};

	// Use a configured route
	$scope['AI_LLM_route'] = function ($routeName, $options = array()) {
		$llm = AI_LLM::route($routeName, $options);
		$result = $llm->generate();
		return array(
			'content' => $result->content ?? null,
			'usage' => $result->usage ?? null
		);
	};

	// List available routes
	$scope['AI_LLM_listRoutes'] = function () {
		return AI_LLM::listRoutes();
	};
}