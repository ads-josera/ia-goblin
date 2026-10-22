<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\HumanOperator;

use Drupal\ai_whatsapp_automation\Application\AI\MenuRouter;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Psr\Log\LoggerInterface;

/**
 * Closes conversations nobody has written in for a while (run by cron).
 *
 * Two timers, both in ai_whatsapp_automation.settings:
 * - options.auto_close_ai_hours: conversations the bot is answering.
 * - options.auto_release_human_hours: conversations handed to the team when
 *   a lead was created. Without it they stayed in human handling forever, and
 *   a contact who came back a week later with another problem got no answer:
 *   the team works from the lead notice and never closes them in the panel.
 *
 * Closed this way (no CONVERSATION_CLOSED audit), a conversation is resumed
 * when the contact writes again, so the bot keeps the history and the cases
 * already registered. It is put back on its reception first: the contact
 * returns to the menu, not to the area of the last case. A conversation an
 * operator closed in the panel is never resumed (WebhookProcessorService).
 */
final class InactiveConversationCloser {

  /**
   * Conversations closed per status and cron run.
   */
  private const BATCH = 100;

  /**
   * The logger channel.
   */
  private readonly LoggerInterface $logger;

  /**
   * Constructs an InactiveConversationCloser object.
   */
  public function __construct(
    private readonly EntityTypeManagerInterface $entityTypeManager,
    private readonly ConfigFactoryInterface $configFactory,
    private readonly MenuRouter $menuRouter,
    private readonly TimeInterface $time,
    LoggerChannelFactoryInterface $loggerFactory,
  ) {
    $this->logger = $loggerFactory->get('ai_whatsapp_automation');
  }

  /**
   * Closes the inactive conversations of both timers.
   *
   * @return array<string, int>
   *   Closed conversations keyed by the status they had.
   */
  public function closeInactive(): array {
    $options = $this->configFactory->get('ai_whatsapp_automation.settings');

    return [
      'AI_ACTIVE' => $this->close('AI_ACTIVE', (int) ($options->get('options.auto_close_ai_hours') ?? 24)),
      'HUMAN_ASSIGNED' => $this->close('HUMAN_ASSIGNED', (int) ($options->get('options.auto_release_human_hours') ?? 24)),
    ];
  }

  /**
   * Closes the conversations of one status inactive for more than $hours.
   *
   * @return int
   *   How many were closed. 0 hours disables the timer.
   */
  private function close(string $status, int $hours): int {
    if ($hours <= 0) {
      return 0;
    }
    $storage = $this->entityTypeManager->getStorage('ai_whatsapp_conversation');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('status', $status)
      ->condition('changed', $this->time->getRequestTime() - $hours * 3600, '<')
      ->range(0, self::BATCH)
      ->execute();
    if ($ids === []) {
      return 0;
    }

    foreach ($storage->loadMultiple($ids) as $conversation) {
      if (!$conversation instanceof ContentEntityInterface) {
        continue;
      }
      $this->menuRouter->returnToReception($conversation);
      $conversation->set('status', 'CLOSED');
      if ($conversation->hasField('assigned_operator')) {
        $conversation->set('assigned_operator', NULL);
      }
      $conversation->save();
    }

    $this->logger->notice('Closed @count inactive conversations in @status after @hours hours.', [
      '@count' => (string) count($ids),
      '@status' => $status,
      '@hours' => (string) $hours,
    ]);

    return count($ids);
  }

}
