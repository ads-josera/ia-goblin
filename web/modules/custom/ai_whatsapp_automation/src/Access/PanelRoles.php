<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Access;

use Drupal\Core\Entity\EntityTypeManagerInterface;

/**
 * The two product roles, defined in one place.
 *
 * - Client agent: a client's staff. Sees and operates only their own client's
 *   dashboard, conversations, messages and leads; never costs.
 * - Manager (gestor): the team's configurator. Sets up bots, knowledge bases,
 *   WhatsApp accounts, QR connections and routing for every client, and reads
 *   the operator log. No conversations, messages, costs, clients or settings.
 *
 * Neither gets Drupal's toolbar or navigation: the panel has its own menu
 * (PanelNavigation) with the sections each role may open and a way out.
 * Installed by hook_install() and by an update hook on existing sites.
 */
final class PanelRoles {

  public const CLIENT_AGENT = 'ai_whatsapp_client_agent';

  public const MANAGER = 'ai_whatsapp_manager';

  /**
   * Role ID => [label, permissions].
   *
   * @return array<string, array{label: string, permissions: string[]}>
   *   The role definitions.
   */
  public static function definitions(): array {
    return [
      self::CLIENT_AGENT => [
        'label' => 'Atención a clientes (AI WhatsApp)',
        'permissions' => [
          ClientAccess::VIEW_PERMISSION,
          ClientAccess::OPERATE_PERMISSION,
          'view ai whatsapp automation dashboard',
          'view the administration theme',
        ],
      ],
      self::MANAGER => [
        'label' => 'Gestor (AI WhatsApp)',
        'permissions' => [
          SectionAccess::BOTS,
          SectionAccess::RAG,
          SectionAccess::ACCOUNTS,
          SectionAccess::EVOLUTION,
          SectionAccess::ROUTING,
          SectionAccess::OPERATOR_LOG,
          'view the administration theme',
        ],
      ],
    ];
  }

  /**
   * Creates missing roles and grants their permissions.
   *
   * Idempotent. Only adds: permissions a site granted on top are kept.
   *
   * @return string[]
   *   Labels of the roles that were created.
   */
  public static function ensure(EntityTypeManagerInterface $entity_type_manager): array {
    $storage = $entity_type_manager->getStorage('user_role');
    $created = [];
    foreach (self::definitions() as $id => $definition) {
      $role = $storage->load($id);
      if ($role === NULL) {
        $role = $storage->create(['id' => $id, 'label' => $definition['label']]);
        $created[] = $definition['label'];
      }
      foreach ($definition['permissions'] as $permission) {
        $role->grantPermission($permission);
      }
      $role->save();
    }
    return $created;
  }

}
