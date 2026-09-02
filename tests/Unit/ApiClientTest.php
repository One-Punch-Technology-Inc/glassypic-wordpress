<?php
// tests/Unit/ApiClientTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\ApiClient;

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
