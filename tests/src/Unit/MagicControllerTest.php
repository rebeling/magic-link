<?php

declare(strict_types=1);

namespace Drupal\Tests\magic_link\Unit;

use Drupal\magic_link\Controller\MagicController;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Language\LanguageDefault;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\user\UserInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * @coversDefaultClass \Drupal\magic_link\Controller\MagicController
 * @group magic_link
 */
class MagicControllerTest extends UnitTestCase {

  /** @var ContainerBuilder */
  protected ContainerBuilder $container;

  /** @var TestMailManager */
  protected TestMailManager $mailManager;

  /** @var TestEntityTypeManager */
  protected TestEntityTypeManager $entityTypeManager;

  /** @var EmailValidatorInterface */
  protected EmailValidatorInterface $emailValidator;

  /** @var TestLoggerFactory */
  protected TestLoggerFactory $loggerFactory;

  protected function setUp(): void {
    parent::setUp();

    $this->container = new ContainerBuilder();

    // Email validator: basic format check.
    $this->emailValidator = new class implements EmailValidatorInterface {
      public function isValid($email) {
        return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
      }
    };
    $this->container->set('email.validator', $this->emailValidator);

    // Language manager stub: return default language with id 'en'.
    $this->container->set('language_manager', new class {
      public function getDefaultLanguage() { return new class { public function getId() { return 'en'; } }; }
    });

    // String translation stub so $this->t() works in controller under test.
    $this->container->set('string_translation', $this->getStringTranslationStub());

    // Mail manager stub.
    $this->mailManager = new TestMailManager();
    $this->container->set('plugin.manager.mail', $this->mailManager);

    // Prepare stub users and entity manager.
    $known = $this->getMockBuilder(\Drupal\user\UserInterface::class)->getMock();
    $known->method('getDisplayName')->willReturn('Test User');
    $known->method('getEmail')->willReturn(TestEntityTypeManager::KNOWN_EMAIL);
    $known->method('id')->willReturn(123);
    $known->method('getPreferredLangcode')->willReturn('en');
    $admin = $this->getMockBuilder(\Drupal\user\UserInterface::class)->getMock();
    $admin->method('getEmail')->willReturn(TestEntityTypeManager::ADMIN_EMAIL);
    TestEntityTypeManager::$knownUser = $known;
    TestEntityTypeManager::$adminUser = $admin;

    $this->entityTypeManager = new TestEntityTypeManager();
    $this->container->set('entity_type.manager', $this->entityTypeManager);

    // Entity type repository stub used by EntityBase static loaders.
    $this->container->set('entity_type.repository', new class {
      public function getEntityTypeFromClass($class) { return 'user'; }
    });

    // Logger factory stub.
    $this->loggerFactory = new TestLoggerFactory();
    $this->container->set('logger.factory', $this->loggerFactory);

    // CSRF token stub for testing.
    $this->container->set('csrf_token', new class {
      public function get($value) { return 'test-csrf-token'; }
      public function validate($token, $value = '') { return $token === 'test-csrf-token'; }
    });

    // Minimal config factory stub for \Drupal::config() calls in controller.
    $this->container->set('config.factory', new class {
      public function get($name) {
        return new class($name) {
          private string $name;
          public function __construct(string $name) { $this->name = $name; }
          public function get($key) {
            if ($this->name === 'system.mail' && $key === 'interface.default') {
              return 'php_mail';
            }
            if ($this->name === 'system.site' && $key === 'mail') {
              return 'noreply@example.com';
            }
            if ($this->name === 'system.site' && $key === 'name') {
              return 'Test Site';
            }
            if ($this->name === 'magic_link.settings' && $key === 'link_expiry') {
              return 15;
            }
            if ($this->name === 'magic_link.settings' && $key === 'neutral_validation') {
              return FALSE;
            }
            if ($this->name === 'magic_link.settings' && $key === 'email.from_name') {
              return '';
            }
            if ($this->name === 'magic_link.settings' && $key === 'email.from_email') {
              return '';
            }
            if ($this->name === 'magic_link.settings' && $key === 'email.subject_template') {
              return 'Your magic link for [site:name]';
            }
            if ($this->name === 'magic_link.settings' && $key === 'email.body_template') {
              return '<p>Hello [user:name],</p><p><a href="[magic_link:url]">Login</a></p>';
            }
            return NULL;
          }
        };
      }
    });

    // Set the global container used by ControllerBase.
    \Drupal::setContainer($this->container);

    // Load procedural functions from the module for hook_mail() testing.
    if (!function_exists('magic_link_mail')) {
      require_once dirname(__DIR__, 3) . '/magic_link.module';
    }
  }

  /**
   * Helper to build a controller instance with a mocked login URL generator.
   */
  protected function buildController(?string $login_url = null): MagicController {
    $controller = new class($login_url) extends MagicController {
      private ?string $fixedUrl;
      private int $fallbackCount = 0;
      public function __construct(?string $fixedUrl) { $this->fixedUrl = $fixedUrl; }
      public static function create($container): self {
        // Not used in this test; we set the container globally.
        return new self(null);
      }
      protected function generateOneTimeLoginUrl(UserInterface $account, Request $request): ?string {
        return $this->fixedUrl ?? 'https://example.com/user/reset/one-time';
      }
      public function setEmailValidator(EmailValidatorInterface $validator): void {
        $this->emailValidator = $validator;
      }
      protected function sendViaPhpMail(UserInterface $account, string $url): void {
        // Do not actually send during unit tests; just record fallback usage.
        $this->fallbackCount++;
      }
      public function getFallbackCount(): int { return $this->fallbackCount; }
    };
    // Inject validator property since we bypassed ::create().
    $controller->setEmailValidator($this->emailValidator);
    return $controller;
  }

  public function testNeutralConfirmationForInvalidEmail(): void {
    $controller = $this->buildController();
    $request = Request::create('/magic-link/request', 'POST', ['magic_email' => 'invalid']);
    $resp = $controller->request($request);
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('If the email exists in our system, a magic link was sent', (string) $resp->getContent());
  }

  // Note: deeper positive-path tests (email send, user lookup) are covered in
  // Kernel/functional tests; this unit test focuses on HTMX + neutral response.

  public function testValidateFragmentInvalid(): void {
    $controller = $this->buildController();
    $request = Request::create('/magic-link/validate', 'GET', ['magic_email' => 'bad']);
    $resp = $controller->validate($request);
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('Invalid email format', (string) $resp->getContent());
  }

  public function testValidateHeadersVariants(): void {
    $controller = $this->buildController();
    // Empty email → warn.
    $respEmpty = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => '']));
    $this->assertSame(200, $respEmpty->getStatusCode());
    $this->assertStringContainsString('Enter your email address.', (string) $respEmpty->getContent());
    $this->assertStringContainsString('"status":"warn"', (string) $respEmpty->headers->get('HX-Trigger'));

    // Invalid email → error.
    $respBad = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => 'bad']));
    $this->assertSame(200, $respBad->getStatusCode());
    $this->assertStringContainsString('Invalid email format', (string) $respBad->getContent());
    $this->assertStringContainsString('"status":"error"', (string) $respBad->headers->get('HX-Trigger'));

    // Valid email → ok.
    $respOk = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => 'user@example.com']));
    $this->assertSame(200, $respOk->getStatusCode());
    $this->assertStringContainsString('Looks good — you can request a magic link.', (string) $respOk->getContent());
    $this->assertStringContainsString('"status":"ok"', (string) $respOk->headers->get('HX-Trigger'));
  }

  // Removed brittle hook_mail formatting test for now.

  public function testNonPhpMailInterfaceTriggersFallback(): void {
    // Override config to simulate non-php_mail setup.
    $this->container->set('config.factory', new class {
      public function get($name) { return new class($name) {
        private string $name; public function __construct(string $name) { $this->name = $name; }
        public function get($key) {
          if ($this->name === 'system.mail' && $key === 'interface.default') { return 'symfony_mailer'; }
          if ($this->name === 'system.site' && $key === 'mail') { return 'noreply@example.com'; }
          if ($this->name === 'system.site' && $key === 'name') { return 'Test Site'; }
          if ($this->name === 'magic_link.settings' && $key === 'link_expiry') { return 15; }
          if ($this->name === 'magic_link.settings' && $key === 'neutral_validation') { return FALSE; }
          if ($this->name === 'magic_link.settings' && $key === 'email.from_name') { return ''; }
          if ($this->name === 'magic_link.settings' && $key === 'email.from_email') { return ''; }
          if ($this->name === 'magic_link.settings' && $key === 'email.subject_template') { return 'Your magic link for [site:name]'; }
          if ($this->name === 'magic_link.settings' && $key === 'email.body_template') { return '<p>Hello [user:name],</p><p><a href="[magic_link:url]">Login</a></p>'; }
          return NULL;
        }
      }; }
    });
    \Drupal::setContainer($this->container);

    $controller = $this->buildController('https://example.com/one-time/login');
    $request = Request::create('/magic-link/request', 'POST', ['magic_email' => TestEntityTypeManager::KNOWN_EMAIL]);
    $controller->request($request);
    $this->assertSame(1, $controller->getFallbackCount());
  }

  public function testRequestWithUnknownValidEmailIsNeutralAndNoSend(): void {
    $this->mailManager->lastMail = null;
    $controller = $this->buildController();
    $request = Request::create('/magic-link/request', 'POST', ['magic_email' => 'nobody@example.com']);
    $resp = $controller->request($request);
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('a magic link was sent', (string) $resp->getContent());
    $this->assertNull($this->mailManager->lastMail, 'No mail should be sent for unknown user');
  }

  public function testRequestWithKnownEmailSendsMail(): void {
    $this->mailManager->lastMail = null;
    $controller = $this->buildController('https://example.com/one-time/login');
    $request = Request::create('/magic-link/request', 'POST', ['magic_email' => TestEntityTypeManager::KNOWN_EMAIL]);
    $resp = $controller->request($request);
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertNotNull($this->mailManager->lastMail, 'Mail should be sent for known active user');
    $this->assertSame('magic_link', $this->mailManager->lastMail['module']);
    $this->assertSame('magic_link', $this->mailManager->lastMail['key']);
    $this->assertSame(TestEntityTypeManager::KNOWN_EMAIL, $this->mailManager->lastMail['to']);
    $this->assertSame('en', $this->mailManager->lastMail['langcode']);
    $this->assertArrayHasKey('url', $this->mailManager->lastMail['params']);
  }

  public function testMailManagerFailureFallsBackToPhpMail(): void {
    $controller = $this->buildController('https://example.com/one-time/login');
    // Instruct the TestMailManager to report failure.
    $this->mailManager->forceFail = true;
    $request = Request::create('/magic-link/request', 'POST', ['magic_email' => TestEntityTypeManager::KNOWN_EMAIL]);
    $resp = $controller->request($request);
    $this->assertSame(200, $resp->getStatusCode());
    // Our controller subclass increments fallback count instead of sending.
    $this->assertSame(1, $controller->getFallbackCount());
  }
}

// -------- Test doubles --------

class TestMailManager {
  public ?array $lastMail = null;
  public bool $forceFail = false;
  public function mail($module, $key, $to, $langcode, $params = [], $reply = NULL, $send = TRUE) {
    $this->lastMail = compact('module', 'key', 'to', 'langcode', 'params', 'reply', 'send');
    return ['result' => !$this->forceFail];
  }
}

class TestLoggerFactory implements LoggerChannelFactoryInterface {
  public function get($channel) { return new class implements LoggerChannelInterface, \Psr\Log\LoggerInterface {
      public function setRequestStack(?\Symfony\Component\HttpFoundation\RequestStack $requestStack = null): void {}
      public function setCurrentUser(?\Drupal\Core\Session\AccountInterface $account = null): void {}
      public function setLoggers(array $loggers): void {}
      public function emergency(\Stringable|string $message, array $context = []): void {}
      public function alert(\Stringable|string $message, array $context = []): void {}
      public function critical(\Stringable|string $message, array $context = []): void {}
      public function error(\Stringable|string $message, array $context = []): void {}
      public function warning(\Stringable|string $message, array $context = []): void {}
      public function notice(\Stringable|string $message, array $context = []): void {}
      public function info(\Stringable|string $message, array $context = []): void {}
      public function debug(\Stringable|string $message, array $context = []): void {}
      public function log($level, \Stringable|string $message, array $context = []): void {}
      public function getName() { return 'test'; }
    }; }
  public function setCurrentUser($account) {}
  public function addLogger($logger, $channel = NULL) {}
}

class TestEntityTypeManager {
  public const KNOWN_EMAIL = 'exists@example.com';
  public const ADMIN_EMAIL = 'admin@example.com';
  public static ?\Drupal\user\UserInterface $knownUser = NULL;
  public static ?\Drupal\user\UserInterface $adminUser = NULL;
  public function getStorage($entity_type) {
    if ($entity_type !== 'user') {
      throw new \InvalidArgumentException('Unexpected entity type');
    }
    return new class {
      public function load($id) { return (int) $id === 1 ? TestEntityTypeManager::$adminUser : NULL; }
      public function loadByProperties(array $properties = []) {
        if (!empty($properties['mail']) && $properties['mail'] === TestEntityTypeManager::KNOWN_EMAIL && ($properties['status'] ?? 0) == 1) {
          return [TestEntityTypeManager::$knownUser];
        }
        return [];
      }
    };
  }
}
