<?php

declare(strict_types=1);

namespace Drupal\Tests\magic_link\Unit\Stub;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserInterface;

/**
 * Entity type manager stub for users.
 */
class TestEntityTypeManager implements EntityTypeManagerInterface {

  public const KNOWN_EMAIL = 'exists@example.com';
  public const ADMIN_EMAIL = 'admin@example.com';

  /**
   * Known user reference.
   *
   * @var \Drupal\user\UserInterface|null
   */
  public static ?UserInterface $knownUser = NULL;

  /**
   * Admin user reference.
   *
   * @var \Drupal\user\UserInterface|null
   */
  public static ?UserInterface $adminUser = NULL;

  /**
   * Provides a minimal user storage stub.
   */
  public function getStorage($entity_type) {
    if ($entity_type !== 'user') {
      throw new \InvalidArgumentException('Unexpected entity type');
    }
    return new class {

      /**
       * Loads a user by ID.
       *
       * @param int $id
       *   The user ID.
       *
       * @return \Drupal\user\UserInterface|null
       *   The user, or NULL if not found.
       */
      public function load($id) {
        return (int) $id === 1 ? TestEntityTypeManager::$adminUser : NULL;
      }

      /**
       * Loads a user by properties.
       *
       * @param array $properties
       *   The user properties.
       *
       * @return \Drupal\user\UserInterface|null
       *   The user, or NULL if not found.
       */
      public function loadByProperties(array $properties = []) {
        if (!empty($properties['mail'])
          && $properties['mail'] === TestEntityTypeManager::KNOWN_EMAIL
          && (int) ($properties['status'] ?? 0) === 1) {
          return [TestEntityTypeManager::$knownUser];
        }
        return [];
      }

    };
  }

  /**
   * {@inheritdoc}
   */
  public function getDefinition($entity_type_id, $exception_on_invalid = TRUE) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasDefinition($entity_type_id) {
    return $entity_type_id === 'user';
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
  public function clearCachedDefinitions() {}

  /**
   * {@inheritdoc}
   */
  public function getHandler($entity_type, $handler_type) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createHandlerInstance($class, $definition = NULL) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getAccessControlHandler($entity_type) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getRouteProviders($entity_type) {
    return [];
  }

  /**
   * {@inheritdoc}
   */
  public function getListBuilder($entity_type) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormObject($entity_type, $operation) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getViewBuilder($entity_type) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function hasHandler($entity_type, $handler_type) {
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

  /**
   * {@inheritdoc}
   */
  public function useCaches($use_caches = FALSE) {}

}
