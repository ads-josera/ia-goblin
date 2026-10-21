<?php

declare(strict_types=1);

namespace Drupal\ai_whatsapp_automation\Plugin\Validation\Constraint;

use Drupal\ai_whatsapp_automation\Application\Lead\LeadEmailNotifier;
use Drupal\Component\Utility\EmailValidatorInterface;
use Drupal\Core\DependencyInjection\ContainerInjectionInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

/**
 * Validates the AiWhatsappEmailList constraint.
 *
 * A mistyped address would silently never get a lead notice: it is rejected
 * when the bot is saved, from the form or from scripts/bots/*.php.
 */
final class EmailListConstraintValidator extends ConstraintValidator implements ContainerInjectionInterface {

  /**
   * Constructs an EmailListConstraintValidator object.
   */
  public function __construct(
    private readonly EmailValidatorInterface $emailValidator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get('email.validator'));
  }

  /**
   * {@inheritdoc}
   */
  public function validate(mixed $value, Constraint $constraint): void {
    assert($constraint instanceof EmailListConstraint);
    $raw = '';
    if (is_object($value) && method_exists($value, 'isEmpty') && !$value->isEmpty()) {
      $raw = (string) $value->value;
    }
    foreach (LeadEmailNotifier::parseAddresses($raw) as $address) {
      if (!$this->emailValidator->isValid($address)) {
        $this->context->addViolation($constraint->message, ['%value' => $address]);
        return;
      }
    }
  }

}
