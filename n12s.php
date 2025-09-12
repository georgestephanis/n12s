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
 * Initialize our custom REST routes.
 *
 * @return void
 */
function rest_api_init() {
	register_rest_route(
		'n12s/v1',
		'/zip/(?P<zip>\d+)',
		array(
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\rest_get_zip',
			'permission_callback' => '__return_true',
			'args'                => array(
				'zip' => array(
					'validate_callback' => function( $param ) {
						return preg_match( '/^\d{5}$/', $param );
					}
				),
			),
		)
	);
}
add_action( 'rest_api_init', __NAMESPACE__ . '\rest_api_init' );

/**
 * Get details about a given zip code.
 *
 * @param \WP_Rest_Request $request The rest request being passed to the api.
 * @return mixed
 */
function rest_get_zip( $request ) {
	global $wpdb;

	$zip = $request->get_param( 'zip' );

	$zip_details = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}n12s_zips WHERE `zip` = %s", $zip ) );
	$agi_details = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}n12s_irs_agi WHERE `state` = %s ORDER BY `year` DESC", $zip ) );

	return array(
		'zip' => $zip_details,
		'agi' => $agi_details,
	);
}

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

	$num_zips = $wpdb->get_var( "SELECT COUNT(id) FROM {$wpdb->prefix}n12s_zips");
	?>
	<div class="wrap">
		<h1 class="wp-heading-inline"> <?php esc_html_e( 'Neighborhoods', 'n12s' ); ?></h1>
		<a href="#" class="page-title-action"><?php esc_html_e( 'Do Thing' ); ?></a>
		<hr class="wp-header-end" />

		<div>
			<p><?php printf( esc_html__( 'There are %s zip records in the database.', 'n12s' ), number_format_i18n( $num_zips ) ); ?></p>
			<?php if ( empty( $num_zips ) ) : ?>
				<button id="btnGetZips" class="button button-primary"><?php esc_html_e( 'Import Zips', 'n12s' ); ?></button>
			<?php endif; ?>
			<p><a href="https://public.opendatasoft.com/explore/dataset/georef-united-states-of-america-zc-point/information/" target="_blank"><?php esc_html_e( 'Zip Code Data sourced from OpenDataSoft. (Licensed CC BY 4.0)', 'n12s' ); ?></a></p>
		</div>
		<div>
			<h4><?php esc_html_e( 'IRS AGIs:', 'n12s' ); ?></h4>
			<select id="selectGetIrsAgis">
				<option value=""><?php esc_html_e( 'Select a year to import…', 'n12s' ); ?></option>
				<?php
					$years = array_fill( 2011, 12, 0 );
					$data_by_year = $wpdb->get_results( "SELECT year, COUNT(id) as `qty` FROM {$wpdb->prefix}n12s_irs_agi GROUP BY `year`");
					$data_by_year = wp_list_pluck( $data_by_year, 'qty', 'year' );
					foreach ( $data_by_year as $year => $qty ) {
						$years[ $year ] = $qty;
					}
					ksort( $years );
					foreach ( $years as $year => $qty ) {
						printf( '<option value="%1$s"%4$s>%2$s (%3$s)</option>', esc_attr( $year ), esc_html( $year ), esc_html( $qty ), ( (int) $qty > 0 ? ' disabled' : '' ) );
					}
				?>
			</select>
			<button id="btnGetIrsAgis" class="button button-primary"><?php esc_html_e( 'Import IRS AGIs', 'n12s' ); ?></button>
		</div>
		<div>
			<h4><?php esc_html_e( 'ZIP Lookups:', 'n12s' ); ?></h4>

			<input type="search" id="searchZip" pattern="\d{5}" required />
			<button id="btnSearchZip" class="button button-primary"><?php esc_html_e( 'Search Zip Codes…', 'n12s' ); ?></button>

			<pre id="zipSearchResults"></pre>
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

	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	$charset_collate = $wpdb->get_charset_collate();

	$zips_sql = "CREATE TABLE `{$wpdb->prefix}n12s_zips` (
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

	dbDelta( $zips_sql );

	$irs_agi_sql = "CREATE TABLE `{$wpdb->prefix}n12s_irs_agi` (
		`id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
		`year` INT(4) UNSIGNED NOT NULL,
		`zip` CHAR(7) NOT NULL,
		`state` CHAR(2) NOT NULL,
		`country` CHAR(3) NOT NULL DEFAULT 'USA',
		`agi_stub` TINYINT(1) NULL,
		`agi` DOUBLE NULL,
		`returns` INT NULL,
		`individuals` INT NULL,
		`has_salary` VARCHAR(45) NULL,
		PRIMARY KEY (`id`),
		INDEX `ZIP` (`zip` ASC) VISIBLE,
		INDEX `YEAR` (`year` DESC) VISIBLE
	) {$charset_collate};";

	dbDelta( $irs_agi_sql );
}
register_activation_hook( __FILE__, __NAMESPACE__ . '\on_plugin_activation' );

function on_plugin_deactivation() {
	global $wpdb;
	$wpdb->query( "DROP TABLE `{$wpdb->prefix}n12s_zips`;");
	$wpdb->query( "DROP TABLE `{$wpdb->prefix}n12s_irs_agi`;");
}
register_deactivation_hook( __FILE__, __NAMESPACE__ . '\on_plugin_deactivation' );

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
 * Utility function to format the zips for the insertion query.
 *
 * @param array $entry The entry from the CSV.
 * @return string
 */
function format_zip_row_for_insert( $entry ) {
	global $wpdb;

	list( $latitude, $longitude ) = explode( ',', $entry['Geo Point'] );

	$insert = array(
		sprintf( '%05d', $entry['Zip Code'] ),
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
		'qty_zips'       => $wpdb->get_var( "SELECT COUNT(*) FROM `{$wpdb->prefix}n12s_zips``;" ),
	);
}


/**
 * An admin-ajax callback to import the IRS AGIs.
 *
 * @todo Change this over to the REST API.
 * @return mixed
 */
function admin_ajax_n12s_get_irs_agis() {
	$year = (int) $_GET['agi_year'];

	$details = import_irs_agis( $year );

	if ( is_wp_error( $details ) ) {
		return $details;
	}

	wp_send_json_success(
		array(
			'message' => sprintf(
				__( 'It worked! %s records imported for the year %s.', 'n12s' ),
				esc_html( number_format_i18n( $details['qty'] ) ),
				esc_html( $year )
			),
			'details' => $details,
		)
	);
}
add_action( 'wp_ajax_n12s-get-irs-agis', __NAMESPACE__ . '\admin_ajax_n12s_get_irs_agis' );

/**
 * Utility function to format the irs data for the insertion query.
 *
 * @param string $entry The entry from the CSV.
 * @return string
 */
function format_irs_agis_for_insert( $entry, $year ) {
	global $wpdb;

	$insert = array(
		$year,
		$entry['ZIPCODE'],
		$entry['STATE'],
		'USA',
		$entry['AGI_STUB'],
		$entry['A00100'],
		$entry['N1'],
		$entry['N2'],
		$entry['N00200'],
	);

	return $wpdb->prepare(
		'(%d,%s,%s,%s,%d,%f,%d,%d,%d)',
		$insert
	);
}

/**
 * Grab the income tax data by zip for a given year.
 *
 * @param number $year A four digit year, currently supporting from 2011-2022.
 *
 * @return array
 */
function import_irs_agis( $year = '2022' ) {
	global $wpdb;

	$year2 = substr( (string) $year, -2 );
	if ( 11 <= intval( $year2 ) && intval( $year2 ) <= 22 ) {
		define( 'WP_IMPORTING', true );
		$table_name = $wpdb->prefix . 'n12s_irs_agi';

		$url = "https://www.irs.gov/pub/irs-soi/{$year2}zpallagi.csv";

		$irs_agi_csv = \download_url( $url );
		$sleep_time  = time();
		$batch       = array();
		$handle      = fopen( $irs_agi_csv, 'r' );
		if ( $handle ) {
			$headers = fgetcsv( $handle );

			while ( ( $line = fgetcsv( $handle ) ) !== false ) {
				$batch[] =  array_combine( $headers, $line );

				if ( count( $batch ) >= 500 ) {
					$values_sql = implode( ',', array_map( __NAMESPACE__ . '\format_irs_agis_for_insert', $batch, array_fill( 0, count( $batch ), $year ) ) );
					$wpdb->query( 'INSERT INTO `' . $table_name . '` ( `year`, `zip`, `state`, `country`, `agi_stub`, `agi`, `returns`, `individuals`, `has_salary` ) VALUES ' . $values_sql );
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
				$values_sql = implode( ',', array_map( __NAMESPACE__ . '\format_irs_agis_for_insert', $batch, array_fill( 0, count( $batch ), $year ) ) );
				$wpdb->query( 'INSERT INTO `' . $table_name . '` ( `year`, `zip`, `state`, `country`, `agi_stub`, `agi`, `returns`, `individuals`, `has_salary` ) VALUES ' . $values_sql );
			}
		}

		return array(
			'filename'  => $irs_agi_csv,
			'filesize'  => filesize( $irs_agi_csv ),
			'qty'       => $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$wpdb->prefix}n12s_irs_agi` WHERE `year` = %d;", $year ) ),
		);
	}

	return new \WP_Error( 'bad-year', __( 'The year given was bad and didn’t match an available option.', 'n12s' ) );

}
