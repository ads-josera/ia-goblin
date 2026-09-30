<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Controller;

use Drupal\ai_whatsapp_automation\Application\AI\BotManagerService;
use Drupal\ai_whatsapp_automation\Ui\ResponsiveTable;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Url;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays WhatsApp account to bot routing.
 */
final class MultiBotRoutingController extends ControllerBase {

  /**
   * Constructs a MultiBotRoutingController object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $automationEntityTypeManager,
    private readonly BotManagerService $botManager,
  ) {
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('entity_type.manager'),
      $container->get('ai_whatsapp_automation.bot_manager'),
    );
  }

  /**
   * Builds the routing overview.
   *
   * @return array<string, mixed>
   *   Render array.
   */
  public function overview(): array {
    $build['#attached']['library'][] = 'ai_whatsapp_automation/multibot_routing';

    $storage = $this->automationEntityTypeManager->getStorage('ai_whatsapp_account');
    $ids = $storage->getQuery()
      ->accessCheck(TRUE)
      ->sort('provider')
      ->sort('name')
      ->execute();

    $rows = [];
    foreach ($storage->loadMultiple($ids) as $account) {
      if (!$account instanceof ContentEntityInterface) {
        continue;
      }

      $bot = $this->botManager->getBotForAccount($account);
      $knowledge_base = $bot instanceof ContentEntityInterface
        ? $this->botManager->getEffectiveKnowledgeBase($bot, $account)
        : NULL;

      $client = $account->get('client')->entity ?? ($bot instanceof ContentEntityInterface ? $bot->get('client')->entity : NULL);
      $provider = $this->fieldValue($account, 'provider');
      // Incoming messages are only routed to active or connected accounts.
      $answers = $this->fieldValue($account, 'status') === 'active' || $this->fieldValue($account, 'connection_status') === 'CONNECTED';
      $rows[] = [
        'client' => $client instanceof ContentEntityInterface ? $client->label() : $this->t('Sin cliente'),
        // Two lines per cell instead of five columns (account, number,
        // status, provider, connection) that pushed the table past Claro's
        // content column: the number goes under the account, and the
        // provider with its connection under the status.
        'account' => [
          'data' => $this->stacked(
            $account->toLink()->toRenderable(),
            $this->fieldValue($account, 'phone_number') ?: $this->t('Sin número'),
            'ai-whatsapp-routing-table__number',
          ),
        ],
        'status' => [
          'data' => $this->stacked(
            $answers
              ? ['#markup' => $this->statusLabel($this->fieldValue($account, 'status'))]
              : ['#markup' => '<strong class="ai-whatsapp-routing-table__warning">' . $this->t('Inactiva: no responde') . '</strong>'],
            // Connection status is tracked only for Evolution instances;
            // Twilio and Cloud API accounts keep a stale default that reads
            // as real.
            $provider === 'evolution'
              ? $this->providerLabel($provider) . ' · ' . $this->connectionLabel($this->fieldValue($account, 'connection_status'))
              : $this->providerLabel($provider),
          ),
        ],
        'bot' => $bot instanceof ContentEntityInterface ? $bot->toLink() : $this->t('Sin bot activo'),
        'model' => [
          'data' => $bot instanceof ContentEntityInterface ? ($this->botManager->getEffectiveModel($bot, $account) ?: $this->t('Predeterminado')) : '',
          'class' => ['ai-whatsapp-routing-table__model'],
        ],
        'knowledge_base' => $knowledge_base instanceof ContentEntityInterface ? $knowledge_base->toLink() : $this->t('Ninguna'),
        'operations' => ['data' => $this->operations($account, $bot)],
      ];
    }

    $build['setup_guide'] = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['ai-whatsapp-routing-guide'],
      ],
    ];
    $build['setup_guide']['heading'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-guide__heading']],
    ];
    $build['setup_guide']['heading']['title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Configura un cliente nuevo'),
    ];
    $build['setup_guide']['heading']['description'] = [
      '#type' => 'html_tag',
      '#tag' => 'p',
      '#value' => $this->t('Sigue este orden. Cada paso te lleva al siguiente con el dato ya elegido.'),
    ];
    $build['setup_guide']['steps'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['ai-whatsapp-routing-guide__steps']],
    ];
    $steps = [
      'client' => [
        'title' => $this->t('Crea el cliente'),
        'description' => $this->t('La empresa a la que das el servicio. Todo lo demás le pertenece.'),
        'label' => $this->t('Agregar cliente'),
        'route' => 'entity.ai_whatsapp_client.add_form',
      ],
      'bot' => [
        'title' => $this->t('Crea y configura su bot'),
        'description' => $this->t('Elige el cliente y define instrucciones, modelo, base de conocimiento, límites y notificaciones de leads.'),
        'label' => $this->t('Agregar bot'),
        'route' => 'entity.ai_whatsapp_bot.add_form',
      ],
      'account' => [
        'title' => $this->t('Conecta su número de WhatsApp'),
        'description' => $this->t('Credenciales del proveedor y número; elige el bot del paso 2. Déjala activa para que responda.'),
        'label' => $this->t('Conectar número de WhatsApp'),
        'route' => 'entity.ai_whatsapp_account.add_form',
      ],
    ];
    $number = 0;
    $primary_given = FALSE;
    foreach ($steps as $key => $step) {
      $number++;
      // A step the viewer cannot do (a manager creating a client) names who
      // does it instead of offering a button that ends in "access denied".
      $url = Url::fromRoute($step['route']);
      $access = $url->access(NULL, TRUE);
      $action = $access->isAllowed()
        ? [
          '#type' => 'link',
          '#title' => $step['label'],
          '#url' => $url,
          // Only the first step the viewer can take is the primary action.
          '#attributes' => ['class' => $primary_given ? ['button'] : ['button', 'button--primary']],
        ]
        : [
          '#type' => 'html_tag',
          '#tag' => 'p',
          '#value' => $this->t('Lo hace un administrador.'),
          '#attributes' => ['class' => ['ai-whatsapp-routing-step__note']],
        ];
      $primary_given = $primary_given || $access->isAllowed();
      CacheableMetadata::createFromObject($access)->applyTo($action);
      $build['setup_guide']['steps'][$key] = [
        '#type' => 'container',
        '#attributes' => ['class' => ['ai-whatsapp-routing-step']],
        'number' => [
          '#type' => 'html_tag',
          '#tag' => 'span',
          '#value' => (string) $number,
          '#attributes' => ['class' => ['ai-whatsapp-routing-step__number']],
        ],
        'content' => [
          '#type' => 'container',
          '#attributes' => ['class' => ['ai-whatsapp-routing-step__content']],
          'title' => ['#type' => 'html_tag', '#tag' => 'h3', '#value' => $step['title']],
          'description' => ['#type' => 'html_tag', '#tag' => 'p', '#value' => $step['description']],
          'action' => $action,
        ],
      ];
    }

    $build['assignments_title'] = [
      '#type' => 'html_tag',
      '#tag' => 'h2',
      '#value' => $this->t('Asignaciones actuales'),
      '#attributes' => ['class' => ['ai-whatsapp-routing-assignments-title']],
    ];
    // Shared component: scrolls inside its own box, with an edge shadow
    // when there is more to the side, like every other list of the panel.
    $build['routing'] = ResponsiveTable::wrap([
      '#type' => 'table',
      '#attributes' => ['class' => ['ai-whatsapp-routing-table']],
      '#header' => [
        $this->t('Cliente'),
        $this->t('Cuenta'),
        $this->t('Estado'),
        $this->t('Bot'),
        $this->t('Modelo'),
        $this->t('Base de conocimiento'),
        $this->t('Acciones'),
      ],
      '#rows' => $rows,
      '#empty' => $this->t('Todavía no hay números de WhatsApp. Empieza por el paso 1: crea el cliente.'),
    ]);

    return $build;
  }

  /**
   * Returns a scalar field value.
   */
  private function fieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    $value = $entity->get($field_name)->value;

    return is_scalar($value) ? (string) $value : '';
  }

  /**
   * A main value with a secondary line under it.
   *
   * @param array<string, mixed> $main
   *   Render array of the main value.
   * @param string|\Stringable|null $secondary
   *   The secondary line, or NULL for none.
   * @param string $class
   *   Extra class for the secondary line.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function stacked(array $main, string|\Stringable|null $secondary, string $class = ''): array {
    $build = ['main' => $main];
    if ($secondary !== NULL && (string) $secondary !== '') {
      $build['secondary'] = [
        '#type' => 'html_tag',
        '#tag' => 'div',
        '#value' => (string) $secondary,
        '#attributes' => ['class' => array_filter(['ai-whatsapp-routing-table__secondary', $class])],
      ];
    }
    return $build;
  }

  /**
   * Edit links for the account and its bot, only those the viewer may use.
   *
   * The bot is what defines how a number answers, so editing it is offered
   * right here next to the account.
   *
   * @return array<string, mixed>
   *   A render array.
   */
  private function operations(ContentEntityInterface $account, ?ContentEntityInterface $bot): array {
    $links = [
      '#type' => 'container',
      '#attributes' => ['class' => ['aiwa-actions']],
    ];
    $targets = ['account' => [$this->t('Editar cuenta'), $account]];
    if ($bot instanceof ContentEntityInterface) {
      $targets['bot'] = [$this->t('Editar bot'), $bot];
    }
    foreach ($targets as $key => [$label, $entity]) {
      $url = $entity->toUrl('edit-form');
      $access = $url->access(NULL, TRUE);
      $links[$key] = [
        '#type' => 'link',
        '#title' => $label,
        '#url' => $url,
        '#access' => $access->isAllowed(),
        '#attributes' => ['class' => ['aiwa-actions__button']],
      ];
      CacheableMetadata::createFromObject($access)->applyTo($links[$key]);
    }
    return $links;
  }

  /**
   * Returns a readable provider name.
   */
  private function providerLabel(string $provider): string|\Stringable {
    return match ($provider) {
      'twilio' => 'Twilio',
      'cloud_api' => 'Cloud API',
      'evolution' => 'Evolution',
      '' => $this->t('Sin proveedor'),
      default => $provider,
    };
  }

  /**
   * Returns a readable account status.
   */
  private function statusLabel(string $status): string|\Stringable {
    return match ($status) {
      'active' => $this->t('Activa'),
      'inactive' => $this->t('Inactiva'),
      'paused' => $this->t('En pausa'),
      default => $status,
    };
  }

  /**
   * Returns a readable Evolution connection state.
   */
  private function connectionLabel(string $connection): string|\Stringable {
    return match ($connection) {
      'CONNECTED' => $this->t('Conectada'),
      'DISCONNECTED' => $this->t('Desconectada'),
      'CONNECTING' => $this->t('Conectando'),
      '' => $this->t('Sin datos'),
      default => $connection,
    };
  }

}
