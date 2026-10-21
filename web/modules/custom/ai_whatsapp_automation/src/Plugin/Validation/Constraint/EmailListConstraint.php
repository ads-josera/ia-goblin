<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\Validation\Constraint;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Validation\Attribute\Constraint;
use Symfony\Component\Validator\Constraint as SymfonyConstraint;

/**
 * A list of e-mail addresses, one per line (commas also accepted).
 */
#[Constraint(
  id: 'AiWhatsappEmailList',
  label: new TranslatableMarkup('List of e-mail addresses', [], ['context' => 'Validation']),
)]
final class EmailListConstraint extends SymfonyConstraint {

  /**
   * Message shown for the first address that is not valid.
   */
  public string $message = '«%value» is not a valid e-mail address. Write one address per line.';

}
