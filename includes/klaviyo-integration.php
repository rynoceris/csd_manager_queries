<?php
/**
 * Klaviyo Integration
 *
 * @package College Sports Directory Manager
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
	exit;
}

/**
 * Klaviyo Integration Class
 */
class CSD_Klaviyo_Integration {
	
	/**
	 * Constructor
	 */
	public function __construct() {
		add_action('admin_menu', array($this, 'add_settings_page'));
		add_action('admin_init', array($this, 'register_settings'));
		
		// AJAX handlers
		add_action('wp_ajax_csd_test_klaviyo_connection', array($this, 'ajax_test_connection'));
		add_action('wp_ajax_csd_get_klaviyo_lists', array($this, 'ajax_get_lists'));
		add_action('wp_ajax_csd_create_klaviyo_list', array($this, 'ajax_create_list'));
		add_action('wp_ajax_csd_get_klaviyo_fields', array($this, 'ajax_get_fields'));
		add_action('wp_ajax_csd_sync_to_klaviyo', array($this, 'ajax_sync_to_klaviyo'));
		
		// Add new AJAX handlers for field mapping persistence
		add_action('wp_ajax_csd_save_field_mapping', array($this, 'ajax_save_field_mapping'));
		add_action('wp_ajax_csd_get_saved_field_mapping', array($this, 'ajax_get_saved_field_mapping'));
	}
	
	/**
	 * Add settings page to admin menu
	 */
	public function add_settings_page() {
		add_submenu_page(
			'csd-manager',
			__('Klaviyo Settings', 'csd-manager'),
			__('Klaviyo Settings', 'csd-manager'),
			'manage_options',
			'csd-klaviyo-settings',
			array($this, 'render_settings_page')
		);
	}
	
	/**
	 * Register settings
	 */
	public function register_settings() {
		register_setting('csd_klaviyo_settings', 'csd_klaviyo_private_key');
		register_setting('csd_klaviyo_settings', 'csd_klaviyo_public_key');
	}
	
	/**
	 * Render settings page
	 */
	public function render_settings_page() {
		?>
		<div class="wrap">
			<h1><?php _e('Klaviyo Integration Settings', 'csd-manager'); ?></h1>
			
			<form method="post" action="options.php">
				<?php
				settings_fields('csd_klaviyo_settings');
				do_settings_sections('csd_klaviyo_settings');
				?>
				
				<table class="form-table">
					<tr>
						<th scope="row"><?php _e('Klaviyo Private API Key', 'csd-manager'); ?></th>
						<td>
							<input type="password" id="csd_klaviyo_private_key" name="csd_klaviyo_private_key" 
								   value="<?php echo esc_attr(get_option('csd_klaviyo_private_key')); ?>" 
								   class="regular-text" />
							<p class="description">
								<?php _e('Your Klaviyo Private API Key (starts with pk_). You can find this in your Klaviyo account under Settings > API Keys.', 'csd-manager'); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php _e('Klaviyo Public API Key', 'csd-manager'); ?></th>
						<td>
							<input type="text" id="csd_klaviyo_public_key" name="csd_klaviyo_public_key" 
								   value="<?php echo esc_attr(get_option('csd_klaviyo_public_key')); ?>" 
								   class="regular-text" />
							<p class="description">
								<?php _e('Your Klaviyo Public API Key. This is optional and only needed for some advanced features.', 'csd-manager'); ?>
							</p>
						</td>
					</tr>
				</table>
				
				<?php submit_button(); ?>
			</form>
			
			<hr>
			
			<h2><?php _e('Test Connection', 'csd-manager'); ?></h2>
			<p><?php _e('Test your Klaviyo API connection to make sure your credentials are working properly.', 'csd-manager'); ?></p>
			<button type="button" id="csd-test-klaviyo" class="button"><?php _e('Test Klaviyo Connection', 'csd-manager'); ?></button>
			<div id="csd-klaviyo-test-result" style="margin-top: 10px;"></div>
			
			<hr>
			
			<h2><?php _e('Cache Management', 'csd-manager'); ?></h2>
			<p><?php _e('Clear cached Klaviyo data if you need to refresh field mappings or lists.', 'csd-manager'); ?></p>
			<button type="button" id="csd-clear-cache" class="button"><?php _e('Clear Klaviyo Cache', 'csd-manager'); ?></button>
			<div id="csd-cache-result" style="margin-top: 10px;"></div>
		</div>
		
		<script type="text/javascript">
			jQuery(document).ready(function($) {
				$('#csd-test-klaviyo').on('click', function() {
					var button = $(this);
					var resultDiv = $('#csd-klaviyo-test-result');
					
					button.prop('disabled', true).text('<?php _e('Testing...', 'csd-manager'); ?>');
					resultDiv.html('');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'csd_test_klaviyo_connection',
							private_key: $('#csd_klaviyo_private_key').val(),
							nonce: '<?php echo wp_create_nonce('csd-klaviyo-nonce'); ?>'
						},
						success: function(response) {
							button.prop('disabled', false).text('<?php _e('Test Klaviyo Connection', 'csd-manager'); ?>');
							
							if (response.success) {
								resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							} else {
								resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
							}
						},
						error: function() {
							button.prop('disabled', false).text('<?php _e('Test Klaviyo Connection', 'csd-manager'); ?>');
							resultDiv.html('<div class="notice notice-error"><p><?php _e('Error testing connection.', 'csd-manager'); ?></p></div>');
						}
					});
				});
				
				$('#csd-clear-cache').on('click', function() {
					var button = $(this);
					var resultDiv = $('#csd-cache-result');
					
					button.prop('disabled', true).text('<?php _e('Clearing...', 'csd-manager'); ?>');
					resultDiv.html('');
					
					$.ajax({
						url: ajaxurl,
						type: 'POST',
						data: {
							action: 'csd_clear_klaviyo_cache',
							nonce: '<?php echo wp_create_nonce('csd-klaviyo-nonce'); ?>'
						},
						success: function(response) {
							button.prop('disabled', false).text('<?php _e('Clear Klaviyo Cache', 'csd-manager'); ?>');
							
							if (response.success) {
								resultDiv.html('<div class="notice notice-success"><p>' + response.data.message + '</p></div>');
							} else {
								resultDiv.html('<div class="notice notice-error"><p>' + response.data.message + '</p></div>');
							}
						},
						error: function() {
							button.prop('disabled', false).text('<?php _e('Clear Klaviyo Cache', 'csd-manager'); ?>');
							resultDiv.html('<div class="notice notice-error"><p><?php _e('Error clearing cache.', 'csd-manager'); ?></p></div>');
						}
					});
				});
			});
		</script>
		<?php
	}
	
	/**
	 * Get all items from a paginated Klaviyo endpoint
	 * 
	 * @param string $endpoint The API endpoint
	 * @return array|WP_Error All items or error
	 */
	private function get_all_paginated_items($endpoint) {
		$all_items = array();
		$cursor = null;
		$page_count = 0;
		$max_pages = 50; // Safety limit
		
		do {
			// Build the endpoint URL with pagination
			$url = $endpoint;
			if ($cursor) {
				$separator = strpos($url, '?') !== false ? '&' : '?';
				$url .= $separator . 'page[cursor]=' . urlencode($cursor);
			}
			
			$result = $this->make_api_request($url);
			
			if (is_wp_error($result)) {
				return $result;
			}
			
			// Add items from this page
			if (isset($result['data']) && is_array($result['data'])) {
				$all_items = array_merge($all_items, $result['data']);
			}
			
			// Check if there's a next page
			$cursor = null;
			if (isset($result['links']['next'])) {
				// Parse the next URL to extract the cursor
				$next_url = $result['links']['next'];
				$parsed_url = parse_url($next_url);
				if (isset($parsed_url['query'])) {
					parse_str($parsed_url['query'], $query_params);
					if (isset($query_params['page']['cursor'])) {
						$cursor = $query_params['page']['cursor'];
					} elseif (isset($query_params['page[cursor]'])) {
						$cursor = $query_params['page[cursor]'];
					}
				}
			}
			
			$page_count++;
			
		} while ($cursor && $page_count < $max_pages);
		
		return $all_items;
	}
	
	/**
	 * Get Klaviyo API headers
	 */
	private function get_api_headers() {
		$private_key = get_option('csd_klaviyo_private_key');
		
		if (empty($private_key)) {
			return false;
		}
		
		return array(
			'Authorization' => 'Klaviyo-API-Key ' . $private_key,
			'Accept' => 'application/json',
			'Content-Type' => 'application/json',
			'revision' => '2024-10-15'
		);
	}
	
	/**
	 * Make API request to Klaviyo
	 */
	private function make_api_request($endpoint, $method = 'GET', $body = null) {
		$headers = $this->get_api_headers();
		
		if (!$headers) {
			return new WP_Error('no_api_key', __('Klaviyo API key not configured.', 'csd-manager'));
		}
		
		$url = 'https://a.klaviyo.com/api/' . ltrim($endpoint, '/');
		
		$args = array(
			'method' => $method,
			'headers' => $headers,
			'timeout' => 30
		);
		
		if ($body && in_array($method, array('POST', 'PUT', 'PATCH'))) {
			$args['body'] = is_array($body) ? json_encode($body) : $body;
		}
		
		$response = wp_remote_request($url, $args);
		
		if (is_wp_error($response)) {
			return $response;
		}
		
		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		
		$decoded = json_decode($body, true);
		
		if ($status_code >= 400) {
			$error_message = 'API Error: ' . $status_code;
			if (isset($decoded['errors'][0]['detail'])) {
				$error_message .= ' - ' . $decoded['errors'][0]['detail'];
			}
			return new WP_Error('api_error', $error_message);
		}
		
		return $decoded;
	}
	
	/**
	 * Test Klaviyo API connection
	 */
	public function ajax_test_connection() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		// Temporarily use the provided key for testing
		$private_key = sanitize_text_field($_POST['private_key']);
		
		if (empty($private_key)) {
			wp_send_json_error(array('message' => __('Please enter your Klaviyo Private API Key.', 'csd-manager')));
			return;
		}
		
		// Test the connection by trying to get account info
		$headers = array(
			'Authorization' => 'Klaviyo-API-Key ' . $private_key,
			'Accept' => 'application/json',
			'revision' => '2024-10-15'
		);
		
		$response = wp_remote_get('https://a.klaviyo.com/api/accounts/', array(
			'headers' => $headers,
			'timeout' => 15
		));
		
		if (is_wp_error($response)) {
			wp_send_json_error(array('message' => __('Connection failed: ', 'csd-manager') . $response->get_error_message()));
			return;
		}
		
		$status_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);
		
		if ($status_code === 200) {
			$decoded = json_decode($body, true);
			
			if (isset($decoded['data'][0]['attributes'])) {
				$attributes = $decoded['data'][0]['attributes'];
				
				// Check if it's a test account (boolean value)
				$is_test_account = isset($attributes['test_account']) && $attributes['test_account'] === true;
				$account_type = $is_test_account ? __('Test Account', 'csd-manager') : __('Live Account', 'csd-manager');
				
				// Get additional account info if available
				$account_id = isset($decoded['data'][0]['id']) ? $decoded['data'][0]['id'] : 'Unknown';
				$contact_info = isset($attributes['contact_information']['default_sender_name']) 
					? $attributes['contact_information']['default_sender_name'] 
					: 'Unknown';
				
				$message = sprintf(
					__('Connection successful! Account Type: %s | Account ID: %s | Default Sender: %s', 'csd-manager'), 
					$account_type, 
					$account_id, 
					$contact_info
				);
				
				wp_send_json_success(array('message' => $message));
			} else {
				wp_send_json_success(array('message' => __('Connection successful! Account details not available.', 'csd-manager')));
			}
		} else {
			$decoded = json_decode($body, true);
			$error_message = isset($decoded['errors'][0]['detail']) 
				? $decoded['errors'][0]['detail'] 
				: sprintf(__('HTTP Error: %d', 'csd-manager'), $status_code);
			
			wp_send_json_error(array('message' => __('Connection failed: ', 'csd-manager') . $error_message));
		}
	}
	
	/**
	 * Get Klaviyo lists
	 */
	public function ajax_get_lists() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		// Check if we should force refresh
		$force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'];
		
		// Try to get cached lists first
		$cache_key = 'csd_klaviyo_lists_' . md5(get_option('csd_klaviyo_private_key'));
		$cached_lists = get_transient($cache_key);
		
		if (!$force_refresh && $cached_lists !== false) {
			wp_send_json_success(array(
				'lists' => $cached_lists,
				'cached' => true,
				'cache_time' => get_option($cache_key . '_timestamp', time())
			));
			return;
		}
		
		// Get all lists using pagination helper
		$all_lists = array();
		$cursor = null;
		$page_count = 0;
		$max_pages = 50; // Safety limit
		
		do {
			// Build the endpoint URL with pagination
			$endpoint = 'lists/';
			$params = array();
			
			// Add cursor for subsequent pages
			if ($cursor) {
				$params['page[cursor]'] = $cursor;
			}
			
			// Build query string if we have parameters
			if (!empty($params)) {
				$endpoint .= '?' . http_build_query($params);
			}
			
			$result = $this->make_api_request($endpoint);
			
			if (is_wp_error($result)) {
				wp_send_json_error(array('message' => $result->get_error_message()));
				return;
			}
			
			// Add lists from this page
			if (isset($result['data']) && is_array($result['data'])) {
				foreach ($result['data'] as $list) {
					$all_lists[] = array(
						'id' => $list['id'],
						'name' => $list['attributes']['name'],
						'created' => $list['attributes']['created']
					);
				}
			}
			
			// Check if there's a next page
			$cursor = null;
			if (isset($result['links']['next'])) {
				// Parse the next URL to extract the cursor
				$next_url = $result['links']['next'];
				$parsed_url = parse_url($next_url);
				if (isset($parsed_url['query'])) {
					parse_str($parsed_url['query'], $query_params);
					if (isset($query_params['page']['cursor'])) {
						$cursor = $query_params['page']['cursor'];
					} elseif (isset($query_params['page[cursor]'])) {
						$cursor = $query_params['page[cursor]'];
					}
				}
			}
			
			$page_count++;
			
		} while ($cursor && $page_count < $max_pages);
		
		// Sort lists alphabetically by name
		usort($all_lists, function($a, $b) {
			return strcasecmp($a['name'], $b['name']);
		});
		
		// Cache the results for 1 hour
		set_transient($cache_key, $all_lists, HOUR_IN_SECONDS);
		update_option($cache_key . '_timestamp', time());
		
		wp_send_json_success(array(
			'lists' => $all_lists,
			'total_count' => count($all_lists),
			'pages_fetched' => $page_count,
			'cached' => false
		));
	}
	
	/**
	 * Create Klaviyo list
	 */
	public function ajax_create_list() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$list_name = sanitize_text_field($_POST['list_name']);
		
		if (empty($list_name)) {
			wp_send_json_error(array('message' => __('List name is required.', 'csd-manager')));
			return;
		}
		
		$body = array(
			'data' => array(
				'type' => 'list',
				'attributes' => array(
					'name' => $list_name
				)
			)
		);
		
		$result = $this->make_api_request('lists/', 'POST', $body);
		
		if (is_wp_error($result)) {
			wp_send_json_error(array('message' => $result->get_error_message()));
			return;
		}
		
		if (isset($result['data'])) {
			wp_send_json_success(array(
				'list' => array(
					'id' => $result['data']['id'],
					'name' => $result['data']['attributes']['name']
				)
			));
		} else {
			wp_send_json_error(array('message' => __('Failed to create list.', 'csd-manager')));
		}
	}
	
	/**
	 * Get available Klaviyo profile fields - IMPROVED with better caching
	 */
	public function ajax_get_fields() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		// Check if we should force refresh
		$force_refresh = isset($_POST['force_refresh']) && $_POST['force_refresh'];
		
		// Try to get cached fields first
		$cache_key = 'csd_klaviyo_fields_' . md5(get_option('csd_klaviyo_private_key'));
		$cached_fields = get_transient($cache_key);
		
		if (!$force_refresh && $cached_fields !== false) {
			wp_send_json_success(array(
				'fields' => $cached_fields,
				'cached' => true,
				'cache_time' => get_option($cache_key . '_timestamp', time())
			));
			return;
		}
		
		// Start with standard Klaviyo profile fields
		$fields = array(
			'email' => __('Email', 'csd-manager'),
			'phone_number' => __('Phone Number', 'csd-manager'),
			'first_name' => __('First Name', 'csd-manager'),
			'last_name' => __('Last Name', 'csd-manager'),
			'organization' => __('Organization', 'csd-manager'),
			'title' => __('Title', 'csd-manager'),
			'image' => __('Image URL', 'csd-manager'),
			'location.address1' => __('Address 1', 'csd-manager'),
			'location.address2' => __('Address 2', 'csd-manager'),
			'location.city' => __('City', 'csd-manager'),
			'location.region' => __('State/Region', 'csd-manager'),
			'location.country' => __('Country', 'csd-manager'),
			'location.zip' => __('Zip Code', 'csd-manager'),
			'location.latitude' => __('Latitude', 'csd-manager'),
			'location.longitude' => __('Longitude', 'csd-manager'),
		);
		
		// Get custom fields from Klaviyo account
		$custom_fields = $this->get_custom_profile_fields();
		
		if (!is_wp_error($custom_fields)) {
			// Merge custom fields with standard fields
			$fields = array_merge($fields, $custom_fields);
		}
		
		// Sort fields alphabetically while keeping email at the top
		$email_field = array('email' => $fields['email']);
		unset($fields['email']);
		asort($fields);
		$fields = $email_field + $fields;
		
		// Cache the results for 4 hours (longer cache for better UX)
		set_transient($cache_key, $fields, 4 * HOUR_IN_SECONDS);
		update_option($cache_key . '_timestamp', time());
		
		wp_send_json_success(array(
			'fields' => $fields,
			'cached' => false
		));
	}
	
	/**
	 * Save field mapping for reuse
	 */
	public function ajax_save_field_mapping() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$mapping_name = sanitize_text_field($_POST['mapping_name']);
		$field_mapping = $_POST['field_mapping'];
		
		if (empty($mapping_name) || empty($field_mapping)) {
			wp_send_json_error(array('message' => __('Mapping name and field mapping are required.', 'csd-manager')));
			return;
		}
		
		// Get existing mappings
		$saved_mappings = get_option('csd_klaviyo_field_mappings', array());
		
		// Save the new mapping
		$saved_mappings[$mapping_name] = $field_mapping;
		
		// Update the option
		update_option('csd_klaviyo_field_mappings', $saved_mappings);
		
		wp_send_json_success(array('message' => __('Field mapping saved successfully.', 'csd-manager')));
	}
	
	/**
	 * Get saved field mappings
	 */
	public function ajax_get_saved_field_mapping() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$saved_mappings = get_option('csd_klaviyo_field_mappings', array());
		
		wp_send_json_success(array('mappings' => $saved_mappings));
	}
	
	/**
	 * Clear Klaviyo cache
	 */
	public function ajax_clear_klaviyo_cache() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$this->clear_all_cache();
		
		wp_send_json_success(array(
			'message' => __('Klaviyo cache cleared successfully.', 'csd-manager')
		));
	}
	
	/**
	 * Clear all Klaviyo cache
	 */
	private function clear_all_cache() {
		$api_key_hash = md5(get_option('csd_klaviyo_private_key'));
		
		// Clear lists cache
		$lists_cache_key = 'csd_klaviyo_lists_' . $api_key_hash;
		delete_transient($lists_cache_key);
		delete_option($lists_cache_key . '_timestamp');
		
		// Clear fields cache
		$fields_cache_key = 'csd_klaviyo_fields_' . $api_key_hash;
		delete_transient($fields_cache_key);
		delete_option($fields_cache_key . '_timestamp');
	}
	
	/**
	 * Get cache status
	 */
	public function ajax_get_cache_status() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$api_key_hash = md5(get_option('csd_klaviyo_private_key'));
		
		$lists_cache_key = 'csd_klaviyo_lists_' . $api_key_hash;
		$fields_cache_key = 'csd_klaviyo_fields_' . $api_key_hash;
		
		$lists_cached = get_transient($lists_cache_key) !== false;
		$fields_cached = get_transient($fields_cache_key) !== false;
		
		$lists_time = get_option($lists_cache_key . '_timestamp', 0);
		$fields_time = get_option($fields_cache_key . '_timestamp', 0);
		
		wp_send_json_success(array(
			'lists' => array(
				'cached' => $lists_cached,
				'cache_time' => $lists_time,
				'age_minutes' => $lists_time ? round((time() - $lists_time) / 60) : 0
			),
			'fields' => array(
				'cached' => $fields_cached,
				'cache_time' => $fields_time,
				'age_minutes' => $fields_time ? round((time() - $fields_time) / 60) : 0
			)
		));
	}
	
	/**
	 * Refresh Klaviyo fields
	 */
	public function ajax_refresh_fields() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		// Clear any cached field data (if you implement caching later)
		delete_transient('csd_klaviyo_custom_fields');
		
		// Call the regular get fields method
		$this->ajax_get_fields();
	}
	
	/**
	 * Get custom profile fields from Klaviyo account
	 * 
	 * @return array|WP_Error Array of custom fields or error
	 */
	private function get_custom_profile_fields() {
		$custom_fields = array();
		$found_properties = array();
		
		try {
			// Method 1: Sample a larger number of profiles to find more custom fields
			$this->discover_fields_from_profiles($found_properties);
			
			// Method 2: Get fields from events (often shows more custom properties)
			$this->discover_fields_from_events($found_properties);
			
			// Method 3: Try to get fields from segments (these often use custom properties)
			$this->discover_fields_from_segments($found_properties);
			
			// Method 4: Check flows for custom properties
			$this->discover_fields_from_flows($found_properties);
			
			// Convert found properties to field options
			foreach (array_keys($found_properties) as $property_name) {
				// Skip standard fields and system fields
				if (!$this->is_standard_field($property_name) && !$this->is_system_field($property_name)) {
					$label = $this->format_field_label($property_name);
					$custom_fields['properties.' . $property_name] = sprintf(__('Custom: %s', 'csd-manager'), $label);
				}
			}
			
			// Add some common custom field placeholders in case we missed any
			$this->add_common_custom_fields($custom_fields);
			
		} catch (Exception $e) {
			error_log('Klaviyo field discovery error: ' . $e->getMessage());
		}
		
		return $custom_fields;
	}
	
	/**
	 * Discover fields from profiles with multiple pagination rounds
	 */
	private function discover_fields_from_profiles(&$found_properties) {
		$cursor = null;
		$pages_checked = 0;
		$max_pages = 10; // Check up to 10 pages of profiles
		
		do {
			$endpoint = 'profiles/';
			$params = array('page[size]' => 100); // Get maximum profiles per page
			
			if ($cursor) {
				$params['page[cursor]'] = $cursor;
			}
			
			$endpoint .= '?' . http_build_query($params);
			$result = $this->make_api_request($endpoint);
			
			if (!is_wp_error($result) && isset($result['data'])) {
				foreach ($result['data'] as $profile) {
					if (isset($profile['attributes']['properties'])) {
						foreach ($profile['attributes']['properties'] as $property_name => $property_value) {
							if ($property_value !== null) {
								$found_properties[$property_name] = true;
							}
						}
					}
				}
				
				// Get cursor for next page
				$cursor = null;
				if (isset($result['links']['next'])) {
					$next_url = $result['links']['next'];
					$parsed_url = parse_url($next_url);
					if (isset($parsed_url['query'])) {
						parse_str($parsed_url['query'], $query_params);
						if (isset($query_params['page']['cursor'])) {
							$cursor = $query_params['page']['cursor'];
						} elseif (isset($query_params['page[cursor]'])) {
							$cursor = $query_params['page[cursor]'];
						}
					}
				}
			} else {
				break; // Stop if there's an error
			}
			
			$pages_checked++;
			
		} while ($cursor && $pages_checked < $max_pages);
	}
	
	/**
	 * Discover fields from events
	 */
	private function discover_fields_from_events(&$found_properties) {
		$cursor = null;
		$pages_checked = 0;
		$max_pages = 5; // Check up to 5 pages of events
		
		do {
			$endpoint = 'events/';
			$params = array('page[size]' => 100);
			
			if ($cursor) {
				$params['page[cursor]'] = $cursor;
			}
			
			$endpoint .= '?' . http_build_query($params);
			$result = $this->make_api_request($endpoint);
			
			if (!is_wp_error($result) && isset($result['data'])) {
				foreach ($result['data'] as $event) {
					// Check profile data in events
					if (isset($event['attributes']['profile']['data']['attributes']['properties'])) {
						foreach ($event['attributes']['profile']['data']['attributes']['properties'] as $property_name => $property_value) {
							if ($property_value !== null) {
								$found_properties[$property_name] = true;
							}
						}
					}
					
					// Also check event properties that might reference profile fields
					if (isset($event['attributes']['properties'])) {
						foreach ($event['attributes']['properties'] as $property_name => $property_value) {
							// Look for properties that might be custom profile fields
							if (strpos($property_name, 'profile_') === 0 || 
								strpos($property_name, 'customer_') === 0 ||
								strpos($property_name, 'user_') === 0) {
								$clean_name = str_replace(array('profile_', 'customer_', 'user_'), '', $property_name);
								$found_properties[$clean_name] = true;
							}
						}
					}
				}
				
				// Get cursor for next page
				$cursor = null;
				if (isset($result['links']['next'])) {
					$next_url = $result['links']['next'];
					$parsed_url = parse_url($next_url);
					if (isset($parsed_url['query'])) {
						parse_str($parsed_url['query'], $query_params);
						if (isset($query_params['page']['cursor'])) {
							$cursor = $query_params['page']['cursor'];
						} elseif (isset($query_params['page[cursor]'])) {
							$cursor = $query_params['page[cursor]'];
						}
					}
				}
			} else {
				break;
			}
			
			$pages_checked++;
			
		} while ($cursor && $pages_checked < $max_pages);
	}
	
	/**
	 * Discover fields from segments
	 */
	private function discover_fields_from_segments(&$found_properties) {
		$result = $this->make_api_request('segments/');
		
		if (!is_wp_error($result) && isset($result['data'])) {
			foreach ($result['data'] as $segment) {
				if (isset($segment['attributes']['definition'])) {
					$definition = json_encode($segment['attributes']['definition']);
					
					// Look for property references in segment definitions
					if (preg_match_all('/properties\.(\w+)/', $definition, $matches)) {
						foreach ($matches[1] as $property_name) {
							$found_properties[$property_name] = true;
						}
					}
				}
			}
		}
	}
	
	/**
	 * Discover fields from flows
	 */
	private function discover_fields_from_flows(&$found_properties) {
		$result = $this->make_api_request('flows/');
		
		if (!is_wp_error($result) && isset($result['data'])) {
			foreach ($result['data'] as $flow) {
				// Get flow actions which might reference custom properties
				$flow_id = $flow['id'];
				$actions_result = $this->make_api_request("flows/{$flow_id}/flow-actions/");
				
				if (!is_wp_error($actions_result) && isset($actions_result['data'])) {
					foreach ($actions_result['data'] as $action) {
						if (isset($action['attributes']['settings'])) {
							$settings = json_encode($action['attributes']['settings']);
							
							// Look for property references in flow action settings
							if (preg_match_all('/\{\{\s*person\.(\w+)\s*\}\}/', $settings, $matches)) {
								foreach ($matches[1] as $property_name) {
									$found_properties[$property_name] = true;
								}
							}
							
							if (preg_match_all('/properties\.(\w+)/', $settings, $matches)) {
								foreach ($matches[1] as $property_name) {
									$found_properties[$property_name] = true;
								}
							}
						}
					}
				}
			}
		}
	}
	
	/**
	 * Check if a field is a standard Klaviyo field
	 */
	private function is_standard_field($field_name) {
		$standard_fields = array(
			'email', 'phone_number', 'first_name', 'last_name', 'organization', 
			'title', 'image', 'created', 'updated', 'last_event_date', 'location',
			'timezone', 'id'
		);
		
		return in_array($field_name, $standard_fields);
	}
	
	/**
	 * Check if a field is a system field that shouldn't be mapped
	 */
	private function is_system_field($field_name) {
		$system_fields = array(
			'_kx', 'klaviyo_id', 'anonymous_id', '$source', '$attributed_source',
			'$email_domain', '$timezone', '$region', '$country_code', '$city',
			'$zip', '$latitude', '$longitude'
		);
		
		return in_array($field_name, $system_fields) || 
			   strpos($field_name, '$') === 0 || 
			   strpos($field_name, '_kx') === 0;
	}
	
	/**
	 * Format field label for display
	 */
	private function format_field_label($property_name) {
		// Convert snake_case and camelCase to Title Case
		$label = preg_replace('/([a-z])([A-Z])/', '$1 $2', $property_name);
		$label = str_replace(array('_', '-'), ' ', $label);
		$label = ucwords(strtolower($label));
		
		return $label;
	}
	
	/**
	 * Add common custom field placeholders
	 */
	private function add_common_custom_fields(&$custom_fields) {
		$common_fields = array(
			'company' => __('Company', 'csd-manager'),
			'department' => __('Department', 'csd-manager'),
			'position' => __('Position', 'csd-manager'),
			'website' => __('Website', 'csd-manager'),
			'birthday' => __('Birthday', 'csd-manager'),
			'gender' => __('Gender', 'csd-manager'),
			'industry' => __('Industry', 'csd-manager'),
			'annual_revenue' => __('Annual Revenue', 'csd-manager'),
			'employee_count' => __('Employee Count', 'csd-manager'),
			'source' => __('Source', 'csd-manager'),
			'custom_field' => __('Generic Custom Field', 'csd-manager'),
		);
		
		foreach ($common_fields as $field_key => $field_label) {
			if (!isset($custom_fields['properties.' . $field_key])) {
				$custom_fields['properties.' . $field_key] = sprintf(__('Common: %s', 'csd-manager'), $field_label);
			}
		}
	}
	
	/**
	 * Updated main sync method - Import profiles and intelligently handle subscriptions
	 */
	public function ajax_sync_to_klaviyo() {
		// Check nonce
		if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'csd-klaviyo-nonce')) {
			wp_send_json_error(array('message' => __('Security check failed.', 'csd-manager')));
			return;
		}
		
		if (!current_user_can('manage_options')) {
			wp_send_json_error(array('message' => __('You do not have permission to perform this action.', 'csd-manager')));
			return;
		}
		
		$list_id = sanitize_text_field($_POST['list_id']);
		$field_mapping = $_POST['field_mapping'];
		$sql_query = $_POST['sql_query'];
		
		if (empty($list_id) || empty($field_mapping) || empty($sql_query)) {
			wp_send_json_error(array('message' => __('Missing required parameters.', 'csd-manager')));
			return;
		}
		
		// Clean the SQL query to handle escaped quotes
		$sql_query = $this->clean_sql_for_sync($sql_query);
		
		// Remove pagination from SQL query to get ALL records
		$sql_query = $this->remove_pagination_from_sql($sql_query);
		
		// Debug logging
		error_log('Klaviyo Sync Debug - Starting smart subscription sync');
		error_log('Klaviyo Sync Debug - List ID: ' . $list_id);
		
		try {
			// Get database connection
			$wpdb = csd_db_connection();
			
			// Execute the query to get ALL data (no pagination)
			$results = $wpdb->get_results($sql_query, ARRAY_A);
			
			if ($wpdb->last_error) {
				wp_send_json_error(array('message' => 'Database error: ' . $wpdb->last_error));
				return;
			}
			
			if (empty($results)) {
				wp_send_json_error(array('message' => __('No data to sync.', 'csd-manager')));
				return;
			}
			
			error_log('Klaviyo Sync Debug - Total SQL results: ' . count($results));
			
			// Validate field mapping
			$validated_field_mapping = array();
			$available_columns = array_keys($results[0]);
			
			foreach ($field_mapping as $column_name => $klaviyo_field) {
				if (!empty($klaviyo_field)) {
					if (in_array($column_name, $available_columns)) {
						$validated_field_mapping[$column_name] = $klaviyo_field;
					}
				}
			}
			
			if (empty($validated_field_mapping)) {
				wp_send_json_error(array('message' => __('No valid field mappings found. Please check your column mappings.', 'csd-manager')));
				return;
			}
			
			// Check that email is mapped
			$has_email_mapping = false;
			foreach ($validated_field_mapping as $column => $klaviyo_field) {
				if ($klaviyo_field === 'email') {
					$has_email_mapping = true;
					break;
				}
			}
			
			if (!$has_email_mapping) {
				wp_send_json_error(array('message' => __('Email field mapping is required for Klaviyo sync.', 'csd-manager')));
				return;
			}
			
			// Process results and prepare profile data
			$total_records = count($results);
			$processed = 0;
			$errors = 0;
			$skipped = 0;
			$validation_errors = array();
			
			// Prepare all profile data first with deduplication
			$all_profiles = array();
			$all_emails = array();
			$email_to_profile_map = array(); // Track unique emails to prevent duplicates
			
			foreach ($results as $row_index => $row) {
				$profile_data = array();
				$has_email = false;
				$email_address = '';
				
				foreach ($validated_field_mapping as $csv_field => $klaviyo_field) {
					if (!empty($klaviyo_field) && isset($row[$csv_field])) {
						$value = $row[$csv_field];
						
						// Skip completely empty values but allow 0
						if ($value === null || $value === '') {
							continue;
						}
						
						// Handle email field specially
						if ($klaviyo_field === 'email') {
							if (filter_var($value, FILTER_VALIDATE_EMAIL) && strpos($value, '@placeholder') === false) {
								$has_email = true;
								$email_address = strtolower(trim($value)); // Normalize email for deduplication
								$profile_data['email'] = $email_address;
							} else {
								$validation_errors[] = "Row " . ($row_index + 1) . ": Invalid email '" . $value . "'";
								continue 2; // Skip this entire row
							}
						} else {
							// Handle nested properties
							if (strpos($klaviyo_field, '.') !== false) {
								$parts = explode('.', $klaviyo_field, 2);
								if ($parts[0] === 'location') {
									if (!isset($profile_data['location'])) {
										$profile_data['location'] = array();
									}
									$profile_data['location'][$parts[1]] = $value;
								} elseif ($parts[0] === 'properties') {
									if (!isset($profile_data['properties'])) {
										$profile_data['properties'] = array();
									}
									$profile_data['properties'][$parts[1]] = $value;
								}
							} else {
								$profile_data[$klaviyo_field] = $value;
							}
						}
					}
				}
				
				// Only add profiles that have a valid email and haven't been seen before
				if ($has_email && !empty($profile_data)) {
					if (isset($email_to_profile_map[$email_address])) {
						// Email already exists, merge the profile data
						$existing_profile = $email_to_profile_map[$email_address];
						
						// Merge properties, giving preference to non-empty values
						foreach ($profile_data as $key => $value) {
							if ($key === 'email') continue; // Skip email field
							
							if ($key === 'properties' && isset($existing_profile['properties'])) {
								$existing_profile['properties'] = array_merge($existing_profile['properties'], $value);
							} elseif ($key === 'location' && isset($existing_profile['location'])) {
								$existing_profile['location'] = array_merge($existing_profile['location'], $value);
							} elseif (!isset($existing_profile[$key]) || empty($existing_profile[$key])) {
								$existing_profile[$key] = $value;
							}
						}
						
						$email_to_profile_map[$email_address] = $existing_profile;
						error_log('Klaviyo Sync Debug - Merged duplicate profile for email: ' . $email_address);
					} else {
						// New unique email
						$email_to_profile_map[$email_address] = $profile_data;
						$all_emails[] = $email_address;
					}
				} else {
					$skipped++;
				}
			}
			
			// Convert the deduplicated map back to an array
			$all_profiles = array_values($email_to_profile_map);
			$duplicates_found = $total_records - $skipped - count($all_profiles);
			
			error_log('Klaviyo Sync Debug - Prepared ' . count($all_profiles) . ' unique profiles for sync');
			if ($duplicates_found > 0) {
				error_log('Klaviyo Sync Debug - Merged ' . $duplicates_found . ' duplicate profiles');
			}
			
			if (empty($all_profiles)) {
				wp_send_json_error(array('message' => __('No valid profiles to sync. Please check your email mapping and data.', 'csd-manager')));
				return;
			}
			
			// Step 1: Bulk import profiles
			$import_results = $this->bulk_import_profiles($all_profiles);
			
			if (is_wp_error($import_results)) {
				wp_send_json_error(array('message' => 'Bulk import failed: ' . $import_results->get_error_message()));
				return;
			}
			
			$processed = $import_results['processed'];
			$errors = $import_results['errors'];
			
			// Step 2: Safely subscribe profiles with proper error handling
			$subscription_results = $this->safe_bulk_subscribe($all_emails, $list_id);
			
			// Prepare response message
			$message_parts = array();
			$message_parts[] = sprintf(__('%d profiles imported', 'csd-manager'), $processed);
			$message_parts[] = sprintf(__('%d subscribed to list', 'csd-manager'), $subscription_results['subscribed']);
			
			if ($subscription_results['already_subscribed'] > 0) {
				$message_parts[] = sprintf(__('%d already subscribed/added to list', 'csd-manager'), $subscription_results['already_subscribed']);
			}
			
			if ($errors > 0) {
				$message_parts[] = sprintf(__('%d errors', 'csd-manager'), $errors);
			}
			
			if ($skipped > 0) {
				$message_parts[] = sprintf(__('%d skipped (no valid email)', 'csd-manager'), $skipped);
			}
			
			if ($duplicates_found > 0) {
				$message_parts[] = sprintf(__('%d duplicates merged', 'csd-manager'), $duplicates_found);
			}
			
			$final_message = __('Sync completed! ', 'csd-manager') . implode(', ', $message_parts) . '.';
			
			// Include error details if any
			$response_data = array(
				'message' => $final_message,
				'processed' => $processed,
				'subscribed' => $subscription_results['subscribed'],
				'already_subscribed' => $subscription_results['already_subscribed'],
				'errors' => $errors,
				'skipped' => $skipped,
				'duplicates_merged' => $duplicates_found,
				'total_records' => $total_records,
				'validation_errors' => array_slice($validation_errors, 0, 10),
				'list_url' => "https://www.klaviyo.com/lists/list/{$list_id}"
			);
			
			if (!empty($subscription_results['error_details'])) {
				$response_data['subscription_errors'] = $subscription_results['error_details'];
			}
			
			wp_send_json_success($response_data);
			
		} catch (Exception $e) {
			error_log('Klaviyo Sync Exception: ' . $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}
	
	/**
	 * Safely bulk subscribe profiles with proper error handling for existing subscriptions
	 * This method handles edge cases where profiles already exist with different consent dates
	 * 
	 * @param array $emails Array of email addresses
	 * @param string $list_id The list ID to subscribe to
	 * @return array Results
	 */
	private function safe_bulk_subscribe($emails, $list_id) {
		$unique_emails = array_unique($emails);
		$total_emails = count($unique_emails);
		$subscribed = 0;
		$already_subscribed = 0;
		$errors = 0;
		$error_details = array();
		
		error_log('Klaviyo Sync Debug - Starting safe bulk subscription of ' . $total_emails . ' emails');
		
		// Process in smaller batches to handle errors better
		$batch_size = 100;
		
		for ($i = 0; $i < $total_emails; $i += $batch_size) {
			$email_batch = array_slice($unique_emails, $i, $batch_size);
			$batch_number = floor($i / $batch_size) + 1;
			
			error_log('Klaviyo Sync Debug - Processing subscription batch ' . $batch_number . ' with ' . count($email_batch) . ' emails');
			
			$batch_result = $this->subscribe_email_batch($email_batch, $list_id);
			
			$subscribed += $batch_result['subscribed'];
			$already_subscribed += $batch_result['already_subscribed'];
			$errors += $batch_result['errors'];
			
			if (!empty($batch_result['error_details'])) {
				$error_details = array_merge($error_details, $batch_result['error_details']);
			}
			
			// Add delay between batches
			if ($i + $batch_size < $total_emails) {
				sleep(1);
			}
		}
		
		return array(
			'subscribed' => $subscribed,
			'already_subscribed' => $already_subscribed,
			'errors' => $errors,
			'error_details' => array_slice($error_details, 0, 10) // Limit error details for response
		);
	}
	
	/**
	 * Subscribe a batch of emails with individual error handling
	 * 
	 * @param array $emails Array of email addresses for this batch
	 * @param string $list_id The list ID to subscribe to
	 * @return array Results for this batch
	 */
	private function subscribe_email_batch($emails, $list_id) {
		$subscribed = 0;
		$already_subscribed = 0;
		$errors = 0;
		$error_details = array();
		
		// Try the bulk approach first (fastest if it works)
		$bulk_result = $this->try_bulk_subscribe($emails, $list_id);
		
		if ($bulk_result['success']) {
			return array(
				'subscribed' => count($emails),
				'already_subscribed' => 0,
				'errors' => 0,
				'error_details' => array()
			);
		}
		
		// If bulk fails, fall back to individual processing
		error_log('Klaviyo Sync Debug - Bulk subscription failed, falling back to individual processing');
		
		foreach ($emails as $email) {
			$individual_result = $this->subscribe_individual_email($email, $list_id);
			
			switch ($individual_result['status']) {
				case 'subscribed':
					$subscribed++;
					break;
				case 'already_subscribed':
					$already_subscribed++;
					break;
				case 'error':
					$errors++;
					if (!empty($individual_result['error'])) {
						$error_details[] = $email . ': ' . $individual_result['error'];
					}
					break;
			}
			
			// Small delay between individual requests
			usleep(100000); // 100ms
		}
		
		return array(
			'subscribed' => $subscribed,
			'already_subscribed' => $already_subscribed,
			'errors' => $errors,
			'error_details' => $error_details
		);
	}
	
	/**
	 * Try bulk subscription approach
	 * 
	 * @param array $emails Array of email addresses
	 * @param string $list_id The list ID to subscribe to
	 * @return array Result with success flag
	 */
	private function try_bulk_subscribe($emails, $list_id) {
		// Use a very old consented_at date to minimize conflicts
		$consented_at = date('c', strtotime('2010-01-01 00:00:00'));
		
		$subscriptions_data = array();
		foreach ($emails as $email) {
			$subscriptions_data[] = array(
				'type' => 'profile',
				'attributes' => array(
					'email' => $email,
					'subscriptions' => array(
						'email' => array(
							'marketing' => array(
								'consent' => 'SUBSCRIBED',
								'consented_at' => $consented_at
							)
						)
					)
				)
			);
		}
		
		$body = array(
			'data' => array(
				'type' => 'profile-subscription-bulk-create-job',
				'attributes' => array(
					'historical_import' => true,
					'profiles' => array(
						'data' => $subscriptions_data
					)
				),
				'relationships' => array(
					'list' => array(
						'data' => array(
							'type' => 'list',
							'id' => $list_id
						)
					)
				)
			)
		);
		
		$result = $this->make_api_request('profile-subscription-bulk-create-jobs/', 'POST', $body);
		
		if (is_wp_error($result)) {
			error_log('Klaviyo bulk subscribe attempt failed: ' . $result->get_error_message());
			return array('success' => false, 'error' => $result->get_error_message());
		}
		
		return array('success' => true);
	}
	
	/**
	 * Subscribe an individual email with comprehensive error handling
	 * 
	 * @param string $email Email address
	 * @param string $list_id The list ID to subscribe to
	 * @return array Result with status and any error
	 */
	private function subscribe_individual_email($email, $list_id) {
		// First, try to get the profile's current subscription status
		$profile_info = $this->get_detailed_profile_info($email);
		
		if ($profile_info === false) {
			// Profile doesn't exist, safe to subscribe with historical import
			return $this->create_new_subscription($email, $list_id);
		}
		
		// Profile exists, check current subscription status
		$current_status = $this->extract_subscription_status($profile_info);
		
		switch ($current_status['status']) {
			case 'never_subscribed':
				// Safe to subscribe
				return $this->create_new_subscription($email, $list_id);
				
			case 'subscribed':
				// Already subscribed, just add to list if not already there
				return $this->add_to_list_only($email, $list_id);
				
			case 'unsubscribed':
				// Previously unsubscribed - don't re-subscribe to respect their choice
				return array(
					'status' => 'already_subscribed', // Count as "handled" but don't re-subscribe
					'note' => 'Previously unsubscribed, skipped to respect consent'
				);
				
			default:
				// Unknown status - likely a newly created profile that hasn't propagated yet
				// Treat as new profile and subscribe with historical import
				return $this->create_new_subscription($email, $list_id);
		}
	}
	
	/**
	 * Get detailed profile information including subscription history
	 * 
	 * @param string $email Email address
	 * @return array|false Profile data or false if not found
	 */
	private function get_detailed_profile_info($email) {
		$encoded_email = urlencode($email);
		$result = $this->make_api_request("profiles/?filter=equals(email,\"{$encoded_email}\")&fields[profile]=email,subscriptions");
		
		if (is_wp_error($result) || empty($result['data'])) {
			return false;
		}
		
		return $result['data'][0];
	}
	
	/**
	 * Extract subscription status from profile data
	 * 
	 * @param array $profile_data Profile data from API
	 * @return array Status information
	 */
	private function extract_subscription_status($profile_data) {
		if (!isset($profile_data['attributes']['subscriptions']['email']['marketing'])) {
			return array('status' => 'never_subscribed');
		}
		
		$marketing = $profile_data['attributes']['subscriptions']['email']['marketing'];
		
		$consent = isset($marketing['consent']) ? $marketing['consent'] : 'NEVER_SUBSCRIBED';
		$consented_at = isset($marketing['consented_at']) ? $marketing['consented_at'] : null;
		
		switch ($consent) {
			case 'SUBSCRIBED':
				return array(
					'status' => 'subscribed',
					'consented_at' => $consented_at
				);
			case 'UNSUBSCRIBED':
				return array(
					'status' => 'unsubscribed',
					'consented_at' => $consented_at
				);
			default:
				return array('status' => 'never_subscribed');
		}
	}
	
	/**
	 * Create a new subscription for a profile
	 * 
	 * @param string $email Email address
	 * @param string $list_id List ID
	 * @return array Result
	 */
	private function create_new_subscription($email, $list_id) {
		$consented_at = date('c', strtotime('2010-01-01 00:00:00'));
		
		$body = array(
			'data' => array(
				'type' => 'profile-subscription-bulk-create-job',
				'attributes' => array(
					'historical_import' => true, // Bypasses double opt-in emails
					'profiles' => array(
						'data' => array(
							array(
								'type' => 'profile',
								'attributes' => array(
									'email' => $email,
									'subscriptions' => array(
										'email' => array(
											'marketing' => array(
												'consent' => 'SUBSCRIBED',
												'consented_at' => $consented_at // Required when historical_import is true
											)
										)
									)
								)
							)
						)
					)
				),
				'relationships' => array(
					'list' => array(
						'data' => array(
							'type' => 'list',
							'id' => $list_id
						)
					)
				)
			)
		);
		
		$result = $this->make_api_request('profile-subscription-bulk-create-jobs/', 'POST', $body);
		
		if (is_wp_error($result)) {
			return array(
				'status' => 'error',
				'error' => $result->get_error_message()
			);
		}
		
		return array('status' => 'subscribed');
	}
	
	/**
	 * Add profile to list without changing subscription status
	 * 
	 * @param string $email Email address  
	 * @param string $list_id List ID
	 * @return array Result
	 */
	private function add_to_list_only($email, $list_id) {
		// Use the relationships endpoint to add profile to list
		$body = array(
			'data' => array(
				array(
					'type' => 'profile',
					'attributes' => array(
						'email' => $email
					)
				)
			)
		);
		
		$result = $this->make_api_request("lists/{$list_id}/relationships/profiles/", 'POST', $body);
		
		if (is_wp_error($result)) {
			return array(
				'status' => 'error',
				'error' => $result->get_error_message()
			);
		}
		
		return array('status' => 'already_subscribed');
	}
	
	/**
	 * Bulk import profiles using Klaviyo's bulk import API
	 * 
	 * @param array $profiles Array of profile data
	 * @return array|WP_Error Results or error
	 */
	private function bulk_import_profiles($profiles) {
		$total_profiles = count($profiles);
		$processed = 0;
		$errors = 0;
		$batch_size = 1000; // Klaviyo's bulk import supports up to 10,000, but we'll use smaller batches
		
		error_log('Klaviyo Sync Debug - Starting bulk import of ' . $total_profiles . ' profiles');
		
		for ($i = 0; $i < $total_profiles; $i += $batch_size) {
			$batch = array_slice($profiles, $i, $batch_size);
			$batch_number = floor($i / $batch_size) + 1;
			
			error_log('Klaviyo Sync Debug - Processing bulk import batch ' . $batch_number . ' with ' . count($batch) . ' profiles');
			
			// Prepare the batch data according to Klaviyo's bulk import API specification
			$batch_data = array();
			foreach ($batch as $profile_data) {
				$batch_data[] = array(
					'type' => 'profile',
					'attributes' => $profile_data
				);
			}
			
			$body = array(
				'data' => array(
					'type' => 'profile-bulk-import-job',
					'attributes' => array(
						'profiles' => array(
							'data' => $batch_data
						)
					)
				)
			);
			
			error_log('Klaviyo Sync Debug - Sending bulk import request for batch ' . $batch_number);
			
			$result = $this->make_api_request('profile-bulk-import-jobs/', 'POST', $body);
			
			if (is_wp_error($result)) {
				error_log('Klaviyo bulk import error for batch ' . $batch_number . ': ' . $result->get_error_message());
				$errors += count($batch);
			} else {
				error_log('Klaviyo Sync Debug - Successfully submitted bulk import job for batch ' . $batch_number);
				$processed += count($batch);
				
				// Optional: You could store the job ID and check status later
				if (isset($result['data']['id'])) {
					error_log('Klaviyo Sync Debug - Bulk import job ID: ' . $result['data']['id']);
				}
			}
			
			// Add delay between batches to avoid rate limiting
			if ($i + $batch_size < $total_profiles) {
				sleep(1); // 1 second delay between batches
			}
		}
		
		return array(
			'processed' => $processed,
			'errors' => $errors
		);
	}
	
	/**
	 * Clean SQL query for sync to handle escaped quotes
	 * 
	 * @param string $sql The SQL query to clean
	 * @return string Cleaned SQL query
	 */
	private function clean_sql_for_sync($sql) {
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
		
		// Additional cleaning for AJAX transmission issues
		$sql = wp_unslash($sql);
		
		return $sql;
	}
	
	/**
	 * Remove pagination from SQL query to get all records
	 * 
	 * @param string $sql SQL query
	 * @return string SQL query without LIMIT clause
	 */
	private function remove_pagination_from_sql($sql) {
		// Remove LIMIT clauses - both formats
		$sql = preg_replace('/\s+LIMIT\s+\d+(?:\s*,\s*\d+)?$/is', '', $sql);
		$sql = preg_replace('/\s+LIMIT\s+\d+\s+OFFSET\s+\d+$/is', '', $sql);
		
		// Trim any trailing whitespace
		$sql = trim($sql);
		
		return $sql;
	}
}