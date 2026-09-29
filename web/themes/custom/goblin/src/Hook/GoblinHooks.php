<?php

declare(strict_types=1);

namespace Drupal\goblin\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\goblin\Color\ColorScheme;
use Drupal\goblin\Form\ThemeSettingsFormAlter;

/**
 * Hook implementations for goblin.
 */
class GoblinHooks {

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {
  }

  /**
   * Implements hook_page_attachments_alter().
   *
   * Prints the brand color tokens in <head>. Values are re-normalized by
   * ColorScheme on every read, so only #rrggbb strings can reach the markup.
   */
  #[Hook('page_attachments_alter')]
  public function pageAttachmentsAlter(array &$attachments): void {
    $config = $this->configFactory->get('goblin.settings');
    $scheme = ColorScheme::fromSettings($config->get('colors'));

    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'style',
        '#value' => Markup::create($scheme->toCss()),
        '#attributes' => ['data-goblin-colors' => ''],
      ],
      'goblin_color_tokens',
    ];
    $attachments['#attached']['html_head'][] = [
      [
        '#tag' => 'meta',
        '#attributes' => ['name' => 'theme-color', 'content' => $scheme->toArray()['header']],
      ],
      'goblin_theme_color',
    ];

    // Declared for correctness, but not what keeps pages fresh today: core's
    // ConfigCacheTag subscriber invalidates the whole 'rendered' tag whenever
    // any THEME.settings config is saved. ThemeSettingsTest covers the
    // behavior (anonymous cached pages get new colors), not this line.
    CacheableMetadata::createFromRenderArray($attachments)
      ->addCacheableDependency($config)
      ->applyTo($attachments);
  }

  /**
   * Implements hook_form_system_theme_settings_alter().
   */
  #[Hook('form_system_theme_settings_alter')]
  public function formSystemThemeSettingsAlter(array &$form, FormStateInterface $form_state): void {
    $colors = $this->configFactory->get('goblin.settings')->get('colors');
    (new ThemeSettingsFormAlter())->alter($form, is_array($colors) ? $colors : []);
  }

  /**
   * Implements hook_form_alter().
   *
   * Core leaves the submit of the account forms as a plain button, so the only
   * action on the sign-in screen looked secondary.
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (in_array($form_id, ['user_login_form', 'user_pass', 'user_register_form'], TRUE) && isset($form['actions']['submit'])) {
      $form['actions']['submit']['#button_type'] = 'primary';
    }
  }

  /**
   * Implements hook_preprocess_page().
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $site = $this->configFactory->get('system.site');
    $variables['goblin_site_name'] = $site->get('name');
    CacheableMetadata::createFromRenderArray($variables)
      ->addCacheableDependency($site)
      ->applyTo($variables);
  }

  /**
   * Implements hook_preprocess_image_widget().
   */
  #[Hook('preprocess_image_widget')]
  public function preprocessImageWidget(array &$variables): void {
    $data = &$variables['data'];
    // This prevents image widget templates from rendering preview container
    // HTML to users that do not have permission to access these previews.
    // @todo revisit in https://drupal.org/node/953034
    // @todo revisit in https://drupal.org/node/3114318
    if (isset($data['preview']['#access']) && $data['preview']['#access'] === FALSE) {
      unset($data['preview']);
    }
  }

}
