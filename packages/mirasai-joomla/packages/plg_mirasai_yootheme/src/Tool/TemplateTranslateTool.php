<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Yootheme\Tool;

use Mirasai\Library\Tool\AbstractTool;
use Mirasai\Library\Tool\YooThemeHelper;
use Mirasai\Library\Tool\YooThemeLayoutProcessor;

class TemplateTranslateTool extends AbstractTool
{
    private YooThemeHelper $yooHelper;

    public function __construct()
    {
        parent::__construct();
        $this->yooHelper = new YooThemeHelper($this->db);
    }

    public function getName(): string
    {
        return 'template/translate';
    }

    public function getDescription(): string
    {
        return 'Creates a translated copy of a YOOtheme Builder page template. YOU must provide the translated text — '
            . 'this tool does NOT auto-translate. Pass yootheme_text_replacements (replacement_key → translated text, from template/read). '
            . 'The tool duplicates the template, sets its language filter to the target language, and patches the text nodes. '
            . 'The original template\'s assignment and layout structure are preserved.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'key' => [
                    'type' => 'string',
                    'description' => 'Source template key as returned by template/list.',
                ],
                'target_language' => [
                    'type' => 'string',
                    'description' => 'Target Joomla language code (e.g. es-ES, en-GB).',
                ],
                'translated_name' => [
                    'type' => 'string',
                    'description' => 'Optional translated template name. Defaults to "<source name> (<lang>)".',
                ],
                'translated_layout' => [
                    'description' => 'Optional translated layout JSON. Provide an object or a JSON string.',
                    'oneOf' => [
                        ['type' => 'object'],
                        ['type' => 'string'],
                    ],
                ],
                'yootheme_text_replacements' => [
                    'description' => 'Either a map of "path.field" => "translated text" or a list of replacement objects with path, field, and text. Paths come from template/read translatable_nodes.',
                    'oneOf' => [
                        [
                            'type' => 'object',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                        [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'path' => ['type' => 'string'],
                                    'field' => ['type' => 'string'],
                                    'text' => ['type' => 'string'],
                                ],
                                'required' => ['path', 'text'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                ],
                'overwrite' => [
                    'type' => 'boolean',
                    'description' => 'If true, overwrite an existing target-language template with the same assignment fingerprint.',
                ],
                'if_match' => [
                    'type' => 'string',
                    'description' => 'Required source template etag from template/list, template/summary, or template/read. Stale values are rejected before changing YOOtheme custom_data.',
                ],
                'dry_run' => [
                    'type' => 'boolean',
                    'description' => 'If true, validate and preview the translated template without writing YOOtheme custom_data.',
                ],
                'confirm_guarded_write' => [
                    'type' => 'boolean',
                    'description' => 'Required for the real write after review. Not required when dry_run=true.',
                ],
            ],
            'required' => ['key', 'target_language', 'if_match'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $targetLanguage = trim((string) ($arguments['target_language'] ?? ''));
        $overwrite = !empty($arguments['overwrite']);
        $dryRun = !empty($arguments['dry_run']);
        $ifMatch = isset($arguments['if_match']) ? trim((string) $arguments['if_match']) : '';

        if ($key === '' || $targetLanguage === '' || $ifMatch === '') {
            return [
                'error' => 'key, target_language, and if_match are required.',
                'code' => 'missing_if_match',
            ];
        }

        if (!$this->languageExists($targetLanguage)) {
            return ['error' => "Language {$targetLanguage} is not published."];
        }

        $templates = $this->yooHelper->loadTemplates();
        $sourceTemplate = $templates[$key] ?? null;

        if (!is_array($sourceTemplate)) {
            return ['error' => "Template {$key} not found."];
        }

        $currentSourceEtag = $this->yooHelper->buildTemplateEtag($sourceTemplate);

        if (!hash_equals($currentSourceEtag, $ifMatch)) {
            return [
                'error' => 'Source template etag mismatch. Re-read the template and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentSourceEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $sourceLanguage = $this->detectLikelySourceLanguage();
        $sourceTemplateLanguage = $this->yooHelper->getTemplateLanguage($sourceTemplate);

        if (($sourceTemplateLanguage !== '' && $sourceTemplateLanguage === $targetLanguage)
            || ($sourceTemplateLanguage === '' && $sourceLanguage === $targetLanguage)
        ) {
            return ['error' => 'target_language matches the source template language.'];
        }

        $sourceLayout = $this->yooHelper->getTemplateLayout($sourceTemplate);

        if ($sourceLayout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $hasStaticText = $this->yooHelper->templateHasStaticText($sourceTemplate);
        $translatedLayout = $this->resolveTranslatedLayout($sourceLayout, $arguments, $hasStaticText);

        if (isset($translatedLayout['error'])) {
            return $translatedLayout;
        }

        $fingerprint = $this->yooHelper->buildTemplateAssignmentFingerprint($sourceTemplate);
        $existingTargetKey = $this->findTemplateKeyByFingerprintAndLanguage($templates, $fingerprint, $targetLanguage, $key);

        if ($existingTargetKey !== null && !$overwrite) {
            return ['error' => "A target-language template already exists for {$targetLanguage}. Use overwrite=true to replace it."];
        }

        $targetKey = $existingTargetKey ?? $this->generateUniqueTemplateKey($templates);
        $targetTemplate = $sourceTemplate;

        $this->yooHelper->setTemplateLanguage($targetTemplate, $targetLanguage);
        $this->yooHelper->setTemplateLayout($targetTemplate, $translatedLayout['layout']);
        $targetTemplate['name'] = $this->buildTargetTemplateName(
            $sourceTemplate,
            $targetLanguage,
            isset($arguments['translated_name']) ? (string) $arguments['translated_name'] : '',
        );

        $templates[$targetKey] = $targetTemplate;

        $sourceLanguageWasScoped = false;

        if ($hasStaticText && $sourceTemplateLanguage === '') {
            $this->yooHelper->setTemplateLanguage($sourceTemplate, $sourceLanguage);
            $templates[$key] = $sourceTemplate;
            $sourceLanguageWasScoped = true;
        }

        $cache = $dryRun
            ? ['cleared' => false, 'groups' => [], 'failures' => [], 'reason' => 'dry_run']
            : $this->yooHelper->writeTemplates($templates);

        $response = [
            'source_key' => $key,
            'target_key' => $targetKey,
            'target_language' => $targetLanguage,
            'dry_run' => $dryRun,
            'action' => $existingTargetKey !== null ? 'updated' : 'created',
            'name' => $targetTemplate['name'],
            'source_etag' => $currentSourceEtag,
            'target_etag' => $this->yooHelper->buildTemplateEtag($targetTemplate),
            'collection_etag' => $this->yooHelper->buildTemplatesEtag($templates),
            'has_static_text' => $hasStaticText,
            'source_language_scoped' => $sourceLanguageWasScoped,
            'source_template_language' => $sourceTemplateLanguage === '' ? '*' : $sourceTemplateLanguage,
            'cache' => $cache,
        ];

        if ($dryRun) {
            $response['note'] = 'No changes were written. Retry with confirm_guarded_write=true and the same if_match if the preview is still current.';
        }

        return $response;
    }

    /**
     * @param array<string, mixed> $sourceTemplate
     */
    private function buildTargetTemplateName(array $sourceTemplate, string $targetLanguage, string $explicit): string
    {
        $explicit = trim($explicit);

        if ($explicit !== '') {
            return $explicit;
        }

        $sourceName = $this->yooHelper->getTemplateName($sourceTemplate);
        $baseName = preg_replace('/ \([A-Za-z]{2,3}-[A-Za-z]{2,3}\)$/', '', $sourceName) ?: $sourceName;

        return trim($baseName) . ' (' . $targetLanguage . ')';
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    private function findTemplateKeyByFingerprintAndLanguage(array $templates, string $fingerprint, string $language, string $excludeKey): ?string
    {
        foreach ($templates as $templateKey => $template) {
            if (!is_array($template) || $templateKey === $excludeKey) {
                continue;
            }

            if ($this->yooHelper->getTemplateLanguage($template) !== $language) {
                continue;
            }

            if ($this->yooHelper->buildTemplateAssignmentFingerprint($template) === $fingerprint) {
                return (string) $templateKey;
            }
        }

        return null;
    }

    /**
     * @param array<string, array<string, mixed>> $templates
     */
    private function generateUniqueTemplateKey(array $templates): string
    {
        do {
            $key = $this->yooHelper->generateStorageKey();
        } while (isset($templates[$key]));

        return $key;
    }

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $sourceLayout
     * @return array{layout?: array<string, mixed>, error?: string}
     */
    private function resolveTranslatedLayout(array $sourceLayout, array $arguments, bool $hasStaticText): array
    {
        if (isset($arguments['translated_layout'])) {
            $translatedLayout = $arguments['translated_layout'];

            if (is_string($translatedLayout)) {
                $decoded = json_decode($translatedLayout, true);

                if (!is_array($decoded)) {
                    return ['error' => 'translated_layout must be valid JSON.'];
                }

                return ['layout' => $decoded];
            }

            if (is_array($translatedLayout)) {
                return ['layout' => $translatedLayout];
            }

            return ['error' => 'translated_layout must be an object or JSON string.'];
        }

        $replacements = $this->normalizeReplacements($arguments['yootheme_text_replacements'] ?? null);

        if (isset($replacements['error'])) {
            return ['error' => $replacements['error']];
        }

        if (!empty($replacements)) {
            return ['layout' => (new YooThemeLayoutProcessor())->patchLayoutArray($sourceLayout, $replacements)];
        }

        if ($hasStaticText) {
            return ['error' => 'Templates with fixed text require translated_layout or yootheme_text_replacements.'];
        }

        return ['layout' => $sourceLayout];
    }

    /**
     * @param mixed $raw
     * @return array<string, string>|array{error: string}
     */
    private function normalizeReplacements(mixed $raw): array
    {
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            return ['error' => 'yootheme_text_replacements must be an object map or an array of replacement objects.'];
        }

        if ($raw === []) {
            return [];
        }

        $isList = array_is_list($raw);

        if (!$isList) {
            $normalized = [];

            foreach ($raw as $path => $text) {
                if (!is_string($path) || !is_string($text) || trim($path) === '') {
                    return ['error' => 'Invalid yootheme_text_replacements map entry.'];
                }

                $normalized[$path] = $text;
            }

            return $normalized;
        }

        $normalized = [];

        foreach ($raw as $item) {
            if (!is_array($item)) {
                return ['error' => 'Each replacement entry must be an object with path, optional field, and text.'];
            }

            $path = trim((string) ($item['path'] ?? ''));
            $field = trim((string) ($item['field'] ?? ''));
            $text = $item['text'] ?? null;

            if ($path === '' || !is_string($text)) {
                return ['error' => 'Each replacement entry requires path and text.'];
            }

            $normalized[$field !== '' ? "{$path}.{$field}" : $path] = $text;
        }

        return $normalized;
    }
}
