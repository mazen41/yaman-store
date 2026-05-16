<?php
/**
 * OCR.Space SKU extraction endpoint.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function engitech_extract_sku_from_ocr_text( $text ) {
	if ( ! is_string( $text ) || '' === $text ) {
		return null;
	}

	if ( preg_match( '/\bSK\d{10,}\b/', $text, $matches ) ) {
		return $matches[0];
	}

	return null;
}

function engitech_ocr_space_extract_sku() {
	$api_key = 'K88393251788957';
	$image_base64 = isset( $_POST['image_base64'] ) ? trim( wp_unslash( $_POST['image_base64'] ) ) : '';
	$image_url = isset( $_POST['image_url'] ) ? esc_url_raw( wp_unslash( $_POST['image_url'] ) ) : '';

	$request_body = array(
		'apikey'      => $api_key,
		'language'    => 'eng',
		'isOverlayRequired' => 'false',
		'OCREngine'   => '2',
	);

	if ( ! empty( $_FILES['image_file']['tmp_name'] ) && is_uploaded_file( $_FILES['image_file']['tmp_name'] ) ) {
		$request_body['file'] = curl_file_create(
			$_FILES['image_file']['tmp_name'],
			$_FILES['image_file']['type'],
			$_FILES['image_file']['name']
		);
	} elseif ( '' !== $image_base64 ) {
		$request_body['base64Image'] = strpos( $image_base64, 'data:' ) === 0 ? $image_base64 : 'data:image/jpeg;base64,' . $image_base64;
	} elseif ( '' !== $image_url ) {
		$request_body['url'] = $image_url;
	} else {
		wp_send_json_success( array( 'sku' => null ) );
	}

	$response = wp_remote_post(
		'https://api.ocr.space/parse/image',
		array(
			'timeout' => 12,
			'body'    => $request_body,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_send_json_success( array( 'sku' => null ) );
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) || empty( $data['ParsedResults'] ) || ! is_array( $data['ParsedResults'] ) ) {
		wp_send_json_success( array( 'sku' => null ) );
	}

	$full_text = '';
	foreach ( $data['ParsedResults'] as $result ) {
		if ( ! empty( $result['ParsedText'] ) ) {
			$full_text .= "\n" . $result['ParsedText'];
		}
	}

	$sku = engitech_extract_sku_from_ocr_text( $full_text );
	wp_send_json_success( array( 'sku' => $sku ) );
}

add_action( 'wp_ajax_engitech_ocr_space_extract_sku', 'engitech_ocr_space_extract_sku' );
add_action( 'wp_ajax_nopriv_engitech_ocr_space_extract_sku', 'engitech_ocr_space_extract_sku' );
