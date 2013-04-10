<?php
/*
Plugin Name: WooCommerce Wishlist Member Integration
Description: Integrates Wishlist Member with WooCommerce, A Wishlist Membership plugin is required. Go to <a href="http://member.wishlistproducts.com/wlp.php?af=a99823522" target="blank" />www.wishlistproducts.com</a> to get your copy.
Plugin URI: http://woothemes.com/woocommerce
Author: Radomir van Dalen
Author URI: http://www.webshop112.nl
Version: 2.0
Requires at least: 3.1
Tested up to: WordPress 3.5 WooCommerce 1.6.6 + WooCommerce 2.0

  License: GNU General Public License v3.0
	License URI: http://www.gnu.org/licenses/gpl-3.0.html
*/

/**
 * Required functions
 */
if ( ! function_exists( 'woothemes_queue_update' ) )
	require_once( 'woo-includes/woo-functions.php' );

/**
 * Plugin updates
 */
woothemes_queue_update( plugin_basename( __FILE__ ), 'c98d3776d16cc97a10bd8814154103e6', '18653' );

if (is_woocommerce_active()) {

	register_activation_hook( __FILE__, 'InitWCWLValues' );

	load_plugin_textdomain('wc_wishlist_members', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );

	function InitWCWLValues() {
		add_option('wlapikey', 'ABCXYZ');
		add_option('wlposturl', 'http://domain.com/');
		add_option('wlskuprefix', 'WL#');
		add_option('wlmailfrom', 'support@yourdomain.com');
		add_option('wlmailfromname', 'Support');
		add_option('wlmailsubject', 'Activate your membership');
		add_option('wlmailbody', "Hello [first_name], \r\n and welcome as a new member. Below you'll find your login details./r/n[login_details]");

	}

	if ( class_exists('WC_WishlistMember') ) return;

	class WC_WishlistMember {
		var $id;
		var $wlposturl;
		var $wlapikey;
		var $wlskuprefix;
		var $wlmailfrom;
		var $wlmailfromname;
		var $wlmailsubject;
		var $wlmailbody;
		var $settings_tabs;
		var $current_tab;
		var $fields = array();

		/**
		 * __construct function.
		 *
		 * @access public
		 */
		public function __construct() {
			$this->current_tab = ( isset($_GET['tab'] ) ) ? $_GET['tab'] : 'general';

			$this->settings_tabs = array(
				'wishlist' => __( 'Wishlist Members', 'wc_wishlist_members' )
			);

			// Load in the new settings tabs.
			add_action( 'woocommerce_settings_tabs', array( &$this, 'add_tab' ), 10 );

			// Run these actions when generating the settings tabs.
			foreach ( $this->settings_tabs as $name => $label ) {
				add_action( 'woocommerce_settings_tabs_' . $name, array( &$this, 'settings_tab_action' ), 10 );
				add_action( 'woocommerce_update_options_' . $name, array( &$this, 'save_settings' ), 10 );
			}

			// Add the settings fields to each tab.
			add_action( 'WC_WishlistMember_settings', array( &$this, 'add_settings_fields' ), 10 );

			// Add Wishlist Member Creation
			add_action('woocommerce_order_status_processing', array( &$this, 'process_orders_payment'));
			add_action('woocommerce_order_status_completed', array( &$this, 'process_orders_completed'));

			$this->wlposturl = get_option('wlposturl');
			$this->wlapikey = get_option('wlapikey');
			$this->wlskuprefix = get_option('wlskuprefix');
			$this->wlmailfrom = get_option('wlmailfrom');
			$this->wlmailfromname = get_option('wlmailfromname');
			$this->wlmailsubject = get_option('wlmailsubject');
			$this->wlmailbody = get_option('wlmailbody');
		}

		/**
		 * add_tab function.
		 *
		 * @access public
		 */
		function add_tab() {
			foreach ( $this->settings_tabs as $name => $label ) {
				$class = 'nav-tab';
				if ( $this->current_tab == $name ) $class .= ' nav-tab-active';
				echo '<a href="' . admin_url( 'admin.php?page=woocommerce&tab=' . $name ) . '" class="' . $class . '">' . $label . '</a>';
			}
		}

		/**
		 * settings_tab_action()
		 * Do this when viewing our custom settings tab(s). One function for all tabs.
		 */
		function settings_tab_action() {
			global $woocommerce_settings;

			// Determine the current tab in effect.
			$current_tab = $this->get_tab_in_view( current_filter(), 'woocommerce_settings_tabs_' );

			// Hook onto this from another function to keep things clean.
			do_action( 'WC_WishlistMember_settings' );

			// Display settings for this tab (make sure to add the settings to the tab).
			woocommerce_admin_fields( $woocommerce_settings[$current_tab] );

		}

		/**
		 * add_settings_fields()
		 * Add settings fields for each tab.
		 */
		function add_settings_fields() {
			global $woocommerce_settings;

			// Load the prepared form fields.
			$this->init_form_fields();

			if ( is_array( $this->fields ) )
				foreach ( $this->fields as $k => $v )
					$woocommerce_settings[$k] = $v;
		}

		/**
		 * get_tab_in_view()
		 * Get the tab current in view/processing.
		 */
		function get_tab_in_view( $current_filter, $filter_base ) {
			return str_replace( $filter_base, '', $current_filter );
		}

		/**
		 * init_form_fields function.
		 *
		 * @access public
		 */
		function init_form_fields() {
			$this->fields['wishlist'] = apply_filters('WC_WishlistMember_settings_fields',
				array(
					array( 'name' => __( 'Wishlist Members Configuration', 'wc_subscribe_to_newsletter' ),
						'type' => 'title',
						'desc' => '',
						'id' => 'wishlist_title'
					),
					array( 'name' => __( 'Post URL', 'wc_wishlist_members' ),
						'type' => 'text',
						'desc' => __( 'The POST URL for API communication. In general, Woocommerce website url, with traling slash.', 'wc_wishlist_members' ),
						'default' => __( 'http://yourblog.com/', 'wc_wishlist_members' ),
						'id' => 'wlposturl'
					),
					array( 'name' => __( 'API Key', 'wc_wishlist_members' ),
						'type' => 'text',
						'desc' => __( 'API key used for communicating with Wishlist Member. Usually found under WL Member - Settings - Miscellaneous', 'wc_wishlist_members' ),
						'default' => __( 'xyzxyzxyzxyz', 'wc_wishlist_members' ),
						'id' => 'wlapikey'
					),
					array( 'name' => __( 'SKU Prefix', 'wc_wishlist_members' ),
						'type' => 'text',
						'desc' => __( 'The SKU prefix which you want to use for Wishlist products (e.g. "WL-","WL","WL#")', 'wc_wishlist_members' ),
						'default' => __( 'WL#', 'wc_wishlist_members' ),
						'id' => 'wlskuprefix'
					),
					array( 'type' => 'sectionend', 'id' => 'wishlistend' ),

						array('name' => __( 'Mail Settings', 'wc_wishlist_members' ), 'type' => 'title', 'desc' => __('Change the settings below to configure confirmation mail format', 'wc_wishlist_members'), 'id' => 'mailfromstart' ),

						array(
							'name' => __( 'Mail From', 'wc_wishlist_members' ),
							'type' => 'text',
							'desc' => __( 'The email address from which email will be sent', 'wc_wishlist_members' ),
							'default' => __( 'support@yourdomain.com', 'wc_wishlist_members' ),
							'id' => 'wlmailfrom'
						),
						array(
							'name' => __( 'Mail From Name', 'wc_wishlist_members' ),
							'type' => 'text',
							'desc' => __( 'The name from which email will be sent', 'wc_wishlist_members' ),
							'default' => __( 'Support', 'wc_wishlist_members' ),
							'id' => 'wlmailfromname'
						),
						array(
							'name' => __( 'Mail Subject', 'wc_wishlist_members' ),
							'type' => 'text',
							'desc' => __( 'Subject of membership confirmation mail', 'wc_wishlist_members' ),
							'default' => __( 'Thank you for signing up!', 'wc_wishlist_members' ),
							'id' => 'wlmailsubject'
						),

						array(
							'name' => __( 'Mail Content', 'wc_wishlist_members' ),
							'type' => 'textarea',
							'desc' => __( 'Content of the confirmation email, be sure to include "[login_details]" to place the membership details.Other placeholders supported are: "[first_name]" and "[last_name]"', 'wc_wishlist_members' ),
							'default' => __( 'Hello [first_name], \r\n and welcome as a new member. Below you\'ll find your login details\r\n[login_details]', 'wc_wishlist_members' ),
							'id' => 'wlmailbody',
							'css' => 'width:500px; height: 150px;'
						),

						array( 'type' => 'sectionend', 'id' => 'mailfromend' )
				));
		}

		/**
		 * save_settings()
		 * Save settings in a single field in the database for each tab's fields (one field per tab).
		 */
		function save_settings() {
			global $woocommerce_settings;

			// Make sure our settings fields are recognised.
			$this->add_settings_fields();
			$current_tab = $this->get_tab_in_view( current_filter(), 'woocommerce_update_options_' );
			woocommerce_update_options( $woocommerce_settings[$current_tab] );

		}

		/**
		 * Admin Panel Options
		 * - Options for bits like 'title' and availability on a country-by-country basis
		 **/
		function admin_options() {
?>
				<h3><?php _e('Wishlist Members', 'wc_wishlist_members'); ?></h3>
				<p><?php _e( 'To integrate your WooCommerce products with Wishlist Members, for automatic membership creation', 'wc_wishlist_members' ); ?></p>

				<table class="form-table">
					<?php $this->generate_settings_html(); ?>
				</table><!--/.form-table-->
			<?php
		}

		/**
		 * process_orders_payment function.
		 *
		 * @access public
		 * @param mixed $order_id
		 */
		function process_orders_payment($order_id) {
			global $woocommerce;
			$order = new WC_Order( $order_id );

			// check if order already is processed by Wishlistmember
			$wlm_proces = get_post_meta( $order_id, 'wlm_processed', true);

			// if not, run processing the order
			if ($wlm_proces ="" || $wlm_proces !='1' ) {

				$items = unserialize($order->order_custom_fields["_order_items"][0]);
				$postURL = $this->wlposturl;
				$secretKey = $this->wlapikey;
				$trans_id = $order_id;
				$email =  $order->billing_email;
				$first = $order->billing_first_name;
				$last = $order->billing_last_name;

			// check if generated username excists, if so, add random number to new	username
				$numb = rand(1,30);
				$username = $first . $last;
				$new_user = $username . $numb;

					if ( username_exists( $username ) )
				$new_username = $new_user;
					else
				$new_username = $username;
				
			// put sku's of all ordered memberships into one array

				$skulevels = array();

					foreach ( $order->get_items() as $order_itm_sku ) {
						if ( function_exists( 'get_product' ) ) 
						$product = $order->get_product_from_item( $order_itm_sku );
						else 
						$product = new WC_Product( $order_itm_sku['id'] );						
						if(strpos($product->sku,$this->wlskuprefix)==0 && strpos($product->sku,$this->wlskuprefix)!==false){
							$sku = str_replace($this->wlskuprefix,'',$product->sku);
							$skulevels[] = $sku;
						}
					}
				
				// connect to WLM via API - 11010213 -> removed http API call to WLM in favour of internal calls
										
				// check if customer email already exists / customer already is a member
							
				$already_user_id = 0;
					if ( email_exists($email)) {
					$already_user_id = email_exists($email);
					}		
					if($already_user_id !=0){	
					
					// use internal call to WLM API to add levels to user
						
					$data=array('user_email' => $email, 'Levels' => $skulevels);
					$result = wlmapi_update_member($already_user_id, $data);
					$order->add_order_note( __("Existing user, added to new level(s)", 'wc_wishlist_members'));
					} else {
						// add new member
						$pwd = wp_generate_password();
						$data = array( 'user_login' => $new_username, 'user_email' => $email, 'user_pass' => $pwd, 'first_name' => $first, 'last_name' => $last, 'user_nicename' => $first . $last, 'display_name' => $first . $last, 'nickname' => $first . $last, 'Levels' => $skulevels, 'Sequential' => true, 'wpm_registration_ip' => $ipaddress );
						$response = wlmapi_add_member($data);
					// prepare membership details email

						$login_url = get_bloginfo('wpurl').'/wp-login.php';
						$message = "
								Username: ".$new_username."\n\r
								Password: ".$pwd."\n\r
								Login url: ".$login_url."\n\r
								";

						$wl_pholders = array('[first_name]', '[login_details]');
						$wl_subs   = array($first,$message);

						$m_subject = $this->wlmailsubject;
						$m_content = str_replace($wl_pholders,$wl_subs,$this->wlmailbody);
						$m_headers = 'From:' . $this->wlmailfromname;
						$m_headers .= ' <' . $this->wlmailfrom . '>';

					// send email with WC layout
					// woothemes.com/extension/follow-up-emails/
						$mailer     = $woocommerce->mailer();
						$wl_message = $mailer->wrap_message( $m_subject, $m_content );
						$mailer->send($email, $m_subject, $wl_message);

						$order->add_order_note( __("New user ", 'wc_wishlist_members'));
					}

				// add meta value to indicate WLM processing has taken place

				add_post_meta($order_id, 'wlm_processed', '1', true);
				$order->update_status('completed');
				} else {
				$order->add_order_note( __("Wishlist Member Processing already done.", 'wc_wishlist_members'));				}
			}

		/**
		 * process_orders_completed function.
		 * @access public
		 * @param mixed $order_id

			// if not, run processing the order
			if ($wlm_proces ="" || $wlm_proces !='1' ) {

				$items = unserialize($order->order_custom_fields["_order_items"][0]);
				$postURL = $this->wlposturl;
				$secretKey = $this->wlapikey;
				$trans_id = $order_id;
				$email =  $order->billing_email;
				$first = $order->billing_first_name;
				$last = $order->billing_last_name;

				// check if generated username excists, if so, add random number to new	username
				$numb = rand(1,30);
				$username = $first . $last;
				$new_user = $username . $numb;

					if ( username_exists( $username ) )
				$new_username = $new_user;
					else
				$new_username = $username;
				
				// put sku's of all ordered memberships into one array
				$skulevels = array();

					foreach ( $order->get_items() as $order_itm_sku ) {
						if ( function_exists( 'get_product' ) ) 
						$product = $order->get_product_from_item( $order_itm_sku );
						else 
						$product = new WC_Product( $order_itm_sku['id'] );							
						if(strpos($product->sku,$this->wlskuprefix)==0 && strpos($product->sku,$this->wlskuprefix)!==false){
							$sku = str_replace($this->wlskuprefix,'',$product->sku);
							$skulevels[] = $sku;
						}
					}
				
				// connect to WLM via API - 11010213 -> removed http API call to WLM in favour of internal calls
				
				// check if customer email already exists / customer already is a member
							
				$already_user_id = 0;
					if ( email_exists($email)) {
					$already_user_id = email_exists($email);
					}		
					if($already_user_id !=0){	

					// prepare membership details email

						$login_url = get_bloginfo('wpurl').'/wp-login.php';
						$message = "
								Gebruikersnaam: ".$new_username."\n\r
								Wachtwoord: ".$pwd."\n\r
								Inloggen op: ".$login_url."\n\r
								";

						$wl_pholders = array('[first_name]', '[login_details]');
						$wl_subs   = array($first,$message);

						$m_subject = $this->wlmailsubject;
						$m_content = str_replace($wl_pholders,$wl_subs,$this->wlmailbody);
						$m_headers = 'From:' . $this->wlmailfromname;
						$m_headers .= ' <' . $this->wlmailfrom . '>';

					// send email with WC layout
					// woothemes.com/extension/follow-up-emails/
						$mailer     = $woocommerce->mailer();
						$wl_message    = $mailer->wrap_message( $m_subject, $m_content );
						$mailer->send($email, $m_subject, $wl_message);
												
						$order->add_order_note( __("New user ", 'wc_wishlist_members'));
					}

				// add meta value to indicate WLM processing has taken place

				add_post_meta($order_id, 'wlm_processed', '1', true);
				} else {
	            $order->add_order_note( __("Wishlist Member Processing done.", 'wc_wishlist_members'));
				}
			}
}
	$GLOBALS['WC_WishlistMember'] = new WC_WishlistMember();

}
