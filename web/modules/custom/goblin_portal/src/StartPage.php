<?php

declare(strict_types=1);

namespace Drupal\goblin_portal;

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Decides where a person starts: the one place for that rule.
 *
 * Used by the front page redirect and by the sign-in form, so both always
 * agree on where a signed-in user lands.
 */
final class StartPage {

  /**
   * The AI WhatsApp dashboard: the product's home once signed in.
   */
  public const DASHBOARD_ROUTE = 'ai_whatsapp_automation.dashboard';

  /**
   * Returns where this account should start.
   *
   * Anonymous visitors go to the sign-in form. Signed-in users go to the
   * dashboard when they may see it, and to their own account page otherwise,
   * so nobody is ever sent to an "access denied" screen.
   */
  public function urlFor(AccountInterface $account): Url {
    if ($account->isAnonymous()) {
      return Url::fromRoute('user.login');
    }

    $dashboard = Url::fromRoute(self::DASHBOARD_ROUTE);
    if ($dashboard->access($account)) {
      return $dashboard;
    }
    return Url::fromRoute('entity.user.canonical', ['user' => $account->id()]);
  }

}
