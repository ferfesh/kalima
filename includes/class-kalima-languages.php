<?php
/**
 * Language registry.
 *
 * Every language is a plain array of display data. Nothing here touches the
 * database — the registry is the single source of truth for what the plugin
 * knows how to translate.
 *
 * @package KALIMA
 */

defined( 'ABSPATH' ) || exit;

/**
 * Static registry of supported languages.
 */
class KALIMA_Languages {

	/**
	 * The full registry.
	 *
	 * `ai` is the code handed to the translation API, `locale` is the WordPress
	 * locale (used for date/number formatting), `dir` drives `dir=` on <html>.
	 *
	 * @return array<string,array>
	 */
	public static function registry() {
		return array(
			'en' => array(
				'name'    => 'English',
				'native'  => 'English',
				'locale'  => 'en_US',
				'dir'     => 'ltr',
				'flag'    => '🇬🇧',
				'ai'      => 'en',
				'default' => true,
			),
			'ar' => array(
				'name'   => 'Arabic',
				'native' => 'العربية',
				'locale' => 'ar',
				'dir'    => 'rtl',
				'flag'   => '🇸🇦',
				'ai'     => 'ar',
			),
			'de' => array(
				'name'   => 'German',
				'native' => 'Deutsch',
				'locale' => 'de_DE',
				'dir'    => 'ltr',
				'flag'   => '🇩🇪',
				'ai'     => 'de',
			),
			'fr' => array(
				'name'   => 'French',
				'native' => 'Français',
				'locale' => 'fr_FR',
				'dir'    => 'ltr',
				'flag'   => '🇫🇷',
				'ai'     => 'fr',
			),
			'it' => array(
				'name'   => 'Italian',
				'native' => 'Italiano',
				'locale' => 'it_IT',
				'dir'    => 'ltr',
				'flag'   => '🇮🇹',
				'ai'     => 'it',
			),
			'es' => array(
				'name'   => 'Spanish',
				'native' => 'Español',
				'locale' => 'es_ES',
				'dir'    => 'ltr',
				'flag'   => '🇪🇸',
				'ai'     => 'es',
			),
			'zh' => array(
				'name'   => 'Chinese (Simplified)',
				'native' => '简体中文',
				'locale' => 'zh_CN',
				'dir'    => 'ltr',
				'flag'   => '🇨🇳',
				'ai'     => 'zh-CN',
			),
			'tr' => array(
				'name'   => 'Turkish',
				'native' => 'Türkçe',
				'locale' => 'tr_TR',
				'dir'    => 'ltr',
				'flag'   => '🇹🇷',
				'ai'     => 'tr',
			),
			'hi' => array(
				'name'   => 'Hindi',
				'native' => 'हिन्दी',
				'locale' => 'hi_IN',
				'dir'    => 'ltr',
				'flag'   => '🇮🇳',
				'ai'     => 'hi',
			),
		);
	}

	/**
	 * Languages WordPress itself ships translations for.
	 *
	 * Anything outside this list still translates, but the admin UI stays in
	 * English because there is no .mo for it.
	 *
	 * @return string[]
	 */
	public function i18n_locales() {
		return array( 'en_US', 'ar', 'de_DE', 'fr_FR', 'it_IT', 'es_ES', 'zh_CN', 'tr_TR', 'hi_IN' );
	}

	/**
	 * Drop the registry down to the languages the site actually enabled.
	 *
	 * @param string[] $codes Codes to keep.
	 * @return array<string,array>
	 */
	public function only( array $codes ) {
		$all  = self::registry();
		$kept = array();

		/*
		 * Walk the requested codes, not the registry, so the order the site
		 * configured its languages in is also the order the switcher shows
		 * them in.
		 */
		foreach ( array_map( 'strval', $codes ) as $code ) {
			if ( isset( $all[ $code ] ) ) {
				$kept[ $code ] = $all[ $code ];
			}
		}

		return $kept;
	}

	/**
	 * Full data for one language.
	 *
	 * @param string $code Language code.
	 * @return array|null
	 */
	public function get( $code ) {
		$all = self::registry();

		return isset( $all[ $code ] ) ? $all[ $code ] : null;
	}

	/**
	 * The code the translation API expects for a given site language.
	 *
	 * @param string $code Site language code.
	 * @return string
	 */
	public function ai_code( $code ) {
		$data = $this->get( $code );

		return $data ? $data['ai'] : $code;
	}

	/**
	 * Human name for a language.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function name( $code ) {
		$data = $this->get( $code );

		return $data ? $data['name'] : strtoupper( $code );
	}

	/**
	 * Native (in-language) name.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function native_name( $code ) {
		$data = $this->get( $code );

		return $data ? $data['native'] : strtoupper( $code );
	}

	/**
	 * Writing direction, `ltr` or `rtl`.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function direction( $code ) {
		$data = $this->get( $code );

		return $data ? $data['dir'] : 'ltr';
	}

	/**
	 * Is this a right-to-left language?
	 *
	 * @param string $code Language code.
	 * @return bool
	 */
	public function is_rtl( $code ) {
		return 'rtl' === $this->direction( $code );
	}

	/**
	 * Flag emoji, used by the switcher.
	 *
	 * @param string $code Language code.
	 * @return string
	 */
	public function flag( $code ) {
		$data = $this->get( $code );

		return $data ? $data['flag'] : '';
	}

	/**
	 * Register the language as a query var so `?lang=xx` works out of the box.
	 *
	 * @param array $vars Existing query vars.
	 * @return array
	 */
	public function query_vars( $vars ) {
		$vars[] = 'kalima_lang';

		return $vars;
	}

	/**
	 * Country → language table.
	 *
	 * ISO 3166-1 alpha-2 codes mapped to the language this site would open in
	 * for a visitor arriving from that country. Kept here beside the language
	 * registry so the whole language model lives in one file: nothing is
	 * fetched, installed or looked up elsewhere.
	 *
	 * Countries whose official language is not in this plugin are deliberately
	 * left out — an unmapped country falls back to the configured fallback
	 * rather than guessing a language we cannot actually serve.
	 *
	 * @return array<string,string>
	 */
	public static function country_map() {
		static $map = null;

		if ( null !== $map ) {
			return $map;
		}

		$map = array(
			/* Arabic */
			'AE' => 'ar', 'BH' => 'ar', 'DZ' => 'ar', 'EG' => 'ar', 'EH' => 'ar',
			'IQ' => 'ar', 'JO' => 'ar', 'KW' => 'ar', 'LB' => 'ar', 'LY' => 'ar',
			'MA' => 'ar', 'OM' => 'ar', 'PS' => 'ar', 'QA' => 'ar', 'SA' => 'ar',
			'SD' => 'ar', 'SY' => 'ar', 'TN' => 'ar', 'YE' => 'ar', 'SO' => 'ar',
			'DJ' => 'ar', 'KM' => 'ar', 'TD' => 'ar', 'MR' => 'ar',

			/* German */
			'AT' => 'de', 'CH' => 'de', 'DE' => 'de', 'LI' => 'de', 'LU' => 'de',

			/* French — Belgium and Switzerland have no Dutch/German entry above
			 * their French-speaking share, so French is the better fit there. */
			'BE' => 'fr', 'BF' => 'fr', 'BJ' => 'fr', 'BI' => 'fr', 'BL' => 'fr',
			'CD' => 'fr', 'CG' => 'fr', 'CI' => 'fr', 'CM' => 'fr', 'FR' => 'fr',
			'GA' => 'fr', 'GN' => 'fr', 'GW' => 'fr', 'HT' => 'fr', 'MC' => 'fr',
			'MG' => 'fr', 'ML' => 'fr', 'MU' => 'fr', 'NE' => 'fr', 'NC' => 'fr',
			'PF' => 'fr', 'PM' => 'fr', 'RW' => 'fr', 'SC' => 'fr', 'SN' => 'fr',
			'TD' => 'ar', 'TG' => 'fr', 'YT' => 'fr', 'VU' => 'fr',

			/* Italian */
			'IT' => 'it', 'SM' => 'it', 'VA' => 'it',

			/* Spanish */
			'AR' => 'es', 'BO' => 'es', 'CL' => 'es', 'CO' => 'es', 'CR' => 'es',
			'CU' => 'es', 'DO' => 'es', 'EC' => 'es', 'ES' => 'es', 'GQ' => 'es',
			'GT' => 'es', 'HN' => 'es', 'MX' => 'es', 'NI' => 'es', 'PA' => 'es',
			'PE' => 'es', 'PR' => 'es', 'PY' => 'es', 'SV' => 'es', 'UY' => 'es',
			'VE' => 'es',

			/* Chinese */
			'CN' => 'zh', 'HK' => 'zh', 'MO' => 'zh', 'TW' => 'zh',

			/* Turkish */
			'TR' => 'tr',

			/* Hindi */
			'IN' => 'hi',

			/* English */
			'AU' => 'en', 'BW' => 'en', 'CA' => 'en', 'GB' => 'en', 'GH' => 'en',
			'GY' => 'en', 'IE' => 'en', 'JM' => 'en', 'KE' => 'en', 'LS' => 'en',
			'LR' => 'en', 'MW' => 'en', 'MT' => 'en', 'MU' => 'en', 'MZ' => 'en',
			'NA' => 'en', 'NG' => 'en', 'NZ' => 'en', 'PG' => 'en', 'PH' => 'en',
			'SB' => 'en', 'SG' => 'en', 'SL' => 'en', 'SZ' => 'en', 'TZ' => 'en',
			'UG' => 'en', 'US' => 'en', 'ZM' => 'en', 'ZW' => 'en', 'ZA' => 'en',
		);

		return $map;
	}

	/**
	 * Language a visitor from this country should be served in.
	 *
	 * @param string $country ISO 3166-1 alpha-2 country code.
	 * @return string Language code, or an empty string when unmapped.
	 */
	public function language_for_country( $country ) {
		$country = strtoupper( trim( (string) $country ) );

		if ( 2 !== strlen( $country ) ) {
			return '';
		}

		$map = self::country_map();

		return isset( $map[ $country ] ) ? $map[ $country ] : '';
	}

	/**
	 * Everything the switcher needs to render a language.
	 *
	 * @param string[] $codes Codes to describe.
	 * @return array<string,array>
	 */
	public function describe( array $codes ) {
		$out = array();

		foreach ( $codes as $code ) {
			$data = $this->get( $code );
			if ( ! $data ) {
				continue;
			}

			$out[ $code ] = array(
				'code'   => $code,
				'name'   => $data['name'],
				'native' => $data['native'],
				'dir'    => $data['dir'],
				'flag'   => $data['flag'],
			);
		}

		return $out;
	}
}
