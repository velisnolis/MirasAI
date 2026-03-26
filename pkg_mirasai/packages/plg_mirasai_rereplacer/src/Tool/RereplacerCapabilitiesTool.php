<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Rereplacer\Tool;

class RereplacerCapabilitiesTool extends AbstractRereplacerTool
{
    public function getName(): string
    {
        return 'rereplacer/capabilities';
    }

    public function getDescription(): string
    {
        return 'Describe the ReReplacer capability level available on this site. Use this first when you need to know whether the site has ReReplacer Free or PRO, whether the Conditions component is installed, and which ReReplacer features are unavailable without PRO.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [],
        ];
    }

    public function handle(array $arguments): array
    {
        $capabilities = $this->rereplacer->getCapabilities();
        $capabilities['summary'] = $this->rereplacer->buildCapabilityNote();

        return $capabilities;
    }
}
