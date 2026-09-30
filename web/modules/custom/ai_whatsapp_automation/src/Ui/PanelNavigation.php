<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Ui;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Path\CurrentPathStack;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;

/**
 * The panel's own menu, for people who do not get Drupal's navigation.
 *
 * Client agents and managers have no toolbar or Navigation sidebar (they
 * would only show Drupal itself), so without this they land on a page with no
 * way to another section and no way to sign out. Every section link is access
 * checked: each role sees exactly the sections it may open, from one list.
 */
final class PanelNavigation {

  use StringTranslationTrait;

  /**
   * Sections in menu order: route name => label.
   *
   * When adding a panel section, add it here too, or users without Drupal's
   * navigation will have no way to reach it. Labels are literals so the
   * translation tools can extract them.
   *
   * @return array<string, \Drupal\Core\StringTranslation\TranslatableMarkup>
   *   Route name => label.
   */
  private function sections(): array {
    return [
      'ai_whatsapp_automation.dashboard' => $this->t('Panel'),
      'entity.ai_whatsapp_conversation.collection' => $this->t('Conversaciones'),
      'entity.ai_whatsapp_lead.collection' => $this->t('Prospectos'),
      'entity.ai_whatsapp_message.collection' => $this->t('Mensajes'),
      'entity.ai_whatsapp_bot.collection' => $this->t('Bots'),
      'entity.ai_whatsapp_knowledge_base.collection' => $this->t('Bases de conocimiento'),
      'entity.ai_whatsapp_knowledge_document.collection' => $this->t('Documentos'),
      'entity.ai_whatsapp_account.collection' => $this->t('Cuentas de WhatsApp'),
      'ai_whatsapp_automation.evolution_connections' => $this->t('Conexión QR'),
      'ai_whatsapp_automation.multibot_routing' => $this->t('Enrutamiento'),
      'entity.ai_whatsapp_operator_action.collection' => $this->t('Bitácora'),
    ];
  }

  public function __construct(
    private readonly AccountInterface $currentUser,
    private readonly CurrentPathStack $currentPath,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {
  }

  /**
   * The first section this account may open, in menu order, or NULL.
   *
   * Where someone lands after signing in: the dashboard for client agents,
   * the first configuration section for managers.
   */
  public function firstSection(AccountInterface $account): ?Url {
    foreach (array_keys($this->sections()) as $route) {
      $url = Url::fromRoute($route);
      if ($url->access($account)) {
        return $url;
      }
    }
    return NULL;
  }

  /**
   * Builds the menu, or an empty array when this user does not need it.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  public function build(): array {
    $cacheability = (new CacheableMetadata())->addCacheContexts(['user', 'url.path']);
    $empty = [];
    $cacheability->applyTo($empty);

    // Anonymous visitors have nothing to navigate; people with Drupal's own
    // navigation (administrators) already have their menu and a way out.
    if ($this->currentUser->isAnonymous() || $this->currentUser->hasPermission('access navigation')) {
      return $empty;
    }

    $path = $this->currentPath->getPath();
    $links = [];
    foreach ($this->sections() as $route => $label) {
      $url = Url::fromRoute($route);
      $access = $url->access($this->currentUser, TRUE);
      $cacheability->addCacheableDependency($access);
      if (!$access->isAllowed()) {
        continue;
      }
      $href = $url->toString();
      $links[] = [
        'title' => $label,
        'url' => $url,
        // A detail page (/…/conversations/5) keeps its section highlighted.
        'active' => $path === $href || str_starts_with($path, rtrim($href, '/') . '/'),
      ];
    }

    if ($links === []) {
      return $empty;
    }

    $navigation = $this->configFactory->get('navigation.settings');
    $site = $this->configFactory->get('system.site');
    $cacheability->addCacheableDependency($navigation)->addCacheableDependency($site);
    $logo_path = $navigation->get('logo.provider') === 'custom' ? (string) $navigation->get('logo.path') : '';

    $build = [
      '#theme' => 'ai_whatsapp_panel_navigation',
      '#links' => $links,
      '#site_name' => $site->get('name'),
      '#logo_url' => $logo_path !== '' ? $this->fileUrlGenerator->generateString($logo_path) : '',
      '#home_url' => Url::fromRoute('<front>'),
      '#account_name' => $this->currentUser->getDisplayName(),
      '#account_url' => Url::fromRoute('entity.user.edit_form', ['user' => $this->currentUser->id()]),
      '#logout_url' => Url::fromRoute('user.logout'),
      '#attached' => ['library' => ['ai_whatsapp_automation/panel_navigation']],
    ];
    $cacheability->applyTo($build);
    return $build;
  }

}
