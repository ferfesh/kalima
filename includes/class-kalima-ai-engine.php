<?php
/**
 * Translation engine.
 *
 * All providers share one contract: hand me an ordered list of plain strings,
 * give me back an ordered list of translated strings. Anything that fails
 * validation degrades to "leave it alone" rather than corrupting content.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Calls the configured AI provider and validates the response.
 */
class KALIMA_AI_Engine {

	/** @var KALIMA */
	private $kalima;

	/** @var array Running counters for the current run. */
	private $stats = array(
		'calls' => 0,
		'units' => 0,
		'chars' => 0,
	);

	/** @var string|null Last transport error. */
	private $last_error = null;

	/** @var array Per-request setting overrides (never persisted). */
	private $overrides = array();

	/**
	 * @param KALIMA $kalima Plugin instance.
	 */
	public function __construct( KALIMA $kalima ) {
		$this->kalima = $kalima;
	}

	/**
	 * Nothing to hook yet — the engine is called on demand.
	 */
	public function init() {
	}

	/**
	 * Reset the run counters.
	 */
	public function reset_stats() {
		$this->stats      = array( 'calls' => 0, 'units' => 0, 'chars' => 0 );
		$this->last_error = null;
	}

	/**
	 * Override settings for a single request without writing them to the DB.
	 *
	 * Used by the "Test connection" probe, where the typed key is not the saved
	 * one and the user has not pressed Save yet.
	 *
	 * @param array $overrides Key => value pairs.
	 */
	public function override( array $overrides ) {
		$this->overrides = array_merge( (array) $this->overrides, $overrides );
	}

	/**
	 * Read a setting, honouring any per-request overrides.
	 *
	 * @param string $key     Setting key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	private function setting( $key, $default = null ) {
		if ( array_key_exists( $key, $this->overrides ) ) {
			return $this->overrides[ $key ];
		}

		// Fall back to the plugin's saved settings. This must call KALIMA::get();
		// calling setting() again here would recurse forever.
		return $this->kalima->get( $key, $default );
	}

	/**
	 * Counters for the current run.
	 *
	 * @return array
	 */
	public function stats() {
		return $this->stats;
	}

	/**
	 * Most recent transport error.
	 *
	 * @return string|null
	 */
	public function last_error() {
		return $this->last_error;
	}

	/**
	 * Translate a list of plain strings, preserving order and length.
	 *
	 * @param array  $strings Strings to translate.
	 * @param string $from    Source language code (site code).
	 * @param string $to      Target language code (site code).
	 * @return array|string|WP_Error
	 */
	public function translate_batch( array $strings, $from, $to ) {
		$strings = array_values( array_filter( array_map( 'strval', $strings ), array( $this, 'filter_nonempty' ) ) );

		if ( empty( $strings ) ) {
			return array();
		}

		$from_ai = $this->kalima->languages->ai_code( $from );
		$to_ai   = $this->kalima->languages->ai_code( $to );

		if ( $from_ai === $to_ai ) {
			return $strings;
		}

		$per_call = max( 1, (int) $this->setting( 'units_per_call', 40 ) );
		$chunks   = array_chunk( $strings, $per_call );

		$out = array();

		foreach ( $chunks as $i => $chunk ) {
			if ( $i > 0 ) {
				$delay = max( 0, (int) $this->setting( 'delay_ms', 350 ) );
				if ( $delay > 0 ) {
					usleep( $delay * 1000 );
				}
			}

			$result = $this->call_with_retry( $chunk, $from_ai, $to_ai );

			if ( is_wp_error( $result ) ) {
				$this->last_error = $result->get_error_message();

				// Keep the rest of the batch usable: fall back for this chunk.
				$result = $chunk;
			}

			$result = array_map( 'strval', array_values( $result ) );

			// Length guard — never return a mismatched array to the caller.
			if ( count( $result ) !== count( $chunk ) ) {
				$this->last_error = sprintf(
					'Provider returned %d items for %d inputs.',
					count( $result ),
					count( $chunk )
				);
				$result = $chunk;
			}

			$out = array_merge( $out, $result );

			$this->stats['calls']++;
			$this->stats['units'] += count( $chunk );
			$this->stats['chars'] += array_sum( array_map( 'strlen', $chunk ) );
		}

		return array_slice( $out, 0, count( $strings ) );
	}

	/**
	 * Translate one string.
	 *
	 * @param string $text Text.
	 * @param string $from Source code.
	 * @param string $to   Target code.
	 * @return string|WP_Error
	 */
	public function translate_one( $text, $from, $to ) {
		$result = $this->translate_batch( array( $text ), $from, $to );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return isset( $result[0] ) ? $result[0] : $text;
	}

	/**
	 * Tokenize markup, translate the units, rebuild the markup.
	 *
	 * @param string $html Markup or plain text.
	 * @param string $from Source code.
	 * @param string $to   Target code.
	 * @return string|WP_Error
	 */
	public function translate_content( $html, $from, $to ) {
		$html = (string) $html;

		if ( '' === trim( $html ) ) {
			return $html;
		}

		$tokenized = KALIMA_Tokenizer::tokenize( $html );

		if ( empty( $tokenized['units'] ) ) {
			return $html;
		}

		$texts = wp_list_pluck( $tokenized['units'], 'text' );

		$translated = $this->translate_batch( $texts, $from, $to );

		if ( is_wp_error( $translated ) ) {
			return $translated;
		}

		return KALIMA_Tokenizer::restore( $tokenized['template'], $tokenized['units'], $translated );
	}

	/**
	 * Cheap round-trip to validate the key and model before a bulk run.
	 *
	 * @return true|WP_Error
	 */
	public function test_connection() {
		$provider = (string) $this->setting( 'ai_provider', 'gemini' );
		$key      = trim( (string) $this->setting( 'ai_key', '' ) );

		// The mock provider is keyless by design: it exists so the whole
		// pipeline can be proven before an API key is spent on it.
		if ( 'mock' !== $provider && '' === $key ) {
			return new WP_Error( 'kalima_no_key', __( 'No API key saved yet.', 'kalima' ) );
		}

		$result = $this->call_with_retry( array( 'Hello' ), 'en', $this->default_test_target() );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return true;
	}

	/**
	 * A language to test against that is not English.
	 *
	 * @return string
	 */
	private function default_test_target() {
		$enabled = (array) $this->setting( 'enabled_langs', array() );

		foreach ( $enabled as $code ) {
			if ( 'en' !== $code ) {
				return $code;
			}
		}

		return 'fr';
	}

	/**
	 * Keep only strings that carry at least one letter.
	 *
	 * @param string $value Candidate.
	 * @return bool
	 */
	public function filter_nonempty( $value ) {
		return '' !== trim( (string) $value );
	}

	/* ---------------------------------------------------------------------
	 * Provider dispatch
	 * ------------------------------------------------------------------ */

	/**
	 * Provider id => label, so the settings screen can build a dropdown.
	 *
	 * @return array<string,string>
	 */
	public static function providers() {
		return array(
			'gemini'   => 'Google Gemini',
			'openai'   => 'OpenAI (GPT)',
			'deepseek' => 'DeepSeek',
			'claude'   => 'Anthropic (Claude)',
			'deepl'    => 'DeepL',
			'mock'     => 'Mock (no key — pipeline test only)',
		);
	}

	/**
	 * Default model per provider.
	 *
	 * @param string $provider Provider id.
	 * @return string
	 */
	public static function default_model( $provider ) {
		$models = array(
			'gemini'   => 'gemini-3.5-flash',
			'openai'   => 'gpt-4o-mini',
			'deepseek' => 'deepseek-chat',
			'claude'   => 'claude-sonnet-4-5',
			'deepl'    => 'deepl',
			'mock'     => 'mock',
		);

		return isset( $models[ $provider ] ) ? $models[ $provider ] : '';
	}

	/**
	 * The API key, or an error when none is configured.
	 *
	 * Providers must fail here rather than sending an unauthenticated request
	 * over the network, which comes back as an opaque 403.
	 *
	 * @return string|WP_Error
	 */
	private function require_key() {
		$key = trim( (string) $this->setting( 'ai_key', '' ) );

		if ( '' === $key ) {
			return new WP_Error( 'kalima_no_key', __( 'No API key saved yet.', 'kalima' ) );
		}

		return $key;
	}

	/**
	 * Whether a failure is worth retrying.
	 *
	 * 429 and 5xx are the provider being busy. A 400/403/404 is our own fault
	 * (bad key, retired model) and retrying it would only waste the budget.
	 *
	 * @param \WP_Error $error Failure.
	 * @return bool
	 */
	private function is_transient( \WP_Error $error ) {
		return (bool) preg_match( '/kalima_http_(429|5\d\d)$/', (string) $error->get_error_code() );
	}

	/**
	 * Call the provider, retrying transient failures with exponential backoff.
	 *
	 * A bulk run across hundreds of strings will meet the occasional 429 or
	 * 503 no matter which provider is used. Falling straight back to the
	 * untranslated text there would quietly leave parts of the site in English,
	 * so it is worth a few patient retries first.
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @return array|WP_Error
	 */
	private function call_with_retry( array $chunk, $from, $to ) {
		$attempts = max( 1, (int) $this->setting( 'retry_attempts', 4 ) );
		$backoff  = max( 500, (int) $this->setting( 'retry_backoff_ms', 1500 ) );

		$last = null;

		for ( $i = 0; $i < $attempts; $i++ ) {
			if ( $i > 0 ) {
				usleep( (int) ( $backoff * pow( 2, $i - 1 ) * 1000 ) );
			}

			$result = $this->call_provider( $chunk, $from, $to );

			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last = $result;

			if ( ! $this->is_transient( $result ) ) {
				return $result;
			}
		}

		return $last;
	}

	/**
	 * Send one chunk to the configured provider.
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @return array|WP_Error
	 */
	private function call_provider( array $chunk, $from, $to ) {
		$provider = (string) $this->setting( 'ai_provider', 'gemini' );

		switch ( $provider ) {
			case 'gemini':
				return $this->call_gemini( $chunk, $from, $to );
			case 'openai':
				return $this->call_openai( $chunk, $from, $to, 'https://api.openai.com/v1/chat/completions' );
			case 'deepseek':
				return $this->call_openai( $chunk, $from, $to, 'https://api.deepseek.com/chat/completions' );
			case 'claude':
				return $this->call_claude( $chunk, $from, $to );
			case 'deepl':
				return $this->call_deepl( $chunk, $from, $to );
			case 'mock':
				return $this->call_mock( $chunk, $from, $to );
		}

		return new WP_Error( 'kalima_bad_provider', sprintf( __( 'Unknown provider: %s', 'kalima' ), $provider ) );
	}

	/**
	 * Shared instruction block. Same text for every provider.
	 *
	 * @param string $from Source AI code.
	 * @param string $to   Target AI code.
	 * @return string
	 */
	private function system_prompt( $from, $to ) {
		$glossary = trim( (string) $this->setting( 'preserve_terms', '' ) );

		$keep = '';
		if ( '' !== $glossary ) {
			$keep = "\n- Never translate these exact terms, keep them in Latin script: " . $glossary;
		}

		return "You are a professional localisation engine for an e-commerce website. "
			. "You will receive a JSON array of strings in {$from}. Return ONLY a JSON array of the same length, "
			. "in the same order, with each string translated into {$to}.\n\n"
			. "Rules:\n"
			. "- Output must be valid JSON: an array of strings. No commentary, no markdown fences.\n"
			. "- Preserve every placeholder exactly: %s, %d, {0}, {{var}}, [shortcode], @@KALIMA_n@@, &amp;, &nbsp;\n"
			. "- Keep HTML entities untouched.\n"
			. "- Keep product model numbers, SKUs, sizes, voltages and currency symbols as-is.\n"
			. "- Keep brand and company names in Latin script.\n"
			. "- Match the register of a retail store: natural, concise, not literal.\n"
			. "- If a string is a single word that is also a common noun, translate it; if it looks like a proper noun, keep it."
			. $keep;
	}

	/**
	 * Pull a JSON array out of a model reply, tolerating fences and chatter.
	 *
	 * @param string $raw Raw reply text.
	 * @return array|WP_Error
	 */
	private function parse_json_array( $raw ) {
		$raw = trim( (string) $raw );

		// Strip ```json ... ``` fences if the model added them anyway.
		$raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
		$raw = preg_replace( '/\s*```$/', '', $raw );

		$decoded = json_decode( $raw, true );

		if ( is_array( $decoded ) ) {
			return $decoded;
		}

		// Last resort: grab the outermost bracketed region.
		$start = strpos( $raw, '[' );
		$end   = strrpos( $raw, ']' );

		if ( false !== $start && false !== $end && $end > $start ) {
			$decoded = json_decode( substr( $raw, $start, $end - $start + 1 ), true );
			if ( is_array( $decoded ) ) {
				return $decoded;
			}
		}

		return new WP_Error( 'kalima_bad_json', __( 'The provider did not return a JSON array.', 'kalima' ) );
	}

	/**
	 * Google Gemini.
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @return array|WP_Error
	 */
	private function call_gemini( array $chunk, $from, $to ) {
		$key = $this->require_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = trim( (string) $this->setting( 'ai_model', '' ) );

		if ( '' === $model ) {
			$model = self::default_model( 'gemini' );
		}

		$body = array(
			'systemInstruction' => array(
				'parts' => array( array( 'text' => $this->system_prompt( $from, $to ) ) ),
			),
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => wp_json_encode( $chunk, JSON_UNESCAPED_UNICODE ) ) ),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
				'temperature'      => 0.2,
			),
		);

		$url = sprintf(
			'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
			rawurlencode( $model ),
			rawurlencode( $key )
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : wp_remote_retrieve_body( $response );

			return new WP_Error( 'kalima_http_' . $code, sprintf( 'Gemini %d: %s', $code, $message ) );
		}

		$text = isset( $json['candidates'][0]['content']['parts'][0]['text'] )
			? $json['candidates'][0]['content']['parts'][0]['text']
			: '';

		if ( '' === $text ) {
			return new WP_Error( 'kalima_empty', 'Gemini returned an empty response.' );
		}

		return $this->parse_json_array( $text );
	}

	/**
	 * OpenAI-compatible chat endpoint (also used by DeepSeek).
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @param string $url   Endpoint.
	 * @return array|WP_Error
	 */
	private function call_openai( array $chunk, $from, $to, $url ) {
		$key = $this->require_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = trim( (string) $this->setting( 'ai_model', '' ) );

		if ( '' === $model ) {
			$provider = (string) $this->setting( 'ai_provider', 'openai' );
			$model    = self::default_model( $provider );
		}

		$body = array(
			'model'       => $model,
			'temperature' => 0.2,
			'messages'    => array(
				array(
					'role'    => 'system',
					'content' => $this->system_prompt( $from, $to ),
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $chunk, JSON_UNESCAPED_UNICODE ),
				),
			),
		);

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $key,
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : wp_remote_retrieve_body( $response );

			return new WP_Error( 'kalima_http_' . $code, sprintf( '%d: %s', $code, $message ) );
		}

		$text = isset( $json['choices'][0]['message']['content'] ) ? $json['choices'][0]['message']['content'] : '';

		if ( '' === $text ) {
			return new WP_Error( 'kalima_empty', 'The provider returned an empty response.' );
		}

		return $this->parse_json_array( $text );
	}

	/**
	 * Anthropic Messages API.
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @return array|WP_Error
	 */
	private function call_claude( array $chunk, $from, $to ) {
		$key = $this->require_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$model = trim( (string) $this->setting( 'ai_model', '' ) );

		if ( '' === $model ) {
			$model = self::default_model( 'claude' );
		}

		$body = array(
			'model'             => $model,
			'max_tokens'        => 8000,
			'system'            => $this->system_prompt( $from, $to ),
			'messages'          => array(
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $chunk, JSON_UNESCAPED_UNICODE ),
				),
			),
		);

		$response = wp_remote_post(
			'https://api.anthropic.com/v1/messages',
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'      => 'application/json',
					'x-api-key'         => $key,
					'anthropic-version' => '2023-06-01',
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $json['error']['message'] ) ? $json['error']['message'] : wp_remote_retrieve_body( $response );

			return new WP_Error( 'kalima_http_' . $code, sprintf( 'Claude %d: %s', $code, $message ) );
		}

		$text = isset( $json['content'][0]['text'] ) ? $json['content'][0]['text'] : '';

		if ( '' === $text ) {
			return new WP_Error( 'kalima_empty', 'Claude returned an empty response.' );
		}

		return $this->parse_json_array( $text );
	}

	/**
	 * DeepL — a translation service rather than a chat model.
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @return array|WP_Error
	 */
	private function call_deepl( array $chunk, $from, $to ) {
		$key = $this->require_key();

		if ( is_wp_error( $key ) ) {
			return $key;
		}

		$host = ( false !== stripos( $key, ':' ) || 0 === strpos( $key, 'a-' ) )
			? 'https://api.deepl.com/v2/translate'
			: 'https://api-free.deepl.com/v2/translate';

		$body = array(
			'text'        => $chunk,
			'target_lang' => strtoupper( str_replace( '-', '_', $to ) ),
			'source_lang' => strtoupper( str_replace( '-', '_', $from ) ),
		);

		$response = wp_remote_post(
			$host,
			array(
				'timeout' => 90,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'DeepL-Auth-Key ' . $key,
				),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $code ) {
			$message = isset( $json['message'] ) ? $json['message'] : wp_remote_retrieve_body( $response );

			return new WP_Error( 'kalima_http_' . $code, sprintf( 'DeepL %d: %s', $code, $message ) );
		}

		$out = array();

		if ( isset( $json['translations'] ) && is_array( $json['translations'] ) ) {
			foreach ( $json['translations'] as $row ) {
				$out[] = isset( $row['text'] ) ? $row['text'] : '';
			}
		}

		return $out;
	}

	/**
	 * Mock provider — exercises the whole pipeline with no network and no cost.
	 *
	 * Every unit is wrapped in a visible marker so mock output can never be
	 * mistaken for a real translation. Use it to confirm that batching,
	 * reassembly, post duplication and term creation all behave, then switch
	 * to a real provider.
	 *
	 * @param array  $chunk Strings.
	 * @param string $from  Source AI code.
	 * @param string $to    Target AI code.
	 * @return array
	 */
	private function call_mock( array $chunk, $from, $to ) {
		$out = array();

		foreach ( $chunk as $text ) {
			// Entities and shortcodes must still survive: the tokenizer already
			// removed them, so anything left is plain prose.
			$out[] = '[' . strtoupper( (string) $to ) . '] ' . $text;
		}

		$this->stats['calls']++;

		return $out;
	}
}
