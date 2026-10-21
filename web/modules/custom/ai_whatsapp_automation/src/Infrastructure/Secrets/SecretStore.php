<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Infrastructure\Secrets;

use Drupal\Core\State\StateInterface;

/**
 * Provider credentials saved from the settings form, kept out of config.
 *
 * Secrets used to live in ai_whatsapp_automation.settings, which is exported
 * to git (`drush cex`) and reset on every deployment (`drush cim`, where the
 * exported value is empty on purpose). They live in State instead: same
 * database, never exported, never touched by a config import.
 *
 * Code keeps reading them from config as before: SecretsConfigOverride puts
 * these values on top of ai_whatsapp_automation.settings at runtime. A value
 * set in settings.php / settings.local.php still wins over them.
 */
final class SecretStore {

  /**
   * Config keys that hold secrets.
   *
   * "group.name" is a key of ai_whatsapp_automation.settings;
   * "config.name:key" is a top-level key of another module's config.
   */
  public const KEYS = [
    'openai.api_key',
    'twilio.auth_token',
    'whatsapp_cloud.access_token',
    'whatsapp_cloud.verify_token',
    'whatsapp_cloud.app_secret',
    'evolution.api_key',
    // Outgoing mail (smtp module): typed on its own form, see SmtpPasswordForm.
    'smtp.settings:smtp_password',
  ];

  /**
   * Config holding the keys written without a config name.
   */
  public const DEFAULT_CONFIG = 'ai_whatsapp_automation.settings';

  private const STATE_KEY = 'ai_whatsapp_automation.secrets';

  public function __construct(
    private readonly StateInterface $state,
  ) {
  }

  /**
   * All stored secrets, keyed like self::KEYS. Empty values are left out.
   *
   * @return array<string, string>
   *   Config key => secret.
   */
  public function all(): array {
    $stored = $this->state->get(self::STATE_KEY, []);
    return array_filter(
      array_intersect_key(is_array($stored) ? $stored : [], array_flip(self::KEYS)),
      static fn ($value): bool => is_string($value) && $value !== '',
    );
  }

  /**
   * Stores a secret. An empty value removes it.
   */
  public function set(string $key, string $value): void {
    if (!in_array($key, self::KEYS, TRUE)) {
      throw new \InvalidArgumentException(sprintf('Unknown secret "%s".', $key));
    }
    $stored = $this->all();
    $value = trim($value);
    if ($value === '') {
      unset($stored[$key]);
    }
    else {
      $stored[$key] = $value;
    }
    $this->state->set(self::STATE_KEY, $stored);
  }

  /**
   * Whether settings.php / settings.local.php defines this secret.
   *
   * That value wins over the stored one, so the form must say so instead of
   * letting someone change a key that has no effect.
   */
  public static function isDefinedInSettings(string $key): bool {
    [$config_name, $path] = self::split($key);
    $value = $GLOBALS['config'][$config_name] ?? [];
    foreach ($path as $part) {
      if (!is_array($value) || !array_key_exists($part, $value)) {
        return FALSE;
      }
      $value = $value[$part];
    }
    return is_string($value) && $value !== '';
  }

  /**
   * Splits a secret key into its config name and the path inside it.
   *
   * @return array{0: string, 1: string[]}
   *   Config name and key path.
   */
  public static function split(string $key): array {
    if (str_contains($key, ':')) {
      [$config_name, $name] = explode(':', $key, 2);
      return [$config_name, [$name]];
    }
    return [self::DEFAULT_CONFIG, explode('.', $key, 2)];
  }

}
