<?php
/**
 * Query Change Detection and Monitoring System
 *
 * @package College Sports Directory Manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Query Change Detection Class
 */
class CSD_Query_Change_Detection {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		// Hook into WordPress cron system
		add_action('init', array($this, 'schedule_weekly_monitoring'));
		add_action('csd_weekly_query_monitoring', array($this, 'run_weekly_monitoring'));
		
		// Admin interface hooks
		add_action('admin_menu', array($this, 'add_monitoring_page'));
		add_action('wp_ajax_csd_toggle_query_monitoring', array($this, 'ajax_toggle_monitoring'));
		add_action('wp_ajax_csd_run_manual_monitoring', array($this, 'ajax_run_manual_monitoring'));
		add_action('wp_ajax_csd_get_monitoring_history', array($this, 'ajax_get_monitoring_history'));
		
		// Create tables on activation
		register_activation_hook(CSD_MANAGER_PLUGIN_DIR . 'college-sports-directory-manager.php', array($this, 'create_monitoring_tables'));
	}
	
	/**
	 * Create monitoring tables
	 */
	public function create_monitoring_tables() {
		$wpdb = csd_db_connection();
		$charset_collate = $wpdb->get_charset_collate();
		
		// Table to store query snapshots
		$table_snapshots = csd_table('query_snapshots');
		$sql_snapshots = "CREATE TABLE $table_snapshots (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			query_id mediumint(9) NOT NULL,
			snapshot_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			data_hash varchar(64) NOT NULL,
			record_count int NOT NULL,
			snapshot_data longtext NOT NULL,
			PRIMARY KEY (id),
			KEY query_date_idx (query_id, snapshot_date),
			KEY query_hash_idx (query_id, data_hash)
		) $charset_collate;";
		
		// Table to store change detection results
		$table_changes = csd_table('query_changes');
		$sql_changes = "CREATE TABLE $table_changes (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			query_id mediumint(9) NOT NULL,
			previous_snapshot_id mediumint(9),
			current_snapshot_id mediumint(9) NOT NULL,
			change_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			changes_detected int NOT NULL DEFAULT 0,
			new_records int NOT NULL DEFAULT 0,
			modified_records int NOT NULL DEFAULT 0,
			deleted_records int NOT NULL DEFAULT 0,
			change_summary longtext,
			notification_sent tinyint(1) DEFAULT 0,
			PRIMARY KEY (id),
			KEY query_date_idx (query_id, change_date),
			KEY notification_idx (notification_sent)
		) $charset_collate;";
		
		// Table to store monitoring settings per query
		$table_monitoring = csd_table('query_monitoring');
		$sql_monitoring = "CREATE TABLE $table_monitoring (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			query_id mediumint(9) NOT NULL UNIQUE,
			monitoring_enabled tinyint(1) DEFAULT 1,
			last_run datetime,
			next_run datetime,
			email_notifications tinyint(1) DEFAULT 1,
			created_date datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY (id),
			KEY next_run_idx (next_run, monitoring_enabled)
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql_snapshots);
		dbDelta($sql_changes);
		dbDelta($sql_monitoring);
	}
	
	/**
	 * Schedule weekly monitoring
	 */
	public function schedule_weekly_monitoring() {
		if (!wp_next_scheduled('csd_weekly_query_monitoring')) {
			// Schedule to run every Sunday at 2 AM
			wp_schedule_event(strtotime('next Sunday 2:00 AM'), 'weekly', 'csd_weekly_query_monitoring');
		}
	}
	
	/**
	 * Run weekly monitoring for all enabled queries
	 */
	public function run_weekly_monitoring() {
		error_log('CSD Query Monitoring: Starting weekly monitoring run');
		
		$wpdb = csd_db_connection();
		
		// Get all queries that have monitoring enabled and users assigned
		// SIMPLIFIED QUERY - let's debug what we're actually getting
		$queries_to_monitor = $wpdb->get_results("
			SELECT DISTINCT sq.id as query_id, sq.query_name, sq.query_settings
			FROM " . csd_table('saved_queries') . " sq
			JOIN " . csd_table('user_queries') . " uq ON sq.id = uq.query_id
			LEFT JOIN " . csd_table('query_monitoring') . " qm ON sq.id = qm.query_id
			WHERE (qm.monitoring_enabled = 1 OR qm.monitoring_enabled IS NULL)
		");
		
		error_log('CSD Query Monitoring: Raw query result count: ' . count($queries_to_monitor));
		
		// Debug: Log each query found
		foreach ($queries_to_monitor as $query_info) {
			error_log('CSD Query Monitoring: Found query ID ' . $query_info->query_id . ' (' . $query_info->query_name . ')');
			
			// Check monitoring settings for this query
			$monitoring_settings = $wpdb->get_row($wpdb->prepare("
				SELECT * FROM " . csd_table('query_monitoring') . " WHERE query_id = %d
			", $query_info->query_id));
			
			if ($monitoring_settings) {
				error_log('CSD Query Monitoring: Query ' . $query_info->query_id . ' monitoring settings - enabled: ' . $monitoring_settings->monitoring_enabled . ', last_run: ' . $monitoring_settings->last_run . ', next_run: ' . $monitoring_settings->next_run);
			} else {
				error_log('CSD Query Monitoring: Query ' . $query_info->query_id . ' has no monitoring settings (will use defaults)');
			}
		}
		
		error_log('CSD Query Monitoring: Found ' . count($queries_to_monitor) . ' queries to monitor');
		
		foreach ($queries_to_monitor as $query_info) {
			try {
				// Add email notifications flag for processing
				$query_info->email_notifications = 1; // Default to enabled for now
				
				error_log('CSD Query Monitoring: Processing query ' . $query_info->query_id . ' (' . $query_info->query_name . ')');
				$this->process_single_query_monitoring($query_info);
			} catch (Exception $e) {
				error_log('CSD Query Monitoring Error for query ' . $query_info->query_id . ': ' . $e->getMessage());
			}
		}
		
		error_log('CSD Query Monitoring: Weekly monitoring run completed');
	}
	
	/**
	 * Process monitoring for a single query
	 */
	private function process_single_query_monitoring($query_info) {
		$wpdb = csd_db_connection();
		$query_id = $query_info->query_id;
		
		error_log('CSD Query Monitoring: Processing query ' . $query_id . ' (' . $query_info->query_name . ')');
		
		// Execute the query and get current data
		try {
			$current_data = $this->execute_saved_query($query_info->query_settings);
		} catch (Exception $e) {
			error_log('CSD Query Monitoring: Failed to execute query ' . $query_id . ': ' . $e->getMessage());
			$this->update_monitoring_schedule($query_id);
			return;
		}
		
		if (empty($current_data)) {
			error_log('CSD Query Monitoring: No data returned for query ' . $query_id);
			$this->update_monitoring_schedule($query_id);
			return;
		}
		
		error_log('CSD Query Monitoring: Query ' . $query_id . ' returned ' . count($current_data) . ' records');
		
		// Create snapshot of current data
		$current_snapshot_id = $this->create_snapshot($query_id, $current_data);
		error_log('CSD Query Monitoring: Created snapshot ' . $current_snapshot_id . ' for query ' . $query_id);
		
		// Get the most recent previous snapshot
		$previous_snapshot = $wpdb->get_row($wpdb->prepare("
			SELECT id, data_hash, snapshot_data, record_count
			FROM " . csd_table('query_snapshots') . "
			WHERE query_id = %d AND id != %d
			ORDER BY snapshot_date DESC
			LIMIT 1
		", $query_id, $current_snapshot_id));
		
		if (!$previous_snapshot) {
			error_log('CSD Query Monitoring: No previous snapshot found for query ' . $query_id . ', creating baseline');
			
			// Create a baseline change record showing this is the first snapshot
			$this->store_change_results($query_id, null, $current_snapshot_id, array(
				'total_changes' => 0,
				'new_records' => array(),
				'modified_records' => array(),
				'deleted_records' => array(),
				'new_count' => 0,
				'modified_count' => 0,
				'deleted_count' => 0,
				'previous_total' => 0,
				'current_total' => count($current_data)
			));
			
			$this->update_monitoring_schedule($query_id);
			return;
		}
		
		error_log('CSD Query Monitoring: Comparing against previous snapshot ' . $previous_snapshot->id);
		
		// Compare data and detect changes
		$changes = $this->detect_changes($previous_snapshot, $current_data, $query_id);
		
		error_log('CSD Query Monitoring: Detected ' . $changes['total_changes'] . ' total changes (New: ' . $changes['new_count'] . ', Modified: ' . $changes['modified_count'] . ', Deleted: ' . $changes['deleted_count'] . ')');
		
		// Store change detection results
		$change_record_id = $this->store_change_results($query_id, $previous_snapshot->id, $current_snapshot_id, $changes);
		
		// Send notifications if changes were detected and email notifications are enabled
		$email_notifications = isset($query_info->email_notifications) ? $query_info->email_notifications : 1;
		if ($changes['total_changes'] > 0 && $email_notifications) {
			error_log('CSD Query Monitoring: Sending email notifications for query ' . $query_id);
			$this->send_change_notifications($query_id, $query_info->query_name, $changes, $current_data, $change_record_id);
		} else {
			error_log('CSD Query Monitoring: No email notifications needed (changes: ' . $changes['total_changes'] . ', email enabled: ' . $email_notifications . ')');
		}
		
		// Update monitoring schedule
		$this->update_monitoring_schedule($query_id);
		
		error_log('CSD Query Monitoring: Completed processing for query ' . $query_id . ' - ' . $changes['total_changes'] . ' changes detected');
	}
	
	/**
	 * Execute a saved query and return the data
	 */
	private function execute_saved_query($query_settings) {
		$settings = json_decode($query_settings, true);
		
		if (!$settings) {
			throw new Exception('Invalid query settings');
		}
		
		// Include the Query Builder class
		require_once(CSD_MANAGER_PLUGIN_DIR . 'includes/query-builder.php');
		$query_builder = new CSD_Query_Builder();
		
		try {
			if (isset($settings['custom_sql']) && !empty($settings['custom_sql'])) {
				// Execute custom SQL
				$sql = $settings['custom_sql'];
				// Remove any LIMIT clauses to get all data
				$sql = $this->remove_pagination_from_sql($sql);
			} else {
				// For form-based queries, temporarily remove limit and build SQL without pagination
				$original_limit = isset($settings['limit']) ? $settings['limit'] : null;
				unset($settings['limit']); // Remove limit from settings
				
				// Build SQL from form settings without pagination
				$sql = $query_builder->build_sql_query($settings, false); // No pagination
				
				// Restore original limit for future use (don't modify the original settings)
				if ($original_limit !== null) {
					$settings['limit'] = $original_limit;
				}
			}
			
			error_log('CSD Query Monitoring: Executing SQL for monitoring: ' . $sql);
			
			$results = $query_builder->execute_query($sql);
			
			error_log('CSD Query Monitoring: Query returned ' . count($results) . ' records');
			
			return $results;
			
		} catch (Exception $e) {
			error_log('CSD Query Monitoring: Error executing query - ' . $e->getMessage());
			throw $e;
		}
	}
	
	/**
	 * Remove pagination from SQL query to get all records - IMPROVED VERSION
	 * 
	 * @param string $sql SQL query
	 * @return string SQL query without LIMIT clause
	 */
	private function remove_pagination_from_sql($sql) {
		// Trim any trailing whitespace
		$sql = trim($sql);
		
		// Remove LIMIT clauses - handle multiple formats more thoroughly
		// Pattern 1: LIMIT number
		$sql = preg_replace('/\s+LIMIT\s+\d+\s*$/i', '', $sql);
		
		// Pattern 2: LIMIT offset, count
		$sql = preg_replace('/\s+LIMIT\s+\d+\s*,\s*\d+\s*$/i', '', $sql);
		
		// Pattern 3: LIMIT count OFFSET offset
		$sql = preg_replace('/\s+LIMIT\s+\d+\s+OFFSET\s+\d+\s*$/i', '', $sql);
		
		// Pattern 4: Handle any remaining LIMIT variations (more aggressive)
		$sql = preg_replace('/\s+LIMIT\s+[^;]*$/i', '', $sql);
		
		// Trim again after removals
		$sql = trim($sql);
		
		// Remove trailing semicolon if present
		$sql = rtrim($sql, ';');
		
		return $sql;
	}
	
	/**
	 * Create a snapshot of query data
	 */
	private function create_snapshot($query_id, $data) {
		$wpdb = csd_db_connection();
		
		// Create a hash of the data for quick comparison
		$data_hash = md5(serialize($data));
		$record_count = count($data);
		
		// Store the snapshot
		$result = $wpdb->insert(
			csd_table('query_snapshots'),
			array(
				'query_id' => $query_id,
				'snapshot_date' => current_time('mysql'),
				'data_hash' => $data_hash,
				'record_count' => $record_count,
				'snapshot_data' => json_encode($data)
			)
		);
		
		if ($result === false) {
			throw new Exception('Failed to create snapshot: ' . $wpdb->last_error);
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Detect changes between previous and current data
	 */
	private function detect_changes($previous_snapshot, $current_data, $query_id) {
		$previous_data = json_decode($previous_snapshot->snapshot_data, true);
		
		// Create lookup arrays for efficient comparison
		$previous_lookup = array();
		$current_lookup = array();
		
		// Build lookup arrays using a composite key of all non-date fields
		foreach ($previous_data as $row) {
			$key = $this->create_record_key($row);
			$previous_lookup[$key] = $row;
		}
		
		foreach ($current_data as $row) {
			$key = $this->create_record_key($row);
			$current_lookup[$key] = $row;
		}
		
		// Detect changes
		$new_records = array();
		$modified_records = array();
		$deleted_records = array();
		
		// Find new and modified records
		foreach ($current_lookup as $key => $current_row) {
			if (!isset($previous_lookup[$key])) {
				$new_records[] = $current_row;
			} else {
				// Check if the record has been modified (including date fields)
				if (serialize($current_row) !== serialize($previous_lookup[$key])) {
					$modified_records[] = array(
						'previous' => $previous_lookup[$key],
						'current' => $current_row,
						'changes' => $this->identify_field_changes($previous_lookup[$key], $current_row)
					);
				}
			}
		}
		
		// Find deleted records
		foreach ($previous_lookup as $key => $previous_row) {
			if (!isset($current_lookup[$key])) {
				$deleted_records[] = $previous_row;
			}
		}
		
		$total_changes = count($new_records) + count($modified_records) + count($deleted_records);
		
		return array(
			'total_changes' => $total_changes,
			'new_records' => $new_records,
			'modified_records' => $modified_records,
			'deleted_records' => $deleted_records,
			'new_count' => count($new_records),
			'modified_count' => count($modified_records),
			'deleted_count' => count($deleted_records),
			'previous_total' => count($previous_data),
			'current_total' => count($current_data)
		);
	}
	
	/**
	 * Create a unique key for a record based on identifying fields
	 */
	private function create_record_key($row) {
		// Use ID fields if available, otherwise use all non-date fields
		$key_fields = array();
		
		foreach ($row as $field => $value) {
			// Skip date fields for key creation as they might change
			if (strpos($field, 'date_') === false && 
				strpos($field, '_date') === false && 
				strpos($field, 'created') === false && 
				strpos($field, 'updated') === false) {
				$key_fields[] = $value;
			}
		}
		
		return md5(implode('|', $key_fields));
	}
	
	/**
	 * Identify specific field changes between two records
	 */
	private function identify_field_changes($previous, $current) {
		$changes = array();
		
		foreach ($current as $field => $current_value) {
			$previous_value = isset($previous[$field]) ? $previous[$field] : null;
			
			if ($previous_value !== $current_value) {
				$changes[$field] = array(
					'from' => $previous_value,
					'to' => $current_value
				);
			}
		}
		
		// Check for removed fields
		foreach ($previous as $field => $previous_value) {
			if (!isset($current[$field])) {
				$changes[$field] = array(
					'from' => $previous_value,
					'to' => null
				);
			}
		}
		
		return $changes;
	}
	
	/**
	 * Store change detection results
	 */
	private function store_change_results($query_id, $previous_snapshot_id, $current_snapshot_id, $changes) {
		$wpdb = csd_db_connection();
		
		$change_summary = array(
			'summary' => array(
				'total_changes' => $changes['total_changes'],
				'new_records' => $changes['new_count'],
				'modified_records' => $changes['modified_count'],
				'deleted_records' => $changes['deleted_count'],
				'previous_total' => $changes['previous_total'],
				'current_total' => $changes['current_total']
			),
			'sample_changes' => array(
				'new' => array_slice($changes['new_records'], 0, 5),
				'modified' => array_slice($changes['modified_records'], 0, 5),
				'deleted' => array_slice($changes['deleted_records'], 0, 5)
			)
		);
		
		$result = $wpdb->insert(
			csd_table('query_changes'),
			array(
				'query_id' => $query_id,
				'previous_snapshot_id' => $previous_snapshot_id,
				'current_snapshot_id' => $current_snapshot_id,
				'change_date' => current_time('mysql'),
				'changes_detected' => $changes['total_changes'] > 0 ? 1 : 0,
				'new_records' => $changes['new_count'],
				'modified_records' => $changes['modified_count'],
				'deleted_records' => $changes['deleted_count'],
				'change_summary' => json_encode($change_summary)
			)
		);
		
		if ($result === false) {
			throw new Exception('Failed to store change results: ' . $wpdb->last_error);
		}
		
		return $wpdb->insert_id;
	}
	
	/**
	 * Send change notifications to assigned users
	 */
	private function send_change_notifications($query_id, $query_name, $changes, $current_data, $change_record_id) {
		$wpdb = csd_db_connection();
		
		// Get all users assigned to this query
		$assigned_users = $wpdb->get_results($wpdb->prepare("
			SELECT u.user_email, u.display_name, u.ID
			FROM " . csd_table('user_queries') . " uq
			JOIN {$wpdb->users} u ON uq.user_id = u.ID
			WHERE uq.query_id = %d
		", $query_id));
		
		if (empty($assigned_users)) {
			error_log('CSD Query Monitoring: No users assigned to query ' . $query_id);
			return;
		}
		
		// Create CSV files
		$changes_csv = $this->create_changes_csv($changes);
		$current_data_csv = $this->create_current_data_csv($current_data);
		
		// Create email content
		$subject = sprintf('[%s] Weekly Query Report: %s', get_bloginfo('name'), $query_name);
		$email_body = $this->create_email_body($query_name, $changes);
		
		foreach ($assigned_users as $user) {
			$sent = wp_mail(
				$user->user_email,
				$subject,
				$email_body,
				array('Content-Type: text/html; charset=UTF-8'),
				array($changes_csv, $current_data_csv)
			);
			
			if ($sent) {
				error_log('CSD Query Monitoring: Email sent to ' . $user->user_email . ' for query ' . $query_id);
			} else {
				error_log('CSD Query Monitoring: Failed to send email to ' . $user->user_email . ' for query ' . $query_id);
			}
		}
		
		// Mark notification as sent
		$wpdb->update(
			csd_table('query_changes'),
			array('notification_sent' => 1),
			array('id' => $change_record_id)
		);
		
		// Clean up temporary CSV files
		unlink($changes_csv);
		unlink($current_data_csv);
	}
	
	/**
	 * Create CSV file with changes
	 */
	private function create_changes_csv($changes) {
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/csd-temp/';
		
		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}
		
		$filename = $temp_dir . 'query-changes-' . date('Y-m-d-H-i-s') . '-' . uniqid() . '.csv';
		$file = fopen($filename, 'w');
		
		// Write BOM for UTF-8
		fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
		
		// Write header
		fputcsv($file, array('Change Type', 'Record Data', 'Field Changes'));
		
		// Write new records
		foreach ($changes['new_records'] as $record) {
			fputcsv($file, array(
				'NEW',
				json_encode($record),
				''
			));
		}
		
		// Write modified records
		foreach ($changes['modified_records'] as $modified) {
			fputcsv($file, array(
				'MODIFIED',
				json_encode($modified['current']),
				json_encode($modified['changes'])
			));
		}
		
		// Write deleted records
		foreach ($changes['deleted_records'] as $record) {
			fputcsv($file, array(
				'DELETED',
				json_encode($record),
				''
			));
		}
		
		fclose($file);
		return $filename;
	}
	
	/**
	 * Create CSV file with current data
	 */
	private function create_current_data_csv($current_data) {
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/csd-temp/';
		
		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}
		
		$filename = $temp_dir . 'current-data-' . date('Y-m-d-H-i-s') . '-' . uniqid() . '.csv';
		$file = fopen($filename, 'w');
		
		// Write BOM for UTF-8
		fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
		
		if (!empty($current_data)) {
			// Write headers
			fputcsv($file, array_keys($current_data[0]));
			
			// Write data
			foreach ($current_data as $row) {
				fputcsv($file, $row);
			}
		}
		
		fclose($file);
		return $filename;
	}
	
	/**
	 * Create email body for change notification
	 */
	private function create_email_body($query_name, $changes) {
		$body = '<html><body>';
		$body .= '<h2>Weekly Query Monitoring Report</h2>';
		$body .= '<p><strong>Query:</strong> ' . esc_html($query_name) . '</p>';
		$body .= '<p><strong>Report Date:</strong> ' . date('F j, Y') . '</p>';
		
		$body .= '<h3>Summary of Changes</h3>';
		$body .= '<ul>';
		$body .= '<li><strong>Total Changes Detected:</strong> ' . number_format($changes['total_changes']) . '</li>';
		$body .= '<li><strong>New Records:</strong> ' . number_format($changes['new_count']) . '</li>';
		$body .= '<li><strong>Modified Records:</strong> ' . number_format($changes['modified_count']) . '</li>';
		$body .= '<li><strong>Deleted Records:</strong> ' . number_format($changes['deleted_count']) . '</li>';
		$body .= '</ul>';
		
		$body .= '<h3>Data Overview</h3>';
		$body .= '<ul>';
		$body .= '<li><strong>Previous Week Total:</strong> ' . number_format($changes['previous_total']) . ' records</li>';
		$body .= '<li><strong>Current Week Total:</strong> ' . number_format($changes['current_total']) . ' records</li>';
		$body .= '</ul>';
		
		if ($changes['total_changes'] > 0) {
			$body .= '<h3>Attached Files</h3>';
			$body .= '<p>This email includes two CSV attachments:</p>';
			$body .= '<ul>';
			$body .= '<li><strong>Changes Summary:</strong> Details of all changes detected (new, modified, deleted records)</li>';
			$body .= '<li><strong>Current Data:</strong> Complete dataset from this week\'s query run</li>';
			$body .= '</ul>';
		}
		
		$body .= '<hr>';
		$body .= '<p><small>This is an automated report from the College Sports Directory Manager plugin. ';
		$body .= 'You are receiving this because you have been assigned to monitor the "' . esc_html($query_name) . '" query.</small></p>';
		$body .= '</body></html>';
		
		return $body;
	}
	
	/**
	 * Update monitoring schedule for next run
	 */
	private function update_monitoring_schedule($query_id) {
		$wpdb = csd_db_connection();
		
		$next_run = date('Y-m-d H:i:s', strtotime('+1 week'));
		$now = current_time('mysql');
		
		// Insert or update monitoring record
		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM " . csd_table('query_monitoring') . " WHERE query_id = %d",
			$query_id
		));
		
		if ($existing) {
			$wpdb->update(
				csd_table('query_monitoring'),
				array(
					'last_run' => $now,
					'next_run' => $next_run
				),
				array('query_id' => $query_id)
			);
		} else {
			$wpdb->insert(
				csd_table('query_monitoring'),
				array(
					'query_id' => $query_id,
					'monitoring_enabled' => 1,
					'last_run' => $now,
					'next_run' => $next_run,
					'email_notifications' => 1,
					'created_date' => $now
				)
			);
		}
	}
	
	/**
	 * Add monitoring admin page
	 */
	public function add_monitoring_page() {
		add_submenu_page(
			'csd-manager',
			__('Query Monitoring', 'csd-manager'),
			__('Query Monitoring', 'csd-manager'),
			'manage_options',
			'csd-query-monitoring',
			array($this, 'render_monitoring_page')
		);
	}
	
	/**
	 * Render monitoring admin page
	 */
	public function render_monitoring_page() {
		$wpdb = csd_db_connection();
		
		// Get table names
		$saved_queries_table = csd_table('saved_queries');
		$monitoring_table = csd_table('query_monitoring');
		$user_queries_table = csd_table('user_queries');
		$changes_table = csd_table('query_changes');
		
		// Get all saved queries with monitoring status
		$queries = $wpdb->get_results("
			SELECT sq.id, sq.query_name, sq.date_created,
				   COALESCE(qm.monitoring_enabled, 1) as monitoring_enabled, 
				   qm.last_run, 
				   qm.next_run, 
				   COALESCE(qm.email_notifications, 1) as email_notifications,
				   0 as assigned_users,
				   0 as total_changes
			FROM $saved_queries_table sq
			LEFT JOIN $monitoring_table qm ON sq.id = qm.query_id
			ORDER BY sq.query_name
		");
		
		// Get user counts and change counts separately to avoid complex JOINs
		if (!empty($queries)) {
			foreach ($queries as $query) {
				// Ensure all properties exist and have default values
				$query->monitoring_enabled = isset($query->monitoring_enabled) ? intval($query->monitoring_enabled) : 1;
				$query->email_notifications = isset($query->email_notifications) ? intval($query->email_notifications) : 1;
				$query->last_run = $query->last_run ?? null;
				$query->next_run = $query->next_run ?? null;
				
				// Get assigned users count
				$user_count = $wpdb->get_var($wpdb->prepare("
					SELECT COUNT(DISTINCT user_id) 
					FROM $user_queries_table 
					WHERE query_id = %d
				", $query->id));
				$query->assigned_users = intval($user_count ?? 0);
				
				// Get changes count
				$changes_count = $wpdb->get_var($wpdb->prepare("
					SELECT COUNT(*) 
					FROM $changes_table 
					WHERE query_id = %d AND changes_detected = 1
				", $query->id));
				$query->total_changes = intval($changes_count ?? 0);
			}
		}
		
		// If no queries found, initialize empty array
		if (empty($queries)) {
			$queries = array();
		}
		?>
		<div class="wrap">
			<h1><?php _e('Query Monitoring', 'csd-manager'); ?></h1>
			
			<p><?php _e('Monitor saved queries for changes and automatically notify assigned users via email.', 'csd-manager'); ?></p>
			
			<div class="csd-monitoring-controls" style="margin-bottom: 20px;">
				<button type="button" id="csd-run-manual-monitoring" class="button button-secondary">
					<?php _e('Run Manual Check Now', 'csd-manager'); ?>
				</button>
				<span id="csd-manual-monitoring-status" style="margin-left: 15px;"></span>
			</div>
			
			<div class="tablenav top">
				<div class="alignleft actions">
					<select id="csd-bulk-monitoring-action">
						<option value=""><?php _e('Bulk Actions', 'csd-manager'); ?></option>
						<option value="enable"><?php _e('Enable Monitoring', 'csd-manager'); ?></option>
						<option value="disable"><?php _e('Disable Monitoring', 'csd-manager'); ?></option>
						<option value="enable_email"><?php _e('Enable Email Notifications', 'csd-manager'); ?></option>
						<option value="disable_email"><?php _e('Disable Email Notifications', 'csd-manager'); ?></option>
					</select>
					<button type="button" id="csd-apply-bulk-action" class="button"><?php _e('Apply', 'csd-manager'); ?></button>
				</div>
			</div>
			
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<td class="manage-column column-cb check-column">
							<input type="checkbox" id="csd-select-all-queries">
						</td>
						<th class="manage-column"><?php _e('Query Name', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Assigned Users', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Monitoring Status', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Email Notifications', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Last Run', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Next Run', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Total Changes', 'csd-manager'); ?></th>
						<th class="manage-column"><?php _e('Actions', 'csd-manager'); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($queries as $query): ?>
					<tr>
						<th class="check-column">
							<input type="checkbox" class="csd-query-checkbox" value="<?php echo esc_attr($query->id); ?>">
						</th>
						<td>
							<strong><?php echo esc_html($query->query_name); ?></strong>
						</td>
						<td>
							<?php echo intval($query->assigned_users ?? 0); ?>
							<?php if (intval($query->assigned_users ?? 0) == 0): ?>
								<span class="description"><?php _e('(No users assigned)', 'csd-manager'); ?></span>
							<?php endif; ?>
						</td>
						<td>
							<label class="csd-toggle-switch">
								<input type="checkbox" class="csd-monitoring-toggle" 
									   data-query-id="<?php echo esc_attr($query->id); ?>"
									   <?php checked(intval($query->monitoring_enabled ?? 1), 1); ?>>
								<span class="csd-toggle-slider"></span>
							</label>
							<span class="csd-monitoring-status">
								<?php echo intval($query->monitoring_enabled ?? 1) ? __('Enabled', 'csd-manager') : __('Disabled', 'csd-manager'); ?>
							</span>
						</td>
						<td>
							<label class="csd-toggle-switch">
								<input type="checkbox" class="csd-email-toggle" 
									   data-query-id="<?php echo esc_attr($query->id); ?>"
									   <?php checked(intval($query->email_notifications ?? 1), 1); ?>>
								<span class="csd-toggle-slider"></span>
							</label>
						</td>
						<td>
							<?php 
							if (!empty($query->last_run)) {
								echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($query->last_run));
							} else {
								echo '<span class="description">' . __('Never', 'csd-manager') . '</span>';
							}
							?>
						</td>
						<td>
							<?php 
							if (!empty($query->next_run)) {
								echo date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($query->next_run));
							} else {
								echo '<span class="description">' . __('Not scheduled', 'csd-manager') . '</span>';
							}
							?>
						</td>
						<td>
							<strong><?php echo number_format(intval($query->total_changes ?? 0)); ?></strong>
						</td>
						<td>
							<button type="button" class="button button-small csd-view-history" 
									data-query-id="<?php echo esc_attr($query->id); ?>"
									data-query-name="<?php echo esc_attr($query->query_name); ?>">
								<?php _e('View History', 'csd-manager'); ?>
							</button>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		
		<!-- History Modal -->
		<div id="csd-history-modal" style="display:none;" class="csd-modal">
			<div class="csd-modal-content csd-history-modal-content">
				<span class="csd-modal-close">&times;</span>
				<h2 id="csd-history-modal-title"><?php _e('Query Monitoring History', 'csd-manager'); ?></h2>
				<div id="csd-history-modal-body">
					<div class="csd-loading"><?php _e('Loading history...', 'csd-manager'); ?></div>
				</div>
			</div>
		</div>
		
		<style type="text/css">
			.csd-toggle-switch {
				position: relative;
				display: inline-block;
				width: 50px;
				height: 24px;
			}
			
			.csd-toggle-switch input {
				opacity: 0;
				width: 0;
				height: 0;
			}
			
			.csd-toggle-slider {
				position: absolute;
				cursor: pointer;
				top: 0;
				left: 0;
				right: 0;
				bottom: 0;
				background-color: #ccc;
				transition: .4s;
				border-radius: 24px;
			}
			
			.csd-toggle-slider:before {
				position: absolute;
				content: "";
				height: 18px;
				width: 18px;
				left: 3px;
				bottom: 3px;
				background-color: white;
				transition: .4s;
				border-radius: 50%;
			}
			
			input:checked + .csd-toggle-slider {
				background-color: #2196F3;
			}
			
			input:checked + .csd-toggle-slider:before {
				transform: translateX(26px);
			}
			
			.csd-monitoring-status {
				margin-left: 10px;
				font-weight: 600;
			}
			
			.csd-history-modal-content {
				max-width: 900px;
				width: 90%;
			}
			
			.csd-history-table {
				width: 100%;
				border-collapse: collapse;
				margin-top: 15px;
			}
			
			.csd-history-table th,
			.csd-history-table td {
				padding: 8px;
				text-align: left;
				border-bottom: 1px solid #ddd;
			}
			
			.csd-history-table th {
				background-color: #f5f5f5;
			}
			
			.csd-change-badge {
				display: inline-block;
				padding: 2px 6px;
				border-radius: 3px;
				font-size: 11px;
				font-weight: bold;
				color: white;
			}
			
			.csd-change-badge.new {
				background-color: #28a745;
			}
			
			.csd-change-badge.modified {
				background-color: #ffc107;
				color: #000;
			}
			
			.csd-change-badge.deleted {
				background-color: #dc3545;
			}
		</style>
		
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				// Toggle monitoring status
				$('.csd-monitoring-toggle, .csd-email-toggle').on('change', function() {
					var $toggle = $(this);
					var queryId = $toggle.data('query-id');
					var field = $toggle.hasClass('csd-monitoring-toggle') ? 'monitoring_enabled' : 'email_notifications';
					var value = $toggle.is(':checked') ? 1 : 0;
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'csd_toggle_query_monitoring',
							query_id: queryId,
							field: field,
							value: value,
							nonce: '<?php echo wp_create_nonce('csd-monitoring-nonce'); ?>'
						},
						success: function(response) {
							if (response.success) {
								if (field === 'monitoring_enabled') {
									$toggle.closest('tr').find('.csd-monitoring-status').text(
										value ? '<?php _e('Enabled', 'csd-manager'); ?>' : '<?php _e('Disabled', 'csd-manager'); ?>'
									);
								}
							} else {
								alert(response.data.message || '<?php _e('Error updating setting.', 'csd-manager'); ?>');
								$toggle.prop('checked', !$toggle.is(':checked')); // Revert
							}
						},
						error: function() {
							alert('<?php _e('Error updating setting.', 'csd-manager'); ?>');
							$toggle.prop('checked', !$toggle.is(':checked')); // Revert
						}
					});
				});
				
				// Manual monitoring run
				$('#csd-run-manual-monitoring').on('click', function() {
					var $button = $(this);
					var $status = $('#csd-manual-monitoring-status');
					
					$button.prop('disabled', true).text('<?php _e('Running...', 'csd-manager'); ?>');
					$status.html('<span style="color: #0073aa;"><?php _e('Running monitoring check...', 'csd-manager'); ?></span>');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'csd_run_manual_monitoring',
							nonce: '<?php echo wp_create_nonce('csd-monitoring-nonce'); ?>'
						},
						success: function(response) {
							$button.prop('disabled', false).text('<?php _e('Run Manual Check Now', 'csd-manager'); ?>');
							
							if (response.success) {
								$status.html('<span style="color: #46b450;">' + response.data.message + '</span>');
								setTimeout(function() {
									location.reload();
								}, 2000);
							} else {
								$status.html('<span style="color: #dc3232;">' + (response.data.message || '<?php _e('Error running monitoring.', 'csd-manager'); ?>') + '</span>');
							}
						},
						error: function() {
							$button.prop('disabled', false).text('<?php _e('Run Manual Check Now', 'csd-manager'); ?>');
							$status.html('<span style="color: #dc3232;"><?php _e('Error running monitoring.', 'csd-manager'); ?></span>');
						}
					});
				});
				
				// View history
				$('.csd-view-history').on('click', function() {
					var queryId = $(this).data('query-id');
					var queryName = $(this).data('query-name');
					
					$('#csd-history-modal-title').text('<?php _e('Monitoring History for', 'csd-manager'); ?> "' + queryName + '"');
					$('#csd-history-modal-body').html('<div class="csd-loading"><?php _e('Loading history...', 'csd-manager'); ?></div>');
					$('#csd-history-modal').show();
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'csd_get_monitoring_history',
							query_id: queryId,
							nonce: '<?php echo wp_create_nonce('csd-monitoring-nonce'); ?>'
						},
						success: function(response) {
							if (response.success) {
								$('#csd-history-modal-body').html(response.data.html);
							} else {
								$('#csd-history-modal-body').html('<p class="notice notice-error">' + 
									(response.data.message || '<?php _e('Error loading history.', 'csd-manager'); ?>') + '</p>');
							}
						},
						error: function() {
							$('#csd-history-modal-body').html('<p class="notice notice-error"><?php _e('Error loading history.', 'csd-manager'); ?></p>');
						}
					});
				});
				
				// Close modal
				$('.csd-modal-close').on('click', function() {
					$(this).closest('.csd-modal').hide();
				});
				
				// Close modal when clicking outside
				$(window).on('click', function(event) {
					if ($(event.target).hasClass('csd-modal')) {
						$(event.target).hide();
					}
				});
			});
		</script>
		<?php
	}
	
	/**
	 * AJAX handler for toggling monitoring settings
	 */
	public function ajax_toggle_monitoring() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-monitoring-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$query_id = intval($_POST['query_id']);
		$field = sanitize_text_field($_POST['field']);
		$value = intval($_POST['value']);
		
		if (!in_array($field, array('monitoring_enabled', 'email_notifications'))) {
			wp_send_json_error(array('message' => __('Invalid field.', 'csd-manager')));
			return;
		}
		
		$wpdb = csd_db_connection();
		
		// Check if monitoring record exists
		$existing = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM " . csd_table('query_monitoring') . " WHERE query_id = %d",
			$query_id
		));
		
		if ($existing) {
			$result = $wpdb->update(
				csd_table('query_monitoring'),
				array($field => $value),
				array('query_id' => $query_id)
			);
		} else {
			$result = $wpdb->insert(
				csd_table('query_monitoring'),
				array(
					'query_id' => $query_id,
					'monitoring_enabled' => $field === 'monitoring_enabled' ? $value : 1,
					'email_notifications' => $field === 'email_notifications' ? $value : 1,
					'created_date' => current_time('mysql')
				)
			);
		}
		
		if ($result === false) {
			wp_send_json_error(array('message' => __('Error updating setting.', 'csd-manager')));
			return;
		}
		
		wp_send_json_success(array('message' => __('Setting updated successfully.', 'csd-manager')));
	}
	
	/**
	 * AJAX handler for running manual monitoring
	 */
	public function ajax_run_manual_monitoring() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-monitoring-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		try {
			$this->run_weekly_monitoring();
			wp_send_json_success(array('message' => __('Monitoring check completed successfully!', 'csd-manager')));
		} catch (Exception $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}
	
	/**
	 * AJAX handler for getting monitoring history
	 */
	public function ajax_get_monitoring_history() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-monitoring-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$query_id = intval($_POST['query_id']);
		$wpdb = csd_db_connection();
		
		// Get monitoring history for this query
		$history = $wpdb->get_results($wpdb->prepare("
			SELECT qc.*, 
				   ps.snapshot_date as previous_snapshot_date,
				   cs.snapshot_date as current_snapshot_date
			FROM " . csd_table('query_changes') . " qc
			LEFT JOIN " . csd_table('query_snapshots') . " ps ON qc.previous_snapshot_id = ps.id
			LEFT JOIN " . csd_table('query_snapshots') . " cs ON qc.current_snapshot_id = cs.id
			WHERE qc.query_id = %d
			ORDER BY qc.change_date DESC
			LIMIT 50
		", $query_id));
		
		$html = '';
		
		if (empty($history)) {
			$html = '<p>' . __('No monitoring history found for this query.', 'csd-manager') . '</p>';
		} else {
			$html .= '<table class="csd-history-table">';
			$html .= '<thead><tr>';
			$html .= '<th>' . __('Date', 'csd-manager') . '</th>';
			$html .= '<th>' . __('Changes', 'csd-manager') . '</th>';
			$html .= '<th>' . __('New', 'csd-manager') . '</th>';
			$html .= '<th>' . __('Modified', 'csd-manager') . '</th>';
			$html .= '<th>' . __('Deleted', 'csd-manager') . '</th>';
			$html .= '<th>' . __('Email Sent', 'csd-manager') . '</th>';
			$html .= '</tr></thead>';
			$html .= '<tbody>';
			
			foreach ($history as $record) {
				$html .= '<tr>';
				$html .= '<td>' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($record->change_date)) . '</td>';
				$html .= '<td>' . (intval($record->changes_detected ?? 0) ? __('Yes', 'csd-manager') : __('No', 'csd-manager')) . '</td>';
				$html .= '<td><span class="csd-change-badge new">' . number_format(intval($record->new_records ?? 0)) . '</span></td>';
				$html .= '<td><span class="csd-change-badge modified">' . number_format(intval($record->modified_records ?? 0)) . '</span></td>';
				$html .= '<td><span class="csd-change-badge deleted">' . number_format(intval($record->deleted_records ?? 0)) . '</span></td>';
				$html .= '<td>' . (intval($record->notification_sent ?? 0) ? __('Yes', 'csd-manager') : __('No', 'csd-manager')) . '</td>';
				$html .= '</tr>';
			}
			
			$html .= '</tbody></table>';
		}
		
		wp_send_json_success(array('html' => $html));
	}
}