<?php

namespace Drupal\magic_link\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Magic Link settings.
 */
class MagicLinkSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'magic_link_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['magic_link.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('magic_link.settings');

    $form['link_expiry'] = [
      '#type' => 'number',
      '#title' => $this->t('Magic link expiry time'),
      '#description' => $this->t('How long magic links remain valid (in minutes). Default: 15 minutes.'),
      '#default_value' => $config->get('link_expiry') ?? 15,
      '#min' => 1,
      '#max' => 1440, // 24 hours max
      '#step' => 1,
      '#required' => TRUE,
      '#field_suffix' => $this->t('minutes'),
    ];

    $form['neutral_validation'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use neutral email validation'),
      '#description' => $this->t('If checked, email validation will not reveal if an account exists (more secure but less user-friendly).'),
      '#default_value' => $config->get('neutral_validation') ?? FALSE,
    ];

    // Email configuration section.
    $form['email'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Configuration'),
      '#open' => TRUE,
    ];

    $site_config = $this->config('system.site');
    $site_mail = $site_config->get('mail') ?: $this->t('No site email configured');
    $site_name = $site_config->get('name') ?: $this->t('Drupal');

    $form['email']['from_name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('From name'),
      '#description' => $this->t('Optional override for sender name. Leave empty to use site name: @name', ['@name' => $site_name]),
      '#default_value' => $config->get('email.from_name') ?? '',
      '#maxlength' => 255,
    ];

    $form['email']['from_email'] = [
      '#type' => 'email',
      '#title' => $this->t('From email'),
      '#description' => $this->t('Optional override for sender email. Leave empty to use site email: @email', ['@email' => $site_mail]),
      '#default_value' => $config->get('email.from_email') ?? '',
    ];

    $form['email']['subject_template'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Subject template'),
      '#description' => $this->t('Email subject line. Available tokens: [user:name], [site:name], [magic_link:url]'),
      '#default_value' => $config->get('email.subject_template') ?? 'Your magic link for [site:name]',
      '#required' => TRUE,
      '#maxlength' => 255,
    ];

    $form['email']['body_template'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Body template (HTML)'),
      '#description' => $this->t('Email body content in HTML format. Available tokens: [user:name], [site:name], [magic_link:url]. A plain-text version will be auto-generated.'),
      '#default_value' => $config->get('email.body_template') ?? $this->getDefaultBodyTemplate(),
      '#required' => TRUE,
      '#rows' => 10,
    ];

    $form['email']['tokens'] = [
      '#type' => 'details',
      '#title' => $this->t('Available tokens'),
      '#open' => FALSE,
    ];

    $form['email']['tokens']['list'] = [
      '#markup' => '<ul>' .
        '<li><code>[user:name]</code> - ' . $this->t('Display name of the user') . '</li>' .
        '<li><code>[site:name]</code> - ' . $this->t('Site name') . '</li>' .
        '<li><code>[magic_link:url]</code> - ' . $this->t('The magic link URL') . '</li>' .
        '</ul>',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $expiry = $form_state->getValue('link_expiry');
    if ($expiry < 1 || $expiry > 1440) {
      $form_state->setErrorByName('link_expiry', $this->t('Expiry time must be between 1 and 1440 minutes (24 hours).'));
    }
    parent::validateForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $email_values = $form_state->getValue('email');
    
    $this->config('magic_link.settings')
      ->set('link_expiry', (int) $form_state->getValue('link_expiry'))
      ->set('neutral_validation', (bool) $form_state->getValue('neutral_validation'))
      ->set('email.from_name', trim((string) $email_values['from_name']))
      ->set('email.from_email', trim((string) $email_values['from_email']))
      ->set('email.subject_template', trim((string) $email_values['subject_template']))
      ->set('email.body_template', trim((string) $email_values['body_template']))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Get default HTML body template.
   */
  private function getDefaultBodyTemplate(): string {
    return '<p>Hello [user:name],</p>

<p>Use this one-time link to log in to [site:name]:</p>

<p><a href="[magic_link:url]">[magic_link:url]</a></p>

<p>This link works once and may expire soon.</p>

<p>If you did not request this, you can ignore this email.</p>

<p>— [site:name]</p>';
  }
}