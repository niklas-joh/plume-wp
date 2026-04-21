<?php
declare( strict_types=1 );

namespace WP_AI_Mind\Admin;

use WP_AI_Mind\DB\Schema;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and renders a WP admin dashboard widget showing a quick usage summary.
 */
class NJ_Usage_Widget {

	public static function register_hooks(): void {
		add_action( 'wp_dashboard_setup', [ self::class, 'register_widget' ] );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue_styles' ] );
	}

	public static function register_widget(): void {
		wp_add_dashboard_widget(
			'wp_ai_mind_usage_widget',
			esc_html__( 'WP AI Mind — Usage', 'wp-ai-mind' ),
			[ self::class, 'render' ]
		);
	}

	public static function enqueue_styles( string $hook ): void {
		if ( 'index.php' !== $hook ) {
			return;
		}
		wp_enqueue_style(
			'wp-ai-mind-usage-widget',
			WP_AI_MIND_URL . 'assets/admin/usage-widget.css',
			[],
			WP_AI_MIND_VERSION
		);
	}

	public static function render(): void {
		global $wpdb;

		$table = Schema::table( 'usage_log' );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$totals = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT SUM(total_tokens) AS tokens, SUM(cost_usd) AS cost
				 FROM {$table}
				 WHERE created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)",
				30
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$tokens = isset( $totals['tokens'] ) ? (int) $totals['tokens'] : 0;
		$cost   = isset( $totals['cost'] ) ? (float) $totals['cost'] : 0.0;
		$budget = (float) get_option( 'wp_ai_mind_monthly_budget', 0 );
		$pct    = ( $budget > 0 ) ? (int) min( 100, round( ( $cost / $budget ) * 100 ) ) : 0;

		if ( $pct > 80 ) {
			$bar_class = 'nj-usage-widget__bar--error';
			$pct_class = 'nj-usage-widget__pct--error';
		} elseif ( $pct > 60 ) {
			$bar_class = 'nj-usage-widget__bar--warning';
			$pct_class = 'nj-usage-widget__pct--warning';
		} else {
			$bar_class = 'nj-usage-widget__bar--success';
			$pct_class = 'nj-usage-widget__pct--success';
		}
		?>
		<div class="nj-usage-widget">
			<?php if ( $budget > 0 ) : ?>
			<div class="nj-usage-widget__progress">
				<div class="nj-usage-widget__track">
					<div
						class="nj-usage-widget__bar <?php echo esc_attr( $bar_class ); ?>"
						style="width: <?php echo absint( $pct ); ?>%"
						role="progressbar"
						aria-valuenow="<?php echo absint( $pct ); ?>"
						aria-valuemin="0"
						aria-valuemax="100"
					></div>
				</div>
				<span class="nj-usage-widget__pct <?php echo esc_attr( $pct_class ); ?>">
					<?php echo absint( $pct ); ?>%
				</span>
			</div>
			<?php endif; ?>
			<dl class="nj-usage-widget__stats">
				<div>
					<dt><?php esc_html_e( 'Cost (30 days)', 'wp-ai-mind' ); ?></dt>
					<dd>$<?php echo esc_html( number_format( $cost, 2 ) ); ?></dd>
				</div>
				<div>
					<dt><?php esc_html_e( 'Tokens (30 days)', 'wp-ai-mind' ); ?></dt>
					<dd><?php echo esc_html( number_format( $tokens ) ); ?></dd>
				</div>
			</dl>
		</div>
		<?php
	}
}
