<?php

/**
 * SpamGuard is a PHP class designed to protect WordPress websites from spam.
 * It integrates with Contact Form 7 and employs token validation, honeypot fields,
 * and keyword filtering to deter spam.
 *
 * @package Rekurencja
 * @version 1.0.1
 * @author KRN
 * @link https://github.com/korneliuszburian
 * @see https://rekurencja.com
 */

namespace Rekurencja;

use DateTime;
use Exception;

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

		if (is_admin()) {
			$this->initializeAdminPanel();
			add_action('admin_post_update_blacklist', [$this, 'handlePostRequest']);
		}
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
		add_action('admin_enqueue_scripts', [$this, 'load_admin_style']);
	}

	/**
	 * Initialize the admin panel hooks and settings.
	 */
	public function initializeAdminPanel(): void
	{
		add_action('admin_menu', [$this, 'addAdminMenu']);
		add_action('admin_init', [$this, 'registerSettings']);
	}

	/**
	 * Add options page to the admin menu.
	 */
	public function addAdminMenu(): void
	{
		add_options_page('SpamGuard Settings', 'SpamGuard', 'manage_options', 'spamguard', [$this, 'adminOptionsPage']);
	}

	/**
	 * Register settings for the admin panel.
	 */
	public function registerSettings(): void
	{
		register_setting('spamguard', 'spamguard_settings');
		add_settings_section('spamguard_section', 'Blacklist Settings', null, 'spamguard');
		add_settings_field('spamguard_blacklist', 'Blacklisted Words', [$this, 'blacklistWordsField'], 'spamguard', 'spamguard_section');
	}

	/**
	 * HTML for the blacklist words field.
	 */
	public function blacklistWordsField(): void
	{
		$options = get_option('spamguard_settings');
		echo '<textarea name="spamguard_settings[blacklist]" rows="10" cols="50">' . esc_textarea($options['blacklist'] ?? '') . '</textarea>';
	}


	/**
	 * Handles POST requests from the admin panel.
	 * 
	 * @return void
	 */
	public function handlePostRequest(): void
	{
		// Check if the form is submitted for updating the blacklist
		if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['new_blacklisted_words'])) {
			$newWords = sanitize_text_field($_POST['new_blacklisted_words']);
			if (!empty($newWords)) {
				$newWordsArray = array_map('trim', explode(',', $newWords));
				$updatedWords = array_unique(array_merge($this->illegalWords, $newWordsArray));
				$this->updateBlacklistFile(implode(',', $updatedWords));
			}
		}

		// Check if the form is submitted for deleting a word from the blacklist
		if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['delete_word'])) {
			$wordToDelete = sanitize_text_field($_POST['delete_word']);
			$this->illegalWords = array_filter($this->illegalWords, function ($word) use ($wordToDelete) {
				return $word !== $wordToDelete;
			});
			$this->updateBlacklistFile(implode(',', $this->illegalWords));
		}

		// Redirect back to the admin page
		$redirect_url = admin_url('options-general.php?page=spamguard');
		wp_redirect($redirect_url);
		exit;
	}


	private function updateBlacklistFile(string $blacklistedWords): void
	{
		$pathToIllegalFile = plugin_dir_path(__FILE__) . '../lib/blacklist.json';
		$wordsArray = array_map('trim', explode(',', $blacklistedWords)); // Split into array
		$data = json_encode(['blacklistedWords' => $wordsArray]);

		if (file_put_contents($pathToIllegalFile, $data) === false) {
			$this->logEvent("Failed to update blacklisted words.");
		} else {
			$this->loadIllegalWords();
		}
	}

	/**
	 * Loads the illegal words list from the file.
	 * 
	 */
	public function loadIllegalWordsList(): void
	{
		$illegalWords = get_option('spamguard_settings');
		$illegalWords = $this->illegalWords;

		if (empty($illegalWords)) {
			$this->logEvent("Blacklisted words list is empty.");
			return;
		}

		$this->illegalWords = $illegalWords;
	}

	/**
	 * Render the options page.
	 */
	public function adminOptionsPage(): void
	{
?>
		<div class="spam__wr">
			<h1>SpamGuard ustawienia</h1>
			<form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
				<input type="hidden" name="action" value="update_blacklist">
				<h2>Aktualna lista zabronionych słów: </h2>
				<div class="spam__words" style="margin-bottom: 20px;">
					<?php
					foreach ($this->illegalWords as $word) {
						echo '<div class="spam__word">';
						echo '<input type="text" value="' . esc_attr($word) . '" disabled>';
						echo '<button type="submit" name="delete_word" value="' . esc_attr($word) . '">Delete <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24"><path d="M24 20.188l-8.315-8.209 8.2-8.282-3.697-3.697-8.212 8.318-8.31-8.203-3.666 3.666 8.321 8.24-8.206 8.313 3.666 3.666 8.237-8.318 8.285 8.203z"/></svg></button>';
						echo '</div>';
					} ?>
				</div>
				<label for="new_blacklisted_words">Dodaj nowe słowa do czarnej listy (oddzielone przecinkami): </label><br>
				<textarea id="new_blacklisted_words" name="new_blacklisted_words" rows="4" cols="50"></textarea><br><br>
				<input type="submit" value="Update Blacklist" class="button button-primary">
			</form>
		</div>
<?php
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
			$this->logEvent("Blacklisted words file not found.");
			return;
		}

		$jsonContents = file_get_contents($pathToIllegalFile);
		$data = json_decode($jsonContents, true);

		if (json_last_error() === JSON_ERROR_NONE && isset($data['blacklistedWords'])) {
			$this->illegalWords = $data['blacklistedWords'];
		} else {
			$this->logEvent("Failed to load blacklisted words.");
		}
	}

	/**
	 * Log events to a custom file for better traceability.
	 *
	 * @param string $message The log message.
	 * @param string $level The log level (info, warning, error).
	 */
	private function logEvent($message, $level = 'info')
	{
		$logDirectory = plugin_dir_path(__FILE__);
		$logFile = $logDirectory . 'SpamGuard.log';

		// Check if the directory exists, if not try to create it
		if (!is_dir($logDirectory)) {
			mkdir($logDirectory, 0755, true);
			$this->logEvent('Log directory not found. Attempting to create it.', 'warning');
		}

		// Check if the log file exists, and create it if it doesn't
		if (!file_exists($logFile)) {
			$fileHandle = fopen($logFile, 'w'); // Create file
			$this->logEvent('Log file not found. Attempting to create it.', 'warning');
			fclose($fileHandle);
		}

		// Format the log entry
		$logEntry = "[" . date('Y-m-d H:i:s') . "] [$level] - $message" . PHP_EOL;

		// Write the log entry to the file
		file_put_contents($logFile, $logEntry, FILE_APPEND);

		$this->logEventToAdminPanel($message, $level);
	}

	/**
	 * Log events to the admin panel.
	 *
	 * @param string $message The log message.
	 * @param string $level The log level (info, warning, error).
	 */
	private function logEventToAdminPanel($message, $level = 'info')
	{
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

		if ($this->wpdb->last_error) {
			$this->logEvent('Database table creation failed: ' . $this->wpdb->last_error, 'error');
			return false;
		}

		return true;
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
		wp_enqueue_script('token-generator', $file_url . '/assets/js/token-generator.min.js', array(), '1.0.0', true);

		wp_localize_script('token-generator', 'rekurencja_vars', array(
			'ajax_url' => admin_url('admin-ajax.php')
		));
	}

	public function load_admin_style()
	{
		$file_url = $this->file_path_to_url(dirname((__FILE__), 2));
		wp_enqueue_style('admin_css', $file_url . '/assets/css/style-admin.css', false, '1.0.0');
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
		wp_enqueue_style('style-admin', $file_url . '/assets/css/style-admin.css', array(), '1.0.0', 'all');
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
            <label for="confirm-phone">>Potwierdź numer telefonu</label>
            <input type="email" id="confirm-phone" name="confirm-phone" class="confirm_field" autofill="false" autocomplete="false">

            <label for="confirm-email">Potwierdź adres e-mail</label>
            <input type="email" id="confirm-email" name="confirm-email" class="confirm_field" autofill="false" autocomplete="false">
        </div>
        <span class="confirm_label">Potwierdź, że nie jesteś robotem</span>
        <div class="confirm_label">
			<label for="confirm-robot" class="confirm_label"> Nie jestem robotem </label>
            <input type="checkbox" id="confirm-robot" name="confirm-robot" class="confirm_field" autofill="false" autocomplete="false">
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
		try {
			if (!function_exists('random_bytes') || !function_exists('openssl_encrypt') || !defined('AUTH_KEY')) {
				throw new Exception("Function missing for token generation", 1);
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

			if (!$success) {
				throw new Exception("Failed to insert token into database", 2);
			}

			return $encryptedToken;
		} catch (Exception $e) {
			$this->logEvent("Token generation error: " . $e->getMessage() . " (Code " . $e->getCode() . ")", 'error');
			return null;
		}
	}

	/**
	 * Deletes tokens older than 24 hours.
	 *
	 * @return void
	 */
	public function cleanupOldTokens(): void
	{
		$expiration = (new DateTime())->modify('-10 hours')->format('Y-m-d H:i:s');
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
			$this->logEvent("Token generation failed. Form may be vulnerable to spam.", 'warning');
			return $hiddenFields;
		} else {
			$this->logEvent("Token $token generated.");
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
		$your_message = $_POST['your-message'] ?? '';
		$user_email = $_POST['your-email'] ?? $_POST['email'];
		$userIp = $_SERVER['REMOTE_ADDR'];

		$this->startSession();

		if (!$this->isTokenValid($token)) {
			$this->logEvent('Invalid token received. Potential spam detected.', 'error');
			$_POST['spam_protection_status'] = 'reduced';
			return $spam;
		}

		if ($this->isSpamDetected($token, $user_agent, $your_message)) {
			$this->logEvent("Spam detected from IP: $userIp. User Email: $user_email.", 'warning');
			return true;
		}

		return $spam;
	}


	/**
	 * Checks if spam is detected based on various conditions.
	 *
	 * @param string|null $token
	 * @param string|null $user_agent
	 * @param string|null $message
	 * @return boolean
	 */
	private function isSpamDetected(?string $token, ?string $user_agent, ?string $message): bool
	{
		// Check token
		if (!isset($token)) {
			$this->logEvent('Token nie jest ustawiony. Formularz może być podatny na spam.', 'warning');
			return true;
		}

		// Check user agent
		if (!isset($user_agent) || $user_agent !== $_SERVER['HTTP_USER_AGENT']) {
			return true;
		}

		// Check message content
		if (!isset($message)) {
			$this->logEvent('Wiadomość nie została wpisana.', 'warning');
			return true;
		}

		// Check for capitalized words, honeypot fields, and illegal words...
		$message = strtolower($message);

		if ($this->countCapitalizedWords($message) > 3) {
			$this->logEvent('Wiadomość zawiera zbyt dużo słów zaczynających się z wielkiej litery. Potencjalny spam wykryty.', 'warning');
			return true;
		}

		foreach ($this->illegalWords as $illegal) {
			if (strpos($message, $illegal) !== false) {
				$this->logEvent("Wiadomość zawiera niedozwolone słowo: $illegal. Potencjalny spam wykryty.", 'warning');
				return true;
			}
		}

		if (isset($_POST['confirm-phone']) && !empty($_POST['confirm-phone'])) {
			$this->logEvent("Pole pułapka" . $_POST['confirm-phone'] . " zostało wypełnione. Potencjalny spam wykryty.", 'warning');
			return true;
		} else if (isset($_POST['confirm-email']) && !empty($_POST['confirm-email'])) {
			$this->logEvent("Pole pułapka" . $_POST['confirm-email'] . " zostało wypełnione. Potencjalny spam wykryty.", 'warning');
			return true;
		}


		return false;
	}

	/**
	 * Deletes the token from the database before sending the mail.
	 *
	 * @param object $contactForm
	 * @return void
	 */
	public function invalidateTokenBeforeSendMail(object $contactForm): void
	{
		$token = $_POST['form_token'] ?? null;
		$originalToken = $_SESSION['original_token'] ?? null;

		if (!$token || !$originalToken) {
			return;
		}

		if ($token !== $originalToken) {
			$this->logEvent('Otrzymano niewazny token. Wykryto potencjalny spam.', 'error');
			return;
		}

		$this->deleteToken($token);
	}

	/**
	 * Deletes a token from the database.
	 *
	 * @param string $token
	 * @return void
	 */
	public function deleteToken(string $token): void
	{
		$this->logEvent("Usuwanie tokenu: $token");
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
			$this->logEvent("Token $token nie istnieje w bazie danych.");
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
