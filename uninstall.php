<?php
if ( ! defined('WP_UNINSTALL_PLUGIN')) {
	exit;
}

// Delete all plugin options
$options = [
	'glassypic_api_key',
	'glassypic_auto_optimize',
	'glassypic_seo_alt_text',
	'glassypic_optimize_thumbnails',
	'glassypic_pipeline_settings',
];
foreach ($options as $option) {
	delete_option($option);
}
delete_transient('glassypic_account_cache');
delete_transient('glassypic_credits_reset_at');
delete_transient('glassypic_bulk_status_cache');

// Delete .glassypic-orig backup files before removing the meta that points to them
global $wpdb;
$backup_paths = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
	"SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_glassypic_orig_backup'"
);
foreach ($backup_paths as $backup_path) {
	if ($backup_path && file_exists($backup_path)) {
		wp_delete_file($backup_path);
	}
}

// Delete all attachment post meta
$meta_keys = [
	'_glassypic_status',
	'_glassypic_job_id',
	'_glassypic_original_size',
	'_glassypic_processed_size',
	'_glassypic_savings_pct',
	'_glassypic_optimized_at',
	'_glassypic_error',
	'_glassypic_orig_backup',
];
foreach ($meta_keys as $key) {
	delete_post_meta_by_key($key);
}

// Cancel all pending ActionScheduler actions
if (function_exists('as_unschedule_all_actions')) {
	as_unschedule_all_actions('glassypic/process_attachment', [], 'glassypic');
}
