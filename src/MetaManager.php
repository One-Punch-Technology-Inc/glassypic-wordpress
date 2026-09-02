<?php
declare(strict_types=1);
namespace GlassyPic;

class MetaManager
{
	private const PREFIX = '_glassypic_';

	public function getStatus( int $attachmentId ): string {
		return (string) get_post_meta($attachmentId, self::PREFIX . 'status', true);
	}

	public function setStatus( int $attachmentId, string $status ): void {
		update_post_meta($attachmentId, self::PREFIX . 'status', $status);
		if ($status === 'pending') {
			delete_post_meta($attachmentId, self::PREFIX . 'job_id');
		}
	}

	public function getJobId( int $attachmentId ): ?string {
		$value = get_post_meta($attachmentId, self::PREFIX . 'job_id', true);
		return $value !== '' ? (string) $value : null;
	}

	public function setJobId( int $attachmentId, string $jobId ): void {
		update_post_meta($attachmentId, self::PREFIX . 'job_id', $jobId);
	}

	public function setError( int $attachmentId, string $message ): void {
		update_post_meta($attachmentId, self::PREFIX . 'error', sanitize_text_field($message));
		$this->setStatus($attachmentId, 'failed');
	}

	public function saveResults( int $attachmentId, array $results ): void {
		$fields = [
			'original_size'  => '_glassypic_original_size',
			'processed_size' => '_glassypic_processed_size',
			'savings_pct'    => '_glassypic_savings_pct',
			'optimized_at'   => '_glassypic_optimized_at',
			'alt_text'       => '_wp_attachment_image_alt',
		];
		foreach ($fields as $key => $metaKey) {
			if (array_key_exists($key, $results)) {
				$value = $key === 'alt_text'
					? sanitize_text_field( (string) $results[ $key ])
					: $results[ $key ];
				update_post_meta($attachmentId, $metaKey, $value);
			}
		}
		$this->setStatus($attachmentId, 'completed');
	}

	public function setOrigBackup( int $attachmentId, string $backupPath ): void {
		update_post_meta($attachmentId, self::PREFIX . 'orig_backup', $backupPath);
	}

	public function clearOptimizationData( int $attachmentId ): void {
		$keys = [
			self::PREFIX . 'status',
			self::PREFIX . 'job_id',
			self::PREFIX . 'original_size',
			self::PREFIX . 'processed_size',
			self::PREFIX . 'savings_pct',
			self::PREFIX . 'optimized_at',
			self::PREFIX . 'error',
			self::PREFIX . 'orig_backup',
		];
		foreach ($keys as $key) {
			delete_post_meta($attachmentId, $key);
		}
	}
}
