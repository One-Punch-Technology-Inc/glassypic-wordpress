<?php
// tests/Unit/MetaManagerTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\MetaManager;

class MetaManagerTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_get_status_returns_pending(): void
    {
        Functions\expect('get_post_meta')
            ->once()->with(42, '_glassypic_status', true)->andReturn('pending');

        $manager = new MetaManager();
        self::assertSame('pending', $manager->getStatus(42));
    }

    public function test_set_status_calls_update_post_meta(): void
    {
        Functions\expect('update_post_meta')
            ->once()->with(42, '_glassypic_status', 'processing');

        $manager = new MetaManager();
        $manager->setStatus(42, 'processing');
    }

    public function test_save_results_updates_all_fields(): void
    {
        // 5 result fields + 1 status update via setStatus() = 6 total
        Functions\expect('update_post_meta')->times(6);
        Functions\when('sanitize_text_field')->returnArg(1);

        $manager = new MetaManager();
        $manager->saveResults(42, [
            'original_size'  => 100000,
            'processed_size' => 32000,
            'savings_pct'    => 68.0,
            'optimized_at'   => '2026-01-01T00:00:00Z',
            'alt_text'       => 'A red apple on a white background',
        ]);
    }

    public function test_get_status_returns_empty_string_when_not_set(): void
    {
        Functions\expect('get_post_meta')
            ->once()->with(99, '_glassypic_status', true)->andReturn('');

        $manager = new MetaManager();
        self::assertSame('', $manager->getStatus(99));
    }

    public function test_increment_poll_resumes_counts_up(): void
    {
        Functions\expect('get_post_meta')
            ->once()->with(42, '_glassypic_poll_resumes', true)->andReturn('2');
        Functions\expect('update_post_meta')
            ->once()->with(42, '_glassypic_poll_resumes', 3);

        $manager = new MetaManager();
        self::assertSame(3, $manager->incrementPollResumes(42));
    }

    public function test_set_status_pending_clears_job_and_resume_counter(): void
    {
        Functions\expect('update_post_meta')->once()->with(42, '_glassypic_status', 'pending');
        Functions\expect('delete_post_meta')->once()->with(42, '_glassypic_job_id');
        Functions\expect('delete_post_meta')->once()->with(42, '_glassypic_poll_resumes');

        $manager = new MetaManager();
        $manager->setStatus(42, 'pending');
    }
}
