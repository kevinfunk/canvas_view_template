<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Controller;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\ViewTemplateDrafts;
use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Copies a view template under a new name, for the View templates panel.
 *
 * The copy starts from the unpublished draft if there is one, otherwise the
 * published version, and is validated before it is saved. Which Views use the
 * original is not copied.
 */
final class ViewTemplateDuplicateController extends ControllerBase {

  public function __construct(
    private readonly ViewTemplateDrafts $drafts,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static($container->get(ViewTemplateDrafts::class));
  }

  /**
   * Creates the copy, or answers with what is wrong with the request.
   */
  public function duplicate(ViewTemplate $view_template, Request $request): JsonResponse {
    if (!$this->entityTypeManager()->getAccessControlHandler(ViewTemplate::ENTITY_TYPE_ID)->createAccess()) {
      return self::failure((string) $this->t('You are not allowed to create view templates.'), 403);
    }
    $body = \json_decode($request->getContent(), TRUE);
    $id = \is_array($body) && \is_string($body['id'] ?? NULL) ? \trim($body['id']) : '';
    $label = \is_array($body) && \is_string($body['label'] ?? NULL) ? \trim($body['label']) : '';

    if ($label === '') {
      return self::failure((string) $this->t('Enter a label.'), 422);
    }
    if (\preg_match('/^[a-z][a-z0-9_]{0,63}$/', $id) !== 1) {
      return self::failure((string) $this->t('The machine name must start with a letter and use only lowercase letters, numbers and underscores.'), 422);
    }
    if ($this->entityTypeManager()->getStorage(ViewTemplate::ENTITY_TYPE_ID)->load($id) !== NULL) {
      return self::failure((string) $this->t('A view template with the machine name "@id" already exists.', ['@id' => $id]), 409);
    }

    $source = $this->drafts->latest($view_template);
    $copy = ViewTemplate::create([
      'id' => $id,
      'label' => $label,
      'description' => $source->get('description'),
      'component_tree' => $source->getComponentTree()->getValue(),
    ]);
    // Canvas only validates a tree when it is published, and a draft may not
    // be valid yet; check it as Canvas's own POST does.
    $violations = $copy->getTypedData()->validate();
    if ($violations->count() > 0) {
      $errors = [];
      foreach ($violations as $violation) {
        $errors[] = [
          'detail' => (string) $violation->getMessage(),
          'source' => ['pointer' => $violation->getPropertyPath()],
        ];
      }
      return new JsonResponse(['errors' => $errors], 422);
    }
    $copy->save();

    $response = new JsonResponse(['id' => $id, 'label' => $label], 201);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Builds the error response the panel reads (`errors[].detail`).
   */
  private static function failure(string $detail, int $status): JsonResponse {
    return new JsonResponse(['errors' => [['detail' => $detail]]], $status);
  }

}
