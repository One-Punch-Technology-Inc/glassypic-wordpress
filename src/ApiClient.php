<?php
declare(strict_types=1);
namespace GlassyPic;

class ApiClient
{
	public function __construct(
		private readonly string $apiKey,
		private readonly string $baseUrl = 'https://api.glassypic.com'
	) {}

	private function authHeaders(): array {
		return [
			'Authorization' => 'Bearer ' . $this->apiKey,
			'User-Agent'    => 'glassypic-wordpress/1.0.0',
		];
	}

	private function get( string $path ): array {
		$response = wp_safe_remote_get($this->baseUrl . $path, [
			'headers'   => $this->authHeaders(),
			'timeout'   => 30,
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			throw new \RuntimeException('HTTP request failed: ' . $response->get_error_message());
		}
		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		return [
			'code' => $code,
			'data' => json_decode($body, true),
		];
	}

	private function post( string $path, array $body = [], array $extraHeaders = [] ): array {
		$response = wp_safe_remote_post($this->baseUrl . $path, [
			'headers'   => array_merge($this->authHeaders(), $extraHeaders),
			'body'      => wp_json_encode($body),
			'timeout'   => 60,
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			throw new \RuntimeException('HTTP request failed: ' . $response->get_error_message());
		}
		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		return [
			'code' => $code,
			'data' => json_decode($body, true),
		];
	}

	public function verifyKey(): array {
		$result = $this->get('/api-keys/verify');
		if ($result['code'] !== 200) {
			throw new \RuntimeException('Invalid API key (HTTP ' . $result['code'] . ')');
		}
		return $result['data'];
	}

	public function upload( string $filePath ): string {
		$boundary    = wp_generate_password(24, false);
		$fileData    = file_get_contents($filePath); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$filename    = basename($filePath);
		$mime_result = mime_content_type($filePath);
		$mimeType    = false !== $mime_result ? $mime_result : 'application/octet-stream';

		$body = "--{$boundary}\r\n"
				. "Content-Disposition: form-data; name=\"file\"; filename=\"{$filename}\"\r\n"
				. "Content-Type: {$mimeType}\r\n\r\n"
				. $fileData . "\r\n"
				. "--{$boundary}--";

		$response = wp_safe_remote_post($this->baseUrl . '/upload', [
			'headers'   => array_merge($this->authHeaders(), [
				'Content-Type' => 'multipart/form-data; boundary=' . $boundary,
			]),
			'body'      => $body,
			'timeout'   => 60,
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			throw new \RuntimeException('HTTP request failed: ' . $response->get_error_message());
		}
		$code = wp_remote_retrieve_response_code($response);
		$data = json_decode(wp_remote_retrieve_body($response), true);
		if ($code !== 200 || empty($data['temp_file_id'])) {
			throw new \RuntimeException('Upload failed (HTTP ' . $code . ')');
		}
		return $data['temp_file_id'];
	}

	public function process( string $tempFileId, array $settings ): string {
		$payload = [
			'temp_file_ids' => [ $tempFileId ],
			'settings'      => $settings,
		];
		$result  = $this->post('/auto', $payload, [ 'Content-Type' => 'application/json' ]);
		// 402 when x402 pay-per-use is configured, 429 otherwise; both carry credits_reset_at
		if ($result['code'] === 402 || $result['code'] === 429) {
			throw new \GlassyPic\Exception\InsufficientCreditsException(
				$result['data']['detail'] ?? 'Insufficient credits',
				$result['data']['credits_reset_at'] ?? null
			);
		}
		if ($result['code'] !== 200 || empty($result['data']['jobs'][0]['id'])) {
			throw new \RuntimeException('Processing failed (HTTP ' . $result['code'] . ')');
		}
		return $result['data']['jobs'][0]['id'];
	}

	public function pollJob( string $jobId ): array {
		$result = $this->get('/status/' . $jobId);
		if ($result['code'] !== 200 || ! is_array($result['data']['job'] ?? null)) {
			throw new \RuntimeException('Poll failed (HTTP ' . $result['code'] . ')');
		}
		return $result['data']['job'];
	}

	public function downloadProcessedFile( string $jobId ): string {
		$response = wp_safe_remote_get($this->baseUrl . '/download/' . $jobId, [
			'headers'   => $this->authHeaders(),
			'timeout'   => 60,
			'sslverify' => true,
		]);
		if (is_wp_error($response)) {
			throw new \RuntimeException('Download failed: ' . $response->get_error_message());
		}
		$code = wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			throw new \RuntimeException('Download failed (HTTP ' . $code . ')');
		}
		return wp_remote_retrieve_body($response);
	}
}
