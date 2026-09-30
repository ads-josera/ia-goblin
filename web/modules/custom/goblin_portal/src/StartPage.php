<?php

declare(strict_types=1);

namespace Drupal\goblin_portal;

use Drupal\ai_whatsapp_automation\Ui\PanelNavigation;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;

/**
 * Decides where a person starts: the one place for that rule.
 *
 * Used by the front page redirect and by the sign-in form, so both always
 * agree on where a signed-in user lands.
 */
final class StartPage {

  public function __construct(
    private readonly PanelNavigation $panelNavigation,
  ) {
  }

  /**
   * Returns where this account should start.
   *
   * Anonymous visitors go to the sign-in form. Signed-in users go to the
   * first panel section they may open, in the panel menu's order: the
   * dashboard for administrators and client agents, Bots for managers.
   * Anyone with no section goes to their own account page, so nobody is
   * ever sent to an "access denied" screen.
   */
  public function urlFor(AccountInterface $account): Url {
    if ($account->isAnonymous()) {
      return Url::fromRoute('user.login');
    }
    return $this->panelNavigation->firstSection($account)
      ?? Url::fromRoute('entity.user.canonical', ['user' => $account->id()]);
  }

}
