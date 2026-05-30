<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

class AcfStatusTool extends AbstractTool
{
    private AcfHelper $acf;

    public function __construct(?AcfHelper $acf = null)
    {
        $this->acf = $acf ?? new AcfHelper();
    }

    public function getName(): string
    {
        return 'acf/status';
    }

    public function getDescription(): string
    {
        return 'Detects ACF/ACF PRO, version, AI/datastore settings, and ACF abilities exposed through the WordPress Abilities API.';
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array
    {
        return array_merge([
            'ok' => true,
        ], $this->acf->status());
    }
}
