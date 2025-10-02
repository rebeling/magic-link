<?php

namespace Drupal\magic_link\Service;

use Drupal\Core\Site\Settings;

/**
 * Service for magic link token generation and verification.
 */
class MagicLinkTokenService {

  /**
   * Generate HMAC-SHA256 signature for magic link token.
   *
   * @param int $uid
   *   User ID.
   * @param int $exp
   *   Expiration timestamp.
   * @param string $nonce
   *   Random nonce string.
   *
   * @return string|null
   *   Base64url-encoded signature, or NULL if hash_salt is unavailable.
   */
  public function signToken(int $uid, int $exp, string $nonce): ?string {
    $salt = (string) (Settings::get('hash_salt') ?? '');
    if ($salt === '') {
      return NULL;
    }
    $data = $uid . '|' . $exp . '|' . $nonce;
    $raw = hash_hmac('sha256', $data, $salt, TRUE);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

  /**
   * Verify magic link token signature.
   *
   * @param int $uid
   *   User ID from the token.
   * @param int $exp
   *   Expiration timestamp.
   * @param string $nonce
   *   Nonce from the token.
   * @param string $sig
   *   Signature to verify.
   *
   * @return bool
   *   TRUE if token is valid and not expired, FALSE otherwise.
   */
  public function verifyTokenSignature(int $uid, int $exp, string $nonce, string $sig): bool {
    if ($exp < time()) {
      return FALSE;
    }
    $expected = $this->signToken($uid, $exp, $nonce);
    if (!$expected) {
      return FALSE;
    }
    // Constant-time compare.
    return hash_equals($expected, $sig);
  }

}
