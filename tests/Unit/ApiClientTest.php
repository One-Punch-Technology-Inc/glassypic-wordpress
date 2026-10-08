<?php
// tests/Unit/ApiClientTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\ApiClient;
use GlassyPic\Exception\InsufficientCreditsException;

class ApiClientTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    private function makeClient(): ApiClient
    {
        return new ApiClient('tfy_live_testkey123', 'https://api.glassypic.com');
    }

    public function test_verify_key_returns_tier_and_credits(): void
    {
        Functions\expect('wp_safe_remote_get')
            ->once()
            ->andReturn(['response' => ['code' => 200], 'body' => '{"valid":true,"tier":"pro","credits_remaining":2847}']);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn('{"valid":true,"tier":"pro","credits_remaining":2847}');
        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = $this->makeClient();
        $result = $client->verifyKey();
        self::assertTrue($result['valid']);
        self::assertSame('pro', $result['tier']);
        self::assertSame(2847, $result['credits_remaining']);
    }

    public function test_upload_returns_temp_file_id(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'glassypic_test_');
        file_put_contents($tmpFile, 'fake image data');

        Functions\expect('wp_generate_password')->once()->andReturn('testboundary123');
        Functions\expect('wp_safe_remote_post')
            ->once()
            ->andReturn(['response' => ['code' => 200], 'body' => '{"temp_file_id":"tmp-abc123"}']);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn('{"temp_file_id":"tmp-abc123"}');
        Functions\expect('is_wp_error')->once()->andReturn(false);

        $client = $this->makeClient();
        $tempFileId = $client->upload($tmpFile);
        self::assertSame('tmp-abc123', $tempFileId);

        unlink($tmpFile);
    }

    private function stubPost(int $code, string $body, ?callable $assertArgs = null): void
    {
        Functions\expect('wp_json_encode')->once()->andReturnUsing(fn($data) => json_encode($data));
        $post = Functions\expect('wp_safe_remote_post')->once();
        if ($assertArgs) {
            $post->with('https://api.glassypic.com/auto', \Mockery::on($assertArgs));
        }
        $post->andReturn(['response' => ['code' => $code], 'body' => $body]);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn($code);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn($body);
    }

    public function test_process_nests_settings_and_returns_first_job_id(): void
    {
        // Shape of ProcessFilesRequest / ProcessFilesResponse in services/api/app/models/job.py
        $this->stubPost(
            200,
            '{"success":true,"jobs":[{"id":"job-xyz","temp_file_id":"tmp-abc","status":"queued"}],"credits_used":4,"credits_remaining":26}',
            function (array $args): bool {
                $payload = json_decode($args['body'], true);
                return $payload['temp_file_ids'] === ['tmp-abc']
                    && $payload['settings'] === ['output_format' => 'webp', 'output_seo_tag_gen' => true]
                    && ! array_key_exists('output_format', $payload);
            }
        );

        $jobId = $this->makeClient()->process('tmp-abc', ['output_format' => 'webp', 'output_seo_tag_gen' => true]);
        self::assertSame('job-xyz', $jobId);
    }

    public function test_process_throws_insufficient_credits_on_402(): void
    {
        $this->stubPost(402, '{"detail":"Insufficient credits. Need 4, have 0.","credits_reset_at":"2026-10-09T00:00:00Z"}');

        try {
            $this->makeClient()->process('tmp-abc', []);
            self::fail('Expected InsufficientCreditsException');
        } catch (InsufficientCreditsException $e) {
            self::assertSame('2026-10-09T00:00:00Z', $e->creditsResetAt);
        }
    }

    public function test_poll_job_unwraps_job_object(): void
    {
        // Shape of StatusResponse in services/api/app/models/job.py
        $body = '{"success":true,"job":{"id":"job-xyz","status":"completed","processed_size":32000,"seo_alt_text":"A cat"}}';
        Functions\expect('wp_safe_remote_get')->once()->andReturn(['response' => ['code' => 200], 'body' => $body]);
        Functions\expect('is_wp_error')->once()->andReturn(false);
        Functions\expect('wp_remote_retrieve_response_code')->once()->andReturn(200);
        Functions\expect('wp_remote_retrieve_body')->once()->andReturn($body);

        $job = $this->makeClient()->pollJob('job-xyz');
        self::assertSame('completed', $job['status']);
        self::assertSame('A cat', $job['seo_alt_text']);
    }

    public function test_throws_on_wp_error(): void
    {
        $wpError = \Mockery::mock('WP_Error');
        $wpError->shouldReceive('get_error_message')->andReturn('Connection refused');
        Functions\expect('wp_safe_remote_get')->once()->andReturn($wpError);
        Functions\expect('is_wp_error')->once()->with($wpError)->andReturn(true);
        Functions\expect('wp_remote_retrieve_body')->never();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP request failed/');

        $client = $this->makeClient();
        $client->verifyKey();
    }
}
