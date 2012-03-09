<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/*
 * CI-Merchant Library
 *
 * Copyright (c) 2011-2012 Crescendo Multimedia Ltd
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:

 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.

 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

// Support legacy drivers which extend the CI_Driver class
// All drivers should be updated to extend Merchant_driver instead
// This will be removed in a future version!
if ( ! class_exists('CI_Driver')) get_instance()->load->library('driver');

define('MERCHANT_VENDOR_PATH', realpath(dirname(__FILE__).'/../vendor'));
define('MERCHANT_DRIVER_PATH', realpath(dirname(__FILE__).'/merchant'));

/**
 * Merchant Class
 *
 * Payment processing for CodeIgniter
 */
class Merchant
{
	protected $_driver;

	public function __construct($driver = NULL)
	{
		if ( ! empty($driver))
		{
			$this->load($driver);
		}
	}

	public function __call($function, $arguments)
	{
		if ( ! empty($this->_driver))
		{
			return call_user_func_array(array($this->_driver, $function), $arguments);
		}
	}

	public function __get($property)
	{
		if ( ! empty($this->_driver))
		{
			return $this->_driver->$property;
		}
	}

	/**
	 * Load the specified driver
	 */
	public function load($driver)
	{
		$this->_driver = $this->_create_instance($driver);
		return $this->_driver !== FALSE;
	}

	/**
	 * Returns the name of the currently loaded driver
	 */
	public function active_driver()
	{
		$class_name = get_class($this->_driver);
		if ($class_name === FALSE) return FALSE;
		return str_replace('Merchant_', '', $class_name);
	}

	/**
	 * Load and create a new instance of a driver.
	 * $driver can be specified either as a class name (Merchant_paypal) or a short name (paypal)
	 */
	protected function _create_instance($driver)
	{
		if (stripos($driver, 'merchant_') === 0)
		{
			$driver_class = ucfirst(strtolower($driver));
		}
		else
		{
			$driver_class = 'Merchant_'.strtolower($driver);
		}

		if ( ! class_exists($driver_class))
		{
			// attempt to load driver file
			$driver_path = MERCHANT_DRIVER_PATH.'/'.strtolower($driver_class).'.php';
			if ( ! file_exists($driver_path)) return FALSE;
			require_once($driver_path);

			// did the driver file implement the class?
			if ( ! class_exists($driver_class)) return FALSE;
		}

		// ensure class is not abstract
		$reflection_class = new ReflectionClass($driver_class);
		if ($reflection_class->isAbstract()) return FALSE;

		$instance = new $driver_class();

		// backwards compatible with drivers which don't have $default_settings array
		if (empty($instance->default_settings))
		{
			$instance->default_settings = $instance->settings;
		}

		// initialize default settings
		$instance->settings = array();
		foreach ($instance->default_settings as $key => $setting)
		{
			if (is_array($setting))
			{
				$instance->settings[$key] = isset($setting['default']) ? $setting['default'] : NULL;
			}
			else
			{
				$instance->settings[$key] = $setting;
			}
		}

		return $instance;
	}

	public function initialize($settings)
	{
		if ( ! is_array($settings)) return;

		foreach ($settings as $key => $value)
		{
			if (isset($this->_driver->settings[$key]))
			{
				if (is_bool($this->_driver->settings[$key])) $value = (bool)$value;

				$this->_driver->settings[$key] = $value;
			}
		}
	}

	public function valid_drivers()
	{
		static $valid_drivers = array();

		if (empty($valid_drivers))
		{
			foreach (scandir(MERCHANT_DRIVER_PATH) as $file_name)
			{
				$driver_path = MERCHANT_DRIVER_PATH.'/'.$file_name;
				if (stripos($file_name, 'merchant_') === 0 AND is_file($driver_path))
				{
					require_once($driver_path);

					// does the file implement an appropriately named class?
					$driver_class = ucfirst(str_replace('.php', '', $file_name));
					if ( ! class_exists($driver_class)) continue;

					// ensure class is not abstract
					$reflection_class = new ReflectionClass($driver_class);
					if ($reflection_class->isAbstract()) continue;

					$valid_drivers[] = str_replace('Merchant_', '', $driver_class);
				}
			}
		}

		return $valid_drivers;
	}

	public function process($params = array())
	{
		if (isset($params['card_no']) AND empty($_SERVER['HTTPS']))
		{
			show_error('Card details were not submitted over a secure connection.');
		}

		if (is_array($this->_driver->required_fields))
		{
			foreach ($this->_driver->required_fields as $field_name)
			{
				if (empty($params[$field_name]))
				{
					$response = new Merchant_response('failed', 'field_missing');
					$response->error_field = $field_name;
					return $response;
				}
			}
		}

		// normalize months to 2 digits and years to 4
		if (isset($params['exp_month'])) $params['exp_month'] = sprintf('%02d', (int)$params['exp_month']);
		if (isset($params['exp_year'])) $params['exp_year'] = sprintf('%04d', (int)$params['exp_year']);
		if (isset($params['start_month'])) $params['start_month'] = sprintf('%02d', (int)$params['start_month']);
		if (isset($params['start_year'])) $params['start_year'] = sprintf('%04d', (int)$params['start_year']);

		// normalize card_type to lowercase
		if (isset($params['card_type'])) $params['card_type'] = strtolower($params['card_type']);

		// DEPRECATED: old _process() function
		if (method_exists($this->_driver, '_process'))
		{
			return $this->_driver->_process($params);
		}

		return $this->_driver->process($params);
	}

	public function process_return($params = array())
	{
		if (method_exists($this->_driver, 'process_return'))
		{
			return $this->_driver->process_return($params);
		}

		// DEPRECATED: Old _process_return() function doesn't accept params array
		if (method_exists($this->_driver, '_process_return'))
		{
			return $this->_driver->_process_return();
		}

		return new Merchant_response('failed', 'return_not_supported');
	}

	/**
	 * Curl helper function
	 *
	 * Let's keep our cURLs consistent
	 */
	public static function curl_helper($url, $post_data = NULL, $username = NULL, $password = NULL)
	{
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_HEADER, FALSE);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE); // don't check client certificate

		if ($post_data !== NULL)
		{
			if (is_array($post_data))
			{
				$post_data = http_build_query($post_data);
			}

			curl_setopt($ch, CURLOPT_POST, TRUE);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
		}

		if ($username !== NULL)
		{
			curl_setopt($ch, CURLOPT_USERPWD, $username.':'.$password);
		}

		$response = array();
		$response['data'] = curl_exec($ch);
		$response['error'] = curl_error($ch);

		curl_close($ch);
		return $response;
	}

	/**
	 * Redirect Post function
	 *
	 * Automatically redirect the user to payment pages which require POST data
	 */
	public static function redirect_post($post_url, $data, $message = 'Please wait while we redirect you to the payment page...')
	{
		?>
<!DOCTYPE html>
<html>
<head><title>Redirecting...</title></head>
<body onload="document.forms[0].submit();">
	<p><?php echo htmlspecialchars($message); ?></p>
	<form name="payment" action="<?php echo htmlspecialchars($post_url); ?>" method="post">
		<p>
			<?php if (is_array($data)): ?>
				<?php foreach ($data as $key => $value): ?>
					<input type="hidden" name="<?php echo $key; ?>" value="<?php echo htmlspecialchars($value); ?>" />
				<?php endforeach ?>
			<?php else: ?>
				<?php echo $data; ?>
			<?php endif; ?>
			<input type="submit" value="Continue" />
		</p>
	</form>
</body>
</html>
	<?php
		exit();
	}
}

abstract class Merchant_driver
{
	public $default_settings = array();
	public $settings;
	public $required_fields;
}

class Merchant_response
{
	public $status;
	public $message;
	public $txn_id;
	public $amount;
	public $error_field;

	public function __construct($status, $message, $txn_id = null, $amount = null)
	{
		$this->status = $status;
		$this->message = $message;
		$this->txn_id = $txn_id;
		$this->amount = $amount;
	}
}

/* End of file ./libraries/merchant/merchant.php */