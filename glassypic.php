<?php
/**
 * Plugin Name:       GlassyPic — AI Image Optimization
 * Plugin URI:        https://glassypic.com
 * Description:       Automatically optimize images using the full GlassyPic pipeline: upscale, resize, compress, and AI-generated alt text.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.1
 * Author:            GlassyPic
 * Author URI:        https://glassypic.com
 * License:           GPL-2.0-only
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       glassypic
 *
 * == External Services ==
 * This plugin sends image data to api.glassypic.com for processing.
 * See https://glassypic.com/privacy for the privacy policy.
 */

declare(strict_types=1);

if ( ! defined('ABSPATH')) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

// Boot ActionScheduler
require_once __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

define('GLASSYPIC_FILE', __FILE__);

add_action('plugins_loaded', static function (): void {
	( new \GlassyPic\Plugin() )->init();
});
