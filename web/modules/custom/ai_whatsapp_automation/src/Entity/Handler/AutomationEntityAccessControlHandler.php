<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Entity\Handler;

use Drupal\ai_whatsapp_automation\Access\ClientAccess;
use Drupal\ai_whatsapp_automation\Access\SectionAccess;
use Drupal\Core\Access\AccessResult;
use Drupal\Core\Entity\EntityAccessControlHandler;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Session\AccountInterface;

/**
 * Access control for AI WhatsApp Automation entities.
 *
 * Administrators manage everything. Section permissions (SectionAccess)
 * open one area each (bots, accounts, knowledge, operator log) for every
 * client. Client users may only view the conversations, messages and leads
 * of their own client; they act on them through dedicated forms (reply,
 * close, lead status), never through the generic edit and delete forms.
 */
final class AutomationEntityAccessControlHandler extends EntityAccessControlHandler {

  /**
   * {@inheritdoc}
   *
   * Without this, core turns "view label" into "view" before checkAccess()
   * runs, and managers could not read a client's name without being able to
   * open the client record.
   */
  protected $viewLabelOperation = TRUE;

  /**
   * {@inheritdoc}
   */
  protected function checkAccess(EntityInterface $entity, $operation, AccountInterface $account): AccessResult {
    $entity_type_id = $entity->getEntityTypeId();
    $admin = SectionAccess::entityAccess($account, $entity_type_id, $operation)->cachePerPermissions();

    // Configurators pick and read client names (bot and account forms, list
    // columns) without being able to open the client record itself.
    if ($entity_type_id === 'ai_whatsapp_client' && $operation === 'view label' && !$admin->isAllowed()) {
      return AccessResult::allowedIfHasPermissions($account, SectionAccess::CONFIGURATION, 'OR')->cachePerPermissions();
    }

    // A client's own records: their label is as visible as the record.
    if ($admin->isAllowed() || !in_array($operation, ['view', 'view label'], TRUE) || !isset(ClientAccess::CLIENT_PATHS[$entity_type_id])) {
      return $admin;
    }

    $client_access = \Drupal::service('ai_whatsapp_automation.client_access');

    return AccessResult::allowedIf($client_access->ownsRecord($account, $entity, ClientAccess::VIEW_PERMISSION))
      ->cachePerUser()
      ->addCacheableDependency($entity);
  }

  /**
   * {@inheritdoc}
   */
  protected function checkCreateAccess(AccountInterface $account, array $context, $entity_bundle = NULL): AccessResult {
    return SectionAccess::entityAccess($account, $this->entityTypeId, 'create')->cachePerPermissions();
  }

}
