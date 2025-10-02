<?php

declare(strict_types=1);

namespace Drupal\Tests\magic_link\Unit;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Tests\UnitTestCase;

/**
 * Base test class for Magic Link module tests.
 *
 * Provides common mock factory methods and setup utilities.
 */
abstract class MagicLinkTestBase extends UnitTestCase {

  /**
   * Creates a ConfigFactory mock with default settings.
   *
   * @param array $overrides
   *   Optional configuration overrides.
   *
   * @return \Drupal\Core\Config\ConfigFactoryInterface
   *   Mocked config factory.
   */
  protected function createConfigFactory(array $overrides = []): ConfigFactoryInterface {
    $defaults = [
      'system.mail' => ['interface.default' => 'php_mail'],
      'system.site' => ['mail' => 'noreply@example.com', 'name' => 'Test Site'],
      'magic_link.settings' => [
        'link_expiry' => 15,
        'neutral_validation' => FALSE,
        'email.from_name' => '',
        'email.from_email' => '',
        'email.subject_template' => 'Your magic link for [site:name]',
        'email.body_template' => '<p>Hello [user:name],</p><p><a href="[magic_link:url]">Login</a></p>',
      ],
    ];
    $settings = array_merge($defaults, $overrides);

    $factory = $this->getMockBuilder(ConfigFactoryInterface::class)->getMock();
    $factory->method('get')->willReturnCallback(function ($name) use ($settings) {
      $values = $settings[$name] ?? [];
      $config = $this->getMockBuilder(ImmutableConfig::class)
        ->disableOriginalConstructor()
        ->getMock();
      $config->method('get')->willReturnCallback(fn($key) => $values[$key] ?? NULL);
      return $config;
    });

    return $factory;
  }

  /**
   * Creates a LanguageManager mock.
   *
   * @param string $langcode
   *   Language code (default: 'en').
   *
   * @return \Drupal\Core\Language\LanguageManagerInterface
   *   Mocked language manager.
   */
  protected function createLanguageManager(string $langcode = 'en'): LanguageManagerInterface {
    $language = $this->getMockBuilder(LanguageInterface::class)->getMock();
    $language->method('getId')->willReturn($langcode);

    $manager = $this->getMockBuilder(LanguageManagerInterface::class)->getMock();
    $manager->method('getDefaultLanguage')->willReturn($language);

    return $manager;
  }

  /**
   * Creates a key-value expirable factory stub.
   *
   * @return object
   *   Key-value factory stub.
   */
  protected function createKeyValueExpirable(): object {
    return new class {

      /**
       * Gets a key-value store.
       *
       * @param string $collection
       *   Collection name.
       *
       * @return object
       *   Key-value store.
       */
      public function get($collection) {
        return new class() {

          /**
           * Storage for temporary data.
           *
           * @var array
           */
          private array $storage = [];

          /**
           * Gets a value from storage.
           *
           * @param string $key
           *   The key.
           *
           * @return mixed
           *   The value or NULL.
           */
          public function get($key) {
            return $this->storage[$key] ?? NULL;
          }

          /**
           * Sets a value with expiration.
           *
           * @param string $key
           *   The key.
           * @param mixed $value
           *   The value.
           * @param int $ttl
           *   Time to live in seconds.
           */
          public function setWithExpire($key, $value, $ttl): void {
            $this->storage[$key] = $value;
          }

        };
      }

    };
  }

  /**
   * Sets up a minimal global Drupal container for testing.
   *
   * @param \Drupal\Core\DependencyInjection\ContainerBuilder $container
   *   The container to populate.
   */
  protected function setupGlobalContainer(ContainerBuilder $container): void {
    // phpcs:disable DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::setContainer($container);
    // phpcs:enable
  }

}
