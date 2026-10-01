<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Infrastructure\Secrets;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Puts the stored secrets on top of ai_whatsapp_automation.settings.
 *
 * Every service keeps reading `openai.api_key` & co. from config and gets the
 * value saved from the admin form. Overrides are never written to config
 * storage, so `drush cex` cannot export them and `drush cim` cannot wipe them.
 * settings.php overrides are applied after module overrides, so a value in
 * settings.local.php still wins.
 */
final class SecretsConfigOverride implements ConfigFactoryOverrideInterface {

  private const CONFIG_NAME = 'ai_whatsapp_automation.settings';

  public function __construct(
    private readonly SecretStore $secrets,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    if (!in_array(self::CONFIG_NAME, $names, TRUE)) {
      return [];
    }
    $override = [];
    foreach ($this->secrets->all() as $key => $value) {
      [$group, $name] = explode('.', $key, 2);
      $override[$group][$name] = $value;
    }
    return $override === [] ? [] : [self::CONFIG_NAME => $override];
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheSuffix() {
    return 'ai_whatsapp_automation_secrets';
  }

  /**
   * {@inheritdoc}
   */
  public function createConfigObject($name, $collection = StorageInterface::DEFAULT_COLLECTION) {
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheableMetadata($name) {
    return new CacheableMetadata();
  }

}
