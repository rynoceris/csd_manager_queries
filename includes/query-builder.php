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
								<button type="button" id="csd-add-group-or" class="button"><?php _e('Add Condition Group (OR)', 'csd-manager'); ?></button>
								<button type="button" id="csd-add-group-and" class="button"><?php _e('Add Condition Group (AND)', 'csd-manager'); ?></button>
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
			<!-- Klaviyo Sync Modal -->
			<div id="csd-klaviyo-modal" style="display:none;" class="csd-modal">
				<div class="csd-modal-content csd-klaviyo-modal-content">
					<span class="csd-modal-close">&times;</span>
					<h2><?php _e('Sync to Klaviyo List', 'csd-manager'); ?></h2>
					
					<div class="csd-modal-body">
						<div id="csd-klaviyo-message" class="notice" style="display:none;"></div>
						
						<!-- Step 1: List Selection -->
						<div id="csd-klaviyo-step-1" class="csd-klaviyo-step">
							<h3><?php _e('Step 1: Select or Create List', 'csd-manager'); ?></h3>
							
							<div class="csd-list-selection">
								<label>
									<input type="radio" name="list_option" value="existing" checked>
									<?php _e('Use Existing List', 'csd-manager'); ?>
								</label>
								
								<div id="csd-existing-list-container" style="margin-left: 25px; margin-top: 10px;">
									<select id="csd-klaviyo-lists" style="width: 100%; max-width: 400px;">
										<option value=""><?php _e('Loading lists...', 'csd-manager'); ?></option>
									</select>
									<button type="button" id="csd-refresh-lists" class="button" style="margin-left: 10px;">
										<span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'csd-manager'); ?>
									</button>
								</div>
							</div>
							
							<div class="csd-list-selection" style="margin-top: 15px;">
								<label>
									<input type="radio" name="list_option" value="new">
									<?php _e('Create New List', 'csd-manager'); ?>
								</label>
								
								<div id="csd-new-list-container" style="margin-left: 25px; margin-top: 10px; display: none;">
									<input type="text" id="csd-new-list-name" placeholder="<?php _e('Enter new list name...', 'csd-manager'); ?>" style="width: 100%; max-width: 400px;">
								</div>
							</div>
							
							<div class="csd-step-actions" style="margin-top: 20px;">
								<button type="button" id="csd-klaviyo-next-step" class="button button-primary"><?php _e('Next: Field Mapping', 'csd-manager'); ?></button>
								<button type="button" id="csd-klaviyo-cancel" class="button"><?php _e('Cancel', 'csd-manager'); ?></button>
							</div>
						</div>
						
						<!-- Step 2: Field Mapping -->
						<div id="csd-klaviyo-step-2" class="csd-klaviyo-step" style="display: none;">
							<h3><?php _e('Step 2: Map Fields', 'csd-manager'); ?></h3>
							<p><?php _e('Map your query columns to Klaviyo profile fields. At minimum, you should map an email field.', 'csd-manager'); ?></p>
							
							<div style="margin-bottom: 15px;">
								<button type="button" id="csd-refresh-klaviyo-fields" class="button button-secondary">
									<span class="dashicons dashicons-update"></span> <?php _e('Refresh Fields from Klaviyo', 'csd-manager'); ?>
								</button>
								
								<button type="button" id="csd-add-custom-field" class="button button-secondary" style="margin-left: 10px;">
									<span class="dashicons dashicons-plus"></span> <?php _e('Add Custom Field', 'csd-manager'); ?>
								</button>
							</div>
							
							<!-- Manual custom field addition -->
							<div id="csd-custom-field-input" style="display: none; margin-bottom: 15px; padding: 10px; background: #f0f0f0; border-radius: 4px;">
								<label for="csd-manual-field-name"><?php _e('Custom Field Name:', 'csd-manager'); ?></label>
								<input type="text" id="csd-manual-field-name" placeholder="<?php _e('e.g., school_division, enrollment_count', 'csd-manager'); ?>" style="width: 200px; margin-right: 10px;">
								<button type="button" id="csd-add-manual-field" class="button button-small"><?php _e('Add Field', 'csd-manager'); ?></button>
								<button type="button" id="csd-cancel-manual-field" class="button button-small"><?php _e('Cancel', 'csd-manager'); ?></button>
							</div>
							
							<div id="csd-field-mapping-container">
								<!-- Field mapping will be generated here -->
							</div>
							
							<div class="csd-step-actions" style="margin-top: 20px;">
								<button type="button" id="csd-klaviyo-back-step" class="button"><?php _e('Back', 'csd-manager'); ?></button>
								<button type="button" id="csd-klaviyo-start-sync" class="button button-primary"><?php _e('Start Sync', 'csd-manager'); ?></button>
								<button type="button" id="csd-klaviyo-cancel-mapping" class="button"><?php _e('Cancel', 'csd-manager'); ?></button>
							</div>
						</div>
						
						<!-- Step 3: Sync Progress -->
						<div id="csd-klaviyo-step-3" class="csd-klaviyo-step" style="display: none;">
							<h3><?php _e('Step 3: Syncing Data', 'csd-manager'); ?></h3>
							<p><?php _e('Please wait while we sync your data to Klaviyo...', 'csd-manager'); ?></p>
							
							<div class="csd-progress-container">
								<div class="csd-progress-bar">
									<div class="csd-progress-fill" style="width: 0%;"></div>
								</div>
								<div class="csd-progress-text">0%</div>
							</div>
							
							<div id="csd-sync-status" style="margin-top: 15px;">
								<?php _e('Preparing sync...', 'csd-manager'); ?>
							</div>
						</div>
						
						<!-- Step 4: Completion -->
						<div id="csd-klaviyo-step-4" class="csd-klaviyo-step" style="display: none;">
							<h3><?php _e('Sync Complete!', 'csd-manager'); ?></h3>
							
							<div id="csd-sync-results">
								<!-- Results will be populated here -->
							</div>
							
							<div class="csd-step-actions" style="margin-top: 20px;">
								<button type="button" id="csd-klaviyo-view-list" class="button button-primary" style="display: none;">
									<?php _e('View List in Klaviyo', 'csd-manager'); ?>
								</button>
								<button type="button" id="csd-klaviyo-close" class="button"><?php _e('Close', 'csd-manager'); ?></button>
							</div>
						</div>
					</div>
				</div>
			</div>
			
			<style type="text/css">
				/* Klaviyo Modal Styles */
				.csd-klaviyo-modal-content {
					max-width: 800px;
					width: 90%;
				}
				
				.csd-klaviyo-step {
					min-height: 300px;
				}
				
				.csd-list-selection {
					margin-bottom: 15px;
				}
				
				.csd-list-selection label {
					font-weight: 600;
					display: block;
					margin-bottom: 5px;
				}
				
				.csd-field-mapping-row {
					display: flex;
					align-items: center;
					margin-bottom: 10px;
					padding: 10px;
					background: #f9f9f9;
					border: 1px solid #ddd;
					border-radius: 4px;
				}
				
				.csd-field-mapping-row label {
					flex: 0 0 200px;
					font-weight: 600;
					margin-right: 15px;
				}
				
				.csd-field-mapping-row select {
					flex: 1;
					max-width: 300px;
				}
				
				.csd-progress-container {
					margin: 20px 0;
				}
				
				.csd-progress-bar {
					width: 100%;
					height: 25px;
					background-color: #f0f0f0;
					border-radius: 4px;
					border: 1px solid #ddd;
					overflow: hidden;
					position: relative;
				}
				
				.csd-progress-fill {
					height: 100%;
					background: linear-gradient(90deg, #0073aa, #00a0d2);
					transition: width 0.3s ease;
					border-radius: 3px;
				}
				
				.csd-progress-text {
					text-align: center;
					margin-top: 10px;
					font-weight: 600;
					font-size: 16px;
				}
				
				.csd-step-actions {
					border-top: 1px solid #ddd;
					padding-top: 15px;
				}
				
				.csd-step-actions .button {
					margin-right: 10px;
				}
				
				#csd-sync-status {
					padding: 10px;
					background: #f8f8f8;
					border: 1px solid #ddd;
					border-radius: 4px;
					font-style: italic;
				}
				
				/* Responsive adjustments */
				@media screen and (max-width: 768px) {
					.csd-field-mapping-row {
						flex-direction: column;
						align-items: flex-start;
					}
					
					.csd-field-mapping-row label {
						flex: none;
						margin-bottom: 5px;
					}
					
					.csd-field-mapping-row select {
						width: 100%;
						max-width: none;
					}
				}
			</style>
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
		<script type="text/javascript">
		// Replace the entire Klaviyo Integration JavaScript section in query-builder.php
		// This goes in the <script type="text/javascript"> section for Klaviyo
		
		(function($) {
			// Klaviyo Integration JavaScript
			var klaviyoCurrentSQL = '';
			var klaviyoSelectedListId = '';
			var klaviyoFieldMapping = {};
		
			$(document).ready(function() {
				// Handle Sync to Klaviyo button click
				$(document).on('click', '#csd-sync-klaviyo', function() {
					// Get the current SQL query - check if sqlEditor exists and is available
					klaviyoCurrentSQL = '';
					
					if (typeof window.sqlEditor !== 'undefined' && window.sqlEditor && window.sqlEditor.getValue) {
						klaviyoCurrentSQL = window.sqlEditor.getValue();
					} else if ($('#csd-sql-query').length) {
						klaviyoCurrentSQL = $('#csd-sql-query').val();
					}
					
					if (!klaviyoCurrentSQL || klaviyoCurrentSQL.trim() === '') {
						alert('<?php _e('Please run a query first.', 'csd-manager'); ?>');
						return;
					}
					
					// Reset modal state
					resetKlaviyoModal();
					
					// Load Klaviyo lists
					loadKlaviyoLists();
					
					// Show the modal
					$('#csd-klaviyo-modal').show();
				});
		
				// Reset modal to initial state
				function resetKlaviyoModal() {
					$('#csd-klaviyo-step-1').show();
					$('#csd-klaviyo-step-2, #csd-klaviyo-step-3, #csd-klaviyo-step-4').hide();
					$('#csd-klaviyo-message').hide();
					$('input[name="list_option"][value="existing"]').prop('checked', true);
					$('#csd-existing-list-container').show();
					$('#csd-new-list-container').hide();
					$('#csd-new-list-name').val('');
					klaviyoSelectedListId = '';
					klaviyoFieldMapping = {};
				}
		
				// Load Klaviyo lists
				function loadKlaviyoLists(forceRefresh = false) {
					$('#csd-klaviyo-lists').html('<option value=""><?php _e('Loading lists...', 'csd-manager'); ?></option>');
					$('#csd-refresh-lists').prop('disabled', true).html('<span class="dashicons dashicons-update"></span> <?php _e('Loading...', 'csd-manager'); ?>');
					
					$.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_get_klaviyo_lists',
							force_refresh: forceRefresh,
							nonce: '<?php echo wp_create_nonce('csd-klaviyo-nonce'); ?>'
						},
						success: function(response) {
							$('#csd-refresh-lists').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'csd-manager'); ?>');
							$('#csd-klaviyo-lists').html('<option value=""><?php _e('-- Select a list --', 'csd-manager'); ?></option>');
							
							if (response.success && response.data.lists) {
								var listCount = response.data.lists.length;
								
								$.each(response.data.lists, function(index, list) {
									$('#csd-klaviyo-lists').append('<option value="' + list.id + '">' + list.name + '</option>');
								});
								
								// Show count and cache status in message
								var cacheStatus = response.data.cached ? ' <?php _e('(from cache)', 'csd-manager'); ?>' : ' <?php _e('(fresh from Klaviyo)', 'csd-manager'); ?>';
								showKlaviyoMessage('success', '<?php _e('Loaded', 'csd-manager'); ?> ' + listCount + ' <?php _e('lists', 'csd-manager'); ?>' + cacheStatus);
							} else {
								showKlaviyoMessage('error', response.data.message || '<?php _e('Failed to load lists.', 'csd-manager'); ?>');
							}
						},
						error: function() {
							$('#csd-refresh-lists').prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Refresh', 'csd-manager'); ?>');
							$('#csd-klaviyo-lists').html('<option value=""><?php _e('Error loading lists', 'csd-manager'); ?></option>');
							showKlaviyoMessage('error', '<?php _e('Error connecting to Klaviyo.', 'csd-manager'); ?>');
						}
					});
				}
		
				// Handle list option radio buttons
				$(document).on('change', 'input[name="list_option"]', function() {
					if ($(this).val() === 'existing') {
						$('#csd-existing-list-container').show();
						$('#csd-new-list-container').hide();
					} else {
						$('#csd-existing-list-container').hide();
						$('#csd-new-list-container').show();
					}
				});
		
				// Handle refresh lists button - force refresh
				$(document).on('click', '#csd-refresh-lists', function() {
					loadKlaviyoLists(true); // Force refresh
				});
				
				// Get Klaviyo fields with caching
				function getKlaviyoFields(forceRefresh = false) {
					return $.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_get_klaviyo_fields',
							force_refresh: forceRefresh,
							nonce: '<?php echo wp_create_nonce('csd-klaviyo-nonce'); ?>'
						}
					});
				}
		
				// Handle next step button
				$(document).on('click', '#csd-klaviyo-next-step', function() {
					var listOption = $('input[name="list_option"]:checked').val();
					
					if (listOption === 'existing') {
						klaviyoSelectedListId = $('#csd-klaviyo-lists').val();
						if (!klaviyoSelectedListId) {
							showKlaviyoMessage('error', '<?php _e('Please select a list.', 'csd-manager'); ?>');
							return;
						}
						proceedToFieldMapping();
					} else {
						var newListName = $('#csd-new-list-name').val().trim();
						if (!newListName) {
							showKlaviyoMessage('error', '<?php _e('Please enter a name for the new list.', 'csd-manager'); ?>');
							return;
						}
						createKlaviyoList(newListName);
					}
				});
		
				// Create new Klaviyo list
				function createKlaviyoList(listName) {
					$('#csd-klaviyo-next-step').prop('disabled', true).text('<?php _e('Creating list...', 'csd-manager'); ?>');
					
					$.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_create_klaviyo_list',
							list_name: listName,
							nonce: '<?php echo wp_create_nonce('csd-klaviyo-nonce'); ?>'
						},
						success: function(response) {
							$('#csd-klaviyo-next-step').prop('disabled', false).text('<?php _e('Next: Field Mapping', 'csd-manager'); ?>');
							
							if (response.success) {
								klaviyoSelectedListId = response.data.list.id;
								showKlaviyoMessage('success', '<?php _e('List created successfully!', 'csd-manager'); ?>');
								proceedToFieldMapping();
							} else {
								showKlaviyoMessage('error', response.data.message || '<?php _e('Failed to create list.', 'csd-manager'); ?>');
							}
						},
						error: function() {
							$('#csd-klaviyo-next-step').prop('disabled', false).text('<?php _e('Next: Field Mapping', 'csd-manager'); ?>');
							showKlaviyoMessage('error', '<?php _e('Error creating list.', 'csd-manager'); ?>');
						}
					});
				}
				
				// Handle refresh Klaviyo fields button - force refresh
				$(document).on('click', '#csd-refresh-klaviyo-fields', function() {
					var button = $(this);
					button.prop('disabled', true).html('<span class="dashicons dashicons-update"></span> <?php _e('Refreshing...', 'csd-manager'); ?>');
					
					getKlaviyoFields(true).done(function(response) {
						button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Refresh Fields from Klaviyo', 'csd-manager'); ?>');
						
						if (response.success) {
							// Get columns again and rebuild interface
							var columns = extractActualColumnNames();
							buildFieldMappingInterface(columns, response.data.fields);
							showKlaviyoMessage('success', '<?php _e('Fields refreshed from Klaviyo!', 'csd-manager'); ?>');
						} else {
							showKlaviyoMessage('error', response.data.message || '<?php _e('Failed to refresh fields.', 'csd-manager'); ?>');
						}
					}).fail(function() {
						button.prop('disabled', false).html('<span class="dashicons dashicons-update"></span> <?php _e('Refresh Fields from Klaviyo', 'csd-manager'); ?>');
						showKlaviyoMessage('error', '<?php _e('Error refreshing fields.', 'csd-manager'); ?>');
					});
				});
		
				// Proceed to field mapping step
				function proceedToFieldMapping() {
					$('#csd-klaviyo-step-1').hide();
					$('#csd-klaviyo-step-2').show();
					
					// Generate field mapping interface
					generateFieldMapping();
				}
		
				// FIXED: Extract actual column names from query results
				function extractActualColumnNames() {
					var columns = [];
					
					// Look at the actual results table and extract column names from data-column attributes
					$('.csd-results-table-wrapper table thead th').each(function() {
						var columnName = $(this).attr('data-column');
						if (columnName && columnName.trim() !== '') {
							columns.push(columnName.trim());
						}
					});
					
					// If no data-column attributes found, fall back to extracting from table headers
					if (columns.length === 0) {
						$('.csd-results-table-wrapper table thead th').each(function() {
							var headerText = $(this).text().trim();
							// Try to convert display names back to actual column names
							var columnName = convertDisplayNameToColumnName(headerText);
							if (columnName) {
								columns.push(columnName);
							}
						});
					}
					
					console.log('Klaviyo Debug - Extracted columns:', columns);
					return columns;
				}
				
				// Helper function to convert display names back to column names
				function convertDisplayNameToColumnName(displayName) {
					// Map common display names to actual column names
					var displayToColumnMap = {
						'ID': 'schools_id',
						'School Name': 'schools_school_name',
						'Street Address Line 1': 'schools_street_address_line_1',
						'Street Address Line 2': 'schools_street_address_line_2',
						'Street Address Line 3': 'schools_street_address_line_3',
						'City': 'schools_city',
						'State': 'schools_state',
						'Zipcode': 'schools_zipcode',
						'Country': 'schools_country',
						'County': 'schools_county',
						'School Divisions': 'schools_school_divisions',
						'School Conferences': 'schools_school_conferences',
						'School Level': 'schools_school_level',
						'School Type': 'schools_school_type',
						'School Enrollment': 'schools_school_enrollment',
						'Mascot': 'schools_mascot',
						'School Colors': 'schools_school_colors',
						'School Website': 'schools_school_website',
						'Athletics Website': 'schools_athletics_website',
						'Athletics Phone': 'schools_athletics_phone',
						'Football Division': 'schools_football_division',
						'Full Name': 'staff_full_name',
						'Title': 'staff_title',
						'Sport Department': 'staff_sport_department',
						'Email': 'staff_email',
						'Phone': 'staff_phone'
					};
					
					return displayToColumnMap[displayName] || null;
				}
		
				// FIXED: Generate field mapping interface using actual column names
				function generateFieldMapping() {
					var columns = extractActualColumnNames();
					
					console.log('Klaviyo Debug - Columns extracted for mapping:', columns);
					
					if (columns.length === 0) {
						showKlaviyoMessage('error', 'No columns found in query results for mapping.');
						return;
					}
					
					// Get available Klaviyo fields and build the mapping interface
					getKlaviyoFields(false).done(function(response) {
						if (response.success) {
							buildFieldMappingInterface(columns, response.data.fields);
							
							if (response.data.cached) {
								showKlaviyoMessage('info', 'Using cached field list. Click "Refresh Fields" to get latest from Klaviyo.');
							}
						} else {
							showKlaviyoMessage('error', response.data.message || 'Failed to load Klaviyo fields.');
						}
					}).fail(function() {
						showKlaviyoMessage('error', 'Error loading Klaviyo fields.');
					});
				}
				
				// FIXED: Build field mapping interface using actual column names
				function buildFieldMappingInterface(queryColumns, klaviyoFields) {
					var html = '';
					
					// Create a dropdown for each actual database column
					$.each(queryColumns, function(index, columnName) {
						// Convert database column name to readable display name for the UI
						var displayName = formatColumnDisplayName(columnName);
						
						html += '<div class="csd-field-mapping-row">';
						html += '<label>' + displayName + ':</label>';
						
						// CRITICAL FIX: Store the actual database column name in data-column
						html += '<select class="csd-field-mapping" data-column="' + columnName + '">';
						html += '<option value="">-- Do not map --</option>';
						
						// Add all available Klaviyo fields as options
						$.each(klaviyoFields, function(fieldKey, fieldLabel) {
							var selected = '';
							
							// Auto-select obvious matches based on actual column names
							var columnLower = columnName.toLowerCase();
							if ((columnLower.includes('email') && fieldKey === 'email') ||
								(columnLower.includes('phone') && fieldKey === 'phone_number') ||
								(columnLower.includes('city') && fieldKey === 'location.city') ||
								(columnLower.includes('state') && fieldKey === 'location.region') ||
								(columnLower.includes('zip') && fieldKey === 'location.zip') ||
								(columnLower.includes('title') && fieldKey === 'title') ||
								(columnLower.includes('school_name') && fieldKey === 'organization')) {
								selected = ' selected';
							}
							
							html += '<option value="' + fieldKey + '"' + selected + '>' + fieldLabel + '</option>';
						});
						
						html += '</select>';
						html += '</div>';
					});
					
					// Insert the generated HTML into the modal
					$('#csd-field-mapping-container').html(html);
				}
				
				// Helper function to convert database column names to readable display names
				function formatColumnDisplayName(columnName) {
					var displayName = columnName;
					
					// Handle different table prefixes and convert to readable names
					if (columnName.startsWith('schools_')) {
						displayName = columnName.replace('schools_', '').replace(/_/g, ' ');
						displayName = 'School ' + displayName.charAt(0).toUpperCase() + displayName.slice(1);
					} else if (columnName.startsWith('staff_')) {
						displayName = columnName.replace('staff_', '').replace(/_/g, ' ');
						displayName = 'Staff ' + displayName.charAt(0).toUpperCase() + displayName.slice(1);
					} else if (columnName.startsWith('school_staff_')) {
						displayName = columnName.replace('school_staff_', '').replace(/_/g, ' ');
						displayName = 'School Staff ' + displayName.charAt(0).toUpperCase() + displayName.slice(1);
					} else {
						displayName = columnName.replace(/_/g, ' ');
						displayName = displayName.charAt(0).toUpperCase() + displayName.slice(1);
					}
					
					// Fix common formatting issues
					displayName = displayName.replace(/\bFull name\b/g, 'Full Name');
					displayName = displayName.replace(/\bSport department\b/g, 'Sport/Department');
					displayName = displayName.replace(/\bStreet address line/g, 'Address Line');
					
					return displayName;
				}
				
				// Handle add custom field button
				$(document).on('click', '#csd-add-custom-field', function() {
					$('#csd-custom-field-input').show();
					$('#csd-manual-field-name').focus();
				});
				
				// Handle cancel manual field
				$(document).on('click', '#csd-cancel-manual-field', function() {
					$('#csd-custom-field-input').hide();
					$('#csd-manual-field-name').val('');
				});
				
				// Handle add manual field
				$(document).on('click', '#csd-add-manual-field', function() {
					var fieldName = $('#csd-manual-field-name').val().trim();
					
					if (!fieldName) {
						alert('<?php _e('Please enter a field name.', 'csd-manager'); ?>');
						return;
					}
					
					// Clean the field name
					fieldName = fieldName.replace(/[^a-zA-Z0-9_]/g, '_').toLowerCase();
					
					var fieldKey = 'properties.' + fieldName;
					var fieldLabel = 'Manual: ' + $('#csd-manual-field-name').val().trim();
					
					// Add to all field mapping dropdowns
					$('.csd-field-mapping').each(function() {
						var existingOption = $(this).find('option[value="' + fieldKey + '"]');
						if (existingOption.length === 0) {
							$(this).append('<option value="' + fieldKey + '">' + fieldLabel + '</option>');
						}
					});
					
					$('#csd-custom-field-input').hide();
					$('#csd-manual-field-name').val('');
					
					showKlaviyoMessage('success', '<?php _e('Custom field added to all mapping dropdowns.', 'csd-manager'); ?>');
				});
		
				// Handle back step button
				$(document).on('click', '#csd-klaviyo-back-step', function() {
					$('#csd-klaviyo-step-2').hide();
					$('#csd-klaviyo-step-1').show();
				});
		
				// FIXED: When user clicks "Start Sync", collect the final field mapping using actual column names
				$(document).on('click', '#csd-klaviyo-start-sync', function() {
					// Build the final mapping object by reading each dropdown
					klaviyoFieldMapping = {};
					$('.csd-field-mapping').each(function() {
						// Get the actual database column name from data-column attribute
						var columnName = $(this).attr('data-column');
						// Get the selected Klaviyo field from the dropdown value
						var klaviyoField = $(this).val();
						
						// Only include mappings where user selected a Klaviyo field
						if (klaviyoField && columnName) {
							klaviyoFieldMapping[columnName] = klaviyoField;
						}
					});
					
					console.log('Klaviyo Debug - Final field mapping to send to server:', klaviyoFieldMapping);
					
					// Validate that at least one column is mapped to email
					var hasEmail = false;
					$.each(klaviyoFieldMapping, function(column, field) {
						if (field === 'email') {
							hasEmail = true;
							return false; // break out of loop
						}
					});
					
					if (!hasEmail) {
						showKlaviyoMessage('error', 'Please map at least one column to the Email field.');
						return;
					}
					
					// Start the actual sync process
					startKlaviyoSync();
				});
						
				// Start Klaviyo sync with progress reporting
				function startKlaviyoSync() {
					$('#csd-klaviyo-step-2').hide();
					$('#csd-klaviyo-step-3').show();
					
					// Reset progress
					$('.csd-progress-fill').css('width', '0%');
					$('.csd-progress-text').text('0%');
					$('#csd-sync-status').text('<?php _e('Starting sync...', 'csd-manager'); ?>');
					
					// Start the sync with longer timeout for large datasets
					$.ajax({
						url: csd_ajax.ajax_url,
						type: 'POST',
						data: {
							action: 'csd_sync_to_klaviyo',
							list_id: klaviyoSelectedListId,
							field_mapping: klaviyoFieldMapping,
							sql_query: klaviyoCurrentSQL,
							nonce: '<?php echo wp_create_nonce('csd-klaviyo-nonce'); ?>'
						},
						timeout: 300000, // 5 minute timeout for large syncs
						xhr: function() {
							var xhr = new window.XMLHttpRequest();
							
							// Improved progress simulation
							var progress = 0;
							var progressInterval = setInterval(function() {
								if (progress < 85) { // Don't go past 85% until we get the actual response
									var increment = Math.random() * 3; // Slower, more realistic progress
									progress += increment;
									$('.csd-progress-fill').css('width', progress + '%');
									$('.csd-progress-text').text(Math.round(progress) + '%');
									
									// Update status messages based on progress
									if (progress < 20) {
										$('#csd-sync-status').text('<?php _e('Preparing data for sync...', 'csd-manager'); ?>');
									} else if (progress < 40) {
										$('#csd-sync-status').text('<?php _e('Validating email addresses...', 'csd-manager'); ?>');
									} else if (progress < 60) {
										$('#csd-sync-status').text('<?php _e('Creating profiles in Klaviyo...', 'csd-manager'); ?>');
									} else if (progress < 80) {
										$('#csd-sync-status').text('<?php _e('Adding profiles to list...', 'csd-manager'); ?>');
									} else {
										$('#csd-sync-status').text('<?php _e('Finalizing sync...', 'csd-manager'); ?>');
									}
								}
							}, 1000); // Update every second instead of every 500ms
							
							xhr.addEventListener('loadend', function() {
								clearInterval(progressInterval);
							});
							
							return xhr;
						},
						beforeSend: function() {
							// Disable browser page unload warning during sync
							window.onbeforeunload = function() {
								return "Sync in progress. Are you sure you want to leave?";
							};
						},
						success: function(response) {
							// Re-enable page unload
							window.onbeforeunload = null;
							
							// Complete progress
							$('.csd-progress-fill').css('width', '100%');
							$('.csd-progress-text').text('100%');
							$('#csd-sync-status').text('<?php _e('Sync completed!', 'csd-manager'); ?>');
							
							if (response.success) {
								// Add a small delay to show completion before showing results
								setTimeout(function() {
									showSyncCompletion(response.data);
								}, 1000);
							} else {
								showSyncError(response.data.message || '<?php _e('Sync failed.', 'csd-manager'); ?>');
							}
						},
						error: function(xhr, status, error) {
							// Re-enable page unload
							window.onbeforeunload = null;
							
							var errorMessage = '<?php _e('Error during sync process.', 'csd-manager'); ?>';
							
							if (status === 'timeout') {
								errorMessage = '<?php _e('Sync timed out. This may happen with very large datasets. Please try syncing smaller batches.', 'csd-manager'); ?>';
							} else if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
								errorMessage = xhr.responseJSON.data.message;
							}
							
							showSyncError(errorMessage);
						}
					});
				}
		
				// Show sync completion
				function showSyncCompletion(data) {
					$('#csd-klaviyo-step-3').hide();
					$('#csd-klaviyo-step-4').show();
					
					var html = '<div class="notice notice-success">';
					html += '<p><strong>' + data.message + '</strong></p>';
					
					if (data.validation_errors && data.validation_errors.length > 0) {
						html += '<p><strong><?php _e('Validation Issues:', 'csd-manager'); ?></strong></p>';
						html += '<ul>';
						$.each(data.validation_errors, function(index, error) {
							html += '<li>' + error + '</li>';
						});
						html += '</ul>';
						
						if (data.validation_errors.length >= 10) {
							html += '<p><em><?php _e('(Only first 10 validation errors shown)', 'csd-manager'); ?></em></p>';
						}
					}
					
					if (data.processed === 0) {
						html += '<div class="notice notice-warning" style="margin-top: 10px;">';
						html += '<p><strong><?php _e('Troubleshooting:', 'csd-manager'); ?></strong></p>';
						html += '<ul>';
						html += '<li><?php _e('Check that you have mapped at least one column to the Email field', 'csd-manager'); ?></li>';
						html += '<li><?php _e('Ensure your data contains valid email addresses', 'csd-manager'); ?></li>';
						html += '<li><?php _e('Verify that column names in your mapping match the actual query results', 'csd-manager'); ?></li>';
						html += '</ul>';
						html += '</div>';
					}
					
					html += '</div>';
					
					if (data.list_url && data.processed > 0) {
						$('#csd-klaviyo-view-list').attr('data-url', data.list_url).show();
					}
					
					$('#csd-sync-results').html(html);
				}
		
				// Show sync error
				function showSyncError(message) {
					$('#csd-klaviyo-step-3').hide();
					$('#csd-klaviyo-step-4').show();
					
					var html = '<div class="notice notice-error">';
					html += '<p><strong><?php _e('Sync Failed:', 'csd-manager'); ?></strong> ' + message + '</p>';
					html += '</div>';
					
					$('#csd-sync-results').html(html);
				}
		
				// Handle view list in Klaviyo button
				$(document).on('click', '#csd-klaviyo-view-list', function() {
					var url = $(this).attr('data-url');
					if (url) {
						window.open(url, '_blank');
					}
				});
		
				// Handle modal close buttons
				$(document).on('click', '#csd-klaviyo-cancel, #csd-klaviyo-cancel-mapping, #csd-klaviyo-close, .csd-modal-close', function() {
					$('#csd-klaviyo-modal').hide();
				});
		
				// Close modal when clicking outside
				$(window).on('click', function(event) {
					if (event.target === $('#csd-klaviyo-modal')[0]) {
						$('#csd-klaviyo-modal').hide();
					}
				});
		
				// Show Klaviyo message
				function showKlaviyoMessage(type, message) {
					var messageDiv = $('#csd-klaviyo-message');
					messageDiv.removeClass('notice-success notice-error notice-warning');
					messageDiv.addClass('notice-' + type);
					messageDiv.html('<p>' + message + '</p>').show();
					
					// Auto-hide success messages after 5 seconds
					if (type === 'success') {
						setTimeout(function() {
							messageDiv.fadeOut();
						}, 5000);
					}
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
			$group_operators = isset($form_data['group_operators']) ? $form_data['group_operators'] : array();
			
			foreach ($form_data['conditions'] as $group_index => $group) {
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
				// Build the WHERE clause with proper group operators
				$where_parts = array();
				for ($i = 0; $i < count($condition_groups); $i++) {
					if ($i === 0) {
						// First group, no operator needed
						$where_parts[] = $condition_groups[$i];
					} else {
						// Get the operator for this group (default to OR if not specified)
						$operator = isset($group_operators[$i]) ? strtoupper($group_operators[$i]) : 'OR';
						$where_parts[] = ' ' . $operator . ' ' . $condition_groups[$i];
					}
				}
				
				$where_clause = 'WHERE ' . implode('', $where_parts);
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
			$count_sql = preg_replace('/\s+LIMIT\s+\d+\s+OFFSET\s+\d+$/is', '', $count_sql);
			$count_sql = preg_replace('/\s+GROUP\s+BY\s+.+$/is', '', $count_sql);
			
			// Get total count
			$total_count = $this->get_query_count($count_sql);
			
			// Add pagination to the query if it doesn't already have LIMIT
			if (!preg_match('/\s+LIMIT\s+\d+(?:\s*,\s*\d+)?$/i', $sql) && !preg_match('/\s+LIMIT\s+\d+\s+OFFSET\s+\d+$/i', $sql)) {
				// Ensure there's an ORDER BY clause for consistent pagination
				if (!preg_match('/\s+ORDER\s+BY\s+/i', $sql)) {
					// If there's no ORDER BY, add a default one on the first column
					$sql .= ' ORDER BY 1';
				}
				
				// Add LIMIT using the compatible syntax: LIMIT offset, count
				$offset = ($page - 1) * $per_page;
				$sql .= ' LIMIT ' . intval($offset) . ', ' . intval($per_page);
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
		
		// Fix escaped quotes that can come from AJAX or CodeMirror
		$sql = str_replace("\'", "'", $sql);
		$sql = str_replace('\"', '"', $sql);
		
		// Remove hexadecimal artifacts that might appear in LIKE clauses
		$sql = preg_replace('/{[0-9a-f]+}/', '%', $sql);
		
		// Remove any double %% that might cause issues
		$sql = str_replace('%%', '%', $sql);
		
		// Clean up any potential double escaping issues
		$sql = stripslashes($sql);
		
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
		
		// Create WHERE clause with support for group operators
		$where_clause = '';
		if (isset($form_data['conditions']) && is_array($form_data['conditions'])) {
			$condition_groups = array();
			$group_operators = isset($form_data['group_operators']) ? $form_data['group_operators'] : array();
			
			foreach ($form_data['conditions'] as $group_index => $group) {
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
				// Build the WHERE clause with proper group operators
				$where_parts = array();
				for ($i = 0; $i < count($condition_groups); $i++) {
					if ($i === 0) {
						// First group, no operator needed
						$where_parts[] = $condition_groups[$i];
					} else {
						// Get the operator for this group (default to OR if not specified)
						$operator = isset($group_operators[$i]) ? strtoupper($group_operators[$i]) : 'OR';
						$where_parts[] = ' ' . $operator . ' ' . $condition_groups[$i];
					}
				}
				
				$where_clause = 'WHERE ' . implode('', $where_parts);
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
			// Add LIMIT using the compatible syntax: LIMIT offset, count
			$offset = ($page - 1) * $per_page;
			$sql .= ' LIMIT ' . intval($offset) . ', ' . intval($per_page);
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
			
			// Table headers with proper data-column attributes
			$html .= '<thead><tr>';
			foreach (array_keys($results[0]) as $column) {
				// Create display label from column name
				$label = $this->format_column_header($column);
				
				// CRITICAL FIX: Always use the actual database column name in data-column
				$html .= '<th data-column="' . esc_attr($column) . '">';
				$html .= '<div class="th-content">' . esc_html($label) . '</div>';
				$html .= '<div class="resize-handle"></div>';
				$html .= '</th>';
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
			
			// Add Klaviyo sync section
			$html .= '<div class="csd-klaviyo-actions" style="margin-top: 20px; padding-top: 15px; border-top: 1px solid #ddd;">';
			$html .= '<h4>' . __('Klaviyo Integration', 'csd-manager') . '</h4>';
			$html .= '<p>' . __('Sync these query results to a Klaviyo list for email marketing.', 'csd-manager') . '</p>';
			$html .= '<button type="button" id="csd-sync-klaviyo" class="button button-secondary">';
			$html .= '<span class="dashicons dashicons-upload" style="margin-right: 5px;"></span>';
			$html .= __('Sync to Klaviyo List', 'csd-manager');
			$html .= '</button>';
			$html .= '</div>';
		}
		
		return $html;
	}
	
	/**
	 * Format column header for display
	 * 
	 * @param string $column Column name
	 * @return string Formatted header
	 */
	private function format_column_header($column) {
		// Define specific mappings for better display names
		$header_mappings = array(
			'schools_id' => 'School ID',
			'schools_school_name' => 'School Name',
			'schools_street_address_line_1' => 'Address Line 1',
			'schools_street_address_line_2' => 'Address Line 2',
			'schools_street_address_line_3' => 'Address Line 3',
			'schools_city' => 'City',
			'schools_state' => 'State',
			'schools_zipcode' => 'Zipcode',
			'schools_country' => 'Country',
			'schools_county' => 'County',
			'schools_school_divisions' => 'School Divisions',
			'schools_school_conferences' => 'School Conferences',
			'schools_school_level' => 'School Level',
			'schools_school_type' => 'School Type',
			'schools_school_enrollment' => 'Estimated Enrollment',
			'schools_mascot' => 'Nickname/Mascot',
			'schools_school_colors' => 'School Colors',
			'schools_school_website' => 'School Website',
			'schools_athletics_website' => 'Athletics Website',
			'schools_athletics_phone' => 'Athletics Phone',
			'schools_football_division' => 'Football Division',
			'schools_date_created' => 'School Date Created',
			'schools_date_updated' => 'School Date Updated',
			'staff_id' => 'Staff ID',
			'staff_full_name' => 'Full Name',
			'staff_title' => 'Title',
			'staff_sport_department' => 'Sport/Department',
			'staff_email' => 'Email',
			'staff_phone' => 'Phone',
			'staff_date_created' => 'Staff Date Created',
			'staff_date_updated' => 'Staff Date Updated',
			'school_staff_id' => 'Relationship ID',
			'school_staff_school_id' => 'School ID (Rel)',
			'school_staff_staff_id' => 'Staff ID (Rel)',
			'school_staff_date_created' => 'Relationship Created'
		);
		
		// Return specific mapping if it exists
		if (isset($header_mappings[$column])) {
			return $header_mappings[$column];
		}
		
		// Otherwise, do generic formatting
		$label = str_replace('_', ' ', $column);
		$label = ucwords($label);
		
		return $label;
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
			// Clean the SQL query
			$sql = $this->clean_sql_query($sql);
			
			// Remove any existing LIMIT clauses for export (we want all results)
			$sql = preg_replace('/\s+LIMIT\s+\d+(?:\s*,\s*\d+)?$/is', '', $sql);
			$sql = preg_replace('/\s+LIMIT\s+\d+\s+OFFSET\s+\d+$/is', '', $sql);
			
			// Get database connection
			$wpdb = csd_db_connection();
			
			// Execute the query directly
			$results = $wpdb->get_results($sql, ARRAY_A);
			
			// Check for database errors
			if ($wpdb->last_error) {
				error_log('SQL Error: ' . $wpdb->last_error);
				wp_die('Database error: ' . $wpdb->last_error);
			}
			
			if (empty($results)) {
				wp_die(__('No results to export.', 'csd-manager'));
			}
			
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