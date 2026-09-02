<?php
// tests/Unit/SchedulerTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\Scheduler;
use GlassyPic\MetaManager;

class SchedulerTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_queue_on_upload_skips_if_status_completed(): void
    {
        $meta = \Mockery::mock(MetaManager::class);
        $meta->shouldReceive('getStatus')->with(42)->andReturn('completed');

        Functions\expect('as_schedule_single_action')->never();
        Functions\expect('get_option')->with('glassypic_auto_optimize', true)->andReturn(true);

        $scheduler = new Scheduler($meta);
        $result    = $scheduler->queueOnUpload(['width' => 800], 42);

        self::assertSame(['width' => 800], $result); // metadata passed through unchanged
    }

    public function test_queue_on_upload_skips_if_status_processing(): void
    {
        $meta = \Mockery::mock(MetaManager::class);
        $meta->shouldReceive('getStatus')->with(42)->andReturn('processing');

        Functions\expect('as_schedule_single_action')->never();
        Functions\expect('get_option')->with('glassypic_auto_optimize', true)->andReturn(true);

        $scheduler = new Scheduler($meta);
        $scheduler->queueOnUpload([], 42);
    }

    public function test_queue_on_upload_schedules_action_for_image(): void
    {
        $meta = \Mockery::mock(MetaManager::class);
        $meta->shouldReceive('getStatus')->with(42)->andReturn('');
        $meta->shouldReceive('setStatus')->with(42, 'pending')->once();

        Functions\expect('get_option')->with('glassypic_auto_optimize', true)->andReturn(true);
        Functions\expect('get_post_mime_type')->with(42)->andReturn('image/jpeg');
        Functions\expect('as_schedule_single_action')
            ->once()
            ->with(\Mockery::any(), 'glassypic/process_attachment', [42], 'glassypic');

        $scheduler = new Scheduler($meta);
        $scheduler->queueOnUpload([], 42);
    }

    public function test_queue_on_upload_skips_non_image(): void
    {
        $meta = \Mockery::mock(MetaManager::class);
        $meta->shouldReceive('getStatus')->with(42)->andReturn('');

        Functions\expect('get_option')->with('glassypic_auto_optimize', true)->andReturn(true);
        Functions\expect('get_post_mime_type')->with(42)->andReturn('application/pdf');
        Functions\expect('as_schedule_single_action')->never();

        $scheduler = new Scheduler($meta);
        $scheduler->queueOnUpload([], 42);
    }
}
