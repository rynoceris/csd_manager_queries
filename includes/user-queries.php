<?php
/**
 * User Queries Functionality
 *
 * @package College Sports Directory Manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * User Queries Class
 */
class CSD_User_Queries {
	/**
	 * Constructor
	 */
	public function __construct() {
		// Create tables if they don't exist
		add_action('init', array($this, 'check_tables'));
		
		// Register AJAX handlers
		add_action('wp_ajax_csd_assign_query_to_users', array($this, 'ajax_assign_query_to_users'));
		add_action('wp_ajax_csd_get_query_users', array($this, 'ajax_get_query_users'));
		add_action('wp_ajax_csd_remove_query_user', array($this, 'ajax_remove_query_user'));
		
		// Register shortcodes
		add_shortcode('csd_user_queries', array($this, 'user_queries_shortcode'));
		add_shortcode('csd_user_query', array($this, 'user_query_shortcode'));
		
		// Add frontend AJAX handlers
		add_action('wp_ajax_csd_load_user_query', array($this, 'ajax_load_user_query'));
		add_action('wp_ajax_nopriv_csd_load_user_query', array($this, 'ajax_load_user_query'));
	}
	
	/**
	 * Check if user queries tables exist and create them if they don't
	 */
	public function check_tables() {
		$this->create_user_queries_table();
	}
	
	/**
	 * Create user queries table
	 */
	public function create_user_queries_table() {
		$wpdb = csd_db_connection();
		
		$table_name = csd_table('user_queries');
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			query_id mediumint(9) NOT NULL,
			user_id bigint(20) NOT NULL,
			date_assigned datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY query_user (query_id, user_id)
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
	
	/**
	 * AJAX handler for assigning a query to users
	 */
	public function ajax_assign_query_to_users() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$query_id = isset($_POST['query_id']) ? intval($_POST['query_id']) : 0;
		$user_ids = isset($_POST['user_ids']) ? array_map('intval', $_POST['user_ids']) : array();
		
		if (!$query_id) {
			wp_send_json_error(array('message' => __('Invalid query ID.', 'csd-manager')));
			return;
		}
		
		if (empty($user_ids)) {
			wp_send_json_error(array('message' => __('No users selected.', 'csd-manager')));
			return;
		}
		
		$wpdb = csd_db_connection();
		
		// Verify query exists
		$query_exists = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM " . csd_table('saved_queries') . " WHERE id = %d",
			$query_id
		));
		
		if (!$query_exists) {
			wp_send_json_error(array('message' => __('Query not found.', 'csd-manager')));
			return;
		}
		
		$success_count = 0;
		$error_count = 0;
		$current_time = current_time('mysql');
		
		foreach ($user_ids as $user_id) {
			// Check if user exists
			$user = get_user_by('id', $user_id);
			
			if (!$user) {
				$error_count++;
				continue;
			}
			
			// Check if assignment already exists
			$exists = $wpdb->get_var($wpdb->prepare(
				"SELECT COUNT(*) FROM " . csd_table('user_queries') . " WHERE query_id = %d AND user_id = %d",
				$query_id, $user_id
			));
			
			if ($exists) {
				// Update existing assignment
				$result = $wpdb->update(
					csd_table('user_queries'),
					array('date_assigned' => $current_time),
					array('query_id' => $query_id, 'user_id' => $user_id)
				);
			} else {
				// Insert new assignment
				$result = $wpdb->insert(
					csd_table('user_queries'),
					array(
						'query_id' => $query_id,
						'user_id' => $user_id,
						'date_assigned' => $current_time
					)
				);
			}
			
			if ($result !== false) {
				$success_count++;
			} else {
				$error_count++;
			}
		}
		
		wp_send_json_success(array(
			'message' => sprintf(
				__('Query assigned to %d users successfully. %d errors occurred.', 'csd-manager'),
				$success_count, $error_count
			)
		));
	}
	
	/**
	 * AJAX handler for getting users assigned to a query
	 */
	public function ajax_get_query_users() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$query_id = isset($_POST['query_id']) ? intval($_POST['query_id']) : 0;
		
		if (!$query_id) {
			wp_send_json_error(array('message' => __('Invalid query ID.', 'csd-manager')));
			return;
		}
		
		$wpdb = csd_db_connection();
		
		// Get all users assigned to this query
		$assigned_users = $wpdb->get_results($wpdb->prepare(
			"SELECT uq.id as assignment_id, uq.user_id, uq.date_assigned
			FROM " . csd_table('user_queries') . " uq
			WHERE uq.query_id = %d
			ORDER BY uq.date_assigned DESC",
			$query_id
		));
		
		$users = array();
		
		foreach ($assigned_users as $assigned) {
			$user = get_user_by('id', $assigned->user_id);
			
			if ($user) {
				$users[] = array(
					'assignment_id' => $assigned->assignment_id,
					'user_id' => $user->ID,
					'name' => $user->display_name,
					'email' => $user->user_email,
					'date_assigned' => $assigned->date_assigned
				);
			}
		}
		
		wp_send_json_success(array(
			'users' => $users
		));
	}
	
	/**
	 * AJAX handler for removing a query from a user
	 */
	public function ajax_remove_query_user() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$assignment_id = isset($_POST['assignment_id']) ? intval($_POST['assignment_id']) : 0;
		
		if (!$assignment_id) {
			wp_send_json_error(array('message' => __('Invalid assignment ID.', 'csd-manager')));
			return;
		}
		
		$wpdb = csd_db_connection();
		
		// Delete the assignment
		$result = $wpdb->delete(
			csd_table('user_queries'),
			array('id' => $assignment_id)
		);
		
		if ($result === false) {
			wp_send_json_error(array('message' => __('Error removing query assignment.', 'csd-manager')));
			return;
		}
		
		wp_send_json_success(array(
			'message' => __('Query assignment removed successfully.', 'csd-manager')
		));
	}
	
	/**
	 * Get assigned queries for a specific user
	 * 
	 * @param int $user_id User ID
	 * @return array Assigned queries
	 */
	public function get_user_queries($user_id) {
		$wpdb = csd_db_connection();
		
		// Get all queries assigned to this user
		$assigned_queries = $wpdb->get_results($wpdb->prepare(
			"SELECT uq.id as assignment_id, uq.query_id, uq.date_assigned, sq.query_name, sq.query_settings
			FROM " . csd_table('user_queries') . " uq
			JOIN " . csd_table('saved_queries') . " sq ON uq.query_id = sq.id
			WHERE uq.user_id = %d
			ORDER BY sq.query_name ASC",
			$user_id
		));
		
		return $assigned_queries;
	}
	
	/**
	 * Check if a user has access to a specific query
	 * 
	 * @param int $query_id Query ID
	 * @param int $user_id User ID
	 * @return bool True if user has access, false otherwise
	 */
	public function user_has_query_access($query_id, $user_id) {
		if (current_user_can('manage_options')) {
			return true; // Admin always has access
		}
		
		$wpdb = csd_db_connection();
		
		// Check if user has access to this query
		$has_access = $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM " . csd_table('user_queries') . " 
			 WHERE query_id = %d AND user_id = %d",
			$query_id, $user_id
		));
		
		return (bool) $has_access;
	}
	
	/**
	 * Get a single query with details
	 * 
	 * @param int $query_id Query ID
	 * @return object|false Query object or false if not found
	 */
	public function get_query($query_id) {
		$wpdb = csd_db_connection();
		
		// Get query details
		$query = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM " . csd_table('saved_queries') . " WHERE id = %d",
			$query_id
		));
		
		if ($query) {
			$query->settings = json_decode($query->query_settings, true);
		}
		
		return $query;
	}
	
	/**
	 * Shortcode for displaying user's assigned queries
	 * 
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public function user_queries_shortcode($atts) {
		$atts = shortcode_atts(array(
			'title' => __('My Saved Reports', 'csd-manager'),
			'view_page' => '', // ID or URL of the page with the csd_user_query shortcode
		), $atts);
		
		// Check if user is logged in
		if (!is_user_logged_in()) {
			return '<p>' . __('Please log in to view your saved reports.', 'csd-manager') . '</p>';
		}
		
		$user_id = get_current_user_id();
		$queries = $this->get_user_queries($user_id);
		
		// Get the URL of the view page
		$view_page_url = '';
		if (!empty($atts['view_page'])) {
			// If a page ID or URL was provided in the shortcode
			if (is_numeric($atts['view_page'])) {
				$view_page_url = get_permalink(intval($atts['view_page']));
			} else {
				$view_page_url = $atts['view_page'];
			}
		} else {
			// Try to find a page with the user_query shortcode
			$pages = get_pages(array(
				'meta_key' => '_wp_page_template',
				'hierarchical' => 0,
			));
			
			foreach ($pages as $page) {
				if (has_shortcode($page->post_content, 'csd_user_query')) {
					$view_page_url = get_permalink($page->ID);
					break;
				}
			}
		}
		
		ob_start();
		?>
		<div class="csd-user-queries">
			<h2><?php echo esc_html($atts['title']); ?></h2>
			
			<?php if (empty($queries)): ?>
				<p><?php _e('You do not have any saved reports.', 'csd-manager'); ?></p>
			<?php else: ?>
				<ul class="csd-query-list">
					<?php foreach ($queries as $query): ?>
						<li>
							<?php if (!empty($view_page_url)): ?>
								<a href="<?php echo esc_url(add_query_arg('query_id', $query->query_id, $view_page_url)); ?>">
							<?php else: ?>
								<a href="<?php echo esc_url(add_query_arg('query_id', $query->query_id)); ?>">
							<?php endif; ?>
								<?php echo esc_html($query->query_name); ?>
							</a>
							<span class="csd-query-date">
								<?php 
								echo sprintf(
									__('Added: %s', 'csd-manager'), 
									date_i18n(get_option('date_format'), strtotime($query->date_assigned))
								); 
								?>
							</span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</div>
		<?php
		$output = ob_get_clean();
		
		wp_enqueue_style('csd-frontend-styles');
		
		return $output;
	}
	
	/**
	 * Shortcode for displaying results from a specific query
	 * 
	 * @param array $atts Shortcode attributes
	 * @return string HTML output
	 */
	public function user_query_shortcode($atts) {
		$atts = shortcode_atts(array(
			'id' => 0,
			'title' => '',
			'per_page' => 25
		), $atts);
		
		// Check if user is logged in
		if (!is_user_logged_in()) {
			return '<p>' . __('Please log in to view this report.', 'csd-manager') . '</p>';
		}
		
		// Get query ID from URL if not specified in shortcode
		$query_id = $atts['id'];
		if (!$query_id && isset($_GET['query_id'])) {
			$query_id = intval($_GET['query_id']);
		}
		
		if (!$query_id) {
			return '<p>' . __('No report specified.', 'csd-manager') . '</p>';
		}
		
		// Check if user has access to this query
		$user_id = get_current_user_id();
		if (!$this->user_has_query_access($query_id, $user_id)) {
			return '<p>' . __('You do not have access to this report.', 'csd-manager') . '</p>';
		}
		
		// Get query details
		$query = $this->get_query($query_id);
		if (!$query) {
			return '<p>' . __('Report not found.', 'csd-manager') . '</p>';
		}
		
		// Set page title if not specified
		if (empty($atts['title'])) {
			$atts['title'] = $query->query_name;
		}
		
		// Generate unique ID for this instance
		$instance_id = 'csd-query-' . uniqid();
		
		// Enqueue required scripts and styles
		wp_enqueue_style('csd-frontend-styles');
		wp_enqueue_script('jquery');
		
		// Add specific styles for query results
		add_action('wp_head', function() {
			?>
			<style type="text/css">
				.csd-user-query-container {
					margin-bottom: 30px;
				}
				.csd-query-header {
					display: flex;
					justify-content: space-between;
					align-items: center;
					margin-bottom: 20px;
				}
				.csd-query-title {
					margin: 0;
				}
				.csd-query-back {
					margin-bottom: 20px;
				}
				.csd-results-table-wrapper {
					overflow-x: auto;
					max-width: 100%;
					margin-bottom: 20px;
					border: 1px solid #ddd;
					border-radius: 4px;
				}
				.csd-results-table {
					width: 100%;
					border-collapse: collapse;
					margin: 0;
				}
				.csd-results-table th {
					background: #f5f5f5;
					padding: 10px;
					text-align: left;
					border-bottom: 2px solid #ddd;
					position: sticky;
					top: 0;
					z-index: 1;
				}
				.csd-results-table td {
					padding: 10px;
					border-bottom: 1px solid #ddd;
					max-width: 250px;
					overflow: hidden;
					text-overflow: ellipsis;
				}
				.csd-results-table tr:nth-child(even) {
					background-color: #f9f9f9;
				}
				.csd-results-table tr:hover {
					background-color: #f0f0f0;
				}
				.csd-pagination {
					display: flex;
					justify-content: space-between;
					align-items: center;
					margin-top: 15px;
				}
				.csd-pagination-counts {
					color: #666;
				}
				.csd-pagination-links {
					display: flex;
					flex-wrap: wrap;
					align-items: center;
				}
				.csd-pagination-links a, 
				.csd-pagination-links span.csd-page-number {
					margin: 0 2px;
					padding: 5px 10px;
					border: 1px solid #ddd;
					text-decoration: none;
					color: #333;
				}
				.csd-pagination-links span.csd-page-number.current {
					background: #f5f5f5;
					font-weight: bold;
				}
				.csd-pagination-links a:hover {
					background: #f5f5f5;
				}
				.csd-per-page-selector {
					margin-left: 15px;
					display: flex;
					align-items: center;
				}
				.csd-per-page-selector label {
					margin-right: 5px;
				}
				#csd-per-page {
					padding: 5px;
					border: 1px solid #ddd;
				}
				@media screen and (max-width: 768px) {
					.csd-query-header {
						flex-direction: column;
						align-items: flex-start;
					}
					.csd-query-title {
						margin-bottom: 10px;
					}
					.csd-pagination {
						flex-direction: column;
						align-items: flex-start;
					}
					.csd-pagination-counts {
						margin-bottom: 10px;
					}
					.csd-per-page-selector {
						margin-left: 0;
						margin-top: 10px;
					}
				}
			</style>
			<?php
		});
		
		// Generate the output
		ob_start();
		?>
		<div class="csd-user-query-container" id="<?php echo esc_attr($instance_id); ?>">
			<div class="csd-query-back">
				<a href="<?php echo esc_url(remove_query_arg('query_id')); ?>" class="button">&laquo; <?php _e('Back to Reports', 'csd-manager'); ?></a>
			</div>
			
			<div class="csd-query-header">
				<h2 class="csd-query-title"><?php echo esc_html($atts['title']); ?></h2>
				
				<div class="csd-per-page-selector">
					<label for="csd-per-page"><?php _e('Records per page:', 'csd-manager'); ?></label>
					<select id="csd-per-page">
						<?php foreach (array(10, 25, 50, 100) as $option): ?>
							<option value="<?php echo esc_attr($option); ?>" <?php selected($atts['per_page'], $option); ?>><?php echo esc_html($option); ?></option>
						<?php endforeach; ?>
					</select>
				</div>
			</div>
			
			<div class="csd-query-results-container">
				<div id="csd-query-results">
					<div class="csd-loading">
						<?php _e('Loading results...', 'csd-manager'); ?>
					</div>
				</div>
			</div>
		</div>
		
		<script type="text/javascript">
			(function($) {
				$(document).ready(function() {
					var instanceId = '<?php echo esc_js($instance_id); ?>';
					var container = $('#' + instanceId);
					var queryId = <?php echo intval($query_id); ?>;
					var currentPage = 1;
					var perPage = parseInt($('#csd-per-page').val() || <?php echo intval($atts['per_page']); ?>);
					
					// Load results on page load
					loadQueryResults();
					
					// Handle pagination clicks
					$(document).on('click', '.csd-page-number', function(e) {
						e.preventDefault();
						currentPage = parseInt($(this).data('page'));
						loadQueryResults();
						
						// Scroll back to top of results
						$('html, body').animate({
							scrollTop: container.offset().top - 50
						}, 500);
					});
					
					// Handle per-page changes
					$('#csd-per-page').on('change', function() {
						perPage = parseInt($(this).val());
						currentPage = 1; // Reset to first page
						loadQueryResults();
					});
					
					// Load query results function
					function loadQueryResults() {
						$('#csd-query-results').html('<div class="csd-loading"><?php _e('Loading results...', 'csd-manager'); ?></div>');
						
						$.ajax({
							url: '<?php echo admin_url('admin-ajax.php'); ?>',
							type: 'POST',
							data: {
								action: 'csd_load_user_query',
								query_id: queryId,
								page: currentPage,
								per_page: perPage,
								nonce: '<?php echo wp_create_nonce('csd-ajax-nonce'); ?>'
							},
							success: function(response) {
								if (response.success) {
									$('#csd-query-results').html(response.data.html);
								} else {
									$('#csd-query-results').html('<div class="notice notice-error"><p>' + 
										(response.data.message || '<?php _e('Error loading results.', 'csd-manager'); ?>') + 
									'</p></div>');
								}
							},
							error: function() {
								$('#csd-query-results').html('<div class="notice notice-error"><p><?php 
									_e('Error connecting to server. Please try again.', 'csd-manager'); 
								?></p></div>');
							}
						});
					}
				});
			})(jQuery);
		</script>
		<?php
		return ob_get_clean();
	}
	
	/**
	 * AJAX handler for loading user query results
	 */
	public function ajax_load_user_query() {
		try {
			error_log('ajax_load_user_query method started');
			
			// Check nonce
			if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
				error_log('Nonce verification failed');
				wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
				return;
			}
			
			// Get parameters
			$query_id = isset($_POST['query_id']) ? intval($_POST['query_id']) : 0;
			$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
			$per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 25;
			
			error_log('Parameters: query_id=' . $query_id . ', page=' . $page . ', per_page=' . $per_page);
			
			if (!$query_id) {
				error_log('Invalid query ID');
				wp_send_json_error(array('message' => __('Invalid query ID.', 'csd-manager')));
				return;
			}
			
			// Check if user has access to this query
			$user_id = get_current_user_id();
			error_log('Checking access for user_id=' . $user_id);
			
			if (!$this->user_has_query_access($query_id, $user_id)) {
				error_log('User does not have access to this query');
				wp_send_json_error(array('message' => __('You do not have access to this report.', 'csd-manager')));
				return;
			}
			
			// Get query details
			error_log('Getting query details');
			$query = $this->get_query($query_id);
			
			if (!$query) {
				error_log('Query not found');
				wp_send_json_error(array('message' => __('Report not found.', 'csd-manager')));
				return;
			}
			
			error_log('Query found: ' . print_r($query, true));
			
			// Parse query settings
			$settings = json_decode($query->query_settings, true);
			error_log('Query settings parsed');
			
			// Check if Query Builder class is available
			if (!file_exists(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php')) {
				error_log('Query Builder file not found');
				wp_send_json_error(array('message' => __('Required component not found.', 'csd-manager')));
				return;
			}
			
			// Include query builder functionality
			require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
			
			if (!class_exists('CSD_Query_Builder')) {
				error_log('CSD_Query_Builder class not found');
				wp_send_json_error(array('message' => __('Required class not found.', 'csd-manager')));
				return;
			}
			
			$query_builder = new CSD_Query_Builder();
			error_log('Query Builder instantiated');
			
			// Get database connection
			$wpdb = csd_db_connection();
			
			if (!$wpdb) {
				error_log('Database connection failed');
				wp_send_json_error(array('message' => __('Database connection failed.', 'csd-manager')));
				return;
			}
			
			error_log('Database connection established');
			
			try {
				// Build SQL query with pagination
				if (isset($settings['custom_sql']) && !empty($settings['custom_sql'])) {
					// Use custom SQL
					$sql = $settings['custom_sql'];
					error_log('Using custom SQL: ' . $sql);
					
					// Add pagination if needed
					if (strpos(strtoupper($sql), 'LIMIT') === false) {
						$offset = ($page - 1) * $per_page;
						$sql .= " LIMIT {$per_page} OFFSET {$offset}";
						error_log('Added pagination to SQL: ' . $sql);
					}
					
					// Run the query
					error_log('Executing query');
					$results = $wpdb->get_results($sql, ARRAY_A);
					
					if ($wpdb->last_error) {
						error_log('Database error: ' . $wpdb->last_error);
						throw new Exception('Database error: ' . $wpdb->last_error);
					}
					
					error_log('Query executed, found ' . count($results) . ' results');
					
					// Count total number of records
					$count_sql = preg_replace('/^\s*SELECT\s+.+?\s+FROM\s+/is', 'SELECT COUNT(*) as total_count FROM ', $sql);
					$count_sql = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $count_sql);
					$count_sql = preg_replace('/\s+LIMIT\s+\d+(?:\s*,\s*\d+)?$/is', '', $count_sql);
					$count_sql = preg_replace('/\s+OFFSET\s+\d+$/is', '', $count_sql);
					
					error_log('Count SQL: ' . $count_sql);
					$total_count = $wpdb->get_var($count_sql);
					
					if ($wpdb->last_error) {
						error_log('Count query error: ' . $wpdb->last_error);
						throw new Exception('Count query error: ' . $wpdb->last_error);
					}
					
					error_log('Total count: ' . $total_count);
				} else {
					// Build form-based query
					error_log('Building form-based query');
					
					// Ensure the query builder has the necessary methods
					if (!method_exists($query_builder, 'build_count_sql_query')) {
						error_log('build_count_sql_query method not found');
						throw new Exception('Required method not found: build_count_sql_query');
					}
					
					// Build count query first to get total
					$count_sql = $query_builder->build_count_sql_query($settings);
					error_log('Count SQL built: ' . $count_sql);
					
					if (!method_exists($query_builder, 'get_query_count')) {
						error_log('get_query_count method not found');
						throw new Exception('Required method not found: get_query_count');
					}
					
					$total_count = $query_builder->get_query_count($count_sql);
					error_log('Total count: ' . $total_count);
					
					// Then build and run the paginated query
					if (!method_exists($query_builder, 'build_sql_query')) {
						error_log('build_sql_query method not found');
						throw new Exception('Required method not found: build_sql_query');
					}
					
					$sql = $query_builder->build_sql_query($settings, true, $page, $per_page);
					error_log('SQL built: ' . $sql);
					
					if (!method_exists($query_builder, 'execute_query')) {
						error_log('execute_query method not found');
						throw new Exception('Required method not found: execute_query');
					}
					
					$results = $query_builder->execute_query($sql);
					error_log('Query executed, found ' . count($results) . ' results');
				}
				
				// Generate HTML output
				error_log('Generating HTML output');
				$html = $this->generate_results_html($results, $page, $per_page, $total_count);
				
				// Send response
				error_log('Sending success response');
				wp_send_json_success(array(
					'html' => $html,
					'count' => $total_count,
					'page' => $page,
					'per_page' => $per_page
				));
				
			} catch (Exception $e) {
				error_log('Error in query execution: ' . $e->getMessage());
				wp_send_json_error(array('message' => $e->getMessage()));
			}
		} catch (Exception $e) {
			error_log('Exception in ajax_load_user_query: ' . $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage()));
		} catch (Error $e) {
			error_log('PHP Error in ajax_load_user_query: ' . $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}
	
	/**
	 * Generate HTML for query results
	 * 
	 * @param array $results Query results
	 * @param int $current_page Current page
	 * @param int $per_page Records per page
	 * @param int $total_count Total records count
	 * @return string HTML
	 */
	private function generate_results_html($results, $current_page, $per_page, $total_count) {
		$html = '';
		
		if (empty($results)) {
			$html = '<div class="notice notice-warning"><p>' . __('No results found.', 'csd-manager') . '</p></div>';
		} else {
			// Define excluded columns (fields that should never be displayed)
			$excluded_columns = array(
				'schools_id', 'schools_date_created', 'schools_date_updated',
				'staff_id', 'staff_date_created', 'staff_date_updated',
				'school_staff_id', 'school_staff_school_id', 'school_staff_staff_id', 'school_staff_date_created'
			);
			
			// Define column name mapping for better display
			$column_mapping = array(
				'schools_school_name' => 'School Name',
				'schools_street_address_line_1' => 'Address Line 1',
				'schools_street_address_line_2' => 'Address Line 2',
				'schools_street_address_line_3' => 'Address Line 3',
				'schools_city' => 'City',
				'schools_state' => 'State',
				'schools_zipcode' => 'Zipcode',
				'schools_country' => 'Country',
				'schools_county' => 'County',
				'schools_school_divisions' => 'Divisions',
				'schools_school_conferences' => 'Conferences',
				'schools_school_level' => 'School Level',
				'schools_school_type' => 'School Type',
				'schools_school_enrollment' => 'Estimated Enrollment',
				'schools_mascot' => 'Nickname/Mascot',
				'schools_school_colors' => 'School Colors',
				'schools_school_website' => 'School Website',
				'schools_athletics_website' => 'Athletics Website',
				'schools_athletics_phone' => 'Athletics Phone',
				'schools_football_division' => 'Football Division',
				'staff_full_name' => 'Full Name',
				'staff_title' => 'Title',
				'staff_sport_department' => 'Sport/Department',
				'staff_email' => 'Email',
				'staff_phone' => 'Phone'
			);
			
			// Filter results to remove excluded columns
			$filtered_results = array();
			foreach ($results as $row) {
				$filtered_row = array();
				foreach ($row as $key => $value) {
					if (!in_array($key, $excluded_columns)) {
						$filtered_row[$key] = $value;
					}
				}
				$filtered_results[] = $filtered_row;
			}
			
			// Only proceed if there are columns to display after filtering
			if (!empty($filtered_results) && !empty($filtered_results[0])) {
				$html .= '<div class="csd-results-table-wrapper">';
				$html .= '<table class="csd-results-table">';
				
				// Table headers
				$html .= '<thead><tr>';
				foreach (array_keys($filtered_results[0]) as $column) {
					// Use the mapped column name if available, otherwise format the original
					if (isset($column_mapping[$column])) {
						$label = $column_mapping[$column];
					} else {
						// Try to make the column header more readable
						$label = str_replace('_', ' ', $column);
						$label = ucwords($label);
					}
					
					$html .= '<th>' . esc_html($label) . '</th>';
				}
				$html .= '</tr></thead>';
				
				// Table body
				$html .= '<tbody>';
				foreach ($filtered_results as $row) {
					$html .= '<tr>';
					foreach ($row as $key => $value) {
						// Format the value for display with appropriate CSS classes
						$display_value = $this->format_value_for_display($key, $value);
						$html .= '<td>' . $display_value . '</td>';
					}
					$html .= '</tr>';
				}
				$html .= '</tbody>';
				$html .= '</table>';
				$html .= '</div>';
				
				// Add pagination controls
				$total_pages = ceil($total_count / $per_page);
				if ($total_pages > 1) {
					$html .= '<div class="csd-pagination">';
					
					// Showing records info
					$start = (($current_page - 1) * $per_page) + 1;
					$end = min($start + count($results) - 1, $total_count);
					
					$html .= '<div class="csd-pagination-counts">';
					$html .= '<span class="csd-showing-records">' . sprintf(__('Showing %d to %d of %d records', 'csd-manager'), $start, $end, $total_count) . '</span>';
					$html .= '</div>';
					
					// Page links
					$html .= '<div class="csd-pagination-links">';
					
					// Previous button
					if ($current_page > 1) {
						$html .= '<a href="#" class="csd-page-number" data-page="' . ($current_page - 1) . '">&laquo; ' . __('Previous', 'csd-manager') . '</a> ';
					}
					
					// Page numbers
					$start_page = max(1, $current_page - 2);
					$end_page = min($total_pages, $start_page + 4);
					
					if ($start_page > 1) {
						$html .= '<a href="#" class="csd-page-number" data-page="1">1</a> ';
						if ($start_page > 2) {
							$html .= '<span class="csd-pagination-dots">...</span> ';
						}
					}
					
					for ($i = $start_page; $i <= $end_page; $i++) {
						if ($i === $current_page) {
							$html .= '<span class="csd-page-number current">' . $i . '</span> ';
						} else {
							$html .= '<a href="#" class="csd-page-number" data-page="' . $i . '">' . $i . '</a> ';
						}
					}
					
					if ($end_page < $total_pages) {
						if ($end_page < $total_pages - 1) {
							$html .= '<span class="csd-pagination-dots">...</span> ';
						}
						$html .= '<a href="#" class="csd-page-number" data-page="' . $total_pages . '">' . $total_pages . '</a> ';
					}
					
					// Next button
					if ($current_page < $total_pages) {
						$html .= '<a href="#" class="csd-page-number" data-page="' . ($current_page + 1) . '">' . __('Next', 'csd-manager') . ' &raquo;</a>';
					}
					
					$html .= '</div>'; // End pagination links
					
					$html .= '</div>'; // End pagination container
				}
			} else {
				$html = '<div class="notice notice-warning"><p>' . __('No displayable columns found after filtering.', 'csd-manager') . '</p></div>';
			}
		}
		
		return $html;
	}
	
	/**
	 * Format a value for display in results table
	 * 
	 * @param string $key Column key
	 * @param mixed $value Value to format
	 * @return string Formatted value
	 */
	private function format_value_for_display($key, $value) {
		// Handle null/empty values
		if ($value === null || $value === '') {
			return '<span class="csd-empty-value">&mdash;</span>';
		}
		
		// Format dates
		if (strpos($key, 'date_') !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
			return '<span class="csd-date">' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($value)) . '</span>';
		}
		
		// Format URLs
		if (strpos($key, 'website') !== false || strpos($key, 'url') !== false) {
			if (filter_var($value, FILTER_VALIDATE_URL)) {
				return '<a href="' . esc_url($value) . '" target="_blank" class="csd-url">' . esc_html($value) . '</a>';
			}
		}
		
		// Format email
		if (strpos($key, 'email') !== false) {
			// Check if the email contains @placeholder and return blank if true
			if (strpos($value, '@placeholder') !== false) {
				return '<span class="csd-empty-value">&mdash;</span>';
			}
			
			if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
				return '<a href="mailto:' . esc_attr($value) . '" class="csd-email">' . esc_html($value) . '</a>';
			}
		}
		
		// Format numbers
		if (is_numeric($value) && strpos($key, 'id') === false) {
			return '<span class="csd-number">' . number_format($value) . '</span>';
		}
		
		// Default formatting
		return '<span class="csd-text">' . esc_html($value) . '</span>';
	}
}

/**
 * Add specific styles for user query results
 */
function csd_add_user_query_styles() {
	// Only load on pages with our shortcode
	global $post;
	if (is_a($post, 'WP_Post') && 
		(has_shortcode($post->post_content, 'csd_user_queries') || 
		 has_shortcode($post->post_content, 'csd_user_query'))) {
		
		wp_enqueue_style('csd-user-queries-styles');
		
		// Add additional inline styles
		$custom_css = "
			/* Query results container */
			.csd-user-query-container {
				margin-bottom: 40px;
				font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen-Sans, Ubuntu, Cantarell, 'Helvetica Neue', sans-serif;
			}
			
			/* Navigation and controls */
			.csd-query-back {
				margin-bottom: 20px;
			}
			
			.csd-query-back a {
				display: inline-block;
				padding: 8px 12px;
				background-color: #f8f8f8;
				border: 1px solid #ddd;
				border-radius: 4px;
				text-decoration: none;
				color: #333;
				font-weight: 500;
				transition: all 0.2s ease;
			}
			
			.csd-query-back a:hover {
				background-color: #f0f0f0;
				border-color: #ccc;
			}
			
			.csd-query-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 20px;
				flex-wrap: wrap;
			}
			
			.csd-query-title {
				margin: 0 0 10px 0;
				font-size: 1.8em;
				color: #333;
				flex: 1 0 100%;
			}
			
			/* Table styles */
			.csd-results-table-wrapper {
				overflow-x: auto;
				width: 100%;
				margin-bottom: 20px;
				background: #fff;
				border: 1px solid #e0e0e0;
				border-radius: 6px;
				box-shadow: 0 2px 4px rgba(0,0,0,0.05);
			}
			
			.csd-results-table {
				width: 100%;
				border-collapse: collapse;
				min-width: 650px; /* Ensures mobile scrolling for better readability */
			}
			
			.csd-results-table th {
				background-color: #f8f8f8;
				padding: 12px 15px;
				text-align: left;
				font-weight: 600;
				color: #333;
				border-bottom: 2px solid #e0e0e0;
				position: sticky;
				top: 0;
				z-index: 10;
				white-space: nowrap;
			}
			
			.csd-results-table td {
				padding: 10px 15px;
				border-bottom: 1px solid #e0e0e0;
				color: #555;
				max-width: 300px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			
			.csd-results-table tr:last-child td {
				border-bottom: none;
			}
			
			.csd-results-table tr:nth-child(even) {
				background-color: #f9f9f9;
			}
			
			.csd-results-table tr:hover {
				background-color: #f5f5f5;
			}
			
			/* Make cells expand on hover for better readability */
			.csd-results-table td:hover {
				white-space: normal;
				max-width: none;
				background-color: #f0f0f0;
				position: relative;
				z-index: 5;
				box-shadow: 0 2px 6px rgba(0,0,0,0.1);
				transition: all 0.2s ease;
			}
			
			/* Format specific data types */
			.csd-email {
				text-decoration: none;
				color: #0073aa;
			}
			
			.csd-email:hover {
				text-decoration: underline;
			}
			
			.csd-date {
				white-space: nowrap;
				color: #666;
			}
			
			/* Pagination controls */
			.csd-pagination {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin: 20px 0;
				flex-wrap: wrap;
				gap: 15px;
			}
			
			.csd-pagination-counts {
				color: #666;
				font-size: 0.9em;
			}
			
			.csd-pagination-links {
				display: flex;
				flex-wrap: wrap;
				gap: 5px;
			}
			
			.csd-page-number {
				display: inline-flex;
				align-items: center;
				justify-content: center;
				min-width: 32px;
				height: 32px;
				padding: 0 10px;
				background: #fff;
				border: 1px solid #ddd;
				border-radius: 4px;
				text-decoration: none;
				color: #333;
				font-weight: 500;
				transition: all 0.2s ease;
			}
			
			.csd-page-number.current {
				background: #0073aa;
				border-color: #0073aa;
				color: #fff;
			}
			
			.csd-page-number:hover:not(.current) {
				background: #f0f0f0;
				border-color: #ccc;
			}
			
			.csd-per-page-selector {
				display: flex;
				align-items: center;
				gap: 8px;
			}
			
			.csd-per-page-selector label {
				font-size: 0.9em;
				color: #666;
			}
			
			#csd-per-page {
				padding: 6px 10px;
				border: 1px solid #ddd;
				border-radius: 4px;
				background: #fff;
			}
			
			/* Notice styling */
			.notice {
				padding: 10px 15px;
				border-radius: 4px;
				margin-bottom: 20px;
			}
			
			.notice-success {
				background-color: #f0f9e8;
				border-left: 4px solid #7ad03a;
				color: #3c763d;
			}
			
			.notice-error {
				background-color: #fbeaea;
				border-left: 4px solid #dc3232;
				color: #a94442;
			}
			
			.notice p {
				margin: 0.5em 0;
			}
			
			/* Loading indicator */
			.csd-loading {
				text-align: center;
				padding: 20px;
				color: #666;
				font-style: italic;
			}
			
			/* User queries list styling */
			.csd-user-queries {
				margin-bottom: 40px;
			}
			
			.csd-query-list {
				list-style: none;
				padding: 0;
				margin: 0;
			}
			
			.csd-query-list li {
				padding: 15px;
				border: 1px solid #e0e0e0;
				border-radius: 6px;
				margin-bottom: 10px;
				background: #fff;
				box-shadow: 0 2px 4px rgba(0,0,0,0.05);
				display: flex;
				justify-content: space-between;
				align-items: center;
				flex-wrap: wrap;
				transition: all 0.2s ease;
			}
			
			.csd-query-list li:hover {
				background: #f8f8f8;
				box-shadow: 0 3px 6px rgba(0,0,0,0.1);
				transform: translateY(-2px);
			}
			
			.csd-query-list a {
				color: #0073aa;
				font-weight: 600;
				text-decoration: none;
				font-size: 1.1em;
				transition: color 0.2s ease;
			}
			
			.csd-query-list a:hover {
				color: #00a0d2;
				text-decoration: underline;
			}
			
			.csd-query-date {
				color: #666;
				font-size: 0.9em;
				font-style: italic;
			}
			
			/* Responsive adjustments */
			@media screen and (max-width: 782px) {
				.csd-query-header {
					flex-direction: column;
					align-items: flex-start;
				}
				
				.csd-pagination {
					flex-direction: column;
					align-items: flex-start;
				}
				
				.csd-per-page-selector {
					margin-top: 10px;
				}
				
				.csd-query-list li {
					padding: 12px;
				}
				
				.csd-query-list a {
					margin-bottom: 5px;
					display: block;
					width: 100%;
				}
				
				.csd-query-date {
					display: block;
					width: 100%;
				}
			}
		";
		
		wp_add_inline_style('csd-user-queries-styles', $custom_css);
	}
}
add_action('wp_enqueue_scripts', 'csd_add_user_query_styles', 99);