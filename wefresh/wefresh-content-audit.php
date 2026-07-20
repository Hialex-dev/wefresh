<?php
/**
 * Plugin Name: We Fresh Content Audit
 * Description: Track content updates, search keywords, and analyze internal links for WordPress SEO workflows.
 * Version:     1.0.1
 * Author:      Wenet
 * Author URI:  https://wenet.website
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

class WNT_Content_Audit {
	const META_MODIFIED   = '_wnt_term_modified';
	const META_INCOMING   = '_wnt_term_incoming';
	const META_INCOMING_T = '_wnt_term_incoming_time';
	const CACHE_TTL       = 43200; // 
	const PER_PAGE        = 100;

	public function __construct() {
		add_action( 'created_term',      array( $this, 'touch_term' ), 10, 3 );
		add_action( 'edited_term',       array( $this, 'touch_term' ), 10, 3 );
		add_action( 'added_term_meta',   array( $this, 'touch_term_meta' ), 10, 3 );
		add_action( 'updated_term_meta', array( $this, 'touch_term_meta' ), 10, 3 );
		add_action( 'admin_menu',        array( $this, 'menu' ) );
		add_action( 'wp_dashboard_setup', array( $this, 'dashboard_widget' ) );
	}


	public function touch_term( $term_id, $tt_id = 0, $taxonomy = '' ) {
		update_term_meta( $term_id, self::META_MODIFIED, current_time( 'mysql' ) );
	}

	public function touch_term_meta( $meta_id, $term_id, $meta_key ) {
		$skip = array( self::META_MODIFIED, self::META_INCOMING, self::META_INCOMING_T );
		if ( in_array( $meta_key, $skip, true ) ) { return; }
		update_term_meta( $term_id, self::META_MODIFIED, current_time( 'mysql' ) );
	}

	private function backfill() {
		global $wpdb;
		$ids = get_terms( array(
			'taxonomy'   => array_keys( $this->taxonomies() ),
			'hide_empty' => false,
			'fields'     => 'ids',
		) );
		if ( is_wp_error( $ids ) ) { return 0; }
		$done = 0;
		foreach ( $ids as $tid ) {
			if ( get_term_meta( $tid, self::META_MODIFIED, true ) ) { continue; }
			$latest = $wpdb->get_var( $wpdb->prepare(
				"SELECT MAX(p.post_modified) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
				 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
				 WHERE tt.term_id = %d AND p.post_status = 'publish'", $tid ) );
			update_term_meta( $tid, self::META_MODIFIED, $latest ? $latest : current_time( 'mysql' ) );
			$done++;
		}
		return $done;
	}

	private function post_types() {
		$allow = array( 'post', 'page' ); // برای افزودن محصول: array( 'post', 'page', 'product' )
		$out = array();
		foreach ( $allow as $name ) {
			$obj = get_post_type_object( $name );
			if ( $obj ) { $out[ $name ] = $obj->labels->singular_name; }
		}
		return $out;
	}

	private function taxonomies() {
		$allow = array( 'category', 'post_tag', 'product_cat', 'product_tag' );
		$out = array();
		foreach ( $allow as $name ) {
			$obj = get_taxonomy( $name );
			if ( $obj ) { $out[ $name ] = $obj->labels->singular_name; }
		}
		return $out;
	}

	private function seo_source() {
		global $wpdb;
		static $src = null;
		if ( null !== $src ) { return $src; }
		$rm = $wpdb->prefix . 'rank_math_internal_meta';
		$yo = $wpdb->prefix . 'yoast_indexable';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$rm}'" ) === $rm ) {
			$src = 'rankmath';
		} elseif ( $wpdb->get_var( "SHOW TABLES LIKE '{$yo}'" ) === $yo ) {
			$src = 'yoast';
		} else {
			$src = '';
		}
		return $src;
	}

	private function post_links( array $ids ) {
		global $wpdb;
		$out = array();
		if ( empty( $ids ) ) { return $out; }
		$in = implode( ',', array_map( 'absint', $ids ) );

		if ( 'rankmath' === $this->seo_source() ) {
			$rows = $wpdb->get_results( "SELECT object_id, internal_link_count, incoming_link_count
				FROM {$wpdb->prefix}rank_math_internal_meta WHERE object_id IN ({$in})" );
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r->object_id ] = array( 'out' => (int) $r->internal_link_count, 'in' => (int) $r->incoming_link_count );
			}
		} elseif ( 'yoast' === $this->seo_source() ) {
			$rows = $wpdb->get_results( "SELECT object_id, link_count, incoming_link_count
				FROM {$wpdb->prefix}yoast_indexable WHERE object_type = 'post' AND object_id IN ({$in})" );
			foreach ( (array) $rows as $r ) {
				$out[ (int) $r->object_id ] = array( 'out' => (int) $r->link_count, 'in' => (int) $r->incoming_link_count );
			}
		}
		return $out;
	}

	private function term_incoming( $term ) {
		$cached = get_term_meta( $term->term_id, self::META_INCOMING, true );
		$ts     = (int) get_term_meta( $term->term_id, self::META_INCOMING_T, true );
		if ( '' !== $cached && ( time() - $ts ) < self::CACHE_TTL ) { return (int) $cached; }

		$link = get_term_link( $term );
		if ( is_wp_error( $link ) ) { return 0; }

		global $wpdb;
		$path  = wp_make_link_relative( $link );
		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			 WHERE post_status = 'publish' AND post_type NOT IN ('revision','attachment')
			 AND post_content LIKE %s", '%' . $wpdb->esc_like( $path ) . '%' ) );

		update_term_meta( $term->term_id, self::META_INCOMING, $count );
		update_term_meta( $term->term_id, self::META_INCOMING_T, time() );
		return $count;
	}

	private function search_posts( $kw, $types ) {
		global $wpdb;
		if ( empty( $types ) || '' === $kw ) { return array(); }
		$ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$like = '%' . $wpdb->esc_like( $kw ) . '%';
		$sql  = "SELECT ID, post_title, post_type, post_modified, post_status
		         FROM {$wpdb->posts}
		         WHERE post_type IN ({$ph})
		           AND post_status = 'publish'
		           AND ( post_title LIKE %s OR post_content LIKE %s OR post_excerpt LIKE %s )
		         LIMIT 1000";
		$args = array_merge( array_values( $types ), array( $like, $like, $like ) );
		return $wpdb->get_results( $wpdb->prepare( $sql, $args ) );
	}

	private function search_terms( $kw, $taxes ) {
		if ( empty( $taxes ) || '' === $kw ) { return array(); }
		$base = array( 'taxonomy' => $taxes, 'hide_empty' => true, 'number' => 500 );
		$a = get_terms( array_merge( $base, array( 'search' => $kw ) ) );
		$b = get_terms( array_merge( $base, array( 'description__like' => $kw ) ) );
		$all = array();
		foreach ( array( $a, $b ) as $set ) {
			if ( is_wp_error( $set ) ) { continue; }
			foreach ( (array) $set as $t ) { $all[ $t->term_id ] = $t; }
		}
		return $all;
	}

	private function noindex_ids( $type, array $ids ) {
		$ids = array_map( 'absint', array_filter( $ids ) );
		if ( empty( $ids ) ) { return array(); }
		$bad = array();

		if ( 'yoast' === $this->seo_source() ) {
			global $wpdb;
			$in   = implode( ',', $ids );
			$rows = $wpdb->get_col( $wpdb->prepare(
				"SELECT object_id FROM {$wpdb->prefix}yoast_indexable
				 WHERE object_type = %s AND object_id IN ({$in}) AND is_robots_noindex = 1", $type ) );
			$bad = array_map( 'intval', (array) $rows );

		} elseif ( 'rankmath' === $this->seo_source() ) {
			update_meta_cache( $type, $ids );
			foreach ( $ids as $id ) {
				$robots = ( 'post' === $type )
					? get_post_meta( $id, 'rank_math_robots', true )
					: get_term_meta( $id, 'rank_math_robots', true );
				if ( is_array( $robots ) && in_array( 'noindex', $robots, true ) ) { $bad[] = $id; }
			}
		}
		return $bad;
	}

	public function menu() {
		add_menu_page( 'بررسی تازگی محتوا', 'بررسی تازگی محتوا ', 'edit_others_posts',
			'wnt-audit', array( $this, 'render' ), 'dashicons-search', 58 );
	}

	private function sort_link( $col, $label, $orderby, $order ) {
		$new = ( $orderby === $col && 'desc' === $order ) ? 'asc' : 'desc';
		$url = add_query_arg( array( 'orderby' => $col, 'order' => $new, 'paged' => 1 ) );
		$ind = ( $orderby === $col ) ? ( 'desc' === $order ? ' ↓' : ' ↑' ) : '';
		return '<a href="' . esc_url( $url ) . '">' . esc_html( $label ) . $ind . '</a>';
	}

	public function render() {
		if ( ! current_user_can( 'edit_others_posts' ) ) { return; }

		if ( isset( $_POST['wnt_backfill'] ) && check_admin_referer( 'wnt_backfill' ) ) {
			$n = $this->backfill();
			echo '<div class="notice notice-success"><p>' . intval( $n ) . ' ترم مقداردهی اولیه شد.</p></div>';
		}

		$all_pt = $this->post_types();
		$all_tx = $this->taxonomies();

		$kw      = isset( $_GET['kw'] ) ? sanitize_text_field( wp_unslash( $_GET['kw'] ) ) : '';
		$types   = isset( $_GET['pt'] ) ? array_map( 'sanitize_key', (array) $_GET['pt'] ) : array_keys( $all_pt );
		$taxes   = isset( $_GET['tx'] ) ? array_map( 'sanitize_key', (array) $_GET['tx'] ) : array_keys( $all_tx );
		$types   = array_values( array_intersect( $types, array_keys( $all_pt ) ) );
		$taxes   = array_values( array_intersect( $taxes, array_keys( $all_tx ) ) );
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'modified';
		$order   = ( isset( $_GET['order'] ) && 'asc' === $_GET['order'] ) ? 'asc' : 'desc';
		$paged   = max( 1, isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1 );

		$src_label = array( 'rankmath' => 'Rank Math', 'yoast' => 'Yoast', '' => 'یافت نشد' );
		?>
		<div class="wrap">
			<h1>بررسی تازگی محتوا </h1>
			<p>منبع لینک داخلی: <strong><?php echo esc_html( $src_label[ $this->seo_source() ] ); ?></strong></p>

			<form method="post" style="margin-bottom:12px">
				<?php wp_nonce_field( 'wnt_backfill' ); ?>
				<button class="button" name="wnt_backfill" value="1">مقداردهی اولیه تاریخ ترم‌ها</button>
				<span class="description">برای ترم‌هایی که هنوز تاریخی ندارند، آخرین تاریخ ویرایش نوشته‌های همان ترم ثبت می‌شود.</span>
			</form>

			<form method="get">
				<input type="hidden" name="page" value="wnt-audit">
				<p>
					<input type="search" name="kw" value="<?php echo esc_attr( $kw ); ?>" placeholder="کلمه کلیدی، مثلاً: پارکت" style="width:320px">
					<button class="button button-primary">جست‌وجو</button>
				</p>
				<p><strong>انواع محتوا:</strong><br>
				<?php foreach ( $all_pt as $k => $l ) : ?>
					<label style="margin-left:12px"><input type="checkbox" name="pt[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $types, true ) ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
				</p>
				<p><strong>طبقه‌بندی‌ها:</strong><br>
				<?php foreach ( $all_tx as $k => $l ) : ?>
					<label style="margin-left:12px"><input type="checkbox" name="tx[]" value="<?php echo esc_attr( $k ); ?>" <?php checked( in_array( $k, $taxes, true ) ); ?>> <?php echo esc_html( $l ); ?></label>
				<?php endforeach; ?>
				</p>
			</form>
		<?php

		if ( '' === $kw ) { echo '</div>'; return; }

		$rows  = array();
		$posts = $this->search_posts( $kw, $types );

		$skip_p = $this->noindex_ids( 'post', wp_list_pluck( (array) $posts, 'ID' ) );
		$posts  = array_filter( (array) $posts, function ( $p ) use ( $skip_p ) {
			return ! in_array( (int) $p->ID, $skip_p, true );
		} );

		$links = $this->post_links( wp_list_pluck( (array) $posts, 'ID' ) );

		foreach ( (array) $posts as $p ) {
			$lk = isset( $links[ $p->ID ] ) ? $links[ $p->ID ] : array( 'in' => 0, 'out' => 0 );
			$rows[] = array(
				'title'    => $p->post_title,
				'type'     => isset( $all_pt[ $p->post_type ] ) ? $all_pt[ $p->post_type ] : $p->post_type,
				'status'   => $p->post_status,
				'modified' => $p->post_modified,
				'in'       => $lk['in'],
				'out'      => $lk['out'],
				'edit'     => get_edit_post_link( $p->ID, '' ),
				'view'     => get_permalink( $p->ID ),
			);
		}

		$terms  = $this->search_terms( $kw, $taxes );
		$skip_t = $this->noindex_ids( 'term', array_keys( $terms ) );
		foreach ( $terms as $t ) {
			if ( in_array( (int) $t->term_id, $skip_t, true ) ) { continue; }
			$rows[] = array(
				'title'    => $t->name,
				'type'     => isset( $all_tx[ $t->taxonomy ] ) ? $all_tx[ $t->taxonomy ] : $t->taxonomy,
				'status'   => $t->count . ' آیتم',
				'modified' => get_term_meta( $t->term_id, self::META_MODIFIED, true ),
				'in'       => $this->term_incoming( $t ),
				'out'      => null,
				'edit'     => get_edit_term_link( $t->term_id, $t->taxonomy ),
				'view'     => get_term_link( $t ),
			);
		}

		usort( $rows, function ( $a, $b ) use ( $orderby, $order ) {
			switch ( $orderby ) {
				case 'title': $c = strcmp( $a['title'], $b['title'] ); break;
				case 'in':    $c = $a['in'] - $b['in']; break;
				case 'type':  $c = strcmp( $a['type'], $b['type'] ); break;
				default:      $c = strtotime( $a['modified'] ? $a['modified'] : '1970-01-01' ) - strtotime( $b['modified'] ? $b['modified'] : '1970-01-01' );
			}
			return ( 'asc' === $order ) ? $c : -$c;
		} );

		$total     = count( $rows );
		$page_rows = array_slice( $rows, ( $paged - 1 ) * self::PER_PAGE, self::PER_PAGE );
		$fmt       = get_option( 'date_format' ) . ' - ' . get_option( 'time_format' );
		?>
			<p><?php echo intval( $total ); ?> نتیجه</p>
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th><?php echo $this->sort_link( 'title', 'عنوان', $orderby, $order ); ?></th>
					<th style="width:120px"><?php echo $this->sort_link( 'type', 'نوع', $orderby, $order ); ?></th>
					<th style="width:90px">وضعیت</th>
					<th style="width:170px"><?php echo $this->sort_link( 'modified', 'آخرین به‌روزرسانی', $orderby, $order ); ?></th>
					<th style="width:80px">روز گذشته</th>
					<th style="width:110px"><?php echo $this->sort_link( 'in', 'لینک ورودی', $orderby, $order ); ?></th>
					<th style="width:110px">لینک خروجی</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $page_rows as $r ) :
					$ts   = $r['modified'] ? strtotime( $r['modified'] ) : 0;
					$days = $ts ? floor( ( current_time( 'timestamp' ) - $ts ) / DAY_IN_SECONDS ) : '—'; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $r['title'] ); ?></strong><br>
							<?php if ( $r['edit'] ) : ?><a href="<?php echo esc_url( $r['edit'] ); ?>">ویرایش</a> | <?php endif; ?>
							<?php if ( ! is_wp_error( $r['view'] ) && $r['view'] ) : ?><a href="<?php echo esc_url( $r['view'] ); ?>" target="_blank">مشاهده</a><?php endif; ?>
						</td>
						<td><?php echo esc_html( $r['type'] ); ?></td>
						<td><?php echo esc_html( $r['status'] ); ?></td>
						<td><?php echo $ts ? esc_html( date_i18n( $fmt, $ts ) ) : '—'; ?></td>
						<td><?php echo esc_html( $days ); ?></td>
						<td><?php echo intval( $r['in'] ); ?></td>
						<td><?php echo ( null === $r['out'] ) ? '—' : intval( $r['out'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<div class="tablenav"><div class="tablenav-pages"><?php
				echo paginate_links( array(
					'base'    => add_query_arg( 'paged', '%#%' ),
					'format'  => '',
					'total'   => max( 1, ceil( $total / self::PER_PAGE ) ),
					'current' => $paged,
				) );
			?></div></div>
		</div>
		<?php
	}
	
	public function dashboard_widget() {
		if ( ! current_user_can( 'edit_others_posts' ) ) { return; }
		wp_add_dashboard_widget( 'wnt_audit_report', 'گزارش تازگی محتوا (Content Freshness)', array( $this, 'render_dashboard' ) );
	}

	private function buckets_def() {
		return array(
			'm1'  => array( 'label' => 'تا ۱ ماه',      'max' => 30,           'color' => '#2e7d32' ),
			'm3'  => array( 'label' => '۱ تا ۳ ماه',    'max' => 90,           'color' => '#7cb342' ),
			'm6'  => array( 'label' => '۳ تا ۶ ماه',    'max' => 180,          'color' => '#f9a825' ),
			'y1'  => array( 'label' => '۶ تا ۱۲ ماه',   'max' => 365,          'color' => '#ef6c00' ),
			'old' => array( 'label' => 'بیش از ۱ سال',  'max' => PHP_INT_MAX,  'color' => '#c62828' ),
		);
	}

	private function report_data( $force = false ) {
		$cached = get_transient( 'wnt_audit_report' );
		if ( ! $force && is_array( $cached ) ) { return $cached; }

		global $wpdb;
		$defs = $this->buckets_def();
		$data = array( 'total' => 0, 'unknown' => 0, 'generated' => time(), 'buckets' => array() );
		foreach ( $defs as $k => $d ) {
			$data['buckets'][ $k ] = array( 'label' => $d['label'], 'color' => $d['color'], 'count' => 0, 'items' => array() );
		}

		$now   = current_time( 'timestamp' );
		$items = array();

		$types = array_keys( $this->post_types() );
		if ( ! empty( $types ) ) {
			$ph   = implode( ',', array_fill( 0, count( $types ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare(
				"SELECT ID, post_title, post_type, post_modified FROM {$wpdb->posts}
				 WHERE post_type IN ({$ph}) AND post_status = 'publish' LIMIT 5000", $types ) );
			$skip = $this->noindex_ids( 'post', wp_list_pluck( (array) $rows, 'ID' ) );
			foreach ( (array) $rows as $r ) {
				if ( in_array( (int) $r->ID, $skip, true ) ) { continue; }
				$items[] = array(
					'title' => $r->post_title,
					'days'  => (int) floor( ( $now - strtotime( $r->post_modified ) ) / DAY_IN_SECONDS ),
					'edit'  => get_edit_post_link( $r->ID, '' ),
				);
			}
		}

		$taxes = array_keys( $this->taxonomies() );
		if ( ! empty( $taxes ) ) {
			$terms = get_terms( array( 'taxonomy' => $taxes, 'hide_empty' => true, 'number' => 2000 ) );
			if ( ! is_wp_error( $terms ) ) {
				$skip = $this->noindex_ids( 'term', wp_list_pluck( $terms, 'term_id' ) );
				foreach ( $terms as $t ) {
					if ( in_array( (int) $t->term_id, $skip, true ) ) { continue; }
					$m = get_term_meta( $t->term_id, self::META_MODIFIED, true );
					if ( ! $m ) { $data['unknown']++; continue; }
					$items[] = array(
						'title' => $t->name,
						'days'  => (int) floor( ( $now - strtotime( $m ) ) / DAY_IN_SECONDS ),
						'edit'  => get_edit_term_link( $t->term_id, $t->taxonomy ),
					);
				}
			}
		}

		foreach ( $items as $it ) {
			foreach ( $defs as $k => $d ) {
				if ( $it['days'] <= $d['max'] ) {
					$data['buckets'][ $k ]['count']++;
					$data['buckets'][ $k ]['items'][] = $it;
					break;
				}
			}
		}
		$data['total'] = count( $items );

		foreach ( $data['buckets'] as $k => $b ) {
			usort( $b['items'], function ( $a, $c ) { return $c['days'] - $a['days']; } );
			$data['buckets'][ $k ]['items'] = array_slice( $b['items'], 0, 10 );
		}

		set_transient( 'wnt_audit_report', $data, 6 * HOUR_IN_SECONDS );
		return $data;
	}

	private function donut( $data ) {
		$r   = 60;
		$c   = 2 * M_PI * $r;
		$off = 0;
		$svg = '<svg viewBox="0 0 160 160" width="160" height="160" style="transform:rotate(-90deg)">';
		$svg .= '<circle cx="80" cy="80" r="' . $r . '" fill="none" stroke="#e5e5e5" stroke-width="28"></circle>';
		if ( $data['total'] > 0 ) {
			foreach ( $data['buckets'] as $b ) {
				if ( ! $b['count'] ) { continue; }
				$len  = $c * ( $b['count'] / $data['total'] );
				$svg .= '<circle cx="80" cy="80" r="' . $r . '" fill="none" stroke="' . esc_attr( $b['color'] ) . '"'
					. ' stroke-width="28" stroke-dasharray="' . round( $len, 2 ) . ' ' . round( $c - $len, 2 ) . '"'
					. ' stroke-dashoffset="' . round( -$off, 2 ) . '"></circle>';
				$off += $len;
			}
		}
		return $svg . '</svg>';
	}

	public function render_dashboard() {
		$force = false;
		if ( isset( $_GET['wnt_refresh'] ) ) {
			check_admin_referer( 'wnt_refresh' );
			$force = true;
		}
		$d = $this->report_data( $force );

		if ( ! $d['total'] ) {
			echo '<p>محتوایی برای گزارش یافت نشد.</p>';
			return;
		}
		?>
		<div style="display:flex;gap:20px;align-items:center;flex-wrap:wrap">
			<div><?php echo $this->donut( $d ); ?></div> 
			<div style="flex:1;min-width:180px">
				<?php foreach ( $d['buckets'] as $b ) :
					$pct = round( $b['count'] / $d['total'] * 100, 1 ); ?>
					<div style="margin-bottom:6px">
						<span style="display:inline-block;width:12px;height:12px;background:<?php echo esc_attr( $b['color'] ); ?>;border-radius:2px;margin-left:6px"></span>
						<?php echo esc_html( $b['label'] ); ?>:
						<strong><?php echo esc_html( $pct ); ?>%</strong>
						<span style="color:#777">(<?php echo intval( $b['count'] ); ?>)</span>
					</div>
				<?php endforeach; ?>
				<p style="color:#777;margin-top:10px">
					مجموع <?php echo intval( $d['total'] ); ?> آیتم
					<?php if ( $d['unknown'] ) : ?>— <?php echo intval( $d['unknown'] ); ?> ترم بدون تاریخ (مقداردهی اولیه را اجرا کنید)<?php endif; ?>
					<br>
					<a href="<?php echo esc_url( wp_nonce_url( add_query_arg( 'wnt_refresh', 1, admin_url( 'index.php' ) ), 'wnt_refresh' ) ); ?>">به‌روزرسانی گزارش</a>
					| آخرین محاسبه: <?php echo esc_html( date_i18n( get_option( 'time_format' ), $d['generated'] ) ); ?>
				</p>
			</div>
		</div>

		<?php
		$lists = array( 'm3' => '۱ تا ۳ ماه', 'm6' => '۳ تا ۶ ماه', 'y1' => '۶ تا ۱۲ ماه', 'old' => 'بیش از ۱ سال' );
		foreach ( $lists as $key => $title ) :
			if ( empty( $d['buckets'][ $key ]['items'] ) ) { continue; } ?>
			<h4 style="margin:14px 0 4px;border-top:1px solid #eee;padding-top:10px">
				<?php echo esc_html( $title ); ?>
				<span style="font-weight:normal;color:#777">— ۱۰ مورد قدیمی‌تر</span>
			</h4>
			<ol style="margin:0;padding-right:20px">
				<?php foreach ( $d['buckets'][ $key ]['items'] as $it ) : ?>
					<li>
						<?php if ( $it['edit'] ) : ?>
							<a href="<?php echo esc_url( $it['edit'] ); ?>"><?php echo esc_html( $it['title'] ); ?></a>
						<?php else : ?>
							<?php echo esc_html( $it['title'] ); ?>
						<?php endif; ?>
						<span style="color:#777">(<?php echo intval( $it['days'] ); ?> روز)</span>
					</li>
				<?php endforeach; ?>
			</ol>
		<?php endforeach;
	}
}



if ( ! isset( $GLOBALS['wnt_content_audit'] ) ) {
	$GLOBALS['wnt_content_audit'] = new WNT_Content_Audit();
}

$puc = __DIR__ . '/plugin-update-checker/plugin-update-checker.php';

if ( file_exists( $puc ) ) {

    require_once $puc;

    if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {

        $checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
            'https://github.com/Hialex-dev/wefresh',
            __FILE__,
            'wefresh'
        );

        $checker->getVcsApi()->enableReleaseAssets();

    }

}