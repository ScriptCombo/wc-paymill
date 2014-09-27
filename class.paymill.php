<?php
/*
 * Plugin Name: WooCommerce Paymill Payment Gateway
 * Plugin URI: http://www.scriptcombo.com/
 * Description: Paymill Payment Gateway for WooCommerce Extension
 * Version: 1.0.0
 * Author: Scripted++
 * Author URI: http://www.scriptcombo.com/
 *  
 */

include plugin_dir_path(__FILE__) . 'callback.php';

function woocommerce_api_paymill_init(){
	
	if(!class_exists('WC_Payment_Gateway')) return;
		
	class WC_API_Paymill extends WC_Payment_Gateway{
		public function __construct()
		{	
			$this->id 				= 'paymill';
			$this->method_title 	= 'Paymill';
			$this->has_fields 		= false; 
			
			$this->init_form_fields();
			$this->init_settings();
			
			$this->title 			= $this->settings[ 'title' ];
			$this->description 		= $this->settings[ 'description' ];
			$this->mode 			= $this->settings[ 'mode' ];
			$this->privateKey 		= $this->settings[ 'privateKey' ];
			$this->publicKey 		= $this->settings[ 'publicKey' ];
			$this->returnUrl 		= $this->settings[ 'returnUrl' ];
			$this->debugMode  		= $this->settings[ 'debugMode' ];
			$this->notify_url   	= add_query_arg( 'woo-api', 'callback_paymill', home_url( '/' ));
			$this->msg['message'] 	= '';
			$this->msg['class'] 	= '';
			
			if ( $this->debugMode == 'on' ){
				$this->logs = new WC_Logger();
			}
			
			add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( &$this, 'process_admin_options' ) );
		
			add_action( 'woocommerce_receipt_paymill', array( &$this, 'receipt_page' ) );
		}
			
		public function init_form_fields()
		{
			$this->form_fields = array(
					'enabled' 			=> array(
	                    'title' 		=> __( 'Enable/Disable', 'woocommerce' ),
	                    'type' 			=> 'checkbox',
	                    'label' 		=> __( 'Enable Paymill Payment Module.', 'woocommerce' ),
	                    'default' 		=> 'no'
	                    ),
	                'title' => array(
	                    'title' 		=> __( 'Title:', 'woocommerce' ),
	                    'type'			=> 'text',
	                    'description' 	=> __( 'This controls the title which the user sees during checkout.', 'woocommerce' ),
	                    'default' 		=> __( 'Paymill', 'woocommerce' )
	                    ),
	                'description' => array(
	                    'title' 		=> __( 'Description:', 'woocommerce' ),
	                    'type' 			=> 'textarea',
	                    'description' 	=> __( 'This controls the description which the user sees during checkout.', 'woocommerce' ),
	                    'default' 		=> __( 'Pay with your credit card via Paymill.', 'woocommerce' )
	                    ),
	                'mode' 	=> array(
	                    'title' 		=> __( 'Environment', 'woocommerce' ),
	                    'type' 			=> 'select',
	                    'description' 	=> '',
	       				'options'     	=> array(
					        's'			=> __( 'Sandbox', 'woocommerce' ),
	                    	'p'			=> __( 'Production', 'woocommerce' )
						)),
					'privateKey' => array(
	                    'title' 		=> __( 'Private Key', 'woocommerce' ),
	                    'type' 			=> 'text',
	                    'description' 	=> __( 'Your Private Key (Live or Test).', 'woocommerce' ),
	                    'required' 		=> true,
	                    'desc_tip'      => true,
	                    ),		
	                'publicKey' => array(
	                    'title' 		=> __( 'Public Key', 'woocommerce' ),
	                    'type' 			=> 'text',
	                    'description' 	=> __( 'Your Public Key  (Live or Test).', 'woocommerce' ),
	                    'required' 		=> true,
	                    'desc_tip'      => true,
	                    ),
	                'returnUrl' => array(
	                    'title' 		=> __( 'Return Url' , 'woocommerce' ),
	                    'type' 			=> 'select',
	                    'desc_tip'      => true,
	                    'options' 		=> $this->getPages( 'Select Page' ),
	                    'description' 	=> __( 'URL of success page', 'woocommerce' )
	                    ),    
	                'debugMode' => array(
	                    'title' 		=> __( 'Debug Mode', 'woocommerce' ),
	                    'type' 			=> 'select',
	                    'description' 	=> '',
	       				'options'     	=> array(
					        'off' 		=> __( 'Off', 'woocommerce' ),
					        'on' 		=> __( 'On', 'woocommerce' )
	                    ))           
			);	
		}
		
		public function process_payment( $order_id )
		{
			global $woocommerce;
			global $wp_rewrite;
	
			$order 		 = new WC_Order( $order_id );
				
			if ( version_compare( WOOCOMMERCE_VERSION, '2.1', '>=' ) ){
	
				if ( $wp_rewrite->permalink_structure == '' ){
					$checkout_url = $woocommerce->cart->get_checkout_url().'&order-pay='.$order_id.'&key='.$order->order_key;
				} else {
					$checkout_url = $woocommerce->cart->get_checkout_url().'/order-pay/'.$order_id.'?key='.$order->order_key;
				}
	
				return array(
						'result' => 'success',
						'redirect' => $checkout_url
				);
					
			} else {
				return array(
						'result' 	=> 'success', 
						'redirect' 	=> add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('pay'))))
				);
			}
			
		}		
		
		public function admin_options()
		{	
			if($this->mode == 'p' && get_option('woocommerce_force_ssl_checkout') == 'no' && $this->enabled == 'yes'){
				echo '<div class="error"><p>'.sprintf(__('%s Sandbox testing is disabled and can performe live transactions but the <a href="%s">force SSL option</a> is disabled; your checkout is not secure! Please enable SSL and ensure your server has a valid SSL certificate.', 'woothemes'), $this->method_title, admin_url('admin.php?page=wc-settings&tab=checkout')).'</p></div>';	
			}
			
			$currencies = array("EUR", "GBP");
			
			if(	!in_array(get_option('woocommerce_currency'), $currencies )){
				echo '<div class="error"><p>'.__(	'In order to support non-EUR and non-GBP currencies, you must contact Paymill support ( support@paymill.com. )  or call +49 89 189 045 300.', 'woocommerce'	).'</p></div>';
			}
			
			echo '<h3>'.__(	'Paymill Payment Gateway', 'woocommerce'	).'</h3>';
			echo '<div class="updated">';
			echo '<p>'.__(	'Do you like this plugin?', 'woocommerce' ).' <a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=9CQRJBSQPPJHE">'.__('Please reward it with a little donation.', 'woocommerce' ).'</a> </p>';
			echo '<p><a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=9CQRJBSQPPJHE"><img src="https://www.paypal.com/en_US/i/btn/btn_donateCC_LG.gif" /> </a> </p>';
			echo '</div>';
			echo '<p>'.__(	'Merchant Details.', 'woocommerce' ).'</p>';
			echo '<table class="form-table">';
			$this->generate_settings_html();
			echo '</table>';
				
		}
		
		public function receipt_page( $order ){
	
			if ( $this->mode == 's' ){
				echo '<p>';
				echo wpautop( wptexturize(  __('TEST MODE/SANDBOX ENABLED', 'woocommerce') )). ' ';
				echo '<p>';
			}
					
			echo '<p>'.__( 
				sprintf(
		                "Please click the button below to pay via %s",
		                $this->method_title
			            ), 'woocommerce' ).'</p>';
			            
			echo $this->set_payment_form( $order );		            
			            
		}
		
		public function set_payment_form( $order_id ){
			
			global $woocommerce;
			$order 			= new WC_Order( $order_id );
			$productinfo 	= "Order #$order_id";
			$post_url 		= add_query_arg( 'order', $order_id , $this->notify_url );
			
			$html = '';
			$html .='<form action="'. $post_url .'" method="post">';
	    	$html .='<script ';
	        $html .='src="https://button.paymill.com/v1/"';
	        $html .='id="button"';
	        $html .='data-label="Pay with CreditCard"';
	        $html .='data-title="'.get_bloginfo() .'"';
	        $html .='data-description="'. $productinfo .'"';
	        $html .='data-submit-button="Pay '. $order->order_total .' '. get_option('woocommerce_currency') .' "';
	        $html .='data-amount="'.( $order->order_total * 100 ).'"';
	        $html .='data-currency="'.get_option('woocommerce_currency').'"';
	        $html .='data-public-key="'.$this->publicKey.'"';
	        $html .='data-elv="false"'; 
	        $html .='data-lang="en-GB"';
	        $html .='data-width="180"';
	        $html .='data-height="45"';
	        $html .='data-inline="true"';
	        //$html .='data-logo="logo.png"';
	        $html .='>';
	    	$html .='</script>';
			$html .='</form>';		
			
			return $html;
		}
		
		public function payment_fields()
		{	
			if( $this->description ){
				echo wpautop( wptexturize( $this->description ) );
				
			}
		}
	
		public function showMessage( $content )
		{
			$html  = '';
			$html .= '<div class="box '.$this->msg['class'].'-box">';
			$html .= $this->msg['message'];
			$html .= '</div>';
			$html .= $content;
				
			return $html;
				
		}
	
		public function getPages( $title = false, $indent = true )
		{
			$wp_pages = get_pages( 'sort_column=menu_order' );
			$page_list = array();
			if ( $title ) $page_list[] = $title;
			foreach ( $wp_pages as $page ) {
				$prefix = '';
				if ( $indent ) {
					$has_parent = $page->post_parent;
					while( $has_parent ) {
						$prefix .=  ' - ';
						$next_page = get_page( $has_parent );
						$has_parent = $next_page->post_parent;
					}
				}
				$page_list[$page->ID] = $prefix . $page->post_title;
			}
			return $page_list;
		}
	}
	
	function woocommerce_add_api_paymill( $methods ) {
		$methods[] = 'WC_API_Paymill';
		return $methods;
	}
	
	add_filter( 'woocommerce_payment_gateways', 'woocommerce_add_api_paymill' );
	
	function paymill_action_links( $links ) {
			return array_merge( array(
				'<a href="' . esc_url( 'https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=9CQRJBSQPPJHE'  ) . '">' . __( 'Donation', 'woocommerce' ) . '</a>'
			), $links );
		}
		
	add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'paymill_action_links' );

}

add_action( 'plugins_loaded', 'woocommerce_api_paymill_init', 0 );


