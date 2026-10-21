<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Infrastructure\Secrets;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryOverrideInterface;
use Drupal\Core\Config\StorageInterface;

/**
 * Puts the stored secrets on top of their config.
 *
 * Mostly ai_whatsapp_automation.settings; also smtp.settings for the outgoing
 * mail password.
 *
 * Every service keeps reading `openai.api_key` & co. from config and gets the
 * value saved from the admin form. Overrides are never written to config
 * storage, so `drush cex` cannot export them and `drush cim` cannot wipe them.
 * settings.php overrides are applied after module overrides, so a value in
 * settings.local.php still wins.
 */
final class SecretsConfigOverride implements ConfigFactoryOverrideInterface {

  public function __construct(
    private readonly SecretStore $secrets,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public function loadOverrides($names) {
    $overrides = [];
    foreach ($this->secrets->all() as $key => $value) {
      [$config_name, $path] = SecretStore::split($key);
      if (!in_array($config_name, $names, TRUE)) {
        continue;
      }
      $tree = $value;
      foreach (array_reverse($path) as $part) {
        $tree = [$part => $tree];
      }
      $overrides[$config_name] = array_replace_recursive($overrides[$config_name] ?? [], $tree);
    }
    return $overrides;
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
