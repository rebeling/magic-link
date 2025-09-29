<?php

namespace Drupal\magic_link\Commands;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Site\Settings;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Drush\Attributes as CLI;
use Drush\Commands\DrushCommands;

final class MagicLinkCommands extends DrushCommands {

  public function __construct(
    protected EntityTypeManagerInterface $entityTypeManager
  ) {
    parent::__construct();
  }

  #[CLI\Command(name: 'magic-link:generate', aliases: ['mli'])]
  #[CLI\Argument(name: 'uid', description: 'User ID to generate the link for (default: 1).')]
  #[CLI\Option(name: 'expire', description: 'Expiry time: 30m, 1h, 24h, 3d, 1w (default: 1h).')]
  #[CLI\Option(name: 'destination', description: 'Destination path after login (default: /user).')]
  #[CLI\Usage(name: 'drush mli', description: 'Generate magic link for user 1 (1 hour expiry).')]
  #[CLI\Usage(name: 'drush mli --expire=24h', description: 'Generate magic link for user 1 (24 hours).')]
  #[CLI\Usage(name: 'drush mli 123 --expire=3d', description: 'Generate magic link for user 123 (3 days).')]
  public function generate(
    ?int $uid = null,
    array $options = ['expire' => '1h', 'destination' => '/user']
  ): int {
    $uid = (int) ($uid ?? 1);

    $user_storage = $this->entityTypeManager->getStorage('user');
    /** @var \Drupal\user\UserInterface|null $account */
    $account = $user_storage->load($uid);

    if (!$account instanceof UserInterface) {
      $this->logger()->error("User with ID {$uid} does not exist.");
      return self::EXIT_FAILURE;
    }

    if (!$account->isActive()) {
      $this->logger()->error("User {$account->getDisplayName()} (ID: {$uid}) is blocked.");
      return self::EXIT_FAILURE;
    }

    $expire_seconds = $this->parseExpireTime((string) ($options['expire'] ?? '1h'));
    $destination = (string) ($options['destination'] ?? '/user');

    $url = $this->buildPersistentMagicLink($account, $expire_seconds, $destination);
    if (!$url) {
      $this->logger()->error('Failed to generate magic link. Check site hash_salt and routing.');
      return self::EXIT_FAILURE;
    }

    $expire_time = date('Y-m-d H:i:s', time() + $expire_seconds);
    $this->output()->writeln("Generated persistent magic link for {$account->getDisplayName()} (ID: {$uid}):");
    $this->output()->writeln("URL: {$url}");
    $this->output()->writeln("Expires: {$expire_time}");
    $this->output()->writeln("Destination: {$destination}");
    $this->output()->writeln('');
    $this->output()->writeln('Note: This link can be used multiple times until expiration.');

    return self::EXIT_SUCCESS;
  }

  private function parseExpireTime(string $expire): int {
    $expire = strtolower(trim($expire));
    if (!preg_match('/^(\d+)\s*([mhdw])$/', $expire, $m)) {
      $this->logger()->warning("Invalid expire format '{$expire}'. Using default 1h. Examples: 30m, 1h, 24h, 3d, 1w");
      return 3600;
    }
    [$all, $value, $unit] = $m;
    $value = (int) $value;
    $mult = ['m' => 60, 'h' => 3600, 'd' => 86400, 'w' => 604800][$unit] ?? 3600;
    $seconds = $value * $mult;

    // Guardrails: 1 minute – 4 weeks
    if ($seconds < 60 || $seconds > 60 * 60 * 24 * 28) {
      $this->logger()->warning('Expire time must be between 1 minute and 4 weeks. Using default 1h.');
      return 3600;
    }
    return $seconds;
  }

  private function buildPersistentMagicLink(UserInterface $account, int $expire_seconds, string $destination): ?string {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return null;
    }

    try {
      $nonce = 'persist_' . bin2hex(random_bytes(8));
    }
    catch (\Throwable $e) {
      $this->logger()->error('Could not generate secure nonce: ' . $e->getMessage());
      return null;
    }

    $exp = time() + $expire_seconds;
    $sig = $this->signToken($uid, $exp, $nonce);
    if (!$sig) {
      return null;
    }

    // Make sure this route name matches your magic_link.routing.yml.
    $url = Url::fromRoute('magic_link.persistent_login', [
      'uid' => $uid,
      'exp' => $exp,
      'nonce' => $nonce,
      'sig' => $sig,
    ], [
      'absolute' => true,
      'query' => ['destination' => $destination],
    ])->toString();

    return $url;
  }

  private function signToken(int $uid, int $exp, string $nonce): ?string {
    $salt = (string) (Settings::get('hash_salt') ?? '');
    if ($salt === '') {
      $this->logger()->error('hash_salt is empty. Set $settings["hash_salt"] in settings.php.');
      return null;
    }
    $data = $uid . '|' . $exp . '|' . $nonce;
    $raw = hash_hmac('sha256', $data, $salt, true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }
}
