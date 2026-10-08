<?php
// tests/Unit/ProcessorTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\{Processor, ApiClient, MetaManager, Replacer, Scheduler, Settings};
use GlassyPic\Exception\InsufficientCreditsException;

class ProcessorTest extends TestCase
{
	protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
	protected function tearDown(): void { Monkey\tearDown(); \Mockery::close(); parent::tearDown(); }

	private function makeMocks(): array
	{
		return [
			'api'       => \Mockery::mock(ApiClient::class),
			'meta'      => \Mockery::mock(MetaManager::class),
			'replacer'  => \Mockery::mock(Replacer::class),
			'scheduler' => \Mockery::mock(Scheduler::class),
			'settings'  => \Mockery::mock(Settings::class),
		];
	}

	public function test_run_completes_full_pipeline(): void
	{
		['api' => $api, 'meta' => $meta, 'replacer' => $replacer,
		 'scheduler' => $scheduler, 'settings' => $settings] = $this->makeMocks();

		Functions\expect('get_attached_file')->with(42)->andReturn('/uploads/photo.jpg');
		Functions\when('file_exists')->justReturn(true);
		Functions\when('filesize')->justReturn(100000);
		Functions\when('wp_tempnam')->justReturn('/tmp/glassypic_out.jpg');
		Functions\when('file_put_contents')->justReturn(1000);

		$api->shouldReceive('upload')->once()->andReturn('tmp-abc');
		$api->shouldReceive('process')->once()->andReturn('job-xyz');
		$api->shouldReceive('pollJob')->andReturn(
			['status' => 'processing'],
			['status' => 'completed', 'processed_size' => 32000, 'seo_alt_text' => 'A cat']
		);
		$api->shouldReceive('downloadProcessedFile')->once()->andReturn('fake binary data');

		$meta->shouldReceive('getJobId')->with(42)->andReturn(null);
		$meta->shouldReceive('setStatus')->with(42, 'processing')->once();
		$meta->shouldReceive('setJobId')->with(42, 'job-xyz')->once();
		$meta->shouldReceive('saveResults')->once();

		$replacer->shouldReceive('swap')->once();
		$settings->shouldReceive('getPipelineSettings')->andReturn([]);
		$settings->shouldReceive('isSeoAltTextEnabled')->andReturn(true);
		$settings->shouldReceive('isOptimizeThumbnails')->andReturn(false);

		$processor = new Processor($api, $meta, $replacer, $scheduler, $settings);
		$processor->run(42);
	}

	public function test_run_marks_failed_when_job_expires(): void
	{
		['api' => $api, 'meta' => $meta, 'replacer' => $replacer,
		 'scheduler' => $scheduler, 'settings' => $settings] = $this->makeMocks();

		Functions\expect('get_attached_file')->with(42)->andReturn('/uploads/photo.jpg');
		Functions\when('file_exists')->justReturn(true);

		$api->shouldReceive('pollJob')->once()->andReturn([ 'status' => 'expired' ]);
		$api->shouldReceive('downloadProcessedFile')->never();

		$meta->shouldReceive('getJobId')->with(42)->andReturn('job-xyz');
		$meta->shouldReceive('setStatus')->with(42, 'processing')->once();
		$meta->shouldReceive('setError')->with(42, \Mockery::pattern('/expired/'))->once();
		$scheduler->shouldReceive('rescheduleAt')->never();

		$processor = new Processor($api, $meta, $replacer, $scheduler, $settings);
		$processor->run(42);
	}

	public function test_run_pauses_and_reschedules_on_429(): void
	{
		['api' => $api, 'meta' => $meta, 'replacer' => $replacer,
		 'scheduler' => $scheduler, 'settings' => $settings] = $this->makeMocks();

		Functions\expect('get_attached_file')->with(42)->andReturn('/uploads/photo.jpg');
		Functions\when('file_exists')->justReturn(true);

		$api->shouldReceive('upload')->once()->andReturn('tmp-abc');
		$api->shouldReceive('process')->once()->andThrow(
			new InsufficientCreditsException('No credits', '2026-06-01T00:05:00Z')
		);

		$meta->shouldReceive('getJobId')->with(42)->andReturn(null);
		$meta->shouldReceive('setStatus')->with(42, 'processing')->once();
		$meta->shouldReceive('setStatus')->with(42, 'paused')->once();

		// rescheduleAt called twice: once for pending ID 99 (pauseAllPendingJobs),
		// once for current attachment 42 (catch block)
		$scheduler->shouldReceive('rescheduleAt')->times(2);
		$settings->shouldReceive('getPipelineSettings')->andReturn([]);
		$settings->shouldReceive('isSeoAltTextEnabled')->andReturn(false);

		Functions\when('set_transient')->justReturn(true);
		Functions\when('update_post_meta')->justReturn(true);

		$wpdb          = \Mockery::mock('wpdb');
		$wpdb->postmeta = 'wp_postmeta';
		$wpdb->shouldReceive('prepare')->andReturn('SELECT ...');
		$wpdb->shouldReceive('get_col')->andReturn([ 99 ]);

		$GLOBALS['wpdb'] = $wpdb;

		$processor = new Processor($api, $meta, $replacer, $scheduler, $settings);
		$processor->run(42);
	}

	public function test_run_reschedules_on_poll_timeout(): void
	{
		['api' => $api, 'meta' => $meta, 'replacer' => $replacer,
		 'scheduler' => $scheduler, 'settings' => $settings] = $this->makeMocks();

		Functions\expect('get_attached_file')->with(42)->andReturn('/uploads/photo.jpg');
		Functions\when('file_exists')->justReturn(true);

		// time(): first call sets deadline to 0+300=300; second call returns 1000 (loop exits)
		$timeCall = 0;
		Functions\when('time')->alias(function () use (&$timeCall) {
			$timeCall++;
			return $timeCall === 1 ? 0 : 1000;
		});
		Functions\when('sleep')->justReturn(null);

		$meta->shouldReceive('getJobId')->with(42)->andReturn(null);
		$meta->shouldReceive('setStatus')->with(42, 'processing')->once();
		$meta->shouldReceive('setJobId')->with(42, 'job-xyz')->once();

		$api->shouldReceive('upload')->once()->andReturn('tmp-abc');
		$api->shouldReceive('process')->once()->andReturn('job-xyz');
		// Loop exits immediately without entering body (1000 < 300 is false)

		$settings->shouldReceive('getPipelineSettings')->andReturn([]);
		$settings->shouldReceive('isSeoAltTextEnabled')->andReturn(false);

		$scheduler->shouldReceive('rescheduleAt')->once();

		$processor = new Processor($api, $meta, $replacer, $scheduler, $settings);
		$processor->run(42);
	}

	public function test_run_resumes_from_stored_job_id(): void
	{
		['api' => $api, 'meta' => $meta, 'replacer' => $replacer,
		 'scheduler' => $scheduler, 'settings' => $settings] = $this->makeMocks();

		Functions\expect('get_attached_file')->with(42)->andReturn('/uploads/photo.jpg');
		Functions\when('file_exists')->justReturn(true);
		Functions\when('filesize')->justReturn(100000);
		Functions\when('wp_tempnam')->justReturn('/tmp/glassypic_out.jpg');
		Functions\when('file_put_contents')->justReturn(1000);

		// Existing job_id — skip upload + process phases
		$meta->shouldReceive('getJobId')->with(42)->andReturn('job-existing');
		$meta->shouldReceive('setStatus')->with(42, 'processing')->once();
		$meta->shouldReceive('saveResults')->once();

		$api->shouldReceive('upload')->never();
		$api->shouldReceive('process')->never();
		$api->shouldReceive('pollJob')->once()->andReturn(
			['status' => 'completed', 'processed_size' => 32000]
		);
		$api->shouldReceive('downloadProcessedFile')->once()->andReturn('data');

		$replacer->shouldReceive('swap')->once();
		$settings->shouldReceive('isSeoAltTextEnabled')->andReturn(false);
		$settings->shouldReceive('isOptimizeThumbnails')->andReturn(false);

		$processor = new Processor($api, $meta, $replacer, $scheduler, $settings);
		$processor->run(42);
	}

	public function test_upload_retry_exhaustion_sets_failed(): void
	{
		['api' => $api, 'meta' => $meta, 'replacer' => $replacer,
		 'scheduler' => $scheduler, 'settings' => $settings] = $this->makeMocks();

		Functions\expect('get_attached_file')->with(42)->andReturn('/uploads/photo.jpg');
		Functions\when('file_exists')->justReturn(true);

		$meta->shouldReceive('getJobId')->with(42)->andReturn(null);
		$meta->shouldReceive('setStatus')->with(42, 'processing')->once();
		$meta->shouldReceive('setError')->with(42, \Mockery::type('string'))->once();

		// upload fails 3 times (MAX_RETRIES = 3), then re-throws
		$api->shouldReceive('upload')->times(3)->andThrow(new \RuntimeException('Connection refused'));

		$settings->shouldReceive('getPipelineSettings')->andReturn([]);
		$settings->shouldReceive('isSeoAltTextEnabled')->andReturn(false);

		$processor = new Processor($api, $meta, $replacer, $scheduler, $settings);
		$processor->run(42);
	}
}
