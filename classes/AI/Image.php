<?php

/**
 * Interface for AI Image generation and processing
 * @interface
 */
interface AI_Image_Interface
{
	/**
	 * Generates an image from a prompt using an AI model
	 * @method generate
	 * @param {string} $prompt The prompt to generate an image from
	 * @param {array} $options Optional settings:
	 * @param {string} [$options.model="dall-e-3"] The model to use, e.g. "dall-e-3" or "dall-e-2"
	 * @param {string} [$options.response_format="url"] Either "url" or "b64_json" for base64 response
	 * @param {string} [$options.size="1024x1024"] The desired image size, e.g. "1024x1024"
	 * @param {string} [$options.quality="standard"] The quality level, e.g. "standard" or "hd"
	 * @return {array} Either ['url' => ...] or ['b64_json' => ...] or ['error' => ...]
	 */
	public function generate($prompt, $options = array());

	/**
	 * Removes the background from a base64-encoded image
	 * @method removeBackground
	 * @param {string} $base64Image The base64-encoded PNG or JPEG image, without the "data:image/...;base64," prefix
	 * @param {array} $options Optional settings
	 * @return {array} Either ['b64_png' => ...] or ['url' => ...] or ['error' => ...]
	 */
	public function removeBackground($base64Image, $options = array());
}

/**
 * Base implementation for AI_Image_Interface
 * @class
 * @implements AI_Image_Interface
 */
class AI_Image implements AI_Image_Interface
{
	/**
	 * Default implementation of generate (does nothing)
	 * @method generate
	 */
	public function generate($prompt, $options = array())
	{
		throw new Q_Exception_NotImplemented(array(
			'functionality' => 'AI_Image::generate'
		));
	}

	/**
	 * Default implementation of removeBackground (does nothing)
	 * @method removeBackground
	 */
	public function removeBackground($base64Image, $options = array())
	{
		throw new Q_Exception_NotImplemented(array(
			'functionality' => 'AI_Image::removeBackground'
		));
	}
}
