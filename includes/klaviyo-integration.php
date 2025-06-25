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
	 * Get available Klaviyo profile fields
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
		
		// Cache the results for 2 hours (custom fields change less frequently than lists)
		set_transient($cache_key, $fields, 2 * HOUR_IN_SECONDS);
		update_option($cache_key . '_timestamp', time());
		
		wp_send_json_success(array(
			'fields' => $fields,
			'cached' => false
		));
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
	 * Sync data to Klaviyo
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
		
		// Debug logging
		error_log('Klaviyo Sync Debug - List ID: ' . $list_id);
		error_log('Klaviyo Sync Debug - Field Mapping: ' . print_r($field_mapping, true));
		error_log('Klaviyo Sync Debug - SQL Query: ' . $sql_query);
		
		try {
			// Get database connection
			$wpdb = csd_db_connection();
			
			// Execute the query to get data
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
			error_log('Klaviyo Sync Debug - First result keys: ' . print_r(array_keys($results[0]), true));
			error_log('Klaviyo Sync Debug - Field mapping received: ' . print_r($field_mapping, true));
			
			// The field mapping should already contain the actual column names since we fixed the JavaScript
			// But let's add some validation to make sure
			$validated_field_mapping = array();
			$available_columns = array_keys($results[0]);
			
			foreach ($field_mapping as $column_name => $klaviyo_field) {
				if (!empty($klaviyo_field)) {
					if (in_array($column_name, $available_columns)) {
						$validated_field_mapping[$column_name] = $klaviyo_field;
					} else {
						error_log('Klaviyo Sync Debug - Column not found in results: ' . $column_name);
					}
				}
			}
			
			error_log('Klaviyo Sync Debug - Validated Field Mapping: ' . print_r($validated_field_mapping, true));
			
			if (empty($validated_field_mapping)) {
				wp_send_json_error(array('message' => __('No valid field mappings found. Please check your column mappings.', 'csd-manager')));
				return;
			}
			
			// Process results in batches
			$batch_size = 100;
			$total_records = count($results);
			$processed = 0;
			$errors = 0;
			$skipped = 0;
			$validation_errors = array();
			
			for ($i = 0; $i < $total_records; $i += $batch_size) {
				$batch = array_slice($results, $i, $batch_size);
				$profiles = array();
				
				foreach ($batch as $row_index => $row) {
					$profile_data = array();
					$has_email = false;
					
					error_log('Klaviyo Sync Debug - Processing row ' . ($i + $row_index) . ': ' . print_r(array_slice($row, 0, 5, true), true));
					
					foreach ($validated_field_mapping as $csv_field => $klaviyo_field) {
						if (!empty($klaviyo_field)) {
							// Check if the CSV field exists in the row
							if (!isset($row[$csv_field])) {
								error_log('Klaviyo Sync Debug - CSV field not found in row: ' . $csv_field . ' (available: ' . implode(', ', array_keys($row)) . ')');
								continue;
							}
							
							$value = $row[$csv_field];
							
							// Skip completely empty values but allow 0
							if ($value === null || $value === '') {
								continue;
							}
							
							// Handle email field specially
							if ($klaviyo_field === 'email') {
								if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
									// Additional check for placeholder emails
									if (strpos($value, '@placeholder') === false) {
										$has_email = true;
										$profile_data['email'] = $value;
									} else {
										$validation_errors[] = "Row " . ($i + $row_index) . ": Placeholder email '" . $value . "'";
										continue 2; // Skip this entire row
									}
								} else {
									$validation_errors[] = "Row " . ($i + $row_index) . ": Invalid email '" . $value . "'";
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
					
					// Only add profiles that have a valid email
					if ($has_email && !empty($profile_data)) {
						$profiles[] = $profile_data; // Don't wrap in type/attributes structure here
						error_log('Klaviyo Sync Debug - Added profile to batch: ' . print_r($profile_data, true));
					} else {
						$skipped++;
						error_log('Klaviyo Sync Debug - Skipped row ' . ($i + $row_index) . ' - has_email: ' . ($has_email ? 'true' : 'false') . ', profile_data: ' . print_r($profile_data, true));
					}
				}
				
				error_log('Klaviyo Sync Debug - Batch ' . ($i / $batch_size + 1) . ' has ' . count($profiles) . ' profiles to sync');
				
				if (!empty($profiles)) {
					// Create profiles first, then add them to the list
					$created_profiles = array();
					
					foreach ($profiles as $profile_data) {
						// Create or update profile using the correct endpoint
						$body = array(
							'data' => array(
								'type' => 'profile',
								'attributes' => $profile_data
							)
						);
						
						$result = $this->make_api_request('profiles/', 'POST', $body);
						
						if (is_wp_error($result)) {
							error_log('Klaviyo profile creation error: ' . $result->get_error_message());
							$errors++;
						} else {
							if (isset($result['data']['id'])) {
								$created_profiles[] = array(
									'type' => 'profile',
									'id' => $result['data']['id']
								);
							}
						}
					}
					
					// Now add the created profiles to the list
					if (!empty($created_profiles)) {
						$list_body = array(
							'data' => $created_profiles
						);
						
						error_log('Klaviyo Sync Debug - Adding profiles to list: ' . print_r($list_body, true));
						
						$list_result = $this->make_api_request("lists/{$list_id}/relationships/profiles/", 'POST', $list_body);
						
						if (is_wp_error($list_result)) {
							error_log('Klaviyo list addition error: ' . $list_result->get_error_message());
							$errors += count($created_profiles);
						} else {
							$processed += count($created_profiles);
							error_log('Klaviyo Sync Debug - Successfully added ' . count($created_profiles) . ' profiles to list');
						}
					}
				}
			}
			
			// Prepare response message
			$message_parts = array();
			$message_parts[] = sprintf(__('%d records processed', 'csd-manager'), $processed);
			
			if ($errors > 0) {
				$message_parts[] = sprintf(__('%d errors', 'csd-manager'), $errors);
			}
			
			if ($skipped > 0) {
				$message_parts[] = sprintf(__('%d skipped (no valid email)', 'csd-manager'), $skipped);
			}
			
			$final_message = __('Sync completed! ', 'csd-manager') . implode(', ', $message_parts) . '.';
			
			wp_send_json_success(array(
				'message' => $final_message,
				'processed' => $processed,
				'errors' => $errors,
				'skipped' => $skipped,
				'total_records' => $total_records,
				'validation_errors' => array_slice($validation_errors, 0, 10), // Show first 10 validation errors
				'list_url' => "https://www.klaviyo.com/lists/list/{$list_id}"
			));
			
		} catch (Exception $e) {
			error_log('Klaviyo Sync Exception: ' . $e->getMessage());
			wp_send_json_error(array('message' => $e->getMessage()));
		}
	}
	
	/**
	 * Create mapping from display names to actual column names
	 * 
	 * @param array $row Sample row from query results
	 * @return array Mapping of display names to column names
	 */
	private function create_display_to_column_mapping($row) {
		$mapping = array();
		
		// Define mappings for known column patterns
		$column_mappings = array(
			'School Name' => 'schools_school_name',
			'School ID' => 'schools_id',
			'Address Line 1' => 'schools_street_address_line_1',
			'Address Line 2' => 'schools_street_address_line_2',
			'Address Line 3' => 'schools_street_address_line_3',
			'City' => 'schools_city',
			'State' => 'schools_state',
			'Zipcode' => 'schools_zipcode',
			'Zip Code' => 'schools_zipcode',
			'Country' => 'schools_country',
			'County' => 'schools_county',
			'School Divisions' => 'schools_school_divisions',
			'School Conferences' => 'schools_school_conferences',
			'School Level' => 'schools_school_level',
			'School Type' => 'schools_school_type',
			'School Enrollment' => 'schools_school_enrollment',
			'Estimated Enrollment' => 'schools_school_enrollment',
			'Nickname/Mascot' => 'schools_mascot',
			'Mascot' => 'schools_mascot',
			'School Colors' => 'schools_school_colors',
			'School Website' => 'schools_school_website',
			'Athletics Website' => 'schools_athletics_website',
			'Athletics Phone' => 'schools_athletics_phone',
			'Football Division' => 'schools_football_division',
			'Staff ID' => 'staff_id',
			'Full Name' => 'staff_full_name',
			'Staff Name' => 'staff_full_name',
			'Name' => 'staff_full_name',
			'Title' => 'staff_title',
			'Staff Title' => 'staff_title',
			'Sport/Department' => 'staff_sport_department',
			'Department' => 'staff_sport_department',
			'Email' => 'staff_email',
			'Staff Email' => 'staff_email',
			'Phone' => 'staff_phone',
			'Staff Phone' => 'staff_phone',
		);
		
		// Add mappings for actual column names found in the results
		foreach (array_keys($row) as $column_name) {
			// Direct mapping (actual column name to itself)
			$mapping[$column_name] = $column_name;
			
			// Convert column name to display format
			$display_name = $this->column_to_display_name($column_name);
			$mapping[$display_name] = $column_name;
		}
		
		// Add predefined mappings
		foreach ($column_mappings as $display_name => $column_name) {
			if (array_key_exists($column_name, $row)) {
				$mapping[$display_name] = $column_name;
			}
		}
		
		return $mapping;
	}
	
	/**
	 * Convert column name to display name
	 * 
	 * @param string $column_name Database column name
	 * @return string Human-readable display name
	 */
	private function column_to_display_name($column_name) {
		// Remove table prefixes and convert to display format
		$display_name = preg_replace('/^(schools|staff|school_staff)_/', '', $column_name);
		$display_name = str_replace('_', ' ', $display_name);
		$display_name = ucwords($display_name);
		
		// Handle special cases
		$special_cases = array(
			'Street Address Line 1' => 'Address Line 1',
			'Street Address Line 2' => 'Address Line 2', 
			'Street Address Line 3' => 'Address Line 3',
			'School Name' => 'School Name',
			'Full Name' => 'Full Name',
			'Sport Department' => 'Sport/Department',
		);
		
		return isset($special_cases[$display_name]) ? $special_cases[$display_name] : $display_name;
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
}