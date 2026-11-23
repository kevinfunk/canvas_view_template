<?php

/**
 * @file
 * The changes the mutation runner makes, one at a time.
 *
 * Each entry breaks the module in one way that a test should notice. This is a
 * curated list of behaviours that matter, not every change a tool could make.
 *
 * Keys:
 * - description: What the change breaks.
 * - file: The file to change, relative to the module.
 * - find: The exact text to replace. Single line.
 * - replace: What to put there.
 * - count: How many times `find` must occur (default 1). The runner stops if
 *   the file no longer matches, so a refactor cannot leave a change unapplied.
 * - tests: Test files that must fail, relative to the module.
 */

declare(strict_types=1);

$kernel = 'tests/src/Kernel/';

return [
  'rowset-ignores-offset' => [
    'description' => "A repeater's offset is ignored.",
    'file' => 'src/Render/RowSet.php',
    'find' => '\array_slice($this->renders, $offset, $limit)',
    'replace' => '\array_slice($this->renders, 0, $limit)',
    'tests' => [$kernel . 'ViewTemplateRendererEdgeCasesTest.php'],
  ],
  'rowset-ignores-limit' => [
    'description' => "A repeater's limit is ignored.",
    'file' => 'src/Render/RowSet.php',
    'find' => '\array_slice($this->renders, $offset, $limit)',
    'replace' => '\array_slice($this->renders, $offset)',
    'tests' => [$kernel . 'ViewTemplateRendererEdgeCasesTest.php'],
  ],
  'limit-zero-is-a-limit' => [
    'description' => 'A limit of 0 shows nothing instead of meaning no limit.',
    'file' => 'src/Plugin/Canvas/ComponentSource/ViewTemplateRepeater.php',
    'find' => '$limit !== NULL && $limit > 0 ? $limit : NULL',
    'replace' => '$limit !== NULL && $limit >= 0 ? $limit : NULL',
    'tests' => [$kernel . 'ViewTemplateRendererEdgeCasesTest.php'],
  ],
  'page-condition-mode-ignored' => [
    'description' => "A repeater's page condition never applies.",
    'file' => 'src/Plugin/Canvas/ComponentSource/ViewTemplateRepeater.php',
    'find' => "'mode' => \\in_array(\$mode, [self::PAGE_CONDITION_ONLY, self::PAGE_CONDITION_EXCEPT], TRUE) ? \$mode : self::PAGE_CONDITION_ALL,",
    'replace' => "'mode' => self::PAGE_CONDITION_ALL,",
    'tests' => [$kernel . 'ViewTemplateRendererEdgeCasesTest.php'],
  ],
  'nested-repeater-allowed' => [
    'description' => 'A repeater inside another repeater is no longer reported.',
    'file' => 'src/Plugin/Validation/Constraint/ViewTemplateNoNestedRepeaterConstraintValidator.php',
    'find' => '$component_id === self::VIEW_REPEATER_COMPONENT_ID',
    'replace' => '$component_id !== self::VIEW_REPEATER_COMPONENT_ID',
    'tests' => [$kernel . 'ViewTemplateRepeaterContentsTest.php'],
  ],
  'not-first-page-inverted' => [
    'description' => '"Not the first page" shows on the first page.',
    'file' => 'src/Render/ViewConditions.php',
    'find' => "self::NOT_FIRST_PAGE => empty(\$facts['first_page']),",
    'replace' => "self::NOT_FIRST_PAGE => !empty(\$facts['first_page']),",
    'tests' => [$kernel . 'ViewTemplateConditionTest.php'],
  ],
  'attachments-need-both' => [
    'description' => '"Has attachments" needs both attachments, not either.',
    'file' => 'src/Render/ViewConditions.php',
    'find' => "!empty(\$areas['attachment_before']) || !empty(\$areas['attachment_after'])",
    'replace' => "!empty(\$areas['attachment_before']) && !empty(\$areas['attachment_after'])",
    'tests' => [$kernel . 'ViewTemplateConditionTest.php'],
  ],
  'grouped-filter-identifier' => [
    'description' => 'A grouped exposed filter is looked up under the wrong name.',
    'file' => 'src/Render/ViewConditions.php',
    'find' => '$filter->isAGroup()',
    'replace' => 'FALSE',
    'tests' => [$kernel . 'ViewTemplateHardeningTest.php'],
  ],
  'item-links-ignore-access' => [
    'description' => 'Items link to pages the visitor cannot view.',
    'file' => 'src/Render/ViewItemUrls.php',
    'find' => 'if (!$access->isAllowed()) {',
    'replace' => 'if (FALSE) {',
    'tests' => [$kernel . 'ViewTemplateHardeningTest.php'],
  ],
  'summary-text-unescaped' => [
    'description' => 'The result summary format is output as markup, not text.',
    'file' => 'src/Plugin/Canvas/ComponentSource/ViewTemplateSummary.php',
    'find' => "\$text = ['#plain_text' => ",
    'replace' => "\$text = ['#markup' => ",
    'tests' => [$kernel . 'ViewTemplateSummaryTest.php'],
  ],
  'template-cache-tag-dropped' => [
    'description' => "A View's output no longer depends on its view template.",
    'file' => 'src/Hook/ViewTemplateThemeHooks.php',
    'find' => '? CacheableMetadata::createFromObject($view_template)',
    'replace' => '? new CacheableMetadata()',
    'tests' => [
      $kernel . 'ViewTemplateFrontEndCacheTest.php',
      $kernel . 'ViewTemplateHardeningTest.php',
    ],
  ],
  'delete-lock-never-forbids' => [
    'description' => 'A template that Views use can be deleted.',
    'file' => 'src/Entity/ViewTemplateAccessControlHandler.php',
    'find' => 'return AccessResult::forbidden(PlainTextOutput',
    'replace' => 'return AccessResult::allowed(); return AccessResult::forbidden(PlainTextOutput',
    'tests' => [$kernel . 'ViewTemplatePanelBackendTest.php'],
  ],
  'delete-lock-reason-escaped' => [
    'description' => 'View names in the delete-lock message are HTML-escaped.',
    'file' => 'src/Entity/ViewTemplateAccessControlHandler.php',
    'find' => 'PlainTextOutput::renderFromHtml(',
    'replace' => '(string) (',
    'tests' => [$kernel . 'ViewTemplateMarkupSafetyTest.php'],
  ],
  'usage-shows-restricted-views' => [
    'description' => 'Views a user cannot access are named in the usage list.',
    'file' => 'src/ViewTemplateUsage.php',
    'find' => 'return $view->getExecutable()->access($displays, $account);',
    'replace' => 'return TRUE;',
    'tests' => [$kernel . 'ViewTemplatePanelBackendTest.php'],
  ],
  'usage-routes-cacheable' => [
    'description' => 'The usage routes can be cached by the browser or a proxy.',
    'file' => 'src/Controller/ViewTemplateUsageController.php',
    'find' => "'no-store, private'",
    'replace' => "'private'",
    'count' => 2,
    'tests' => [$kernel . 'ViewTemplatePanelBackendTest.php'],
  ],
  'duplicate-conflict-status' => [
    'description' => 'A taken machine name returns 422 instead of 409.',
    'file' => 'src/Controller/ViewTemplateDuplicateController.php',
    'find' => "['@id' => \$id]), 409)",
    'replace' => "['@id' => \$id]), 422)",
    'tests' => [$kernel . 'ViewTemplatePanelBackendTest.php'],
  ],
  'duplicate-without-csrf' => [
    'description' => 'Duplicating a template no longer needs a CSRF token.',
    'file' => 'canvas_view_template.routing.yml',
    'find' => "    _csrf_request_header_token: 'TRUE'",
    'replace' => '',
    'tests' => [$kernel . 'ViewTemplateRouteAccessTest.php'],
  ],
  'schema-hook-adds-nothing' => [
    'description' => "Canvas's schema is not told about this module's sources.",
    'file' => 'src/Hook/ViewTemplateSchemaHooks.php',
    'find' => '...self::sourcePluginIds(),',
    'replace' => '',
    'tests' => [
      'tests/src/Unit/ViewTemplateSchemaHooksTest.php',
      $kernel . 'ViewTemplateWithoutDevModeTest.php',
    ],
  ],
];
