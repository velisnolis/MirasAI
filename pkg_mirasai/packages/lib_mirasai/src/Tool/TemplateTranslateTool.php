<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

class TemplateTranslateTool extends AbstractTool
{
    public function getName(): string
    {
        return 'template/translate';
    }

    public function getDescription(): string
    {
        return 'Duplicates a YOOtheme Builder template to a target language, preserves its assignment, and only translates fixed text nodes in the layout.';
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
            ],
            'required' => ['key', 'target_language'],
        ];
    }

    public function getPermissions(): array
    {
        return [
            'readonly' => false,
            'destructive' => false,
            'idempotent' => false,
        ];
    }

    public function handle(array $arguments): array
    {
        $key = trim((string) ($arguments['key'] ?? ''));
        $targetLanguage = trim((string) ($arguments['target_language'] ?? ''));
        $overwrite = !empty($arguments['overwrite']);

        if ($key === '' || $targetLanguage === '') {
            return ['error' => 'key and target_language are required.'];
        }

        if (!$this->languageExists($targetLanguage)) {
            return ['error' => "Language {$targetLanguage} is not published."];
        }

        $templates = $this->loadYoothemeTemplates();
        $sourceTemplate = $templates[$key] ?? null;

        if (!is_array($sourceTemplate)) {
            return ['error' => "Template {$key} not found."];
        }

        $sourceLanguage = $this->detectLikelySourceLanguage();
        $sourceTemplateLanguage = $this->getYoothemeTemplateLanguage($sourceTemplate);

        if (($sourceTemplateLanguage !== '' && $sourceTemplateLanguage === $targetLanguage)
            || ($sourceTemplateLanguage === '' && $sourceLanguage === $targetLanguage)
        ) {
            return ['error' => 'target_language matches the source template language.'];
        }

        $sourceLayout = $this->getYoothemeTemplateLayout($sourceTemplate);

        if ($sourceLayout === null) {
            return ['error' => "Template {$key} has no layout."];
        }

        $hasStaticText = $this->yoothemeTemplateHasStaticText($sourceTemplate);
        $translatedLayout = $this->resolveTranslatedLayout($sourceLayout, $arguments, $hasStaticText);

        if (isset($translatedLayout['error'])) {
            return $translatedLayout;
        }

        $fingerprint = $this->buildYoothemeTemplateAssignmentFingerprint($sourceTemplate);
        $existingTargetKey = $this->findTemplateKeyByFingerprintAndLanguage($templates, $fingerprint, $targetLanguage, $key);

        if ($existingTargetKey !== null && !$overwrite) {
            return ['error' => "A target-language template already exists for {$targetLanguage}. Use overwrite=true to replace it."];
        }

        $targetKey = $existingTargetKey ?? $this->generateUniqueTemplateKey($templates);
        $targetTemplate = $sourceTemplate;

        $this->setYoothemeTemplateLanguage($targetTemplate, $targetLanguage);
        $this->setYoothemeTemplateLayout($targetTemplate, $translatedLayout['layout']);
        $targetTemplate['name'] = $this->buildTargetTemplateName(
            $sourceTemplate,
            $targetLanguage,
            isset($arguments['translated_name']) ? (string) $arguments['translated_name'] : '',
        );

        $templates[$targetKey] = $targetTemplate;

        $sourceLanguageWasScoped = false;

        if ($hasStaticText && $sourceTemplateLanguage === '') {
            $this->setYoothemeTemplateLanguage($sourceTemplate, $sourceLanguage);
            $templates[$key] = $sourceTemplate;
            $sourceLanguageWasScoped = true;
        }

        $this->writeYoothemeTemplates($templates);

        return [
            'source_key' => $key,
            'target_key' => $targetKey,
            'target_language' => $targetLanguage,
            'action' => $existingTargetKey !== null ? 'updated' : 'created',
            'name' => $targetTemplate['name'],
            'has_static_text' => $hasStaticText,
            'source_language_scoped' => $sourceLanguageWasScoped,
            'source_template_language' => $sourceTemplateLanguage === '' ? '*' : $sourceTemplateLanguage,
        ];
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

        $sourceName = $this->getYoothemeTemplateName($sourceTemplate);
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

            if ($this->getYoothemeTemplateLanguage($template) !== $language) {
                continue;
            }

            if ($this->buildYoothemeTemplateAssignmentFingerprint($template) === $fingerprint) {
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
            $key = $this->generateYoothemeStorageKey();
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
            return ['layout' => $this->patchYoothemeLayoutArray($sourceLayout, $replacements)];
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
