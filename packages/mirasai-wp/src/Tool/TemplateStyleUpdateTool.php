<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class TemplateStyleUpdateTool extends AbstractTool
{
    private const MAX_CSS_BYTES = 8388608;

    public function getName(): string
    {
        return 'template/style-update';
    }

    public function getDescription(): string
    {
        return 'Applies a guarded WordPress YOOtheme Style patch together with already compiled LTR/RTL CSS. Requires if_match, dry_run first, and confirm_guarded_write for a real write. Creates a private config/CSS snapshot and rolls back automatically on failure.';
    }

    public function getPermissions(): array
    {
        return [
            'risk_level' => self::RISK_GUARDED_WRITE,
            'idempotent' => false,
        ];
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'if_match' => ['type' => 'string'],
                'style_id' => ['type' => 'string'],
                'variation' => ['type' => 'string'],
                'vars' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => ['string', 'number']],
                ],
                'unset_vars' => ['type' => 'array', 'items' => ['type' => 'string']],
                'custom_less' => ['type' => 'string'],
                'compiled_css' => ['type' => 'string'],
                'compiled_rtl' => ['type' => 'string'],
                'compiled_css_sha256' => ['type' => 'string'],
                'compiled_rtl_sha256' => ['type' => 'string'],
                'dry_run' => ['type' => 'boolean'],
                'confirm_guarded_write' => ['type' => 'boolean'],
            ],
            'required' => [
                'if_match',
                'compiled_css',
                'compiled_rtl',
                'compiled_css_sha256',
                'compiled_rtl_sha256',
            ],
        ];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        $helper = new YoothemeStyleHelper();
        $status = $helper->status();

        if (!$status['installed'] || !$status['active']) {
            return ['error' => 'YOOtheme Pro is not the active WordPress theme.', 'code' => 'yootheme_inactive'];
        }

        $ifMatch = trim((string) ($arguments['if_match'] ?? ''));
        $dryRun = ($arguments['dry_run'] ?? null) === true;
        $confirmed = ($arguments['confirm_guarded_write'] ?? null) === true;

        if ($ifMatch === '') {
            return ['error' => 'if_match is required.', 'code' => 'missing_if_match'];
        }

        if (!$dryRun && !$confirmed) {
            return [
                'error' => 'This is a guarded write. Run dry_run=true first, then retry with confirm_guarded_write=true and a fresh if_match.',
                'code' => 'guarded_write_confirmation_required',
            ];
        }

        $config = $helper->loadConfig();
        $compiled = $helper->compiledState();
        $currentEtag = $helper->etag($config, $compiled);

        if (!hash_equals($currentEtag, $ifMatch)) {
            return [
                'error' => 'Style etag mismatch. Re-read it and retry with the fresh etag.',
                'code' => 'stale_etag',
                'expected_etag' => $currentEtag,
                'provided_etag' => $ifMatch,
            ];
        }

        $active = $helper->activeStyle($config);
        $styleId = isset($arguments['style_id'])
            ? trim((string) $arguments['style_id'])
            : $active['style_id'];
        $variation = array_key_exists('variation', $arguments)
            ? trim((string) $arguments['variation'])
            : $active['variation'];
        $variation = $variation !== '' ? $variation : null;

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $styleId)) {
            return ['error' => 'style_id is invalid.', 'code' => 'invalid_style_id'];
        }

        if ($styleId !== $active['style_id']) {
            return [
                'error' => 'This first guarded writer only updates the active style family. Switching style families needs an explicit override-reset policy.',
                'code' => 'style_switch_not_supported',
            ];
        }

        $available = array_column($helper->availableStyles(), null, 'id');
        if (!isset($available[$styleId])) {
            return ['error' => sprintf('Style "%s" was not found.', $styleId), 'code' => 'style_not_found'];
        }

        $vars = is_array($arguments['vars'] ?? null) ? $arguments['vars'] : [];
        $unsetVars = is_array($arguments['unset_vars'] ?? null) ? $arguments['unset_vars'] : [];
        $validation = $this->validateVariablePatch($vars, $unsetVars);
        if ($validation !== null) {
            return $validation;
        }

        $css = is_string($arguments['compiled_css'] ?? null) ? $arguments['compiled_css'] : '';
        $rtl = is_string($arguments['compiled_rtl'] ?? null) ? $arguments['compiled_rtl'] : '';
        $cssHash = strtolower(trim((string) ($arguments['compiled_css_sha256'] ?? '')));
        $rtlHash = strtolower(trim((string) ($arguments['compiled_rtl_sha256'] ?? '')));

        if ($css === '' || $rtl === '' || strlen($css) > self::MAX_CSS_BYTES || strlen($rtl) > self::MAX_CSS_BYTES) {
            return [
                'error' => 'compiled_css and compiled_rtl must be non-empty and no larger than 8 MiB each.',
                'code' => 'invalid_compiled_css',
            ];
        }

        if (!hash_equals(hash('sha256', $css), $cssHash) || !hash_equals(hash('sha256', $rtl), $rtlHash)) {
            return [
                'error' => 'A compiled CSS SHA-256 does not match its payload.',
                'code' => 'compiled_css_hash_mismatch',
            ];
        }

        $candidateConfig = $helper->patchConfig(
            $config,
            $styleId,
            $variation,
            $vars,
            array_values($unsetVars),
            is_string($arguments['custom_less'] ?? null) ? $arguments['custom_less'] : null,
            array_key_exists('custom_less', $arguments)
        );
        $configChanged = !hash_equals(
            hash('sha256', (string) wp_json_encode($config)),
            hash('sha256', (string) wp_json_encode($candidateConfig))
        );

        $response = [
            'dry_run' => $dryRun,
            'would_change_config' => $configChanged,
            'would_write_css' => true,
            'old_etag' => $currentEtag,
            'style' => ['from' => $active['raw'], 'to' => $candidateConfig['style']],
            'patch' => [
                'set_vars' => array_keys($vars),
                'unset_vars' => array_values($unsetVars),
                'custom_less_replaced' => array_key_exists('custom_less', $arguments),
            ],
            'compiled' => [
                'ltr_bytes' => strlen($css),
                'ltr_sha256' => $cssHash,
                'rtl_bytes' => strlen($rtl),
                'rtl_sha256' => $rtlHash,
            ],
            'secret_preserved' => ($config['yootheme_apikey'] ?? null)
                === ($candidateConfig['yootheme_apikey'] ?? null),
        ];

        if ($dryRun) {
            return $response + [
                'action' => 'preview',
                'snapshot_created' => false,
                'note' => 'Nothing was written. Retry with confirm_guarded_write=true and the same if_match if this preview is still current.',
            ];
        }

        $written = $helper->commitStyleUpdate($candidateConfig, $css, $rtl, $ifMatch);
        if (isset($written['error'])) {
            return $written;
        }

        return $response + $written + ['action' => 'updated', 'snapshot_created' => true];
    }

    /**
     * @param array<string, mixed> $vars
     * @param array<mixed> $unsetVars
     * @return array<string, mixed>|null
     */
    private function validateVariablePatch(array $vars, array $unsetVars): ?array
    {
        foreach ($vars as $name => $value) {
            if (!is_string($name) || !preg_match('/^@[A-Za-z0-9_-]+$/', $name)) {
                return ['error' => 'Every vars key must be a valid Less variable name.', 'code' => 'invalid_less_variable'];
            }
            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                return ['error' => sprintf('Variable %s must have a scalar string or number value.', $name), 'code' => 'invalid_less_value'];
            }
        }

        foreach ($unsetVars as $name) {
            if (!is_string($name) || !preg_match('/^@[A-Za-z0-9_-]+$/', $name)) {
                return ['error' => 'Every unset_vars item must be a valid Less variable name.', 'code' => 'invalid_less_variable'];
            }
        }

        return null;
    }
}
