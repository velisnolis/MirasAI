<?php

declare(strict_types=1);

namespace Mirasai\Library\Tool;

/**
 * Strategy for processing page-builder layout content stored in article fulltext.
 *
 * Implementations handle a specific builder format (e.g. YOOtheme Builder JSON
 * wrapped in an HTML comment). Content tools use this interface to detect, read,
 * and patch builder layouts without coupling to a specific builder.
 */
interface ContentLayoutProcessorInterface
{
    /**
     * Return true when $content is in the format handled by this processor.
     * Used by content tools to decide whether to invoke the processor.
     */
    public function detectLayout(string $content): bool;

    /**
     * Extract all translatable text nodes from $content.
     *
     * @return list<array{path: string, node_type: string, field: string, text: string, format: string}>
     */
    public function extractTranslatableText(string $content): array;

    /**
     * Apply $replacements (path → translated text) to the layout in $content
     * and return the updated content string.
     *
     * @param array<string, string> $replacements
     */
    public function replaceText(string $content, array $replacements): string;

    /**
     * Walk every translatable node in $content, calling $visitor for each.
     * The visitor receives (path, field, text) and returns the replacement string.
     * Returns the updated content string.
     *
     * @param callable(string $path, string $field, string $text): string $visitor
     */
    public function walkTranslatableNodes(string $content, callable $visitor): string;

    /**
     * Return the list of field names considered plain text / HTML in this format.
     *
     * @return list<string>
     */
    public function getTextProperties(): array;

    /**
     * Return the list of field names considered non-translatable config in this format.
     *
     * @return list<string>
     */
    public function getConfigProperties(): array;
}
