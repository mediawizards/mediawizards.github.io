<?php
/**
 * Plugin Name: Denwix — Remove Duplicate jQuery Placeholder Script
 * Description: Removes a homepage-only script in the theme's functions.php that
 *              creates its own fake window.jQuery placeholder, redundant with (and
 *              less safe than) the mediaelementplayer stub already covering this.
 * Version:      1.0.0
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.2
 *
 * Install: copy to wp-content/mu-plugins/denwix-remove-duplicate-jquery-placeholder.php
 * Remove:  delete the file, or add define('DENWIX_DUPLICATE_JQUERY_FIX_OFF', true); to wp-config.php
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The theme's functions.php (line ~386) independently defines:
 *
 *   function absolute_first_js_override() {
 *       if ( is_front_page() || is_home() ) {
 *           ?><script>window.jQuery=window.jQuery||function(){return window.jQuery;};
 *           window.jQuery.fn=window.jQuery.fn||{};
 *           window.jQuery.fn.mediaelementplayer=function(){return this;};</script><?php
 *       }
 *   }
 *   add_action('wp_body_open', 'absolute_first_js_override', 1);
 *
 * This is the exact "assign a placeholder once, patch it, and stop" pattern
 * that turned out to be unsafe earlier in this site's troubleshooting: if
 * window.jQuery isn't loaded yet, this assigns a FAKE placeholder function
 * (`function(){return window.jQuery}`) in its place - not a real, empty jQuery
 * stand-in, just a self-referencing function - and never re-checks it once the
 * real jQuery library actually loads and replaces it. dslc-conditional-video-
 * assets.php's own stub (an Object.defineProperty trap on window.jQuery) already
 * covers this same problem correctly, including the case where a placeholder is
 * later replaced by the real library. This script is redundant with that one,
 * runs only on the homepage - the one page this site's carousel-related bugs
 * have all been observed on - and its fake placeholder function is capable of
 * confusing any other inline script that checks `if (window.jQuery)` and tries
 * to call it before the real library loads.
 *
 * ---------------------------------------------------------------------------
 * HONEST LIMITS
 * ---------------------------------------------------------------------------
 * This removes a confirmed-redundant, confirmed-unsafe-pattern script. It has
 * not been confirmed as the cause of the carousel/testimonial content-swap bug
 * still under investigation - that still needs a live test to verify either way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'DENWIX_DUPLICATE_JQUERY_FIX_OFF' ) && DENWIX_DUPLICATE_JQUERY_FIX_OFF ) {
	return;
}

add_action( 'init', 'denwix_remove_duplicate_jquery_placeholder', 999 );
function denwix_remove_duplicate_jquery_placeholder() {
	remove_action( 'wp_body_open', 'absolute_first_js_override', 1 );
}
