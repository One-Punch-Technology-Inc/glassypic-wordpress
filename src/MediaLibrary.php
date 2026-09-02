<?php
declare(strict_types=1);
namespace GlassyPic;

class MediaLibrary
{
	public function __construct(
		private readonly MetaManager $meta,
		private readonly Scheduler $scheduler,
		private readonly Settings $settings,
	) {}

	public function addStatusColumn( array $columns ): array {
		$columns['glassypic'] = esc_html__('GlassyPic', 'glassypic');
		return $columns;
	}

	public function renderStatusColumn( string $column, int $attachmentId ): void {
		if ($column !== 'glassypic') {
			return;
		}

		$status = $this->meta->getStatus($attachmentId);
		switch ($status) {
			case 'completed':
				$pct = get_post_meta($attachmentId, '_glassypic_savings_pct', true);
				/* translators: %s: percentage of file size reduction */
				echo '<span class="glassypic-status glassypic-done">'
					. esc_html(sprintf(__('✓ %s%% smaller', 'glassypic'), round( (float) $pct, 1))) // phpcs:ignore WordPress.WP.I18n.MissingTranslatorsComment
					. '</span>';
				break;
			case 'processing':
			case 'pending':
				echo '<span class="glassypic-status glassypic-processing">'
					. esc_html__('⟳ Processing…', 'glassypic') . '</span>';
				break;
			case 'failed':
				printf(
					'<span class="glassypic-status glassypic-failed">%s</span> <button class="button button-small glassypic-optimize-single" data-id="%d">%s</button>',
					esc_html__('✕ Failed', 'glassypic'),
					absint($attachmentId),
					esc_html__('Retry', 'glassypic')
				);
				break;
			default:
				printf(
					'<span class="glassypic-status glassypic-none">— </span><button class="button button-small glassypic-optimize-single" data-id="%d">%s</button>',
					absint($attachmentId),
					esc_html__('Optimize', 'glassypic')
				);
		}
	}

	public function renderAttachmentPanel(): void {
		global $post;
		if ( ! $post || $post->post_type !== 'attachment') {
			return;
		}
		$id         = $post->ID;
		$status     = $this->meta->getStatus($id);
		$origSize   = get_post_meta($id, '_glassypic_original_size', true);
		$procSize   = get_post_meta($id, '_glassypic_processed_size', true);
		$backupPath = get_post_meta($id, '_glassypic_orig_backup', true);
		?>
		<div class="misc-pub-section glassypic-panel">
			<strong><?php esc_html_e('GlassyPic', 'glassypic'); ?></strong><br>
			<?php if ($status === 'completed') : ?>
				<?php esc_html_e('Status: Optimized ✓', 'glassypic'); ?><br>
				<?php if ($origSize && $procSize) : ?>
					<?php
					/* translators: 1: original file size, 2: optimized file size */
					printf(esc_html__('%1$s → %2$s', 'glassypic'),
						esc_html(size_format( (int) $origSize)),
						esc_html(size_format( (int) $procSize)));
					?>
						<br>
				<?php endif; ?>
				<button class="button button-small glassypic-optimize-single" data-id="<?php echo absint($id); ?>">
					<?php esc_html_e('Re-optimize', 'glassypic'); ?>
				</button>
				<?php if ($backupPath && file_exists($backupPath)) : ?>
				<br>
				<button class="button button-small glassypic-restore-original" data-id="<?php echo absint($id); ?>" style="margin-top:4px;">
					<?php esc_html_e('Restore Original', 'glassypic'); ?>
				</button>
				<?php endif; ?>
			<?php elseif (in_array($status, [ 'processing', 'pending' ], true)) : ?>
				<?php esc_html_e('Status: Processing…', 'glassypic'); ?>
			<?php else : ?>
				<button class="button button-small glassypic-optimize-single" data-id="<?php echo absint($id); ?>">
					<?php esc_html_e('Optimize', 'glassypic'); ?>
				</button>
			<?php endif; ?>
		</div>
		<?php
	}

	public function handleAjaxOptimizeSingle(): void {
		check_ajax_referer('glassypic_ajax', 'nonce');
		if ( ! current_user_can('upload_files')) {
			wp_die(-1);
		}

		$attachmentId = absint($_POST['attachment_id'] ?? 0);
		if ( ! $attachmentId) {
			wp_send_json_error('Invalid attachment ID');
		}

		$this->meta->setStatus($attachmentId, 'pending');
		$this->scheduler->queue($attachmentId);

		wp_send_json_success([ 'status' => 'queued' ]);
	}

	public function handleAjaxRestoreOriginal(): void {
		check_ajax_referer('glassypic_ajax', 'nonce');
		if ( ! current_user_can('upload_files')) {
			wp_die(-1);
		}

		$attachmentId = absint($_POST['attachment_id'] ?? 0);
		if ( ! $attachmentId) {
			wp_send_json_error('Invalid attachment ID');
			return;
		}

		$backupPath = get_post_meta($attachmentId, '_glassypic_orig_backup', true);
		$destPath   = get_attached_file($attachmentId);

		if ( ! $backupPath || ! $destPath || ! file_exists($backupPath)) {
			wp_send_json_error('Backup not found');
			return;
		}

		// Path guard: backup must be within uploads dir
		$uploadsDir   = wp_upload_dir()['basedir'];
		$uploads_real = realpath($uploadsDir);
		$uploadsReal  = false !== $uploads_real ? $uploads_real : $uploadsDir;
		$backup_real  = realpath($backupPath);
		$backupReal   = false !== $backup_real ? $backup_real : $backupPath;
		if ( ! str_starts_with($backupReal . DIRECTORY_SEPARATOR, $uploadsReal . DIRECTORY_SEPARATOR)) {
			wp_send_json_error('Invalid backup path');
			return;
		}

		if ( ! copy($backupPath, $destPath)) {
			wp_send_json_error('Failed to restore backup');
			return;
		}

		$this->meta->clearOptimizationData($attachmentId);
		wp_delete_file($backupPath);

		$metadata = wp_generate_attachment_metadata($attachmentId, $destPath);
		wp_update_attachment_metadata($attachmentId, $metadata);

		wp_send_json_success([ 'status' => 'restored' ]);
	}
}
