<?php
declare(strict_types=1);
namespace GlassyPic;

class Scheduler
{
	public const ACTION_HOOK  = 'glassypic/process_attachment';
	public const ACTION_GROUP = 'glassypic';

	public function __construct( private readonly MetaManager $meta ) {}

	/**
	 * Hook: wp_generate_attachment_metadata
	 * Returns $metadata unchanged — WP requires this filter to pass metadata through.
	 */
	public function queueOnUpload( array $metadata, int $attachmentId ): array {
		if ( ! get_option('glassypic_auto_optimize', true)) {
			return $metadata;
		}

		// Re-entrant guard: prevents infinite loop when Replacer::swap() calls
		// wp_generate_attachment_metadata() to regenerate thumbnails.
		$status = $this->meta->getStatus($attachmentId);
		if (in_array($status, [ 'completed', 'processing', 'pending' ], true)) {
			return $metadata;
		}

		$mimeType = get_post_mime_type($attachmentId);
		if ( ! str_starts_with( (string) $mimeType, 'image/')) {
			return $metadata;
		}

		$this->meta->setStatus($attachmentId, 'pending');
		as_schedule_single_action(
			time(),
			self::ACTION_HOOK,
			[ $attachmentId ],
			self::ACTION_GROUP
		);

		return $metadata;
	}

	public function queue( int $attachmentId ): void {
		$this->meta->setStatus($attachmentId, 'pending');
		as_schedule_single_action(
			time(),
			self::ACTION_HOOK,
			[ $attachmentId ],
			self::ACTION_GROUP
		);
	}

	public function rescheduleAt( int $attachmentId, \DateTimeImmutable $when ): void {
		as_schedule_single_action(
			$when->getTimestamp(),
			self::ACTION_HOOK,
			[ $attachmentId ],
			self::ACTION_GROUP
		);
	}

	public function cancelAll(): void {
		as_unschedule_all_actions(self::ACTION_HOOK, [], self::ACTION_GROUP);
	}
}
