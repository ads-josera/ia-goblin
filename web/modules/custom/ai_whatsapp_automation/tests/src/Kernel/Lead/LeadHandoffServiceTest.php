<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Kernel\Lead;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests lead creation from a qualified conversation.
 */
#[Group('ai_whatsapp_automation')]
#[RunTestsInSeparateProcesses]
final class LeadHandoffServiceTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'file',
    'options',
    'text',
    'views',
    'ai_whatsapp_automation',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('file');
    $entity_type_ids = [
      'ai_whatsapp_client',
      'ai_whatsapp_knowledge_base',
      'ai_whatsapp_bot',
      'ai_whatsapp_account',
      'ai_whatsapp_conversation',
      'ai_whatsapp_message',
      'ai_whatsapp_lead',
      'ai_whatsapp_operator_action',
    ];
    foreach ($entity_type_ids as $entity_type_id) {
      $this->installEntitySchema($entity_type_id);
    }
    $this->installConfig(['ai_whatsapp_automation']);
    $this->config('ai_whatsapp_automation.settings')
      ->set('options.enable_lead_notifications', TRUE)
      ->save();
  }

  /**
   * The lead stores its origin and correctly extracted contact data.
   *
   * Replays production conversation 28 with the JG Mylard handoff settings.
   */
  public function testLeadStoresOriginAndContactData(): void {
    $storage = fn (string $type) => $this->container->get('entity_type.manager')->getStorage($type);

    $bot = $storage('ai_whatsapp_bot')->create([
      'name' => 'JG Mylard',
      'status' => 'active',
      'handoff_enabled' => TRUE,
      'handoff_minimum_fields' => 6,
      'handoff_required_fields' => "empresa, negocio\nmercancía, mercancia, carga\norigen, salida\ndestino, entrega\nmedio, transporte, terrestre\nvalor, usd, mxn\nfrecuencia, embarques\ncontacto, teléfono, telefono, correo, @",
      'handoff_trigger_phrases' => "asesor\npropuesta personalizada",
    ]);
    $bot->save();

    // Another bot talked to a contact with the same phone number later. The
    // legacy list attributed leads by phone and would pick this one.
    $other_bot = $storage('ai_whatsapp_bot')->create(['name' => 'Laboratorio', 'status' => 'active']);
    $other_bot->save();

    $conversation = $storage('ai_whatsapp_conversation')->create([
      'phone' => 'web:1:session',
      'name' => 'Web visitor',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
    ]);
    $conversation->save();
    $this->addMessage($conversation, 'contact', 'Necesito una cotizacion');
    $this->addMessage($conversation, 'ai', "Cotización — información necesaria\n\n🏢 **Nombre de la empresa:**\n📦 **Tipo de mercancía:**\n📞 **Nombre y teléfono de contacto:**  \n📧 **Correo electrónico:**");
    $this->addMessage($conversation, 'contact', 'Jerotracker, guitarras, paracho Michoacán, a CDMX, terrestres, valor 50, 000 USD, tel. 5512309140, frecuencia 4 embarcaciones por mes');
    $ai_response = "Datos recibidos — falta información\n\n🏢 **Empresa:** Jerotracker\n📞 **Teléfono:** 55 1230 9140\n\n👤 **Nombre del contacto:**  \n📧 **Correo electrónico:**\n\n👉 Un asesor especializado elaborará una propuesta personalizada.";
    $this->addMessage($conversation, 'ai', $ai_response);

    $storage('ai_whatsapp_conversation')->create([
      'phone' => '+525512309140',
      'channel' => 'whatsapp',
      'provider' => 'twilio',
      'status' => 'AI_ACTIVE',
      'bot' => $other_bot->id(),
    ])->save();

    $result = $this->container->get('ai_whatsapp_automation.lead_handoff')->handle($conversation, $ai_response);

    $this->assertSame('created_without_recipients', $result['status']);
    $lead = $storage('ai_whatsapp_lead')->load($result['lead_id']);
    $this->assertInstanceOf(ContentEntityInterface::class, $lead);
    $this->assertSame((string) $conversation->id(), (string) $lead->get('conversation')->target_id);
    $this->assertSame((string) $bot->id(), (string) $lead->get('bot')->target_id);
    $this->assertSame('Nombre no capturado', $lead->get('name')->value);
    $this->assertSame('+525512309140', $lead->get('phone')->value);
    $this->assertSame('web', $lead->get('source')->value);
  }

  /**
   * Examples in the assistant's questions never become the contact's data.
   *
   * Replays a live test: the bot asked for the new mailbox "(ej.
   * juan@tunegocio.com)" and the lead stored that example as the e-mail.
   */
  public function testAssistantExamplesAreNotContactData(): void {
    $storage = fn (string $type) => $this->container->get('entity_type.manager')->getStorage($type);
    $bot = $storage('ai_whatsapp_bot')->create([
      'name' => 'Goblin',
      'status' => 'active',
      'handoff_enabled' => TRUE,
      'handoff_minimum_fields' => 3,
      'handoff_required_fields' => "nombre\nteléfono, telefono, whatsapp, celular\ncategoría, categoria\ndescripción, descripcion",
    ]);
    $bot->save();
    $conversation = $storage('ai_whatsapp_conversation')->create([
      'phone' => '+5215511122233',
      'name' => '🦊 Perfil',
      'channel' => 'whatsapp',
      'provider' => 'evolution',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
    ]);
    $conversation->save();
    $this->addMessage($conversation, 'contact', 'Necesito crear un correo para un empleado nuevo');
    $this->addMessage($conversation, 'ai', "Para solicitarlo necesito:\n1) Nombre de la persona:\n2) Usuario deseado (ej. juan@tunegocio.com):");
    $this->addMessage($conversation, 'contact', 'Carlos Ruiz, midominio.com');
    $ai_response = "Datos capturados:\n👤 Nombre: Carlos Ruiz\n📱 Teléfono / WhatsApp: +5215511122233\n🗂️ Categoría: Correo electrónico\n📝 Descripción: Crear un correo en midominio.com para un empleado nuevo.\n\nListo. Ya tengo la información necesaria para canalizar tu solicitud con nuestro equipo.";
    $this->addMessage($conversation, 'ai', $ai_response);

    $result = $this->container->get('ai_whatsapp_automation.lead_handoff')->handle($conversation, $ai_response);

    $lead = $storage('ai_whatsapp_lead')->load($result['lead_id']);
    $this->assertSame('', (string) $lead->get('email')->value, 'The example address is not the contact\'s e-mail');
    $this->assertSame('Carlos Ruiz', $lead->get('name')->value, 'The name comes from the closing summary');
    $this->assertSame('+5215511122233', $lead->get('phone')->value);
  }

  /**
   * The lead notice is e-mailed to the bot's people, even with no WhatsApp.
   *
   * A web chat lead has no WhatsApp account to notify from: before e-mail,
   * nobody heard about it (status "created_without_recipients").
   */
  public function testLeadNoticeIsEmailedToTheBotPeople(): void {
    $this->config('system.mail')->set('interface.default', 'test_mail_collector')->save();
    \Drupal::state()->set('system.test_mail_collector', []);
    $storage = fn (string $type) => $this->container->get('entity_type.manager')->getStorage($type);
    $client = $storage('ai_whatsapp_client')->create(['name' => 'Goblin', 'brand_name' => 'Goblin Creative', 'status' => 'active']);
    $client->save();
    $bot = $storage('ai_whatsapp_bot')->create([
      'client' => $client->id(),
      'name' => 'Goblin Soporte',
      'status' => 'active',
      'handoff_enabled' => TRUE,
      'handoff_minimum_fields' => 3,
      'handoff_required_fields' => "nombre\nteléfono, telefono, whatsapp, celular\ncategoría, categoria\ndescripción, descripcion",
      'lead_notification_emails' => "soporte@example.com\n jefe@example.com ",
    ]);
    $bot->save();
    $conversation = $storage('ai_whatsapp_conversation')->create([
      'phone' => 'web:1:session',
      'name' => 'Web visitor',
      'channel' => 'web',
      'provider' => 'web',
      'status' => 'AI_ACTIVE',
      'bot' => $bot->id(),
    ]);
    $conversation->save();
    $this->addMessage($conversation, 'contact', 'Mi correo no envía mensajes');
    $ai_response = "Datos capturados:\n👤 Nombre: Ana López\n📱 Teléfono / WhatsApp: 5512345678\n🗂️ Categoría: Correo electrónico\n📝 Descripción: **No envía** correos desde Outlook.\n\nListo. Ya tengo la información necesaria para canalizar tu solicitud con nuestro equipo.";
    $this->addMessage($conversation, 'ai', $ai_response);

    $result = $this->container->get('ai_whatsapp_automation.lead_handoff')->handle($conversation, $ai_response);

    $this->assertSame('notified', $result['status']);
    $this->assertSame(['soporte@example.com', 'jefe@example.com'], array_column($result['notifications'], 'recipient'));
    $mails = \Drupal::state()->get('system.test_mail_collector', []);
    $this->assertCount(2, $mails);
    $mail = $mails[0];
    $this->assertSame('ai_whatsapp_automation_lead_notification', $mail['id']);
    $this->assertSame('soporte@example.com', $mail['to']);
    $this->assertSame('Nuevo lead ' . $result['lead_id'] . ' — Goblin Soporte', $mail['subject']);
    $this->assertStringStartsWith('text/html', $mail['headers']['Content-Type']);
    // The collector turns HTML into text, like php_mail: the data is there.
    foreach (['Goblin Creative', 'Folio: ' . $result['lead_id'], 'Contacto: Ana López', 'Origen: Chat web', 'No envía correos desde Outlook'] as $expected) {
      $this->assertStringContainsString($expected, $mail['body']);
    }
    $this->assertStringNotContainsString('**', $mail['body'], 'Chat formatting is removed');
    // The client's mail: no Josera brand, no button or link to the panel.
    $this->assertStringNotContainsString('Josera', $mail['body']);
    $this->assertStringNotContainsString('/admin/content/ai-whatsapp/conversations', $mail['body']);
  }

  /**
   * A mistyped notice address is rejected when the bot is saved.
   */
  public function testInvalidNoticeEmailIsRejected(): void {
    $bot = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot')->create([
      'name' => 'Goblin Ventas',
      'status' => 'active',
      'lead_notification_emails' => "ventas@example.com\nventas@example",
    ]);
    $violations = $bot->validate()->getByField('lead_notification_emails');

    $this->assertCount(1, $violations);
    $this->assertStringContainsString('ventas@example', (string) $violations[0]->getMessage());
  }

  /**
   * A notice number without country code is rejected; usual formats pass.
   *
   * A local «55 1234 5678» was sent to a wrong number: the notice never
   * arrived and nothing said so.
   */
  public function testNoticeNumberNeedsCountryCode(): void {
    $storage = $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_bot');
    $valid = $storage->create([
      'name' => 'Goblin Soporte',
      'status' => 'active',
      'lead_notification_numbers' => "+52 55 1234 5678\n+5215512345678\n+52-55-1234-5678\n+1 415 555 0100",
    ]);
    $this->assertCount(0, $valid->validate()->getByField('lead_notification_numbers'));

    $local = $storage->create([
      'name' => 'Goblin Ventas',
      'status' => 'active',
      'lead_notification_numbers' => "+5215512345678\n55 1234 5678",
    ]);
    $violations = $local->validate()->getByField('lead_notification_numbers');
    $this->assertCount(1, $violations);
    $this->assertStringContainsString('55 1234 5678', (string) $violations[0]->getMessage());
  }

  /**
   * Stores a message in a conversation.
   */
  private function addMessage(ContentEntityInterface $conversation, string $sender, string $content): void {
    $this->container->get('entity_type.manager')->getStorage('ai_whatsapp_message')->create([
      'conversation' => $conversation->id(),
      'sender' => $sender,
      'content' => $content,
    ])->save();
  }

}
