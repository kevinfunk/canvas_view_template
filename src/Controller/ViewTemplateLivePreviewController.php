<?php

declare(strict_types=1);

namespace Drupal\canvas_view_template\Controller;

use Drupal\canvas_view_template\Entity\ViewTemplate;
use Drupal\canvas_view_template\Preview\ViewTemplateLivePreview;
use Drupal\Component\Utility\UrlHelper;
use Drupal\Core\Asset\AttachedAssets;
use Drupal\Core\Asset\AssetResolverInterface;
use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\File\FileUrlGeneratorInterface;
use Drupal\Core\Render\BubbleableMetadata;
use Drupal\Core\Render\RenderContext;
use Drupal\Core\Render\RendererInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-renders the live preview of a view template for the full-width preview.
 *
 * The query string is the exposed filter and pager input, plus the page's
 * `ajax_page_state` as Drupal's own AJAX sends it. The response is the HTML
 * plus the CSS, JS and settings of the libraries the page does not have yet.
 */
final class ViewTemplateLivePreviewController extends ControllerBase {

  public function __construct(
    private readonly ViewTemplateLivePreview $livePreview,
    private readonly RendererInterface $renderer,
    private readonly AssetResolverInterface $assetResolver,
    private readonly FileUrlGeneratorInterface $fileUrlGenerator,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get(ViewTemplateLivePreview::class),
      $container->get('renderer'),
      $container->get('asset.resolver'),
      $container->get('file_url_generator'),
    );
  }

  /**
   * Returns the live preview's HTML, with the assets it needs.
   */
  public function render(ViewTemplate $view_template, Request $request): Response {
    // Decompressed by the AjaxPageState middleware. Removed from the query so
    // Views doesn't take it as exposed input or put it in pager links.
    $page_state = $request->attributes->get('ajax_page_state') ?? [];
    $request->query->remove('ajax_page_state');

    // Views renders fields while the View is built. Under the controller's own
    // render context their metadata (libraries included) would be discarded,
    // since a JsonResponse carries none.
    $context = new RenderContext();
    $build = $this->renderer->executeInRenderContext($context, fn() => $this->livePreview->build($view_template)) ?? ['#markup' => ''];
    if (!$context->isEmpty()) {
      BubbleableMetadata::createFromRenderArray($build)->merge($context->pop())->applyTo($build);
    }
    $html = (string) $this->renderer->renderInIsolation($build);

    $assets = AttachedAssets::createFromRenderArray($build);
    $loaded = $page_state['libraries'] ?? '';
    $assets->setAlreadyLoadedLibraries($loaded === '' ? [] : \explode(',', $loaded));
    $css = $this->assetResolver->getCssAssets($assets, FALSE);
    [$header_js, $footer_js] = $this->assetResolver->getJsAssets($assets, FALSE);
    // Read after getJsAssets(), which merges in the libraries' settings.
    $settings = $assets->getSettings();
    // The page's path is the editor preview's, not this route's.
    unset($settings['path']);
    if (isset($settings['ajaxPageState']['libraries'])) {
      $settings['ajaxPageState']['libraries'] = UrlHelper::compressQueryParameter($settings['ajaxPageState']['libraries']);
    }

    $response = new JsonResponse([
      'html' => $html,
      'css' => $this->urls($css),
      'js' => $this->urls(\array_merge($header_js, $footer_js)),
      'settings' => $settings ?: new \stdClass(),
    ]);
    $response->headers->set('Cache-Control', 'no-store, private');
    return $response;
  }

  /**
   * Turns resolved assets into the URLs to load them from.
   *
   * @param array<int|string, array<string, mixed>> $assets
   *   Assets as the asset resolver returns them.
   *
   * @return list<string>
   *   URLs, in order.
   */
  private function urls(array $assets): array {
    $urls = [];
    foreach ($assets as $asset) {
      if (!isset($asset['data']) || !\is_string($asset['data'])) {
        continue;
      }
      $urls[] = ($asset['type'] ?? 'file') === 'external' ? $asset['data'] : $this->fileUrlGenerator->generateString($asset['data']);
    }
    return $urls;
  }

}
