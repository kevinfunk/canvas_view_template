<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Plugin\views\style;

use Drupal\canvas\Entity\PageVariant;
use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\Component\Render\FormattableMarkup;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Render\Markup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\Url;
use Drupal\views\Attribute\ViewsStyle;
use Drupal\views\Plugin\views\style\StylePluginBase;
use Drupal\views\ViewExecutable;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Selects the Canvas view template (and optional page template) for a display.
 *
 * Rendering happens in ViewTemplateThemeHooks. This plugin declares no config
 * dependency on the templates: deleting one would otherwise delete the View.
 *
 * @see \Drupal\canvas_view_template\Hook\ViewTemplateThemeHooks
 * @see \Drupal\canvas_view_template\EventSubscriber\ViewTemplatePageVariantSelectorSubscriber
 */
#[ViewsStyle(
  id: self::PLUGIN_ID,
  title: new TranslatableMarkup('Canvas view template'),
  help: new TranslatableMarkup('Displays using a Canvas view template.'),
  theme: 'views_view_unformatted',
  register_theme: FALSE,
  display_types: ['normal'],
)]
final class ViewTemplateStyle extends StylePluginBase {

  /**
   * This plugin's own ID.
   */
  public const string PLUGIN_ID = 'canvas_view_template';

  /**
   * The option key under which the selected view template id is stored.
   */
  public const string OPTION_KEY = 'view_template';

  /**
   * The option key under which the page template override is stored.
   */
  public const string PAGE_VARIANT_OPTION_KEY = 'page_variant';

  /**
   * The "Page template" value that forces core block layout.
   *
   * Unlike an empty option, which uses the site default.
   */
  public const string NO_PAGE_VARIANT_OPTION_VALUE = '_none';

  /**
   * The style options a display stores, or NULL if it doesn't use this style.
   *
   * Reads the display's own option, so it works before the style plugin is
   * built.
   *
   * @return array<string, mixed>|null
   *   The options, or NULL.
   */
  public static function displayOptions(ViewExecutable $view): ?array {
    $style = $view->display_handler->getOption('style');
    if (!\is_array($style) || ($style['type'] ?? NULL) !== self::PLUGIN_ID) {
      return NULL;
    }
    return \is_array($style['options'] ?? NULL) ? $style['options'] : [];
  }

  /**
   * {@inheritdoc}
   */
  protected $usesRowPlugin = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesFields = TRUE;

  /**
   * {@inheritdoc}
   */
  protected $usesGrouping = FALSE;

  public function __construct(
    array $configuration,
    $plugin_id,
    $plugin_definition,
    // Not readonly: PluginBase uses DependencySerializationTrait, whose
    // __wakeup() cannot re-hydrate a readonly property on PHP < 8.4.
    protected EntityTypeManagerInterface $entityTypeManager,
    protected AccountInterface $currentUser,
  ) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get(EntityTypeManagerInterface::class),
      $container->get('current_user'),
    );
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions(): array {
    $options = parent::defineOptions();
    $options[self::OPTION_KEY] = ['default' => NULL];
    $options[self::PAGE_VARIANT_OPTION_KEY] = ['default' => NULL];
    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    $view_templates = $this->entityTypeManager->getStorage(ViewTemplate::ENTITY_TYPE_ID)->loadMultiple();
    $templates = [];
    foreach ($view_templates as $view_template) {
      \assert($view_template instanceof ViewTemplate);
      $label = (string) $view_template->label();
      $templates[$view_template->id()] = $view_template->status() ? $label : (string) $this->t('@label (disabled)', ['@label' => $label]);
    }
    \asort($templates, SORT_NATURAL | SORT_FLAG_CASE);
    $options = ['' => $this->t('- None -')] + $templates;

    // Saving would otherwise quietly turn a deleted template into "- None -".
    $stored = $this->options[self::OPTION_KEY] ?? NULL;
    $missing = \is_string($stored) && $stored !== '' && !isset($templates[$stored]);
    if ($missing) {
      $options[$stored] = $this->t('- Missing: @id -', ['@id' => $stored]);
    }

    $form[self::OPTION_KEY] = [
      '#type' => 'select',
      '#title' => $this->t('View template'),
      '#description' => $missing
        ? $this->t('The saved view template "@id" no longer exists, so this display renders without it. Choose another template, or save to keep the setting.', ['@id' => $stored])
        : $this->t('The Canvas layout this display renders through.'),
      '#options' => $options,
      '#default_value' => $this->options[self::OPTION_KEY] ?? '',
      '#attributes' => ['data-cvt-template-select' => ''],
    ];

    // Links open in a new tab so unsaved View edits are not lost.
    $current = $this->options[self::OPTION_KEY] ?? NULL;
    $current = \is_string($current) && isset($templates[$current]) ? $current : NULL;
    $edit_urls = [];
    foreach (\array_keys($view_templates) as $template_id) {
      $edit_urls[$template_id] = Url::fromRoute('canvas.boot.entity', [
        'entity_type' => ViewTemplate::ENTITY_TYPE_ID,
        'entity' => $template_id,
      ])->toString();
    }
    $form['template_links'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['description', 'form-item__description']],
      'edit' => [
        '#type' => 'html_tag',
        '#tag' => 'span',
        '#attributes' => ['data-cvt-edit-wrapper' => '', 'hidden' => $current === NULL],
        'link' => [
          '#type' => 'link',
          '#title' => $this->newTabLinkTitle($this->t('Edit this view template in Canvas')),
          '#url' => $current !== NULL ? Url::fromRoute('canvas.boot.entity', [
            'entity_type' => ViewTemplate::ENTITY_TYPE_ID,
            'entity' => $current,
          ]) : Url::fromRoute('<none>'),
          '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        ],
        // Outside the link, so it isn't underlined with it.
        'separator' => ['#markup' => Markup::create('<span aria-hidden="true"> · </span>')],
      ],
      'create' => [
        '#type' => 'link',
        '#title' => $this->newTabLinkTitle($this->t('Create or manage view templates')),
        '#url' => Url::fromRoute('canvas.boot.app', ['extension_id' => 'view_templates']),
        '#attributes' => ['target' => '_blank', 'rel' => 'noopener'],
        '#access' => $this->currentUser->hasPermission(ViewTemplate::ADMIN_PERMISSION),
      ],
    ];
    $form['#attached']['library'][] = 'canvas_view_template/style_options';
    $form['#attached']['drupalSettings']['canvasViewTemplate']['editUrls'] = $edit_urls;

    $page_variants = $this->entityTypeManager->getStorage(PageVariant::ENTITY_TYPE_ID)->loadMultiple();
    $page_variant_options = [
      '' => $this->t('- Site default -'),
      self::NO_PAGE_VARIANT_OPTION_VALUE => $this->t('No page template'),
    ];
    foreach ($page_variants as $page_variant) {
      \assert($page_variant instanceof PageVariant);
      $page_variant_options[$page_variant->id()] = $page_variant->label();
    }
    $form[self::PAGE_VARIANT_OPTION_KEY] = [
      '#type' => 'select',
      '#title' => $this->t('Page template'),
      '#description' => $this->t('Overrides the Canvas page template that wraps this display when it renders as a page. Choose "No page template" to render without one.'),
      '#options' => $page_variant_options,
      '#default_value' => $this->options[self::PAGE_VARIANT_OPTION_KEY] ?? '',
    ];
  }

  /**
   * Builds a link title that tells assistive technology it opens a tab.
   */
  private function newTabLinkTitle(TranslatableMarkup|string $label): FormattableMarkup {
    return new FormattableMarkup('@label<span class="visually-hidden"> (@hint)</span>', [
      '@label' => (string) $label,
      '@hint' => (string) $this->t('opens in a new tab'),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function summaryTitle() {
    $id = $this->options[self::OPTION_KEY] ?? NULL;
    $template = $id !== NULL ? $this->entityTypeManager->getStorage(ViewTemplate::ENTITY_TYPE_ID)->load($id) : NULL;
    return $template?->label() ?? $this->t('Canvas view template');
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);
    $value = $form_state->getValue(self::OPTION_KEY);
    $this->options[self::OPTION_KEY] = $value !== '' ? $value : NULL;
    $page_variant_value = $form_state->getValue(self::PAGE_VARIANT_OPTION_KEY);
    $this->options[self::PAGE_VARIANT_OPTION_KEY] = $page_variant_value !== '' ? $page_variant_value : NULL;
  }

}
