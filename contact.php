<?php 
require_once('vendor/phpmailer/phpmailer/class.phpmailer.php');

use \PHPMailer;

/**
 * Simple contact form for the micro CMS Pico.
 *
 * @author Klas Gidlöv
 * @link http://gidlov.com/code/
 * @license LGPL
 */

define('CONTACT_MESSAGE', '<!--CONTACT-MESSAGE-->');

class Contact {

	private $validation;
	private $message;
	private $error;
	private $post;
	private $captcha;

	public function __construct() {
		session_start();
	}

	public function config_loaded(&$settings) {
		// Missing config settings.
		if (empty($settings['contact']))
			return;
		// No post request.
		if (empty($settings['contact']['post']))
			return;
		$this->contact = $settings['contact'];
		$validate = array('name', 'mail', 'message');
		if (isset($this->contact['captcha']) && isset($this->contact['key']) && $this->contact['captcha'] === true) {
			$validate[] = 'captcha';	
		}
		$this->post = $settings['contact']['post'];
		// Post to this form was made.
		if (isset($this->post['contact']) AND $this->post['contact'] == 'true') {
			foreach ($validate as $value) {
				if ($value == 'mail') {
					if (filter_var($this->post['mail'], FILTER_VALIDATE_EMAIL) === false) {
						$this->validation[$value] = isset($this->contact['validation_messages']['invalid_mail']) ? sprintf($this->contact['validation_messages']['invalid_mail'], $value) : "<li>Een geldige {$value} e-mail is vereist.</li>";;
					}
				}
				if ($value != 'captcha' && empty($this->post[$value])) {
					$this->validation[$value] = isset($this->contact['validation_messages']['required']) ? sprintf($this->contact['validation_messages']['required'], $value) : "<li>Het veld {$value} is vereist.</li>";
				}
				
				if ($value == 'captcha') {
					$response = $this->post['g-recaptcha-response'];
					$key = $this->contact['key'];
					$ip = $_SERVER['REMOTE_ADDR'];
					$url = 'https://www.google.com/recaptcha/api/siteverify';
					
					$data = array( 'secret' => "$key",
									'response' => "$response",
									'remoteip' => "$ip"
								);
					
					$decoded = json_decode($this->call_api($url, $data), true);
					
					if (!$decoded["success"]) {
						$this->validation[$value] = isset($this->contact['validation_messages']['captcha']) ? sprintf($this->contact['validation_messages']['captcha'], $value) : "<li>De captcha is ongeldig.</li>";
					}
				}
			}
		}
		// No validation validation, proceed sending the email.
		if (count($this->validation) == 0) {
			$mail = new \PHPMailer;
			if (isset($this->contact['smtp'])) {
				$mail->isSMTP();
				$mail->Host = $this->contact['smtp']['host'] ? $this->contact['smtp']['host'] : '';
				$mail->SMTPAuth = $this->contact['smtp']['auth'] ? $this->contact['smtp']['auth'] : '';
				$mail->Username = $this->contact['smtp']['username'] ? $this->contact['smtp']['username'] : '';
				$mail->Password = $this->contact['smtp']['password'] ? $this->contact['smtp']['password'] : '';
				$mail->SMTPSecure = $this->contact['smtp']['encryption'] ? $this->contact['smtp']['encryption'] : '';
				$mail->Port = $this->contact['smtp']['port'] ? $this->contact['smtp']['port'] : '';
			}
			$mail->CharSet = "UTF-8";
			$mail->FromName = $this->post['name'];
			$mail->From = $this->post['mail'];
			$mail->addAddress($this->contact['send_to']);
			$subject = isset($this->post['subject']) ? $this->post['subject'] : '';
			$args = array($this->post['name'], $this->post['mail'], $subject, $settings['site_title'], $settings['base_url']);
			$header = isset($this->contact['body_header']) ? vsprintf($this->contact['body_header'], $args) : '';
			$footer = isset($this->contact['body_footer']) ? vsprintf($this->contact['body_footer'], $args) : '';
			if (isset($this->contact['subject'])) {
				$mail->Subject = vsprintf($this->contact['subject'], $args);
			} elseif ($subject != '') {
				$mail->Subject = $this->post['subject'];
			}
			$mail->Body = $header.$this->post['message'].$footer;
			
			if(!$mail->send()) {
				// Try to use SMTP if you'll get here.
				$this->error = isset($mail->validationInfo) ? $mail->validationInfo : 'Onbekende fout.';
			} else {
				$this->post = false;
				$this->message = true;
			}
		}
	}

	public function content_parsed(&$content) {
		// Show validation failiurs.
		if (isset($this->validation)) {
			$validation = '';
				foreach ($this->validation as $section => $value) {
					if (isset($value) AND $value != '') {
						if (isset($this->contact['error_class'])) {
							$content = preg_replace('/<input(.*?name="'.$section.'".*?class=".*?)"/ms', '<input$1 '.$this->contact['error_class'].'"', $content);
						}
						$validation .= $value."<br />\n";
					}
				}
			if ($validation) {
				$message = isset($this->contact['alert_messages']['validation_error']) ? $this->contact['alert_messages']['validation_error'] : '<div class="warning alert">Er is nog iets mis met uw bericht:<ul class="disc">%1$s</ul></div>';
				$content = preg_replace('/'.CONTACT_MESSAGE.'/ms', sprintf($message, $validation), $content);
			}
		}
		if ($this->message) {
			$message = isset($this->contact['alert_messages']['success']) ? $this->contact['alert_messages']['success'] : '<div class="success alert">Uw bericht is verzonden. We nemen zo snel mogelijk contact met u op.</div>';
			$content = preg_replace('/'.CONTACT_MESSAGE.'/ms', $message, $content);
		}
		if ($this->error) {
			$message = isset($this->contact['alert_messages']['error']) ? $this->contact['alert_messages']['error'] : '<div class="danger alert">Oh nee! Uw bericht kon verzonden worden: %1$s</p></div>';
			$content = preg_replace('/'.CONTACT_MESSAGE.'/ms', sprintf($message, $this->error), $content);
		}
		// User input.
		if (empty($this->post))
			return;
		foreach ($this->post as $key => $value) {
			if ($key == 'message') {
				$content = preg_replace('/<textarea(.*?)><\/textarea>/', '<textarea$1>'.$value.'</textarea>', $content);	
			} else {
				$content = preg_replace('/<input(.*?)name="'.$key.'"/ms', "<input$1name='{$key}' value='{$value}'", $content);
			}
		}
	}

	private function call_api($url, $data) {
		$curl = curl_init();

        $url = sprintf("%s?%s", $url, http_build_query($data));
	
		curl_setopt($curl, CURLOPT_URL, $url);
		curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);

		$result = curl_exec($curl);

		curl_close($curl);

		return $result;
	}
}