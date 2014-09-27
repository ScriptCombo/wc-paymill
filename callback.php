<?php
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) )
{
	
	function  paymill_api_callback()
	{	
		
		$order_id 	= (!empty($_REQUEST['order']) ? filter_var($_REQUEST['order'], FILTER_SANITIZE_NUMBER_INT) : '' ) ;
		
		if($order_id){
			
			global $woocommerce;
			$gateways = $woocommerce->payment_gateways->payment_gateways();
			
			if ( $_REQUEST['woo-api'] != 'callback_paymill' ){
				$logs = new WC_Logger();
				$logs->add( 'paymill', __('Invalid Callback URL','woocommerce')  );
				return;
			}
				
			if ( !isset($gateways['paymill']) ){
				$logs = new WC_Logger();
				$logs->add( 'paymill', __('Paymill plugin not enabled in woocommerce','woocommerce') );
				return;
			}
			
			if (!isset($_REQUEST['paymillToken'])){
				$logs = new WC_Logger();
				$logs->add( 'paymill', __( 'The Paymill Token was not generated correctly','woocommerce') );
				return;
			}
				
			try 
			{
				$paymill 	 = $gateways['paymill'];
				$order 		 = new WC_Order( $order_id );
				$token 		 = $_REQUEST['paymillToken'];
				$productinfo = get_bloginfo() ." Order #$order_id";
				$privateApiKey 	= $paymill->privateKey;
				$amount 		= ( $order->order_total * 100 );
				$description 	= get_bloginfo() . ' ' . $productinfo;
				$currency		= get_option('woocommerce_currency');
				
				$client = paymillRequest(
			        'clients/',
			        array(),
			        $privateApiKey
			    );
			
			    $payment = paymillRequest(
			        'payments/',
			        array(
			             'token'  => $token,
			             'client' => $client['id']
			        ),
			        $privateApiKey
			    );
			
			    $transaction = paymillRequest(
			        'transactions/',
			        array(
			             'amount'      => $amount,
			             'currency'    => $currency,
			             'client'      => $client['id'],
			             'payment'     => $payment['id'],
			             'description' => $description
			        ),
			        $privateApiKey
			    );
			    
			    
							
				$isStatusClosed = isset($transaction['status']) && $transaction['status'] == 'closed';

			    $isResponseCodeSuccess = isset($transaction['response_code']) && $transaction['response_code'] == 20000;
			
			    if ($isStatusClosed && $isResponseCodeSuccess) {
				    $order->payment_complete();
				    $order->add_order_note(
			            sprintf(
			                "%s Payment Completed with Transaction Id of '%s'",
			                $paymill->method_title,
			                $transaction['id']
			            )
			        );
			        
					$woocommerce->cart->empty_cart();
					
					if($paymill->returnUrl == '' || $paymill->returnUrl == 0 ){
						$redirect_url = $paymill->get_return_url( $order );
					}else{
						$redirect_url = get_permalink( $paymill->returnUrl );
					}
					
					wp_redirect( $redirect_url ); exit;
			        
			    } else {
			        
			    	$order->add_order_note(
			            sprintf(
			                "%s Payment Failed",
			                $paymill->method_title
			            )
			        );
			       
			        wc_add_notice(__( 'Transaction Error: Could not complete your payment' , 'woocommerce'), "error");
			        wp_redirect( $woocommerce->cart->get_checkout_url() ); exit;
			    }
			    
				
			} catch ( Exception $e ){	
				
				$trxnresponsemessage = $e->getMessage();
				$order->add_order_note(
			            sprintf(
			                "%s Payment Failed with message: '%s'",
			                $paymill->method_title,
			                $trxnresponsemessage
			            )
			        );
			        
				wc_add_notice(__( 'Transaction Error: Could not complete your payment' , 'woocommerce'), "error");
		  		wp_redirect( $woocommerce->cart->get_checkout_url() ); exit;
		  		
			}
		}
			
	}	
	
	add_action( 'init', 'paymill_api_callback' );
	
		/**
		 * Perform HTTP request to REST endpoint
		 *
		 * @param string $action
		 * @param array  $params
		 * @param string $privateApiKey
		 *
		 * @return array
		 */
		function paymillRequestApi($action = '', $params = array(), $privateApiKey)
		{
		    $curlOpts = array(
		        CURLOPT_URL            => "https://api.paymill.com/v2/" . $action,
		        CURLOPT_RETURNTRANSFER => true,
		        CURLOPT_CUSTOMREQUEST  => 'POST',
		        CURLOPT_USERAGENT      => 'Paymill-php/0.0.2',
		        CURLOPT_SSL_VERIFYPEER => true,
		        CURLOPT_CAINFO         => realpath(dirname(__FILE__)) . DIRECTORY_SEPARATOR . 'paymill.crt',
		    );
		    
		     
		
		    $curlOpts[CURLOPT_POSTFIELDS] = http_build_query($params, null, '&');
		    $curlOpts[CURLOPT_USERPWD] = $privateApiKey . ':';
		
		    $curl = curl_init();
		    curl_setopt_array($curl, $curlOpts);
		    $responseBody = curl_exec($curl);
		    $responseInfo = curl_getinfo($curl);
		    if ($responseBody === false) {
		        $responseBody = array('error' => curl_error($curl));
		    }
		    curl_close($curl);
		
		    if ('application/json' === $responseInfo['content_type']) {
		        $responseBody = json_decode($responseBody, true);
		    }
		
		    return array(
		        'header' => array(
		            'status' => $responseInfo['http_code'],
		            'reason' => null,
		        ),
		        'body'   => $responseBody
		    );
		}

		/**
		 * Perform API and handle exceptions
		 *
		 * @param        $action
		 * @param array  $params
		 * @param string $privateApiKey
		 *
		 * @return mixed
		 */
		function paymillRequest($action, $params = array(), $privateApiKey)
		{
		    if (!is_array($params)) {
		        $params = array();
		    }
		
		    $responseArray = paymillRequestApi($action, $params, $privateApiKey);
		    $httpStatusCode = $responseArray['header']['status'];
		    if ($httpStatusCode != 200) {
		        $errorMessage = 'Client returned HTTP status code ' . $httpStatusCode;
		        if (isset($responseArray['body']['error'])) {
		            $errorMessage = $responseArray['body']['error'];
		        }
		        $responseCode = '';
		        if (isset($responseArray['body']['data']['response_code'])) {
		            $responseCode = $responseArray['body']['data']['response_code'];
		        }
		
		        return array("data" => array(
		            "error"            => $errorMessage,
		            "response_code"    => $responseCode,
		            "http_status_code" => $httpStatusCode
		        ));
		    }
		
		    return $responseArray['body']['data'];
		}
	
}