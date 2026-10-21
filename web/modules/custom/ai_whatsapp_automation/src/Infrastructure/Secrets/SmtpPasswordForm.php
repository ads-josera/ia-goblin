<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Infrastructure\Secrets;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;

/**
 * Keeps the SMTP password typed on the smtp module's form out of config.
 *
 * The smtp module stores its password in smtp.settings, which is exported to
 * git (`drush cex`) and reset to the exported, empty value on every
 * deployment (`drush cim`): the site would stop sending mail after the next
 * deployment. The password goes to SecretStore instead, like the provider
 * keys, and SecretsConfigOverride hands it to the smtp module at runtime.
 *
 * A password in settings.local.php still wins; the field then stays disabled
 * (the smtp module's own behaviour for overridden values).
 */
final class SmtpPasswordForm {

  /**
   * The secret key of the SMTP password.
   */
  public const KEY = 'smtp.settings:smtp_password';

  /**
   * Alters the smtp module's settings form (smtp_admin_settings).
   */
  public static function alter(array &$form): void {
    if (!isset($form['auth']['smtp_password']) || SecretStore::isDefinedInSettings(self::KEY)) {
      return;
    }
    $stored = \Drupal::service('ai_whatsapp_automation.secrets')->all()[self::KEY] ?? '';

    // A stored secret reads as "overridden" to the smtp module, which would
    // lock the field: the whole point is being able to change it here.
    $form['auth']['smtp_password']['#disabled'] = FALSE;
    $form['auth']['smtp_password']['#default_value'] = '';
    $form['auth']['smtp_password']['#description'] = new TranslatableMarkup('@state It is saved outside the site configuration: it is not exported to git and deployments do not erase it.', [
      '@state' => $stored !== '' ? new TranslatableMarkup('A password is saved; leave the field empty to keep it.') : new TranslatableMarkup('No password saved yet.'),
    ]);
    // First, so the smtp module never gets the value to write into config.
    array_unshift($form['#submit'], [self::class, 'submit']);
  }

  /**
   * Stores a newly typed password and hides it from the smtp module.
   */
  public static function submit(array &$form, FormStateInterface $form_state): void {
    $password = (string) $form_state->getValue('smtp_password', '');
    $username = trim((string) $form_state->getValue('smtp_username', ''));
    $secrets = \Drupal::service('ai_whatsapp_automation.secrets');
    if ($username === '') {
      // No user, no password: the smtp module's own rule.
      $secrets->set(self::KEY, '');
    }
    elseif ($password !== '') {
      $secrets->set(self::KEY, $password);
    }
    $form_state->unsetValue('smtp_password');
    \Drupal::configFactory()->reset('smtp.settings');
  }

}
