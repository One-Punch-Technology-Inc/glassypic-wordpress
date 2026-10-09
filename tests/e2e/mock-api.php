<?php
/**
 * Test-only mu-plugin: fakes api.glassypic.com for tests/e2e/run.sh.
 *
 * Response shapes mirror the backend (tinify-ai-landing services/api):
 *   /api-keys/verify  routes/api_keys.py verify_key()
 *   /upload           models/job.py TempFileResponse
 *   /auto             models/job.py ProcessFilesRequest / ProcessFilesResponse
 *   /status/{id}      models/job.py StatusResponse { success, job: JobResponse }
 *   402 / 429         services/x402_service.py, routes/_x402_helpers.py
 * When the backend changes one of these, update it here too — this file is the contract.
 *
 * Mode is set with the `mock_mode` option: ok | no_credits | expired | slow.
 */

add_filter('pre_http_request', function ( $pre, $args, $url ) {
	$parts = wp_parse_url($url);
	if (( $parts['host'] ?? '' ) !== 'api.glassypic.com') {
		return $pre;
	}
	$method = $args['method'] ?? 'GET';
	$path   = $parts['path'] ?? '/';
	$mode   = get_option('mock_mode', 'ok');
	$state  = get_option('mock_state', [ 'temps' => [], 'jobs' => [] ]);

	$log   = get_option('mock_log', []);
	$entry = [
		'method' => $method,
		'path'   => $path,
		'auth'   => $args['headers']['Authorization'] ?? null,
	];
	if ($path === '/auto') {
		$entry['body'] = json_decode($args['body'], true);
	}
	$log[] = $entry;
	update_option('mock_log', $log, false);

	$json = function ( int $code, array $data ) {
		return [
			'headers'  => [ 'content-type' => 'application/json' ],
			'body'     => wp_json_encode($data),
			'response' => [ 'code' => $code, 'message' => '' ],
			'cookies'  => [],
			'filename' => null,
		];
	};

	if ($method === 'GET' && $path === '/api-keys/verify') {
		// Backend accepts only gp_live_ keys (middleware/auth.py API_KEY_PREFIX)
		if ( ! str_starts_with($entry['auth'] ?? '', 'Bearer gp_live_')) {
			return $json(401, [ 'detail' => 'Authentication required' ]);
		}
		return $json(200, [ 'valid' => true, 'tier' => 'pro', 'credits_remaining' => 3300, 'credits_limit' => 3300 ]);
	}

	if ($method === 'POST' && $path === '/upload') {
		// Pull the file bytes out of the multipart body
		$body = $args['body'];
		$start = strpos($body, "\r\n\r\n") + 4;
		$end   = strrpos($body, "\r\n--");
		$bytes = substr($body, $start, $end - $start);
		$id    = wp_generate_uuid4();
		$file  = get_temp_dir() . 'mock-temp-' . $id;
		file_put_contents($file, $bytes);
		$state['temps'][ $id ] = $file;
		update_option('mock_state', $state, false);
		return $json(200, [
			'temp_file_id'      => $id,
			'original_filename' => 'x.jpg',
			'file_size'         => strlen($bytes),
			'mime_type'         => 'image/jpeg',
			'session_token'     => null,
		]);
	}

	if ($method === 'POST' && $path === '/auto') {
		$req = json_decode($args['body'], true);
		if ($mode === 'no_credits') {
			return $json(402, [
				'detail'            => 'Insufficient credits. Need 4, have 0.',
				'x402'              => [ 'credits_needed' => 4 ],
				'is_guest'          => false,
				'tier'              => 'free',
				'credits_remaining' => 0,
				'credits_limit'     => 30,
				'credits_reset_at'  => gmdate('c', time() + 3600),
			]);
		}
		$jobId = wp_generate_uuid4();
		$state['jobs'][ $jobId ] = [ 'temp' => $req['temp_file_ids'][0], 'settings' => $req['settings'] ?? [], 'polls' => 0 ];
		update_option('mock_state', $state, false);
		return $json(200, [
			'success'           => true,
			'jobs'              => [ [ 'id' => $jobId, 'temp_file_id' => $req['temp_file_ids'][0], 'status' => 'queued', 'auto_upscale' => false ] ],
			'credits_used'      => 4,
			'credits_remaining' => 3296,
		]);
	}

	if ($method === 'GET' && preg_match('#^/status/([0-9a-f-]+)$#', $path, $m)) {
		$job = $state['jobs'][ $m[1] ] ?? null;
		if ( ! $job) {
			return $json(404, [ 'detail' => 'Job not found' ]);
		}
		++$state['jobs'][ $m[1] ]['polls'];
		update_option('mock_state', $state, false);
		$status = 'completed';
		if ($mode === 'expired') {
			$status = 'expired';
		} elseif ($mode === 'slow' || $job['polls'] < 1) {
			$status = 'processing';
		}
		$tag = ! empty($job['settings']['output_seo_tag_gen']);
		return $json(200, [
			'success' => true,
			'job'     => [
				'id'                          => $m[1],
				'status'                      => $status,
				'processed_size'              => $status === 'completed' ? 12345 : null,
				'processed_compression_ratio' => $status === 'completed' ? 71.5 : null,
				'seo_alt_text'                => ( $status === 'completed' && $tag ) ? 'Gradient test card with GlassyPic label' : null,
				'error_message'               => null,
			],
		]);
	}

	if ($method === 'GET' && preg_match('#^/download/([0-9a-f-]+)$#', $path, $m)) {
		$job = $state['jobs'][ $m[1] ];
		$img = imagecreatefromstring(file_get_contents($state['temps'][ $job['temp'] ]));
		ob_start();
		imagejpeg($img, null, 30);
		$out = ob_get_clean();
		return [
			'headers'  => [ 'content-type' => 'image/jpeg' ],
			'body'     => $out,
			'response' => [ 'code' => 200, 'message' => 'OK' ],
			'cookies'  => [],
			'filename' => null,
		];
	}

	return $json(404, [ 'detail' => 'mock: no route ' . $method . ' ' . $path ]);
}, 10, 3);
