<?php
declare(strict_types=1);
namespace GlassyPic;

class Settings
{
	public function register(): void {
		register_setting('glassypic', 'glassypic_api_key', [ 'sanitize_callback' => [ $this, 'sanitizeApiKey' ] ]);
		register_setting('glassypic', 'glassypic_auto_optimize', [ 'sanitize_callback' => 'rest_sanitize_boolean' ]);
		register_setting('glassypic', 'glassypic_seo_alt_text', [ 'sanitize_callback' => 'rest_sanitize_boolean' ]);
		register_setting('glassypic', 'glassypic_optimize_thumbnails', [ 'sanitize_callback' => 'rest_sanitize_boolean' ]);
		register_setting('glassypic', 'glassypic_pipeline_settings', [ 'sanitize_callback' => [ $this, 'sanitizePipelineSettings' ] ]);

		add_settings_section('glassypic_main', '', '__return_empty_string', 'glassypic');
		add_settings_field('glassypic_api_key', esc_html__('API Key', 'glassypic'), [ $this, 'renderApiKeyField' ], 'glassypic', 'glassypic_main');
	}

	public function sanitizeApiKey( string $value ): string {
		$trimmed = trim($value);
		// Treat empty or masked placeholder as "no change" — keep existing encrypted value
		if ($trimmed === '' || str_contains($trimmed, '•')) {
			return get_option('glassypic_api_key', '');
		}
		// Already encrypted: on the first save update_option() falls through to add_option(),
		// which runs this callback a second time on the value we just encrypted.
		if (str_starts_with($this->decryptKey($trimmed), 'tfy_live_')) {
			return $trimmed;
		}
		if ( ! str_starts_with($trimmed, 'tfy_live_')) {
			add_settings_error('glassypic', 'invalid_key', esc_html__('API key must start with tfy_live_', 'glassypic'));
			return get_option('glassypic_api_key', '');
		}
		return $this->encryptKey($trimmed);
	}

	public function sanitizePipelineSettings( array $input ): array {
		$width  = absint($input['output_width'] ?? 0);
		$height = absint($input['output_height'] ?? 0);
		return [
			'output_format'          => in_array($input['output_format'] ?? '', [ 'original', 'jpg', 'png', 'webp', 'avif' ], true)
										? $input['output_format'] : 'original',
			'output_width'           => $width > 0 ? $width : null,
			'output_height'          => $height > 0 ? $height : null,
			'output_resize_behavior' => in_array($input['output_resize_behavior'] ?? '', [ 'pad', 'crop' ], true)
										? $input['output_resize_behavior'] : 'pad',
		];
	}

	public function getApiKey(): ?string {
		$encrypted = get_option('glassypic_api_key', '');
		if ($encrypted === '') {
			return null;
		}
		return $this->decryptKey($encrypted);
	}

	public function getPipelineSettings(): array {
		return get_option('glassypic_pipeline_settings', [
			'output_format'          => 'original',
			'output_width'           => null,
			'output_height'          => null,
			'output_resize_behavior' => 'pad',
		]);
	}

	public function isAutoOptimize(): bool {
		return (bool) get_option('glassypic_auto_optimize', true);
	}

	public function isSeoAltTextEnabled(): bool {
		return (bool) get_option('glassypic_seo_alt_text', true);
	}

	public function isOptimizeThumbnails(): bool {
		return (bool) get_option('glassypic_optimize_thumbnails', false);
	}

	public function renderPage(): void {
		$apiKey           = $this->getApiKey();
		$hasKey           = $apiKey !== null;
		$accountCache     = get_transient('glassypic_account_cache');
		$pipelineSettings = $this->getPipelineSettings();
		?>
		<div class="wrap">
			<h1><?php esc_html_e('GlassyPic Settings', 'glassypic'); ?></h1>

			<?php if ( ! $hasKey) : ?>
			<div class="notice notice-info">
				<h3><?php esc_html_e('Getting started with GlassyPic', 'glassypic'); ?></h3>
				<ol>
					<li>
					<?php
					/* translators: %s: URL to GlassyPic signup page */
					printf(esc_html__('Create a free account at %s', 'glassypic'), '<a href="https://glassypic.com/register" target="_blank">glassypic.com/register</a>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
					</li>
					<li><?php esc_html_e('Go to Dashboard → API Keys → Generate New Key', 'glassypic'); ?></li>
					<li><?php esc_html_e('Name it "My WordPress Site" and copy the key', 'glassypic'); ?></li>
					<li><?php esc_html_e('Paste it below and click Save Settings', 'glassypic'); ?></li>
				</ol>
				<p><strong><?php esc_html_e('Pricing:', 'glassypic'); ?></strong>
					<?php
					/* translators: %s: URL to GlassyPic pricing page */
					printf(esc_html__('The WordPress plugin uses your existing %s subscription.', 'glassypic'), '<a href="https://glassypic.com/pricing" target="_blank">GlassyPic</a>'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					?>
				</p>
			</div>
			<?php endif; ?>

			<?php if ($hasKey && $accountCache) : ?>
			<div class="notice notice-success inline">
				<p>
				<?php
				printf(
					/* translators: 1: account tier, 2: credits remaining count */
					esc_html__('Connected — %1$s plan · %2$s credits remaining', 'glassypic'),
					esc_html(ucfirst($accountCache['tier'])),
					esc_html(number_format($accountCache['credits_remaining']))
				);
				?>
				</p>
			</div>
			<?php endif; ?>

			<form method="post" action="options.php">
				<?php
				settings_fields('glassypic');
				do_settings_sections('glassypic');
				?>
				<table class="form-table" role="presentation">
					<tr>
						<th><?php esc_html_e('API Key', 'glassypic'); ?></th>
						<td>
							<input type="password" name="glassypic_api_key"
									value="<?php echo $hasKey ? 'tfy_live_••••••••••••' : ''; ?>"
									class="regular-text" autocomplete="off"
									placeholder="tfy_live_..." />
							<p class="description"><?php esc_html_e('Leave unchanged to keep your existing key.', 'glassypic'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Auto-optimize', 'glassypic'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="glassypic_auto_optimize" value="1"
									<?php checked($this->isAutoOptimize()); ?> />
								<?php esc_html_e('Optimize new image uploads automatically', 'glassypic'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('SEO Alt Text', 'glassypic'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="glassypic_seo_alt_text" value="1"
									<?php checked($this->isSeoAltTextEnabled()); ?> />
								<?php esc_html_e('Write AI-generated alt text to image metadata', 'glassypic'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Optimize Thumbnails', 'glassypic'); ?></th>
						<td>
							<label>
								<input type="checkbox" name="glassypic_optimize_thumbnails" value="1"
									<?php checked($this->isOptimizeThumbnails()); ?> />
								<?php esc_html_e('Also compress each thumbnail (~3 credits per size, default OFF)', 'glassypic'); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Output Format', 'glassypic'); ?></th>
						<td>
							<select name="glassypic_pipeline_settings[output_format]">
								<?php
								$formats = [
									'original' => esc_html__('Original format', 'glassypic'),
									'webp'     => 'WebP',
									'avif'     => 'AVIF',
									'jpg'      => 'JPEG',
									'png'      => 'PNG',
								];
								foreach ($formats as $fmt_val => $fmt_label) :
									?>
								<option value="<?php echo esc_attr($fmt_val); ?>"
									<?php selected($pipelineSettings['output_format'], $fmt_val); ?>>
									<?php echo esc_html($fmt_label); ?>
								</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Max Dimensions', 'glassypic'); ?></th>
						<td>
							<input type="number" name="glassypic_pipeline_settings[output_width]"
								value="<?php echo esc_attr($pipelineSettings['output_width'] ?? ''); ?>"
								min="0" max="10000" style="width:80px" placeholder="W" />
							&times;
							<input type="number" name="glassypic_pipeline_settings[output_height]"
								value="<?php echo esc_attr($pipelineSettings['output_height'] ?? ''); ?>"
								min="0" max="10000" style="width:80px" placeholder="H" />
							<p class="description"><?php esc_html_e('Leave blank for no resize.', 'glassypic'); ?></p>
						</td>
					</tr>
					<tr>
						<th><?php esc_html_e('Resize Behavior', 'glassypic'); ?></th>
						<td>
							<label>
								<input type="radio" name="glassypic_pipeline_settings[output_resize_behavior]"
									value="pad" <?php checked($pipelineSettings['output_resize_behavior'], 'pad'); ?> />
								<?php esc_html_e('Pad (letterbox)', 'glassypic'); ?>
							</label>
							&nbsp;&nbsp;
							<label>
								<input type="radio" name="glassypic_pipeline_settings[output_resize_behavior]"
									value="crop" <?php checked($pipelineSettings['output_resize_behavior'], 'crop'); ?> />
								<?php esc_html_e('Crop (center)', 'glassypic'); ?>
							</label>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	public function renderApiKeyField(): void {} // Handled in renderPage directly

	private function encryptKey( string $plaintext ): string {
		$keyMaterial = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true), 0, 32);
		$iv          = random_bytes(16);
		$encrypted   = openssl_encrypt($plaintext, 'AES-256-CBC', $keyMaterial, 0, $iv);
		return base64_encode($iv . '::' . $encrypted); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private function decryptKey( string $stored ): string {
		$decoded = base64_decode($stored); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$parts   = explode('::', $decoded, 2);
		if (count($parts) < 2 || strlen($parts[0]) !== 16) {
			return '';
		}
		[$iv, $data] = $parts;
		$keyMaterial = substr(hash('sha256', AUTH_KEY . SECURE_AUTH_KEY, true), 0, 32);
		$result      = openssl_decrypt($data, 'AES-256-CBC', $keyMaterial, 0, $iv);
		return false !== $result ? $result : '';
	}
}
