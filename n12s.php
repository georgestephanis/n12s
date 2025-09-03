<?php
/**
 * Plugin Name:       Neighborhoods
 * Description:       A plugin to aggregate and output data for reflecting neighborhood quality.
 * Version:           0.1.0
 * Requires at least: 6.8
 * Requires PHP:      7.4
 * Author:            georgestephanis
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       n12s
 *
 * @package Jt
 */

namespace Jt\N12s;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}
/**
 * Registers the block using a `blocks-manifest.php` file, which improves the performance of block type registration.
 * Behind the scenes, it also registers all assets so they can be enqueued
 * through the block editor in the corresponding context.
 *
 * @see https://make.wordpress.org/core/2025/03/13/more-efficient-block-type-registration-in-6-8/
 * @see https://make.wordpress.org/core/2024/10/17/new-block-type-registration-apis-to-improve-performance-in-wordpress-6-7/
 */
function jt_n12s_block_init() {
	wp_register_block_types_from_metadata_collection( __DIR__ . '/build', __DIR__ . '/build/blocks-manifest.php' );
}
add_action( 'init', __NAMESPACE__ . '\jt_n12s_block_init' );

/**
 * Register our admin page.
 *
 * @return void
 */
function on_admin_menu() {
	add_menu_page(
		__( 'Neighborhoods', 'n12s' ),
		__( 'Neighborhoods', 'n12s' ),
		'manage_options',
		'n12s',
		__NAMESPACE__ . '\admin_page',
		'dashicons-admin-multisite',
		50
	);
}
add_action( 'admin_menu', __NAMESPACE__ . '\on_admin_menu' );

/**
 * Display our admin page.
 *
 * @return void
 */
function admin_page() {
	global $wpdb;

	$asset_file = include plugin_dir_path( __FILE__ ) . 'build/admin-page.asset.php';
	wp_enqueue_script(
		'n12s',
		plugins_url( 'build/admin-page.js', __FILE__ ),
		$asset_file['dependencies'],
		$asset_file['version'],
		true
	);

	$num_zips = $wpdb->get_var( "SELECT COUNT(*) IN {$wpdb->prefix}n12s_zips");
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"> <?php esc_html_e( 'Neighborhoods', 'n12s' ); ?></h1>
		<a href="#" class="page-title-action"><?php esc_html_e( 'Do Thing' ); ?></a>
		<hr class="wp-header-end" />

		<pre>
			<?php var_dump( $num_zips ); ?>
		</pre>
		<div>
			<p><?php printf( esc_html__( 'There are %s zip records in the database.', 'n12s' ), number_format_i18n( $num_zips ) ); ?></p>
			<?php if ( empty( $num_zips ) ) : ?>
				<button id="btnGetZips" class="button button-primary"><?php esc_html_e( 'Import Zips', 'n12s' ); ?></button>
			<?php endif; ?>
			<p><a href="https://public.opendatasoft.com/explore/dataset/georef-united-states-of-america-zc-point/information/" target="_blank"><?php esc_html_e( 'Zip Code Data sourced from OpenDataSoft. (Licensed CC BY 4.0)', 'n12s' ); ?></a></p>
		</div>
	</div>
	<?php
}

/**
 * Adds the table names to the WPDB global object for easier use and compatibility.
 *
 * @return void
 */
function add_tables_to_wpdb() {
	global $wpdb;

	if ( ! in_array( 'n12s_zips', $wpdb->tables ) ) {
		$wpdb->tables[] = $wpdb->prefix . 'n12s_zips';
	}
}
add_action( 'wp_loaded', __NAMESPACE__ . '\add_tables_to_wpdb' );

/**
 * On plugin activation, create the db tables we will be storing data in.
 *
 * @return void
 */
function on_plugin_activation() {
	global $wpdb;

	$charset_collate = $wpdb->get_charset_collate();

	$sql = "CREATE TABLE `{$wpdb->prefix}n12s_zips` (
		`id` bigint unsigned NOT NULL AUTO_INCREMENT,
		`zip` char(7) NOT NULL,
		`city` varchar(255) NOT NULL,
		`state` char(2) NOT NULL,
		`country` char(3) NOT NULL default 'USA',
		`population` int unsigned NOT NULL default 0,
		`density` float NOT NULL default 0,
		`latitude` float NULL default NULL,
		`longitude` float NULL default NULL,
		PRIMARY KEY  (id),
		KEY ZIP (zip)
	) {$charset_collate};";

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta( $sql );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\on_plugin_activation' );

/**
 * An admin-ajax callback to import the zips.
 *
 * @todo Change this over to the REST API.
 * @return mixed
 */
function admin_ajax_n12s_get_zips() {
	$details = import_zips();

	if ( is_wp_error( $details ) ) {
		return $details;
	}

	wp_send_json_success(
		array(
			'message' => __( 'It worked!', 'n12s' ),
			'details' => $details,
		)
	);
}
add_action( 'wp_ajax_n12s-get-zips', __NAMESPACE__ . '\admin_ajax_n12s_get_zips' );


/**
 * Utility function to format the domain for the insertion query.
 *
 * @param string $entry The entry from the CSV.
 * @return string
 */
function format_zip_row_for_insert( $entry ) {
	global $wpdb;

	list( $latitude, $longitude ) = explode( ',', $entry['Geo Point'] );

	$insert = array(
		$entry['Zip Code'],
		$entry['Official USPS city name'],
		$entry['Official USPS State Code'],
		'USA',
		$entry['Population'],
		$entry['Density'],
		trim( $latitude ),
		trim( $longitude ),
	);

	return $wpdb->prepare(
		'(%s,%s,%s,%s,%s,%s,%s,%s)',
		$insert
	);
}

/**
 * A function to update the list of zips.
 *
 * @todo Update this so it uses a temp table for the import and then hotswaps, so the list doesn't have downtime?
 *
 * @return array The status and result of the attempted import operation.
 */
function import_zips() {
	global $wpdb;

	define( 'WP_IMPORTING', true );

	$zips_filename = plugin_dir_path( __FILE__ ) . 'data/georef-united-states-of-america-zc-point.csv';
	$sleep_time    = time();
	$batch         = array();
	$handle        = fopen( $zips_filename, 'r' );
	if ( $handle ) {
		$headers = fgetcsv( $handle, 0, ';' );
		$headers[0] = 'Zip Code';
		while ( ( $line = fgetcsv( $handle, 0, ';' ) ) !== false ) {
			$batch[] = array_combine( $headers, $line );

			if ( count( $batch ) >= 500 ) {
				$values_sql = implode( ',', array_map( __NAMESPACE__ . '\format_zip_row_for_insert', $batch ) );
				$wpdb->query( "INSERT INTO `{$wpdb->prefix}n12s_zips` ( `zip`, `city`, `state`, `country`, `population`, `density`, `latitude`, `longitude` ) VALUES " . $values_sql );
				$batch = array();

				// Make sure we do a sleep every five seconds or so.
				if ( time() - $sleep_time >= 5 ) {
					set_time_limit( 20 );
					sleep( 1 );
					$sleep_time = time();
				}
			}
		}
		fclose( $handle );

		if ( count( $batch ) > 0 ) {
			$values_sql = implode( ',', array_map( __NAMESPACE__ . '\format_zip_row_for_insert', $batch ) );
			$wpdb->query( "INSERT INTO `{$wpdb->prefix}n12s_zips` ( `zip`, `city`, `state`, `country`, `population`, `density`, `latitude`, `longitude` ) VALUES " . $values_sql );
		}
	}

	return array(
		'zips_filename'  => $zips_filename,
		'zips_filesize'  => filesize( $zips_filename ),
		'qty_zips'       => $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->n12s_zips}`;" ),
	);
}
