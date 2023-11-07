<?php

/**
 * SpamGuard is a PHP class designed to protect WordPress websites from spam.
 * It integrates with Contact Form 7 and employs token validation, honeypot fields,
 * and keyword filtering to deter spam.
 *
 * @package Rekurencja
 * @version 1.0.0
 * @author KRN
 * @link https://github.com/korneliuszburian
 * @see https://rekurencja.com
 */

namespace Rekurencja;

use DateTime;

class SpamGuard
{

	/**
	 * Minimum required PHP version for the plugin to work.
	 *
	 * @var string
	 */

	const MIN_PHP_VERSION = '7.4';

	/**
	 * Database table name for storing unique tokens.
	 *
	 * @var string
	 */
	const TABLE_NAME = 'CF7_unique_tokens';

	protected $wpdb;

	/**
	 * Array of illegal words to check against form submissions.
	 *
	 * @var array
	 */
	protected $illegalWords = [];

	public function __construct($wpdb)
	{
		$this->wpdb = $wpdb;
		$this->initializeActionsAndFilters();
		$this->loadIllegalWords();
	}


	/**
	 * Initializes WordPress actions and filters to integrate with Contact Form 7.
	 *
	 * @return void
	 */
	private function initializeActionsAndFilters(): void
	{
		add_action('init', [$this, 'createTokenTable']);
		add_action('init', [$this, 'cleanupOldTokens']);
		add_action('wpcf7_before_send_mail', [$this, 'invalidateTokenBeforeSendMail']);
		add_action('wp_ajax_regenerate_token', [$this, 'ajaxRegenerateToken']);
		add_action('wp_ajax_nopriv_regenerate_token', [$this, 'ajaxRegenerateToken']);
		add_action('wp_enqueue_scripts', [$this, 'enqueueScripts']);
		add_action('wp_enqueue_scripts', [$this, 'enqueueStyles']);
		add_filter('wpcf7_form_hidden_fields', [$this, 'addTokenHiddenField']);
		add_filter('wpcf7_spam', [$this, 'validateTokenAndKeywords'], 10, 1);
		add_filter('wpcf7_form_elements', 'do_shortcode');
		add_filter('wpcf7_form_elements', [$this, 'addHoneyPotField']);
	}


	/**
	 * Loads illegal words from a file.
	 *
	 * @return void
	 */
	public function loadIllegalWords(): void
	{
		$pathToIllegalFile = plugin_dir_path(__FILE__) . '../lib/blacklist.json';
		if (!file_exists($pathToIllegalFile)) {
			error_log("Blacklisted words file not found.");
			return;
		}
		$jsonContents = file_get_contents($pathToIllegalFile);
		$data = json_decode($jsonContents, true);

		if (json_last_error() === JSON_ERROR_NONE && isset($data['blacklistedWords'])) {
			$this->illegalWords = $data['blacklistedWords'];
		}
	}


	/**
	 * Creates a database table for storing tokens if it does not exist.
	 *
	 * @return bool Returns true if the table creation was successful, false otherwise.
	 */
	public function createTokenTable(): bool
	{
		$charsetCollate = $this->wpdb->get_charset_collate();
		$tableName = $this->wpdb->base_prefix . self::TABLE_NAME;

		$sql = "CREATE TABLE IF NOT EXISTS `$tableName` (
            id mediumint(9) NOT NULL AUTO_INCREMENT,
            token varchar(255) NOT NULL,
            salt varchar(16) NOT NULL,
            timestamp datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
            PRIMARY KEY  (id)
        ) $charsetCollate;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta($sql);

		return empty($this->wpdb->last_error);
	}

	/**
	 * Converts a file path to a URL.
	 *
	 * @param [type] $file_path
	 * @return void
	 */
	public function file_path_to_url($file_path)
	{
		$content_url = content_url();
		$content_dir = WP_CONTENT_DIR;

		$url = str_replace($content_dir, $content_url, $file_path);
		return $url;
	}

	/**
	 * Enqueues scripts.
	 *
	 * @return void
	 */
	public function enqueueScripts()
	{
		$file_url = $this->file_path_to_url(dirname((__FILE__), 2));
		// if (is_page('kontakt', '/')) {
		wp_enqueue_script('token-generator', $file_url . '/assets/js/token-generator.min.js', array(), '1.0.0', true);
		// }
	}

	/**
	 * Enqueues styles.
	 *
	 * @return void
	 */
	public function enqueueStyles()
	{
		$file_url = $this->file_path_to_url(dirname((__FILE__), 2));
		wp_enqueue_style('rekuspamshield', $file_url . '/assets/css/rekuspamshield.css', array(), '1.0.0', 'all');
	}

	/**
	 * Adds a honeypot field to the form.
	 *
	 * @param string $html
	 * @return string
	 */
	public function addHoneyPotField(string $html): string
	{
		$file_url = $this->file_path_to_url(dirname((__FILE__), 2));

		$html .= '

        <div class="confirm_label">
            <label>Potwierdź numer telefonu</label>
            <input type="text" name="confirm-phone" class="confirm_field">

            <label>Potwierdź adres e-mail</label>
            <input type="email" name="confirm-email" class="confirm_field">
        </div>
        <span class="confirm_label">Potwierdź, że nie jesteś robotem</span>
        <div class="confirm_label">
            <input type="checkbox" name="confirm-robot" class="confirm_field">
            <label class="confirm_label">Nie jestem robotem</label>
        </div>
        ';

		return $html;
	}

	/**
	 * Counts the number of capitalized words in a string.
	 *
	 * @param string $message
	 * @return integer
	 */
	public function countCapitalizedWords(string $message): int
	{
		$words = str_word_count($message, 1);
		$capitalizedCount = 0;
		foreach ($words as $word) {
			if (ctype_upper($word[0])) {
				$capitalizedCount++;
			}
		}
		return $capitalizedCount;
	}


	/**
	 * Generates a unique token and stores it in the database.
	 *
	 * @return string|null Returns the generated token or null if the token could not be generated.
	 */
	public function generateToken(): ?string
	{
		if (!function_exists('random_bytes') || !function_exists('openssl_encrypt') || !defined('AUTH_KEY')) {
			return null;
		}

		$token = bin2hex(random_bytes(32));
		$salt = random_bytes(8);
		$saltHex = bin2hex($salt);
		$encryptedToken = openssl_encrypt($token, 'aes-256-cbc', AUTH_KEY, 0, $saltHex);

		$success = $this->wpdb->insert($this->wpdb->prefix . self::TABLE_NAME, [
			'token' => $encryptedToken,
			'salt' => $saltHex,
			'timestamp' => current_time('mysql'),
		]);

		// error_log("Original token generated: $token, Encrypted token: $encryptedToken");

		return $success ? $encryptedToken : null;
	}

	/**
	 * Deletes tokens older than 24 hours.
	 *
	 * @return void
	 */
	public function cleanupOldTokens(): void
	{
		$expiration = (new DateTime())->modify('-24 hours')->format('Y-m-d H:i:s');
		$this->wpdb->query($this->wpdb->prepare("DELETE FROM `{$this->wpdb->prefix}" . self::TABLE_NAME . "` WHERE timestamp < %s", $expiration));
	}

	/**
	 * Adds a token hidden field to the form.
	 *
	 * @param array $hiddenFields
	 * @return array
	 */
	public function addTokenHiddenField(array $hiddenFields): array
	{
		$token = $this->generateToken();
		if (!$token) {
			return $hiddenFields;
		}

		$this->startSession();

		$_SESSION['original_token'] = $token;
		$hiddenFields['form_token'] = $token;
		$hiddenFields['user_agent'] = $_SERVER['HTTP_USER_AGENT'];

		return $hiddenFields;
	}

	/**
	 * Validates the token and keywords.
	 *
	 * @param boolean $spam
	 * @return boolean
	 */
	public function validateTokenAndKeywords(bool $spam): bool
	{
		$token = $_POST['form_token'] ?? null;
		$user_agent = $_POST['user_agent'] ?? null;
		$your_message = $_POST['your-message'] ?? null;

		$this->startSession();

		if (!isset($token)) {
			return true;
		}

		if (!$this->isTokenValid($token)) {
			return true;
		}

		if (isset($_POST['user_agent']) && $_POST['user_agent'] !== $_SERVER['HTTP_USER_AGENT']) {
			return true;
		}

		$message = strtolower($_POST['your-message']);

		if ($this->countCapitalizedWords($_POST['your-message']) > 3) {
			error_log("Wykryto spam: Więcej niż 3 słowa capslockiem.");
			return true;
		}

		foreach ($this->illegalWords as $illegal) {
			if (strpos($message, $illegal) !== false) {
				error_log("Wykryto spam: Nielegalne słowo: $illegal");
				return true;
			}
		}

		if (isset($_POST['confirm-phone']) && !empty($_POST['confirm-phone'])) {
			error_log("Spam wykryty! Honeypot: confirm-phone");
			return true;
		} else if (isset($_POST['confirm-email']) && !empty($_POST['confirm-email'])) {
			error_log("Spam wykryty! Honeypot: confirm-email");
			return true;
		}

		return $spam;
	}

	/**
	 * Deletes the token from the database before sending the mail.
	 *
	 * @param object $contactForm
	 * @return void
	 */
	public function invalidateTokenBeforeSendMail($contactForm): void
	{
		if (isset($_POST['form_token'])) {
			$token = $_POST['form_token'];
			$this->deleteToken($token);
		}
	}

	/**
	 * Deletes a token from the database.
	 *
	 * @param string $token
	 * @return void
	 */
	public function deleteToken(string $token): void
	{
		error_log("Usuwanie tokenu: $token");
		$this->wpdb->delete("{$this->wpdb->prefix}" . self::TABLE_NAME, array('token' => $token));
	}

	/**
	 * Checks if a token exists in the database.
	 *
	 * @param string $token
	 * @return boolean
	 */
	public function isTokenValid(string $token): bool
	{
		$result = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM `{$this->wpdb->prefix}" . self::TABLE_NAME . "` WHERE token = %s", $token));

		if (!$result) {
			error_log("Token $token nie istnieje w bazie danych.");
			return false;
		}

		return true;
	}

	private function startSession(): void
	{
		if (session_status() !== PHP_SESSION_ACTIVE) {
			session_start();
		}
	}


	/**
	 * Regenerates a token and returns it as a JSON response.
	 *
	 * @return void
	 */
	public function ajaxRegenerateToken(): void
	{
		$newToken = $this->generateToken();

		if ($newToken) {
			echo json_encode(['success' => true, 'token' => $newToken]);
		} else {
			echo json_encode(['success' => false]);
		}
		wp_die();
	}
}
