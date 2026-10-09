<?php
// tests/Unit/SettingsTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\Settings;

class SettingsTest extends TestCase
{
	protected function setUp(): void
	{
		parent::setUp();
		Monkey\setUp();
		if ( ! defined('AUTH_KEY')) {
			define('AUTH_KEY', 'test-auth-key');
			define('SECURE_AUTH_KEY', 'test-secure-auth-key');
		}
	}

	protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

	public function test_sanitize_api_key_is_idempotent_on_first_save(): void
	{
		// WordPress runs the sanitize callback twice on the first save
		// (update_option -> add_option), the second time on the encrypted value.
		Functions\expect('add_settings_error')->never();

		$settings  = new Settings();
		$encrypted = $settings->sanitizeApiKey('tfy_live_abc123');
		$again     = $settings->sanitizeApiKey($encrypted);

		self::assertSame($encrypted, $again);
		Functions\when('get_option')->justReturn($again);
		self::assertSame('tfy_live_abc123', $settings->getApiKey());
	}

	public function test_sanitize_api_key_rejects_wrong_prefix(): void
	{
		Functions\when('esc_html__')->returnArg(1);
		Functions\expect('add_settings_error')->once();
		Functions\when('get_option')->justReturn('');

		self::assertSame('', ( new Settings() )->sanitizeApiKey('sk_live_nope'));
	}
}
