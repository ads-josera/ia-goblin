<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A list of WhatsApp numbers with country code, one per line.
 */
#[Constraint(
  id: 'AiWhatsappPhoneList',
  label: new TranslatableMarkup('List of WhatsApp numbers', [], ['context' => 'Validation']),
)]
final class PhoneListConstraint extends SymfonyConstraint {

  /**
   * Message shown for the first number without a country code.
   */
  public string $message = '«%value» is missing the country code. Write it like +52 55 1234 5678, one number per line.';

}
