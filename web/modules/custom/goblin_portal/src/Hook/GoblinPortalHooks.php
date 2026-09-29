<?php

declare(strict_types=1);

namespace Drupal\goblin_portal\Hook;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\goblin_portal\StartPage;

/**
 * Hook implementations for goblin_portal.
 */
final class GoblinPortalHooks {

  use StringTranslationTrait;

  /**
   * Implements hook_form_FORM_ID_alter() for user_login_form.
   *
   * The copy promises what this module delivers (e-mail sign-in), so it
   * lives here and not in the theme: without the module it would be untrue.
   */
  #[Hook('form_user_login_form_alter')]
  public function formUserLoginFormAlter(array &$form, FormStateInterface $form_state): void {
    $form['name']['#title'] = $this->t('Usuario');
    $form['name']['#attributes']['placeholder'] = $this->t('usuario / correo');
    // Browsers and password managers offer both kinds of saved identifier.
    $form['name']['#attributes']['autocomplete'] = 'username email';
    $form['actions']['submit']['#value'] = $this->t('Entrar');
    // Runs after core's submit, which has already signed the user in.
    $form['#submit'][] = [self::class, 'redirectToStart'];
  }

  /**
   * Implements hook_page_attachments().
   *
   * Stops automatic hyphenation of Spanish words in Claro and the
   * Navigation sidebar (see css/spanish-admin.css).
   */
  #[Hook('page_attachments')]
  public function pageAttachments(array &$attachments): void {
    $attachments['#attached']['library'][] = 'goblin_portal/spanish-admin';
  }

  /**
   * Form submit: sends the user to their start page.
   *
   * A ?destination= (for example, from an access-denied page) still wins:
   * core's RedirectResponseSubscriber applies it over this redirect.
   */
  public static function redirectToStart(array &$form, FormStateInterface $form_state): void {
    if (empty($form_state->get('uid'))) {
      return;
    }
    $form_state->setRedirectUrl(\Drupal::service(StartPage::class)->urlFor(\Drupal::currentUser()));
  }

}
