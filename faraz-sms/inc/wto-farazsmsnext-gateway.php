<?php
namespace PW\PWSMS\Gateways;

/*
 * Farazsms.com(Next) / IranPayamak gateways for Persian WooCommerce SMS (7.1 + 7.2).
 */
if ( ! defined( 'ABSPATH' ) ) {
	return;
}

require_once dirname( __FILE__ ) . '/wto-farazsmsnext-gateway-logic.php';

// PWSMS 7.2+ uses an abstract base gateway class. Prefer it whenever available.
// Do NOT rely on GatewayInterface existence here because some installations may
// provide legacy shims for backward-compatibility.
$wto_faraz_pwsms_modern = class_exists( __NAMESPACE__ . '\\Gateway' );

if ( $wto_faraz_pwsms_modern ) {

	/**
	 * PWSMS 7.2+: gateways extend abstract Gateway.
	 */
	class FarazSMSNext extends Gateway {
		use WTO_FarazSMSNext_Logic;

		public string $api_url = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
		public string $simple_sms_url = 'https://api.iranpayamak.com/ws/v1/sms/simple';
		public array $failed_numbers = [];
		public string $api_key = '';
		// Declared explicitly to avoid PHP 8.2 "creation of dynamic property" deprecation.
		public string $username = '';
		public string $password = '';
		public string $senderNumber = '';

		public static function id(): string {
			return 'farazsmsnext';
		}

		public static function name(): string {
			return 'Farazsms.com(Next)';
		}

		public function __construct() {
			parent::__construct();
			$this->senderNumber = \PWSMS()->get_option( 'sms_gateway_sender' );
			$this->api_key       = \PWSMS()->get_option( 'sms_gateway_apikey' );
			if ( $this->api_key === '' || $this->api_key === null ) {
				$this->api_key = \PWSMS()->get_option( 'sms_gateway_username' );
			}
			$this->username = (string) $this->api_key;
			$this->password = '';
		}
	}

	class IranPayamak extends FarazSMSNext {
		public static function id(): string {
			return 'iranpayamak';
		}

		public static function name(): string {
			return 'iranpayamak.com';
		}
	}
} else {

	/**
	 * PWSMS 7.1: GatewayInterface + GatewayTrait.
	 */
	class FarazSMSNext implements GatewayInterface {
		use GatewayTrait;
		use WTO_FarazSMSNext_Logic;

		public string $api_url = 'https://api.iranpayamak.com/ws/v1/sms/pattern';
		public string $simple_sms_url = 'https://api.iranpayamak.com/ws/v1/sms/simple';
		public array $failed_numbers = [];
		public string $api_key = '';
		// Declared explicitly to avoid PHP 8.2 "creation of dynamic property" deprecation.
		public string $username = '';
		public string $password = '';
		public string $senderNumber = '';

		public static function id() {
			return 'farazsmsnext';
		}

		public static function name() {
			return 'Farazsms.com(Next)';
		}

		public function __construct() {
			$this->senderNumber = \PWSMS()->get_option( 'sms_gateway_sender' );
			$this->api_key       = \PWSMS()->get_option( 'sms_gateway_apikey' );
			if ( empty( $this->api_key ) ) {
				$this->api_key = \PWSMS()->get_option( 'sms_gateway_username' );
			}
			$this->username = $this->api_key;
			$this->password = '';
		}
	}

	class IranPayamak extends FarazSMSNext {
		public static function id() {
			return 'iranpayamak';
		}

		public static function name() {
			return 'iranpayamak.com';
		}
	}
}
