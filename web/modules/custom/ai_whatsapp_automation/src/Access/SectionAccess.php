<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Session\AccountInterface;

/**
 * One permission per panel section, the single source for that mapping.
 *
 * The entity access handler, the route requirements and the list query
 * alters all read from here, so they cannot drift apart.
 *
 * ClientAccess::ADMIN_PERMISSION ("administer ai whatsapp automation
 * entities") still grants every section: roles that relied on it before the
 * sections existed keep exactly the access they had.
 */
final class SectionAccess {

  public const BOTS = 'administer ai whatsapp automation bots';

  public const ACCOUNTS = 'administer ai whatsapp automation accounts';

  public const ROUTING = 'administer ai whatsapp automation routing';

  public const OPERATOR_LOG = 'view ai whatsapp automation operator log';

  public const RAG = 'administer ai whatsapp automation rag';

  public const EVOLUTION = 'administer ai whatsapp automation evolution';

  /**
   * Entity types managed by a section, and the permission that opens them.
   *
   * Types not listed (clients, conversations, messages, leads) stay under
   * ClientAccess: the umbrella permission, or a client's own records.
   */
  public const ENTITY_TYPES = [
    'ai_whatsapp_bot' => self::BOTS,
    'ai_whatsapp_account' => self::ACCOUNTS,
    'ai_whatsapp_knowledge_base' => self::RAG,
    'ai_whatsapp_knowledge_document' => self::RAG,
    'ai_whatsapp_knowledge_chunk' => self::RAG,
    'ai_whatsapp_operator_action' => self::OPERATOR_LOG,
  ];

  /**
   * Sections that only read: their permission never allows changes.
   */
  public const READ_ONLY = [self::OPERATOR_LOG];

  /**
   * Permissions that let someone configure the product for every client.
   *
   * They need to read client names (bot and account forms, list columns),
   * but not open client records.
   */
  public const CONFIGURATION = [self::BOTS, self::ACCOUNTS, self::RAG, self::EVOLUTION, self::ROUTING];

  /**
   * Returns the section permission of an entity type, or NULL.
   */
  public static function permissionFor(string $entity_type_id): ?string {
    return self::ENTITY_TYPES[$entity_type_id] ?? NULL;
  }

  /**
   * Route requirement: the section permission or the umbrella one.
   *
   * "+" means OR in Drupal permission requirements.
   */
  public static function requirement(string $permission): string {
    return $permission . '+' . ClientAccess::ADMIN_PERMISSION;
  }

  /**
   * Access to an entity of a section-managed type.
   *
   * Read-only sections grant "view" only; everything else still needs the
   * umbrella permission.
   */
  public static function entityAccess(AccountInterface $account, string $entity_type_id, string $operation): AccessResult {
    $permissions = [ClientAccess::ADMIN_PERMISSION];
    $section = self::permissionFor($entity_type_id);
    if ($section !== NULL && ($operation === 'view' || $operation === 'view label' || !in_array($section, self::READ_ONLY, TRUE))) {
      $permissions[] = $section;
    }
    return AccessResult::allowedIfHasPermissions($account, $permissions, 'OR');
  }

  /**
   * Whether the account works across all clients in this section.
   *
   * Used by the list query alters: such users are not limited to one client.
   */
  public static function isGlobal(AccountInterface $account, string $entity_type_id): bool {
    $section = self::permissionFor($entity_type_id);
    return $account->hasPermission(ClientAccess::ADMIN_PERMISSION)
      || ($section !== NULL && $account->hasPermission($section));
  }

}
