<?php

declare(strict_types=1);

namespace Drupal\Tests\ai_whatsapp_automation\Unit;

use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Every English text of the bot and its form has a Spanish translation.
 *
 * The site is in Spanish; translations/es.po ships with the module and is
 * imported on deployment (drush locale:update). A new field added with an
 * English label and no entry here would show up in English to the team.
 */
#[Group('ai_whatsapp_automation')]
final class BotFormTranslationTest extends UnitTestCase {

  /**
   * Files whose user-facing texts must be translated.
   */
  private const SOURCES = [
    'src/Entity/Bot.php',
    'src/Form/AutomationEntityForm.php',
  ];

  /**
   * Every t() / $this->t() literal is in es.po (with its context, if any).
   */
  public function testEveryTextIsTranslated(): void {
    $module = dirname(__DIR__, 3);
    $translated = $this->poEntries($module . '/translations/es.po');

    $missing = [];
    foreach (self::SOURCES as $source) {
      $code = (string) file_get_contents($module . '/' . $source);
      preg_match_all("/(?<![\\w])t\\('((?:[^'\\\\]|\\\\.)*)'(?:,\\s*\\[[^\\]]*\\],\\s*\\['context'\\s*=>\\s*'([^']+)'\\])?/", $code, $matches, PREG_SET_ORDER);
      foreach ($matches as $match) {
        $text = stripslashes($match[1]);
        // Texts written in Spanish in the source need no translation.
        if (preg_match('/[áéíóúñ¿¡«]|^(Siguiente paso|La cuenta|@field:)/u', $text)) {
          continue;
        }
        $key = ($match[2] ?? '') . "\x04" . $text;
        if (!isset($translated[$key])) {
          $missing[] = ($match[2] ?? '' ? '[' . $match[2] . '] ' : '') . $text . '  (' . $source . ')';
        }
      }
    }

    $this->assertSame([], array_values(array_unique($missing)), 'Add these to translations/es.po.');
  }

  /**
   * Reads msgctxt/msgid pairs with a non-empty msgstr.
   *
   * @return array<string, true>
   *   Keys "context\x04msgid".
   */
  private function poEntries(string $file): array {
    $entries = [];
    $context = '';
    $id = NULL;
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
      if (preg_match('/^msgctxt "(.*)"$/', $line, $m)) {
        $context = stripcslashes($m[1]);
      }
      elseif (preg_match('/^msgid "(.*)"$/', $line, $m)) {
        $id = stripcslashes($m[1]);
      }
      elseif (preg_match('/^msgstr "(.+)"$/', $line) && $id !== NULL) {
        $entries[$context . "\x04" . $id] = TRUE;
        $context = '';
        $id = NULL;
      }
    }

    return $entries;
  }

}
