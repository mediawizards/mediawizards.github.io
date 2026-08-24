<?php
/**
 * Plugin Name: DSLC Conditional Video Assets
 * Description: Fixes "jQuery(...).mediaelementplayer is not a function" thrown by
 *              Live Composer's client_frontend.min.js (window.dslc_bg_video), and keeps
 *              MediaElement.js / wp-mediaelement off pages that don't actually use a
 *              Live Composer background video, YouTube/Vimeo embed, or <video> tag.
 *
 * INSTALLATION
 * 1. Upload this file as-is to: wp-content/mu-plugins/dslc-conditional-video-assets.php
 *    (create the "mu-plugins" folder next to "plugins" if it doesn't exist yet).
 * 2. No activation needed - "mu" (must-use) plugins load automatically.
 * 3. Nothing to configure. It is safe to leave active permanently: pages that
 *    genuinely contain video will keep loading the video libraries as normal;
 *    every other page (the vast majority on a lightweight/brochure site) will not.
 *
 * WHY THE ERROR HAPPENS
 * Live Composer runs a background-video initializer (dslc_bg_video) on every single
 * page on document-ready, whether or not a background-video module is present. That
 * initializer calls jQuery(...).mediaelementplayer(). If nothing on the page needs
 * video, WordPress correctly never loads the MediaElement.js library - which is good
 * for performance - but Live Composer's script still calls a jQuery plugin method
 * that was never registered, throwing the TypeError you saw in the console. WP
 * Rocket's "Delay JavaScript Execution" ships its own compatibility patch for this
 * exact scenario, but it listens for DOMContentLoaded, which has usually already
 * fired by the time delayed scripts run - so the patch frequently arrives too late
 * and the error still surfaces.
 *
 * WHAT THIS FILE DOES
 * 1. Prints a tiny stub in <head> that defines jQuery.fn.mediaelementplayer as a
 *    harmless no-op the instant jQuery becomes available. On sites where jQuery
 *    itself is delay-loaded (e.g. WP Rocket "Delay JavaScript Execution" applied
 *    to jquery-core), a simple polling loop leaves a small timing gap that Live
 *    Composer's script can win, so this uses an Object.defineProperty trap on
 *    window.jQuery instead: the patch is applied synchronously the instant
 *    something assigns window.jQuery, with no polling interval and therefore no
 *    race window, before any script that runs after jQuery (like Live Composer's)
 *    gets a chance to execute. If a real video is later loaded on the page,
 *    MediaElement.js overwrites this no-op with its real implementation, so
 *    nothing is lost. A polling fallback is kept for the rare case where
 *    Object.defineProperty on window.jQuery isn't possible.
 * 2. Detects, server-side, whether the current page actually contains a video
 *    (Live Composer background-video module, a core <video>/[video] block, or a
 *    YouTube/Vimeo URL/embed) and dequeues every mediaelement-related script/style
 *    when it doesn't. This keeps those files (and any future update to Live
 *    Composer that starts loading them unconditionally) off pages with no video,
 *    protecting your PageSpeed Insights score.
 * 3. Skips the dequeue step for logged-in editors and for Live Composer's own
 *    builder/editing mode, so building a section with a video inside the page
 *    builder is never affected - only what anonymous visitors receive is trimmed.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Print an always-safe, always-early mediaelementplayer() stub.
 * Runs at the earliest wp_head priority so it lands before any deferred/delayed
 * script, regardless of what WP Rocket (or any other optimizer) does afterwards.
 */
add_action( 'wp_head', 'dslc_print_mediaelementplayer_stub', 1 );
function dslc_print_mediaelementplayer_stub() {
	if ( is_admin() ) {
		return;
	}
	?>
	<script id="dslc-mediaelement-stub">(function(w){function patch(j){if(j&&j.fn&&!j.fn.mediaelementplayer){j.fn.mediaelementplayer=function(){return this;};}}if(w.jQuery){patch(w.jQuery);return;}var cur;try{Object.defineProperty(w,'jQuery',{configurable:true,enumerable:true,get:function(){return cur;},set:function(v){cur=v;patch(v);}});}catch(e){var n=0,t=setInterval(function(){if(w.jQuery){patch(w.jQuery);clearInterval(t);}else if(++n>400){clearInterval(t);}},25);}})(window);</script>
	<?php
}

/**
 * Keep the stub script out of WP Rocket's "Delay JavaScript Execution" queue, and
 * (when it does get enqueued because a video is actually present) keep the real
 * mediaelement library out of that queue too, so it can't race Live Composer's
 * own front-end script.
 */
add_filter( 'rocket_delay_js_exclusions', 'dslc_rocket_delay_js_exclusions' );
function dslc_rocket_delay_js_exclusions( $exclusions ) {
	$exclusions[] = 'dslc-mediaelement-stub';
	$exclusions[] = 'mediaelement';
	return $exclusions;
}

/**
 * Dequeue MediaElement.js / wp-mediaelement (script + style) on any page that has
 * no detectable video, so they are only ever downloaded when actually needed.
 */
add_action( 'wp_enqueue_scripts', 'dslc_maybe_dequeue_mediaelement_assets', 999 );
function dslc_maybe_dequeue_mediaelement_assets() {
	if ( dslc_should_skip_video_optimization() ) {
		return;
	}

	if ( dslc_current_page_has_video() ) {
		return;
	}

	foreach ( wp_scripts()->queue as $handle ) {
		if ( false !== stripos( $handle, 'mediaelement' ) ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	foreach ( wp_styles()->queue as $handle ) {
		if ( false !== stripos( $handle, 'mediaelement' ) ) {
			wp_dequeue_style( $handle );
			wp_deregister_style( $handle );
		}
	}
}

/**
 * Bail out of the optimization for anyone who could be editing the page (so
 * building/previewing a Live Composer section with video is never affected),
 * and for Live Composer's own builder/editing requests.
 */
function dslc_should_skip_video_optimization() {
	if ( is_admin() ) {
		return true;
	}

	if ( isset( $_GET['dslc_active'] ) || isset( $_GET['dslc_edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
		return true;
	}

	if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
		return true;
	}

	return (bool) apply_filters( 'dslc_skip_video_asset_optimization', false );
}

/**
 * Detect whether the current request's post genuinely contains a video:
 * - a core <video>/[video]/[embed] block or raw <video> tag,
 * - a YouTube/Vimeo URL or embed,
 * - a Live Composer module setting that references a background/embedded video.
 *
 * Result is cached per post ID for the duration of the request.
 */
function dslc_current_page_has_video() {
	$post = get_queried_object();

	if ( ! ( $post instanceof WP_Post ) ) {
		return (bool) apply_filters( 'dslc_page_has_video', false, null );
	}

	static $cache = array();
	if ( isset( $cache[ $post->ID ] ) ) {
		return $cache[ $post->ID ];
	}

	$has_video = false;
	$content   = (string) $post->post_content;

	if ( '' !== $content ) {
		$content_patterns = array(
			'<video',
			'[video',
			'[embed',
			'wp-block-embed-youtube',
			'wp-block-embed-vimeo',
			'youtube.com',
			'youtu.be',
			'vimeo.com',
		);

		foreach ( $content_patterns as $pattern ) {
			if ( false !== stripos( $content, $pattern ) ) {
				$has_video = true;
				break;
			}
		}
	}

	if ( ! $has_video ) {
		global $wpdb;
		$meta_values = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
				$post->ID,
				$wpdb->esc_like( '_dslc' ) . '%'
			)
		);

		foreach ( $meta_values as $meta_value ) {
			if ( is_string( $meta_value ) && preg_match( '/bg[_-]?video|video[_-]?(mp4|ogv|webm|url)|youtube|vimeo/i', $meta_value ) ) {
				$has_video = true;
				break;
			}
		}
	}

	$has_video           = (bool) apply_filters( 'dslc_page_has_video', $has_video, $post );
	$cache[ $post->ID ] = $has_video;

	return $has_video;
}
