<?php
declare(strict_types=1);
namespace GlassyPic\Exception;

class PollTimeoutException extends \RuntimeException
{
	public function __construct( string $message, public readonly string $jobId ) {
		parent::__construct($message);
	}
}
