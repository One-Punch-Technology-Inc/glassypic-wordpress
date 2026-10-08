<?php
declare(strict_types=1);
namespace GlassyPic;

use GlassyPic\Exception\InsufficientCreditsException;
use GlassyPic\Exception\PollTimeoutException;

class Processor
{
	private const POLL_INTERVAL_SECONDS = 3;
	private const POLL_MAX_SECONDS      = 300;
	private const MAX_RETRIES           = 3;

	public function __construct(
		private readonly ApiClient $api,
		private readonly MetaManager $meta,
		private readonly Replacer $replacer,
		private readonly Scheduler $scheduler,
		private readonly Settings $settings,
	) {}

	public function run( int $attachmentId ): void {
		$filePath = get_attached_file($attachmentId);
		if ( ! $filePath || ! file_exists($filePath)) {
			$this->meta->setError($attachmentId, 'Attachment file not found on disk');
			return;
		}

		$this->meta->setStatus($attachmentId, 'processing');

		try {
			// Resume from poll timeout if a job_id is already stored (skip re-upload)
			$jobId = $this->meta->getJobId($attachmentId);

			if ( ! $jobId) {
				// Phase 1: Upload
				$tempFileId = $this->uploadWithRetry($filePath);

				// Phase 2: Process
				$pipelineSettings = $this->settings->getPipelineSettings();
				if ($this->settings->isSeoAltTextEnabled()) {
					$pipelineSettings['output_seo_tag_gen'] = true;
					$pipelineSettings['output_seo_rename']  = false;
				}
				$jobId = $this->api->process($tempFileId, $pipelineSettings);
				$this->meta->setJobId($attachmentId, $jobId);
			}

			// Phase 3: Poll
			$jobData = $this->pollUntilDone($jobId);

			// Phase 4: Download + Replace
			$processedContent = $this->api->downloadProcessedFile($jobId);
			$tmpPath          = wp_tempnam('glassypic_');
			file_put_contents($tmpPath, $processedContent); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			$this->replacer->swap($attachmentId, $tmpPath);

			// Phase 5: Write results back
			$results = [
				'original_size'  => filesize($filePath),
				'processed_size' => $jobData['processed_size'] ?? null,
				'savings_pct'    => $jobData['processed_compression_ratio'] ?? null,
				'optimized_at'   => ( new \DateTimeImmutable() )->format(\DateTimeInterface::ATOM),
			];
			if ($this->settings->isSeoAltTextEnabled() && ! empty($jobData['seo_alt_text'])) {
				$results['alt_text'] = $jobData['seo_alt_text'];
			}
			$this->meta->saveResults($attachmentId, $results);

			// Phase 6: Optionally compress thumbnails (compress-only, non-fatal)
			if ($this->settings->isOptimizeThumbnails()) {
				$this->optimizeThumbnails($attachmentId, $filePath);
			}

		} catch (InsufficientCreditsException $e) {
			$this->meta->setStatus($attachmentId, 'paused');
			$this->pauseAllPendingJobs($e->creditsResetAt);
			// Also reschedule the current attachment — pauseAllPendingJobs only covers 'pending' ones
			if ($e->creditsResetAt) {
				$resumeAt = ( new \DateTimeImmutable($e->creditsResetAt) )->modify('+5 minutes');
				$this->scheduler->rescheduleAt($attachmentId, $resumeAt);
			}

		} catch (PollTimeoutException $e) {
			// Leave status as 'processing'; job_id is stored — next run resumes from polling
			$this->scheduler->rescheduleAt($attachmentId, new \DateTimeImmutable('+5 minutes'));

		} catch (\Throwable $e) {
			$this->meta->setError($attachmentId, $e->getMessage());
		}
	}

	private function uploadWithRetry( string $filePath ): string {
		$lastException = null;
		for ($attempt = 0; $attempt < self::MAX_RETRIES; $attempt++) {
			try {
				return $this->api->upload($filePath);
			} catch (\RuntimeException $e) {
				$lastException = $e;
			}
		}
		throw $lastException;
	}

	private function pollUntilDone( string $jobId ): array {
		$deadline = time() + self::POLL_MAX_SECONDS;
		while (time() < $deadline) {
			$job = $this->api->pollJob($jobId);
			if ($job['status'] === 'completed') {
				return $job;
			}
			if ($job['status'] === 'failed') {
				throw new \RuntimeException('Processing failed: ' . ( $job['error_message'] ?? 'unknown error' ));
			}
			if ($job['status'] === 'expired') {
				throw new \RuntimeException('Processing job expired before completion');
			}
			sleep(self::POLL_INTERVAL_SECONDS);
		}
		throw new PollTimeoutException(
			'Processing timed out after ' . self::POLL_MAX_SECONDS . 's',
			$jobId
		);
	}

	private function pauseAllPendingJobs( ?string $creditsResetAt ): void {
		if ( ! $creditsResetAt) {
			return;
		}
		$resetTime = new \DateTimeImmutable($creditsResetAt);
		$resumeAt  = $resetTime->modify('+5 minutes');

		global $wpdb;
		$pendingIds = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_glassypic_status' AND meta_value = %s",
				'pending'
			)
		);
		foreach ($pendingIds as $id) {
			$this->scheduler->rescheduleAt( (int) $id, $resumeAt);
			update_post_meta( (int) $id, '_glassypic_status', 'paused');
		}

		set_transient('glassypic_credits_reset_at', $creditsResetAt, DAY_IN_SECONDS * 2);
	}

	private function optimizeThumbnails( int $attachmentId, string $originalFilePath ): void {
		$metadata = wp_get_attachment_metadata($attachmentId);
		if (empty($metadata['sizes'])) {
			return;
		}

		$baseDir = dirname($originalFilePath);

		foreach ($metadata['sizes'] as $size) {
			$thumbPath = $baseDir . DIRECTORY_SEPARATOR . $size['file'];
			if ( ! file_exists($thumbPath)) {
				continue;
			}
			try {
				$tempFileId = $this->api->upload($thumbPath);
				$jobId      = $this->api->process($tempFileId, [ 'output_format' => 'original' ]);
				$this->pollUntilDone($jobId);
				$content = $this->api->downloadProcessedFile($jobId);
				$tmpPath = wp_tempnam('glassypic_thumb_');
				file_put_contents($tmpPath, $content); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				copy($tmpPath, $thumbPath);
				wp_delete_file($tmpPath);
			} catch (\Throwable $e) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- thumbnail failure is intentionally non-fatal
			}
		}
	}
}
