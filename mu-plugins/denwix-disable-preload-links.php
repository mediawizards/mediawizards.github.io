<?php
/**
 * Plugin Name: Denwix — Force-Disable WP Rocket Preload Links
 * Description: Permanently disables WP Rocket's "Preload Links" (hover-prefetch)
 *              feature at the code level, so it can't quietly come back from a
 *              dashboard checkbox being re-checked, a cache regeneration, or a
 *              future WP Rocket update resetting the setting.
 * Version:      1.0.0
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.2
 *
 * Install: copy to wp-content/mu-plugins/denwix-disable-preload-links.php
 * Remove:  delete the file, or add define('DENWIX_DISABLE_PRELOAD_LINKS_OFF', true); to wp-config.php
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * Confirmed root cause, directly from this site's own console output:
 * hovering a menu that reveals several links at once (e.g. the "Services"
 * dropdown) makes WP Rocket's Preload Links feature fire a prefetch request
 * for every visible link almost simultaneously - about 13 requests within
 * the same moment. The server responds to that burst with 503 Service
 * Unavailable for most of them:
 *
 *   GET https://denwix.com/about/ net::ERR_ABORTED 503 (Service Unavailable)
 *   GET https://denwix.com/services-web-design/ net::ERR_ABORTED 503 ...
 *   (and ~11 more, same moment)
 *
 * Unchecking "Enable link preloading" under WP Rocket -> Preload stops NEW
 * page-cache generations from including the feature, but doesn't retroactively
 * touch pages WP Rocket already cached with it baked in, and nothing stops a
 * future WP Rocket update - or someone accidentally re-checking that box -
 * from silently bringing it back. This file makes the setting stick at the
 * code level, permanently, independent of the database value.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES
 * ---------------------------------------------------------------------------
 * 1. Forces WP Rocket's own `preload_links` setting off every time its
 *    settings are read, via `option_wp_rocket_settings` - the standard
 *    WordPress filter that fires on every get_option('wp_rocket_settings')
 *    call. This works regardless of what value is actually saved in the
 *    database, so a re-checked dashboard box has no effect.
 * 2. Directly dequeues/deregisters the script WP Rocket enqueues for this
 *    feature - handle `rocket-preload-links-js`, confirmed from this site's
 *    own rendered page source (printed as `rocket-preload-links-js-after`,
 *    WordPress's standard suffix for an inline script attached to that
 *    handle). This is a hard backstop: even if a future WP Rocket version
 *    renames or restructures its settings array, the actual script this
 *    feature depends on is still removed from the page.
 *
 * ---------------------------------------------------------------------------
 * HONEST LIMITS
 * ---------------------------------------------------------------------------
 * This does not purge WP Rocket's existing page cache. Any page WP Rocket
 * generated before this file was installed will keep serving its old,
 * already-cached copy (preload-links script included) until that cache
 * entry is cleared or naturally regenerated. Clear the WP Rocket cache once
 * after installing this for it to take effect immediately everywhere.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'DENWIX_DISABLE_PRELOAD_LINKS_OFF' ) && DENWIX_DISABLE_PRELOAD_LINKS_OFF ) {
	return;
}

add_filter( 'option_wp_rocket_settings', 'denwix_force_disable_preload_links' );
function denwix_force_disable_preload_links( $options ) {
	if ( is_array( $options ) ) {
		$options['preload_links'] = 0;
	}
	return $options;
}

add_action( 'wp_enqueue_scripts', 'denwix_dequeue_preload_links_script', 999 );
function denwix_dequeue_preload_links_script() {
	wp_dequeue_script( 'rocket-preload-links-js' );
	wp_deregister_script( 'rocket-preload-links-js' );
}
