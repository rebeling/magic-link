<?php

namespace Drupal\magic_link\Controller;

use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Site\Settings;
use Drupal\user\UserInterface as CoreUserInterface;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for magic-link validation and request.
 */
class MagicController extends ControllerBase {

  /** @var \Drupal\Component\Utility\EmailValidatorInterface */
  protected EmailValidatorInterface $emailValidator;
  /** @var \Drupal\Core\Mail\MailManagerInterface */
  protected MailManagerInterface $mailManager;

  public static function create(ContainerInterface $container): self {
    $instance = new self();
    $instance->emailValidator = $container->get('email.validator');
    $instance->mailManager = $container->get('plugin.manager.mail');
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
      if ($this->useNeutralValidation()) {
        // Neutral validation: don't reveal account existence.
        $markup = '<div class="messages messages--status">' . (string) $this->t('Looks good — you can request a magic link.') . '</div>';
        $status = 'ok';
      }
      else {
        // Check if account exists for better UX.
        $account = $this->loadActiveUserByEmail($email);
        if ($account) {
          $markup = '<div class="messages messages--status">' . (string) $this->t('Account found — you can request a magic link.') . '</div>';
          $status = 'ok';
        }
        else {
          $markup = '<div class="messages messages--warning">' . (string) $this->t('No account found with this email address.') . '</div>';
          $status = 'warn';
        }
      }
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
    // Always return a neutral confirmation for privacy (no enumeration),
    // regardless of the provided email value/format. Attempt delivery only
    // if the email looks valid and an active account exists.
    $proceed = ($email !== '' && $this->emailValidator->isValid($email));

    $account = $proceed ? $this->loadActiveUserByEmail($email) : NULL;
    if ($account) {
      // Try to obtain a one-time login URL. Preferred: Drupal API, fallback to Drush.
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
    // If Drupal is not configured to use php_mail, prefer the known-good
    // path that matches local DDEV Mailpit wiring.
    $defaultInterface = (string) (\Drupal::config('system.mail')->get('interface.default') ?? '');
    if ($defaultInterface !== 'php_mail') {
      $this->sendViaPhpMail($account, $url);
      return;
    }

    $mailManager = $this->mailManager;
    $langcode = $account->getPreferredLangcode() ?: \Drupal::languageManager()->getDefaultLanguage()->getId();
    $to = $account->getEmail();
    $params = [
      'account' => $account,
      'url' => $url,
    ];
    $result = (array) $mailManager->mail('magic_link', 'magic_link', $to, $langcode, $params);
    $ok = !empty($result['result']);
    if (!$ok) {
      // Fallback: Use PHP's mail() directly.
      $this->sendViaPhpMail($account, $url);
    }
  }

  /**
   * Replace tokens in templates.
   */
  protected function replaceTokens(string $template, UserInterface $account, string $url): string {
    $site_config = \Drupal::config('system.site');
    $site_name = $site_config->get('name') ?: 'Drupal';
    $user_name = $account->getDisplayName() ?: $account->getAccountName();
    
    $tokens = [
      '[user:name]' => $user_name,
      '[site:name]' => $site_name,
      '[magic_link:url]' => $url,
    ];
    
    return str_replace(array_keys($tokens), array_values($tokens), $template);
  }

  /**
   * Convert HTML to plain text.
   */
  protected function htmlToPlainText(string $html): string {
    // Strip HTML tags and decode entities.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Convert common HTML elements to text equivalents.
    $html = str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html);
    $html = str_replace(['<p>', '</div>'], "\n", $html);
    
    // Re-strip tags after replacements.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Normalize whitespace.
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);
    $text = trim($text);
    
    return $text;
  }

  /**
   * Get configured sender information.
   */
  protected function getSenderInfo(): array {
    $config = \Drupal::config('magic_link.settings');
    $site_config = \Drupal::config('system.site');
    
    // Get configured or fallback values.
    $from_name = trim((string) $config->get('email.from_name'));
    if ($from_name === '') {
      $from_name = $site_config->get('name') ?: 'Drupal';
    }
    
    $from_email = trim((string) $config->get('email.from_email'));
    if ($from_email === '') {
      // Try admin user first, then site email.
      $admin_user = \Drupal\user\Entity\User::load(1);
      $from_email = ($admin_user && $admin_user->getEmail()) 
        ? $admin_user->getEmail() 
        : ($site_config->get('mail') ?: 'noreply@localhost');
    }
    
    return [$from_name, $from_email];
  }

  /**
   * Fallback sender using PHP's mail(), useful in DDEV with Mailpit sendmail.
   */
  protected function sendViaPhpMail(UserInterface $account, string $url): void {
    $config = \Drupal::config('magic_link.settings');
    [$from_name, $from_email] = $this->getSenderInfo();
    
    // Get configured templates.
    $subject_template = $config->get('email.subject_template') ?: 'Your magic link for [site:name]';
    $body_template = $config->get('email.body_template') ?: $this->getDefaultBodyTemplate();
    
    // Replace tokens.
    $subject = $this->replaceTokens($subject_template, $account, $url);
    $body_html = $this->replaceTokens($body_template, $account, $url);
    $body_text = $this->htmlToPlainText($body_html);
    
    $headers = implode("\r\n", [
        "From: $from_name <$from_email>",
        'Content-Type: text/plain; charset=UTF-8',
        'X-Auth-Magic: 1',
    ]);

    $to = $account->getEmail();
    $ok = @mail($to, $subject, $body_text, $headers);
    if (!$ok) {
      throw new \RuntimeException('PHP mail() fallback failed.');
    }
  }

  /**
   * Get default HTML body template (same as in settings form).
   */
  private function getDefaultBodyTemplate(): string {
    return '<p>Hello [user:name],</p>

<p>Use this one-time link to log in to [site:name]:</p>

<p><a href="[magic_link:url]">[magic_link:url]</a></p>

<p>This link works once and may expire soon.</p>

<p>If you did not request this, you can ignore this email.</p>

<p>— [site:name]</p>';
  }

  // No silent redirect or core reset destinations needed with internal OTT.

  /**
   * Build an internal HMAC-based one-time login URL without reset UI.
   */
  protected function buildInternalOneTimeLoginUrl(UserInterface $account, Request $request): ?string {
    $uid = (int) $account->id();
    if ($uid <= 0) { return NULL; }
    $nonce = bin2hex(random_bytes(8));
    $exp = time() + $this->getLinkExpirySeconds();
    $sig = $this->signToken($uid, $exp, $nonce);
    if (!$sig) { return NULL; }
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

  protected function signToken(int $uid, int $exp, string $nonce): ?string {
    $salt = (string) (Settings::get('hash_salt') ?? '');
    if ($salt === '') { return NULL; }
    $data = $uid . '|' . $exp . '|' . $nonce;
    $raw = hash_hmac('sha256', $data, $salt, true);
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
  }

  protected function verifyToken(int $uid, int $exp, string $nonce, string $sig, bool $enforce_one_time = true): bool {
    if ($exp < time()) { return false; }
    $expected = $this->signToken($uid, $exp, $nonce);
    if (!$expected) { return false; }
    // Constant-time compare
    if (!hash_equals($expected, $sig)) { return false; }
    
    // Enforce one-time use via expirable keyvalue store (only for non-persistent links).
    if ($enforce_one_time) {
      try {
        $kv = \Drupal::service('keyvalue.expirable')->get('magic_link_ott');
        if ($kv->get($sig)) { return false; }
        // Store until expiration.
        $ttl = max(1, $exp - time());
        $kv->setWithExpire($sig, 1, $ttl);
      }
      catch (\Throwable $e) {
        // If storage is unavailable, still proceed with HMAC+expiry only.
      }
    }
    return true;
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
    if ($dest === '') { $dest = '/'; }
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
    if (!$this->verifyToken($uid, $exp, $nonce, $sig, false)) {
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
    if ($dest === '') { $dest = '/'; }
    return new RedirectResponse($dest);
  }

  /**
   * Get configured link expiry time in seconds.
   */
  protected function getLinkExpirySeconds(): int {
    $config = \Drupal::config('magic_link.settings');
    $minutes = (int) ($config->get('link_expiry') ?? 15);
    return $minutes * 60;
  }

  /**
   * Check if neutral validation is enabled.
   */
  protected function useNeutralValidation(): bool {
    $config = \Drupal::config('magic_link.settings');
    return (bool) ($config->get('neutral_validation') ?? FALSE);
  }
}
