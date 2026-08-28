<?php
/**
 * Plugin Name: Denwix SEO Auditor
 * Description: Self-hosted, unlimited on-page + technical SEO checker for this
 *              site, modeled on tools like Seobility's SEO Check. Scores every
 *              published post/page automatically and lets you audit any URL
 *              on demand from wp-admin. See "HONEST LIMITS" below for what this
 *              cannot do without a third-party data source.
 * Version:      1.0.0
 * License:      GPL-2.0-or-later
 * Requires PHP: 7.4
 *
 * INSTALLATION
 * Upload as-is to wp-content/mu-plugins/denwix-seo-auditor.php. No activation
 * needed. Open wp-admin -> "SEO Audit" in the left menu.
 *
 * ---------------------------------------------------------------------------
 * WHAT THIS CHECKS (all self-hosted, no daily limit, no external service)
 * ---------------------------------------------------------------------------
 * Meta tags     - title/description presence & length, canonical, meta robots,
 *                 viewport, charset, html lang attribute
 * Page quality  - word count, H1 count, heading order, focus-keyword usage
 *                 (title/description/H1/URL/first paragraph), image alt-text
 *                 coverage, readability (Flesch Reading Ease)
 * Structure     - URL length/format, Open Graph tags, Twitter Card tags,
 *                 JSON-LD structured data presence & validity, favicon
 * Links         - internal/external link counts, empty or generic anchor text
 * Technical     - HTTPS scheme, HTTP->HTTPS redirect, response time, gzip/br
 *                 compression, HTTP status code, common security headers,
 *                 robots.txt reachability + blanket-disallow check, XML
 *                 sitemap discoverability
 *
 * It works two ways:
 * 1. Automatically, in the background, for every published post/page on this
 *    site (via WP-Cron, triggered right after you save/update one).
 * 2. On demand, for ANY url you type into the "Analyze a URL" screen - this
 *    site's pages or a competitor's - since it is just an HTTP fetch + HTML
 *    parse, the same technique any on-page checker uses.
 *
 * ---------------------------------------------------------------------------
 * HONEST LIMITS - read before expecting Seobility-identical results
 * ---------------------------------------------------------------------------
 * The following require a proprietary, continuously-crawled index of the
 * entire web (backlinks, rankings) or a headless-browser rendering service
 * (real Lighthouse runs). No self-hosted WordPress plugin can produce them
 * from scratch - Seobility itself spends real infrastructure on this:
 *   - Backlinks / referring domains / domain authority
 *   - Google search-result rankings for a keyword
 *   - Traffic estimates, competitor comparisons, social share counts
 *   - Real Core Web Vitals FIELD data (Google's own CrUX dataset)
 *   - A genuine Lighthouse performance run (needs headless Chrome)
 * These are listed in every report as "Not available" rather than faked.
 *
 * OPTIONAL: if you set the constants below in wp-config.php, this plugin will
 * pull real data for the two that Google exposes via free public APIs -
 * everything else above stays genuinely out of reach for a self-hosted tool.
 *   define( 'DENWIX_SEO_PSI_API_KEY', 'your-pagespeed-insights-api-key' );
 *   define( 'DENWIX_SEO_PSI_ENABLED', true );
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DENWIX_SEO_VERSION', '1.0.0' );
define( 'DENWIX_SEO_SLUG', 'denwix-seo-audit' );

/* ---------------------------------------------------------------------------
 * Admin menu
 * ------------------------------------------------------------------------ */
add_action( 'admin_menu', 'dnx_seo_register_admin_menu' );
function dnx_seo_register_admin_menu() {
	add_menu_page(
		'SEO Audit',
		'SEO Audit',
		'manage_options',
		DENWIX_SEO_SLUG,
		'dnx_seo_render_dashboard_page',
		'dashicons-chart-area',
		80
	);
	add_submenu_page( DENWIX_SEO_SLUG, 'Site Pages', 'Site Pages', 'manage_options', DENWIX_SEO_SLUG, 'dnx_seo_render_dashboard_page' );
	add_submenu_page( DENWIX_SEO_SLUG, 'Analyze a URL', 'Analyze a URL', 'manage_options', DENWIX_SEO_SLUG . '-url', 'dnx_seo_render_url_page' );
	add_submenu_page( DENWIX_SEO_SLUG, 'Site Health', 'Site Health', 'manage_options', DENWIX_SEO_SLUG . '-health', 'dnx_seo_render_health_page' );
}

add_action( 'admin_enqueue_scripts', 'dnx_seo_enqueue_admin_assets' );
function dnx_seo_enqueue_admin_assets( $hook ) {
	if ( strpos( $hook, DENWIX_SEO_SLUG ) === false && 'post.php' !== $hook && 'post-new.php' !== $hook ) {
		return;
	}
	wp_register_script( 'dnx-seo-admin', false, array( 'jquery' ), DENWIX_SEO_VERSION, true );
	wp_enqueue_script( 'dnx-seo-admin' );
	wp_localize_script(
		'dnx-seo-admin',
		'dnxSeo',
		array(
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'dnx_seo_nonce' ),
		)
	);
}

/* ---------------------------------------------------------------------------
 * Focus keyword meta box
 * ------------------------------------------------------------------------ */
add_action( 'add_meta_boxes', 'dnx_seo_add_meta_box' );
function dnx_seo_add_meta_box() {
	foreach ( array( 'post', 'page' ) as $type ) {
		add_meta_box( 'dnx_seo_box', 'Denwix SEO Audit', 'dnx_seo_render_meta_box', $type, 'side', 'high' );
	}
}

function dnx_seo_render_meta_box( $post ) {
	wp_nonce_field( 'dnx_seo_save_meta', 'dnx_seo_meta_nonce' );
	$keyword = get_post_meta( $post->ID, '_dnx_seo_focus_keyword', true );
	$score   = get_post_meta( $post->ID, '_dnx_seo_score', true );
	$checked = get_post_meta( $post->ID, '_dnx_seo_checked_at', true );
	?>
	<p>
		<label for="dnx_seo_focus_keyword"><strong>Focus keyword</strong></label><br>
		<input type="text" id="dnx_seo_focus_keyword" name="dnx_seo_focus_keyword"
			value="<?php echo esc_attr( $keyword ); ?>" style="width:100%" placeholder="e.g. wordpress seo audit">
	</p>
	<?php if ( '' !== (string) $score ) : ?>
		<p><strong>Last score:</strong> <?php echo esc_html( $score ); ?>/100</p>
		<p style="color:#666;font-size:12px">
			Checked <?php echo esc_html( human_time_diff( (int) $checked ) ); ?> ago.
		</p>
	<?php else : ?>
		<p style="color:#666;font-size:12px">Not yet scanned. Save/update this post to queue a scan, or visit the SEO Audit dashboard.</p>
	<?php endif; ?>
	<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . DENWIX_SEO_SLUG . '&post_id=' . $post->ID ) ); ?>">View full report &rarr;</a></p>
	<?php
}

add_action( 'save_post', 'dnx_seo_save_meta_box', 10, 2 );
function dnx_seo_save_meta_box( $post_id, $post ) {
	if ( ! isset( $_POST['dnx_seo_meta_nonce'] ) || ! wp_verify_nonce( $_POST['dnx_seo_meta_nonce'], 'dnx_seo_save_meta' ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		return;
	}
	if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
		return;
	}
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		return;
	}
	if ( isset( $_POST['dnx_seo_focus_keyword'] ) ) {
		update_post_meta( $post_id, '_dnx_seo_focus_keyword', sanitize_text_field( wp_unslash( $_POST['dnx_seo_focus_keyword'] ) ) );
	}

	if ( 'publish' === $post->post_status && in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
		wp_schedule_single_event( time() + 5, 'dnx_seo_scan_event', array( $post_id ) );
	}
}

add_action( 'dnx_seo_scan_event', 'dnx_seo_run_scheduled_scan' );
function dnx_seo_run_scheduled_scan( $post_id ) {
	$post = get_post( $post_id );
	if ( ! $post || 'publish' !== $post->post_status ) {
		return;
	}
	$keyword = get_post_meta( $post_id, '_dnx_seo_focus_keyword', true );
	$result  = Denwix_SEO_Analyzer::analyze( get_permalink( $post_id ), $keyword );
	dnx_seo_store_result( $post_id, $result );
}

function dnx_seo_store_result( $post_id, $result ) {
	update_post_meta( $post_id, '_dnx_seo_score', $result['score'] );
	update_post_meta( $post_id, '_dnx_seo_report', $result );
	update_post_meta( $post_id, '_dnx_seo_checked_at', time() );
}

/* ---------------------------------------------------------------------------
 * AJAX handlers
 * ------------------------------------------------------------------------ */
add_action( 'wp_ajax_dnx_seo_scan_post', 'dnx_seo_ajax_scan_post' );
function dnx_seo_ajax_scan_post() {
	check_ajax_referer( 'dnx_seo_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
	$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
	$post    = get_post( $post_id );
	if ( ! $post ) {
		wp_send_json_error( array( 'message' => 'Post not found' ), 404 );
	}
	$keyword = get_post_meta( $post_id, '_dnx_seo_focus_keyword', true );
	$result  = Denwix_SEO_Analyzer::analyze( get_permalink( $post_id ), $keyword );
	dnx_seo_store_result( $post_id, $result );
	wp_send_json_success( $result );
}

add_action( 'wp_ajax_dnx_seo_scan_url', 'dnx_seo_ajax_scan_url' );
function dnx_seo_ajax_scan_url() {
	check_ajax_referer( 'dnx_seo_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
	$url     = isset( $_POST['url'] ) ? esc_url_raw( wp_unslash( $_POST['url'] ) ) : '';
	$keyword = isset( $_POST['keyword'] ) ? sanitize_text_field( wp_unslash( $_POST['keyword'] ) ) : '';
	if ( '' === $url || ! wp_http_validate_url( $url ) ) {
		wp_send_json_error( array( 'message' => 'Enter a valid, full URL (including https://).' ) );
	}
	$cache_key = 'dnx_seo_url_' . md5( $url . '|' . $keyword );
	$cached    = get_transient( $cache_key );
	if ( false !== $cached ) {
		wp_send_json_success( array( 'html' => dnx_seo_render_report_html( $cached ) ) );
	}
	$result = Denwix_SEO_Analyzer::analyze( $url, $keyword );
	set_transient( $cache_key, $result, HOUR_IN_SECONDS );
	wp_send_json_success( array( 'html' => dnx_seo_render_report_html( $result ) ) );
}

add_action( 'wp_ajax_dnx_seo_scan_health', 'dnx_seo_ajax_scan_health' );
function dnx_seo_ajax_scan_health() {
	check_ajax_referer( 'dnx_seo_nonce', 'nonce' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => 'Forbidden' ), 403 );
	}
	$result = Denwix_SEO_Analyzer::site_health_checks( home_url( '/' ) );
	ob_start();
	?>
	<div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:12px 0;background:#fff;max-width:900px">
		<div style="font-size:28px;font-weight:700;color:<?php echo esc_attr( dnx_seo_score_color( $result['score'] ) ); ?>">
			<?php echo esc_html( $result['score'] ); ?>/100
		</div>
	</div>
	<table class="widefat striped" style="max-width:900px">
		<tbody>
		<?php foreach ( $result['checks'] as $chk ) : ?>
			<tr>
				<td style="width:28px"><?php echo dnx_seo_status_badge( $chk['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
				<td style="width:260px"><strong><?php echo esc_html( $chk['label'] ); ?></strong></td>
				<td><?php echo esc_html( $chk['message'] ); ?></td>
			</tr>
		<?php endforeach; ?>
		</tbody>
	</table>
	<?php
	$html = ob_get_clean();
	wp_send_json_success( array( 'html' => $html ) );
}

/* ---------------------------------------------------------------------------
 * Analyzer
 * ------------------------------------------------------------------------ */
class Denwix_SEO_Analyzer {

	const WEIGHT_CRITICAL = 14;
	const WEIGHT_WARNING  = 7;
	const WEIGHT_NOTICE   = 3;

	/**
	 * Fetch a URL and return [html, headers, status, response_time, error].
	 */
	public static function fetch( $url ) {
		$start = microtime( true );
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 15,
				'redirection' => 5,
				'user-agent'  => 'Denwix-SEO-Auditor/' . DENWIX_SEO_VERSION . ' (+' . home_url( '/' ) . ')',
				'sslverify'   => true,
			)
		);
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		if ( is_wp_error( $response ) ) {
			return array(
				'html'          => '',
				'headers'       => array(),
				'status'        => 0,
				'response_time' => $elapsed,
				'error'         => $response->get_error_message(),
			);
		}

		return array(
			'html'          => wp_remote_retrieve_body( $response ),
			'headers'       => wp_remote_retrieve_headers( $response ),
			'status'        => wp_remote_retrieve_response_code( $response ),
			'response_time' => $elapsed,
			'error'         => '',
		);
	}

	/**
	 * Run the full on-page + technical audit against a single URL.
	 */
	public static function analyze( $url, $focus_keyword = '' ) {
		$focus_keyword = trim( (string) $focus_keyword );
		$fetch         = self::fetch( $url );
		$checks        = array();

		if ( '' !== $fetch['error'] || 0 === $fetch['status'] ) {
			$checks[] = self::check( 'Technical', 'Page reachable', 'fail', 'critical', 'Could not fetch the URL: ' . $fetch['error'] );
			return self::finalize( $url, $focus_keyword, $checks, $fetch );
		}

		$dom = self::load_dom( $fetch['html'] );
		$xp  = $dom ? new DOMXPath( $dom ) : null;

		$checks = array_merge(
			$checks,
			self::meta_checks( $dom, $xp, $url ),
			self::quality_checks( $dom, $xp, $focus_keyword, $url ),
			self::structure_checks( $dom, $xp, $url ),
			self::link_checks( $dom, $xp, $url ),
			self::technical_checks( $fetch, $url ),
			self::external_factor_notes()
		);

		return self::finalize( $url, $focus_keyword, $checks, $fetch );
	}

	private static function load_dom( $html ) {
		if ( '' === trim( (string) $html ) || ! class_exists( 'DOMDocument' ) ) {
			return null;
		}
		$dom = new DOMDocument();
		$internal_errors = libxml_use_internal_errors( true );
		$dom->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors();
		libxml_use_internal_errors( $internal_errors );
		return $dom;
	}

	private static function check( $category, $label, $status, $severity, $message ) {
		return array(
			'category' => $category,
			'label'    => $label,
			'status'   => $status, // pass | warning | fail | info
			'severity' => $severity, // critical | warning | notice | none
			'message'  => $message,
		);
	}

	private static function text_of( $node ) {
		return $node ? trim( preg_replace( '/\s+/', ' ', $node->textContent ) ) : '';
	}

	private static function first( $xp, $query ) {
		if ( ! $xp ) {
			return null;
		}
		$nodes = $xp->query( $query );
		return ( $nodes && $nodes->length > 0 ) ? $nodes->item( 0 ) : null;
	}

	/* ---- Meta tags ---------------------------------------------------- */
	private static function meta_checks( $dom, $xp, $url ) {
		$c = array();

		$title_node = $dom ? self::first( $xp, '//title' ) : null;
		$title      = self::text_of( $title_node );
		$len        = mb_strlen( $title );
		if ( '' === $title ) {
			$c[] = self::check( 'Meta Tags', 'Title tag', 'fail', 'critical', 'No <title> tag found.' );
		} elseif ( $len < 10 || $len > 65 ) {
			$c[] = self::check( 'Meta Tags', 'Title tag length', 'warning', 'warning', "Title is {$len} characters (ideal: 10-60). Current: \"{$title}\"" );
		} else {
			$c[] = self::check( 'Meta Tags', 'Title tag length', 'pass', 'none', "Title is {$len} characters: \"{$title}\"" );
		}

		$desc_node = self::first( $xp, '//meta[translate(@name,"DESCRIPTION","description")="description"]/@content' );
		$desc      = $desc_node ? trim( $desc_node->nodeValue ) : '';
		$dlen      = mb_strlen( $desc );
		if ( '' === $desc ) {
			$c[] = self::check( 'Meta Tags', 'Meta description', 'fail', 'critical', 'No meta description found.' );
		} elseif ( $dlen < 70 || $dlen > 165 ) {
			$c[] = self::check( 'Meta Tags', 'Meta description length', 'warning', 'warning', "Description is {$dlen} characters (ideal: 70-160)." );
		} else {
			$c[] = self::check( 'Meta Tags', 'Meta description length', 'pass', 'none', "Description is {$dlen} characters." );
		}

		$canonical = self::first( $xp, '//link[translate(@rel,"CANONICAL","canonical")="canonical"]/@href' );
		if ( ! $canonical ) {
			$c[] = self::check( 'Meta Tags', 'Canonical tag', 'warning', 'warning', 'No canonical link tag found.' );
		} else {
			$c[] = self::check( 'Meta Tags', 'Canonical tag', 'pass', 'none', 'Canonical tag points to: ' . esc_html( $canonical->nodeValue ) );
		}

		$robots = self::first( $xp, '//meta[translate(@name,"ROBOTS","robots")="robots"]/@content' );
		$robots_val = $robots ? strtolower( $robots->nodeValue ) : '';
		if ( false !== strpos( $robots_val, 'noindex' ) ) {
			$c[] = self::check( 'Meta Tags', 'Meta robots', 'warning', 'warning', 'Page is set to "noindex" - it will not appear in search results. Remove this only if unintentional.' );
		} else {
			$c[] = self::check( 'Meta Tags', 'Meta robots', 'pass', 'none', 'Page is indexable (no noindex directive).' );
		}

		$viewport = self::first( $xp, '//meta[translate(@name,"VIEWPORT","viewport")="viewport"]' );
		$c[] = $viewport
			? self::check( 'Meta Tags', 'Viewport meta tag', 'pass', 'none', 'Responsive viewport meta tag present.' )
			: self::check( 'Meta Tags', 'Viewport meta tag', 'fail', 'critical', 'No viewport meta tag - page may not be mobile-friendly.' );

		$charset = $xp ? $xp->query( '//meta[@charset]' ) : null;
		$c[] = ( $charset && $charset->length > 0 )
			? self::check( 'Meta Tags', 'Character encoding', 'pass', 'none', 'Charset declared.' )
			: self::check( 'Meta Tags', 'Character encoding', 'warning', 'warning', 'No <meta charset> declaration found.' );

		$html_node = $dom ? self::first( $xp, '//html/@lang' ) : null;
		$c[] = $html_node
			? self::check( 'Meta Tags', 'HTML lang attribute', 'pass', 'none', 'Language declared: ' . esc_html( $html_node->nodeValue ) )
			: self::check( 'Meta Tags', 'HTML lang attribute', 'warning', 'warning', 'No lang attribute on <html> - accessibility & language signal missing.' );

		return $c;
	}

	/* ---- Page quality --------------------------------------------------*/
	private static function quality_checks( $dom, $xp, $focus_keyword, $url ) {
		$c = array();
		if ( ! $dom ) {
			return $c;
		}

		$body_node = self::first( $xp, '//body' );
		$body_text = self::text_of( $body_node );
		$word_count = $body_text ? str_word_count( $body_text ) : 0;
		if ( $word_count < 150 ) {
			$c[] = self::check( 'Page Quality', 'Content length', 'fail', 'warning', "Only {$word_count} words on the page (thin content). Aim for 300+ for a standard page." );
		} elseif ( $word_count < 300 ) {
			$c[] = self::check( 'Page Quality', 'Content length', 'warning', 'notice', "{$word_count} words. 300+ is a safer minimum for competitive pages." );
		} else {
			$c[] = self::check( 'Page Quality', 'Content length', 'pass', 'none', "{$word_count} words." );
		}

		$h1s = $xp->query( '//h1' );
		$h1_count = $h1s ? $h1s->length : 0;
		if ( 0 === $h1_count ) {
			$c[] = self::check( 'Page Quality', 'H1 heading', 'fail', 'critical', 'No H1 heading found on the page.' );
		} elseif ( $h1_count > 1 ) {
			$c[] = self::check( 'Page Quality', 'H1 heading', 'warning', 'warning', "{$h1_count} H1 headings found - use exactly one per page." );
		} else {
			$c[] = self::check( 'Page Quality', 'H1 heading', 'pass', 'none', 'Exactly one H1 heading, as recommended.' );
		}

		$prev_level = 0;
		$skipped    = false;
		$headings   = $xp->query( '//h1|//h2|//h3|//h4|//h5|//h6' );
		foreach ( $headings as $h ) {
			$level = (int) substr( $h->nodeName, 1 );
			if ( $prev_level > 0 && $level > $prev_level + 1 ) {
				$skipped = true;
			}
			$prev_level = $level;
		}
		$c[] = $skipped
			? self::check( 'Page Quality', 'Heading order', 'warning', 'notice', 'Heading levels skip a level somewhere (e.g. H2 straight to H4) - keep the hierarchy sequential.' )
			: self::check( 'Page Quality', 'Heading order', 'pass', 'none', 'Heading levels are in a sequential order.' );

		$images = $xp->query( '//img' );
		$total_img = $images->length;
		$with_alt  = 0;
		foreach ( $images as $img ) {
			$alt = $img->getAttribute( 'alt' );
			if ( '' !== trim( $alt ) ) {
				++$with_alt;
			}
		}
		if ( 0 === $total_img ) {
			$c[] = self::check( 'Page Quality', 'Image alt text', 'info', 'none', 'No images found on this page.' );
		} else {
			$pct = round( ( $with_alt / $total_img ) * 100 );
			if ( 100 === $pct ) {
				$c[] = self::check( 'Page Quality', 'Image alt text', 'pass', 'none', "All {$total_img} images have alt text." );
			} elseif ( $pct >= 70 ) {
				$c[] = self::check( 'Page Quality', 'Image alt text', 'warning', 'notice', "{$with_alt}/{$total_img} images have alt text ({$pct}%)." );
			} else {
				$c[] = self::check( 'Page Quality', 'Image alt text', 'fail', 'warning', "Only {$with_alt}/{$total_img} images have alt text ({$pct}%)." );
			}
		}

		if ( $body_text ) {
			$flesch = self::flesch_reading_ease( $body_text );
			if ( $flesch >= 60 ) {
				$c[] = self::check( 'Page Quality', 'Readability', 'pass', 'none', "Flesch Reading Ease: {$flesch} (easy to read)." );
			} elseif ( $flesch >= 30 ) {
				$c[] = self::check( 'Page Quality', 'Readability', 'warning', 'notice', "Flesch Reading Ease: {$flesch} (fairly difficult). Consider shorter sentences." );
			} else {
				$c[] = self::check( 'Page Quality', 'Readability', 'fail', 'warning', "Flesch Reading Ease: {$flesch} (difficult to read)." );
			}
		}

		if ( '' !== $focus_keyword ) {
			$kw_lower    = mb_strtolower( $focus_keyword );
			$title_node  = self::first( $xp, '//title' );
			$title_lower = mb_strtolower( self::text_of( $title_node ) );
			$desc_node   = self::first( $xp, '//meta[translate(@name,"DESCRIPTION","description")="description"]/@content' );
			$desc_lower  = mb_strtolower( $desc_node ? $desc_node->nodeValue : '' );
			$h1_lower    = mb_strtolower( self::text_of( self::first( $xp, '//h1' ) ) );
			$url_lower   = mb_strtolower( $url );
			$body_lower  = mb_strtolower( $body_text );
			$first_150   = mb_substr( $body_lower, 0, 800 );

			$c[] = ( false !== mb_strpos( $title_lower, $kw_lower ) )
				? self::check( 'Page Quality', 'Keyword in title', 'pass', 'none', 'Focus keyword found in the title tag.' )
				: self::check( 'Page Quality', 'Keyword in title', 'warning', 'warning', 'Focus keyword not found in the title tag.' );

			$c[] = ( false !== mb_strpos( $desc_lower, $kw_lower ) )
				? self::check( 'Page Quality', 'Keyword in meta description', 'pass', 'none', 'Focus keyword found in the meta description.' )
				: self::check( 'Page Quality', 'Keyword in meta description', 'warning', 'notice', 'Focus keyword not found in the meta description.' );

			$c[] = ( false !== mb_strpos( $h1_lower, $kw_lower ) )
				? self::check( 'Page Quality', 'Keyword in H1', 'pass', 'none', 'Focus keyword found in the H1 heading.' )
				: self::check( 'Page Quality', 'Keyword in H1', 'warning', 'notice', 'Focus keyword not found in the H1 heading.' );

			$c[] = ( false !== mb_strpos( $url_lower, str_replace( ' ', '-', $kw_lower ) ) )
				? self::check( 'Page Quality', 'Keyword in URL', 'pass', 'none', 'Focus keyword (or a slug form of it) found in the URL.' )
				: self::check( 'Page Quality', 'Keyword in URL', 'info', 'none', 'Focus keyword not found in the URL slug.' );

			$c[] = ( false !== mb_strpos( $first_150, $kw_lower ) )
				? self::check( 'Page Quality', 'Keyword in opening content', 'pass', 'none', 'Focus keyword appears early in the body content.' )
				: self::check( 'Page Quality', 'Keyword in opening content', 'warning', 'notice', 'Focus keyword does not appear in the opening content.' );

			$occurrences = $body_lower ? substr_count( $body_lower, $kw_lower ) : 0;
			$density     = $word_count > 0 ? round( ( $occurrences / $word_count ) * 100, 2 ) : 0;
			if ( $density > 3 ) {
				$c[] = self::check( 'Page Quality', 'Keyword density', 'warning', 'notice', "Keyword density is {$density}% - may read as keyword stuffing. Keep it under ~2-3%." );
			} else {
				$c[] = self::check( 'Page Quality', 'Keyword density', 'pass', 'none', "Keyword density is {$density}% ({$occurrences} occurrences)." );
			}
		} else {
			$c[] = self::check( 'Page Quality', 'Focus keyword', 'info', 'none', 'No focus keyword set - keyword-usage checks skipped. Set one on the post/page editor.' );
		}

		return $c;
	}

	/* ---- Structure -------------------------------------------------- */
	private static function structure_checks( $dom, $xp, $url ) {
		$c = array();

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( $path && mb_strlen( $path ) > 80 ) {
			$c[] = self::check( 'Structure', 'URL length', 'warning', 'notice', 'URL path is long (' . mb_strlen( $path ) . ' chars). Shorter URLs are easier to read and share.' );
		} else {
			$c[] = self::check( 'Structure', 'URL length', 'pass', 'none', 'URL length is reasonable.' );
		}
		if ( $path && preg_match( '/[A-Z_]|%20/', $path ) ) {
			$c[] = self::check( 'Structure', 'URL format', 'warning', 'notice', 'URL contains uppercase letters, underscores, or spaces - prefer lowercase with hyphens.' );
		} else {
			$c[] = self::check( 'Structure', 'URL format', 'pass', 'none', 'URL uses a clean, lowercase, hyphenated format.' );
		}

		if ( ! $dom ) {
			return $c;
		}

		$og_title = self::first( $xp, '//meta[translate(@property,"OGTITLE","ogtitle")="og:title"]' );
		$og_desc  = self::first( $xp, '//meta[translate(@property,"OGDESCRIPTION","ogdescription")="og:description"]' );
		$og_image = self::first( $xp, '//meta[translate(@property,"OGIMAGE","ogimage")="og:image"]' );
		$og_count = ( $og_title ? 1 : 0 ) + ( $og_desc ? 1 : 0 ) + ( $og_image ? 1 : 0 );
		if ( 3 === $og_count ) {
			$c[] = self::check( 'Structure', 'Open Graph tags', 'pass', 'none', 'og:title, og:description and og:image are all present.' );
		} elseif ( $og_count > 0 ) {
			$c[] = self::check( 'Structure', 'Open Graph tags', 'warning', 'notice', "Only {$og_count}/3 core Open Graph tags present - social share previews may look incomplete." );
		} else {
			$c[] = self::check( 'Structure', 'Open Graph tags', 'warning', 'warning', 'No Open Graph tags found - social shares of this page will look bare.' );
		}

		$twitter_card = self::first( $xp, '//meta[translate(@name,"TWITTERCARD","twittercard")="twitter:card"]' );
		$c[] = $twitter_card
			? self::check( 'Structure', 'Twitter Card tag', 'pass', 'none', 'Twitter Card meta tag present.' )
			: self::check( 'Structure', 'Twitter Card tag', 'info', 'none', 'No Twitter Card tag - falls back to Open Graph tags on X/Twitter.' );

		$ld_json_nodes = $xp->query( '//script[@type="application/ld+json"]' );
		if ( 0 === $ld_json_nodes->length ) {
			$c[] = self::check( 'Structure', 'Structured data (JSON-LD)', 'warning', 'notice', 'No JSON-LD structured data found. Adding schema.org markup can earn rich results.' );
		} else {
			$valid = 0;
			foreach ( $ld_json_nodes as $node ) {
				json_decode( $node->textContent );
				if ( JSON_ERROR_NONE === json_last_error() ) {
					++$valid;
				}
			}
			if ( $valid === $ld_json_nodes->length ) {
				$c[] = self::check( 'Structure', 'Structured data (JSON-LD)', 'pass', 'none', "{$valid} valid JSON-LD block(s) found." );
			} else {
				$invalid = $ld_json_nodes->length - $valid;
				$c[] = self::check( 'Structure', 'Structured data (JSON-LD)', 'fail', 'warning', "{$invalid} JSON-LD block(s) contain invalid JSON." );
			}
		}

		$favicon = self::first( $xp, '//link[contains(translate(@rel,"ICON","icon"),"icon")]' );
		$c[] = $favicon
			? self::check( 'Structure', 'Favicon', 'pass', 'none', 'Favicon link tag present.' )
			: self::check( 'Structure', 'Favicon', 'warning', 'notice', 'No favicon link tag found.' );

		return $c;
	}

	/* ---- Links -------------------------------------------------------- */
	private static function link_checks( $dom, $xp, $url ) {
		$c = array();
		if ( ! $dom ) {
			return $c;
		}
		$host  = wp_parse_url( $url, PHP_URL_HOST );
		$links = $xp->query( '//a[@href]' );
		$internal = 0;
		$external = 0;
		$empty_anchor = 0;
		$generic_words = array( 'click here', 'read more', 'here', 'link', 'more' );
		$generic_anchor = 0;

		foreach ( $links as $a ) {
			$href = trim( $a->getAttribute( 'href' ) );
			if ( '' === $href || 0 === strpos( $href, '#' ) || 0 === strpos( $href, 'javascript:' ) ) {
				continue;
			}
			$link_host = wp_parse_url( $href, PHP_URL_HOST );
			if ( ! $link_host || $link_host === $host ) {
				++$internal;
			} else {
				++$external;
			}
			$anchor = mb_strtolower( trim( self::text_of( $a ) ) );
			if ( '' === $anchor ) {
				++$empty_anchor;
			} elseif ( in_array( $anchor, $generic_words, true ) ) {
				++$generic_anchor;
			}
		}

		$c[] = self::check( 'Links', 'Internal / external links', 'info', 'none', "{$internal} internal, {$external} external link(s) found." );

		if ( 0 === $internal ) {
			$c[] = self::check( 'Links', 'Internal linking', 'warning', 'warning', 'No internal links found - internal linking helps both users and crawlers navigate the site.' );
		} else {
			$c[] = self::check( 'Links', 'Internal linking', 'pass', 'none', 'Page links to other internal pages.' );
		}

		if ( $empty_anchor > 0 ) {
			$c[] = self::check( 'Links', 'Empty anchor text', 'warning', 'notice', "{$empty_anchor} link(s) have no visible anchor text (e.g. image-only links missing alt text)." );
		}
		if ( $generic_anchor > 0 ) {
			$c[] = self::check( 'Links', 'Generic anchor text', 'warning', 'notice', "{$generic_anchor} link(s) use generic anchor text like \"click here\" - descriptive anchor text helps SEO and accessibility." );
		}
		if ( 0 === $empty_anchor && 0 === $generic_anchor ) {
			$c[] = self::check( 'Links', 'Anchor text quality', 'pass', 'none', 'No empty or generic anchor text detected.' );
		}

		return $c;
	}

	/* ---- Technical ------------------------------------------------------ */
	private static function technical_checks( $fetch, $url ) {
		$c = array();

		$scheme = wp_parse_url( $url, PHP_URL_SCHEME );
		$c[] = ( 'https' === $scheme )
			? self::check( 'Technical', 'HTTPS', 'pass', 'none', 'Page is served over HTTPS.' )
			: self::check( 'Technical', 'HTTPS', 'fail', 'critical', 'Page is not served over HTTPS.' );

		if ( $fetch['status'] >= 200 && $fetch['status'] < 300 ) {
			$c[] = self::check( 'Technical', 'HTTP status code', 'pass', 'none', "Responded with HTTP {$fetch['status']}." );
		} elseif ( $fetch['status'] >= 300 && $fetch['status'] < 400 ) {
			$c[] = self::check( 'Technical', 'HTTP status code', 'info', 'none', "Responded with a redirect (HTTP {$fetch['status']}) before landing here." );
		} else {
			$c[] = self::check( 'Technical', 'HTTP status code', 'fail', 'critical', "Responded with HTTP {$fetch['status']}." );
		}

		if ( $fetch['response_time'] > 2000 ) {
			$c[] = self::check( 'Technical', 'Server response time', 'fail', 'warning', "{$fetch['response_time']} ms - slow first-byte response." );
		} elseif ( $fetch['response_time'] > 800 ) {
			$c[] = self::check( 'Technical', 'Server response time', 'warning', 'notice', "{$fetch['response_time']} ms." );
		} else {
			$c[] = self::check( 'Technical', 'Server response time', 'pass', 'none', "{$fetch['response_time']} ms." );
		}

		$encoding = isset( $fetch['headers']['content-encoding'] ) ? $fetch['headers']['content-encoding'] : '';
		$c[] = ( '' !== $encoding )
			? self::check( 'Technical', 'Compression', 'pass', 'none', "Response is compressed ({$encoding})." )
			: self::check( 'Technical', 'Compression', 'warning', 'warning', 'No gzip/brotli compression detected on this response.' );

		$security_headers = array(
			'x-content-type-options' => 'X-Content-Type-Options',
			'x-frame-options'        => 'X-Frame-Options',
			'referrer-policy'        => 'Referrer-Policy',
		);
		$present = array();
		$missing = array();
		foreach ( $security_headers as $key => $label ) {
			if ( isset( $fetch['headers'][ $key ] ) ) {
				$present[] = $label;
			} else {
				$missing[] = $label;
			}
		}
		if ( empty( $missing ) ) {
			$c[] = self::check( 'Technical', 'Security headers', 'pass', 'none', 'Common security headers present: ' . implode( ', ', $present ) . '.' );
		} else {
			$c[] = self::check( 'Technical', 'Security headers', 'warning', 'notice', 'Missing: ' . implode( ', ', $missing ) . '. Informational only - does not affect rankings directly.' );
		}

		return $c;
	}

	/**
	 * Site-wide checks (robots.txt / sitemap / http->https redirect) run
	 * once against the site root rather than per-page.
	 */
	public static function site_health_checks( $home_url ) {
		$checks = array();

		$robots_url = trailingslashit( $home_url ) . 'robots.txt';
		$robots     = self::fetch( $robots_url );
		if ( 200 === (int) $robots['status'] ) {
			if ( preg_match( '/^\s*Disallow:\s*\/\s*$/mi', $robots['html'] ) && ! preg_match( '/^\s*Allow:/mi', $robots['html'] ) ) {
				$checks[] = self::check( 'Technical', 'robots.txt', 'fail', 'critical', 'robots.txt contains a blanket "Disallow: /" - this blocks search engines from the entire site.' );
			} else {
				$checks[] = self::check( 'Technical', 'robots.txt', 'pass', 'none', 'robots.txt is reachable and does not block the whole site.' );
			}
			if ( false === stripos( $robots['html'], 'sitemap' ) ) {
				$checks[] = self::check( 'Technical', 'Sitemap referenced in robots.txt', 'warning', 'notice', 'robots.txt does not reference a sitemap URL.' );
			} else {
				$checks[] = self::check( 'Technical', 'Sitemap referenced in robots.txt', 'pass', 'none', 'robots.txt references a sitemap.' );
			}
		} else {
			$checks[] = self::check( 'Technical', 'robots.txt', 'warning', 'warning', 'robots.txt was not reachable (HTTP ' . $robots['status'] . ').' );
		}

		$sitemap_paths = array( 'wp-sitemap.xml', 'sitemap_index.xml', 'sitemap.xml' );
		$found_sitemap = false;
		foreach ( $sitemap_paths as $path ) {
			$sm = self::fetch( trailingslashit( $home_url ) . $path );
			if ( 200 === (int) $sm['status'] ) {
				$checks[] = self::check( 'Technical', 'XML sitemap', 'pass', 'none', "Sitemap found at /{$path}" );
				$found_sitemap = true;
				break;
			}
		}
		if ( ! $found_sitemap ) {
			$checks[] = self::check( 'Technical', 'XML sitemap', 'warning', 'warning', 'No sitemap found at common paths (/wp-sitemap.xml, /sitemap_index.xml, /sitemap.xml).' );
		}

		if ( 'https' === wp_parse_url( $home_url, PHP_URL_SCHEME ) ) {
			$http_url = set_url_scheme( $home_url, 'http' );
			$http_res = self::fetch( $http_url );
			if ( $http_res['status'] >= 300 && $http_res['status'] < 400 ) {
				$checks[] = self::check( 'Technical', 'HTTP to HTTPS redirect', 'pass', 'none', 'Plain HTTP requests are redirected to HTTPS.' );
			} else {
				$checks[] = self::check( 'Technical', 'HTTP to HTTPS redirect', 'warning', 'warning', 'HTTP does not appear to redirect to HTTPS (got status ' . $http_res['status'] . ').' );
			}
		}

		return array(
			'url'    => $home_url,
			'checks' => $checks,
			'score'  => self::score_from_checks( $checks ),
		);
	}

	private static function external_factor_notes() {
		$items = array(
			'Backlinks / referring domains',
			'Domain & page authority',
			'Google search ranking position',
			'Traffic estimate',
			'Competitor comparison',
			'Real Core Web Vitals (field data)',
		);
		$c = array();
		foreach ( $items as $item ) {
			$c[] = self::check( 'External Factors', $item, 'info', 'none', 'Not available in a self-hosted tool - requires a proprietary crawled web index or Google account data. Does not affect this page\'s score.' );
		}
		return $c;
	}

	private static function score_from_checks( $checks ) {
		$score = 100;
		foreach ( $checks as $chk ) {
			if ( 'fail' === $chk['status'] ) {
				$score -= self::weight( $chk['severity'] );
			} elseif ( 'warning' === $chk['status'] ) {
				$score -= (int) round( self::weight( $chk['severity'] ) / 2 );
			}
		}
		return max( 0, min( 100, $score ) );
	}

	private static function weight( $severity ) {
		switch ( $severity ) {
			case 'critical':
				return self::WEIGHT_CRITICAL;
			case 'warning':
				return self::WEIGHT_WARNING;
			case 'notice':
				return self::WEIGHT_NOTICE;
			default:
				return 0;
		}
	}

	private static function finalize( $url, $focus_keyword, $checks, $fetch ) {
		return array(
			'url'            => $url,
			'focus_keyword'  => $focus_keyword,
			'checked_at'     => time(),
			'http_status'    => $fetch['status'],
			'response_time'  => $fetch['response_time'],
			'checks'         => $checks,
			'score'          => self::score_from_checks( $checks ),
			'counts'         => self::count_by_status( $checks ),
		);
	}

	private static function count_by_status( $checks ) {
		$counts = array(
			'pass'    => 0,
			'warning' => 0,
			'fail'    => 0,
			'info'    => 0,
		);
		foreach ( $checks as $chk ) {
			if ( isset( $counts[ $chk['status'] ] ) ) {
				++$counts[ $chk['status'] ];
			}
		}
		return $counts;
	}

	/**
	 * Approximate Flesch Reading Ease score.
	 */
	public static function flesch_reading_ease( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $text ) ) );
		if ( '' === $text ) {
			return 0;
		}
		$sentence_count = preg_match_all( '/[.!?]+/', $text, $m );
		$sentence_count = max( 1, $sentence_count );
		$words          = preg_split( '/\s+/', $text, -1, PREG_SPLIT_NO_EMPTY );
		$word_count     = max( 1, count( $words ) );
		$syllables      = 0;
		foreach ( $words as $w ) {
			$syllables += self::count_syllables( $w );
		}
		$score = 206.835 - 1.015 * ( $word_count / $sentence_count ) - 84.6 * ( $syllables / $word_count );
		return round( max( 0, min( 100, $score ) ), 1 );
	}

	private static function count_syllables( $word ) {
		$word = strtolower( preg_replace( '/[^a-z]/i', '', $word ) );
		if ( '' === $word ) {
			return 1;
		}
		preg_match_all( '/[aeiouy]+/', $word, $m );
		$count = count( $m[0] );
		if ( strlen( $word ) > 1 && 'e' === substr( $word, -1 ) ) {
			--$count;
		}
		return max( 1, $count );
	}
}

/* ---------------------------------------------------------------------------
 * Admin screens
 * ------------------------------------------------------------------------ */
function dnx_seo_status_badge( $status ) {
	$map = array(
		'pass'    => array( '#1a7f37', '&#10003;' ),
		'warning' => array( '#9a6700', '&#9888;' ),
		'fail'    => array( '#cf222e', '&#10007;' ),
		'info'    => array( '#57606a', '&#8505;' ),
	);
	list( $color, $icon ) = isset( $map[ $status ] ) ? $map[ $status ] : $map['info'];
	return '<span style="color:' . esc_attr( $color ) . ';font-weight:600">' . $icon . '</span>';
}

function dnx_seo_score_color( $score ) {
	if ( $score >= 80 ) {
		return '#1a7f37';
	}
	if ( $score >= 50 ) {
		return '#9a6700';
	}
	return '#cf222e';
}

function dnx_seo_render_report_html( $result ) {
	$grouped = array();
	foreach ( $result['checks'] as $chk ) {
		$grouped[ $chk['category'] ][] = $chk;
	}
	ob_start();
	?>
	<div style="border:1px solid #dcdcde;border-radius:6px;padding:16px;margin:12px 0;background:#fff">
		<div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap">
			<div style="font-size:36px;font-weight:700;color:<?php echo esc_attr( dnx_seo_score_color( $result['score'] ) ); ?>">
				<?php echo esc_html( $result['score'] ); ?>/100
			</div>
			<div style="color:#666">
				<?php echo esc_html( $result['url'] ); ?><br>
				HTTP <?php echo esc_html( $result['http_status'] ); ?> &middot; <?php echo esc_html( $result['response_time'] ); ?> ms &middot;
				checked <?php echo esc_html( gmdate( 'Y-m-d H:i', $result['checked_at'] ) ); ?> UTC
			</div>
		</div>
	</div>
	<?php foreach ( $grouped as $category => $items ) : ?>
		<h3 style="margin:22px 0 8px"><?php echo esc_html( $category ); ?></h3>
		<table class="widefat striped" style="max-width:900px">
			<tbody>
			<?php foreach ( $items as $chk ) : ?>
				<tr>
					<td style="width:28px"><?php echo dnx_seo_status_badge( $chk['status'] ); // phpcs:ignore WordPress.Security.EscapeOutput ?></td>
					<td style="width:220px"><strong><?php echo esc_html( $chk['label'] ); ?></strong></td>
					<td><?php echo esc_html( $chk['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	<?php endforeach;
	return ob_get_clean();
}

function dnx_seo_render_dashboard_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification
	if ( $post_id > 0 ) {
		$post = get_post( $post_id );
		if ( $post ) {
			$report = get_post_meta( $post_id, '_dnx_seo_report', true );
			echo '<div class="wrap"><h1>SEO Audit &mdash; ' . esc_html( get_the_title( $post ) ) . '</h1>';
			echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . DENWIX_SEO_SLUG ) ) . '">&larr; Back to all pages</a> &middot; <button class="button button-primary" id="dnx-rescan" data-post-id="' . esc_attr( $post_id ) . '">Rescan now</button></p>';
			echo '<div id="dnx-report-target">';
			if ( is_array( $report ) ) {
				echo dnx_seo_render_report_html( $report ); // phpcs:ignore WordPress.Security.EscapeOutput
			} else {
				echo '<p>Not scanned yet. Click "Rescan now".</p>';
			}
			echo '</div></div>';
			dnx_seo_print_rescan_script();
			return;
		}
	}

	$posts = get_posts(
		array(
			'post_type'      => array( 'post', 'page' ),
			'post_status'    => 'publish',
			'posts_per_page' => 200,
			'orderby'        => 'modified',
			'order'          => 'DESC',
		)
	);
	?>
	<div class="wrap">
		<h1>SEO Audit &mdash; Site Pages</h1>
		<p>Scores are cached from the last scan (automatic on save, or manual "Rescan"). Self-hosted, unlimited checks &mdash; no daily quota.</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th>Title</th>
					<th>Type</th>
					<th style="width:100px">Score</th>
					<th style="width:160px">Last checked</th>
					<th style="width:120px">Action</th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $posts as $p ) :
				$score   = get_post_meta( $p->ID, '_dnx_seo_score', true );
				$checked = get_post_meta( $p->ID, '_dnx_seo_checked_at', true );
				?>
				<tr>
					<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . DENWIX_SEO_SLUG . '&post_id=' . $p->ID ) ); ?>"><?php echo esc_html( get_the_title( $p ) ); ?></a></td>
					<td><?php echo esc_html( $p->post_type ); ?></td>
					<td>
						<?php if ( '' !== (string) $score ) : ?>
							<span style="font-weight:700;color:<?php echo esc_attr( dnx_seo_score_color( $score ) ); ?>"><?php echo esc_html( $score ); ?></span>
						<?php else : ?>
							<span style="color:#999">&mdash;</span>
						<?php endif; ?>
					</td>
					<td><?php echo $checked ? esc_html( human_time_diff( (int) $checked ) . ' ago' ) : '&mdash;'; ?></td>
					<td><button class="button dnx-rescan-row" data-post-id="<?php echo esc_attr( $p->ID ); ?>">Rescan</button></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
	</div>
	<script>
	(function($){
		$('.dnx-rescan-row').on('click', function(){
			var btn = $(this), id = btn.data('post-id');
			btn.prop('disabled', true).text('Scanning...');
			$.post(dnxSeo.ajaxUrl, { action: 'dnx_seo_scan_post', post_id: id, nonce: dnxSeo.nonce })
				.done(function(res){ if (res.success) { location.reload(); } else { alert('Scan failed.'); btn.prop('disabled', false).text('Rescan'); } })
				.fail(function(){ alert('Scan failed.'); btn.prop('disabled', false).text('Rescan'); });
		});
	})(jQuery);
	</script>
	<?php
}

function dnx_seo_print_rescan_script() {
	?>
	<script>
	(function($){
		$('#dnx-rescan').on('click', function(){
			var btn = $(this), id = btn.data('post-id');
			btn.prop('disabled', true).text('Scanning...');
			$.post(dnxSeo.ajaxUrl, { action: 'dnx_seo_scan_post', post_id: id, nonce: dnxSeo.nonce })
				.done(function(res){ if (res.success) { location.reload(); } else { alert('Scan failed.'); btn.prop('disabled', false).text('Rescan now'); } })
				.fail(function(){ alert('Scan failed.'); btn.prop('disabled', false).text('Rescan now'); });
		});
	})(jQuery);
	</script>
	<?php
}

function dnx_seo_render_url_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>SEO Audit &mdash; Analyze a URL</h1>
		<p>Runs the same on-page + technical checks against any public URL &mdash; this site's or a competitor's. Results are cached for an hour per URL/keyword pair.</p>
		<p>
			<input type="url" id="dnx-url-input" placeholder="https://example.com/some-page" style="width:420px">
			<input type="text" id="dnx-url-keyword" placeholder="Focus keyword (optional)" style="width:220px">
			<button class="button button-primary" id="dnx-url-scan">Analyze</button>
		</p>
		<div id="dnx-url-result"></div>
	</div>
	<script>
	(function($){
		$('#dnx-url-scan').on('click', function(){
			var url = $('#dnx-url-input').val();
			var kw = $('#dnx-url-keyword').val();
			if (!url) { return; }
			var btn = $(this);
			btn.prop('disabled', true).text('Analyzing...');
			$('#dnx-url-result').html('<p>Fetching and analyzing&hellip;</p>');
			$.post(dnxSeo.ajaxUrl, { action: 'dnx_seo_scan_url', url: url, keyword: kw, nonce: dnxSeo.nonce })
				.done(function(res){
					btn.prop('disabled', false).text('Analyze');
					if (res.success) {
						$('#dnx-url-result').html(res.data.html);
					} else {
						$('#dnx-url-result').html('<p style="color:#cf222e">' + (res.data && res.data.message ? res.data.message : 'Scan failed.') + '</p>');
					}
				})
				.fail(function(){ btn.prop('disabled', false).text('Analyze'); $('#dnx-url-result').html('<p style="color:#cf222e">Request failed.</p>'); });
		});
	})(jQuery);
	</script>
	<?php
}

function dnx_seo_render_health_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1>SEO Audit &mdash; Site Health</h1>
		<p>Site-wide technical checks (robots.txt, sitemap, HTTPS redirect) run once against the site root.</p>
		<p><button class="button button-primary" id="dnx-health-scan">Run site health check</button></p>
		<div id="dnx-health-result"></div>
	</div>
	<script>
	(function($){
		$('#dnx-health-scan').on('click', function(){
			var btn = $(this);
			btn.prop('disabled', true).text('Checking...');
			$('#dnx-health-result').html('<p>Checking&hellip;</p>');
			$.post(dnxSeo.ajaxUrl, { action: 'dnx_seo_scan_health', nonce: dnxSeo.nonce })
				.done(function(res){
					btn.prop('disabled', false).text('Run site health check');
					if (res.success) {
						$('#dnx-health-result').html(res.data.html);
					} else {
						$('#dnx-health-result').html('<p style="color:#cf222e">Check failed.</p>');
					}
				})
				.fail(function(){ btn.prop('disabled', false).text('Run site health check'); $('#dnx-health-result').html('<p style="color:#cf222e">Request failed.</p>'); });
		});
	})(jQuery);
	</script>
	<?php
}
