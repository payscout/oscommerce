<?php
/*
  $Id$

  osCommerce, Open Source E-Commerce Solutions
  http://www.oscommerce.com

  Copyright (c) 2014 osCommerce

  Released under the GNU General Public License
*/

  class payscout {
    var $code, $title, $description, $enabled;

    function payscout() {
      global $HTTP_GET_VARS, $PHP_SELF, $order;

      $this->signature = 'payscoutinc|payscout|2.0|2.1';
      $this->api_version = '2.0';

      $this->code = 'payscout';
      $this->title = MODULE_PAYMENT_PAYSCOUT_TEXT_TITLE;
      $this->public_title = MODULE_PAYMENT_PAYSCOUT_TEXT_PUBLIC_TITLE;
      $this->description = MODULE_PAYMENT_PAYSCOUT_TEXT_DESCRIPTION;
      $this->sort_order = defined('MODULE_PAYMENT_PAYSCOUT_SORT_ORDER') ? MODULE_PAYMENT_PAYSCOUT_SORT_ORDER : 0;
      $this->enabled = defined('MODULE_PAYMENT_PAYSCOUT_STATUS') && (MODULE_PAYMENT_PAYSCOUT_STATUS == 'True') ? true : false;
      $this->order_status = defined('MODULE_PAYMENT_PAYSCOUT_ORDER_STATUS_ID') && ((int)MODULE_PAYMENT_PAYSCOUT_ORDER_STATUS_ID > 0) ? (int)MODULE_PAYMENT_PAYSCOUT_ORDER_STATUS_ID : 0;

      if ( defined('MODULE_PAYMENT_PAYSCOUT_STATUS') ) {
        if ( (MODULE_PAYMENT_PAYSCOUT_TRANSACTION_SERVER == 'Test') || (MODULE_PAYMENT_PAYSCOUT_TRANSACTION_MODE == 'Test') ) {
          $this->title .= ' [Test]';
          $this->public_title .= ' (' . $this->code . '; Test)';
        }

        $this->description .= $this->getTestLinkInfo();
      }

      if ( !function_exists('curl_init') ) {
        $this->description = '<div class="secWarning">' . MODULE_PAYMENT_PAYSCOUT_ERROR_ADMIN_CURL . '</div>' . $this->description;

        $this->enabled = false;
      }

      if ( $this->enabled === true ) {
        if ( !tep_not_null(MODULE_PAYMENT_PAYSCOUT_CLIENT_ID) || !tep_not_null(MODULE_PAYMENT_PAYSCOUT_CLIENT_USERNAME)  || !tep_not_null(MODULE_PAYMENT_PAYSCOUT_CLIENT_PASSWORD)  || !tep_not_null(MODULE_PAYMENT_PAYSCOUT_CLIENT_TOKEN) ) {
          $this->description = '<div class="secWarning">' . MODULE_PAYMENT_PAYSCOUT_ERROR_ADMIN_CONFIGURATION . '</div>' . $this->description;

          $this->enabled = false;
        }
      }

      if ( $this->enabled === true ) {
        if ( isset($order) && is_object($order) ) {
          $this->update_status();
        }
      }

      if ( defined('FILENAME_MODULES') && ($PHP_SELF == FILENAME_MODULES) && isset($HTTP_GET_VARS['action']) && ($HTTP_GET_VARS['action'] == 'install') && isset($HTTP_GET_VARS['subaction']) && ($HTTP_GET_VARS['subaction'] == 'conntest') ) {
        
        exit;
      }
    }

    function update_status() {
      global $order;

      if ( ($this->enabled == true) && ((int)MODULE_PAYMENT_PAYSCOUT_ZONE > 0) ) {
        $check_flag = false;
        $check_query = tep_db_query("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . MODULE_PAYMENT_PAYSCOUT_ZONE . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");
        while ($check = tep_db_fetch_array($check_query)) {
          if ($check['zone_id'] < 1) {
            $check_flag = true;
            break;
          } elseif ($check['zone_id'] == $order->billing['zone_id']) {
            $check_flag = true;
            break;
          }
        }

        if ($check_flag == false) {
          $this->enabled = false;
        }
      }
    }

    function javascript_validation() {
      return false;
    }

    function selection() {
      return array('id' => $this->code,
                   'module' => $this->public_title);
    }

    function pre_confirmation_check() {
      return false;
    }

    function confirmation() {
      global $order;

      for ($i=1; $i<13; $i++) {
        $expires_month[] = array('id' => sprintf('%02d', $i), 'text' => sprintf('%02d', $i));
      }

      $today = getdate(); 
      for ($i=$today['year']; $i < $today['year']+10; $i++) {
        $expires_year[] = array('id' => strftime('%y',mktime(0,0,0,1,1,$i)), 'text' => strftime('%Y',mktime(0,0,0,1,1,$i)));
      }

      $confirmation = array('fields' => array(array('title' => MODULE_PAYMENT_PAYSCOUT_CREDIT_CARD_OWNER_FIRSTNAME,
                                                    'field' => tep_draw_input_field('cc_owner_firstname', $order->billing['firstname'])),
                                              array('title' => MODULE_PAYMENT_PAYSCOUT_OWNER_LASTNAME,
                                                    'field' => tep_draw_input_field('cc_owner_lastname', $order->billing['lastname'])),
											array('title' => MODULE_PAYMENT_PAYSCOUT_OWNER_DOB,
                                                    'field' => tep_draw_input_field('cc_owner_dob', NULL, ' size="10" placeholder="MM/DD/YYYY" maxlength="10"', $order->billing['dob'])),
                                              array('title' => MODULE_PAYMENT_PAYSCOUT_CREDIT_CARD_NUMBER,
                                                    'field' => tep_draw_input_field('cc_number_nh-dns')),
                                              array('title' => MODULE_PAYMENT_PAYSCOUT_CREDIT_CARD_EXPIRES,
                                                    'field' => tep_draw_pull_down_menu('cc_expires_month', $expires_month) . '&nbsp;' . tep_draw_pull_down_menu('cc_expires_year', $expires_year)),
                                              array('title' => MODULE_PAYMENT_PAYSCOUT_CREDIT_CARD_CCV,
                                                    'field' => tep_draw_input_field('cc_ccv_nh-dns', '', 'size="5" maxlength="4"'))));

      return $confirmation;
    }

    function process_button() {
      return false;
    }

    function before_process() {
      global $HTTP_POST_VARS, $customer_id, $order, $sendto, $currency, $response;

      $params = array('client_username' => MODULE_PAYMENT_PAYSCOUT_CLIENT_USERNAME,
                      'client_password' => MODULE_PAYMENT_PAYSCOUT_CLIENT_PASSWORD,
                      'client_token' => MODULE_PAYMENT_PAYSCOUT_CLIENT_TOKEN,                      
                      'currency' => strtoupper(substr($currency, 0, 3)),
					  'ip_address' => $_SERVER['REMOTE_ADDR'],
					  'processing_type'     => 'DEBIT',
					  'billing_date_of_birth' => preg_replace('/[^0-9]/', '', date('Ymd',strtotime($_POST['cc_owner_dob']))),
                      'initial_amount' => substr($this->format_raw($order->info['total']), 0, 15),                     
                      'account_number' => substr(preg_replace('/[^0-9]/', '', $HTTP_POST_VARS['cc_number_nh-dns']), 0, 22),
                      'expiration_month' => $HTTP_POST_VARS['cc_expires_month'],
					  'expiration_year'	=>  2000 + (int)$HTTP_POST_VARS['cc_expires_year'],
                      'cvv2' => substr($HTTP_POST_VARS['cc_ccv_nh-dns'], 0, 4),                      
                      'billing_first_name' => html_entity_decode(substr($order->billing['firstname'], 0, 50), ENT_QUOTES, 'UTF-8'),
                      'billing_last_name' => html_entity_decode(substr($order->billing['lastname'], 0, 50), ENT_QUOTES, 'UTF-8'),                     
                      'billing_address_line_1' => html_entity_decode(substr($order->billing['street_address'], 0, 60), ENT_QUOTES, 'UTF-8'),
                      'billing_city' => html_entity_decode(substr($order->billing['city'], 0, 40), ENT_QUOTES, 'UTF-8'),
                      'billing_state' => html_entity_decode(substr($order->billing['state'], 0, 40), ENT_QUOTES, 'UTF-8'),
                      'billing_postal_code' => html_entity_decode(substr($order->billing['postcode'], 0, 20), ENT_QUOTES, 'UTF-8'),
                      'billing_country' => html_entity_decode(substr($order->billing['country']['title'], 0, 60), ENT_QUOTES, 'UTF-8'),
                      'billing_phone_number' => substr($order->customer['telephone'], 0, 25),
                      'billing_email_address' => substr($order->customer['email_address'], 0, 255)
                      );

      if (is_numeric($sendto) && ($sendto > 0)) {
        $params['shipping_first_name'] = substr($order->delivery['firstname'], 0, 50);
        $params['shipping_last_name'] = substr($order->delivery['lastname'], 0, 50);        
        $params['shipping_address_line_1'] = substr($order->delivery['street_address'], 0, 60);
        $params['shipping_city'] = substr($order->delivery['city'], 0, 40);
        $params['shipping_state'] = substr($order->delivery['state'], 0, 40);
        $params['shipping_postal_code'] = substr($order->delivery['postcode'], 0, 20);
        $params['shipping_country'] = substr($order->delivery['country']['title'], 0, 60);
      }

      if (MODULE_PAYMENT_AUTHORIZENET_CC_AIM_TRANSACTION_MODE == 'Test') {
        $params['x_test_request'] = 'TRUE';
      }

      $tax_value = 0;

      foreach ($order->info['tax_groups'] as $key => $value) {
        if ($value > 0) {
          //$tax_value += $this->format_raw($value);
        }
      }

      $post_string = '';

      foreach ($params as $key => $value) {
        $post_string .= $key . '=' . urlencode(trim($value)) . '&';
      }

      $post_string = substr($post_string, 0, -1);

      if ( MODULE_PAYMENT_AUTHORIZENET_CC_AIM_TRANSACTION_SERVER == 'Live' ) {
        $gateway_url = 'https://gateway.payscout.com/api/process';
      } else {
        $gateway_url = 'https://mystaging.paymentecommerce.com/api/process';
      }

      $transaction_response = $this->sendTransactionToGateway($gateway_url, $post_string);

      $response = json_decode($transaction_response);	  
	  
      $error = false;

      if ( isset($response->result_code) &&  $response->result_code == '00') {  
         
            $order->info['order_status'] = MODULE_PAYMENT_PAYSCOUT_REVIEW_ORDER_STATUS_ID;
          
        
      } elseif (isset($response->result_code) &&  ($response->result_code != '00' || $response->result_code == '') ) {
		  
		 $error_message =  base64_encode($response->raw_message);
		 if(isset($response->message) && $response->message!="")
		 {
			$error_message =  base64_encode($response->message);
		}
	
		  
        $error = 'declined';
      } else {
        $error_message = base64_encode('Payscout CURL ERROR: Empty Gateway Response');
		$error = 'declined';
      }
      
      if ($error == 'declined') {      
        tep_redirect(tep_href_link(FILENAME_CHECKOUT_PAYMENT, 'payment_error=' . $this->code . '&error=' . $error. '&errmsg=' . $error_message, 'SSL'));
      }
    }

    function after_process() {
      global $response, $order, $insert_id;

      $status = array(); 
	  
	  $res_text =  $response->message;
	  
	  if(trim($res_text)  == '')
	  {
		$res_text = $response->raw_message;	  
	  }
	  
      
      $status[] = 'Response: ' . tep_db_prepare_input($res_text) . ' (' . tep_db_prepare_input($response->result_code) . ')';
      $status[] = 'Transaction ID: ' . tep_db_prepare_input($response->transaction_id);

    
      $sql_data_array = array('orders_id' => $insert_id,
                              'orders_status_id' => MODULE_PAYMENT_PAYSCOUT_ORDER_STATUS_ID,
                              'date_added' => 'now()',
                              'customer_notified' => '0',
                              'comments' => implode("\n", $status));

      tep_db_perform(TABLE_ORDERS_STATUS_HISTORY, $sql_data_array);
    }

    function get_error() {
      global $HTTP_GET_VARS;

      $error_message = MODULE_PAYMENT_PAYSCOUT_ERROR_GENERAL;

      switch ($HTTP_GET_VARS['error']) {		  
        case 'declined':
          $error_message = base64_decode($HTTP_GET_VARS['errmsg']);
          break;
		  
		  default:
          $error_message = MODULE_PAYMENT_PAYSCOUT_ERROR_GENERAL;
          break;
      }

      $error = array('title' => MODULE_PAYMENT_PAYSCOUT_ERROR_TITLE,
                     'error' => $error_message);

      return $error;
    }

    function check() {
      if (!isset($this->_check)) {
        $check_query = tep_db_query("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_PAYSCOUT_STATUS'");
        $this->_check = tep_db_num_rows($check_query);
      }
      return $this->_check;
    }

    function install($parameter = null) {
      $params = $this->getParams();

      if (isset($parameter)) {
        if (isset($params[$parameter])) {
          $params = array($parameter => $params[$parameter]);
        } else {
          $params = array();
        }
      }

      foreach ($params as $key => $data) {
        $sql_data_array = array('configuration_title' => $data['title'],
                                'configuration_key' => $key,
                                'configuration_value' => (isset($data['value']) ? $data['value'] : ''),
                                'configuration_description' => $data['desc'],
                                'configuration_group_id' => '6',
                                'sort_order' => '0',
                                'date_added' => 'now()');

        if (isset($data['set_func'])) {
          $sql_data_array['set_function'] = $data['set_func'];
        }

        if (isset($data['use_func'])) {
          $sql_data_array['use_function'] = $data['use_func'];
        }

        tep_db_perform(TABLE_CONFIGURATION, $sql_data_array);
      }
    }

    function remove() {
      tep_db_query("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    function keys() {
      $keys = array_keys($this->getParams());

      if ($this->check()) {
        foreach ($keys as $key) {
          if (!defined($key)) {
            $this->install($key);
          }
        }
      }

      return $keys;
    }

    function getParams() {
      if (!defined('MODULE_PAYMENT_PAYSCOUT_TRANSACTION_ORDER_STATUS_ID')) {
        $check_query = tep_db_query("select orders_status_id from " . TABLE_ORDERS_STATUS . " where orders_status_name = 'Payscout [Transactions]' limit 1");

        if (tep_db_num_rows($check_query) < 1) {
          $status_query = tep_db_query("select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS);
          $status = tep_db_fetch_array($status_query);

          $status_id = $status['status_id']+1;

          $languages = tep_get_languages();

          foreach ($languages as $lang) {
            tep_db_query("insert into " . TABLE_ORDERS_STATUS . " (orders_status_id, language_id, orders_status_name) values ('" . $status_id . "', '" . $lang['id'] . "', 'Payscout [Transactions]')");
          }

          $flags_query = tep_db_query("describe " . TABLE_ORDERS_STATUS . " public_flag");
          if (tep_db_num_rows($flags_query) == 1) {
            tep_db_query("update " . TABLE_ORDERS_STATUS . " set public_flag = 0 and downloads_flag = 0 where orders_status_id = '" . $status_id . "'");
          }
        } else {
          $check = tep_db_fetch_array($check_query);

          $status_id = $check['orders_status_id'];
        }
      } else {
        $status_id = MODULE_PAYMENT_PAYSCOUT_TRANSACTION_ORDER_STATUS_ID;
      }

      $params = array('MODULE_PAYMENT_PAYSCOUT_STATUS' => array('title' => 'Enable Payscout Payment Method',
                                                                           'desc' => 'Do you want to accept card payment via Payscout payments Gateway?',
                                                                           'value' => 'True',
                                                                           'set_func' => 'tep_cfg_select_option(array(\'True\', \'False\'), '),                   
                      'MODULE_PAYMENT_PAYSCOUT_CLIENT_USERNAME' => array('title' => 'API USERNAME',
                                                                                    'desc' => 'The API Username used for the Payscout service'),
                      'MODULE_PAYMENT_PAYSCOUT_CLIENT_PASSWORD' => array('title' => 'API PASSWORD',
                                                                             'desc' => 'The API Password used for the payscout service'),
					   'MODULE_PAYMENT_PAYSCOUT_CLIENT_TOKEN' => array('title' => 'API TOKEN',
                                                                             'desc' => 'The API Token used for the payscout service'),
                     
                      'MODULE_PAYMENT_PAYSCOUT_ORDER_STATUS_ID' => array('title' => 'Set Order Status',
                                                                                    'desc' => 'Set the status of orders made with this payment module to this value',
                                                                                    'value' => '0',
                                                                                    'use_func' => 'tep_get_order_status_name',
                                                                                    'set_func' => 'tep_cfg_pull_down_order_statuses('),
                      'MODULE_PAYMENT_PAYSCOUT_REVIEW_ORDER_STATUS_ID' => array('title' => 'Review Order Status',
                                                                                           'desc' => 'Set the status of orders flagged as being under review to this value',
                                                                                           'value' => '0',
                                                                                           'use_func' => 'tep_get_order_status_name',
                                                                                           'set_func' => 'tep_cfg_pull_down_order_statuses('),
                      'MODULE_PAYMENT_PAYSCOUT_TRANSACTION_ORDER_STATUS_ID' => array('title' => 'Transaction Order Status',
                                                                                                'desc' => 'Include transaction information in this order status level',
                                                                                                'value' => $status_id,
                                                                                                'use_func' => 'tep_get_order_status_name',
                                                                                                'set_func' => 'tep_cfg_pull_down_order_statuses('),
                      'MODULE_PAYMENT_PAYSCOUT_ZONE' => array('title' => 'Payment Zone',
                                                                         'desc' => 'If a zone is selected, only enable this payment method for that zone.',
                                                                         'value' => '0',
                                                                         'set_func' => 'tep_cfg_pull_down_zone_classes(',
                                                                         'use_func' => 'tep_get_zone_class_title'),
                      'MODULE_PAYMENT_PAYSCOUT_TRANSACTION_SERVER' => array('title' => 'Transaction Server',
                                                                                       'desc' => 'Perform transactions on the live or test server. The test server should only be used by developers with payscout test accounts.',
                                                                                       'value' => 'Live',
                                                                                       'set_func' => 'tep_cfg_select_option(array(\'Live\', \'Test\'), '),
                      'MODULE_PAYMENT_PAYSCOUT_TRANSACTION_MODE' => array('title' => 'Transaction Mode',
                                                                                     'desc' => 'Transaction mode used for processing orders',
                                                                                     'value' => 'Live',
                                                                                     'set_func' => 'tep_cfg_select_option(array(\'Live\', \'Test\'), '),
                      'MODULE_PAYMENT_PAYSCOUT_SORT_ORDER' => array('title' => 'Sort order of display.',
                                                                               'desc' => 'Sort order of display. Lowest is displayed first.',
                                                                               'value' => '0'));

      return $params;
    }

    function _hmac($key, $data) {
      if (function_exists('hash_hmac')) {
        return hash_hmac('md5', $data, $key);
      } elseif (function_exists('mhash') && defined('MHASH_MD5')) {
        return bin2hex(mhash(MHASH_MD5, $data, $key));
      }

      $b = 64; // byte length for md5
      if (strlen($key) > $b) {
        $key = pack("H*",md5($key));
      }

      $key = str_pad($key, $b, chr(0x00));
      $ipad = str_pad('', $b, chr(0x36));
      $opad = str_pad('', $b, chr(0x5c));
      $k_ipad = $key ^ $ipad ;
      $k_opad = $key ^ $opad;

      return md5($k_opad . pack("H*",md5($k_ipad . $data)));
    }

    function sendTransactionToGateway($url, $parameters) {
      $server = parse_url($url);

      if ( !isset($server['port']) ) {
        $server['port'] = ($server['scheme'] == 'https') ? 443 : 80;
      }

      if ( !isset($server['path']) ) {
        $server['path'] = '/';
      }

      $curl = curl_init($server['scheme'] . '://' . $server['host'] . $server['path'] . (isset($server['query']) ? '?' . $server['query'] : ''));
      curl_setopt($curl, CURLOPT_PORT, $server['port']);
      curl_setopt($curl, CURLOPT_HEADER, false);
      curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
      curl_setopt($curl, CURLOPT_FORBID_REUSE, true);
      curl_setopt($curl, CURLOPT_FRESH_CONNECT, true);
      curl_setopt($curl, CURLOPT_POST, true);
      curl_setopt($curl, CURLOPT_POSTFIELDS, $parameters);
	  curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
      curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 40);
	  curl_setopt($curl, CURLOPT_TIMEOUT, 120);
      $result = curl_exec($curl);
      curl_close($curl);

      return $result;
    }

    function getTestLinkInfo() {
      $dialog_title = MODULE_PAYMENT_PAYSCOUT_DIALOG_CONNECTION_TITLE;
      $dialog_button_close = MODULE_PAYMENT_PAYSCOUT_DIALOG_CONNECTION_BUTTON_CLOSE;
      $dialog_success = MODULE_PAYMENT_PAYSCOUT_DIALOG_CONNECTION_SUCCESS;
      $dialog_failed = MODULE_PAYMENT_PAYSCOUT_DIALOG_CONNECTION_FAILED;
      $dialog_error = MODULE_PAYMENT_PAYSCOUT_DIALOG_CONNECTION_ERROR;
      $dialog_connection_time = MODULE_PAYMENT_PAYSCOUT_DIALOG_CONNECTION_TIME;

      $test_url = tep_href_link(FILENAME_MODULES, 'set=payment&module=' . $this->code . '&action=install&subaction=conntest');

      return $info;
    }  

// format prices without currency formatting
    function format_raw($number, $currency_code = '', $currency_value = '') {
      global $currencies, $currency;

      if (empty($currency_code) || !$this->is_set($currency_code)) {
        $currency_code = $currency;
      }

      if (empty($currency_value) || !is_numeric($currency_value)) {
        $currency_value = $currencies->currencies[$currency_code]['value'];
      }

      return number_format(tep_round($number * $currency_value, $currencies->currencies[$currency_code]['decimal_places']), $currencies->currencies[$currency_code]['decimal_places'], '.', '');
    }   
  }
?>
