<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Functional\Access;

use Drupal\ai_whatsapp_automation\Access\PanelRoles;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\BrowserTestBase;
use Drupal\user\Entity\Role;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the client agent and manager roles and the panel menu.
 *
 * Each role is checked with its own account: what it sees in the menu, what
 * it may open, what it may not, and that it can always sign out.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class PanelRolesTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_whatsapp_automation', 'navigation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The client the client agent belongs to.
   */
  private ContentEntityInterface $client;

  /**
   * A bot of that client, managed by the manager.
   */
  private ContentEntityInterface $bot;

  /**
   * A conversation of that client, seen only by the client agent.
   */
  private ContentEntityInterface $conversation;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $etm = $this->container->get('entity_type.manager');
    $this->client = $etm->getStorage('ai_whatsapp_client')->create(['name' => 'Cliente Prueba', 'status' => 'active']);
    $this->client->save();
    $this->bot = $etm->getStorage('ai_whatsapp_bot')->create([
      'name' => 'Bot Prueba',
      'client' => $this->client->id(),
      'system_prompt' => 'Prueba.',
      'model' => 'gpt-5-mini',
      'reasoning_effort' => 'low',
      'status' => 'active',
    ]);
    $this->bot->save();
    $this->conversation = $etm->getStorage('ai_whatsapp_conversation')->create([
      'client' => $this->client->id(),
      'phone' => '+5215550000000',
      'name' => 'Contacto Prueba',
      'channel' => 'whatsapp',
      'provider' => 'evolution',
      'status' => 'AI_ACTIVE',
    ]);
    $this->conversation->save();
  }

  /**
   * Installing the module creates both roles with exactly their permissions.
   */
  public function testRolesAreInstalled(): void {
    foreach (PanelRoles::definitions() as $id => $definition) {
      $role = Role::load($id);
      $this->assertNotNull($role, "Role $id exists after install.");
      $this->assertEqualsCanonicalizing($definition['permissions'], $role->getPermissions());
    }
  }

  /**
   * Ensuring the roles again keeps permissions a site added on top.
   */
  public function testEnsureIsIdempotentAndAdditive(): void {
    Role::load(PanelRoles::MANAGER)->grantPermission('access content')->save();
    Role::load(PanelRoles::CLIENT_AGENT)->delete();

    $created = PanelRoles::ensure($this->container->get('entity_type.manager'));

    $this->assertSame(['Atención a clientes (AI WhatsApp)'], $created);
    $this->assertTrue(Role::load(PanelRoles::MANAGER)->hasPermission('access content'));
    $this->assertTrue(Role::load(PanelRoles::CLIENT_AGENT)->hasPermission('view ai whatsapp automation dashboard'));
  }

  /**
   * A client agent sees their four sections and none of the manager's.
   */
  public function testClientAgent(): void {
    $this->drupalLogin($this->roleUser(PanelRoles::CLIENT_AGENT, TRUE));
    $this->drupalGet('admin/reports/ai-whatsapp-automation');
    $this->assertMenu(['Panel', 'Conversaciones', 'Prospectos', 'Mensajes']);

    $this->drupalGet('admin/content/ai-whatsapp/conversations/' . $this->conversation->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementTextEquals('css', '.aiwa-panel-nav__link.is-active', 'Conversaciones');

    foreach (['bots', 'accounts', 'knowledge-bases', 'routing', 'operator-actions', 'clients'] as $section) {
      $this->drupalGet('admin/content/ai-whatsapp/' . $section);
      $this->assertSession()->statusCodeEquals(403);
    }
  }

  /**
   * A manager configures every client's setup but reads no conversations.
   */
  public function testManager(): void {
    $this->drupalLogin($this->roleUser(PanelRoles::MANAGER));
    $this->drupalGet('admin/content/ai-whatsapp/bots');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertMenu([
      'Bots',
      'Bases de conocimiento',
      'Documentos',
      'Cuentas de WhatsApp',
      'Conexión QR',
      'Enrutamiento',
      'Bitácora',
    ]);
    // Bots of every client are listed.
    $this->assertSession()->pageTextContains('Bot Prueba');

    // The bot's page shows its client's name: the reference formatter asks
    // for "view label" on the client, which managers get without being able
    // to open the client record.
    $this->drupalGet('admin/content/ai-whatsapp/bots/' . $this->bot->id());
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Cliente Prueba');

    $this->drupalGet('admin/content/ai-whatsapp/bots/' . $this->bot->id() . '/edit');
    $this->assertSession()->statusCodeEquals(200);
    foreach (['knowledge-bases', 'accounts', 'routing', 'operator-actions', 'evolution'] as $section) {
      $this->drupalGet('admin/content/ai-whatsapp/' . $section);
      $this->assertSession()->statusCodeEquals(200);
    }

    // The routing guide never offers a step the manager cannot take.
    $this->drupalGet('admin/content/ai-whatsapp/routing');
    $this->assertSession()->pageTextContains('Lo hace un administrador.');
    $this->assertSession()->linkByHrefNotExists('/admin/content/ai-whatsapp/clients/add');
    $this->assertSession()->elementTextEquals('css', '.ai-whatsapp-routing-step .button--primary', 'Agregar bot');

    foreach (['conversations', 'messages', 'leads', 'clients', 'conversations/' . $this->conversation->id()] as $section) {
      $this->drupalGet('admin/content/ai-whatsapp/' . $section);
      $this->assertSession()->statusCodeEquals(403);
    }
    $this->drupalGet('admin/reports/ai-whatsapp-automation');
    $this->assertSession()->statusCodeEquals(403);
    $this->drupalGet('admin/content/ai-whatsapp/clients/' . $this->client->id());
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * The menu's sign-out link really signs out.
   */
  public function testSignOutFromMenu(): void {
    $this->drupalLogin($this->roleUser(PanelRoles::MANAGER));
    $this->drupalGet('admin/content/ai-whatsapp/bots');
    $this->clickLink('Cerrar sesión');

    $this->drupalGet('admin/content/ai-whatsapp/bots');
    $this->assertSession()->statusCodeEquals(403);
  }

  /**
   * People with Drupal's navigation do not get a second menu.
   */
  public function testAdministratorHasNoPanelMenu(): void {
    $this->drupalLogin($this->drupalCreateUser([
      'administer ai whatsapp automation entities',
      'view ai whatsapp automation dashboard',
      'access navigation',
    ]));
    $this->drupalGet('admin/reports/ai-whatsapp-automation');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->elementNotExists('css', '.aiwa-panel-nav');
  }

  /**
   * The umbrella permission still opens every section, as before sections.
   */
  public function testUmbrellaPermissionKeepsFullAccess(): void {
    $this->drupalLogin($this->drupalCreateUser(['administer ai whatsapp automation entities']));
    $this->drupalGet('admin/content/ai-whatsapp/routing');
    $this->assertSession()->elementTextEquals('css', '.ai-whatsapp-routing-step .button--primary', 'Agregar cliente');
    $this->assertSession()->pageTextNotContains('Lo hace un administrador.');
    $sections = [
      'bots',
      'accounts',
      'knowledge-bases',
      'routing',
      'operator-actions',
      'evolution',
      'conversations',
      'clients',
    ];
    foreach ($sections as $section) {
      $this->drupalGet('admin/content/ai-whatsapp/' . $section);
      $this->assertSession()->statusCodeEquals(200);
    }
  }

  /**
   * Asserts the panel menu lists exactly these sections and a sign-out.
   *
   * @param string[] $expected
   *   Section labels in order.
   */
  private function assertMenu(array $expected): void {
    $links = $this->getSession()->getPage()->findAll('css', '.aiwa-panel-nav__link');
    $this->assertSame($expected, array_map(static fn ($link) => trim($link->getText()), $links));
    $this->assertSession()->elementExists('css', '.aiwa-panel-nav__logout');
  }

  /**
   * Creates a user with one of the panel roles.
   */
  private function roleUser(string $role, bool $with_client = FALSE): UserInterface {
    $account = $this->drupalCreateUser();
    $account->addRole($role);
    if ($with_client) {
      $account->set('ai_whatsapp_client', $this->client->id());
    }
    $account->save();
    return $account;
  }

}
