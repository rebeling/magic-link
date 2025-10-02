<?php

declare(strict_types=1);

namespace Drupal\Tests\magic_link\Unit\Stub;

use Drupal\Core\Session\AccountInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Psr\Log\LoggerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;

/**
 * Logger factory stub.
 */
class TestLoggerFactory implements LoggerChannelFactoryInterface {

  /**
   * {@inheritdoc}
   */
  public function get($channel) {
    return new class() implements LoggerChannelInterface, LoggerInterface {

      /**
       * The user.
       *
       * @var \Drupal\Core\Session\AccountInterface|null
       */
      private $user;

      /**
       * {@inheritdoc}
       */
      public function setRequestStack(?RequestStack $requestStack = NULL): void {}

      /**
       * {@inheritdoc}
       */
      public function setCurrentUser(?AccountInterface $account = NULL): void {}

      /**
       * {@inheritdoc}
       */
      public function setLoggers(array $loggers): void {}

      /**
       * {@inheritdoc}
       */
      public function emergency(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function alert(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function critical(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function error(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function warning(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function notice(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function info(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function debug(\Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function log($level, \Stringable|string $message, array $context = []): void {}

      /**
       * {@inheritdoc}
       */
      public function getName() {
        return 'test';
      }

    };
  }

  /**
   * {@inheritdoc}
   */
  public function setCurrentUser($account) {}

  /**
   * {@inheritdoc}
   */
  public function addLogger($logger, $channel = NULL) {}

}
