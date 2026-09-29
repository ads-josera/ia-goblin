<?php

declare(strict_types=1);

namespace Drupal\goblin\Hook;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ThemeSettingsProvider;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Hook\Attribute\Hook;
use Drupal\Core\Render\Markup;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\goblin\Color\ColorScheme;
use Drupal\goblin\Form\ThemeSettingsFormAlter;

/**
 * Hook implementations for goblin.
 */
class GoblinHooks {

  /**
   * Routes shown with the split-screen sign-in layout.
   */
  public const AUTH_ROUTES = ['user.login', 'user.pass', 'user.reset.form'];

  /**
   * Account forms whose single action is the primary, large button.
   */
  private const ACCOUNT_FORMS = ['user_login_form', 'user_pass', 'user_pass_reset', 'user_register_form'];

  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
    private readonly RouteMatchInterface $routeMatch,
    private readonly ThemeSettingsProvider $themeSettings,
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
   * action on the sign-in screen looked secondary. It is also large: that
   * size is what lets it carry a white label on the brand orange (see
   * .button--large in controls.css).
   */
  #[Hook('form_alter')]
  public function formAlter(array &$form, FormStateInterface $form_state, string $form_id): void {
    if (in_array($form_id, self::ACCOUNT_FORMS, TRUE) && isset($form['actions']['submit'])) {
      $form['actions']['submit']['#button_type'] = 'primary';
      $form['actions']['submit']['#attributes']['class'][] = 'button--large';
    }
  }

  /**
   * Implements hook_theme_suggestions_page_alter().
   */
  #[Hook('theme_suggestions_page_alter')]
  public function themeSuggestionsPageAlter(array &$suggestions): void {
    if (in_array($this->routeMatch->getRouteName(), self::AUTH_ROUTES, TRUE)) {
      $suggestions[] = 'page__goblin_auth';
    }
  }

  /**
   * Implements hook_preprocess_page().
   */
  #[Hook('preprocess_page')]
  public function preprocessPage(array &$variables): void {
    $site = $this->configFactory->get('system.site');
    $variables['goblin_site_name'] = $site->get('name');
    $variables['goblin_slogan'] = $site->get('slogan');
    $variables['goblin_logo'] = $this->themeSettings->getSetting('logo.url', 'goblin');
    $variables['goblin_auth_route'] = $this->routeMatch->getRouteName();
    CacheableMetadata::createFromRenderArray($variables)
      ->addCacheableDependency($this->configFactory->get('goblin.settings'))
      ->addCacheContexts(['route'])
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
