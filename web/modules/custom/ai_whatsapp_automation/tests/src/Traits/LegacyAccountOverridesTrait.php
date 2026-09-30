<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Traits;

use Drupal\Core\Field\BaseFieldDefinition;

/**
 * Recreates the account override columns removed in update_11040.
 *
 * Sites installed before that update still have them, with data, when the
 * older update hooks and update_11040 itself run. Tests use this to replay
 * that shape instead of the current, override-free schema.
 */
trait LegacyAccountOverridesTrait {

  /**
   * Installs the three legacy fields as they were defined.
   */
  protected function installLegacyAccountOverrides(): void {
    $definitions = [
      'prompt_override' => BaseFieldDefinition::create('string_long'),
      'model_override' => BaseFieldDefinition::create('list_string')->setSetting('allowed_values', [
        'gpt-5-mini' => 'GPT-5 mini',
        'gpt-5.1' => 'GPT-5.1',
        'gpt-5' => 'GPT-5',
        'gpt-5-nano' => 'GPT-5 nano',
        'gpt-4.1-mini' => 'GPT-4.1 mini',
      ]),
      'knowledge_base' => BaseFieldDefinition::create('entity_reference')->setSetting('target_type', 'ai_whatsapp_knowledge_base'),
    ];
    $manager = $this->container->get('entity.definition_update_manager');
    foreach ($definitions as $name => $definition) {
      $definition->setName($name)->setTargetEntityTypeId('ai_whatsapp_account');
      $manager->installFieldStorageDefinition($name, 'ai_whatsapp_account', 'ai_whatsapp_automation', $definition);
    }
  }

  /**
   * Writes legacy override values straight into an account row.
   *
   * @param int|string $account_id
   *   The account ID.
   * @param array<string, string|int|null> $values
   *   Column => value, for prompt_override, model_override, knowledge_base.
   */
  protected function setLegacyAccountOverrides(int|string $account_id, array $values): void {
    $this->container->get('database')->update('ai_whatsapp_account')->fields($values)->condition('id', $account_id)->execute();
  }

}
