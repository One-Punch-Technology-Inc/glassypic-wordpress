=== GlassyPic — AI Image Optimization ===
Contributors: glassypic
Tags: image optimization, compress images, webp, avif, alt text
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPL-2.0-only
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI image optimizer: upscale with Real-ESRGAN, apply perceptual compression, convert to WebP/AVIF, and auto-generate SEO alt text — runs in the background.

== Description ==

**GlassyPic** is the only WordPress image optimization plugin that combines AI upscaling, smart compression, modern format conversion, and automatic SEO alt text generation in a single automated background workflow.

Every image you upload is automatically sent through the GlassyPic pipeline. Each stage runs only when it is needed:

1. **Upscale** — When your target size is more than 1.2× larger than the source, Real-ESRGAN AI upscaling runs first so the enlarged image stays sharp
2. **Resize** — Fit to your configured maximum dimensions with letterbox padding or center-crop (only when you set a maximum size)
3. **Compress** — Shrink the file with perceptual compression, which strips data the eye cannot resolve (lossless and lossy)
4. **Tag** — Generate descriptive, keyword-rich SEO alt text using AI and write it directly to WordPress media metadata (on by default; can be turned off)

Processing runs in the background using ActionScheduler — uploads complete instantly and optimization never slows your site.

= Key Features =

* **Auto-optimize on upload** — New images are queued the moment they are uploaded; no manual step required
* **Bulk optimize** — Process your entire existing Media Library from Media → Bulk Optimize with a real-time progress bar
* **WebP and AVIF output** — Serve next-generation image formats that cut file sizes by 30–70% vs. JPEG
* **AI-generated alt text** — Descriptive alt text is written to the standard WordPress alt text field, improving accessibility and image SEO rankings
* **Thumbnail compression** — Optionally compress every WordPress-generated thumbnail (3 credits per size; disabled by default)
* **Original file backup** — Your unoptimized original is preserved alongside the optimized file; restore it in one click from the Media Library
* **Credits auto-resume** — When API credits run out, all pending jobs pause and automatically resume 5 minutes after your plan resets — no intervention required
* **Encrypted API key storage** — Your API key is encrypted with AES-256-CBC before being stored in the database and is never echoed into HTML

= Who Is This For? =

* **Bloggers and content creators** who upload images regularly and want optimization on autopilot
* **WooCommerce store owners** with large product image libraries that need consistent sizing, compression, and alt text
* **SEO-focused sites** that need descriptive alt text on every image without manual effort
* **Performance-focused developers** targeting Core Web Vitals (LCP) improvements through smaller, modern-format images

= Output Formats =

Choose your target format once in Settings — the pipeline converts every image automatically:

* **Original** — Keep the existing format unchanged
* **WebP** — 96%+ browser support; 25–35% smaller than JPEG at equivalent quality
* **AVIF** — 90%+ browser support; up to 50% smaller than JPEG; best overall compression
* **JPEG** — Universal compatibility; best for photographs
* **PNG** — Universal compatibility; best for graphics and transparency

= How It Works =

GlassyPic sends your image to the GlassyPic cloud API, which runs the full pipeline and returns the optimized file. The plugin atomically replaces your original with the result, stores the original as a `.glassypic-orig` backup, and writes all metadata back to WordPress. Images are not stored permanently on GlassyPic servers after processing.

API calls are made to `api.glassypic.com`. See the [Privacy Policy](https://glassypic.com/privacy) and [Terms of Service](https://glassypic.com/terms).

= What Makes This Different From Other Image Optimization Plugins =

Most WordPress image optimizers are single-step compressors. GlassyPic runs a four-stage pipeline:

* AI upscaling (Real-ESRGAN) when an image is enlarged, so it comes out sharper — not just bigger
* AI-generated alt text written directly to WordPress metadata on every optimized image
* AVIF output support, the most bandwidth-efficient format available today
* Non-blocking background processing via ActionScheduler — the same job queue used by WooCommerce
* Original file backup with one-click restore, so you can always go back

== External Services ==

This plugin sends image data to **api.glassypic.com** for processing. Images are not stored permanently after processing. By using this plugin, you agree to the:

* [Terms of Service](https://glassypic.com/terms)
* [Privacy Policy](https://glassypic.com/privacy)

A GlassyPic account and API key (`gp_live_...`) are required.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/glassypic/` or install directly from the WordPress Plugin Directory
2. Activate the plugin through the **Plugins** menu in WordPress admin
3. Navigate to **Settings → GlassyPic**
4. Create a free account at [glassypic.com/register](https://glassypic.com/register)
5. In your GlassyPic dashboard, go to **API Keys → Generate New Key**, name it, and copy it
6. Paste the key (starts with `gp_live_`) into the API Key field and click **Save Settings**
7. New uploads are now optimized automatically — run **Media → Bulk Optimize** to process your existing library

== Frequently Asked Questions ==

= Does this plugin work automatically on new uploads? =

Yes. Auto-optimize is enabled by default. Every image uploaded to the WordPress Media Library is queued immediately. Processing runs in the background via ActionScheduler so your upload completes instantly and the admin stays responsive.

= Can I optimize images I've already uploaded? =

Yes. Go to **Media → Bulk Optimize**. The page shows how many images in your library are not yet optimized. Click the button to queue them all — a real-time progress bar updates every 3 seconds.

= What image formats does the plugin support? =

Input: JPEG, PNG, WebP, GIF, AVIF, SVG (SVG passes MIME validation but is not rasterized).
Output: your choice of Original (unchanged), WebP, AVIF, JPEG, or PNG — set once in Settings.

= Does GlassyPic generate alt text automatically? =

Yes. When **SEO Alt Text** is enabled (on by default), the AI pipeline writes a descriptive alt text string to the standard WordPress `_wp_attachment_image_alt` meta field. It appears in the Media Library editor and is read by all themes and plugins that use WordPress alt text.

= Will optimization overwrite my original images? =

The optimized file replaces the original in WordPress, but your original is always saved as a `<filename>.glassypic-orig` backup in the same directory. You can restore it at any time from the attachment edit screen or directly from the Media Library column — with a single click.

= How is this different from a compression plugin? =

A compression plugin does one step: it makes the file smaller. GlassyPic runs four — AI upscaling, configurable resizing, perceptual compression, and AI alt text — on every upload, so images land production-ready instead of merely lighter.

= How many credits does optimization use? =

With the default settings, each image uses 4 credits: 3 for compression and 1 for AI alt text. Setting a maximum size adds 1 credit. If the target size is more than 1.2× larger than the source, AI upscaling runs automatically and adds 2 credits (7 credits total). Turning off alt text saves 1 credit. Thumbnail compression uses 3 credits per thumbnail size (opt-in, disabled by default). See [glassypic.com/pricing](https://glassypic.com/pricing) for plan details and credit limits.

= What happens when my credits run out? =

All pending jobs are automatically paused and rescheduled to resume 5 minutes after your plan's credit reset date. A notice appears in the WordPress admin. No manual action is needed — jobs resume on their own.

= Is my API key stored securely? =

Yes. The API key is encrypted with AES-256-CBC using your WordPress `AUTH_KEY` and `SECURE_AUTH_KEY` as key material before it is stored in the database. The key is never output in plaintext in any HTML page.

= Does the plugin slow down my site for visitors? =

No. All processing is asynchronous via ActionScheduler. Visitors are never affected. Admin uploads complete in the normal time regardless of how large the optimization queue is.

= What are the server requirements? =

PHP 8.1 or higher and WordPress 6.0 or higher. The OpenSSL PHP extension is required for API key encryption (present by default on all major hosts).

= Can I enforce a standard image size across my library? =

Yes. In **Settings → Max Dimensions**, enter your target width and height in pixels. Choose **Resize Behavior**: Pad (letterbox, preserves aspect ratio with transparent fill) or Crop (center-crop to exact dimensions). Leave both fields blank to skip resizing.

= Does this work with Multisite? =

The plugin can be activated per-site in a Multisite network. Each site uses its own API key and settings. Network-wide activation is not tested.

== Changelog ==

= 1.0.0 =
* Initial release: four-stage AI pipeline (upscale, resize, compress, AI alt text), auto-optimize on upload, bulk optimizer, WebP/AVIF/JPEG/PNG output, configurable resize with pad/crop, original file backup and one-click restore, credits auto-pause and auto-resume, AES-256-CBC API key encryption
