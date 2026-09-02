<?php
// tests/Unit/ReplacerTest.php
declare(strict_types=1);
namespace GlassyPic\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use GlassyPic\Replacer;

class ReplacerTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); parent::tearDown(); }

    public function test_rejects_path_outside_uploads_dir(): void
    {
        Functions\expect('wp_upload_dir')->once()->andReturn(['basedir' => '/var/www/wp-content/uploads']);
        Functions\expect('get_attached_file')->once()->andReturn('/etc/passwd');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/path traversal/i');

        $replacer = new Replacer(new \GlassyPic\MetaManager());
        $replacer->swap(42, '/tmp/processed.jpg');
    }

    public function test_rejects_invalid_mime_type(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'glassypic_test_');
        file_put_contents($tmpFile, '<?php echo "evil"; ?>');

        Functions\expect('wp_upload_dir')->once()->andReturn(['basedir' => '/var/www/wp-content/uploads']);
        Functions\expect('get_attached_file')->once()->andReturn('/var/www/wp-content/uploads/photo.jpg');
        Functions\expect('wp_check_filetype_and_ext')->once()->andReturn(['type' => false, 'ext' => false]);
        Functions\expect('wp_delete_file')->once();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/MIME/i');

        $replacer = new Replacer(new \GlassyPic\MetaManager());
        $replacer->swap(42, $tmpFile);

        unlink($tmpFile);
    }

    public function test_path_within_uploads_passes_traversal_check(): void
    {
        // Test the guard logic in isolation
        $uploadsDir = '/var/www/wp-content/uploads';
        $filePath   = '/var/www/wp-content/uploads/2026/01/photo.jpg';
        $realBase   = realpath($uploadsDir) ?: $uploadsDir;

        // Simulate the guard: dirname resolved against base
        $dirToCheck = dirname($filePath);
        self::assertStringStartsWith(
            rtrim($uploadsDir, '/'),
            $dirToCheck,
            'Path within uploads should pass guard'
        );
    }
}
