<?php

/**
 * Tool name: Spamguard
 */

require_once __DIR__ . '/src/SpamGuard.php';

if (version_compare(PHP_VERSION, Rekurencja\SpamGuard::MIN_PHP_VERSION) < 0) {
	throw new Exception('Rekurencja SpamGuard wymaga PHP w wersji ' . Rekurencja\SpamGuard::MIN_PHP_VERSION . ' lub powyżej.');
}

new Rekurencja\SpamGuard($GLOBALS['wpdb']);
