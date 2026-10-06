<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Application\AI;

use Drupal\Component\Transliteration\TransliterationInterface;
use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Hands a conversation from a reception bot to the bot of the chosen area.
 *
 * A bot with "menu bots" is a reception: it never calls the model. It answers
 * with a numbered menu until the contact picks an option (its number, or one
 * of the area bot's keywords), then the conversation's bot becomes that area
 * bot and the area bot answers the same message. Writing "menú" at any time
 * goes back to the reception. Same number, same chat link: the switch is the
 * conversation's `bot` field, which every service already reads first.
 */
final class MenuRouter {

  /**
   * What the contact writes to go back to the menu, once normalized.
   */
  private const MENU_WORD = 'menu';

  /**
   * Shown above the options when the reception has no menu message.
   */
  private const DEFAULT_INTRO = 'Hola, ¿con qué área quieres hablar?';

  /**
   * What the area bot reads instead of a bare option number.
   *
   * "2" alone, after an older numbered list in the same conversation, was
   * read as option 2 of that list ("Renovación") instead of the area. The
   * stored message stays "2": only the model gets this sentence.
   */
  private const CHOICE_NOTE = '(El cliente eligió «%s» en el menú de áreas de Goblin Creative. Salúdalo brevemente y pregúntale en qué le ayudas dentro de esta área. El número que escribió no es una opción de ninguna lista anterior de esta conversación.)';

  /**
   * Shown below the options.
   */
  private const FOOTER = 'Responde con el número. Escribe «menú» en cualquier momento para volver a estas opciones.';

  public function __construct(
    private readonly TransliterationInterface $transliteration,
  ) {
  }

  /**
   * Decides which bot answers this message, or that the menu answers it.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $conversation
   *   The conversation; saved when its bot changes.
   * @param \Drupal\Core\Entity\ContentEntityInterface $bot
   *   The conversation's current bot.
   * @param string $message
   *   What the contact wrote.
   *
   * @return array{bot: \Drupal\Core\Entity\ContentEntityInterface, menu: string, message: string}
   *   The bot that handles the message; the menu text when the menu is the
   *   answer (empty string otherwise); and the message the model must read,
   *   which is a description of the choice when an area was picked by its
   *   number. The conversation is saved when its bot changes.
   */
  public function route(ContentEntityInterface $conversation, ContentEntityInterface $bot, string $message): array {
    $reception = $this->reception($conversation, $bot);
    if ($reception === NULL) {
      return ['bot' => $bot, 'menu' => '', 'message' => $message];
    }

    $options = $this->options($reception);
    if ($bot->id() !== $reception->id()) {
      if ($this->normalize($message) !== self::MENU_WORD) {
        return ['bot' => $bot, 'menu' => '', 'message' => $message];
      }
      $this->assign($conversation, $reception, $reception);

      return ['bot' => $reception, 'menu' => $this->menuText($reception, $options), 'message' => $message];
    }

    $chosen = $this->match($message, $options);
    if ($chosen !== NULL) {
      $this->assign($conversation, $chosen, $reception);
      $by_number = (bool) preg_match('/^\d{1,2}$/', $this->normalize($message));

      return [
        'bot' => $chosen,
        'menu' => '',
        'message' => $by_number ? sprintf(self::CHOICE_NOTE, $this->label($chosen)) : $message,
      ];
    }

    return ['bot' => $reception, 'menu' => $this->menuText($reception, $options), 'message' => $message];
  }

  /**
   * Whether a bot works as a reception (it has at least one active option).
   */
  public function isReception(ContentEntityInterface $bot): bool {
    return $this->options($bot) !== [];
  }

  /**
   * The menu a reception bot shows, for the web chat welcome or previews.
   */
  public function menuFor(ContentEntityInterface $reception): string {
    return $this->menuText($reception, $this->options($reception));
  }

  /**
   * Returns the reception this conversation belongs to, if any.
   *
   * The one recorded on the conversation, or the current bot when it is a
   * reception itself (the conversation has not chosen an area yet).
   */
  private function reception(ContentEntityInterface $conversation, ContentEntityInterface $bot): ?ContentEntityInterface {
    if ($conversation->hasField('reception_bot') && !$conversation->get('reception_bot')->isEmpty()) {
      $reception = $conversation->get('reception_bot')->entity;
      if ($reception instanceof ContentEntityInterface && $this->isActive($reception) && $this->isReception($reception)) {
        return $reception;
      }
    }

    return $this->isReception($bot) ? $bot : NULL;
  }

  /**
   * Returns the active area bots of a reception, in menu order.
   *
   * @return \Drupal\Core\Entity\ContentEntityInterface[]
   *   Area bots keyed by option number, starting at 1.
   */
  private function options(ContentEntityInterface $reception): array {
    if (!$reception->hasField('menu_bots')) {
      return [];
    }

    $options = [];
    foreach ($reception->get('menu_bots')->referencedEntities() as $area_bot) {
      if ($area_bot instanceof ContentEntityInterface && $area_bot->id() !== $reception->id() && $this->isActive($area_bot)) {
        $options[count($options) + 1] = $area_bot;
      }
    }

    return $options;
  }

  /**
   * Returns the area bot the message picks, or NULL.
   *
   * A number picks its option. Otherwise the keywords decide, but only when
   * exactly one area matches: "factura de soporte" stays on the menu instead
   * of guessing.
   *
   * @param string $message
   *   What the contact wrote.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $options
   *   Area bots keyed by option number.
   */
  private function match(string $message, array $options): ?ContentEntityInterface {
    $text = $this->normalize($message);
    if (preg_match('/^(\d{1,2})$/', $text, $number)) {
      return $options[(int) $number[1]] ?? NULL;
    }

    $matches = [];
    foreach ($options as $number => $area_bot) {
      foreach ($this->keywords($area_bot) as $keyword) {
        if (preg_match('/(^| )' . preg_quote($keyword, '/') . '( |$)/', $text)) {
          $matches[$number] = $area_bot;
          break;
        }
      }
    }

    return count($matches) === 1 ? reset($matches) : NULL;
  }

  /**
   * Returns an area bot's keywords, normalized like the messages.
   *
   * @return string[]
   *   Keywords.
   */
  private function keywords(ContentEntityInterface $area_bot): array {
    $raw = $this->fieldValue($area_bot, 'menu_keywords');

    return array_values(array_filter(array_map(
      $this->normalize(...),
      preg_split('/[\r\n,]+/', $raw) ?: [],
    )));
  }

  /**
   * Builds the menu: intro, numbered options, how to choose.
   *
   * @param \Drupal\Core\Entity\ContentEntityInterface $reception
   *   The reception bot.
   * @param \Drupal\Core\Entity\ContentEntityInterface[] $options
   *   Area bots keyed by option number.
   */
  private function menuText(ContentEntityInterface $reception, array $options): string {
    $lines = [];
    foreach ($options as $number => $area_bot) {
      $lines[] = $this->keycap($number) . ' ' . $this->label($area_bot);
    }
    $intro = trim($this->fieldValue($reception, 'menu_message')) ?: self::DEFAULT_INTRO;

    return $intro . "\n\n" . implode("\n", $lines) . "\n\n" . self::FOOTER;
  }

  /**
   * How an area bot appears in the menu.
   */
  private function label(ContentEntityInterface $area_bot): string {
    return trim($this->fieldValue($area_bot, 'menu_label')) ?: (string) $area_bot->label();
  }

  /**
   * Returns 1️⃣…9️⃣ for one-digit options, as the existing prompts do.
   */
  private function keycap(int $number): string {
    return $number < 10 ? $number . "\u{FE0F}\u{20E3}" : $number . '.';
  }

  /**
   * Lowercase, no accents, no emoji or punctuation, single spaces.
   *
   * "1️⃣", "1.", " 1 " all become "1"; "Menú" and "MENU!" become "menu".
   */
  private function normalize(string $text): string {
    $text = mb_strtolower($this->transliteration->transliterate(trim($text), 'es', ''));
    $text = preg_replace('/[^a-z0-9ñ]+/u', ' ', $text) ?? '';

    return trim($text);
  }

  /**
   * Points the conversation to a bot and remembers its reception.
   */
  private function assign(ContentEntityInterface $conversation, ContentEntityInterface $bot, ContentEntityInterface $reception): void {
    $conversation->set('bot', $bot->id());
    if ($conversation->hasField('reception_bot')) {
      $conversation->set('reception_bot', $reception->id());
    }
    $conversation->save();
  }

  /**
   * Whether a bot is active.
   */
  private function isActive(ContentEntityInterface $bot): bool {
    return !$bot->hasField('status') || $bot->get('status')->value === 'active';
  }

  /**
   * Reads a scalar field value.
   */
  private function fieldValue(ContentEntityInterface $entity, string $field_name): string {
    if (!$entity->hasField($field_name) || $entity->get($field_name)->isEmpty()) {
      return '';
    }

    return (string) $entity->get($field_name)->value;
  }

}
