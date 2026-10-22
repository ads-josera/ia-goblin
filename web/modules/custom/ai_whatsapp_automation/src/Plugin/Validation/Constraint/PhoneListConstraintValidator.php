<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\Validation\Constraint;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the AiWhatsappPhoneList constraint.
 *
 * Spaces, dashes and a missing Mexican mobile «1» are fixed when sending
 * (ProviderMessageSenderService::normalizeTwilioPhone), but a number without
 * its country code (a local «55 1234 5678») is sent to the wrong number and
 * the lead notice never arrives, with no error anywhere. It is rejected when
 * the bot is saved instead.
 */
final class PhoneListConstraintValidator extends ConstraintValidator {

  /**
   * Fewest digits of a number with country code (E.164 numbers have 8–15).
   *
   * 11 rejects the 10-digit local numbers people type in Mexico.
   */
  private const MIN_DIGITS = 11;

  /**
   * Most digits of an E.164 number.
   */
  private const MAX_DIGITS = 15;

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof PhoneListConstraint);
    $raw = '';
    if (is_object($value) && method_exists($value, 'isEmpty') && !$value->isEmpty()) {
      $raw = (string) $value->value;
    }
    foreach (preg_split('/[\r\n,;]+/', $raw) ?: [] as $number) {
      $number = trim($number);
      if ($number === '') {
        continue;
      }
      $digits = strlen(preg_replace('/\D+/', '', $number) ?? '');
      if ($digits < self::MIN_DIGITS || $digits > self::MAX_DIGITS) {
        $this->context->addViolation($constraint->message, ['%value' => $number]);
        return;
      }
    }
  }

}
