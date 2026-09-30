<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\ai_whatsapp_automation\Entity\Bot;
use Drupal\Core\Database\Connection;
use Drupal\Core\Database\Statement\FetchAs;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityDefinitionUpdateManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * Moves WhatsApp account overrides into bots, then drops the override fields.
 *
 * Accounts used to override their bot's prompt, model and knowledge base.
 * The bot is now the only place that defines behavior, so each account that
 * overrode anything gets its own copy of its bot carrying those values, and
 * answers exactly as before:
 *
 * - The copy is named "<bot> — <account>" and keeps every other setting.
 * - The account and its conversations that were on the original bot move to
 *   the copy. Conversations keep their bot, so moving only the account would
 *   leave ongoing chats on the original prompt.
 * - Its web chat is off, with a new token and no API key: the original bot's
 *   widget stays the only one on that embed.
 * - Records move with plain column updates, so "changed" dates, which drive
 *   inbox order and automatic closing, are not touched.
 *
 * Reads the columns directly: the fields are no longer defined in code.
 * Safe to run again: once the columns are gone there is nothing to do.
 */
final class AccountOverrideMigration {

  /**
   * Account columns that held overrides.
   */
  public const FIELDS = ['prompt_override', 'model_override', 'knowledge_base'];

  private const TABLE = 'ai_whatsapp_account';

  public function __construct(
    private readonly Connection $database,
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly EntityDefinitionUpdateManagerInterface $definitionUpdateManager,
  ) {
  }

  /**
   * Runs the migration.
   *
   * @return string[]
   *   One line per account changed or skipped, for the update report.
   */
  public function run(): array {
    $columns = array_values(array_filter(self::FIELDS, fn (string $column): bool => $this->database->schema()->fieldExists(self::TABLE, $column)));
    $report = $columns === [] ? [] : $this->moveOverridesToBots($columns);

    foreach (self::FIELDS as $field_name) {
      $definition = $this->definitionUpdateManager->getFieldStorageDefinition($field_name, 'ai_whatsapp_account');
      if ($definition !== NULL) {
        $this->definitionUpdateManager->uninstallFieldStorageDefinition($definition);
      }
    }

    return $report;
  }

  /**
   * Gives every overriding account its own bot.
   *
   * @param string[] $columns
   *   Override columns present in the table.
   *
   * @return string[]
   *   Report lines.
   */
  private function moveOverridesToBots(array $columns): array {
    $query = $this->database->select(self::TABLE, 'a')->fields('a', array_merge(['id', 'name', 'bot'], $columns));
    $any = $query->orConditionGroup();
    foreach ($columns as $column) {
      $column === 'knowledge_base' ? $any->isNotNull('a.' . $column) : $any->condition('a.' . $column, '', '<>');
    }
    $rows = $query->condition($any)->execute()->fetchAllAssoc('id', FetchAs::Associative);

    $bots = $this->entityTypeManager->getStorage('ai_whatsapp_bot');
    $report = [];
    foreach ($rows as $row) {
      $overrides = array_filter(
        array_intersect_key($row, array_flip($columns)),
        static fn ($value): bool => $value !== NULL && $value !== '',
      );
      if ($overrides === []) {
        continue;
      }

      $original = $row['bot'] ? $bots->load($row['bot']) : NULL;
      if (!$original instanceof ContentEntityInterface) {
        // Without a bot the overrides never reached the AI.
        $report[] = sprintf('«%s»: sin bot, sus valores propios no se usaban y se descartan.', $row['name']);
        continue;
      }

      $copy = $this->copyWithOverrides($original, (string) $row['name'], $overrides);
      $this->database->update(self::TABLE)->fields(['bot' => $copy->id()])->condition('id', $row['id'])->execute();
      $moved = $this->database->update('ai_whatsapp_conversation')
        ->fields(['bot' => $copy->id()])
        ->condition('whatsapp_account', $row['id'])
        ->condition('bot', $original->id())
        ->execute();

      $report[] = sprintf('«%s»: ahora usa el bot «%s» (%s); %d conversaciones movidas.', $row['name'], $copy->label(), implode(', ', array_keys($overrides)), $moved);
    }

    $this->entityTypeManager->getStorage('ai_whatsapp_account')->resetCache();
    $this->entityTypeManager->getStorage('ai_whatsapp_conversation')->resetCache();

    return $report;
  }

  /**
   * Duplicates a bot with an account's overrides applied.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $bot
   *   The bot the account used.
   * @param string $account_name
   *   The account's name, appended to the copy's name.
   * @param array<string, string|int> $overrides
   *   Non-empty override values keyed by column.
   */
  private function copyWithOverrides(ContentEntityInterface $bot, string $account_name, array $overrides): ContentEntityInterface {
    $copy = $bot->createDuplicate();
    $copy->set('name', mb_substr($bot->label() . ' — ' . $account_name, 0, 255));
    if (isset($overrides['prompt_override'])) {
      $copy->set('system_prompt', $overrides['prompt_override']);
    }
    if (isset($overrides['model_override'])) {
      $copy->set('model', $overrides['model_override']);
    }
    if (isset($overrides['knowledge_base'])) {
      $copy->set('knowledge_base', $overrides['knowledge_base']);
    }
    $copy->set('web_widget_enabled', FALSE);
    $copy->set('web_widget_token', Bot::generateWidgetToken());
    $copy->set('web_widget_api_key', '');
    $copy->save();

    return $copy;
  }

}
