<?php

namespace Drupal\magic_link\Controller;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Drupal\magic_link\Service\MagicLinkMailService;
use Drupal\magic_link\Service\MagicLinkTokenService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\user\UserInterface as CoreUserInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for magic-link validation and request.
 */
class MagicController extends ControllerBase {

  /**
   * The email validator interface.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  protected EmailValidatorInterface $emailValidator;

  /**
   * The key-value expirable factory.
   *
   * @var \Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface
   */
  protected $keyValueExpirable;

  /**
   * The magic link token service.
   *
   * @var \Drupal\magic_link\MagicLinkTokenService
   */
  protected MagicLinkTokenService $tokenService;

  /**
   * The magic link mail service.
   *
   * @var \Drupal\magic_link\MagicLinkMailService
   */
  protected MagicLinkMailService $mailService;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    $instance = new self();
    $instance->emailValidator = $container->get('email.validator');
    $instance->keyValueExpirable = $container->get('keyvalue.expirable');
    $instance->tokenService = $container->get('magic_link.token');
    $instance->mailService = $container->get('magic_link.mail');
    // ControllerBase provides configFactory and languageManager properties.
    $instance->configFactory = $container->get('config.factory');
    $instance->languageManager = $container->get('language_manager');
    return $instance;
  }

  /**
   * Validates an email for magic-link use and returns a small HTML fragment.
   */
  public function validate(Request $request): Response {
    $email = trim((string) ($request->get('magic_email') ?? $request->query->get('magic_email', '')));

    $markup = '';
    $status = 'error';
    if ($email === '') {
      $markup = '<div class="messages messages--warning">' . (string) $this->t('Enter your email address.') . '</div>';
      $status = 'warn';
    }
    elseif (!$this->emailValidator->isValid($email)) {
      $markup = '<div class="messages messages--error">' . (string) $this->t('Invalid email format') . '</div>';
    }
    else {
      // Always use neutral validation: don't reveal account existence.
      $markup = '<div class="messages messages--status">' . (string) $this->t('Valid email format.') . '</div>';
      $status = 'ok';
    }

    $response = new Response(Markup::create($markup));
    $response->headers->set('Vary', 'HX-Request');
    // Optional: indicate validation state for custom client handling.
    $response->headers->set('HX-Trigger', json_encode(['magic-link-validate' => ['status' => $status]]));
    return $response;
  }

  /**
   * Generates a one-time login link and redirects the browser (via HTMX).
   */
  public function request(Request $request): Response {
    // CSRF token is automatically validated by routing requirement,
    // but we can add additional validation here if needed.
    $email = trim((string) ($request->request->get('magic_email') ?? $request->get('magic_email', '')));

    // Validate email format first and show error if invalid.
    if ($email === '' || !$this->emailValidator->isValid($email)) {
      $html = '<div class="messages messages--error">'
        . (string) $this->t('Invalid email format')
        . '</div>';
      return $this->fragment($html);
    }

    // Always return a neutral confirmation for privacy (no enumeration),
    // regardless of the provided email value/format. Attempt delivery only
    // if the email looks valid and an active account exists.
    $proceed = TRUE;

    $account = $proceed ? $this->loadActiveUserByEmail($email) : NULL;
    if ($account) {
      // Try to obtain a one-time login URL.
      // Preferred: Drupal API, fallback to Drush.
      $login_url = $this->generateOneTimeLoginUrl($account, $request);
      if (is_string($login_url) && $login_url !== '') {
        // Always send the link via email to the account's address.
        try {
          $this->sendMagicLinkEmail($account, $login_url);
        }
        catch (\Throwable $e) {
          // Log but keep response neutral.
          $this->getLogger('magic_link')->warning('Failed to send magic link email: @msg', ['@msg' => $e->getMessage()]);
        }
      }
      else {
        // Log generation failure; keep response neutral.
        $this->getLogger('magic_link')->warning('Magic link generation failed for uid @uid.', ['@uid' => $account->id()]);
      }
    }

    // Always return a neutral confirmation, regardless of account existence.
    return $this->neutralConfirmation();
  }

  /**
   * Helper: Create a small HTML fragment response with standard headers.
   */
  protected function fragment(string $html): Response {
    $response = new Response(Markup::create($html));
    $response->headers->set('Vary', 'HX-Request');
    return $response;
  }

  /**
   * Neutral confirmation fragment for POST response.
   */
  protected function neutralConfirmation(): Response {
    $html = '<div class="messages messages--status">'
      . (string) $this->t('If the email exists in our system, a magic link was sent')
      . '</div>';
    return $this->fragment($html);
  }

  /**
   * Load an active user by email.
   */
  protected function loadActiveUserByEmail(string $email): ?UserInterface {
    $accounts = $this->entityTypeManager()->getStorage('user')->loadByProperties([
      'mail' => $email,
      'status' => 1,
    ]);
    $account = $accounts ? reset($accounts) : NULL;
    return $account instanceof UserInterface ? $account : NULL;
  }

  /**
   * Generate an internal one-time login URL (no core reset UI).
   */
  protected function generateOneTimeLoginUrl(UserInterface $account, Request $request): ?string {
    // Always use internal one-time login link that bypasses core reset UI.
    try {
      return $this->buildInternalOneTimeLoginUrl($account, $request);
    }
    catch (\Throwable $e) {
      // If anything goes wrong, do not fall back to core reset/Drush.
      return NULL;
    }
  }

  /**
   * Send the magic-link email to the user.
   */
  protected function sendMagicLinkEmail(UserInterface $account, string $url): void {
    $this->mailService->sendMagicLinkEmail($account, $url);
  }

  /**
   * Build an internal HMAC-based one-time login URL without reset UI.
   */
  protected function buildInternalOneTimeLoginUrl(UserInterface $account, Request $request): ?string {
    $uid = (int) $account->id();
    if ($uid <= 0) {
      return NULL;
    }
    $nonce = bin2hex(random_bytes(8));
    $exp = time() + $this->getLinkExpirySeconds();
    $sig = $this->tokenService->signToken($uid, $exp, $nonce);
    if (!$sig) {
      return NULL;
    }
    $url = Url::fromRoute('magic_link.ott_login', [
      'uid' => $uid,
      'exp' => $exp,
      'nonce' => $nonce,
      'sig' => $sig,
    ], [
      'absolute' => TRUE,
      'query' => [
        // Default to user profile; can be overridden by request param.
        'destination' => $request->query->get('destination', '/user'),
      ],
    ])->toString();
    return $url;
  }

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
  protected function signToken(int $uid, int $exp, string $nonce): ?string {
    return $this->tokenService->signToken($uid, $exp, $nonce);
  }

  /**
   * Verify magic link token signature and enforce one-time use.
   *
   * @param int $uid
   *   User ID from the token.
   * @param int $exp
   *   Expiration timestamp.
   * @param string $nonce
   *   Nonce from the token.
   * @param string $sig
   *   Signature to verify.
   * @param bool $enforce_one_time
   *   Whether to enforce one-time use via keyvalue store.
   *
   * @return bool
   *   TRUE if token is valid and not expired, FALSE otherwise.
   */
  protected function verifyToken(int $uid, int $exp, string $nonce, string $sig, bool $enforce_one_time = TRUE): bool {
    // Verify signature and expiration using token service.
    if (!$this->tokenService->verifyTokenSignature($uid, $exp, $nonce, $sig)) {
      return FALSE;
    }

    // Enforce one-time use via expirable keyvalue store.
    if ($enforce_one_time) {
      try {
        $kv = $this->keyValueExpirable->get('magic_link_ott');
        if ($kv->get($sig)) {
          return FALSE;
        }
        // Store until expiration.
        $ttl = max(1, $exp - time());
        $kv->setWithExpire($sig, 1, $ttl);
      }
      catch (\Throwable $e) {
        // If storage is unavailable, still proceed with HMAC+expiry only.
      }
    }
    return TRUE;
  }

  /**
   * Handle internal one-time login and redirect.
   */
  public function oneTimeLogin(Request $request, int $uid, int $exp, string $nonce, string $sig): Response {
    // Verify token.
    if (!$this->verifyToken($uid, $exp, $nonce, $sig)) {
      return $this->fragment('<div class="messages messages--error">' . (string) $this->t('Invalid or expired link.') . '</div>');
    }
    // Load active user and log in.
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account instanceof CoreUserInterface || !$account->isActive()) {
      return $this->fragment('<div class="messages messages--error">' . (string) $this->t('Invalid account.') . '</div>');
    }
    // Finalize login without adding messages.
    if (function_exists('user_login_finalize')) {
      user_login_finalize($account);
    }
    // Redirect to destination.
    $dest = (string) $request->query->get('destination', '/');
    if ($dest === '') {
      $dest = '/';
    }
    return new RedirectResponse($dest);
  }

  /**
   * Handle persistent login that doesn't expire after use.
   */
  public function persistentLogin(Request $request, int $uid, int $exp, string $nonce, string $sig): Response {
    // Check if this is a persistent link by nonce prefix.
    if (!str_starts_with($nonce, 'persist_')) {
      return $this->fragment('<div class="messages messages--error">' . (string) $this->t('Invalid link format.') . '</div>');
    }

    // Verify token without enforcing one-time use.
    if (!$this->verifyToken($uid, $exp, $nonce, $sig, FALSE)) {
      return $this->fragment('<div class="messages messages--error">' . (string) $this->t('Invalid or expired link.') . '</div>');
    }

    // Load active user and log in.
    $account = $this->entityTypeManager()->getStorage('user')->load($uid);
    if (!$account instanceof CoreUserInterface || !$account->isActive()) {
      return $this->fragment('<div class="messages messages--error">' . (string) $this->t('Invalid account.') . '</div>');
    }

    // Finalize login without adding messages.
    if (function_exists('user_login_finalize')) {
      user_login_finalize($account);
    }

    // Redirect to destination.
    $dest = (string) $request->query->get('destination', '/');
    if ($dest === '') {
      $dest = '/';
    }
    return new RedirectResponse($dest);
  }

  /**
   * Get configured link expiry time in seconds.
   */
  protected function getLinkExpirySeconds(): int {
    $config = $this->config('magic_link.settings');
    $minutes = (int) ($config->get('link_expiry') ?? 15);
    return $minutes * 60;
  }

  /**
   * Check if neutral validation is enabled.
   */
  protected function useNeutralValidation(): bool {
    $config = $this->config('magic_link.settings');
    return (bool) ($config->get('neutral_validation') ?? FALSE);
  }

}
