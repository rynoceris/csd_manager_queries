<?php
/**
 * Plugin Name: College Sports Directory Manager
 * Plugin URI: https://ryanours.com
 * Description: Manage college and university staff members and school information with search, sort, import, and shortcode capabilities.
 * Version: 1.2
 * Author: Ryan Ours
 * Author URI: https://ryanours.com
 * Text Domain: csd-manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

// Define plugin constants
define('CSD_MANAGER_VERSION', '1.2');
define('CSD_MANAGER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('CSD_MANAGER_PLUGIN_URL', plugin_dir_url(__FILE__));

// Include required files
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/admin-menu.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/schools-manager.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/staff-manager.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/import-export.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/functions.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/database-connection.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/snapshot-tool.php');

/**
 * Add this standalone activation function
 */
function csd_plugin_activate() {
	// Ensure the admin role has the required capability
	$admin = get_role('administrator');
	if ($admin) {
		$admin->add_cap('manage_csd');
	}
	
	// Run the class activation method if available
	global $csd_manager;
	if (isset($csd_manager) && method_exists($csd_manager, 'activate')) {
		$csd_manager->activate();
	} else {
		// If class isn't ready, at least set up basic functionality
		flush_rewrite_rules();
	}
}

// Add an additional safety check function
function csd_ensure_capabilities() {
	// Only add the capability if it doesn't exist yet
	$admin = get_role('administrator');
	if ($admin && !$admin->has_cap('manage_csd')) {
		$admin->add_cap('manage_csd');
	}
}
add_action('admin_init', 'csd_ensure_capabilities');

// Register AJAX handlers manually
add_action('wp_ajax_csd_get_schools', 'csd_ajax_get_schools_wrapper');
function csd_ajax_get_schools_wrapper() {
	global $csd_manager;
	
	// Include necessary files
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/schools-manager.php');
	
	// Create instance and call handler
	$schools_manager = new CSD_Schools_Manager();
	$schools_manager->ajax_get_schools();
}

add_action('wp_ajax_csd_get_staff', 'csd_ajax_get_staff_wrapper');
function csd_ajax_get_staff_wrapper() {
	global $csd_manager;
	
	// Include necessary files
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/staff-manager.php');
	
	// Create instance and call handler
	$staff_manager = new CSD_Staff_Manager();
	$staff_manager->ajax_get_staff();
}

// Add wrappers for other AJAX actions
add_action('wp_ajax_csd_save_school', 'csd_ajax_save_school_wrapper');
function csd_ajax_save_school_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/schools-manager.php');
	$schools_manager = new CSD_Schools_Manager();
	$schools_manager->ajax_save_school();
}

add_action('wp_ajax_csd_delete_school', 'csd_ajax_delete_school_wrapper');
function csd_ajax_delete_school_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/schools-manager.php');
	$schools_manager = new CSD_Schools_Manager();
	$schools_manager->ajax_delete_school();
}

add_action('wp_ajax_csd_save_staff', 'csd_ajax_save_staff_wrapper');
function csd_ajax_save_staff_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/staff-manager.php');
	$staff_manager = new CSD_Staff_Manager();
	$staff_manager->ajax_save_staff();
}

add_action('wp_ajax_csd_delete_staff', 'csd_ajax_delete_staff_wrapper');
function csd_ajax_delete_staff_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/staff-manager.php');
	$staff_manager = new CSD_Staff_Manager();
	$staff_manager->ajax_delete_staff();
}

add_action('wp_ajax_csd_get_schools_dropdown', 'csd_ajax_get_schools_dropdown_wrapper');
function csd_ajax_get_schools_dropdown_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/staff-manager.php');
	$staff_manager = new CSD_Staff_Manager();
	$staff_manager->ajax_get_schools_dropdown();
}

// Shortcode related actions
add_action('wp_ajax_csd_save_shortcode_view', 'csd_ajax_save_shortcode_view_wrapper');
function csd_ajax_save_shortcode_view_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php');
	$shortcodes = new CSD_Shortcodes();
	$shortcodes->ajax_save_shortcode_view();
}

add_action('wp_ajax_csd_get_shortcode_views', 'csd_ajax_get_shortcode_views_wrapper');
function csd_ajax_get_shortcode_views_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php');
	$shortcodes = new CSD_Shortcodes();
	$shortcodes->ajax_get_shortcode_views();
}

add_action('wp_ajax_csd_delete_shortcode_view', 'csd_ajax_delete_shortcode_view_wrapper');
function csd_ajax_delete_shortcode_view_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php');
	$shortcodes = new CSD_Shortcodes();
	$shortcodes->ajax_delete_shortcode_view();
}

// Frontend AJAX handlers 
add_action('wp_ajax_csd_filter_schools', 'csd_ajax_filter_schools_wrapper');
add_action('wp_ajax_nopriv_csd_filter_schools', 'csd_ajax_filter_schools_wrapper');
function csd_ajax_filter_schools_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php');
	$shortcodes = new CSD_Shortcodes();
	$shortcodes->ajax_filter_schools();
}

add_action('wp_ajax_csd_filter_staff', 'csd_ajax_filter_staff_wrapper');
add_action('wp_ajax_nopriv_csd_filter_staff', 'csd_ajax_filter_staff_wrapper');
function csd_ajax_filter_staff_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/shortcodes.php');
	$shortcodes = new CSD_Shortcodes();
	$shortcodes->ajax_filter_staff();
}

// Import/Export AJAX handlers
add_action('wp_ajax_csd_preview_import', 'csd_ajax_preview_import_wrapper');
function csd_ajax_preview_import_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/import-export.php');
	$import_export = new CSD_Import_Export();
	$import_export->ajax_preview_import();
}

add_action('wp_ajax_csd_process_import', 'csd_ajax_process_import_wrapper');
function csd_ajax_process_import_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/import-export.php');
	$import_export = new CSD_Import_Export();
	$import_export->ajax_process_import();
}

add_action('wp_ajax_csd_export_data', 'csd_ajax_export_data_wrapper');
function csd_ajax_export_data_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/import-export.php');
	$import_export = new CSD_Import_Export();
	$import_export->ajax_export_data();
}

// Debug test function - only needed for testing
add_action('wp_ajax_csd_test_simple', 'csd_ajax_test_simple_wrapper');
function csd_ajax_test_simple_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/import-export.php');
	$import_export = new CSD_Import_Export();
	$import_export->ajax_test_simple();
}

// Query Builder AJAX handlers
add_action('wp_ajax_csd_run_custom_query', 'csd_ajax_run_custom_query_wrapper');
function csd_ajax_run_custom_query_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
	$query_builder = new CSD_Query_Builder();
	$query_builder->ajax_run_custom_query();
}

add_action('wp_ajax_csd_get_field_values', 'csd_ajax_get_field_values_wrapper');
function csd_ajax_get_field_values_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
	$query_builder = new CSD_Query_Builder();
	$query_builder->ajax_get_field_values();
}

add_action('wp_ajax_csd_save_query', 'csd_ajax_save_query_wrapper');
function csd_ajax_save_query_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
	$query_builder = new CSD_Query_Builder();
	$query_builder->ajax_save_query();
}

add_action('wp_ajax_csd_load_query', 'csd_ajax_load_query_wrapper');
function csd_ajax_load_query_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
	$query_builder = new CSD_Query_Builder();
	$query_builder->ajax_load_query();
}

add_action('wp_ajax_csd_delete_query', 'csd_ajax_delete_query_wrapper');
function csd_ajax_delete_query_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
	$query_builder = new CSD_Query_Builder();
	$query_builder->ajax_delete_query();
}

add_action('wp_ajax_csd_export_query_results', 'csd_ajax_export_query_results_wrapper');
function csd_ajax_export_query_results_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
	$query_builder = new CSD_Query_Builder();
	$query_builder->ajax_export_query_results();
}

// Snapshot tool AJAX handler
add_action('wp_ajax_csd_fetch_snapshot', 'csd_ajax_fetch_snapshot_wrapper');
function csd_ajax_fetch_snapshot_wrapper() {
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/snapshot-tool.php');
	$snapshot_tool = new CSD_Snapshot_Tool();
	$snapshot_tool->ajax_fetch_snapshot();
}

// User search AJAX handler
add_action('wp_ajax_csd_search_users', 'csd_ajax_search_users_wrapper');
function csd_ajax_search_users_wrapper() {
	// Check nonce
	if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
		wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
		return;
	}
	
	if (!current_user_can('manage_options')) {
		wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
		return;
	}
	
	$search = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
	
	$args = array(
		'search' => '*' . $search . '*',
		'search_columns' => array('user_login', 'user_email', 'display_name'),
		'orderby' => 'display_name',
		'number' => 20
	);
	
	$users = get_users($args);
	$user_data = array();
	
	foreach ($users as $user) {
		$user_data[] = array(
			'id' => $user->ID,
			'text' => $user->display_name . ' (' . $user->user_email . ')'
		);
	}
	
	wp_send_json_success(array('users' => $user_data));
}

/**
 * Main plugin class
 */
class CSD_Manager {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Initialize hooks
		add_action('admin_enqueue_scripts', array($this, 'admin_scripts'));
		add_action('wp_enqueue_scripts', array($this, 'frontend_scripts'));
		
		// Initialize admin menus
		new CSD_Admin_Menu();
		
		// Initialize shortcodes
		new CSD_Shortcodes();
		
		// Register activation and deactivation hooks
		register_activation_hook(__FILE__, array($this, 'activate'));
		register_deactivation_hook(__FILE__, array($this, 'deactivate'));
	}
	
	/**
	 * Enqueue admin scripts and styles
	 */
	public function admin_scripts($hook) {
		$admin_pages = array(
			'toplevel_page_csd-manager',
			'csd-manager_page_csd-schools',
			'csd-manager_page_csd-staff',
			'csd-manager_page_csd-import-export',
			'csd-manager_page_csd-shortcodes',
		);
		
		if (in_array($hook, $admin_pages)) {
			wp_enqueue_style('csd-admin-styles', CSD_MANAGER_PLUGIN_URL . 'assets/css/admin.css?v=1.1', array(), CSD_MANAGER_VERSION);
			wp_enqueue_script('csd-admin-scripts', CSD_MANAGER_PLUGIN_URL . 'assets/js/admin.js', array('jquery', 'jquery-ui-sortable', 'jquery-ui-datepicker'), CSD_MANAGER_VERSION, true);
			
			// Add Ajax URL
			wp_localize_script('csd-admin-scripts', 'csd_ajax', array(
				'ajax_url' => admin_url('admin-ajax.php'),
				'nonce' => wp_create_nonce('csd-ajax-nonce')
			));
		}
	}
	
	/**
	 * Enqueue frontend scripts and styles
	 */
	public function frontend_scripts() {
		wp_enqueue_style('csd-frontend-styles', CSD_MANAGER_PLUGIN_URL . 'assets/css/frontend.css', array(), CSD_MANAGER_VERSION);
		wp_enqueue_script('csd-frontend-scripts', CSD_MANAGER_PLUGIN_URL . 'assets/js/frontend.js', array('jquery'), CSD_MANAGER_VERSION, true);
	}
	
	/**
	 * Plugin activation
	 */
	public function activate() {
		// Create necessary tables if they don't exist
		// In this case, we're working with existing tables, so we'll just check if they exist
		$wpdb = csd_db_connection();
		
		$tables_exist = true;
		
		// Check if csd_schools table exists
		if ($wpdb->get_var("SHOW TABLES LIKE 'csd_schools'") != "csd_schools") {
			$tables_exist = false;
		}
		
		// Check if csd_staff table exists
		if ($wpdb->get_var("SHOW TABLES LIKE 'csd_staff'") != "csd_staff") {
			$tables_exist = false;
		}
		
		// Check if csd_school_staff table exists
		if ($wpdb->get_var("SHOW TABLES LIKE 'csd_school_staff'") != "csd_school_staff") {
			$tables_exist = false;
		}
		
		// If tables don't exist, show an admin notice
		if (!$tables_exist) {
			add_option('csd_show_tables_notice', 1);
		}
		
		// Create capabilities
		$admin = get_role('administrator');
		if ($admin) {
			$admin->add_cap('manage_csd');
		}
		
		// Create shortcode view storage table
		$this->create_shortcode_views_table();
		
		// Create query builder tables
		$query_builder = new CSD_Query_Builder();
		$query_builder->create_saved_queries_table();
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}
	
	/**
	 * Create shortcode views storage table
	 */
	private function create_shortcode_views_table() {
		$wpdb = csd_db_connection();
		
		$table_name = csd_table('shortcode_views');
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			view_name varchar(255) NOT NULL,
			view_type varchar(50) NOT NULL,
			view_settings longtext NOT NULL,
			shortcode varchar(255) NOT NULL,
			date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
	
	/**
	 * Plugin deactivation
	 */
	public function deactivate() {
		// Remove capabilities
		$admin = get_role('administrator');
		if ($admin) {
			$admin->remove_cap('manage_csd');
		}
		
		// Flush rewrite rules
		flush_rewrite_rules();
	}
}

// Register both hooks - standalone function and class method
register_activation_hook(__FILE__, 'csd_plugin_activate');

// Initialize the plugin
function csd_manager_init() {
	global $csd_manager;
	$csd_manager = new CSD_Manager();
	
	// Load the user queries class
	require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/user-queries.php');
	new CSD_User_Queries();
}
add_action('plugins_loaded', 'csd_manager_init');

/**
 * Display admin notice if tables don't exist
 */
function csd_admin_tables_notice() {
	if (get_option('csd_show_tables_notice') && current_user_can('manage_options')) {
		?>
		<div class="notice notice-error is-dismissible">
			<p><?php _e('College Sports Directory Manager: Required database tables were not found. Please ensure the tables csd_schools, csd_staff, and csd_school_staff exist in your database.', 'csd-manager'); ?></p>
		</div>
		<?php
	}
}
add_action('admin_notices', 'csd_admin_tables_notice');

/**
 * Dismiss admin notice
 */
function csd_dismiss_tables_notice() {
	if (isset($_GET['csd_dismiss_tables_notice']) && $_GET['csd_dismiss_tables_notice'] == '1' && current_user_can('manage_options')) {
		delete_option('csd_show_tables_notice');
	}
}
add_action('admin_init', 'csd_dismiss_tables_notice');