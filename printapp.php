<?php
 /**
 * Plugin Name: 		Print.App
 * Plugin URI: 			https://print.app
 * Description: 		Empower your customers to personalize products like Business Cards, Photo Prints, T-Shirts, Mugs, Banners, Canvases, etc. on your store before purchase
 * Version: 			1.3.6
 * Requires at least: 	3.8
 * Requires PHP:      	5.2.4
 * Author:            	36 Studios, Inc.
 * Author URI:        	https://print.app
 * Tested up to: 6.3
 * WC requires at least: 3.0.0
 * WC tested up to: 8.0.2
 *
 * @package PrintApp
 * @category Core
 * @author PrintApp
 */

require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

class PrintApp {
 	// IMPLEMENT CLASS CONSTRUCTOR
	public function __construct() {
		$this->define_constants();
		$this->init_hooks();
	}

	// DECLARE SOME CONSTANTS USED FOR THIS PLUGIN
	private function define_constants() {
		global $wpdb;
		define('print_app_TABLE_NAME', $wpdb->prefix . 'print_app_projects' );
		define('print_app_RUN_BASE_URL', 'https://run.print.app');
		define('print_app_DESIGN_SELECT_JS', plugin_dir_url( __FILE__ ) . 'js/design-select.js');
		define('print_app_SESSION_ID', 'print_app_sessId');
	}

	// INITIALIZE HOOKS FOR THIS PLUGIN
	public function init_hooks() {
		
		add_action( 'before_woocommerce_init', function() {
			if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
				\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			}
		} );

		if ($this->request_type('frontend')) {
			add_action('init', array($this,'register_session'), 0 );
			add_action('woocommerce_before_add_to_cart_button', array($this, 'print_app_add_edit_button'));
			add_filter('woocommerce_add_cart_item_data', array($this, 'print_app_add_cart_item_data'), 10, 2);
			add_filter('woocommerce_cart_item_thumbnail', array($this, 'print_app_cart_thumbnail'), 70, 2);
			add_filter('woocommerce_checkout_create_order_line_item', array($this, 'print_app_add_order_item_meta'), 70, 2);
			add_action('wp_ajax_nopriv_print_app_save_project', array($this, 'print_app_save_project'));
			add_action('wp_ajax_print_app_save_project', array($this, 'print_app_save_project'));
			add_action('wp_ajax_nopriv_print_app_reset_project', array($this, 'print_app_reset_project'));
			add_action('wp_ajax_print_app_reset_project', array($this, 'print_app_reset_project'));
		}
		else if ($this->request_type('admin')) {
			add_action('admin_menu', array($this, 'print_app_actions'));
			add_action('admin_init', array($this, 'print_app_settings_api_init'));
			add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'print_app_add_settings_link'));
			add_filter('woocommerce_order_item_get_formatted_meta_data', array($this, 'print_app_remove_preview_line_item_from_meta'), 10, 2);
			add_filter('woocommerce_product_data_tabs', array($this, 'print_app_add_design_selection_tab'), 10, 1 );
			add_action('woocommerce_product_data_panels', array($this,'print_app_assign_design_form') );
			add_filter('woocommerce_order_item_display_meta_key', array($this,'print_app_filter_wc_order_item_display_meta_key'), 20, 3 );
			add_filter('woocommerce_order_item_display_meta_value', array($this,'print_app_filter_wc_order_item_display_meta_value'), 20, 3 );
		}
		
	}

	// REMOVE PREVIEW ON LINE META ITEMS IN ORDER DETAILS ON ADMIN
	public function print_app_remove_preview_line_item_from_meta($formatted_meta, $item) {
		foreach ($formatted_meta as $key => $meta) {
	        if(is_int(strpos($meta->value, 'pa-preview-image'))) {
	            unset($formatted_meta[$key]);
        	}
		}
		return $formatted_meta;
	}
	
	// FORMAT THE META DATA ON ORDER TO DISPLAY DOWNLOAD LINKS
	public function print_app_filter_wc_order_item_display_meta_value( $display_value, $meta ) {
		if ( $meta->key === '_pda_w2p_set_option' ) {
			$pda_data = json_decode($display_value, true);
			
			$auth_key = get_option('print_app_secret_key');

			if (!empty($pda_data['projectId'])) {
				$hash = md5( $pda_data['projectId'] . $auth_key );
				$post_fix = $pda_data['projectId'] . '!' . $hash;

				return '
					<div class="print_app_order_meta" style="display: flex;">
						<div onclick="pda_show_preview(this)" data-project-id="' . $pda_data["projectId"] . '" class="pda_show_preview" style="margin-right: 10px;">
							<img src="' . $pda_data['previews'][0]['url'] . '" width="180px"/>
							<div>
								<svg xmlns="http://www.w3.org/2000/svg" class="icon-tabler icon-tabler-search" width="22px" height="22px" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" fill="none" stroke-linecap="round" stroke-linejoin="round" data-v-09078359="">   <path stroke="none" d="M0 0h24v24H0z" fill="none"></path>   <circle cx="10" cy="10" r="7"></circle>   <line x1="21" y1="21" x2="15" y2="15"></line> </svg>
							</div>
						</div>
						<div>
							<a target="_blank" href="https://pdf.print.app/'. $post_fix .'">Download PDF</a><br/>
							<a target="_blank" href="https://png.print.app/'. $post_fix .'">Download PNG</a><br/>
							<a target="_blank" href="https://jpg.print.app/'. $post_fix .'">Download JPEG</a><br/>
							<a target="_blank" href="https://tiff.print.app/'. $post_fix .'">Download TIFF</a><br/>
							<a target="_blank" href="https://admin.print.app/projects/'. $pda_data['projectId'] .'">Modify Project</a>
						</div>
					</div>';
			} else if (!empty($pda_data['form'])) {
				print_r($pda_data);
			}
		}
		return $display_value;  
	}

	// CHANGE THE META LABEL TO SOMETHING MORE HUMAN READABLE
	public function print_app_filter_wc_order_item_display_meta_key( $display_key, $meta, $item ) {
	    if ( $meta->key === '_pda_w2p_set_option' ) 
	    	$display_key = "PrintApp";
	    return $display_key;    
	}

	// ADD PROJECT DATA AS META DATA ON ORDER ITEMS
	public function print_app_add_order_item_meta($order_item, $cart_item_key) {
		$cart_item = WC()->cart->get_cart_item( $cart_item_key );

		if	( !empty($cart_item['_pda_w2p_set_option']) ) {
			$order_item->add_meta_data( '_pda_w2p_set_option', $cart_item['_pda_w2p_set_option'], true );
			$order_item->add_meta_data( 'Preview', '<img class="pa-preview-image" style="width:120px;margin-left;10px" src="'.json_decode($cart_item['_pda_w2p_set_option'],true)['previews'][0]['url'].'">', true );
		}
		if	( gettype($cart_item) == 'object' && isset($cart_item->legacy_values) && isset($cart_item->legacy_values['_pda_w2p_set_option']) )
			$order_item->add_meta_data( '_pda_w2p_set_option', $cart_item->legacy_values['_pda_w2p_set_option'], true );
	}

	// SHOW PROJECT PREVIEW IN THUMBNAIL
	public function print_app_cart_thumbnail($img, $val) {
		if (!empty($val['_pda_w2p_set_option'])) {
			$itm = $val['_pda_w2p_set_option'];
			$itm = json_decode($itm, true);
			$img = '<img src="' . $itm['previews'][0]['url'] . '" >';
		}
		return $img;
	}

	// ADD PROJECT DATA AS META TO CART ITEMS
	public function print_app_add_cart_item_data($cart_item_meta, $product_id) {
		$_projects = $this->getProjectData($product_id);
		if (isset($_projects) && isset($_projects[$product_id])) {
			$cart_item_meta['_pda_w2p_set_option'] = $_projects[$product_id];
			$this->clearProjects($product_id);
		}
		return $cart_item_meta;
	}

	// REMOVE PROJECTS FROM SESSION
	private function clearProjects($productId) {
		global $wpdb;
		$sessId = isset($_COOKIE['print_app_sessId']) ? sanitize_text_field($_COOKIE['print_app_sessId']) : false;
		if (!$sessId) return false;
		$wpdb->delete(print_app_TABLE_NAME, array('id' => $sessId, 'product_id' => $productId) );
	}

	// CREATE UNIQUE SESSION ID FOR EACH CUSTOMER
	public function register_session() {
		if(!isset($_COOKIE['print_app_sessId']))
			setcookie('print_app_sessId', uniqid('pda_w2p_', true), time()+60*60*24*30, '/');
	}

	// SAVE PROJECT DATA ON CLIENT SERVER FOR PAGE REFRESH AND NOT YET ADDED TO CART.
	public function print_app_reset_project() {
		global $wpdb;
		$productId	= sanitize_text_field($_POST['product_id']);
		if (isset($_COOKIE['print_app_sessId'])) {
			$sessId = sanitize_text_field($_COOKIE['print_app_sessId']);
			// Delete old
			$wpdb->delete(print_app_TABLE_NAME, array('id' => $sessId, 'product_id' => $productId) );
			wp_die(json_encode(array('success'=>true))); 
			return;
		}
		wp_die(json_encode(array('success'=>false)));
	}

	//  A CUSTOM FUNCTION TO SANITIZE OUR PRINT.APP VALUE OBJECT
	private function custom_sanitize_pp_object($object, $allowedKeys) {
		$cleanItem = array();
		foreach($object as $key => $value) {
			if (in_array($key, $allowedKeys)) {
				if ($key == 'previews' && is_array($value)) {
					$cleanItem[$key] = array();
					foreach ($value as $prevKey => $prev)
						if(is_array($prev)) {
							$cleanItem[$key][$prevKey] = array();
							$cleanItem[$key][$prevKey]['url'] = sanitize_url($prev['url']);
						}
				}
				elseif ($key == 'isAdmin')
					$cleanItem[$key] = rest_sanitize_boolean($value);
				else
					$cleanItem[$key] = sanitize_text_field($value);
			}
		}
		return $cleanItem;
	}

	// SAVE PROJECT DATA ON CLIENT SERVER FOR PAGE REFRESH AND NOT YET ADDED TO CART.
	public function print_app_save_project() {
		global $wpdb;
		if (!isset($_POST ['value'])) return;
		$value		= json_decode(stripslashes(html_entity_decode($_POST['value'])), true);
		if (!$value) return wp_die(json_encode(array('success'=>false)));
		$productId	= sanitize_text_field($_POST['product_id']);
		if (isset($_COOKIE['print_app_sessId'])) {
			$sessId = sanitize_text_field($_COOKIE['print_app_sessId']);
			// Delete old
			$wpdb->delete(print_app_TABLE_NAME, array('id' => $sessId, 'product_id' => $productId) );
			// Insert new
			$date = date('Y-m-d H:i:s', time()+60*60*24*30);
			$table_name = print_app_TABLE_NAME;
			$sql = $wpdb->prepare("INSERT INTO `{$table_name}` VALUES (%s, %d, %s, %s)", $sessId, $productId, json_encode($value), $date);
			$exec = dbDelta($sql);
			wp_die(json_encode(array('success'=>true))); 
		}
		wp_die(json_encode(array('success'=>false))); 
	}

	// DISTINGUISH WHERE THE REQUEST IS FROM, FRONT OR BACK
	private function request_type( $type ) {
		switch ( $type ) {
			case 'admin' :
				return is_admin();
			case 'ajax' :
				return defined( 'DOING_AJAX' );
			case 'cron' :
				return defined( 'DOING_CRON' );
			case 'frontend' :
				return ( ! is_admin() || defined( 'DOING_AJAX' ) ) && ! defined( 'DOING_CRON' );
		}
	}

	// GET SAVED PROJECT DATA BY SESSION ID STORED IN COOKIE
	private function getProjectData($product_id) {
		global $wpdb;
		$_projects	= array();
		$sessId 	= sanitize_text_field($_COOKIE[print_app_SESSION_ID]);
		$tableName	= print_app_TABLE_NAME;
		$sql		= $wpdb->prepare("SELECT `value` FROM `$tableName` WHERE `product_id` = %d AND `id` = %s;", $product_id, $sessId);
		
		$results = $wpdb->get_results($sql);
		if (count($results))
			$_projects[$product_id] = $results[0]->value;
		
		return $_projects;
	}

	// DISPLAY THE EDIT BUTTON
	public function print_app_add_edit_button() {
		global $post;
		$pda_domain_key = get_option('print_app_domain_key');

		// LOAD SCRIPTS
		$lang_code = substr(get_bloginfo('language'), 0, 2);
		if (!$lang_code) $lang_code = 'en';
		$run_url = print_app_RUN_BASE_URL . '/' . $pda_domain_key . '/' . $post->ID . '/wp?lang=' . $lang_code;
		wp_enqueue_script('print_app_class', $run_url, '', '', true);
		
		$userData = "";
		if ( is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			$customer = WC()->customer;
		
			$fname = esc_js($customer->get_billing_first_name());
			$lname = esc_js($customer->get_billing_last_name());
		
			$address = $customer->get_billing_address_1() . "<br>";
			if ( !empty($customer->get_billing_address_2()) ) {
				$address .= $customer->get_billing_address_2() . "<br>";
			}
			$address .= $customer->get_billing_city() . " " . $customer->get_billing_postcode() . "<br>";
			if ( !empty($customer->get_billing_state()) ) {
				$address .= $customer->get_billing_state() . "<br>";
			}
			$address .= $customer->get_billing_country();
			$address = esc_js($address);
		
			$userData = "{
					email: '" . esc_js($current_user->user_email) . "',
					name: '{$fname} {$lname}',
					firstname: '{$fname}',
					lastname: '{$lname}',
					phone: '" . esc_js($customer->get_billing_phone()) . "',
					address: '{$address}'.split('<br>').join('\\n')
				}";
		}

		$projects = $this->getProjectData($post->ID);
		if (count($projects)) $projectData = json_decode($projects[$post->ID], true);

		$pda_project_id = '';
		$pda_mode		= 'new-project';
		$pda_previews	= '';
		if (isset($projectData)) {
			$pda_project_id = $projectData['projectId'];
			$pda_mode		= isset($projectData['mode']) ? $projectData['mode'] : 'edit-project';
			$pda_previews	= isset($projectData['previews']) ? json_encode($projectData['previews']) : '';
		}
		
		$pda_uid		= get_current_user_id() === 0 ? 'guest' : get_current_user_id();

		wp_localize_script('print_app_class', 'printAppParams', array(
			'wp_ajax_url' => admin_url( 'admin-ajax.php' ),
			'langCode' => substr(get_bloginfo('language'), 0, 2),
			'previews' => $pda_previews,
			'mode' => $pda_mode,
			'projectId' => $pda_project_id,
			'pluginRoot' => site_url() . '/print_app',
			'product' => array(
				'id' => $post->ID,
				'name' => $post->post_name
			),
			'userId' => $pda_uid,
			'launchData' => $userData,
		));

		echo '<div id="pa-buttons"></div>';
	}

	// SHOW DESIGN SELECTION FORM
	public function print_app_assign_design_form() {
		global $post;
		$pda_domain_key = get_option('print_app_domain_key');

		echo '<div id="print_app_tab" style="padding:1rem" class="panel woocommerce_options_panel hidden"></div>';

		wp_enqueue_script('print_app_design_tree', print_app_DESIGN_SELECT_JS);
		wp_localize_script('print_app_design_tree', 'pa_admin_values', array( 
			'api_key' => $pda_domain_key,
			'product_id' => $post->ID,
			'product_title' => $post->post_title,
		));
	}

	// ADD DESIGN SELECTION TAB
	public function print_app_add_design_selection_tab($default_tabs) {
		$default_tabs['print_app_tab'] = array(
	        'label'   =>  __( 'PrintApp', 'domain' ),
	        'target'  =>  'print_app_tab',
	        'priority' => 60,
	        'class'   => array()
	    );
	    return $default_tabs;
	}

	// PLUGIN LINKS AFTER DEACTIVATE/ACTIVATE
	public function print_app_add_settings_link($links) {
		$url = add_query_arg('page', 'printapp', admin_url('admin.php'));
		$settings_link = '<a href="' . esc_url($url) . '" rel="noopener">Settings</a>';
		array_unshift($links, $settings_link);
		
		return $links;
	}

	// DISPLAY THE SETTINGS PAGE FOR THIS PLUGIN
	public function print_app_admin_page() {
		if (!class_exists('WooCommerce')) {
			echo ('<h3>This plugin depends on WooCommerce plugin. Kindly install <a target="_blank" href="https://wordpress.org/plugins/woocommerce/">WooCommerce here!</a></h3>');
			exit();
		}
		settings_errors();

		echo '<form method="post" action="options.php"><div class="wrap">';
			settings_fields('print_app');
			do_settings_sections('print_app');
			submit_button();
		echo '</div></form>';
	}

	// STORE API KEY AND SECRET KEY
	public function print_app_settings_api_init() {
		add_settings_section('print_app_settings_section', 'PrintApp Settings', array($this, 'print_app_create_settings'), 'print_app');
		add_settings_field('print_app_domain_key', 'Domain Key', array($this, 'print_app_domain_key'), 'print_app', 'print_app_settings_section', array());
		add_settings_field('print_app_secret_key', 'Auth Key', array($this, 'print_app_secret_key'), 'print_app', 'print_app_settings_section', array());
		register_setting('print_app', 'print_app_domain_key');
		register_setting('print_app', 'print_app_secret_key');
	}

	// DISPLAY DOMAIN KEY INPUT
	public function print_app_domain_key() {
		echo  '<input class="regular-text" id="print_app_domain_key" name="print_app_domain_key" type="text" value="' . esc_html( get_option('print_app_domain_key') ) . '" />';
	}

	// DISPLAY SECRET KEY INPUT
	public function print_app_secret_key() {
		echo '<input class="regular-text" id="print_app_secret_key" name="print_app_secret_key" type="text" value="' . esc_html( get_option('print_app_secret_key') ) . '" />';
	}

	// DISPLAY SHOW ON CATEGORY SWITCH
	public function print_app_cat_customize() {
		echo '<input class="regular-text" id="print_app_cat_customize" name="print_app_cat_customize" type="checkbox" '. esc_html( ( get_option('print_app_cat_customize') == 'on' ? 'checked' : '' )  ) . ' />' ;
	}

	// DISPLAY MESSAGE: HOW TO GET NEW DOMAIN KEY
	public function print_app_create_settings() {
		echo '<p>' . __("You can generate your api and secret keys from the <a target=\"_blank\" href=\"https://admin.print.app/domains\">PrintApp domains page</a>", "PrintApp") . '</p>' ;
	}

	// ADD PLUGIN MENU TO ADMIN
	public function print_app_actions() {
		$menu_icon = plugin_dir_url( __FILE__ ) .'assets/icon.svg';
		add_menu_page('PrintApp Settings', 'PrintApp', 'manage_options', 'printapp', array($this, 'print_app_admin_page'), $menu_icon);
	}
}
 
global $PrintApp;
$PrintApp = new PrintApp();
 

// PERFORM SOME ACTIONS UPON INSTALL
function print_app_install() {
	// CREATE DATABASE TABLE TO STORE PROJECTS IN
	global $wpdb;
	
	$table_name 		= $wpdb->prefix . 'print_app_projects';
	$charset_collate	= $wpdb->get_charset_collate();
	
	$sql = "CREATE TABLE IF NOT EXISTS $table_name (
	  id varchar(55) NOT NULL ,
	  product_id mediumint(9) NOT NULL,
	  value TEXT  NOT NULL,
	  expires TIMESTAMP
	) $charset_collate;";
	
	$exec = dbDelta( $sql );
}
register_activation_hook( __FILE__, 'print_app_install');

 