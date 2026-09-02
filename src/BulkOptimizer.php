<?php
declare(strict_types=1);
namespace GlassyPic;

class BulkOptimizer
{
	public function __construct(
		private readonly MetaManager $meta,
		private readonly Scheduler $scheduler,
		private readonly Settings $settings,
		private readonly ApiClient $api,
	) {}

	public function renderPage(): void {
		$counts  = $this->getStatusCounts();
		$apiKey  = $this->settings->getApiKey();
		$account = $apiKey ? get_transient('glassypic_account_cache') : null;
		?>
		<div class="wrap">
			<h1><?php esc_html_e('Bulk Optimize — GlassyPic', 'glassypic'); ?></h1>

			<?php if ($account) : ?>
			<p>
				<?php
				printf(
					/* translators: 1: account tier name, 2: credits remaining count */
					esc_html__('%1$s plan · %2$s credits remaining', 'glassypic'),
					esc_html(ucfirst($account['tier'])),
					esc_html(number_format($account['credits_remaining']))
				);
				?>
			</p>
			<?php endif; ?>

			<div id="glassypic-progress-bar" style="display:none; margin: 1em 0;">
				<progress id="glassypic-progress" max="<?php echo esc_attr($counts['total']); ?>"
							value="<?php echo esc_attr($counts['completed']); ?>" style="width:100%;height:20px;"></progress>
				<p id="glassypic-progress-text"></p>
			</div>

			<p>
				<strong>
				<?php
				/* translators: %d: count of optimized images */
				printf(esc_html__('✓ Optimized: %d', 'glassypic'), absint($counts['completed']));
				?>
				</strong>
				&nbsp;|&nbsp;
				<?php
				/* translators: %d: count of pending images */
				printf(esc_html__('Pending: %d', 'glassypic'), absint($counts['pending'] + $counts['processing']));
				?>
				&nbsp;|&nbsp;
				<?php
				/* translators: %d: count of failed images */
				printf(esc_html__('Failed: %d', 'glassypic'), absint($counts['failed']));
				?>
			</p>

			<?php
			$remaining = $counts['pending'] + $counts['processing'];
			$estimated = $remaining * 7; // 7 credits per full pipeline
			if ($account && $estimated > $account['credits_remaining']) :
				?>
			<div class="notice notice-warning inline">
				<p>
				<?php
				printf(
					/* translators: 1: estimated credits needed, 2: credits available, 3: upgrade link HTML */
					esc_html__('This batch needs ~%1$d credits but you only have %2$d. %3$s', 'glassypic'),
					absint($estimated),
					absint($account['credits_remaining']),
					'<a href="https://glassypic.com/pricing" target="_blank">' . esc_html__('Upgrade', 'glassypic') . '</a>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				);
				?>
				</p>
			</div>
			<?php endif; ?>

			<button id="glassypic-bulk-start" class="button button-primary">
				<?php
				/* translators: %d: count of images to optimize */
				printf(esc_html__('Optimize %d images', 'glassypic'), absint(max(0, $counts['total'] - $counts['completed'])));
				?>
			</button>
			<?php if ($counts['failed'] > 0) : ?>
			<button id="glassypic-bulk-retry" class="button" style="margin-left:8px;">
				<?php
				/* translators: %d: count of failed images */
				printf(esc_html__('Retry %d failed', 'glassypic'), absint($counts['failed']));
				?>
			</button>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handleAjaxBulkQueue(): void {
		check_ajax_referer('glassypic_ajax', 'nonce');
		if ( ! current_user_can('upload_files')) {
			wp_die(-1);
		}

		$retryFailed = (bool) ( $_POST['retry_failed'] ?? false );
		$ids         = $this->getUnoptimizedAttachmentIds($retryFailed);

		foreach (array_chunk($ids, 50) as $chunk) {
			foreach ($chunk as $id) {
				$this->meta->setStatus($id, 'pending');
				$this->scheduler->queue($id);
			}
		}

		wp_send_json_success([ 'queued' => count($ids) ]);
	}

	public function handleAjaxBulkStatus(): void {
		check_ajax_referer('glassypic_ajax', 'nonce');
		if ( ! current_user_can('upload_files')) {
			wp_die(-1);
		}

		// Rate-limit: cache status counts for 2 seconds
		$cached = get_transient('glassypic_bulk_status_cache');
		if ($cached !== false) {
			wp_send_json_success($cached);
			return;
		}

		$counts = $this->getStatusCounts();
		set_transient('glassypic_bulk_status_cache', $counts, 2);
		wp_send_json_success($counts);
	}

	private function getStatusCounts(): array {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS cnt
                 FROM {$wpdb->postmeta}
                 WHERE meta_key = %s
                 GROUP BY meta_value",
				'_glassypic_status'
			),
			ARRAY_A
		);

		$counts = [
			'completed'  => 0,
			'processing' => 0,
			'pending'    => 0,
			'failed'     => 0,
			'paused'     => 0,
			'total'      => 0,
		];
		foreach ($rows as $row) {
			$status = $row['status'];
			if (isset($counts[ $status ])) {
				$counts[ $status ] = (int) $row['cnt'];
			}
		}

		// total = all image attachments regardless of status
		$counts['total'] = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%' AND post_status = 'inherit'"
		);

		return $counts;
	}

	private function getUnoptimizedAttachmentIds( bool $includeFailed ): array {
		global $wpdb;
		// $statuses contains only hardcoded string literals — no user input is interpolated.
		$statuses = $includeFailed ? [ "'pending'", "'failed'" ] : [ "'pending'" ];

		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared
		return array_map('intval', $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_glassypic_status'
             WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%' AND p.post_status = 'inherit'
             AND (pm.meta_value IS NULL OR pm.meta_value IN (" . implode(',', $statuses) . '))'
		));
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared
	}
}
