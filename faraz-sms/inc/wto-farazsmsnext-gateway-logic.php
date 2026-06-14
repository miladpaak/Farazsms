<?php
namespace PW\PWSMS\Gateways;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait WTO_FarazSMSNext_Logic {
public function send( $data = [] ) {
		$api_key = trim( $this->api_key );
		$message_content = trim( $this->message );
		$sender_number = trim( $this->senderNumber );
		$recipient_numbers = $this->mobile;

		if ( function_exists( 'wto_split_mobile_list' ) ) {
			$recipient_numbers = wto_split_mobile_list( $recipient_numbers );
		} elseif ( function_exists( 'wto_normalize_phone' ) ) {
			if ( is_array( $recipient_numbers ) ) {
				$recipient_numbers = array_map( 'wto_normalize_phone', $recipient_numbers );
			} else {
				$normalized          = wto_normalize_phone( $recipient_numbers );
				$recipient_numbers   = is_array( $normalized ) ? $normalized : array( $normalized );
			}
		}

		if ( ! is_array( $recipient_numbers ) ) {
			$recipient_numbers = array( $recipient_numbers );
		}
		$recipient_numbers = array_values( array_filter( $recipient_numbers ) );
		
		$order_id = ! empty( $data['post_id'] ) ? intval( $data['post_id'] ) : 0;

		if ( empty( $api_key ) ) {
			return 'لطفا کلید دسترسی (Api-Key) را وارد کنید.';
		}

		if ( empty( $sender_number ) ) {
			$sender_number = '90008361';
		}

		$this->failed_numbers = [];
		$message_content = str_replace( 'pcode', 'patterncode', $message_content );
		$is_pattern = substr( $message_content, 0, 11 ) === "patterncode";
		$pattern_data = null;
		$pattern_code = null;
		if ( ! $is_pattern && $order_id > 0 && function_exists( 'PWSMS' ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			if ( $order ) {
				$order_status = $order->get_status();
				$modified_status = PWSMS()->modify_status( $order_status );
				$section_type = 'buyer';
				if ( ! empty( $data['type'] ) ) {
					if ( $data['type'] == 4 ) {
						$section_type = 'super_admin';
					} elseif ( $data['type'] == 5 ) {
						$section_type = 'product_admin';
					}
				}
				
				$original_template = '';
				if ( $section_type === 'super_admin' ) {
					$original_template = PWSMS()->get_option( 'super_admin_sms_body_' . $modified_status );
				} elseif ( $section_type === 'product_admin' ) {
					$original_template = PWSMS()->get_option( 'product_admin_sms_body_' . $modified_status );
				} else {
					$original_template = PWSMS()->get_option( 'sms_body_' . $modified_status );
				}
				
				$patterns = get_option( 'wto_patterns', [] );
				
				if ( ! empty( $patterns ) && is_array( $patterns ) ) {
					if ( isset( $patterns[ $section_type ] ) && is_array( $patterns[ $section_type ] ) ) {
						if ( isset( $patterns[ $section_type ][ $modified_status ] ) ) {
							$pattern_code = $patterns[ $section_type ][ $modified_status ];
						}
					}
				}
				
				if ( ! empty( $pattern_code ) && ! empty( $original_template ) ) {
					$pattern_data = [];
					preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $original_template, $shortcode_matches );
					$shortcodes = ! empty( $shortcode_matches[1] ) ? array_unique( $shortcode_matches[1] ) : [];
					
				if ( ! empty( $shortcodes ) && function_exists( 'PWSMS' ) ) {
					foreach ( $shortcodes as $shortcode ) {
						$attr_value = PWSMS()->replace_short_codes( '{' . $shortcode . '}', $order_status, $order );
						$attr_value = $this->normalize_pattern_attribute_value( $attr_value );
						if ( $attr_value === null ) {
							$pattern_data[ $shortcode ] = null;
						} else {
							$current_encoding = mb_detect_encoding( $attr_value, 'UTF-8, ISO-8859-1, Windows-1252', true );
							if ( $current_encoding && $current_encoding !== 'UTF-8' ) {
								$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', $current_encoding );
							} elseif ( ! $current_encoding ) {
								$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII' );
							}
							if ( ! mb_check_encoding( $attr_value, 'UTF-8' ) ) {
								$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII' );
							}
							$pattern_data[ $shortcode ] = $attr_value;
						}
					}
				}
					
					if ( ! empty( $pattern_data ) ) {
						$is_pattern = true;
						$message_content = 'patterncode:' . $pattern_code;
					}
				}
			}
		}

		if ( $is_pattern ) {
			if ( $pattern_data === null || empty( $pattern_data ) ) {
				$message_content = str_replace( [ "\r\n", "\n" ], ';', $message_content );
				$message_parts = explode( ';', $message_content );
				
				if ( empty( $message_parts[0] ) ) {
					return 'خطا: کد پترن یافت نشد.';
				}
				
				$pattern_code_line = explode( ':', $message_parts[0] );
				if ( count( $pattern_code_line ) < 2 ) {
					return 'خطا: فرمت کد پترن نامعتبر است.';
				}
				
				if ( empty( $pattern_code ) ) {
					$pattern_code = trim( $pattern_code_line[1] );
				}
				unset( $message_parts[0] );

				$pattern_data = [];
				foreach ( $message_parts as $parameter ) {
					$split_parameter = explode( ':', $parameter, 2 );
					if ( count( $split_parameter ) == 2 ) {
						$key = trim( $split_parameter[0] );
						$value = trim( $split_parameter[1] );
						if ( ! empty( $value ) && ! mb_check_encoding( $value, 'UTF-8' ) ) {
							$current_encoding = mb_detect_encoding( $value, 'UTF-8, ISO-8859-1, Windows-1252', true );
							if ( $current_encoding && $current_encoding !== 'UTF-8' ) {
								$value = mb_convert_encoding( $value, 'UTF-8', $current_encoding );
							} else {
								$value = mb_convert_encoding( $value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII' );
							}
						}
						
						$pattern_data[ $key ] = $value;
					}
				}
			}
			
			if ( empty( $pattern_code ) ) {
				$message_content = str_replace( [ "\r\n", "\n" ], ';', $message_content );
				$message_parts = explode( ';', $message_content );
				if ( ! empty( $message_parts[0] ) ) {
					$pattern_code_line = explode( ':', $message_parts[0] );
					if ( count( $pattern_code_line ) >= 2 ) {
						$pattern_code = trim( $pattern_code_line[1] );
					}
				}
			}
			
			if ( empty( $pattern_data ) && $order_id > 0 && function_exists( 'PWSMS' ) && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( $order ) {
					$original_template = ! empty( $data['original_template'] ) ? $data['original_template'] : '';
					if ( empty( $original_template ) ) {
						$order_status = $order->get_status();
						if ( function_exists( 'PWSMS' ) ) {
							$original_template = PWSMS()->get_option( 'sms_body_' . $order_status );
						}
					}
					
					if ( ! empty( $original_template ) && substr( trim( $original_template ), 0, 11 ) === "patterncode" ) {
						preg_match_all( '/\{([a-zA-Z0-9_]+)\}/', $original_template, $shortcode_matches );
						$shortcodes = ! empty( $shortcode_matches[1] ) ? array_unique( $shortcode_matches[1] ) : [];
						
						if ( ! empty( $shortcodes ) && function_exists( 'PWSMS' ) ) {
							$order_status = $order->get_status();
							$template_lines = explode( "\n", trim( $original_template ) );
						foreach ( array_slice( $template_lines, 1 ) as $line ) {
							$line = trim( $line );
							if ( ! empty( $line ) ) {
								if ( preg_match( '/^([a-zA-Z0-9_]+):\{([a-zA-Z0-9_]+)\}$/', $line, $attr_match ) ) {
									$attr_name = $attr_match[1];
									$shortcode = $attr_match[2];
									$attr_value = PWSMS()->replace_short_codes( '{' . $shortcode . '}', $order_status, $order );
									$attr_value = $this->normalize_pattern_attribute_value( $attr_value );
									if ( $attr_value === null ) {
										$pattern_data[$attr_name] = null;
									} else {
										if ( ! mb_check_encoding( $attr_value, 'UTF-8' ) ) {
											$current_encoding = mb_detect_encoding( $attr_value, 'UTF-8, ISO-8859-1, Windows-1252', true );
											if ( $current_encoding && $current_encoding !== 'UTF-8' ) {
												$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', $current_encoding );
											} else {
												$attr_value = mb_convert_encoding( $attr_value, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII' );
											}
										}
										
										$pattern_data[$attr_name] = $attr_value;
									}
								}
							}
						}
						}
					}
				}
			}
			
			if ( ! empty( $pattern_data ) && is_array( $pattern_data ) ) {
				foreach ( $pattern_data as $key => $value ) {
					if ( $value === null ) {
						continue;
					}
					$coerced = $this->normalize_pattern_attribute_value( $value );
					if ( $coerced === null ) {
						$pattern_data[ $key ] = null;
						continue;
					}
					if ( ! mb_check_encoding( $coerced, 'UTF-8' ) ) {
						$current_encoding = mb_detect_encoding( $coerced, 'UTF-8, ISO-8859-1, Windows-1252', true );
						if ( $current_encoding && $current_encoding !== 'UTF-8' ) {
							$pattern_data[ $key ] = mb_convert_encoding( $coerced, 'UTF-8', $current_encoding );
						} else {
							$pattern_data[ $key ] = mb_convert_encoding( $coerced, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252, ASCII' );
						}
					} else {
						$pattern_data[ $key ] = $coerced;
					}
				}
			}
			
			foreach ( $recipient_numbers as $recipient ) {
				$body = [
					'code'          => $pattern_code,
					'recipient'     => $recipient,
					'attributes'    => $pattern_data,
					'line_number'   => $sender_number,
					'number_format' => 'english',
				];

				$response = $this->post_with_fallback( $this->api_url, [
					'method'  => 'POST',
					'body'    => json_encode( $body, JSON_UNESCAPED_UNICODE ),
					'timeout' => 30,
					'headers' => [
						'Accept'       => 'application/json',
						'Content-Type' => 'application/json',
						'Api-Key'      => $api_key,
					],
				] );
				 
				$this->handle_response( $response, $recipient );
			}

		} else {
			$body = [
				'text'          => $message_content,
				'line_number'   => $sender_number,
				'recipients'    => $recipient_numbers,
				'number_format' => 'english',
			];

			$response = $this->post_with_fallback( $this->simple_sms_url, [
				'method'  => 'POST',
				'body'    => json_encode( $body, JSON_UNESCAPED_UNICODE ),
				'timeout' => 30,
				'headers' => [
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
					'Api-Key'      => $api_key,
				],
			] );
			
			if ( is_wp_error( $response ) ) {
				foreach ( $recipient_numbers as $recipient ) {
					$this->failed_numbers[ $recipient ] = $response->get_error_message();
				}
			} else {
				$response_code = wp_remote_retrieve_response_code( $response );
				$response_body = wp_remote_retrieve_body( $response );
				$response_data = json_decode( $response_body, true );
				$is_success_code = $response_code >= 200 && $response_code < 300;
				$is_success_status = isset( $response_data['status'] ) && $response_data['status'] === 'success';
				$is_success = $is_success_code && $is_success_status;

				if ( $is_success ) {
					return true;
				} else {
					$error_message = '';
					if ( isset( $response_data['status'] ) && $response_data['status'] !== 'success' ) {
						$api_message = $this->normalize_error_message( $response_data['message'] ?? '' );
						if ( $api_message !== '' ) {
							$error_message = $api_message;
						} else {
							$error_message = 'خطای ارسال پیامک از سمت API.';
						}
					} elseif ( ! $is_success_code ) {
						$error_message = $response_code . ' -> ' . wp_remote_retrieve_response_message( $response );
					}
					
					$error_message = $this->normalize_error_message( $error_message );
					if ( $error_message !== '' ) {
						
						foreach ( $recipient_numbers as $recipient ) {
					$this->failed_numbers[ $recipient ] = $error_message;
				}
			}
		}
			}
		}

		if ( ! empty( $this->failed_numbers ) ) {
			$grouped = [];
			foreach ( $this->failed_numbers as $number => $message ) {
				if ( ! is_string( $message ) ) {
					if ( is_array( $message ) ) {
						$message = json_encode( $message, JSON_UNESCAPED_UNICODE );
					} else {
						$message = (string) $message;
					}
				}
				$message_key = strlen( $message ) > 100 ? md5( $message ) : $message;
				
				if ( ! isset( $grouped[ $message_key ] ) ) {
					$grouped[ $message_key ] = [ 'message' => $message, 'numbers' => [] ];
				}
				$grouped[ $message_key ]['numbers'][] = $number;
			}

			$errors = [];
			foreach ( $grouped as $group ) {
				$errors[] = implode( ',', $group['numbers'] ) . ': ' . $group['message'];
			}

			return implode( ', ', $errors );
		}

		return true;
	}


	/**
	 * Handle the response for each recipient.
	 *
	 * @param mixed $response
	 * @param string $recipient
	 */
	private function handle_response( $response, $recipient ) {

		if ( is_wp_error( $response ) ) {
			$this->failed_numbers[ $recipient ] = $response->get_error_message();

			return;
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$response_message = wp_remote_retrieve_response_message( $response );
		$response_body = wp_remote_retrieve_body( $response );

		if ( empty( $response_code ) || 200 != $response_code ) {
			// تلاش برای استخراج پیام خطا از body
			$error_message = $response_code . ' -> ' . $response_message;
			
			if ( ! empty( $response_body ) ) {
				$response_data = json_decode( $response_body, true );
				if ( json_last_error() === JSON_ERROR_NONE && is_array( $response_data ) ) {
					// اگر message در response_data وجود داشت، از آن استفاده کن
					$top_message  = $this->normalize_error_message( $response_data['message'] ?? '' );
					$data_message = $this->normalize_error_message( $response_data['data']['message'] ?? '' );
					if ( $top_message !== '' ) {
						$error_message = $top_message;
					} elseif ( $data_message !== '' ) {
						$error_message = $data_message;
					} else {
						// اگر message نداشت، کد و پیام HTTP را نگه دار
						$error_message = $response_code . ' -> ' . $response_message;
					}
				}
			}
			
			// اطمینان از اینکه error_message یک string است
			if ( ! is_string( $error_message ) ) {
				if ( is_array( $error_message ) ) {
					$error_message = json_encode( $error_message, JSON_UNESCAPED_UNICODE );
				} else {
					$error_message = (string) $error_message;
				}
			}
			
			$this->failed_numbers[ $recipient ] = $error_message;

			return;

		}

		if ( empty( $response_body ) ) {

			$this->failed_numbers[ $recipient ] = 'بدون پاسخ دریافتی از سمت وب سرویس.';

			return;

		}

		$response_data = json_decode( $response_body, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->failed_numbers[ $recipient ] = 'فرمت نامعتبر پاسخ از سمت وب سرویس.';
			return;
		}

		if ( isset( $response_data['status'] ) && $response_data['status'] === 'success' ) {
			return;
		}

		if ( isset( $response_data['status'] ) && $response_data['status'] !== 'success' ) {
			$error_message = $response_data['message'] ?? 'خطای ناشناخته از API جدید.';
			if ( isset( $response_data['data']['message'] ) ) {
				$error_message = $response_data['data']['message'];
			}
			if ( ! is_string( $error_message ) ) {
				if ( is_array( $error_message ) ) {
					$error_message = json_encode( $error_message, JSON_UNESCAPED_UNICODE );
				} else {
					$error_message = (string) $error_message;
				}
			}
			$this->failed_numbers[ $recipient ] = $error_message;

			return;
		}

		if ( is_numeric( $response_data ) || ( isset( $response_data[0] ) && $response_data[0] == '0' ) ) {
			return;
		}

		$error_msg = $response_data[1] ?? 'خطای ناشناخته.';
		if ( ! is_string( $error_msg ) ) {
			if ( is_array( $error_msg ) ) {
				$error_msg = json_encode( $error_msg, JSON_UNESCAPED_UNICODE );
			} else {
				$error_msg = (string) $error_msg;
			}
		}
		$this->failed_numbers[ $recipient ] = $error_msg;
	}

	/**
	 * Coerce PWSMS shortcode / pattern values to UTF-8 string or null (never array) for API attributes.
	 *
	 * @param mixed $value Raw replacement value.
	 * @return string|null Non-empty string or null.
	 */
	private function normalize_pattern_attribute_value( $value ) {
		if ( $value === null ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
		}
		if ( ! is_string( $value ) ) {
			if ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$value = (string) $value;
			} else {
				$value = '';
			}
		}
		$value = trim( $value );

		return $value === '' ? null : $value;
	}

	/**
	 * Convert mixed API error values to safe trimmed string.
	 *
	 * @param mixed $message
	 * @return string
	 */
	private function normalize_error_message( $message ) {
		if ( is_array( $message ) ) {
			$message = wp_json_encode( $message, JSON_UNESCAPED_UNICODE );
		} elseif ( ! is_string( $message ) ) {
			$message = (string) $message;
		}
		return trim( $message );
	}

	/**
	 * Execute wp_remote_post with cURL fallback on blocked hosts.
	 *
	 * @param string $url
	 * @param array  $args
	 * @return array|WP_Error
	 */
	private function post_with_fallback( $url, $args ) {
		$response = wp_remote_post( $url, $args );
		if ( ! function_exists( 'wto_is_http_blocked_error' ) || ! function_exists( 'wto_curl_fallback_request' ) ) {
			return $response;
		}
		if ( ! wto_is_http_blocked_error( $response ) ) {
			return $response;
		}
		return wto_curl_fallback_request( 'POST', $url, $args );
	}

}
