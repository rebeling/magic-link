<?php

namespace Drupal\magic_link\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drush\Commands\DrushCommands;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Drush commands for Magic Link module.
 */
final class MagicLinkCommands extends DrushCommands {

  /**
   * The entity type manager.
   */
  private EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs a new MagicLinkCommands object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct();
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager')
    );
  }

  /**
   * Generate a persistent magic login link.
   *
   * Unlike the standard magic links, these links:
   * - Don't expire after a single use
   * - Have configurable expiration times (default: 1 hour)
   * - Are intended for administrative/development use
   *
   * @command magic-link:generate
   * @aliases mli
   * @param int $uid User ID to generate link for. Defaults to user 1 (admin).
   * @option expire Link expiration time (e.g., 1h, 24h, 3d). Default: 1h
   * @option destination Destination path after login. Default: /user
   * @usage drush mli
   *   Generate magic link for user 1 (1 hour expiry)
   * @usage drush mli --expire=24h
   *   Generate magic link for user 1 (24 hour expiry)
   * @usage drush mli --expire=3d
   *   Generate magic link for user 1 (3 day expiry)
   * @usage drush mli --uid=123
   *   Generate magic link for user 123
   */
  public function generate($uid = null, array $options = ['expire' => '1h', 'destination' => '/user']): void {
    // Default to user 1 if no UID specified.
    $uid = $uid ?? 1;
    
    // Load and validate user.
    $user_storage = $this->entityTypeManager->getStorage('user');
    $account = $user_storage->load($uid);
    
    if (!$account instanceof UserInterface) {
      throw new \InvalidArgumentException("User with ID $uid does not exist.");
    }
    
    if (!$account->isActive()) {
      throw new \InvalidArgumentException("User {$account->getDisplayName()} (ID: $uid) is blocked.");
    }
    
    // Parse expiration time.
    $expire_seconds = $this->parseExpireTime($options['expire']);
    $destination = $options['destination'];
    
    // Generate persistent magic link.
    $url = $this->buildPersistentMagicLink($account, $expire_seconds, $destination);
    
    if (!$url) {
      throw new \RuntimeException('Failed to generate magic link. Check site configuration.');
    }
    
    // Display results.
    $expire_time = date('Y-m-d H:i:s', time() + $expire_seconds);
    $this->output()->writeln("Generated persistent magic link for {$account->getDisplayName()} (ID: $uid):");
    $this->output()->writeln("URL: $url");
    $this->output()->writeln("Expires: $expire_time");
    $this->output()->writeln("Destination: $destination");
    $this->output()->writeln('');
    $this->output()->writeln('Note: This link can be used multiple times until expiration.');
  }

  /**
   * Parse human-readable expire time to seconds.
   */
  private function parseExpireTime(string $expire): int {
    $expire = trim($expire);
    
    // Match pattern like "1h", "24h", "3d", "30m", etc.
    if (!preg_match('/^(\d+)([mhdw])$/', $expire, $matches)) {
      throw new \InvalidArgumentException("Invalid expire format '$expire'. Use formats like: 30m, 1h, 24h, 3d, 1w");
    }
    
    $value = (int) $matches[1];
    $unit = $matches[2];
    
    $multipliers = [
      'm' => 60,        // minutes
      'h' => 3600,      // hours  
      'd' => 86400,     // days
      'w' => 604800,    // weeks
    ];
    
    $seconds = $value * $multipliers[$unit];
    
    // Reasonable limits (1 minute to 4 weeks).
    if ($seconds < 60 || $seconds > 2419200) {
      throw new \InvalidArgumentException("Expire time must be between 1 minute and 4 weeks.");
    }
    
    return $seconds;
  }

  /**
   * Build a persistent magic link that doesn't expire on use.
   */
  private function buildPersistentMagicLink(UserInterface $account, int $expire_seconds, string $destination): ?string {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return null;
    }
    
    // Use a special nonce prefix to identify persistent links.
    $nonce = 'persist_' . bin2hex(random_bytes(8));
    $exp = time() + $expire_seconds;
    $sig = $this->signToken($uid, $exp, $nonce);
    
    if (!$sig) {
      return null;
    }
    
    $url = Url::fromRoute('magic_link.persistent_login', [
      'uid' => $uid,
      'exp' => $exp,
      'nonce' => $nonce,
      'sig' => $sig,
    ], [
      'absolute' => TRUE,
      'query' => ['destination' => $destination],
    ])->toString();
    
    return $url;
  }

  /**
   * Sign a token using the same method as MagicController.
   */
  private function signToken(int $uid, int $exp, string $nonce): ?string {
    $salt = (string) (Settings::get('hash_salt') ?? '');
    if ($salt === '') {
      return null;
    }
    
    $data = $uid . '|' . $exp . '|' . $nonce;
    $raw = hash_hmac('sha256', $data, $salt, true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }
}