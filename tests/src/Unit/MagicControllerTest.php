<?php

declare(strict_types=1);

namespace Drupal\Tests\magic_link\Unit;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\magic_link\Controller\MagicController;
use Drupal\magic_link\Service\MagicLinkMailService;
use Drupal\magic_link\Service\MagicLinkTokenService;
use Drupal\Tests\magic_link\Unit\Stub\TestEntityTypeManager;
use Drupal\Tests\magic_link\Unit\Stub\TestLoggerFactory;
use Drupal\Tests\magic_link\Unit\Stub\TestMailManager;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;

/**
 * Unit tests for MagicController.
 */
#[CoversClass(MagicController::class)]
#[Group('magic_link')]
class MagicControllerTest extends MagicLinkTestBase {

  /**
   * Manages sending and handling of test mails.
   *
   * @var \Drupal\Tests\magic_link\Unit\Stub\TestMailManager
   */
  private TestMailManager $mailManager;

  /**
   * Provides access to entity type definitions and storage handlers in tests.
   *
   * @var \Drupal\Tests\magic_link\Unit\Stub\TestEntityTypeManager
   */
  private TestEntityTypeManager $entityTypeManager;

  /**
   * Validates email addresses for correct format and compliance with standards.
   *
   * @var \Drupal\Component\Utility\EmailValidatorInterface
   */
  private EmailValidatorInterface $emailValidator;

  /**
   * Generates and validates secure tokens for magic link functionality.
   *
   * @var \Drupal\magic_link\Service\MagicLinkTokenService
   */
  private MagicLinkTokenService $tokenService;

  /**
   * Handles creation and sending of magic link emails.
   *
   * @var \Drupal\magic_link\Service\MagicLinkMailService
   */
  private MagicLinkMailService $mailService;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Email validator.
    $this->emailValidator = new class implements EmailValidatorInterface {

      /**
       * {@inheritdoc}
       */
      public function isValid($email) {
        return (bool) filter_var((string) $email, FILTER_VALIDATE_EMAIL);
      }

    };

    // Mail manager stub.
    $this->mailManager = new TestMailManager();

    // Entity type manager with test users.
    $known = $this->getMockBuilder(UserInterface::class)->getMock();
    $known->method('getDisplayName')->willReturn('Test User');
    $known->method('getEmail')->willReturn(TestEntityTypeManager::KNOWN_EMAIL);
    $known->method('id')->willReturn(123);
    $known->method('getPreferredLangcode')->willReturn('en');
    $known->method('isActive')->willReturn(TRUE);

    $admin = $this->getMockBuilder(UserInterface::class)->getMock();
    $admin->method('getEmail')->willReturn(TestEntityTypeManager::ADMIN_EMAIL);

    TestEntityTypeManager::$knownUser = $known;
    TestEntityTypeManager::$adminUser = $admin;
    $this->entityTypeManager = new TestEntityTypeManager();

    // Services.
    $this->tokenService = new MagicLinkTokenService();

    // Set up global container for magic_link.module hook.
    $this->setupContainer();

    // Load module functions.
    if (!function_exists('magic_link_mail')) {
      require_once dirname(__DIR__, 3) . '/magic_link.module';
    }
  }

  /**
   * Set up minimal global container for procedural code.
   */
  private function setupContainer(): void {
    $container = new ContainerBuilder();

    // Config factory mock.
    $configFactory = $this->createConfigFactory();
    $container->set('config.factory', $configFactory);

    // Language manager mock.
    $languageManager = $this->createLanguageManager();
    $container->set('language_manager', $languageManager);

    // Mail service.
    $this->mailService = new MagicLinkMailService(
      $configFactory,
      $this->mailManager,
      $languageManager,
      $this->entityTypeManager
    );
    $container->set('magic_link.mail', $this->mailService);
    $container->set('magic_link.token', $this->tokenService);

    $container->set('email.validator', $this->emailValidator);
    $container->set('entity_type.manager', $this->entityTypeManager);
    $container->set('logger.factory', new TestLoggerFactory());
    $container->set('string_translation', $this->getStringTranslationStub());

    $this->setupGlobalContainer($container);
  }

  /**
   * Builds a controller with optional fixed login URL.
   */
  private function buildController(?string $login_url = NULL): MagicController {
    $controller = new class($login_url) extends MagicController {

      /**
       * The fixed URL if one is set, or NULL otherwise.
       *
       * @var string|null
       */
      private ?string $fixedUrl;

      public function __construct(?string $fixedUrl) {
        $this->fixedUrl = $fixedUrl;
      }

      /**
       * {@inheritdoc}
       */
      public static function create($container): self {
        return new self(NULL);
      }

      /**
       * {@inheritdoc}
       */
      protected function generateOneTimeLoginUrl(UserInterface $account, Request $request): ?string {
        return $this->fixedUrl ?? 'https://example.com/user/reset/one-time';
      }

      /**
       * {@inheritdoc}
       */
      public function setEmailValidator(EmailValidatorInterface $validator): void {
        $this->emailValidator = $validator;
      }

      /**
       * {@inheritdoc}
       */
      public function setTokenService(MagicLinkTokenService $service): void {
        $this->tokenService = $service;
      }

      /**
       * {@inheritdoc}
       */
      public function setMailService(MagicLinkMailService $service): void {
        $this->mailService = $service;
      }

      /**
       * {@inheritdoc}
       */
      public function setConfigFactory($factory): void {
        $this->configFactory = $factory;
      }

      /**
       * {@inheritdoc}
       */
      public function setLanguageManager($manager): void {
        $this->languageManager = $manager;
      }

      /**
       * {@inheritdoc}
       */
      public function setKeyValueExpirable($factory): void {
        $this->keyValueExpirable = $factory;
      }

    };

    $controller->setEmailValidator($this->emailValidator);
    $controller->setTokenService($this->tokenService);
    $controller->setMailService($this->mailService);
    $controller->setConfigFactory($this->createConfigFactory());
    $controller->setLanguageManager($this->createLanguageManager());
    $controller->setKeyValueExpirable($this->createKeyValueExpirable());

    return $controller;
  }

  /**
   * Ensures invalid email yields error message.
   */
  public function testNeutralConfirmationForInvalidEmail(): void {
    $controller = $this->buildController();
    $resp = $controller->request(Request::create('/magic-link/request', 'POST', ['magic_email' => 'invalid']));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('Invalid email format', (string) $resp->getContent());
  }

  /**
   * Validates fragment response for a bad email.
   */
  public function testValidateFragmentInvalid(): void {
    $controller = $this->buildController();
    $resp = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => 'bad']));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('Invalid email format', (string) $resp->getContent());
  }

  /**
   * Checks HX-Trigger headers for empty/invalid/valid emails.
   */
  public function testValidateHeadersVariants(): void {
    $controller = $this->buildController();

    $respEmpty = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => '']));
    $this->assertStringContainsString('"status":"warn"', (string) $respEmpty->headers->get('HX-Trigger'));

    $respBad = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => 'bad']));
    $this->assertStringContainsString('"status":"error"', (string) $respBad->headers->get('HX-Trigger'));

    $respOk = $controller->validate(Request::create('/magic-link/validate', 'GET', ['magic_email' => TestEntityTypeManager::KNOWN_EMAIL]));
    $this->assertStringContainsString('"status":"ok"', (string) $respOk->headers->get('HX-Trigger'));
  }

  /**
   * Unknown but valid email remains neutral and sends nothing.
   */
  public function testRequestWithUnknownValidEmailIsNeutralAndNoSend(): void {
    $this->mailManager->lastMail = NULL;
    $controller = $this->buildController();
    $resp = $controller->request(Request::create('/magic-link/request', 'POST', ['magic_email' => 'nobody@example.com']));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('a magic link was sent', (string) $resp->getContent());
    $this->assertNull($this->mailManager->lastMail);
  }

  /**
   * Known user triggers mail send.
   */
  public function testRequestWithKnownEmailSendsMail(): void {
    $this->mailManager->lastMail = NULL;
    $controller = $this->buildController('https://example.com/one-time/login');
    $resp = $controller->request(Request::create('/magic-link/request', 'POST', ['magic_email' => TestEntityTypeManager::KNOWN_EMAIL]));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertNotNull($this->mailManager->lastMail);
    $this->assertArrayHasKey('url', $this->mailManager->lastMail['params']);
  }

  /**
   * Mail-manager failure still returns neutral confirmation.
   */
  public function testMailManagerFailureFallsBackToPhpMail(): void {
    $controller = $this->buildController('https://example.com/one-time/login');
    $this->mailManager->forceFail = TRUE;
    $resp = $controller->request(Request::create('/magic-link/request', 'POST', ['magic_email' => TestEntityTypeManager::KNOWN_EMAIL]));
    $this->assertSame(200, $resp->getStatusCode());
    $this->assertStringContainsString('a magic link was sent', (string) $resp->getContent());
  }

}
