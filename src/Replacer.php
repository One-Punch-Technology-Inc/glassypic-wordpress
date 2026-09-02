<?php
declare(strict_types=1);
namespace GlassyPic;

class Replacer
{
	private const ALLOWED_MIME_TYPES = [
		'image/jpeg',
		'image/png',
		'image/webp',
		'image/gif',
		'image/avif',
		'image/svg+xml',
	];

	public function __construct( private readonly MetaManager $meta ) {}

	public function swap( int $attachmentId, string $processedTmpPath ): void {
		$destPath   = get_attached_file($attachmentId);
		$uploadsDir = wp_upload_dir()['basedir'];

		// Path traversal guard
		$dest_dir_real = realpath(dirname($destPath));
		$destDir       = false !== $dest_dir_real ? $dest_dir_real : dirname($destPath);
		$uploads_real  = realpath($uploadsDir);
		$uploadsReal   = false !== $uploads_real ? $uploads_real : $uploadsDir;
		if ( ! str_starts_with($destDir . DIRECTORY_SEPARATOR, $uploadsReal . DIRECTORY_SEPARATOR)) {
			throw new \RuntimeException('Path traversal detected: destination is outside uploads directory');
		}

		// MIME validation — check the downloaded file, not the tmp path extension
		$mimeCheck = wp_check_filetype_and_ext($processedTmpPath, basename($destPath));
		if (empty($mimeCheck['type']) || ! in_array($mimeCheck['type'], self::ALLOWED_MIME_TYPES, true)) {
			wp_delete_file($processedTmpPath);
			throw new \RuntimeException('MIME validation failed: processed file is not an allowed image type');
		}

		// File-size guard: reject empty downloads (network truncation)
		if (filesize($processedTmpPath) === 0) {
			wp_delete_file($processedTmpPath);
			throw new \RuntimeException('Downloaded file is empty (0 bytes)');
		}

		// Image-integrity guard: bitmap types must pass getimagesize()
		$rasterMimes = [ 'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif' ];
		if (in_array($mimeCheck['type'], $rasterMimes, true) && getimagesize($processedTmpPath) === false) {
			wp_delete_file($processedTmpPath);
			throw new \RuntimeException('Downloaded file failed image integrity check');
		}

		// Create .glassypic-orig backup before overwriting
		$backupPath = $destPath . '.glassypic-orig';
		if ( ! file_exists($backupPath)) {
			copy($destPath, $backupPath);
			$this->meta->setOrigBackup($attachmentId, $backupPath);
		}

		// Atomic swap via copy+rename (rename is atomic on POSIX same-filesystem)
		$stagingPath = $destPath . '.new';
		if ( ! copy($processedTmpPath, $stagingPath)) {
			throw new \RuntimeException('Failed to stage processed file at ' . $stagingPath);
		}
		if ( ! rename($stagingPath, $destPath)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
			wp_delete_file($stagingPath);
			throw new \RuntimeException('Failed to atomically replace original file');
		}
		wp_delete_file($processedTmpPath);

		// Regenerate WP thumbnails; the Scheduler::queueOnUpload re-entrant guard
		// prevents re-queuing this attachment since status is already 'completed'.
		$metadata = wp_generate_attachment_metadata($attachmentId, $destPath);
		wp_update_attachment_metadata($attachmentId, $metadata);
	}
}
