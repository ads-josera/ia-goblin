<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Functional\Access;

use Drupal\ai_whatsapp_automation\Access\PanelRoles;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that a manager sets up a reception menu from the bot form.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class ReceptionMenuFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_whatsapp_automation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Creates a bot.
   */
  private function bot(string $name, ContentEntityInterface $client): ContentEntityInterface {
    $bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create([
      'name' => $name,
      'client' => $client->id(),
      'system_prompt' => 'Prueba.',
      'model' => 'gpt-5-mini',
      'reasoning_effort' => 'low',
      'status' => 'active',
    ]);
    $bot->save();

    return $bot;
  }

  /**
   * The manager adds two area bots; another client's bot is refused.
   */
  public function testManagerBuildsTheMenu(): void {
    $clients = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_client');
    $own = $clients->create(['name' => 'Goblin', 'status' => 'active']);
    $other = $clients->create(['name' => 'Otro', 'status' => 'active']);
    $own->save();
    $other->save();
    $reception = $this->bot('Recepción', $own);
    $billing = $this->bot('Facturación', $own);
    $support = $this->bot('Soporte', $own);
    $foreign = $this->bot('Ajeno', $other);

    $manager = $this->drupalCreateUser();
    $manager->addRole(PanelRoles::MANAGER);
    $manager->save();
    $this->drupalLogin($manager);

    $edit_url = $reception->toUrl('edit-form');
    $this->drupalGet($edit_url);
    $this->assertSession()->pageTextContains('Reception menu');
    $this->submitForm([
      'menu_bots[0][target_id]' => 'Facturación (' . $billing->id() . ')',
      'menu_message[0][value]' => 'Hola, elige un área:',
    ], 'Save');
    $this->drupalGet($edit_url);
    $this->submitForm([
      'menu_bots[1][target_id]' => 'Soporte (' . $support->id() . ')',
    ], 'Add another item');
    $this->submitForm([
      'menu_bots[1][target_id]' => 'Soporte (' . $support->id() . ')',
    ], 'Save');

    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot');
    $storage->resetCache();
    $saved = $storage->load($reception->id());
    $this->assertSame(
      [(string) $billing->id(), (string) $support->id()],
      array_map('strval', array_column($saved->get('menu_bots')->getValue(), 'target_id')),
    );
    $menu = $this->container->get('ai_whatsapp_automation.menu_router')->menuFor($saved);
    $this->assertStringContainsString("1\u{FE0F}\u{20E3} Facturación", $menu);
    $this->assertStringContainsString("2\u{FE0F}\u{20E3} Soporte", $menu);

    // Another client's bot cannot be offered in this client's menu.
    $this->drupalGet($edit_url);
    $this->submitForm([
      'menu_bots[0][target_id]' => 'Ajeno (' . $foreign->id() . ')',
    ], 'Save');
    $this->assertSession()->pageTextContains('pertenece al cliente «Otro»');
    $storage->resetCache();
    $this->assertSame((string) $billing->id(), (string) $storage->load($reception->id())->get('menu_bots')->target_id);
  }

}
