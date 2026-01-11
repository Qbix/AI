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
	 * @param {string} [$options.model] The model to use, e.g. "dall-e-3" or "dall-e-2"
	 * @param {string} [$options.response_format] Either "url" or "b64_json" for base64 response
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

	/**
     * Create an Image adapter instance from a string or return an existing instance.
     *
     * @method create
     * @static
     * @param {String|Object} adapter Adapter name, FQCN, or instance
     * @param {Object} [options] Optional constructor/options to pass to adapter
     * @return {Object|null} Instance of adapter or null if not found
     */
    public static function create($adapter, $options = array())
    {
        if (empty($adapter)) {
            return null;
        }

        // If already an instance, return it
        if (is_object($adapter)) {
            return $adapter;
        }

        // If full class name provided and exists, instantiate
        if (is_string($adapter) && class_exists($adapter)) {
            return new $adapter($options);
        }

        // Normalize adapter string to a class suffix:
        // e.g. "openai" => "Openai" => "AI_Image_Openai" ; "open-ai" or "open_ai" => "OpenAi"
        $sanitized = preg_replace('/[^a-z0-9]+/i', ' ', (string)$adapter);
        $suffix = str_replace(' ', '', ucfirst($sanitized));

        // Common naming convention: AI_Image_<Adapter>
        $className = "AI_Image_{$suffix}";

        if (class_exists($className)) {
            return new $className($options);
        }

        // Try alternative: prefix without underscore (legacy variations)
        $altClass = "AI_Image_" . $suffix;
        if (class_exists($altClass)) {
            return new $altClass($options);
        }

        // Not found — rely on autoloader to load file by convention if needed,
        // otherwise return null so caller can handle.
        return null;
    }

}
