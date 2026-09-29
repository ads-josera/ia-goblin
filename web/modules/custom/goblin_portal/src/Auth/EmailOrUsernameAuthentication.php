<?php

declare(strict_types=1);

namespace Drupal\goblin_portal\Auth;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\user\UserAuthenticationInterface;
use Drupal\user\UserAuthInterface;
use Drupal\user\UserInterface;

/**
 * Lets people sign in with their e-mail address as well as their username.
 *
 * Decorates user.auth and only widens lookupAccount(): the password check,
 * the rehash, and the flood control in UserLoginForm are core's, unchanged.
 * The username always wins, so an account whose name looks like an e-mail
 * keeps signing in exactly as before. E-mail addresses are unique per account
 * (core's UserMailUnique constraint), so a match is never ambiguous.
 */
final class EmailOrUsernameAuthentication implements UserAuthInterface, UserAuthenticationInterface {

  public function __construct(
    private readonly UserAuthenticationInterface $inner,
    private readonly EntityTypeManagerInterface $entityTypeManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function lookupAccount($identifier): UserInterface|false {
    $account = $this->inner->lookupAccount($identifier);
    if ($account !== FALSE || !is_string($identifier) || filter_var($identifier, FILTER_VALIDATE_EMAIL) === FALSE) {
      return $account;
    }

    $accounts = $this->entityTypeManager->getStorage('user')->loadByProperties(['mail' => $identifier]);
    $account = reset($accounts);
    return $account instanceof UserInterface ? $account : FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function authenticateAccount(UserInterface $account, #[\SensitiveParameter] string $password): bool {
    return $this->inner->authenticateAccount($account, $password);
  }

  /**
   * {@inheritdoc}
   *
   * Kept for callers of the deprecated UserAuthInterface; built on the new
   * methods so it also accepts an e-mail and triggers no deprecation.
   */
  public function authenticate($username, #[\SensitiveParameter] $password) {
    if (empty($username) || strlen((string) $password) === 0) {
      return FALSE;
    }
    $account = $this->lookupAccount($username);
    return $account && $this->authenticateAccount($account, (string) $password) ? (int) $account->id() : FALSE;
  }

}
