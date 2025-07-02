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
		add_action('init', array($this, 'schedule_weekly_monitoring'));
		add_action('csd_weekly_query_monitoring', array($this, 'run_weekly_monitoring'));
		add_action('admin_menu', array($this, 'add_monitoring_page'));
		add_action('wp_ajax_csd_toggle_query_monitoring', array($this, 'ajax_toggle_monitoring'));
		add_action('wp_ajax_csd_run_manual_monitoring', array($this, 'ajax_run_manual_monitoring'));
		add_action('wp_ajax_csd_get_monitoring_history', array($this, 'ajax_get_monitoring_history'));
		
		// NEW: Add Gmail SMTP settings hooks
		add_action('admin_menu', array($this, 'add_smtp_settings_page'));
		add_action('admin_init', array($this, 'register_smtp_settings'));
		add_action('wp_ajax_csd_test_smtp_email', array($this, 'ajax_test_smtp_email'));
		
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
	 * Execute a saved query and return the data - FIXED VERSION
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
				// Clean the SQL to fix hex placeholders and other issues
				$sql = $this->clean_sql_for_monitoring($sql);
				// Remove any LIMIT clauses to get all data
				$sql = $this->remove_pagination_from_sql($sql);
			} else {
				// For form-based queries, temporarily remove limit and build SQL without pagination
				$original_limit = isset($settings['limit']) ? $settings['limit'] : null;
				unset($settings['limit']); // Remove limit from settings
				
				// Build SQL from form settings without pagination
				$sql = $query_builder->build_sql_query($settings, false); // No pagination
				
				// Clean the SQL to fix any issues
				$sql = $this->clean_sql_for_monitoring($sql);
				
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
	 * Clean SQL query for monitoring to handle escaped quotes and hex placeholders
	 * 
	 * @param string $sql The SQL query to clean
	 * @return string Cleaned SQL query
	 */
	private function clean_sql_for_monitoring($sql) {
		// Make sure we have a string
		if (!is_string($sql)) {
			return '';
		}
		
		// Trim whitespace
		$sql = trim($sql);
		
		// Fix escaped quotes that can come from AJAX or CodeMirror
		$sql = str_replace("\'", "'", $sql);
		$sql = str_replace('\"', '"', $sql);
		
		// Remove hexadecimal artifacts that might appear in LIKE clauses
		// This is the key fix for your monitoring system
		$sql = preg_replace('/{[0-9a-f]+}/', '%', $sql);
		
		// Remove any double %% that might cause issues
		$sql = str_replace('%%', '%', $sql);
		
		// Clean up any potential double escaping issues
		$sql = stripslashes($sql);
		
		// Additional cleaning for AJAX transmission issues
		$sql = wp_unslash($sql);
		
		return $sql;
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
	 * Updated send_change_notifications method with Gmail SMTP
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
			$sent = $this->send_gmail_smtp_email(
				$user->user_email,
				$user->display_name,
				$subject,
				$email_body,
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
		if (file_exists($changes_csv)) {
			unlink($changes_csv);
		}
		if (file_exists($current_data_csv)) {
			unlink($current_data_csv);
		}
	}
	
	/**
	 * Send email using Gmail SMTP
	 */
	private function send_gmail_smtp_email($to_email, $to_name, $subject, $body, $attachments = array()) {
		// Load PHPMailer
		if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
			require_once ABSPATH . WPINC . '/PHPMailer/PHPMailer.php';
			require_once ABSPATH . WPINC . '/PHPMailer/SMTP.php';
			require_once ABSPATH . WPINC . '/PHPMailer/Exception.php';
		}
		
		$mail = new PHPMailer\PHPMailer\PHPMailer(true);
		
		try {
			// Get Gmail SMTP settings
			$smtp_settings = $this->get_gmail_smtp_settings();
			
			if (!$smtp_settings || !$smtp_settings['enabled']) {
				error_log('CSD Query Monitoring: Gmail SMTP not configured, falling back to wp_mail');
				return $this->send_fallback_email($to_email, $subject, $body, $attachments);
			}
			
			// Server settings
			$mail->isSMTP();
			$mail->Host = 'smtp.gmail.com';
			$mail->SMTPAuth = true;
			$mail->Username = $smtp_settings['username'];
			$mail->Password = $smtp_settings['password'];
			$mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
			$mail->Port = 587;
			
			// Recipients
			$mail->setFrom($smtp_settings['from_email'], $smtp_settings['from_name']);
			$mail->addAddress($to_email, $to_name);
			$mail->addReplyTo($smtp_settings['from_email'], $smtp_settings['from_name']);
			
			// Content
			$mail->isHTML(true);
			$mail->Subject = $subject;
			$mail->Body = $body;
			$mail->AltBody = strip_tags($body);
			
			// Add attachments
			foreach ($attachments as $attachment) {
				if (file_exists($attachment)) {
					$mail->addAttachment($attachment);
				}
			}
			
			// Send the email
			$result = $mail->send();
			
			if ($result) {
				error_log('CSD Query Monitoring: Gmail SMTP email sent successfully to ' . $to_email);
				return true;
			} else {
				error_log('CSD Query Monitoring: Gmail SMTP failed to send email to ' . $to_email);
				return false;
			}
			
		} catch (Exception $e) {
			error_log('CSD Query Monitoring: Gmail SMTP Error: ' . $e->getMessage());
			
			// Fallback to wp_mail
			error_log('CSD Query Monitoring: Falling back to wp_mail for ' . $to_email);
			return $this->send_fallback_email($to_email, $subject, $body, $attachments);
		}
	}
	
	/**
	 * Fallback email using wp_mail
	 */
	private function send_fallback_email($to_email, $subject, $body, $attachments = array()) {
		$headers = array('Content-Type: text/html; charset=UTF-8');
		
		$sent = wp_mail(
			$to_email,
			$subject,
			$body,
			$headers,
			$attachments
		);
		
		if ($sent) {
			error_log('CSD Query Monitoring: Fallback wp_mail sent successfully to ' . $to_email);
		} else {
			error_log('CSD Query Monitoring: Fallback wp_mail failed to send to ' . $to_email);
		}
		
		return $sent;
	}
	
	/**
	 * Get Gmail SMTP settings
	 */
	private function get_gmail_smtp_settings() {
		// You can store these in WordPress options or define them as constants
		// For security, consider using WordPress constants in wp-config.php
		
		$settings = array(
			'enabled' => defined('CSD_GMAIL_SMTP_ENABLED') ? CSD_GMAIL_SMTP_ENABLED : false,
			'username' => defined('CSD_GMAIL_SMTP_USERNAME') ? CSD_GMAIL_SMTP_USERNAME : '',
			'password' => defined('CSD_GMAIL_SMTP_PASSWORD') ? CSD_GMAIL_SMTP_PASSWORD : '',
			'from_email' => defined('CSD_GMAIL_SMTP_FROM_EMAIL') ? CSD_GMAIL_SMTP_FROM_EMAIL : get_option('admin_email'),
			'from_name' => defined('CSD_GMAIL_SMTP_FROM_NAME') ? CSD_GMAIL_SMTP_FROM_NAME : get_bloginfo('name')
		);
		
		// Alternative: Get from WordPress options (less secure but more flexible)
		if (!$settings['enabled']) {
			$settings = array(
				'enabled' => get_option('csd_gmail_smtp_enabled', false),
				'username' => get_option('csd_gmail_smtp_username', ''),
				'password' => get_option('csd_gmail_smtp_password', ''),
				'from_email' => get_option('csd_gmail_smtp_from_email', get_option('admin_email')),
				'from_name' => get_option('csd_gmail_smtp_from_name', get_bloginfo('name'))
			);
		}
		
		return $settings;
	}
	
	/**
	 * Create CSV file with changes - IMPROVED VERSION
	 */
	private function create_changes_csv($changes) {
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/csd-temp/';
		
		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}
		
		$filename = $temp_dir . 'query-changes-' . date('Y-m-d-H-i-s') . '-' . uniqid() . '.csv';
		$file = fopen($filename, 'w');
		
		if (!$file) {
			error_log('CSD Query Monitoring: Failed to create changes CSV file: ' . $filename);
			return false;
		}
		
		// Write BOM for UTF-8
		fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
		
		// Write summary header
		fputcsv($file, array('CHANGE SUMMARY'));
		fputcsv($file, array('Total Changes', $changes['total_changes']));
		fputcsv($file, array('New Records', $changes['new_count']));
		fputcsv($file, array('Modified Records', $changes['modified_count']));
		fputcsv($file, array('Deleted Records', $changes['deleted_count']));
		fputcsv($file, array('Previous Total', $changes['previous_total']));
		fputcsv($file, array('Current Total', $changes['current_total']));
		fputcsv($file, array('Date', current_time('mysql')));
		fputcsv($file, array('')); // Empty row
		
		// If no changes, just return the summary
		if ($changes['total_changes'] == 0) {
			fputcsv($file, array('No changes detected'));
			fclose($file);
			return $filename;
		}
		
		// Write NEW RECORDS section
		if (!empty($changes['new_records'])) {
			fputcsv($file, array('NEW RECORDS (' . count($changes['new_records']) . ')'));
			$this->write_record_section($file, $changes['new_records'], 'new');
			fputcsv($file, array('')); // Empty row
		}
		
		// Write MODIFIED RECORDS section
		if (!empty($changes['modified_records'])) {
			fputcsv($file, array('MODIFIED RECORDS (' . count($changes['modified_records']) . ')'));
			$this->write_modified_record_section($file, $changes['modified_records']);
			fputcsv($file, array('')); // Empty row
		}
		
		// Write DELETED RECORDS section
		if (!empty($changes['deleted_records'])) {
			fputcsv($file, array('DELETED RECORDS (' . count($changes['deleted_records']) . ')'));
			$this->write_record_section($file, $changes['deleted_records'], 'deleted');
		}
		
		fclose($file);
		return $filename;
	}
	
	/**
	 * Write a section of records to CSV
	 */
	private function write_record_section($file, $records, $type) {
		if (empty($records)) {
			return;
		}
		
		// Get the first record to determine headers
		$first_record = reset($records);
		
		// Extract key fields for easy scanning
		$key_fields = $this->get_key_fields($first_record);
		
		if (!empty($key_fields)) {
			// Write header for key fields
			fputcsv($file, array_keys($key_fields));
			
			// Write key field data for each record
			foreach ($records as $record) {
				$key_data = $this->get_key_fields($record);
				fputcsv($file, array_values($key_data));
			}
			
			fputcsv($file, array('')); // Empty row before full data
		}
		
		// Write full data section header
		fputcsv($file, array('FULL RECORD DATA'));
		
		// Write headers
		$headers = array_keys($first_record);
		fputcsv($file, $headers);
		
		// Write data rows
		foreach ($records as $record) {
			fputcsv($file, array_values($record));
		}
	}
	
	/**
	 * Write modified records section with change details
	 */
	private function write_modified_record_section($file, $modified_records) {
		if (empty($modified_records)) {
			return;
		}
		
		// Write change summary header
		fputcsv($file, array('Record ID/Key', 'Changed Fields', 'Summary of Changes'));
		
		foreach ($modified_records as $modified) {
			$current = $modified['current'];
			$changes = $modified['changes'];
			
			// Create record identifier
			$record_id = $this->get_record_identifier($current);
			
			// Get list of changed fields
			$changed_fields = array_keys($changes);
			
			// Create summary of changes
			$change_summary = array();
			foreach ($changes as $field => $change) {
				$from = $change['from'] ?? 'NULL';
				$to = $change['to'] ?? 'NULL';
				$change_summary[] = "$field: '$from' → '$to'";
			}
			
			fputcsv($file, array(
				$record_id,
				implode(', ', $changed_fields),
				implode(' | ', $change_summary)
			));
		}
		
		fputcsv($file, array('')); // Empty row
		
		// Write full modified records data
		fputcsv($file, array('FULL MODIFIED RECORDS DATA'));
		
		if (!empty($modified_records)) {
			$first_record = reset($modified_records)['current'];
			$headers = array_keys($first_record);
			fputcsv($file, $headers);
			
			foreach ($modified_records as $modified) {
				fputcsv($file, array_values($modified['current']));
			}
		}
	}
	
	/**
	 * Get key fields for easy identification of records
	 */
	private function get_key_fields($record) {
		$key_fields = array();
		
		// Define the most important fields to show first
		$important_fields = array(
			'staff_full_name',
			'staff_title', 
			'staff_email',
			'staff_sport_department',
			'schools_school_name',
			'staff_id',
			'schools_id'
		);
		
		foreach ($important_fields as $field) {
			if (isset($record[$field]) && $record[$field] !== null && $record[$field] !== '') {
				$key_fields[$field] = $record[$field];
			}
		}
		
		return $key_fields;
	}
	
	/**
	 * Get a human-readable identifier for a record
	 */
	private function get_record_identifier($record) {
		// Try to create a meaningful identifier
		if (isset($record['staff_full_name']) && !empty($record['staff_full_name'])) {
			$id = $record['staff_full_name'];
			if (isset($record['schools_school_name'])) {
				$id .= ' (' . $record['schools_school_name'] . ')';
			}
			return $id;
		}
		
		if (isset($record['staff_id'])) {
			return 'Staff ID: ' . $record['staff_id'];
		}
		
		if (isset($record['schools_school_name'])) {
			return 'School: ' . $record['schools_school_name'];
		}
		
		return 'Record';
	}
	
	/**
	 * Create current data CSV with better formatting - UPDATED VERSION
	 */
	private function create_current_data_csv($current_data) {
		$upload_dir = wp_upload_dir();
		$temp_dir = $upload_dir['basedir'] . '/csd-temp/';
		
		if (!file_exists($temp_dir)) {
			wp_mkdir_p($temp_dir);
		}
		
		$filename = $temp_dir . 'current-data-' . date('Y-m-d-H-i-s') . '-' . uniqid() . '.csv';
		$file = fopen($filename, 'w');
		
		if (!$file) {
			error_log('CSD Query Monitoring: Failed to create current data CSV file: ' . $filename);
			return false;
		}
		
		// Write BOM for UTF-8
		fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
		
		// Write summary header
		fputcsv($file, array('CURRENT QUERY DATA SUMMARY'));
		fputcsv($file, array('Total Records', count($current_data)));
		fputcsv($file, array('Export Date', current_time('mysql')));
		fputcsv($file, array('')); // Empty row
		
		if (empty($current_data)) {
			fputcsv($file, array('No data available'));
			fclose($file);
			return $filename;
		}
		
		// Write key fields summary first
		fputcsv($file, array('KEY FIELDS SUMMARY'));
		$first_record = reset($current_data);
		$key_fields = $this->get_key_fields($first_record);
		
		if (!empty($key_fields)) {
			fputcsv($file, array_keys($key_fields));
			foreach ($current_data as $record) {
				$key_data = $this->get_key_fields($record);
				fputcsv($file, array_values($key_data));
			}
			fputcsv($file, array('')); // Empty row
		}
		
		// Write full data
		fputcsv($file, array('COMPLETE DATA'));
		$headers = array_keys($first_record);
		fputcsv($file, $headers);
		
		foreach ($current_data as $record) {
			fputcsv($file, array_values($record));
		}
		
		fclose($file);
		return $filename;
	}
	
	/**
	 * Create email body for change notification - GMAIL-OPTIMIZED VERSION
	 */
	private function create_email_body($query_name, $changes) {
		// Get logo settings
		$logo_settings = $this->get_email_logo_settings();
		
		// Keep it concise to avoid Gmail clipping (under 102KB)
		$body = '<!DOCTYPE html>';
		$body .= '<html>';
		$body .= '<head>';
		$body .= '<meta charset="UTF-8">';
		$body .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
		$body .= '<style>';
		// Minimal, Gmail-friendly CSS
		$body .= 'body{font-family:Arial,sans-serif;line-height:1.6;color:#333;margin:0;padding:0;background:#f4f4f4}';
		$body .= '.container{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden}';
		// Orange/yellow gradient to match your logo
		$body .= '.header{background:linear-gradient(135deg, #ff9800 0%, #ffc107 100%);color:#fff;padding:30px;text-align:center}';
		$body .= '.logo{margin-bottom:15px}';
		$body .= '.logo img{max-height:50px;max-width:180px;background:rgba(255,255,255,0.2);padding:8px;border-radius:4px}';
		$body .= '.content{padding:25px}';
		$body .= '.info-box{background:#f8f9fa;border-left:4px solid #ff9800;padding:15px;margin:15px 0;border-radius:0 4px 4px 0}';
		$body .= '.summary{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:20px;margin:15px 0}';
		$body .= '.stats{display:table;width:100%;margin:15px 0}';
		$body .= '.stat{display:table-cell;text-align:center;padding:12px;background:#f8f9fa;border:1px solid #e0e0e0}';
		$body .= '.stat-num{font-size:20px;font-weight:bold;color:#ff9800;margin-bottom:4px}';
		$body .= '.stat-label{font-size:11px;color:#666;text-transform:uppercase}';
		$body .= '.overview{background:#e8f5e8;border:1px solid #c3e6c3;border-radius:6px;padding:18px;margin:15px 0}';
		$body .= '.highlight{background:linear-gradient(135deg, #ff6b6b 0%, #ffa726 100%);color:#fff;padding:12px;border-radius:4px;margin:10px 0;text-align:center}';
		$body .= '.footer{background:#f8f9fa;padding:15px;border-top:1px solid #e0e0e0;text-align:center;font-size:11px;color:#666}';
		$body .= '.no-changes{background:#d4edda;border:1px solid #c3e6cb;color:#155724;padding:12px;border-radius:4px;text-align:center}';
		$body .= '@media (max-width:600px){.container{margin:0;border-radius:0}.content{padding:15px}.stats{display:block}.stat{display:block;margin:5px 0}}';
		$body .= '</style>';
		$body .= '</head>';
		$body .= '<body>';
		
		$body .= '<div class="container">';
		
		// Header with logo
		$body .= '<div class="header">';
		if ($logo_settings['enabled'] && !empty($logo_settings['url'])) {
			$body .= '<div class="logo">';
			$body .= '<img src="' . esc_url($logo_settings['url']) . '" alt="' . esc_attr($logo_settings['alt_text']) . '">';
			$body .= '</div>';
		}
		$body .= '<h1 style="margin:0;font-size:22px;">Weekly Query Report</h1>';
		$body .= '</div>';
		
		// Main content
		$body .= '<div class="content">';
		
		// Query information
		$body .= '<div class="info-box">';
		$body .= '<p style="margin:4px 0;"><strong>Query:</strong> ' . esc_html($query_name) . '</p>';
		$body .= '<p style="margin:4px 0;"><strong>Date:</strong> ' . date('M j, Y g:i A T') . '</p>';
		$body .= '<p style="margin:4px 0;"><strong>System:</strong> ' . esc_html(get_bloginfo('name')) . '</p>';
		$body .= '</div>';
		
		// Changes summary
		$body .= '<div class="summary">';
		$body .= '<h3 style="margin-top:0;color:#495057;border-bottom:2px solid #e0e0e0;padding-bottom:8px;">Summary of Changes</h3>';
		
		if ($changes['total_changes'] > 0) {
			$body .= '<div class="highlight">';
			$body .= '<strong>' . number_format($changes['total_changes']) . ' changes detected</strong>';
			$body .= '</div>';
		} else {
			$body .= '<div class="no-changes">';
			$body .= 'No changes detected - data is stable';
			$body .= '</div>';
		}
		
		// Stats grid (using table for better Gmail compatibility)
		$body .= '<div class="stats">';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($changes['new_count']) . '</div>';
		$body .= '<div class="stat-label">New</div>';
		$body .= '</div>';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($changes['modified_count']) . '</div>';
		$body .= '<div class="stat-label">Modified</div>';
		$body .= '</div>';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($changes['deleted_count']) . '</div>';
		$body .= '<div class="stat-label">Deleted</div>';
		$body .= '</div>';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($changes['total_changes']) . '</div>';
		$body .= '<div class="stat-label">Total</div>';
		$body .= '</div>';
		$body .= '</div>';
		$body .= '</div>';
		
		// Data overview
		$body .= '<div class="overview">';
		$body .= '<h3 style="margin-top:0;color:#2e7d32;">Data Overview</h3>';
		$body .= '<p style="margin:5px 0;"><strong>Previous:</strong> ' . number_format($changes['previous_total']) . ' records</p>';
		$body .= '<p style="margin:5px 0;"><strong>Current:</strong> ' . number_format($changes['current_total']) . ' records</p>';
		
		$net_change = $changes['current_total'] - $changes['previous_total'];
		if ($net_change > 0) {
			$body .= '<p style="margin:5px 0;"><strong>Net Change:</strong> <span style="color:#2e7d32;">+' . number_format($net_change) . ' records</span></p>';
		} elseif ($net_change < 0) {
			$body .= '<p style="margin:5px 0;"><strong>Net Change:</strong> <span style="color:#d32f2f;">' . number_format($net_change) . ' records</span></p>';
		} else {
			$body .= '<p style="margin:5px 0;"><strong>Net Change:</strong> <span style="color:#666;">No change</span></p>';
		}
		$body .= '</div>';
		
		// Attachments info (only if there are changes)
		if ($changes['total_changes'] > 0) {
			$body .= '<div style="background:#fff3cd;border:1px solid #ffeaa7;border-radius:6px;padding:15px;margin:15px 0;">';
			$body .= '<h3 style="margin-top:0;color:#856404;">Attached Files</h3>';
			$body .= '<p style="margin:8px 0;">This email includes detailed CSV reports:</p>';
			$body .= '<ul style="margin:8px 0;padding-left:20px;">';
			$body .= '<li><strong>Changes Summary:</strong> Detailed breakdown of changes</li>';
			$body .= '<li><strong>Current Data:</strong> Complete current dataset</li>';
			$body .= '</ul>';
			$body .= '</div>';
		}
		
		$body .= '</div>'; // End content
		
		// Footer
		$body .= '<div class="footer">';
		$body .= '<p style="margin:5px 0;">Automated report from College Sports Directory Manager</p>';
		$body .= '<p style="margin:5px 0;">You are monitoring: <strong>' . esc_html($query_name) . '</strong></p>';
		if (!empty($logo_settings['organization_name'])) {
			$body .= '<p style="margin:5px 0;">&copy; ' . date('Y') . ' ' . esc_html($logo_settings['organization_name']) . '</p>';
		}
		$body .= '</div>';
		
		$body .= '</div>'; // End container
		$body .= '</body>';
		$body .= '</html>';
		
		return $body;
	}
	
	/**
	 * Get email logo settings
	 */
	private function get_email_logo_settings() {
		// Check for constants first (more secure)
		if (defined('CSD_EMAIL_LOGO_URL')) {
			return array(
				'enabled' => true,
				'url' => CSD_EMAIL_LOGO_URL,
				'alt_text' => defined('CSD_EMAIL_LOGO_ALT') ? CSD_EMAIL_LOGO_ALT : get_bloginfo('name') . ' Logo',
				'organization_name' => defined('CSD_EMAIL_ORG_NAME') ? CSD_EMAIL_ORG_NAME : get_bloginfo('name')
			);
		}
		
		// Fall back to WordPress options
		return array(
			'enabled' => get_option('csd_email_logo_enabled', false),
			'url' => get_option('csd_email_logo_url', ''),
			'alt_text' => get_option('csd_email_logo_alt', get_bloginfo('name') . ' Logo'),
			'organization_name' => get_option('csd_email_org_name', get_bloginfo('name'))
		);
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
	
	/**
	 * Add Gmail SMTP settings page
	 */
	public function add_smtp_settings_page() {
		add_submenu_page(
			'csd-manager',
			__('Email Settings', 'csd-manager'),
			__('Email Settings', 'csd-manager'),
			'manage_options',
			'csd-email-settings',
			array($this, 'render_smtp_settings_page')
		);
	}
	
	/**
	 * Update your register_smtp_settings method to include logo settings
	 */
	public function register_smtp_settings() {
		// Existing SMTP settings
		register_setting('csd_smtp_settings', 'csd_gmail_smtp_enabled');
		register_setting('csd_smtp_settings', 'csd_gmail_smtp_username');
		register_setting('csd_smtp_settings', 'csd_gmail_smtp_password');
		register_setting('csd_smtp_settings', 'csd_gmail_smtp_from_email');
		register_setting('csd_smtp_settings', 'csd_gmail_smtp_from_name');
		
		// NEW: Email logo settings
		register_setting('csd_smtp_settings', 'csd_email_logo_enabled');
		register_setting('csd_smtp_settings', 'csd_email_logo_url');
		register_setting('csd_smtp_settings', 'csd_email_logo_alt');
		register_setting('csd_smtp_settings', 'csd_email_org_name');
	}
	
	/**
	 * Updated render_smtp_settings_page method with logo settings
	 */
	public function render_smtp_settings_page() {
		// Handle form submission
		if (isset($_POST['submit']) && check_admin_referer('csd_smtp_settings_nonce')) {
			// SMTP settings
			update_option('csd_gmail_smtp_enabled', isset($_POST['csd_gmail_smtp_enabled']) ? 1 : 0);
			update_option('csd_gmail_smtp_username', sanitize_email($_POST['csd_gmail_smtp_username']));
			update_option('csd_gmail_smtp_from_email', sanitize_email($_POST['csd_gmail_smtp_from_email']));
			update_option('csd_gmail_smtp_from_name', sanitize_text_field($_POST['csd_gmail_smtp_from_name']));
			
			// Only update password if it's provided
			if (!empty($_POST['csd_gmail_smtp_password'])) {
				update_option('csd_gmail_smtp_password', sanitize_text_field($_POST['csd_gmail_smtp_password']));
			}
			
			// NEW: Logo settings
			update_option('csd_email_logo_enabled', isset($_POST['csd_email_logo_enabled']) ? 1 : 0);
			update_option('csd_email_logo_url', esc_url_raw($_POST['csd_email_logo_url']));
			update_option('csd_email_logo_alt', sanitize_text_field($_POST['csd_email_logo_alt']));
			update_option('csd_email_org_name', sanitize_text_field($_POST['csd_email_org_name']));
			
			echo '<div class="notice notice-success"><p>' . __('Settings saved successfully!', 'csd-manager') . '</p></div>';
		}
		
		// Get current settings
		$enabled = get_option('csd_gmail_smtp_enabled', false);
		$username = get_option('csd_gmail_smtp_username', '');
		$password = get_option('csd_gmail_smtp_password', '');
		$from_email = get_option('csd_gmail_smtp_from_email', get_option('admin_email'));
		$from_name = get_option('csd_gmail_smtp_from_name', get_bloginfo('name'));
		
		// NEW: Get logo settings
		$logo_enabled = get_option('csd_email_logo_enabled', false);
		$logo_url = get_option('csd_email_logo_url', '');
		$logo_alt = get_option('csd_email_logo_alt', get_bloginfo('name') . ' Logo');
		$org_name = get_option('csd_email_org_name', get_bloginfo('name'));
		?>
		<div class="wrap">
			<h1><?php _e('Email Settings', 'csd-manager'); ?></h1>
			
			<form method="post" action="">
				<?php wp_nonce_field('csd_smtp_settings_nonce'); ?>
				
				<!-- Gmail SMTP Configuration -->
				<div class="card" style="max-width: 800px;">
					<h2><?php _e('Gmail SMTP Configuration', 'csd-manager'); ?></h2>
					<p><?php _e('Configure Gmail SMTP to ensure reliable delivery of query monitoring notifications.', 'csd-manager'); ?></p>
					
					<table class="form-table">
						<tr>
							<th scope="row"><?php _e('Enable Gmail SMTP', 'csd-manager'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="csd_gmail_smtp_enabled" value="1" <?php checked($enabled, 1); ?> />
									<?php _e('Use Gmail SMTP for query monitoring emails', 'csd-manager'); ?>
								</label>
								<p class="description"><?php _e('When enabled, emails will be sent through Gmail SMTP instead of the server\'s default mail function.', 'csd-manager'); ?></p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('Gmail Username', 'csd-manager'); ?></th>
							<td>
								<input type="email" name="csd_gmail_smtp_username" value="<?php echo esc_attr($username); ?>" class="regular-text" placeholder="your-email@gmail.com" />
								<p class="description"><?php _e('Your Gmail email address.', 'csd-manager'); ?></p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('Gmail App Password', 'csd-manager'); ?></th>
							<td>
								<input type="password" name="csd_gmail_smtp_password" value="" class="regular-text" placeholder="<?php echo $password ? '••••••••••••••••' : 'Enter App Password'; ?>" />
								<p class="description">
									<?php _e('Your Gmail App Password (not your regular password).', 'csd-manager'); ?>
									<br>
									<strong><?php _e('Setup Instructions:', 'csd-manager'); ?></strong>
									<br>1. <?php _e('Enable 2-Step Verification on your Gmail account', 'csd-manager'); ?>
									<br>2. <?php _e('Go to Google Account settings > Security > App passwords', 'csd-manager'); ?>
									<br>3. <?php _e('Generate an app password for "Mail"', 'csd-manager'); ?>
									<br>4. <?php _e('Use that 16-character password here', 'csd-manager'); ?>
								</p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('From Email', 'csd-manager'); ?></th>
							<td>
								<input type="email" name="csd_gmail_smtp_from_email" value="<?php echo esc_attr($from_email); ?>" class="regular-text" />
								<p class="description"><?php _e('Email address that will appear as the sender. Should usually match your Gmail username.', 'csd-manager'); ?></p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('From Name', 'csd-manager'); ?></th>
							<td>
								<input type="text" name="csd_gmail_smtp_from_name" value="<?php echo esc_attr($from_name); ?>" class="regular-text" />
								<p class="description"><?php _e('Name that will appear as the sender.', 'csd-manager'); ?></p>
							</td>
						</tr>
					</table>
				</div>
				
				<!-- NEW: Email Branding Configuration -->
				<div class="card" style="max-width: 800px; margin-top: 20px;">
					<h2><?php _e('Email Branding & Logo', 'csd-manager'); ?></h2>
					<p><?php _e('Customize the appearance of your query monitoring emails with your organization\'s branding.', 'csd-manager'); ?></p>
					
					<table class="form-table">
						<tr>
							<th scope="row"><?php _e('Enable Logo in Emails', 'csd-manager'); ?></th>
							<td>
								<label>
									<input type="checkbox" name="csd_email_logo_enabled" value="1" <?php checked($logo_enabled, 1); ?> />
									<?php _e('Include logo in email headers', 'csd-manager'); ?>
								</label>
								<p class="description"><?php _e('When enabled, your logo will appear at the top of monitoring emails.', 'csd-manager'); ?></p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('Logo URL', 'csd-manager'); ?></th>
							<td>
								<input type="url" name="csd_email_logo_url" value="<?php echo esc_attr($logo_url); ?>" class="regular-text" placeholder="https://example.com/logo.png" />
								<p class="description">
									<?php _e('Full URL to your logo image. Recommended size: 200x60px or similar aspect ratio.', 'csd-manager'); ?>
									<br><?php _e('Supported formats: PNG, JPG, GIF, SVG', 'csd-manager'); ?>
								</p>
								<?php if ($logo_url): ?>
									<div style="margin-top: 10px; padding: 10px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
										<strong><?php _e('Preview:', 'csd-manager'); ?></strong><br>
										<img src="<?php echo esc_url($logo_url); ?>" alt="<?php echo esc_attr($logo_alt); ?>" style="max-height: 60px; max-width: 200px; object-fit: contain; margin-top: 5px;" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
										<span style="display: none; color: #d63384; font-size: 12px;">❌ Logo could not be loaded</span>
									</div>
								<?php endif; ?>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('Logo Alt Text', 'csd-manager'); ?></th>
							<td>
								<input type="text" name="csd_email_logo_alt" value="<?php echo esc_attr($logo_alt); ?>" class="regular-text" />
								<p class="description"><?php _e('Alternative text for the logo (for accessibility and when images don\'t load).', 'csd-manager'); ?></p>
							</td>
						</tr>
						
						<tr>
							<th scope="row"><?php _e('Organization Name', 'csd-manager'); ?></th>
							<td>
								<input type="text" name="csd_email_org_name" value="<?php echo esc_attr($org_name); ?>" class="regular-text" />
								<p class="description"><?php _e('Your organization name for the email footer and copyright notice.', 'csd-manager'); ?></p>
							</td>
						</tr>
					</table>
				</div>
				
				<?php submit_button(__('Save Settings', 'csd-manager')); ?>
			</form>
			
			<?php if ($enabled && $username && $password): ?>
			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h3><?php _e('Test Email Configuration', 'csd-manager'); ?></h3>
				<p><?php _e('Send a test email to verify your Gmail SMTP configuration and see how your branding looks.', 'csd-manager'); ?></p>
				
				<div id="smtp-test-section">
					<p>
						<label for="test-email"><?php _e('Test Email Address:', 'csd-manager'); ?></label><br>
						<input type="email" id="test-email" value="<?php echo esc_attr(wp_get_current_user()->user_email); ?>" class="regular-text" />
					</p>
					<p>
						<button type="button" id="send-test-email" class="button button-secondary"><?php _e('Send Test Email', 'csd-manager'); ?></button>
						<span id="test-email-status" style="margin-left: 10px;"></span>
					</p>
				</div>
				
				<script type="text/javascript">
				jQuery(document).ready(function($) {
					$('#send-test-email').click(function() {
						var button = $(this);
						var status = $('#test-email-status');
						var testEmail = $('#test-email').val();
						
						if (!testEmail) {
							status.html('<span style="color: red;"><?php _e('Please enter a test email address.', 'csd-manager'); ?></span>');
							return;
						}
						
						button.prop('disabled', true).text('<?php _e('Sending...', 'csd-manager'); ?>');
						status.html('<span style="color: blue;"><?php _e('Sending test email...', 'csd-manager'); ?></span>');
						
						$.ajax({
							url: ajaxurl,
							type: 'POST',
							data: {
								action: 'csd_test_smtp_email',
								test_email: testEmail,
								nonce: '<?php echo wp_create_nonce('csd-smtp-test-nonce'); ?>'
							},
							success: function(response) {
								if (response.success) {
									status.html('<span style="color: green;"><?php _e('Test email sent successfully! Check your inbox.', 'csd-manager'); ?></span>');
								} else {
									status.html('<span style="color: red;"><?php _e('Failed to send test email: ', 'csd-manager'); ?>' + response.data.message + '</span>');
								}
							},
							error: function() {
								status.html('<span style="color: red;"><?php _e('Error sending test email.', 'csd-manager'); ?></span>');
							},
							complete: function() {
								button.prop('disabled', false).text('<?php _e('Send Test Email', 'csd-manager'); ?>');
							}
						});
					});
				});
				</script>
			</div>
			<?php endif; ?>
			
			<div class="card" style="max-width: 800px; margin-top: 20px;">
				<h3><?php _e('Security & Best Practices', 'csd-manager'); ?></h3>
				<ul>
					<li><?php _e('Never use your regular Gmail password. Always use an App Password.', 'csd-manager'); ?></li>
					<li><?php _e('App Passwords can only be generated if 2-Step Verification is enabled.', 'csd-manager'); ?></li>
					<li><?php _e('Store credentials securely. Consider using wp-config.php constants for production.', 'csd-manager'); ?></li>
					<li><?php _e('Gmail has sending limits: 500 emails per day for free accounts, 2000 for paid accounts.', 'csd-manager'); ?></li>
					<li><?php _e('Host your logo on a reliable server (your website or CDN) for best email delivery.', 'csd-manager'); ?></li>
				</ul>
			</div>
		</div>
		<?php
	}
	
	/**
	 * AJAX handler for testing SMTP email - UPDATED VERSION
	 */
	public function ajax_test_smtp_email() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-smtp-test-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$test_email = sanitize_email($_POST['test_email']);
		
		if (!is_email($test_email)) {
			wp_send_json_error(array('message' => __('Invalid email address.', 'csd-manager')));
			return;
		}
		
		// Create test content using the enhanced template
		$subject = '[' . get_bloginfo('name') . '] Gmail SMTP & Email Template Test';
		$body = $this->create_test_email_body();
		
		// Send test email using the same method as monitoring emails
		if (method_exists($this, 'send_gmail_smtp_email')) {
			$sent = $this->send_gmail_smtp_email($test_email, 'Test Recipient', $subject, $body);
		} else {
			// Fallback to wp_mail if SMTP method doesn't exist yet
			$sent = wp_mail(
				$test_email,
				$subject,
				$body,
				array('Content-Type: text/html; charset=UTF-8')
			);
		}
		
		if ($sent) {
			wp_send_json_success(array('message' => __('Test email sent successfully! Check your inbox to see how your branding looks.', 'csd-manager')));
		} else {
			wp_send_json_error(array('message' => __('Failed to send test email. Check your settings and try again.', 'csd-manager')));
		}
	}
	
	/**
	 * Create test email body - GMAIL-OPTIMIZED VERSION
	 */
	private function create_test_email_body() {
		// Create sample test data
		$test_changes = array(
			'total_changes' => 5,
			'new_count' => 3,
			'modified_count' => 2,
			'deleted_count' => 0,
			'previous_total' => 125,
			'current_total' => 128
		);
		
		// Get logo settings
		$logo_settings = $this->get_email_logo_settings();
		
		$body = '<!DOCTYPE html>';
		$body .= '<html>';
		$body .= '<head>';
		$body .= '<meta charset="UTF-8">';
		$body .= '<style>';
		// Same minimal CSS as above
		$body .= 'body{font-family:Arial,sans-serif;line-height:1.6;color:#333;margin:0;padding:0;background:#f4f4f4}';
		$body .= '.container{max-width:600px;margin:0 auto;background:#fff;border-radius:8px;overflow:hidden}';
		$body .= '.header{background:linear-gradient(135deg, #ff9800 0%, #ffc107 100%);color:#fff;padding:30px;text-align:center}';
		$body .= '.logo{margin-bottom:15px}';
		$body .= '.logo img{max-height:50px;max-width:180px;background:rgba(255,255,255,0.2);padding:8px;border-radius:4px}';
		$body .= '.content{padding:25px}';
		$body .= '.test-notice{background:#fff3cd;border:1px solid #ffeaa7;border-radius:6px;padding:12px;margin:15px 0;text-align:center;color:#856404}';
		$body .= '.info-box{background:#f8f9fa;border-left:4px solid #ff9800;padding:15px;margin:15px 0;border-radius:0 4px 4px 0}';
		$body .= '.summary{background:#fff;border:1px solid #e0e0e0;border-radius:6px;padding:20px;margin:15px 0}';
		$body .= '.stats{display:table;width:100%;margin:15px 0}';
		$body .= '.stat{display:table-cell;text-align:center;padding:12px;background:#f8f9fa;border:1px solid #e0e0e0}';
		$body .= '.stat-num{font-size:20px;font-weight:bold;color:#ff9800;margin-bottom:4px}';
		$body .= '.stat-label{font-size:11px;color:#666;text-transform:uppercase}';
		$body .= '.overview{background:#e8f5e8;border:1px solid #c3e6c3;border-radius:6px;padding:18px;margin:15px 0}';
		$body .= '.highlight{background:linear-gradient(135deg, #ff6b6b 0%, #ffa726 100%);color:#fff;padding:12px;border-radius:4px;margin:10px 0;text-align:center}';
		$body .= '.footer{background:#f8f9fa;padding:15px;border-top:1px solid #e0e0e0;text-align:center;font-size:11px;color:#666}';
		$body .= '</style>';
		$body .= '</head>';
		$body .= '<body>';
		
		$body .= '<div class="container">';
		
		// Header with logo
		$body .= '<div class="header">';
		if ($logo_settings['enabled'] && !empty($logo_settings['url'])) {
			$body .= '<div class="logo">';
			$body .= '<img src="' . esc_url($logo_settings['url']) . '" alt="' . esc_attr($logo_settings['alt_text']) . '">';
			$body .= '</div>';
		}
		$body .= '<h1 style="margin:0;font-size:22px;">Email Template Test</h1>';
		$body .= '</div>';
		
		// Main content
		$body .= '<div class="content">';
		
		// Test notice
		$body .= '<div class="test-notice">';
		$body .= '<strong>This is a test email</strong><br>';
		$body .= 'Preview of your query monitoring email template';
		$body .= '</div>';
		
		// Query information
		$body .= '<div class="info-box">';
		$body .= '<p style="margin:4px 0;"><strong>Query:</strong> Sample - NCAA Football Head Coaches</p>';
		$body .= '<p style="margin:4px 0;"><strong>Date:</strong> ' . date('M j, Y g:i A T') . '</p>';
		$body .= '<p style="margin:4px 0;"><strong>System:</strong> ' . esc_html(get_bloginfo('name')) . '</p>';
		$body .= '</div>';
		
		// Changes summary with sample data
		$body .= '<div class="summary">';
		$body .= '<h3 style="margin-top:0;color:#495057;border-bottom:2px solid #e0e0e0;padding-bottom:8px;">Summary of Changes (Sample)</h3>';
		
		$body .= '<div class="highlight">';
		$body .= '<strong>' . number_format($test_changes['total_changes']) . ' changes detected</strong>';
		$body .= '</div>';
		
		$body .= '<div class="stats">';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($test_changes['new_count']) . '</div>';
		$body .= '<div class="stat-label">New</div>';
		$body .= '</div>';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($test_changes['modified_count']) . '</div>';
		$body .= '<div class="stat-label">Modified</div>';
		$body .= '</div>';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($test_changes['deleted_count']) . '</div>';
		$body .= '<div class="stat-label">Deleted</div>';
		$body .= '</div>';
		$body .= '<div class="stat">';
		$body .= '<div class="stat-num">' . number_format($test_changes['total_changes']) . '</div>';
		$body .= '<div class="stat-label">Total</div>';
		$body .= '</div>';
		$body .= '</div>';
		$body .= '</div>';
		
		// Data overview
		$body .= '<div class="overview">';
		$body .= '<h3 style="margin-top:0;color:#2e7d32;">Data Overview (Sample)</h3>';
		$body .= '<p style="margin:5px 0;"><strong>Previous:</strong> ' . number_format($test_changes['previous_total']) . ' records</p>';
		$body .= '<p style="margin:5px 0;"><strong>Current:</strong> ' . number_format($test_changes['current_total']) . ' records</p>';
		$body .= '<p style="margin:5px 0;"><strong>Net Change:</strong> <span style="color:#2e7d32;">+3 records</span></p>';
		$body .= '</div>';
		
		$body .= '</div>'; // End content
		
		// Footer
		$body .= '<div class="footer">';
		$body .= '<p style="margin:5px 0;">Test email from College Sports Directory Manager</p>';
		$body .= '<p style="margin:5px 0;">Your email template is working correctly!</p>';
		if (!empty($logo_settings['organization_name'])) {
			$body .= '<p style="margin:5px 0;">&copy; ' . date('Y') . ' ' . esc_html($logo_settings['organization_name']) . '</p>';
		}
		$body .= '</div>';
		
		$body .= '</div>'; // End container
		$body .= '</body>';
		$body .= '</html>';
		
		return $body;
	}
}