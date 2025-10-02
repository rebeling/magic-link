<?php

namespace Drupal\magic_link\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\user\UserInterface;

/**
 * Service for magic link email operations.
 */
class MagicLinkMailService {

  /**
   * Constructs a MagicLinkMailService.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \Drupal\Core\Mail\MailManagerInterface $mailManager
   *   The mail manager.
   * @param \Drupal\Core\Language\LanguageManagerInterface $languageManager
   *   The language manager.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entityTypeManager
   *   The entity type manager.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected MailManagerInterface $mailManager,
    protected LanguageManagerInterface $languageManager,
    protected EntityTypeManagerInterface $entityTypeManager,
  ) {}

  /**
   * Send magic link email to user.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param string $url
   *   The magic link URL.
   *
   * @return bool
   *   TRUE if email was sent successfully, FALSE otherwise.
   */
  public function sendMagicLinkEmail(UserInterface $account, string $url): bool {
    $langcode = $account->getPreferredLangcode() ?: $this->languageManager->getDefaultLanguage()->getId();
    $to = $account->getEmail();
    $params = [
      'account' => $account,
      'url' => $url,
    ];

    $result = (array) $this->mailManager->mail('magic_link', 'magic_link', $to, $langcode, $params);
    $ok = !empty($result['result']);

    if (!$ok) {
      // Fallback to PHP mail() only in production.
      $defaultInterface = (string) ($this->configFactory->get('system.mail')->get('interface.default') ?? '');
      if ($defaultInterface === 'php_mail') {
        return $this->sendViaPhpMail($account, $url);
      }
    }

    return $ok;
  }

  /**
   * Send email using PHP's mail() function.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param string $url
   *   The magic link URL.
   *
   * @return bool
   *   TRUE if email was sent successfully, FALSE otherwise.
   */
  protected function sendViaPhpMail(UserInterface $account, string $url): bool {
    $config = $this->configFactory->get('magic_link.settings');
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
    return @mail($to, $subject, $body_text, $headers) !== FALSE;
  }

  /**
   * Get configured sender information.
   *
   * @return array
   *   Array with [name, email].
   */
  public function getSenderInfo(): array {
    $config = $this->configFactory->get('magic_link.settings');
    $site_config = $this->configFactory->get('system.site');

    // Get configured or fallback values.
    $from_name = trim((string) $config->get('email.from_name'));
    if ($from_name === '') {
      $from_name = $site_config->get('name') ?: 'Drupal';
    }

    $from_email = trim((string) $config->get('email.from_email'));
    if ($from_email === '') {
      // Try admin user first, then site email.
      $admin_user = $this->entityTypeManager->getStorage('user')->load(1);
      $from_email = ($admin_user && $admin_user->getEmail())
        ? $admin_user->getEmail()
        : ($site_config->get('mail') ?: 'noreply@localhost');
    }

    return [$from_name, $from_email];
  }

  /**
   * Replace tokens in templates.
   *
   * @param string $template
   *   The template string.
   * @param \Drupal\user\UserInterface $account
   *   The user account.
   * @param string $url
   *   The magic link URL.
   *
   * @return string
   *   The template with tokens replaced.
   */
  public function replaceTokens(string $template, UserInterface $account, string $url): string {
    $site_config = $this->configFactory->get('system.site');
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
   *
   * @param string $html
   *   The HTML string.
   *
   * @return string
   *   Plain text version.
   */
  public function htmlToPlainText(string $html): string {
    // Convert common HTML elements to text equivalents.
    $html = str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html);
    $html = str_replace(['<p>', '</div>'], "\n", $html);

    // Strip HTML tags and decode entities.
    $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

    // Normalize whitespace.
    $text = preg_replace('/\n\s*\n/', "\n\n", $text);
    $text = preg_replace('/[ \t]+/', ' ', $text);

    return trim($text);
  }

  /**
   * Get default HTML body template.
   *
   * @return string
   *   The default template.
   */
  public function getDefaultBodyTemplate(): string {
    return '<p>Hello [user:name],</p>

<p>Use this one-time link to log in to [site:name]:</p>

<p><a href="[magic_link:url]">[magic_link:url]</a></p>

<p>This link works once and may expire soon.</p>

<p>If you did not request this, you can ignore this email.</p>

<p>— [site:name]</p>';
  }

}
