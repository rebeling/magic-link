<?php

declare(strict_types=1);

namespace Drupal\Tests\magic_link\Unit\Stub;

use Drupal\Core\Mail\MailManagerInterface;

/**
 * Test mail manager stub.
 */
class TestMailManager implements MailManagerInterface {

  /**
   * Last sent mail payload (if any).
   *
   * @var array<string,mixed>|null
   */
  public ?array $lastMail = NULL;

  /**
   * Forces a failure result when TRUE.
   *
   * @var bool
   */
  public bool $forceFail = FALSE;

  /**
   * Records mail parameters and returns a result based on $forceFail.
   *
   * @param string $module
   *   The module name that sends the message.
   * @param string $key
   *   The message key identifying the mail variant.
   * @param string $to
   *   The recipient email address.
   * @param string $langcode
   *   The language code for the message.
   * @param array<string,mixed> $params
   *   Additional parameters passed to the mail plugin.
   * @param string|null $reply
   *   Optional reply-to address.
   * @param bool $send
   *   Whether the message should actually be sent.
   *
   * @return array{result:bool}
   *   An array containing the boolean send result.
   */
  public function mail(
    $module,
    $key,
    $to,
    $langcode,
    $params = [],
    $reply = NULL,
    $send = TRUE,
  ) {
    $this->lastMail = compact('module', 'key', 'to', 'langcode', 'params', 'reply', 'send');
    return ['result' => !$this->forceFail];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($plugin_id, $exception_on_invalid = TRUE) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinitions() {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($plugin_id) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function createInstance($plugin_id, array $configuration = []) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getInstance(array $options) {
    return NULL;
  }

}
