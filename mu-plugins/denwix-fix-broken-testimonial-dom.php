<?php
/**
 * Plugin Name: Denwix — Remove Broken Testimonial DOM-Flattening Filter
 * Description: Removes a the_content filter in the theme's functions.php that
 *              produces invalid, unbalanced HTML on every testimonial.
 * Version:      1.0.0
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.2
 *
 * Install: copy to wp-content/mu-plugins/denwix-fix-broken-testimonial-dom.php
 * Remove:  delete the file, or add define('DENWIX_TESTIMONIAL_DOM_FIX_OFF', true); to wp-config.php
 *
 * ---------------------------------------------------------------------------
 * WHY THIS EXISTS
 * ---------------------------------------------------------------------------
 * The theme's functions.php (not a separate mu-plugin - it was pasted directly
 * into the theme file) registers this on `the_content`:
 *
 *   function flatten_seowp_testimonial_dom($content) {
 *       $content = str_replace('<div class="dslc-testimonial-author-main">', '', $content);
 *       $content = str_replace('</div><!-- .dslc-testimonial-author-main -->', '', $content);
 *       $content = str_replace('<div class="dslc-testimonial-inner">', '', $content);
 *       $content = str_replace('</div><!-- .dslc-testimonial-main -->', '', $content);
 *       return $content;
 *   }
 *
 * Intent (per its own comment): strip two wrapper <div>s per testimonial to
 * reduce DOM depth. The bug: the third and fourth str_replace calls are not a
 * matching opening/closing pair. The opening tag removed is
 * `dslc-testimonial-inner`, but the closing-tag search string references a
 * *different* class in its HTML comment (`.dslc-testimonial-main`) - a string
 * that does not appear anywhere in Live Composer's actual output. Confirmed
 * directly against this site's own live page source: the opening tag is
 * reliably removed (zero occurrences of `dslc-testimonial-inner` remain), but
 * the closing-tag search string matches zero times, every time - meaning that
 * `</div>` is never removed. Every testimonial on every page is left with one
 * orphaned, unmatched closing tag - invalid, unbalanced HTML.
 *
 * A browser doesn't reject invalid HTML; it silently closes whatever ancestor
 * element happens to be open at that point in the parse tree instead, which
 * can differ from what the server intended - especially with several
 * carousel-style Live Composer modules stacked on the same page, each
 * expecting the DOM to match the structure it was actually built for. This is
 * a strong candidate for the "wrong section's content appearing inside
 * another section" behavior, and it's a confirmed markup defect regardless.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS DOES
 * ---------------------------------------------------------------------------
 * Removes that filter entirely, so testimonials render with Live Composer's
 * own, structurally valid markup. This gives up whatever small DOM-depth
 * reduction the filter was attempting - a minor, cosmetic optimization not
 * worth trading for broken HTML on every testimonial.
 *
 * ---------------------------------------------------------------------------
 * HONEST LIMITS
 * ---------------------------------------------------------------------------
 * This removes the broken filter; it does not by itself prove that filter
 * was the sole or actual cause of the carousel content-swap bug. It's the
 * strongest, most concretely-evidenced candidate found so far, and worth
 * testing on its own before assuming it's the complete fix.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'DENWIX_TESTIMONIAL_DOM_FIX_OFF' ) && DENWIX_TESTIMONIAL_DOM_FIX_OFF ) {
	return;
}

add_action( 'init', 'denwix_remove_broken_testimonial_dom_filter', 999 );
function denwix_remove_broken_testimonial_dom_filter() {
	remove_filter( 'the_content', 'flatten_seowp_testimonial_dom', 999 );
}
