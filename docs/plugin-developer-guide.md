# MirasAI Plugin Developer Guide

MirasAI v0.4.0 introduced a plugin architecture that lets you add custom MCP tools without modifying the core library. This guide shows how to create a `plg_mirasai_*` plugin.

## Overview

The plugin system works in two modes:

| Mode | How it works |
|------|-------------|
| **Joomla-native** | Plugin listens to `onMirasaiCollectTools` event. Loaded by Joomla's plugin manager. |
| **Standalone** | `mcp-endpoint.php` scans `JPATH_PLUGINS/mirasai/*/provider.php`. Each file returns a `ToolProviderInterface` instance. |

Both modes run the same `ToolProviderInterface` — you only write it once.

---

## Minimal Plugin Structure

```
plg_mirasai_myplugin/
├── mirasai_myplugin.xml          ← Joomla manifest
├── provider.php                  ← Standalone bootstrap (returns ToolProviderInterface)
├── services/
│   └── provider.php              ← Joomla DI service provider
└── src/
    ├── Extension/
    │   └── MirasaiMyplugin.php   ← Plugin class (handles onMirasaiCollectTools)
    ├── MyToolProvider.php        ← implements ToolProviderInterface
    └── Tool/
        └── MyCustomTool.php      ← Your MCP tool(s)
```

See `pkg_mirasai/packages/plg_mirasai_example/` for a complete working example.

---

## Step 1: Create Your Tool

Your tool class must extend `\Mirasai\Library\Tool\AbstractTool`:

```php
<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Myplugin\Tool;

use Mirasai\Library\Tool\AbstractTool;

class MyCustomTool extends AbstractTool
{
    public function getName(): string
    {
        return 'myextension/do-something';
    }

    public function getDescription(): string
    {
        return 'Does something useful via MCP.';
    }

    public function getInputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => [
                    'type' => 'integer',
                    'description' => 'Item ID.',
                ],
            ],
            'required' => ['id'],
        ];
    }

    public function handle(array $arguments): array
    {
        $id = (int) ($arguments['id'] ?? 0);

        // $this->db is available from AbstractTool
        $query = $this->db->getQuery(true)
            ->select('title')
            ->from($this->db->quoteName('#__content'))
            ->where('id = :id')
            ->bind(':id', $id, \Joomla\Database\ParameterType::INTEGER);

        $title = $this->db->setQuery($query)->loadResult();

        return $title
            ? ['id' => $id, 'title' => $title]
            : ['error' => "Item {$id} not found."];
    }
}
```

**Tool naming convention:** `vendor/action` (e.g. `akeeba/backup-site`, `acymailing/send-campaign`).

---

## Step 2: Create the ToolProvider

```php
<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Myplugin;

use Mirasai\Library\Tool\ContentLayoutProcessorInterface;
use Mirasai\Library\Tool\ToolInterface;
use Mirasai\Library\Tool\ToolProviderInterface;
use Mirasai\Plugin\Mirasai\Myplugin\Tool\MyCustomTool;

class MyToolProvider implements ToolProviderInterface
{
    public function getId(): string
    {
        return 'myvendor.myplugin';           // unique, reverse-domain style
    }

    public function getName(): string
    {
        return 'My Extension MCP Tools';
    }

    public function isAvailable(): bool
    {
        // Return false when prerequisites are missing.
        // Examples: check if your extension is installed and enabled.
        return class_exists('MyExtension\Application', false)
            || $this->extensionIsEnabled('myextension');
    }

    public function getToolNames(): array
    {
        return ['myextension/do-something'];
    }

    public function createTool(string $name): ToolInterface
    {
        return match ($name) {
            'myextension/do-something' => new MyCustomTool(),
            default => throw new \InvalidArgumentException("Unknown tool: {$name}"),
        };
    }

    /**
     * Return a ContentLayoutProcessorInterface only if your plugin handles
     * a page-builder format. Return null otherwise.
     */
    public function getContentLayoutProcessor(): ?ContentLayoutProcessorInterface
    {
        return null;
    }

    private function extensionIsEnabled(string $element): bool
    {
        try {
            $db = \Joomla\CMS\Factory::getContainer()->get(\Joomla\Database\DatabaseInterface::class);
            $query = $db->getQuery(true)
                ->select('COUNT(*)')
                ->from($db->quoteName('#__extensions'))
                ->where('element = ' . $db->quote($element))
                ->where('enabled = 1');

            return (int) $db->setQuery($query)->loadResult() > 0;
        } catch (\Throwable) {
            return false;
        }
    }
}
```

---

## Step 3: Create the Plugin Class (Joomla-native path)

```php
<?php

declare(strict_types=1);

namespace Mirasai\Plugin\Mirasai\Myplugin\Extension;

use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\Event\SubscriberInterface;
use Mirasai\Library\Mcp\MirasaiCollectToolsEvent;
use Mirasai\Plugin\Mirasai\Myplugin\MyToolProvider;

final class MirasaiMyplugin extends CMSPlugin implements SubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            'onMirasaiCollectTools' => 'onMirasaiCollectTools',
        ];
    }

    public function onMirasaiCollectTools(MirasaiCollectToolsEvent $event): void
    {
        $event->addProvider(new MyToolProvider());
    }
}
```

---

## Step 4: Create provider.php (Standalone path)

```php
<?php
// plg_mirasai_myplugin/provider.php

declare(strict_types=1);

defined('_JEXEC') or define('_JEXEC', 1);

$src = __DIR__ . '/src';
require_once $src . '/MyToolProvider.php';
require_once $src . '/Tool/MyCustomTool.php';

return new \Mirasai\Plugin\Mirasai\Myplugin\MyToolProvider();
```

---

## Step 5: Create the Joomla Manifest

```xml
<?xml version="1.0" encoding="utf-8"?>
<extension type="plugin" group="mirasai" method="upgrade">
    <name>plg_mirasai_myplugin</name>
    <version>1.0.0</version>
    <author>Your Name</author>
    <description>Adds MCP tools for My Extension.</description>
    <namespace path="src">Mirasai\Plugin\Mirasai\Myplugin</namespace>
    <files>
        <folder plugin="mirasai_myplugin">services</folder>
        <folder>src</folder>
    </files>
</extension>
```

---

## How isAvailable() Works

`isAvailable()` is called once per registry build. If it returns `false`:
- The plugin's tools are silently skipped.
- No error is shown to the AI agent.
- Core tools still load normally.

Use this to guard against missing dependencies:

```php
public function isAvailable(): bool
{
    // Check if the extension is installed
    return class_exists('Akeeba\Backup\Engine\Factory', false);
}
```

---

## Tool Name Conflicts

If your tool name conflicts with a core tool or another plugin's tool, the first-registered wins and a `E_USER_WARNING` is logged. **Core tools always win** (they register first).

Prefix your tool names with your vendor/extension name to avoid conflicts:
- ✅ `akeeba/backup-now`
- ✅ `acymailing/send-campaign`
- ❌ `content/read` (conflicts with core)

---

## Destructive Tools

If your tool modifies data, override `getPermissions()`:

```php
public function getPermissions(): array
{
    return [
        'readonly' => false,
        'destructive' => true,
        'idempotent' => false,
    ];
}
```

Destructive tools require Smart Sudo elevation before the AI agent can call them.
