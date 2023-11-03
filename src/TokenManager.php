
<?php

namespace Rekurencja;

use DateTime;
use wpdb;

class TokenManager
{
    const TABLE_NAME = 'CF7_unique_tokens';
    private $wpdb;

    public function __construct($wpdb)
    {
        $this->wpdb = $wpdb;
    }

    public function createTokenTable(): bool
    {
        // ... Method implementation ...
    }

    public function cleanupOldTokens(): void
    {
        // ... Method implementation ...
    }

    public function generateToken(): ?string
    {
        // ... Method implementation ...
    }

    public function invalidateTokenBeforeSendMail($contactForm): void
    {
        // ... Method implementation ...
    }

    private function isTokenValid(string $token): bool
    {
        // ... Method implementation ...
    }
}

// The actual method implementations need to be copied from the SpamGuard class.
