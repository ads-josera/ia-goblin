<?php

declare(strict_types=1);

namespace Drupal\goblin_portal\Controller;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\goblin_portal\StartPage;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * The site front page: sends each person to where they start.
 *
 * The front page (system.site:page.front, set on install) points here, so
 * "/" and the redirect after signing out both land in the right place.
 */
final class EntryController implements ContainerInjectionInterface {

  use AutowireTrait;

  public function __construct(
    private readonly StartPage $startPage,
    private readonly AccountProxyInterface $currentUser,
  ) {
  }

  /**
   * Redirects to the sign-in form or to the dashboard.
   */
  public function redirectToStart(): RedirectResponse {
    return new RedirectResponse($this->startPage->urlFor($this->currentUser)->toString());
  }

}
