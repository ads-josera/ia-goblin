<?php

declare(strict_types=1);

namespace Drupal\Tests\goblin_portal\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests where people land: front page, after signing in, with an e-mail.
 */
#[Group('goblin_portal')]
#[RunTestsInSeparateProcesses]
final class PortalEntryTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['goblin_portal'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  private const DASHBOARD = '/admin/reports/ai-whatsapp-automation';

  /**
   * The front page sends visitors to sign in and users to the dashboard.
   */
  public function testFrontPage(): void {
    $this->drupalGet('<front>');
    $this->assertSession()->addressEquals('/user/login');

    $this->drupalLogin($this->dashboardUser());
    $this->drupalGet('<front>');
    $this->assertSession()->addressEquals(self::DASHBOARD);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Signing in with the username lands on the dashboard.
   */
  public function testSignInWithUsername(): void {
    $account = $this->dashboardUser();
    $this->signIn($account->getAccountName(), $account->passRaw);

    $this->assertSession()->addressEquals(self::DASHBOARD);
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * Signing in with the e-mail address works the same way.
   */
  public function testSignInWithEmail(): void {
    $account = $this->dashboardUser();
    $this->signIn($account->getEmail(), $account->passRaw);

    $this->assertSession()->addressEquals(self::DASHBOARD);
  }

  /**
   * A wrong password with a valid e-mail gets core's generic error.
   */
  public function testWrongPasswordWithEmail(): void {
    $account = $this->dashboardUser();
    $this->signIn($account->getEmail(), 'not-the-password');

    $this->assertSession()->addressEquals('/user/login');
    $this->assertSession()->statusMessageContains('Unrecognized username or password.', 'error');
  }

  /**
   * Users who may not see the dashboard land on their account instead.
   */
  public function testUserWithoutDashboardAccess(): void {
    $account = $this->drupalCreateUser();
    $this->signIn($account->getAccountName(), $account->passRaw);

    $this->assertSession()->addressEquals('/user/' . $account->id());
    $this->assertSession()->statusCodeEquals(200);
  }

  /**
   * An explicit destination wins over the dashboard.
   */
  public function testDestinationIsRespected(): void {
    $account = $this->dashboardUser();
    $this->drupalGet('user/login', ['query' => ['destination' => '/user/' . $account->id() . '/edit']]);
    $this->submitForm(['name' => $account->getAccountName(), 'pass' => $account->passRaw], 'Entrar');

    $this->assertSession()->addressEquals('/user/' . $account->id() . '/edit');
  }

  /**
   * The sign-in form shows the approved copy.
   */
  public function testSignInCopy(): void {
    $this->drupalGet('user/login');
    $this->assertSession()->fieldExists('Usuario');
    $this->assertSession()->elementAttributeContains('css', '#edit-name', 'placeholder', 'usuario / correo');
    $this->assertSession()->buttonExists('Entrar');
  }

  /**
   * Creates a user who may see the dashboard.
   */
  private function dashboardUser(): UserInterface {
    return $this->drupalCreateUser(['view ai whatsapp automation dashboard']);
  }

  /**
   * Submits the sign-in form.
   */
  private function signIn(string $identifier, string $password): void {
    $this->drupalGet('user/login');
    $this->submitForm(['name' => $identifier, 'pass' => $password], 'Entrar');
  }

}
