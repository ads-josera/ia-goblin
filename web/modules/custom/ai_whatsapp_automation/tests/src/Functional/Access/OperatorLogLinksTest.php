<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Functional\Access;

use Drupal\ai_whatsapp_automation\Access\PanelRoles;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests that the operator log only links what the viewer may open.
 *
 * The manager reads the log but not conversations or leads: links to them
 * led to "Acceso denegado" (found in the QA sweep of 2026-10-12).
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class OperatorLogLinksTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['ai_whatsapp_automation'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Manager: names as text; administrator: links that open.
   */
  public function testLinksFollowAccess(): void {
    $etm = $this->container->get('entity_type.manager');
    $conversation = $etm->getStorage('ai_whatsapp_conversation')->create([
      'phone' => 'web:1:log',
      'provider' => 'web',
      'channel' => 'web',
      'status' => 'HUMAN_ASSIGNED',
    ]);
    $conversation->save();
    $lead = $etm->getStorage('ai_whatsapp_lead')->create([
      'name' => 'Ana Ruiz',
      'phone' => '+525512345678',
      'conversation' => $conversation->id(),
      'source' => 'web',
      'status' => 'qualified',
    ]);
    $lead->save();
    $etm->getStorage('ai_whatsapp_operator_action')->create([
      'conversation' => $conversation->id(),
      'user' => 1,
      'action' => 'LEAD_HANDOFF',
      'note' => 'Lead ID: ' . $lead->id(),
    ])->save();

    $manager = $this->drupalCreateUser();
    $manager->addRole(PanelRoles::MANAGER);
    $manager->save();
    $this->drupalLogin($manager);
    $this->drupalGet('admin/content/ai-whatsapp/operator-actions');
    $this->assertSession()->statusCodeEquals(200);
    $this->assertSession()->pageTextContains('Lead #' . $lead->id());
    $this->assertSession()->elementNotExists('css', 'a[href$="/conversations/' . $conversation->id() . '"]');
    $this->assertSession()->elementNotExists('css', 'a[href$="/leads/' . $lead->id() . '"]');

    $this->drupalLogin($this->drupalCreateUser(['administer ai whatsapp automation entities']));
    $this->drupalGet('admin/content/ai-whatsapp/operator-actions');
    $this->assertSession()->elementExists('css', 'a[href$="/conversations/' . $conversation->id() . '"]');
    $this->assertSession()->elementExists('css', 'a[href$="/leads/' . $lead->id() . '"]');
  }

}
