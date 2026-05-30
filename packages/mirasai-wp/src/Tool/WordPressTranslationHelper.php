<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class WordPressTranslationHelper
{
    /**
     * @return array{name: string, active: bool}
     */
    public function provider(): array
    {
        if (function_exists('pll_languages_list')) {
            return [
                'name' => 'polylang',
                'active' => true,
            ];
        }

        if (defined('ICL_SITEPRESS_VERSION') || has_filter('wpml_active_languages')) {
            return [
                'name' => 'wpml',
                'active' => true,
            ];
        }

        return [
            'name' => 'none',
            'active' => false,
        ];
    }

    /**
     * @return list<array{code: string, name: string, default: bool}>
     */
    public function languages(): array
    {
        $provider = $this->provider()['name'];

        return match ($provider) {
            'polylang' => $this->polylangLanguages(),
            'wpml' => $this->wpmlLanguages(),
            default => [],
        };
    }

    public function defaultLanguage(): ?string
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang' && function_exists('pll_default_language')) {
            $default = pll_default_language('slug');

            return is_string($default) && $default !== '' ? $default : null;
        }

        if ($provider === 'wpml') {
            $default = apply_filters('wpml_default_language', null);

            return is_string($default) && $default !== '' ? $default : null;
        }

        return null;
    }

    public function languageExists(string $language): bool
    {
        foreach ($this->languages() as $item) {
            if (($item['code'] ?? null) === $language) {
                return true;
            }
        }

        return false;
    }

    public function postLanguage(int $postId, string $postType): ?string
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang' && function_exists('pll_get_post_language')) {
            $language = pll_get_post_language($postId, 'slug');

            return is_string($language) && $language !== '' ? $language : null;
        }

        if ($provider === 'wpml') {
            $details = apply_filters('wpml_element_language_details', null, [
                'element_id' => $postId,
                'element_type' => $this->wpmlPostElementType($postType),
            ]);

            if (is_object($details) && isset($details->language_code) && is_string($details->language_code)) {
                return $details->language_code;
            }

            if (is_array($details) && isset($details['language_code']) && is_string($details['language_code'])) {
                return $details['language_code'];
            }
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public function postTranslations(int $postId, string $postType): array
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang' && function_exists('pll_get_post_translations')) {
            $translations = pll_get_post_translations($postId);

            if (!is_array($translations)) {
                return [];
            }

            return array_filter(
                array_map(static fn($id): int => (int) $id, $translations),
                static fn(int $id): bool => $id > 0
            );
        }

        if ($provider === 'wpml') {
            return $this->wpmlPostTranslations($postId, $postType);
        }

        return [];
    }

    public function termLanguage(int $termId, string $taxonomy): ?string
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang' && function_exists('pll_get_term_language')) {
            $language = pll_get_term_language($termId, 'slug');

            return is_string($language) && $language !== '' ? $language : null;
        }

        if ($provider === 'wpml') {
            $details = $this->wpmlTermLanguageDetails($termId, $taxonomy);

            return $this->extractWpmlLanguageCode($details);
        }

        return null;
    }

    /**
     * @return array<string, int>
     */
    public function termTranslations(int $termId, string $taxonomy): array
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang' && function_exists('pll_get_term_translations')) {
            $translations = pll_get_term_translations($termId);

            if (!is_array($translations)) {
                return [];
            }

            return array_filter(
                array_map(static fn($id): int => (int) $id, $translations),
                static fn(int $id): bool => $id > 0
            );
        }

        if ($provider === 'wpml') {
            return $this->wpmlTermTranslations($termId, $taxonomy);
        }

        return [];
    }

    /**
     * @return array{ok: true}|array{error: string, code: string}
     */
    public function setPostLanguageAndAssociation(int $postId, string $postType, string $language, ?int $sourceId = null): array
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang') {
            if (!function_exists('pll_set_post_language') || !function_exists('pll_save_post_translations')) {
                return ['error' => 'Polylang translation functions are not available.', 'code' => 'translation_provider_unavailable'];
            }

            pll_set_post_language($postId, $language);
            $translations = $sourceId !== null ? $this->postTranslations($sourceId, $postType) : [];
            $translations[$language] = $postId;
            pll_save_post_translations($translations);

            return ['ok' => true];
        }

        if ($provider === 'wpml') {
            $elementType = $this->wpmlPostElementType($postType);
            $sourceLanguage = null;
            $trid = null;

            if ($sourceId !== null) {
                $details = $this->wpmlLanguageDetails($sourceId, $postType);
                $trid = $this->extractWpmlTrid($details);
                $sourceLanguage = $this->extractWpmlLanguageCode($details);
            }

            do_action('wpml_set_element_language_details', [
                'element_id' => $postId,
                'element_type' => $elementType,
                'trid' => $trid,
                'language_code' => $language,
                'source_language_code' => $sourceLanguage !== $language ? $sourceLanguage : null,
            ]);

            return ['ok' => true];
        }

        return ['error' => 'No supported multilingual provider is active.', 'code' => 'multilingual_provider_missing'];
    }

    /**
     * @return array{ok: true}|array{error: string, code: string}
     */
    public function setTermLanguageAndAssociation(int $termId, string $taxonomy, string $language, ?int $sourceId = null): array
    {
        $provider = $this->provider()['name'];

        if ($provider === 'polylang') {
            if (!function_exists('pll_set_term_language') || !function_exists('pll_save_term_translations')) {
                return ['error' => 'Polylang term translation functions are not available.', 'code' => 'translation_provider_unavailable'];
            }

            pll_set_term_language($termId, $language);
            $translations = $sourceId !== null ? $this->termTranslations($sourceId, $taxonomy) : [];
            $translations[$language] = $termId;
            pll_save_term_translations($translations);

            return ['ok' => true];
        }

        if ($provider === 'wpml') {
            $term = get_term($termId, $taxonomy);

            if (!$term instanceof \WP_Term) {
                return ['error' => "Term {$termId} not found in taxonomy {$taxonomy}.", 'code' => 'term_not_found'];
            }

            $elementType = $this->wpmlTermElementType($taxonomy);
            $sourceLanguage = null;
            $trid = null;

            if ($sourceId !== null) {
                $details = $this->wpmlTermLanguageDetails($sourceId, $taxonomy);
                $trid = $this->extractWpmlTrid($details);
                $sourceLanguage = $this->extractWpmlLanguageCode($details);
            }

            do_action('wpml_set_element_language_details', [
                'element_id' => (int) $term->term_taxonomy_id,
                'element_type' => $elementType,
                'trid' => $trid,
                'language_code' => $language,
                'source_language_code' => $sourceLanguage !== $language ? $sourceLanguage : null,
            ]);

            return ['ok' => true];
        }

        return ['error' => 'No supported multilingual provider is active.', 'code' => 'multilingual_provider_missing'];
    }

    /**
     * @return list<array{code: string, name: string, default: bool}>
     */
    private function polylangLanguages(): array
    {
        $codes = pll_languages_list(['fields' => 'slug']);
        $names = pll_languages_list(['fields' => 'name']);
        $default = $this->defaultLanguage();
        $languages = [];

        if (!is_array($codes)) {
            return [];
        }

        foreach (array_values($codes) as $index => $code) {
            if (!is_string($code) || $code === '') {
                continue;
            }

            $name = is_array($names) && isset($names[$index]) && is_string($names[$index])
                ? $names[$index]
                : $code;

            $languages[] = [
                'code' => $code,
                'name' => $name,
                'default' => $default === $code,
            ];
        }

        return $languages;
    }

    /**
     * @return list<array{code: string, name: string, default: bool}>
     */
    private function wpmlLanguages(): array
    {
        $rawLanguages = apply_filters('wpml_active_languages', null, ['skip_missing' => 0]);
        $default = $this->defaultLanguage();
        $languages = [];

        if (!is_array($rawLanguages)) {
            return [];
        }

        foreach ($rawLanguages as $code => $language) {
            $languageCode = is_array($language) && isset($language['code']) && is_string($language['code'])
                ? $language['code']
                : (string) $code;

            if ($languageCode === '') {
                continue;
            }

            $name = is_array($language) && isset($language['native_name']) && is_string($language['native_name'])
                ? $language['native_name']
                : $languageCode;

            $languages[] = [
                'code' => $languageCode,
                'name' => $name,
                'default' => $default === $languageCode,
            ];
        }

        return $languages;
    }

    /**
     * @return array<string, int>
     */
    private function wpmlPostTranslations(int $postId, string $postType): array
    {
        $elementType = $this->wpmlPostElementType($postType);
        $details = $this->wpmlLanguageDetails($postId, $postType);
        $trid = $this->extractWpmlTrid($details);

        if ($trid === null || $trid === '') {
            return [];
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $elementType);
        if (!is_array($translations)) {
            return [];
        }

        $result = [];
        foreach ($translations as $translation) {
            if (!is_object($translation)) {
                continue;
            }

            $languageCode = isset($translation->language_code) && is_string($translation->language_code)
                ? $translation->language_code
                : '';
            $elementId = isset($translation->element_id) ? (int) $translation->element_id : 0;

            if ($languageCode !== '' && $elementId > 0) {
                $result[$languageCode] = $elementId;
            }
        }

        return $result;
    }

    private function wpmlPostElementType(string $postType): string
    {
        return 'post_' . $postType;
    }

    private function wpmlTermElementType(string $taxonomy): string
    {
        $elementType = apply_filters('wpml_element_type', $taxonomy);

        if (is_string($elementType) && str_starts_with($elementType, 'tax_')) {
            return $elementType;
        }

        return 'tax_' . $taxonomy;
    }

    /**
     * @return mixed
     */
    private function wpmlLanguageDetails(int $postId, string $postType)
    {
        return apply_filters('wpml_element_language_details', null, [
            'element_id' => $postId,
            'element_type' => $this->wpmlPostElementType($postType),
        ]);
    }

    /**
     * @return mixed
     */
    private function wpmlTermLanguageDetails(int $termId, string $taxonomy)
    {
        $term = get_term($termId, $taxonomy);

        if (!$term instanceof \WP_Term) {
            return null;
        }

        return apply_filters('wpml_element_language_details', null, [
            'element_id' => (int) $term->term_taxonomy_id,
            'element_type' => $this->wpmlTermElementType($taxonomy),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function wpmlTermTranslations(int $termId, string $taxonomy): array
    {
        $details = $this->wpmlTermLanguageDetails($termId, $taxonomy);
        $trid = $this->extractWpmlTrid($details);

        if ($trid === null || $trid === '') {
            return [];
        }

        $translations = apply_filters('wpml_get_element_translations', null, $trid, $this->wpmlTermElementType($taxonomy));
        if (!is_array($translations)) {
            return [];
        }

        $result = [];
        foreach ($translations as $translation) {
            if (!is_object($translation)) {
                continue;
            }

            $languageCode = isset($translation->language_code) && is_string($translation->language_code)
                ? $translation->language_code
                : '';
            $termId = $this->termIdFromTermTaxonomyId((int) ($translation->element_id ?? 0), $taxonomy);

            if ($languageCode !== '' && $termId > 0) {
                $result[$languageCode] = $termId;
            }
        }

        return $result;
    }

    private function termIdFromTermTaxonomyId(int $termTaxonomyId, string $taxonomy): int
    {
        if ($termTaxonomyId <= 0) {
            return 0;
        }

        global $wpdb;

        $termId = $wpdb->get_var($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d AND taxonomy = %s",
            $termTaxonomyId,
            $taxonomy
        ));

        return $termId !== null ? (int) $termId : 0;
    }

    /**
     * @param mixed $details
     * @return mixed
     */
    private function extractWpmlTrid($details)
    {
        if (is_object($details) && isset($details->trid)) {
            return $details->trid;
        }

        if (is_array($details) && isset($details['trid'])) {
            return $details['trid'];
        }

        return null;
    }

    /**
     * @param mixed $details
     */
    private function extractWpmlLanguageCode($details): ?string
    {
        if (is_object($details) && isset($details->language_code) && is_string($details->language_code)) {
            return $details->language_code;
        }

        if (is_array($details) && isset($details['language_code']) && is_string($details['language_code'])) {
            return $details['language_code'];
        }

        return null;
    }
}
