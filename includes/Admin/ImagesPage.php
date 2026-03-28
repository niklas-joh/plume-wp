<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class ImagesPage {

	public static function render(): void {
		echo '<div id="wp-ai-mind-images" class="wp-ai-mind-page"></div>';
	}
}
