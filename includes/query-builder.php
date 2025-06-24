<?php
/**
 * Query Builder
 *
 * @package College Sports Directory Manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Query Builder Class
 */
class CSD_Query_Builder {
	/**
	 * Check if the saved_queries table exists and create it if it doesn't
	 */
	private function check_table_exists() {
		$wpdb = csd_db_connection();
		
		$table_name = csd_table('saved_queries');
		
		// Check if the table exists
		$table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") === $table_name;
		
		if (!$table_exists) {
			// Create the table
			$this->create_saved_queries_table();
		}
	}
	
	/**
	 * Tables and fields configuration
	 */
	private $tables_config = array();
	
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action('wp_ajax_csd_run_custom_query', array($this, 'ajax_run_custom_query'));
		add_action('wp_ajax_csd_get_field_values', array($this, 'ajax_get_field_values'));
		add_action('wp_ajax_csd_save_query', array($this, 'ajax_save_query'));
		add_action('wp_ajax_csd_load_query', array($this, 'ajax_load_query'));
		
		// Initialize tables configuration
		$this->init_tables_config();
	}
	
	/**
	 * Initialize tables configuration
	 */
	private function init_tables_config() {
		// Schools table
		$this->tables_config['schools'] = array(
			'label' => 'Schools',
			'table' => csd_table('schools'),
			'fields' => array(
				'id' => array('label' => 'ID', 'type' => 'number'),
				'school_name' => array('label' => 'School Name', 'type' => 'text'),
				'street_address_line_1' => array('label' => 'Address Line 1', 'type' => 'text'),
				'street_address_line_2' => array('label' => 'Address Line 2', 'type' => 'text'),
				'street_address_line_3' => array('label' => 'Address Line 3', 'type' => 'text'),
				'city' => array('label' => 'City', 'type' => 'text'),
				'state' => array('label' => 'State', 'type' => 'text'),
				'zipcode' => array('label' => 'Zip Code', 'type' => 'text'),
				'country' => array('label' => 'Country', 'type' => 'text'),
				'county' => array('label' => 'County', 'type' => 'text'),
				'school_divisions' => array('label' => 'School Divisions', 'type' => 'text'),
				'school_conferences' => array('label' => 'School Conferences', 'type' => 'text'),
				'school_level' => array('label' => 'School Level', 'type' => 'text'),
				'school_type' => array('label' => 'School Type', 'type' => 'text'),
				'school_enrollment' => array('label' => 'School Enrollment', 'type' => 'number'),
				'mascot' => array('label' => 'Mascot', 'type' => 'text'),
				'school_colors' => array('label' => 'School Colors', 'type' => 'text'),
				'school_website' => array('label' => 'School Website', 'type' => 'text'),
				'athletics_website' => array('label' => 'Athletics Website', 'type' => 'text'),
				'athletics_phone' => array('label' => 'Athletics Phone', 'type' => 'text'),
				'football_division' => array('label' => 'Football Division', 'type' => 'text'),
				'date_created' => array('label' => 'Date Created', 'type' => 'date'),
				'date_updated' => array('label' => 'Date Updated', 'type' => 'date')
			)
		);
		
		// Staff table
		$this->tables_config['staff'] = array(
			'label' => 'Staff',
			'table' => csd_table('staff'),
			'fields' => array(
				'id' => array('label' => 'ID', 'type' => 'number'),
				'full_name' => array('label' => 'Full Name', 'type' => 'text'),
				'title' => array('label' => 'Title', 'type' => 'text'),
				'sport_department' => array('label' => 'Sport/Department', 'type' => 'text'),
				'email' => array('label' => 'Email', 'type' => 'text'),
				'phone' => array('label' => 'Phone', 'type' => 'text'),
				'date_created' => array('label' => 'Date Created', 'type' => 'date'),
				'date_updated' => array('label' => 'Date Updated', 'type' => 'date')
			)
		);
		
		// School Staff relation table
		$this->tables_config['school_staff'] = array(
			'label' => 'School Staff Relations',
			'table' => csd_table('school_staff'),
			'fields' => array(
				'id' => array('label' => 'ID', 'type' => 'number'),
				'school_id' => array('label' => 'School ID', 'type' => 'number'),
				'staff_id' => array('label' => 'Staff ID', 'type' => 'number'),
				'date_created' => array('label' => 'Date Created', 'type' => 'date')
			)
		);
	}
	
	/**
	 * Render query builder page
	 */
	public function render_page() {
		// Check if table exists and create it if it doesn't
		$this->check_table_exists();
		
		// Dequeue the problematic script
		wp_dequeue_script('csd-admin-scripts');
		
		// Enqueue only jQuery and UI components
		wp_enqueue_script('jquery');
		wp_enqueue_script('jquery-ui-core');
		wp_enqueue_script('jquery-ui-datepicker');
		
		// Enqueue styles
		wp_enqueue_style('csd-admin-styles');
		
		// Register and enqueue our custom script for the query builder
		// Register and enqueue the custom query builder script
		wp_register_script(
			'csd-query-builder-js', 
			CSD_MANAGER_PLUGIN_URL . 'assets/js/query-builder.js',
			array('jquery'),  // Specify jQuery as a dependency
			CSD_MANAGER_VERSION, 
			true  // Load in footer
		);
		
		wp_enqueue_script('csd-query-builder-js');
		
		// Localize script with AJAX data
		wp_localize_script('csd-query-builder-js', 'csd_ajax', array(
			'ajax_url' => admin_url('admin-ajax.php'),
			'nonce' => wp_create_nonce('csd-ajax-nonce')
		));
		
		// Add this code right after wp_enqueue_script('csd-query-builder-js')
		wp_enqueue_style('codemirror', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.css');
		wp_enqueue_style('codemirror-theme', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/theme/monokai.min.css');
		wp_enqueue_script('codemirror', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/codemirror.min.js', array('jquery'), null, true);
		wp_enqueue_script('codemirror-sql', 'https://cdnjs.cloudflare.com/ajax/libs/codemirror/5.65.2/mode/sql/sql.min.js', array('codemirror'), null, true);
		
		// Get saved queries for dropdown
		$wpdb = csd_db_connection();
		$saved_queries = $wpdb->get_results("SELECT id, query_name FROM " . csd_table('saved_queries') . " ORDER BY query_name");
		
		// Render user assignment modal
		$this->render_user_assignment_modal();
		?>
		<div class="wrap">
			<h1 style="display: inline;"><?php _e('Custom Query Builder', 'csd-manager'); ?></h1>&nbsp;|&nbsp;<a href="https://use1.brightpearlapp.com/report.php?report_type=sales&output=screen&order_type=1&date_timeframe=30days&date_type=date_purchased&sortby=o.orders_id&sort_dir=DESC&hide_cancelled=true" target="_blank">Create Brightpearl Quote</a>&nbsp;|&nbsp;<a href="https://collegesportsdirectory.com/cc-auth/" target="_blank">Generate Credit Card Auth PDF</a>
			
			<div class="csd-query-builder-container">
				<!-- Saved queries section -->
				<div class="csd-saved-queries">
					<h2><?php _e('Saved Queries', 'csd-manager'); ?></h2>
					<select id="csd-load-query">
						<option value=""><?php _e('-- Select a saved query --', 'csd-manager'); ?></option>
						<?php foreach ($saved_queries as $query): ?>
							<option value="<?php echo esc_attr($query->id); ?>"><?php echo esc_html($query->query_name); ?></option>
						<?php endforeach; ?>
					</select>
					<button type="button" id="csd-load-query-btn" class="button"><?php _e('Load', 'csd-manager'); ?></button>
					<button type="button" id="csd-delete-query-btn" class="button"><?php _e('Delete', 'csd-manager'); ?></button>
				</div>
				
				<!-- Query builder form -->
				<form id="csd-query-builder-form">
					<div class="csd-query-main-panel">
						<div class="csd-query-tabs">
							<button type="button" class="csd-query-tab active" data-panel="fields"><?php _e('Fields', 'csd-manager'); ?></button>
							<button type="button" class="csd-query-tab" data-panel="conditions"><?php _e('Conditions', 'csd-manager'); ?></button>
							<button type="button" class="csd-query-tab" data-panel="options"><?php _e('Options', 'csd-manager'); ?></button>
						</div>
						
						<!-- Fields selection panel -->
						<div class="csd-query-panel active" id="csd-fields-panel">
							<h3><?php _e('Select Fields to Display', 'csd-manager'); ?></h3>
							
							<?php foreach ($this->tables_config as $table_key => $table_config): ?>
								<div class="csd-table-fields">
									<h4><?php echo esc_html($table_config['label']); ?></h4>
									
									<div class="csd-field-list">
										<?php foreach ($table_config['fields'] as $field_key => $field_config): ?>
											<div class="csd-field-checkbox">
												<label>
													<input type="checkbox" name="fields[]" value="<?php echo esc_attr($table_key . '.' . $field_key); ?>">
													<?php echo esc_html($field_config['label']); ?>
												</label>
											</div>
										<?php endforeach; ?>
									</div>
								</div>
							<?php endforeach; ?>
							
							<div class="csd-field-actions">
								<button type="button" id="csd-select-all-fields" class="button"><?php _e('Select All', 'csd-manager'); ?></button>
								<button type="button" id="csd-select-none-fields" class="button"><?php _e('Select None', 'csd-manager'); ?></button>
							</div>
						</div>
						
						<!-- Conditions panel -->
						<div class="csd-query-panel" id="csd-conditions-panel">
							<h3><?php _e('Set Query Conditions', 'csd-manager'); ?></h3>
							
							<div id="csd-conditions-container">
								<div class="csd-condition-group" data-group="0">
									<div class="csd-condition-group-header">
										<h4><?php _e('Condition Group', 'csd-manager'); ?> 1</h4>
										<button type="button" class="csd-remove-group button" <?php echo 'style="display:none"'; ?>><?php _e('Remove Group', 'csd-manager'); ?></button>
									</div>
									
									<div class="csd-conditions">
										<div class="csd-condition" data-index="0">
											<select class="csd-condition-field" name="conditions[0][0][field]">
												<option value=""><?php _e('-- Select Field --', 'csd-manager'); ?></option>
												<?php foreach ($this->tables_config as $table_key => $table_config): ?>
													<optgroup label="<?php echo esc_attr($table_config['label']); ?>">
														<?php foreach ($table_config['fields'] as $field_key => $field_config): ?>
															<option value="<?php echo esc_attr($table_key . '.' . $field_key); ?>" data-type="<?php echo esc_attr($field_config['type']); ?>">
																<?php echo esc_html($field_config['label']); ?>
															</option>
														<?php endforeach; ?>
													</optgroup>
												<?php endforeach; ?>
											</select>
											
											<select class="csd-condition-operator" name="conditions[0][0][operator]">
												<option value=""><?php _e('-- Select Operator --', 'csd-manager'); ?></option>
												<option value="="><?php _e('= (equals)', 'csd-manager'); ?></option>
												<option value="!="><?php _e('!= (not equals)', 'csd-manager'); ?></option>
												<option value="LIKE"><?php _e('LIKE', 'csd-manager'); ?></option>
												<option value="LIKE %...%"><?php _e('LIKE %...% (contains)', 'csd-manager'); ?></option>
												<option value="NOT LIKE"><?php _e('NOT LIKE', 'csd-manager'); ?></option>
												<option value="NOT LIKE %...%"><?php _e('NOT LIKE %...% (not contains)', 'csd-manager'); ?></option>
												<option value="REGEXP"><?php _e('REGEXP', 'csd-manager'); ?></option>
												<option value="REGEXP ^...$"><?php _e('REGEXP ^...$ (exact match)', 'csd-manager'); ?></option>
												<option value="NOT REGEXP"><?php _e('NOT REGEXP', 'csd-manager'); ?></option>
												<option value="= ''"><?php _e("= '' (empty)", 'csd-manager'); ?></option>
												<option value="!= ''"><?php _e("!= '' (not empty)", 'csd-manager'); ?></option>
												<option value="IN"><?php _e('IN (...)', 'csd-manager'); ?></option>
												<option value="NOT IN"><?php _e('NOT IN (...)', 'csd-manager'); ?></option>
												<option value="BETWEEN"><?php _e('BETWEEN', 'csd-manager'); ?></option>
												<option value="NOT BETWEEN"><?php _e('NOT BETWEEN', 'csd-manager'); ?></option>
												<option value=">"><?php _e('> (greater than)', 'csd-manager'); ?></option>
												<option value=">="><?php _e('>= (greater than or equal)', 'csd-manager'); ?></option>
												<option value="<"><?php _e('< (less than)', 'csd-manager'); ?></option>
												<option value="<="><?php _e('<= (less than or equal)', 'csd-manager'); ?></option>
											</select>
											
											<div class="csd-condition-value-container">
												<input type="text" class="csd-condition-value" name="conditions[0][0][value]" placeholder="<?php _e('Enter value', 'csd-manager'); ?>">
												
												<div class="csd-between-values" style="display:none;">
													<input type="text" class="csd-condition-value-2" name="conditions[0][0][value2]" placeholder="<?php _e('End value', 'csd-manager'); ?>">
												</div>
												
												<button type="button" class="csd-get-values button" title="<?php _e('Get possible values', 'csd-manager'); ?>">
													<span class="dashicons dashicons-arrow-down"></span>
												</button>
											</div>
											
											<select class="csd-condition-relation" name="conditions[0][0][relation]">
												<option value="AND"><?php _e('AND', 'csd-manager'); ?></option>
												<option value="OR"><?php _e('OR', 'csd-manager'); ?></option>
											</select>
											
											<button type="button" class="csd-remove-condition button" <?php echo 'style="display:none"'; ?>>
												<span class="dashicons dashicons-no"></span>
											</button>
										</div>
									</div>
									
									<div class="csd-condition-actions">
										<button type="button" class="csd-add-condition button"><?php _e('Add Condition', 'csd-manager'); ?></button>
									</div>
								</div>
							</div>
							
							<div class="csd-group-actions">
								<button type="button" id="csd-add-group" class="button"><?php _e('Add Condition Group (OR)', 'csd-manager'); ?></button>
							</div>
						</div>
						
						<!-- Options panel -->
						<div class="csd-query-panel" id="csd-options-panel">
							<h3><?php _e('Query Options', 'csd-manager'); ?></h3>
							
							<div class="csd-options-form">
								<div class="csd-option">
									<label for="csd-limit"><?php _e('Limit Results:', 'csd-manager'); ?></label>
									<input type="number" id="csd-limit" name="limit" value="100" min="1">
								</div>
								
								<div class="csd-option">
									<label for="csd-order-by"><?php _e('Order By:', 'csd-manager'); ?></label>
									<select id="csd-order-by" name="order_by">
										<option value=""><?php _e('-- Select Field --', 'csd-manager'); ?></option>
										<?php foreach ($this->tables_config as $table_key => $table_config): ?>
											<optgroup label="<?php echo esc_attr($table_config['label']); ?>">
												<?php foreach ($table_config['fields'] as $field_key => $field_config): ?>
													<option value="<?php echo esc_attr($table_key . '.' . $field_key); ?>">
														<?php echo esc_html($field_config['label']); ?>
													</option>
												<?php endforeach; ?>
											</optgroup>
										<?php endforeach; ?>
									</select>
									
									<select id="csd-order-dir" name="order_dir">
										<option value="ASC"><?php _e('Ascending', 'csd-manager'); ?></option>
										<option value="DESC"><?php _e('Descending', 'csd-manager'); ?></option>
									</select>
								</div>
								
								<div class="csd-option">
									<label for="csd-join-type"><?php _e('Join Type:', 'csd-manager'); ?></label>
									<select id="csd-join-type" name="join_type">
										<option value="LEFT JOIN"><?php _e('LEFT JOIN (include all records, even if no match)', 'csd-manager'); ?></option>
										<option value="INNER JOIN"><?php _e('INNER JOIN (only include records with matches)', 'csd-manager'); ?></option>
									</select>
								</div>
								
								<div class="csd-option">
									<label for="csd-query-name"><?php _e('Save Query As:', 'csd-manager'); ?></label>
									<input type="text" id="csd-query-name" name="query_name" placeholder="<?php _e('Enter name to save this query', 'csd-manager'); ?>">
									<button type="button" id="csd-save-query-btn" class="button"><?php _e('Save Query', 'csd-manager'); ?></button>
								</div>
							</div>
						</div>
					</div>
					
					<div class="csd-query-actions">
						<button type="submit" id="csd-run-query" class="button button-primary button-large"><?php _e('Run Query', 'csd-manager'); ?></button>
						<button type="button" id="csd-clear-form" class="button button-large"><?php _e('Clear Form', 'csd-manager'); ?></button>
					</div>
				</form>
				
				<div class="csd-query-results-container">
					<div class="csd-query-count-container">
						<h3><?php _e('Query Results', 'csd-manager'); ?></h3>
						<div class="csd-query-count">
							<span class="csd-count-label"><?php _e('Records found:', 'csd-manager'); ?></span>
							<span id="csd-record-count">0</span>
						</div>
					</div>
					
					<!-- Replace the existing SQL query textarea with this -->
					<div class="csd-query-sql-container">
						<h4><?php _e('SQL Query', 'csd-manager'); ?></h4>
						<textarea id="csd-sql-query" rows="8" readonly 
							placeholder="<?php _e('SQL query will appear here after running', 'csd-manager'); ?>"></textarea>
						<div class="csd-sql-actions">
							<button type="button" id="csd-edit-sql" class="button"><?php _e('Edit SQL', 'csd-manager'); ?></button>
							<button type="button" id="csd-run-sql" class="button" style="display:none;"><?php _e('Run SQL', 'csd-manager'); ?></button>
							<button type="button" id="csd-cancel-sql-edit" class="button" style="display:none;"><?php _e('Cancel Edit', 'csd-manager'); ?></button>
						</div>
					</div>
					
					<div class="csd-query-table-container">
						<div id="csd-query-results"></div>
						
						<div class="csd-export-actions">
							<button type="button" id="csd-export-csv" class="button"><?php _e('Export to CSV', 'csd-manager'); ?></button>
						</div>
					</div>
				</div>
			</div>
		</div>
		
		<style type="text/css">
			/* Query Builder Styles */
			.csd-query-builder-container {
				margin-top: 20px;
			}
			
			.csd-saved-queries {
				margin-bottom: 20px;
				padding: 15px;
				background: #f8f8f8;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
			
			.csd-query-main-panel {
				background: #fff;
				border: 1px solid #ddd;
				margin-bottom: 20px;
			}
			
			.csd-query-tabs {
				display: flex;
				border-bottom: 1px solid #ddd;
				background: #f5f5f5;
			}
			
			.csd-query-tab {
				padding: 10px 15px;
				border: none;
				background: none;
				border-right: 1px solid #ddd;
				cursor: pointer;
				font-weight: 600;
			}
			
			.csd-query-tab.active {
				background: #fff;
				border-bottom: 2px solid #2271b1;
			}
			
			.csd-query-panel {
				display: none;
				padding: 20px;
			}
			
			.csd-query-panel.active {
				display: block;
			}
			
			.csd-table-fields {
				margin-bottom: 20px;
			}
			
			.csd-field-list {
				display: flex;
				flex-wrap: wrap;
				margin: 0 -10px;
			}
			
			.csd-field-checkbox {
				flex: 0 0 25%;
				padding: 5px 10px;
				box-sizing: border-box;
			}
			
			@media (max-width: 1200px) {
				.csd-field-checkbox {
					flex: 0 0 33.33%;
				}
			}
			
			@media (max-width: 782px) {
				.csd-field-checkbox {
					flex: 0 0 50%;
				}
			}
			
			.csd-field-actions {
				margin-top: 15px;
				text-align: right;
			}
			
			.csd-condition-group {
				margin-bottom: 20px;
				padding: 15px;
				background: #f8f8f8;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
			
			.csd-condition-group-header {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 10px;
			}
			
			.csd-condition-group-header h4 {
				margin: 0;
			}
			
			.csd-condition {
				display: flex;
				margin-bottom: 10px;
				align-items: center;
			}
			
			.csd-condition-field,
			.csd-condition-operator {
				width: 200px;
				margin-right: 10px;
			}
			
			.csd-condition-value-container {
				flex: 1;
				display: flex;
				align-items: center;
				margin-right: 10px;
			}
			
			.csd-condition-value {
				flex: 1;
				margin-right: 5px;
			}
			
			.csd-between-values {
				margin-left: 5px;
				margin-right: 5px;
			}
			
			.csd-condition-value-2 {
				width: 150px;
			}
			
			.csd-values-dropdown {
				position: absolute;
				margin-top: 30px;
				z-index: 100;
				background: white;
				border: 1px solid #ddd;
				max-height: 200px;
				overflow-y: auto;
				width: 200px;
			}
			
			.csd-condition-relation {
				width: 80px;
				margin-right: 10px;
			}
			
			.csd-condition-actions {
				margin-top: 15px;
			}
			
			.csd-group-actions {
				margin-top: 20px;
			}
			
			.csd-options-form {
				max-width: 600px;
			}
			
			.csd-option {
				margin-bottom: 15px;
			}
			
			.csd-option label {
				display: block;
				margin-bottom: 5px;
				font-weight: 600;
			}
			
			.csd-query-actions {
				margin: 20px 0;
			}
			
			.csd-query-results-container {
				margin-top: 30px;
			}
			
			.csd-query-count-container {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-bottom: 15px;
			}
			
			.csd-query-count {
				font-size: 16px;
				font-weight: 600;
			}
			
			#csd-record-count {
				color: #2271b1;
				font-size: 18px;
				font-weight: 700;
			}
			
			.csd-query-sql-container {
				margin-bottom: 20px;
			}
			
			#csd-sql-query {
				width: 100%;
				font-family: monospace;
				margin-bottom: 10px;
			}
			
			.csd-query-table-container {
				overflow-x: auto;
				margin-bottom: 20px;
			}
			
			.csd-query-table-container table {
				width: 100%;
				border-collapse: collapse;
				margin-bottom: 20px;
			}
			
			.csd-query-table-container th,
			.csd-query-table-container td {
				padding: 8px;
				text-align: left;
				border: 1px solid #ddd;
			}
			
			.csd-query-table-container th {
				background-color: #f5f5f5;
				font-weight: 600;
			}
			
			.csd-query-table-container tr:nth-child(even) {
				background-color: #f9f9f9;
			}
			
			.csd-export-actions {
				margin-top: 15px;
				text-align: right;
			}
			
			/* Make room for the value dropdown */
			.csd-condition-value-container {
				position: relative;
			}
			
			/* Add this to your existing CSS for the query builder */
			.csd-results-table-wrapper {
				overflow-x: auto;
				width: 100%;
				margin-bottom: 20px;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
			
			.csd-results-table-wrapper table {
				width: 100%;
				border-collapse: collapse;
				margin: 0;
			}
			
			.csd-results-table-wrapper th,
			.csd-results-table-wrapper td {
				padding: 10px;
				border: 1px solid #eee;
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
				max-width: 250px; /* Maximum column width */
			}
			
			/* Allow cell content to wrap when hovered */
			.csd-results-table-wrapper td:hover {
				white-space: normal;
				overflow: visible;
				max-width: none;
				background-color: #f9f9f9;
				position: relative;
				z-index: 1;
			}
			
			.csd-results-table-wrapper th {
				background-color: #f5f5f5;
				font-weight: 600;
				position: sticky;
				top: 0;
			}
			
			/* Alternating row colors */
			.csd-results-table-wrapper tr:nth-child(even) {
				background-color: #f9f9f9;
			}
			
			/* Hover effect for rows */
			.csd-results-table-wrapper tr:hover {
				background-color: #f0f0f0;
			}
			
			/* Responsive adjustments */
			@media screen and (max-width: 782px) {
				.csd-results-table-wrapper td, 
				.csd-results-table-wrapper th {
					padding: 8px 5px;
				}
			}
			
			/* Improved SQL textarea styling */
			#csd-sql-query {
				width: 100%;
				font-family: monospace;
				padding: 12px;
				line-height: 1.5;
				border: 1px solid #ddd;
				border-radius: 4px;
				background-color: #f9f9f9;
				resize: vertical;
				min-height: 150px;
				white-space: pre;
				overflow-x: auto;
			}
			
			#csd-sql-query:focus {
				background-color: #fff;
				border-color: #2271b1;
				box-shadow: 0 0 0 1px #2271b1;
				outline: none;
			}
			
			#csd-sql-query[readonly]:hover {
				cursor: pointer;
				background-color: #f0f0f0;
			}
			
			.csd-sql-actions {
				margin-top: 10px;
				margin-bottom: 20px;
			}
			
			/* Add this to your CSS (either in the style tag in query-builder.php or in admin.css) */
			.csd-results-table-wrapper {
				overflow-x: auto;
				max-width: 100%;
				margin-bottom: 20px;
				border: 1px solid #ddd;
				border-radius: 4px;
			}
			
			.csd-results-table-wrapper table {
				table-layout: auto;  /* This enables auto-width columns */
				border-collapse: collapse;
				margin: 0;
				min-width: 100%;
			}
			
			.csd-results-table-wrapper th {
				white-space: nowrap;
				overflow: hidden;
				text-overflow: ellipsis;
				background-color: #f5f5f5;
				font-weight: 600;
				position: sticky;
				top: 0;
				z-index: 10;
			}
			
			.csd-results-table-wrapper td {
				max-width: 300px;
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			
			/* Add tooltip-like behavior on hover */
			.csd-results-table-wrapper td:hover {
				white-space: normal;
				overflow: visible;
				position: relative;
				background-color: #f9f9f9;
				z-index: 1;
				box-shadow: 0 0 5px rgba(0,0,0,0.2);
			}
			
			/* Add this to your CSS */
			.csd-resizable-table th {
				position: relative;
				padding-right: 20px;
			}
			
			.csd-resizable-table th .resize-handle {
				position: absolute;
				top: 0;
				right: 0;
				width: 8px;
				height: 100%;
				cursor: col-resize;
				user-select: none;
			}
			
			.csd-resizable-table th .resize-handle:hover,
			.csd-resizable-table th .resize-handle.resizing {
				background-color: #0073aa;
			}
			
			.csd-resizable-table th .th-content {
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
			
			/* Add this to your existing CSS in query-builder.php */
			.CodeMirror {
			  border: 1px solid #ddd;
			  height: auto;
			  min-height: 100px;
			  transition: height 0.2s ease;
			}
			
			/* Add this to restore resizable editor */
			.CodeMirror {
			  resize: vertical;
			  overflow: auto !important;
			  min-height: 150px;
			  border: 1px solid #ddd;
			}
			
			/* Make sure the content doesn't overflow */
			.CodeMirror-scroll {
			  overflow-y: hidden;
			  overflow-x: auto;
			}
			
			.resizable-cm {
			  resize: vertical;
			  overflow: auto !important;
			  min-height: 100px;
			  transition: height 0.1s ease;
			}
			.CodeMirror {
			  border: 1px solid #ddd;
			  height: auto;
			  transition: height 0.1s ease;
			}
			.CodeMirror-focused {
			  border-color: #2271b1;
			  box-shadow: 0 0 0 1px #2271b1;
			}
			.CodeMirror-scroll {
			  min-height: 100px;
			}
			/* Dragging handle style */
			.CodeMirror .CodeMirror-scrollbar-filler {
			  background-color: #f0f0f0;
			  cursor: ns-resize;
			}
			.CodeMirror:hover .CodeMirror-scrollbar-filler {
			  background-color: #ddd;
			}
			
			.csd-pagination {
				display: flex;
				justify-content: space-between;
				align-items: center;
				margin-top: 20px;
				margin-bottom: 20px;
			}
			
			.csd-pagination-counts {
				color: #666;
			}
			
			.csd-pagination-links {
				display: flex;
				align-items: center;
				flex-wrap: wrap;
			}
			
			.csd-pagination-links a, 
			.csd-pagination-links span.csd-page-number {
				margin: 0 2px;
			}
			
			.csd-pagination-dots {
				margin: 0 5px;
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
				min-width: 70px;
			}
			
			@media screen and (max-width: 782px) {
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
		<!-- Add this just before the final closing PHP tag -->
		<script type="text/javascript">
		(function($) {
			'use strict';
			
			$(document).ready(function() {
				// Add "Assign to Users" button to each query in the saved queries list
				$('#csd-load-query').after('<button type="button" id="csd-assign-query-btn" class="button"><?php _e('Assign to Users', 'csd-manager'); ?></button>');
					
				// Handle click on "Assign to Users" button
				$('#csd-assign-query-btn').on('click', function() {
					var queryId = $('#csd-load-query').val();
					var queryName = $('#csd-load-query option:selected').text();
					
					if (!queryId) {
						alert('<?php _e('Please select a saved query first.', 'csd-manager'); ?>');
						return;
					}
					
					// Initialize Select2 for user selection if it exists
					if (typeof $.fn.select2 !== 'undefined') {
						$('#csd-user-select').select2({
							placeholder: '<?php _e('Select users...', 'csd-manager'); ?>',
							width: '100%',
							ajax: {
								url: csd_ajax.ajax_url,
								dataType: 'json',
								delay: 250,
								data: function (params) {
									return {
										action: 'csd_search_users',
										search: params.term,
										nonce: csd_ajax.nonce
									};
								},
								processResults: function (data) {
									return {
										results: data.data.users
									};
								},
								cache: true
							},
							minimumInputLength: 2
						});
					}
					
					// Set query ID and name
					$('#csd-assign-query-id').val(queryId);
					$('#csd-assign-query-name').text(queryName);
					
					// Load current assignments
					loadQueryUsers(queryId);
					
					// Show the modal
					$('#csd-user-assignment-modal').show();
				});
				
				// Close modal when clicking on X or Cancel
				$('.csd-modal-close, #csd-cancel-assignment').on('click', function() {
					$('#csd-user-assignment-modal').hide();
				});
				
				// Also close modal when clicking outside of modal content
				$(window).on('click', function(event) {
					var modal = $('#csd-user-assignment-modal');
					if (event.target === modal[0]) {
						modal.hide();
					}
				});
				
				// Assign to selected users
				$('#csd-assign-users').on('click', function() {
					var queryId = $('#csd-assign-query-id').val();
					var userIds = $('#csd-user-select').val();
					
					if (!queryId) {
						alert('<?php _e('Query ID is missing.', 'csd-manager'); ?>');
						return;
					}
					
					if (!userIds || userIds.length === 0) {
						alert('<?php _e('Please select at least one user.', 'csd-manager'); ?>');
						return;
					}
					
					// Show loading state
					$('#csd-assign-users').prop('disabled', true).text('<?php _e('Assigning...', 'csd-manager'); ?>');
					
					// Call AJAX to assign
					$.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_assign_query_to_users',
							query_id: queryId,
							user_ids: userIds,
							nonce: csd_ajax.nonce
						},
						success: function(response) {
							$('#csd-assign-users').prop('disabled', false).text('<?php _e('Assign to Selected Users', 'csd-manager'); ?>');
							
							if (response.success) {
								// Show success message
								$('#csd-assignment-message')
									.removeClass('notice-error')
									.addClass('notice-success')
									.html('<p>' + response.data.message + '</p>')
									.show();
								
								// Clear selection
								$('#csd-user-select').val(null).trigger('change');
								
								// Reload assignments
								loadQueryUsers(queryId);
							} else {
								// Show error message
								$('#csd-assignment-message')
									.removeClass('notice-success')
									.addClass('notice-error')
									.html('<p>' + (response.data.message || '<?php _e('Error assigning query to users.', 'csd-manager'); ?>') + '</p>')
									.show();
							}
						},
						error: function() {
							$('#csd-assign-users').prop('disabled', false).text('<?php _e('Assign to Selected Users', 'csd-manager'); ?>');
							
							// Show error message
							$('#csd-assignment-message')
								.removeClass('notice-success')
								.addClass('notice-error')
								.html('<p><?php _e('Error connecting to server. Please try again.', 'csd-manager'); ?></p>')
								.show();
						}
					});
				});
					
				// Load query users
				function loadQueryUsers(queryId) {
					$('#csd-current-users-list').html('<p><?php _e('Loading...', 'csd-manager'); ?></p>');
					
					$.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_get_query_users',
							query_id: queryId,
							nonce: csd_ajax.nonce
						},
						success: function(response) {
							if (response.success) {
								var users = response.data.users;
								
								if (users.length === 0) {
									$('#csd-current-users-list').html('<p><?php _e('No users currently assigned to this query.', 'csd-manager'); ?></p>');
									return;
								}
								
								var html = '<div class="csd-user-list">';
								html += '<table>';
								html += '<thead><tr>';
								html += '<th><?php _e('Name', 'csd-manager'); ?></th>';
								html += '<th><?php _e('Email', 'csd-manager'); ?></th>';
								html += '<th><?php _e('Date Assigned', 'csd-manager'); ?></th>';
								html += '<th><?php _e('Actions', 'csd-manager'); ?></th>';
								html += '</tr></thead>';
								html += '<tbody>';
								
								$.each(users, function(index, user) {
									html += '<tr>';
									html += '<td>' + user.name + '</td>';
									html += '<td>' + user.email + '</td>';
									html += '<td>' + formatDate(user.date_assigned) + '</td>';
									html += '<td class="csd-user-actions">';
									html += '<button type="button" class="button button-small csd-remove-user" data-id="' + user.assignment_id + '">';
									html += '<?php _e('Remove', 'csd-manager'); ?>';
									html += '</button>';
									html += '</td>';
									html += '</tr>';
								});
								
								html += '</tbody></table></div>';
								
								$('#csd-current-users-list').html(html);
							} else {
								$('#csd-current-users-list').html('<p class="notice notice-error">' + (response.data.message || '<?php _e('Error loading assigned users.', 'csd-manager'); ?>') + '</p>');
							}
						},
						error: function() {
							$('#csd-current-users-list').html('<p class="notice notice-error"><?php _e('Error connecting to server. Please try again.', 'csd-manager'); ?></p>');
						}
					});
				}
				
				// Remove user assignment
				$(document).on('click', '.csd-remove-user', function() {
					if (!confirm('<?php _e('Are you sure you want to remove this user assignment?', 'csd-manager'); ?>')) {
						return;
					}
					
					var assignmentId = $(this).data('id');
					var queryId = $('#csd-assign-query-id').val();
					
					$(this).prop('disabled', true).text('<?php _e('Removing...', 'csd-manager'); ?>');
					
					$.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_remove_query_user',
							assignment_id: assignmentId,
							nonce: csd_ajax.nonce
						},
						success: function(response) {
							if (response.success) {
								// Show success message
								$('#csd-assignment-message')
									.removeClass('notice-error')
									.addClass('notice-success')
									.html('<p>' + response.data.message + '</p>')
									.show();
								
								// Reload assignments
								loadQueryUsers(queryId);
							} else {
								// Show error message
								$('#csd-assignment-message')
									.removeClass('notice-success')
									.addClass('notice-error')
									.html('<p>' + (response.data.message || '<?php _e('Error removing user assignment.', 'csd-manager'); ?>') + '</p>')
									.show();
								
								// Re-enable button
								$('.csd-remove-user[data-id="' + assignmentId + '"]')
									.prop('disabled', false)
									.text('<?php _e('Remove', 'csd-manager'); ?>');
							}
						},
						error: function() {
							// Show error message
							$('#csd-assignment-message')
								.removeClass('notice-success')
								.addClass('notice-error')
								.html('<p><?php _e('Error connecting to server. Please try again.', 'csd-manager'); ?></p>')
								.show();
							
							// Re-enable button
							$('.csd-remove-user[data-id="' + assignmentId + '"]')
								.prop('disabled', false)
								.text('<?php _e('Remove', 'csd-manager'); ?>');
						}
					});
				});
				
				// Format date for display
				function formatDate(dateString) {
					var date = new Date(dateString);
					return date.toLocaleDateString();
				}
			});
		})(jQuery);
		</script>
		<?php
	}
	
	/**
	 * AJAX handler for running a custom query
	 */
	public function ajax_run_custom_query() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		// Check if we're running a custom SQL query directly
		if (isset($_POST['custom_sql'])) {
			$this->run_custom_sql_query();
			return;
		}
		
		// Parse form data
		parse_str($_POST['form_data'], $form_data);
		
		// Get pagination parameters
		$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
		$per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 25;
		
		// Validate fields
		if (!isset($form_data['fields']) || !is_array($form_data['fields']) || empty($form_data['fields'])) {
			wp_send_json_error(array('message' => __('Please select at least one field to display.', 'csd-manager')));
			return;
		}
		
		try {
			// Build SQL query without pagination to get total count
			$count_sql = $this->build_count_sql_query($form_data);
			
			// Build paginated SQL query for actual data
			$sql = $this->build_sql_query($form_data, true, $page, $per_page);
			
			// Clean the SQL queries
			$count_sql = $this->clean_sql_query($count_sql);
			$sql = $this->clean_sql_query($sql);
			
			// Get total count
			$total_count = $this->get_query_count($count_sql);
			
			// Run the paginated query
			$results = $this->execute_query($sql);
			
			// Generate results HTML with pagination
			$html = $this->generate_results_html($results, $page, $per_page, $total_count);
			
			// Calculate total pages
			$total_pages = ceil($total_count / $per_page);
			
			wp_send_json_success(array(
				'count' => $total_count,
				'current_page' => $page,
				'per_page' => $per_page,
				'total_pages' => $total_pages,
				'sql' => $sql,
				'html' => $html
			));
		} catch (Exception $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}
	
	/**
	 * Build SQL query for counting total results
	 * 
	 * @param array $form_data Form data
	 * @return string SQL count query
	 */
	public function build_count_sql_query($form_data) {
		// Determine which tables are needed
		$tables_needed = array();
		foreach ($form_data['fields'] as $field) {
			list($table, $field_name) = explode('.', $field);
			$tables_needed[$table] = true;
		}
		
		// Add tables needed for conditions
		if (isset($form_data['conditions']) && is_array($form_data['conditions'])) {
			foreach ($form_data['conditions'] as $group) {
				if (is_array($group)) {
					foreach ($group as $condition) {
						if (!empty($condition['field'])) {
							list($table, $field_name) = explode('.', $condition['field']);
							$tables_needed[$table] = true;
						}
					}
				}
			}
		}
		
		// Create COUNT query
		$select_clause = 'SELECT COUNT(*) as total_count';
		
		// Create FROM and JOIN clauses
		$join_type = isset($form_data['join_type']) ? $form_data['join_type'] : 'LEFT JOIN';
		$tables_list = array_keys($tables_needed);
		
		// Start with the first table
		$from_clause = 'FROM ' . $this->tables_config[$tables_list[0]]['table'];
		
		// Add JOINs for additional tables
		$join_clauses = array();
		if (count($tables_list) > 1) {
			// If we have both schools and staff tables, use school_staff to join them
			if (isset($tables_needed['schools']) && isset($tables_needed['staff'])) {
				$tables_needed['school_staff'] = true;
				
				if ($tables_list[0] === 'schools') {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['schools']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.school_id';
					
					$join_clauses[] = $join_type . ' ' . $this->tables_config['staff']['table'] . ' ON ' . 
									  $this->tables_config['school_staff']['table'] . '.staff_id = ' . 
									  $this->tables_config['staff']['table'] . '.id';
				} else {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['staff']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.staff_id';
					
					$join_clauses[] = $join_type . ' ' . $this->tables_config['schools']['table'] . ' ON ' . 
									  $this->tables_config['school_staff']['table'] . '.school_id = ' . 
									  $this->tables_config['schools']['table'] . '.id';
				}
			} 
			// Handle school_staff table if it's explicitly selected
			else if (isset($tables_needed['school_staff'])) {
				if (isset($tables_needed['schools'])) {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['schools']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.school_id';
				} else if (isset($tables_needed['staff'])) {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['staff']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.staff_id';
				}
			}
		}
		
		// Combine JOIN clauses
		$join_clause = implode(' ', $join_clauses);
		
		// Create WHERE clause - reuse the same logic as build_sql_query
		$where_clause = '';
		if (isset($form_data['conditions']) && is_array($form_data['conditions'])) {
			$condition_groups = array();
			
			foreach ($form_data['conditions'] as $group) {
				if (is_array($group)) {
					$conditions = array();
					
					foreach ($group as $index => $condition) {
						// Skip empty conditions
						if (empty($condition['field']) || empty($condition['operator'])) {
							continue;
						}
						
						// Parse field
						list($table, $field_name) = explode('.', $condition['field']);
						$field = $this->tables_config[$table]['table'] . '.' . $field_name;
						
						// Handle different operators
						$where_condition = '';
						
						switch ($condition['operator']) {
							case '=':
							case '!=':
							case '>':
							case '>=':
							case '<':
							case '<=':
								$where_condition = $field . ' ' . $condition['operator'] . ' ' . $this->prepare_value($condition['value']);
								break;
								
							case 'LIKE':
								$where_condition = $field . ' LIKE ' . $this->prepare_value($condition['value']);
								break;
								
							case 'LIKE %...%':
								$where_condition = $field . ' LIKE ' . $this->prepare_value('%' . $condition['value'] . '%');
								break;
								
							case 'NOT LIKE':
								$where_condition = $field . ' NOT LIKE ' . $this->prepare_value($condition['value']);
								break;
								
							case 'NOT LIKE %...%':
								$where_condition = $field . ' NOT LIKE ' . $this->prepare_value('%' . $condition['value'] . '%');
								break;
								
							case 'REGEXP':
								$where_condition = $field . ' REGEXP ' . $this->prepare_value($condition['value']);
								break;
								
							case 'REGEXP ^...$':
								$where_condition = $field . ' REGEXP ' . $this->prepare_value('^' . $condition['value'] . '$');
								break;
								
							case 'NOT REGEXP':
								$where_condition = $field . ' NOT REGEXP ' . $this->prepare_value($condition['value']);
								break;
								
							case "= ''":
								$where_condition = '(' . $field . ' = \'\' OR ' . $field . ' IS NULL)';
								break;
								
							case "!= ''":
								$where_condition = '(' . $field . ' != \'\' AND ' . $field . ' IS NOT NULL)';
								break;
								
							case 'IN':
								$values = array_map('trim', explode(',', $condition['value']));
								$prepared_values = array();
								
								foreach ($values as $value) {
									$prepared_values[] = $this->prepare_value($value);
								}
								
								$where_condition = $field . ' IN (' . implode(', ', $prepared_values) . ')';
								break;
								
							case 'NOT IN':
								$values = array_map('trim', explode(',', $condition['value']));
								$prepared_values = array();
								
								foreach ($values as $value) {
									$prepared_values[] = $this->prepare_value($value);
								}
								
								$where_condition = $field . ' NOT IN (' . implode(', ', $prepared_values) . ')';
								break;
								
							case 'BETWEEN':
								$where_condition = $field . ' BETWEEN ' . $this->prepare_value($condition['value']) . ' AND ' . $this->prepare_value($condition['value2']);
								break;
								
							case 'NOT BETWEEN':
								$where_condition = $field . ' NOT BETWEEN ' . $this->prepare_value($condition['value']) . ' AND ' . $this->prepare_value($condition['value2']);
								break;
						}
						
						if (!empty($where_condition)) {
							if ($index < count($group) - 1 && isset($condition['relation'])) {
								$where_condition .= ' ' . $condition['relation'] . ' ';
							}
							
							$conditions[] = $where_condition;
						}
					}
					
					if (!empty($conditions)) {
						$condition_groups[] = '(' . implode('', $conditions) . ')';
					}
				}
			}
			
			if (!empty($condition_groups)) {
				$where_clause = 'WHERE ' . implode(' OR ', $condition_groups);
			}
		}
		
		// Combine all clauses for the count query
		$sql = $select_clause . ' ' . $from_clause . ' ' . $join_clause;
		
		if (!empty($where_clause)) {
			$sql .= ' ' . $where_clause;
		}
		
		return $sql;
	}
	
	/**
	 * Get total count from a COUNT query
	 * 
	 * @param string $sql SQL count query
	 * @return int Total count
	 */
	public function get_query_count($sql) {
		$wpdb = csd_db_connection();
		$result = $wpdb->get_row($sql);
		
		if ($wpdb->last_error) {
			throw new Exception('Database error: ' . $wpdb->last_error);
		}
		
		return isset($result->total_count) ? intval($result->total_count) : 0;
	}
	
	/**
	 * Run a custom SQL query
	 */
	private function run_custom_sql_query() {
		$sql = trim($_POST['custom_sql']);
		$page = isset($_POST['page']) ? intval($_POST['page']) : 1;
		$per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 25;
		
		// Basic validation
		if (empty($sql)) {
			wp_send_json_error(array('message' => __('SQL query is empty.', 'csd-manager')));
			return;
		}
		
		// Only allow SELECT queries
		if (!preg_match('/^\s*SELECT\s/i', $sql)) {
			wp_send_json_error(array('message' => __('Only SELECT queries are allowed.', 'csd-manager')));
			return;
		}
		
		try {
			// Clean the SQL query
			$sql = $this->clean_sql_query($sql);
			
			// Create a count query
			$count_sql = preg_replace('/^\s*SELECT\s+.+?\s+FROM\s+/is', 'SELECT COUNT(*) as total_count FROM ', $sql);
			
			// Remove any ORDER BY, LIMIT, and GROUP BY clauses from the count query
			$count_sql = preg_replace('/\s+ORDER\s+BY\s+.+$/is', '', $count_sql);
			$count_sql = preg_replace('/\s+LIMIT\s+\d+(?:\s*,\s*\d+)?$/is', '', $count_sql);
			$count_sql = preg_replace('/\s+GROUP\s+BY\s+.+$/is', '', $count_sql);
			
			// Get total count
			$total_count = $this->get_query_count($count_sql);
			
			// Add pagination to the query if it doesn't already have LIMIT
			if (!preg_match('/\s+LIMIT\s+\d+(?:\s*,\s*\d+)?$/i', $sql)) {
				// Ensure there's an ORDER BY clause for consistent pagination
				if (!preg_match('/\s+ORDER\s+BY\s+/i', $sql)) {
					// If there's no ORDER BY, add a default one on the first column
					$sql .= ' ORDER BY 1';
				}
				
				// Add LIMIT/OFFSET
				$offset = ($page - 1) * $per_page;
				$sql .= ' LIMIT ' . intval($per_page) . ' OFFSET ' . intval($offset);
			}
			
			// Run the query
			$results = $this->execute_query($sql);
			
			// Generate results HTML
			$html = $this->generate_results_html($results, $page, $per_page, $total_count);
			
			wp_send_json_success(array(
				'count' => $total_count,
				'current_page' => $page,
				'per_page' => $per_page,
				'total_pages' => ceil($total_count / $per_page),
				'sql' => $sql,
				'html' => $html
			));
		} catch (Exception $e) {
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}
	
	/**
	 * Clean an SQL query by removing unwanted characters
	 * This is a new method to add to the class
	 * 
	 * @param string $sql The SQL query to clean
	 * @return string Cleaned SQL query
	 */
	private function clean_sql_query($sql) {
		// Make sure we have a string
		if (!is_string($sql)) {
			return '';
		}
		
		// Trim whitespace
		$sql = trim($sql);
		
		// Remove hexadecimal artifacts that might appear in LIKE clauses
		$sql = preg_replace('/{[0-9a-f]+}/', '%', $sql);
		
		// Remove any double %% that might cause issues
		$sql = str_replace('%%', '%', $sql);
		
		return $sql;
	}
	
	/**
	 * Build SQL query from form data
	 * 
	 * @param array $form_data Form data
	 * @param bool $add_pagination Whether to add pagination
	 * @param int $page Current page
	 * @param int $per_page Records per page
	 * @return string SQL query
	 */
	public function build_sql_query($form_data, $add_pagination = false, $page = 1, $per_page = 25) {
		// Extract selected fields
		$fields = $form_data['fields'];
		
		// Determine which tables are needed
		$tables_needed = array();
		foreach ($fields as $field) {
			list($table, $field_name) = explode('.', $field);
			$tables_needed[$table] = true;
		}
		
		// Add tables needed for conditions
		if (isset($form_data['conditions']) && is_array($form_data['conditions'])) {
			foreach ($form_data['conditions'] as $group) {
				if (is_array($group)) {
					foreach ($group as $condition) {
						if (!empty($condition['field'])) {
							list($table, $field_name) = explode('.', $condition['field']);
							$tables_needed[$table] = true;
						}
					}
				}
			}
		}
		
		// Create SELECT clause
		$select_fields = array();
		foreach ($fields as $field) {
			list($table, $field_name) = explode('.', $field);
			$select_fields[] = $this->tables_config[$table]['table'] . '.' . $field_name . ' AS `' . $table . '_' . $field_name . '`';
		}
		
		$select_clause = 'SELECT ' . implode(', ', $select_fields);
		
		// Create FROM and JOIN clauses
		$join_type = isset($form_data['join_type']) ? $form_data['join_type'] : 'LEFT JOIN';
		$tables_list = array_keys($tables_needed);
		
		// Start with the first table
		$from_clause = 'FROM ' . $this->tables_config[$tables_list[0]]['table'];
		
		// Add JOINs for additional tables
		$join_clauses = array();
		if (count($tables_list) > 1) {
			// If we have both schools and staff tables, use school_staff to join them
			if (isset($tables_needed['schools']) && isset($tables_needed['staff'])) {
				$tables_needed['school_staff'] = true;
				
				if ($tables_list[0] === 'schools') {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['schools']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.school_id';
					
					$join_clauses[] = $join_type . ' ' . $this->tables_config['staff']['table'] . ' ON ' . 
									  $this->tables_config['school_staff']['table'] . '.staff_id = ' . 
									  $this->tables_config['staff']['table'] . '.id';
				} else {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['staff']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.staff_id';
					
					$join_clauses[] = $join_type . ' ' . $this->tables_config['schools']['table'] . ' ON ' . 
									  $this->tables_config['school_staff']['table'] . '.school_id = ' . 
									  $this->tables_config['schools']['table'] . '.id';
				}
			} 
			// Handle school_staff table if it's explicitly selected
			else if (isset($tables_needed['school_staff'])) {
				if (isset($tables_needed['schools'])) {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['schools']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.school_id';
				} else if (isset($tables_needed['staff'])) {
					$join_clauses[] = $join_type . ' ' . $this->tables_config['school_staff']['table'] . ' ON ' . 
									  $this->tables_config['staff']['table'] . '.id = ' . 
									  $this->tables_config['school_staff']['table'] . '.staff_id';
				}
			}
		}
		
		// Combine JOIN clauses
		$join_clause = implode(' ', $join_clauses);
		
		// Create WHERE clause
		$where_clause = '';
		if (isset($form_data['conditions']) && is_array($form_data['conditions'])) {
			$condition_groups = array();
			
			foreach ($form_data['conditions'] as $group) {
				if (is_array($group)) {
					$conditions = array();
					
					foreach ($group as $index => $condition) {
						// Skip empty conditions
						if (empty($condition['field']) || empty($condition['operator'])) {
							continue;
						}
						
						// Parse field
						list($table, $field_name) = explode('.', $condition['field']);
						$field = $this->tables_config[$table]['table'] . '.' . $field_name;
						
						// Handle different operators
						$where_condition = '';
						
						switch ($condition['operator']) {
							case '=':
							case '!=':
							case '>':
							case '>=':
							case '<':
							case '<=':
								$where_condition = $field . ' ' . $condition['operator'] . ' ' . $this->prepare_value($condition['value']);
								break;
								
							case 'LIKE':
								$where_condition = $field . ' LIKE ' . $this->prepare_value($condition['value']);
								break;
								
							case 'LIKE %...%':
								$where_condition = $field . ' LIKE ' . $this->prepare_value('%' . $condition['value'] . '%');
								break;
								
							case 'NOT LIKE':
								$where_condition = $field . ' NOT LIKE ' . $this->prepare_value($condition['value']);
								break;
								
							case 'NOT LIKE %...%':
								$where_condition = $field . ' NOT LIKE ' . $this->prepare_value('%' . $condition['value'] . '%');
								break;
								
							case 'REGEXP':
								$where_condition = $field . ' REGEXP ' . $this->prepare_value($condition['value']);
								break;
								
							case 'REGEXP ^...$':
								$where_condition = $field . ' REGEXP ' . $this->prepare_value('^' . $condition['value'] . '$');
								break;
								
							case 'NOT REGEXP':
								$where_condition = $field . ' NOT REGEXP ' . $this->prepare_value($condition['value']);
								break;
								
							case "= ''":
								$where_condition = '(' . $field . ' = \'\' OR ' . $field . ' IS NULL)';
								break;
								
							case "!= ''":
								$where_condition = '(' . $field . ' != \'\' AND ' . $field . ' IS NOT NULL)';
								break;
								
							case 'IN':
								$values = array_map('trim', explode(',', $condition['value']));
								$prepared_values = array();
								
								foreach ($values as $value) {
									$prepared_values[] = $this->prepare_value($value);
								}
								
								$where_condition = $field . ' IN (' . implode(', ', $prepared_values) . ')';
								break;
								
							case 'NOT IN':
								$values = array_map('trim', explode(',', $condition['value']));
								$prepared_values = array();
								
								foreach ($values as $value) {
									$prepared_values[] = $this->prepare_value($value);
								}
								
								$where_condition = $field . ' NOT IN (' . implode(', ', $prepared_values) . ')';
								break;
								
							case 'BETWEEN':
								$where_condition = $field . ' BETWEEN ' . $this->prepare_value($condition['value']) . ' AND ' . $this->prepare_value($condition['value2']);
								break;
								
							case 'NOT BETWEEN':
								$where_condition = $field . ' NOT BETWEEN ' . $this->prepare_value($condition['value']) . ' AND ' . $this->prepare_value($condition['value2']);
								break;
						}
						
						if (!empty($where_condition)) {
							if ($index < count($group) - 1 && isset($condition['relation'])) {
								$where_condition .= ' ' . $condition['relation'] . ' ';
							}
							
							$conditions[] = $where_condition;
						}
					}
					
					if (!empty($conditions)) {
						$condition_groups[] = '(' . implode('', $conditions) . ')';
					}
				}
			}
			
			if (!empty($condition_groups)) {
				$where_clause = 'WHERE ' . implode(' OR ', $condition_groups);
			}
		}
		
		// Combine the query base
		$sql = $select_clause . ' ' . $from_clause . ' ' . $join_clause;
		
		if (!empty($where_clause)) {
			$sql .= ' ' . $where_clause;
		}
		
		// Add ORDER BY clause
		$order_clause = '';
		if (!empty($form_data['order_by'])) {
			list($table, $field_name) = explode('.', $form_data['order_by']);
			$order_field = $this->tables_config[$table]['table'] . '.' . $field_name;
			$order_direction = !empty($form_data['order_dir']) ? $form_data['order_dir'] : 'ASC';
			
			$order_clause = ' ORDER BY ' . $order_field . ' ' . $order_direction;
			$sql .= $order_clause;
		} else if ($add_pagination) {
			// Default ordering if none specified
			// Use the first field from the SELECT clause as default
			list($table, $field_name) = explode('.', $form_data['fields'][0]);
			$default_order = ' ORDER BY ' . $this->tables_config[$table]['table'] . '.' . $field_name . ' ASC';
			$sql .= $default_order;
		}
		
		// Add pagination if requested
		if ($add_pagination) {
			// Add LIMIT and OFFSET for pagination
			$offset = ($page - 1) * $per_page;
			$sql .= ' LIMIT ' . intval($per_page) . ' OFFSET ' . intval($offset);
		} else {
			// Non-paginated query - use the original limit setting if present
			if (!empty($form_data['limit'])) {
				$limit = intval($form_data['limit']);
				if ($limit > 0) {
					$sql .= ' LIMIT ' . $limit;
				}
			}
		}
		
		return $sql;
	}
	
	/**
	 * Prepare value for SQL query
	 * This replaces the current prepare_value method in the CSD_Query_Builder class
	 * 
	 * @param mixed $value Value to prepare
	 * @return string Prepared value
	 */
	private function prepare_value($value) {
		global $wpdb;
		
		// Handle empty values
		if (is_null($value) || $value === '') {
			return "''";
		}
		
		// Check if it's numeric
		if (is_numeric($value)) {
			return $value;
		}
		
		// For text values, use proper escaping with $wpdb->prepare
		// But manually handle the % characters for LIKE queries
		if (strpos($value, '%') !== false) {
			// Replace % with escaped version first to avoid SQL injection
			$escaped_value = str_replace('%', '%%', $value);
			// Then use $wpdb->prepare for proper escaping
			$prepared = $wpdb->prepare('%s', $escaped_value);
			// Then put back the % signs for LIKE queries
			return str_replace('%%', '%', $prepared);
		} else {
			// Normal string escaping
			return $wpdb->prepare('%s', $value);
		}
	}
	
	/**
	 * Execute SQL query
	 * 
	 * @param string $sql SQL query
	 * @return array Query results
	 */
	public function execute_query($sql) {
		$wpdb = csd_db_connection();
		
		// Run the query
		$results = $wpdb->get_results($sql, ARRAY_A);
		
		if ($wpdb->last_error) {
			throw new Exception('Database error: ' . $wpdb->last_error);
		}
		
		return $results;
	}
	
	/**
	 * Generate HTML for query results with pagination
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
			$html .= '<div class="csd-results-table-wrapper">';
			$html .= '<table class="wp-list-table widefat fixed striped csd-resizable-table">';
			
			// Table headers
			$html .= '<thead><tr>';
			foreach (array_keys($results[0]) as $column) {
				$label = $column;
				
				// Try to make the column header more readable
				$label = str_replace('_', ' ', $label);
				$label = ucwords($label);
				
				$html .= '<th><div class="th-content">' . esc_html($label) . '</div><div class="resize-handle"></div></th>';
			}
			$html .= '</tr></thead>';
			
			// Table body
			$html .= '<tbody>';
			foreach ($results as $row) {
				$html .= '<tr>';
				foreach ($row as $key => $value) {
					// Format the value for display
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
					$html .= '<a href="#" class="csd-page-number button" data-page="' . ($current_page - 1) . '">&laquo; ' . __('Previous', 'csd-manager') . '</a> ';
				}
				
				// Page numbers
				$start_page = max(1, $current_page - 2);
				$end_page = min($total_pages, $start_page + 4);
				
				if ($start_page > 1) {
					$html .= '<a href="#" class="csd-page-number button" data-page="1">1</a> ';
					if ($start_page > 2) {
						$html .= '<span class="csd-pagination-dots">...</span> ';
					}
				}
				
				for ($i = $start_page; $i <= $end_page; $i++) {
					if ($i === $current_page) {
						$html .= '<span class="csd-page-number button button-primary">' . $i . '</span> ';
					} else {
						$html .= '<a href="#" class="csd-page-number button" data-page="' . $i . '">' . $i . '</a> ';
					}
				}
				
				if ($end_page < $total_pages) {
					if ($end_page < $total_pages - 1) {
						$html .= '<span class="csd-pagination-dots">...</span> ';
					}
					$html .= '<a href="#" class="csd-page-number button" data-page="' . $total_pages . '">' . $total_pages . '</a> ';
				}
				
				// Next button
				if ($current_page < $total_pages) {
					$html .= '<a href="#" class="csd-page-number button" data-page="' . ($current_page + 1) . '">' . __('Next', 'csd-manager') . ' &raquo;</a>';
				}
				
				$html .= '</div>'; // End pagination links
				
				// Records per page selector
				$html .= '<div class="csd-per-page-selector">';
				$html .= '<label for="csd-per-page">' . __('Records per page:', 'csd-manager') . '</label> ';
				$html .= '<select id="csd-per-page">';
				foreach (array(25, 50, 100, 200) as $option) {
					$html .= '<option value="' . $option . '"' . ($per_page == $option ? ' selected' : '') . '>' . $option . '</option>';
				}
				$html .= '</select>';
				$html .= '</div>';
				
				$html .= '</div>'; // End pagination container
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
			return '&mdash;';
		}
		
		// Format dates
		if (strpos($key, 'date_') !== false && preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
			return date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($value));
		}
		
		// Format URLs
		if (strpos($key, 'website') !== false || strpos($key, 'url') !== false) {
			if (filter_var($value, FILTER_VALIDATE_URL)) {
				return '<a href="' . esc_url($value) . '" target="_blank">' . esc_html($value) . '</a>';
			}
		}
		
		// Format email - new condition for @placeholder emails
		if (strpos($key, 'email') !== false) {
			// Check if the email contains @placeholder and return blank if true
			if (strpos($value, '@placeholder') !== false) {
				return '&mdash;';
			}
			
			if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
				return '<a href="mailto:' . esc_attr($value) . '">' . esc_html($value) . '</a>';
			}
		}
		
		// Default formatting
		return esc_html($value);
	}
	
	/**
	 * AJAX handler for getting field values
	 */
	public function ajax_get_field_values() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		// Get field
		$field = isset($_POST['field']) ? sanitize_text_field($_POST['field']) : '';
		
		if (empty($field) || !strpos($field, '.')) {
			wp_send_json_error(array('message' => __('Invalid field.', 'csd-manager')));
			return;
		}
		
		list($table, $field_name) = explode('.', $field);
		
		if (!isset($this->tables_config[$table]) || !isset($this->tables_config[$table]['fields'][$field_name])) {
			wp_send_json_error(array('message' => __('Field not found.', 'csd-manager')));
			return;
		}
		
		$wpdb = csd_db_connection();
		
		// Get distinct values
		$values = $wpdb->get_col("
			SELECT DISTINCT {$field_name}
			FROM {$this->tables_config[$table]['table']}
			WHERE {$field_name} IS NOT NULL AND {$field_name} != ''
			ORDER BY {$field_name}
			LIMIT 100
		");
		
		wp_send_json_success(array('values' => $values));
	}
	
	/**
	 * AJAX handler for saving a query
	 */
	public function ajax_save_query() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$query_name = isset($_POST['query_name']) ? sanitize_text_field($_POST['query_name']) : '';
		
		if (empty($query_name)) {
			wp_send_json_error(array('message' => __('Query name is required.', 'csd-manager')));
			return;
		}
		
		// Parse form data
		parse_str($_POST['form_data'], $form_data);
		
		// Initialize variables
		$wpdb = csd_db_connection();
		
		// Check if a query with this name already exists
		$existing_query_id = $wpdb->get_var($wpdb->prepare(
			"SELECT id FROM " . csd_table('saved_queries') . " WHERE query_name = %s",
			$query_name
		));
		
		// Prepare query data
		$query_data = array(
			'query_name' => $query_name,
			'query_settings' => json_encode($form_data),
			'date_created' => current_time('mysql')
		);
		
		if ($existing_query_id) {
			// Update existing query
			$result = $wpdb->update(
				csd_table('saved_queries'),
				$query_data,
				array('id' => $existing_query_id)
			);
			
			$query_id = $existing_query_id;
		} else {
			// Insert new query
			$result = $wpdb->insert(
				csd_table('saved_queries'),
				$query_data
			);
			
			$query_id = $wpdb->insert_id;
		}
		
		if ($result === false) {
			wp_send_json_error(array('message' => __('Error saving query.', 'csd-manager')));
			return;
		}
		
		wp_send_json_success(array(
			'message' => __('Query saved successfully.', 'csd-manager'),
			'query_id' => $query_id
		));
	}
	
	/**
	 * AJAX handler for loading a query
	 */
	public function ajax_load_query() {
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
		
		// Get query data
		$query = $wpdb->get_row($wpdb->prepare(
			"SELECT * FROM " . csd_table('saved_queries') . " WHERE id = %d",
			$query_id
		));
		
		if (!$query) {
			wp_send_json_error(array('message' => __('Query not found.', 'csd-manager')));
			return;
		}
		
		// Parse query settings
		$query_settings = json_decode($query->query_settings, true);
		
		if (!$query_settings) {
			wp_send_json_error(array('message' => __('Invalid query settings.', 'csd-manager')));
			return;
		}
		
		// Add query name
		$query_settings['query_name'] = $query->query_name;
		
		wp_send_json_success(array(
			'query_data' => $query_settings
		));
	}
	
	/**
	 * AJAX handler for deleting a query
	 */
	public function ajax_delete_query() {
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
		
		// Delete query
		$result = $wpdb->delete(
			csd_table('saved_queries'),
			array('id' => $query_id)
		);
		
		if ($result === false) {
			wp_send_json_error(array('message' => __('Error deleting query.', 'csd-manager')));
			return;
		}
		
		wp_send_json_success(array(
			'message' => __('Query deleted successfully.', 'csd-manager')
		));
	}
	
	/**
	 * Render user assignment modal
	 */
	public function render_user_assignment_modal() {
		?>
		<div id="csd-user-assignment-modal" style="display:none;" class="csd-modal">
			<div class="csd-modal-content">
				<span class="csd-modal-close">&times;</span>
				<h2><?php _e('Assign Query to Users', 'csd-manager'); ?></h2>
				
				<div class="csd-modal-body">
					<div id="csd-assignment-message" class="notice" style="display:none;"></div>
					
					<div class="csd-assignment-form">
						<input type="hidden" id="csd-assign-query-id" value="">
						<p><strong><?php _e('Query Name:', 'csd-manager'); ?></strong> <span id="csd-assign-query-name"></span></p>
						
						<div class="csd-user-search">
							<label for="csd-user-search-input"><?php _e('Search Users:', 'csd-manager'); ?></label>
							<input type="text" id="csd-user-search-input" placeholder="<?php _e('Type to search users...', 'csd-manager'); ?>">
							<select id="csd-user-select" multiple="multiple" style="width: 100%; max-width: 500px; margin-top: 10px;">
								<?php
								// Get all WordPress users
								$users = get_users(array(
									'number' => 20,
									'orderby' => 'display_name'
								));
								
								foreach ($users as $user) {
									echo '<option value="' . esc_attr($user->ID) . '">' . 
										esc_html($user->display_name) . ' (' . esc_html($user->user_email) . ')' . 
									'</option>';
								}
								?>
							</select>
						</div>
						
						<div class="csd-actions" style="margin-top: 20px;">
							<button type="button" id="csd-assign-users" class="button button-primary"><?php _e('Assign to Selected Users', 'csd-manager'); ?></button>
							<button type="button" id="csd-cancel-assignment" class="button"><?php _e('Cancel', 'csd-manager'); ?></button>
						</div>
					</div>
					
					<div id="csd-current-assignments" style="margin-top: 30px;">
						<h3><?php _e('Current Assignments', 'csd-manager'); ?></h3>
						<div id="csd-current-users-list"></div>
					</div>
				</div>
			</div>
		</div>
		
		<style type="text/css">
			.csd-modal {
				position: fixed;
				z-index: 9999;
				left: 0;
				top: 0;
				width: 100%;
				height: 100%;
				overflow: auto;
				background-color: rgba(0,0,0,0.4);
			}
			
			.csd-modal-content {
				background-color: #fefefe;
				margin: 5% auto;
				padding: 20px;
				border: 1px solid #888;
				width: 80%;
				max-width: 800px;
				box-shadow: 0 4px 8px 0 rgba(0,0,0,0.2);
				position: relative;
			}
			
			.csd-modal-close {
				color: #aaa;
				float: right;
				font-size: 28px;
				font-weight: bold;
				cursor: pointer;
			}
			
			.csd-modal-close:hover,
			.csd-modal-close:focus {
				color: black;
				text-decoration: none;
			}
			
			.csd-user-list {
				margin-top: 15px;
				border: 1px solid #ddd;
				max-height: 300px;
				overflow-y: auto;
			}
			
			.csd-user-list table {
				width: 100%;
				border-collapse: collapse;
			}
			
			.csd-user-list th,
			.csd-user-list td {
				padding: 8px;
				text-align: left;
				border-bottom: 1px solid #ddd;
			}
			
			.csd-user-list th {
				background-color: #f5f5f5;
			}
			
			.csd-user-list tr:nth-child(even) {
				background-color: #f9f9f9;
			}
			
			.csd-user-list tr:hover {
				background-color: #f0f0f0;
			}
			
			.csd-user-actions {
				white-space: nowrap;
				text-align: right;
			}
		</style>
		<?php
	}
	
	/**
	 * AJAX handler for exporting query results to CSV
	 */
	public function ajax_export_query_results() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-ajax-nonce')) {
			wp_die(__('Security check failed.', 'csd-manager'));
		}
		
		if (!current_user_can('manage_options')) {
			wp_die(__('You do not have permission to perform this action.', 'csd-manager'));
		}
		
		$sql = isset($_POST['sql']) ? trim($_POST['sql']) : '';
		
		if (empty($sql)) {
			wp_die(__('SQL query is empty.', 'csd-manager'));
		}
		
		// Only allow SELECT queries
		if (!preg_match('/^\s*SELECT\s/i', $sql)) {
			wp_die(__('Only SELECT queries are allowed.', 'csd-manager'));
		}
		
		try {
			// Debugging - log the incoming SQL
			error_log('Original SQL for export: ' . $sql);
			
			// Get database connection
			$wpdb = csd_db_connection();
			
			// Fix the SQL syntax issues with quoted LIKE values
			$sql = str_replace("\'", "'", $sql);
			
			// For debugging - log the fixed SQL
			error_log('Fixed SQL for export: ' . $sql);
			
			// Execute the query directly without trying to break it down
			$results = $wpdb->get_results($sql, ARRAY_A);
			
			// Check for database errors
			if ($wpdb->last_error) {
				error_log('SQL Error: ' . $wpdb->last_error);
				wp_die('Database error: ' . $wpdb->last_error);
			}
			
			// Debug info about results
			error_log('Results count: ' . (is_array($results) ? count($results) : 'not an array'));
			if (is_array($results) && !empty($results)) {
				error_log('First row keys: ' . print_r(array_keys($results[0]), true));
			}
			
			if (empty($results)) {
				wp_die(__('No results to export.', 'csd-manager'));
			}
			
			// Don't use output buffering here, it might be interfering
			
			// Set filename with timestamp to prevent caching
			$filename = 'csd-query-export-' . date('Y-m-d-H-i-s') . '.csv';
			
			// Set headers for CSV download - be very explicit
			header('Content-Type: text/csv');
			header('Content-Disposition: attachment; filename="' . $filename . '"');
			header('Pragma: no-cache');
			header('Expires: 0');
			header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
			header('Cache-Control: private', false);
			
			// Create output stream
			$output = fopen('php://output', 'w');
			
			// Write UTF-8 BOM to help with Excel compatibility
			fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
			
			// Write headers
			fputcsv($output, array_keys($results[0]));
			
			// Write data rows
			foreach ($results as $row) {
				fputcsv($output, $row);
			}
			
			// Close the output
			fclose($output);
			
			// End execution
			exit();
		} catch (Exception $e) {
			error_log('CSV Export Exception: ' . $e->getMessage());
			wp_die('Export error: ' . $e->getMessage());
		}
	}
	
	/**
	 * Create saved queries table
	 */
	public function create_saved_queries_table() {
		$wpdb = csd_db_connection();
		
		$table_name = csd_table('saved_queries');
		$charset_collate = $wpdb->get_charset_collate();
		
		$sql = "CREATE TABLE $table_name (
			id mediumint(9) NOT NULL AUTO_INCREMENT,
			query_name varchar(255) NOT NULL,
			query_settings longtext NOT NULL,
			date_created datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
			PRIMARY KEY  (id)
		) $charset_collate;";
		
		require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
		dbDelta($sql);
	}
}