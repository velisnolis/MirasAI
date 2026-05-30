<?php

declare(strict_types=1);

namespace Mirasai\WordPress\Tool;

interface ToolInterface
{
    public function getName(): string;

    public function getDescription(): string;

    /**
     * @return array<string, mixed>
     */
    public function getInputSchema(): array;

    /**
     * @return array<string, mixed>
     */
    public function getPermissions(): array;

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    public function handle(array $arguments): array;
}
