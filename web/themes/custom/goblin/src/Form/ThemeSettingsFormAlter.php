<?php

declare(strict_types=1);

namespace Drupal\goblin\Form;

use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\goblin\Color\ColorScheme;

/**
 * Adds brand colors and logo formats to the core theme settings form.
 *
 * Logo and favicon uploads are core's (ThemeSettingsForm); this only widens
 * the logo formats and documents them. Colors are saved by core too: every
 * leftover form value lands in goblin.settings, so the "colors" key needs no
 * submit handler — only validation and the schema in config/schema.
 *
 * Validation is attached as #element_validate on purpose. Adding to
 * $form['#validate'] from a theme alter runs before FormBuilder::prepareForm()
 * and would stop core from registering its own ::validateForm, which handles
 * the logo and favicon uploads.
 */
final class ThemeSettingsFormAlter {

  use StringTranslationTrait;

  /**
   * Logo formats: core's list plus WebP.
   */
  public const LOGO_EXTENSIONS = 'png gif jpg jpeg apng webp svg';

  /**
   * Alters the theme settings form.
   *
   * @param array<string, mixed> $form
   *   The form.
   * @param array<string, mixed> $current_colors
   *   The colors currently stored in goblin.settings.
   */
  public function alter(array &$form, array $current_colors): void {
    $scheme = ColorScheme::fromSettings($current_colors)->toArray();

    $form['goblin_colors'] = [
      '#type' => 'details',
      '#title' => $this->t('Colores de la marca'),
      '#open' => TRUE,
      '#weight' => -20,
      '#description' => $this->t('El resto de tonos (texto atenuado, bordes, texto sobre los botones, estado al pasar el ratón) se calculan a partir de estos para que siempre se lean bien.'),
    ];
    $form['goblin_colors']['colors'] = [
      '#type' => 'container',
      '#tree' => TRUE,
      '#element_validate' => [[self::class, 'validateColors']],
    ];

    foreach ($this->roles() as $role => $labels) {
      $form['goblin_colors']['colors'][$role] = [
        '#type' => 'color',
        '#title' => $labels['title'],
        '#description' => $labels['description'],
        '#default_value' => $scheme[$role],
      ];
    }

    $form['goblin_colors']['colors']['reset'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Restablecer los colores predeterminados al guardar'),
    ];

    if (isset($form['logo']['settings']['logo_upload'])) {
      $form['logo']['settings']['logo_upload']['#upload_validators']['FileExtension']['extensions'] = self::LOGO_EXTENSIONS;
      $form['logo']['settings']['logo_upload']['#description'] = $this->t('Formatos: @formats. Se usa en la pantalla de acceso (hasta 176 px de ancho) y en el encabezado (48 px de alto). Recomendado: SVG, que se ve nítido a cualquier tamaño.', [
        '@formats' => strtoupper(str_replace(' ', ', ', self::LOGO_EXTENSIONS)),
      ]);
    }
    if (isset($form['favicon']['settings']['favicon_upload'])) {
      $form['favicon']['settings']['favicon_upload']['#description'] = $this->t('Formatos: ICO, PNG, GIF, JPG, APNG, SVG, WEBP. Usa una imagen cuadrada de al menos 48 × 48 px.');
    }
  }

  /**
   * Element validation: refuses unreadable text, applies the reset.
   *
   * @param array<string, mixed> $element
   *   The colors container.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state.
   */
  public static function validateColors(array &$element, FormStateInterface $form_state): void {
    $values = $form_state->getValue($element['#parents']);

    if (!empty($values['reset'])) {
      $form_state->setValue($element['#parents'], ColorScheme::DEFAULTS);
      return;
    }
    unset($values['reset']);

    $scheme = ColorScheme::fromSettings($values);
    $roles = (new self())->roles();
    foreach ($scheme->textContrastFailures() as $failure) {
      $form_state->setError($element[$failure['against']], t('El texto no se lee sobre «@role»: contraste @ratio:1, el mínimo es @min:1. Oscurece el texto o aclara ese color.', [
        '@role' => $roles[$failure['against']]['title'],
        '@ratio' => $failure['ratio'],
        '@min' => ColorScheme::TEXT_CONTRAST,
      ]));
    }

    if (!$scheme->linkUsesAccent() && !$form_state->hasAnyErrors()) {
      \Drupal::messenger()->addWarning(t('El color principal no tiene contraste suficiente para usarse en enlaces sobre el fondo; los enlaces usarán el color del texto (siguen subrayados). Los botones sí usan el color principal.'));
    }

    // Store exactly the normalized roles, never the reset flag.
    $form_state->setValue($element['#parents'], $scheme->toArray());
  }

  /**
   * Editable roles with their labels.
   *
   * @return array<string, array{title: \Drupal\Core\StringTranslation\TranslatableMarkup, description: \Drupal\Core\StringTranslation\TranslatableMarkup}>
   *   Keyed like ColorScheme::DEFAULTS.
   */
  private function roles(): array {
    return [
      'accent' => [
        'title' => $this->t('Color principal'),
        'description' => $this->t('Botones, enlaces y el indicador de foco del teclado.'),
      ],
      'header' => [
        'title' => $this->t('Encabezado y pie'),
        'description' => $this->t('Franja superior con el logo y el menú, y pie de página.'),
      ],
      'background' => [
        'title' => $this->t('Fondo de la página'),
        'description' => $this->t('Detrás de todo el contenido.'),
      ],
      'surface' => [
        'title' => $this->t('Superficie'),
        'description' => $this->t('Tarjetas, formularios y campos de texto.'),
      ],
      'text' => [
        'title' => $this->t('Texto'),
        'description' => $this->t('Texto principal. Debe leerse sobre el fondo y la superficie.'),
      ],
    ];
  }

}
