<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

use Joomla\CMS\Component\ComponentHelper;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\Database\DatabaseInterface;

/**
 * Writes MCP writes to Users → User Actions Log.
 *
 * Inserts the row ActionlogModel::addLog() would insert, without booting
 * com_actionlogs inside the API application (which has no UserFactory) and
 * without its notification e-mails. The message renders through
 * COM_MIRASAI_ACTIONLOG_MCP_WRITE in com_mirasai's admin language file.
 */
final class McpActionLog
{
    public const LANGUAGE_KEY = 'COM_MIRASAI_ACTIONLOG_MCP_WRITE';
    public const CONTEXT = 'com_mirasai.mcp';

    /**
     * The Super User whose token authenticated this request. The MCP plugins set it instead of
     * loading it as the application identity, which would change how every tool runs.
     */
    private static ?User $user = null;

    public static function setUser(User $user): void
    {
        self::$user = $user;
    }

    /**
     * @param array{tool: string, risk_level: string, target: string, client: string, old_etag: string, new_etag: string} $record
     */
    public static function write(array $record): void
    {
        try {
            $user = self::$user ?? Factory::getApplication()->getIdentity();
            $userId = (int) ($user->id ?? 0);
            $ip = 'COM_ACTIONLOGS_DISABLED';

            if (ComponentHelper::getComponent('com_actionlogs')->getParams()->get('ip_logging', 0)) {
                $ip = filter_var($_SERVER['REMOTE_ADDR'] ?? '', FILTER_VALIDATE_IP) ? (string) $_SERVER['REMOTE_ADDR'] : 'COM_ACTIONLOGS_IP_INVALID';
            }

            $message = [
                'action' => 'mcp_write',
                'id' => $userId,
                'userid' => $userId,
                'username' => (string) ($user->username ?? ''),
                'accountlink' => 'index.php?option=com_users&task=user.edit&id=' . $userId,
            ] + $record;

            $row = (object) [
                'message_language_key' => self::LANGUAGE_KEY,
                'message' => json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'log_date' => Factory::getDate()->toSql(),
                'extension' => self::CONTEXT,
                'user_id' => $userId,
                'item_id' => 0,
                'ip_address' => $ip,
            ];

            Factory::getContainer()->get(DatabaseInterface::class)->insertObject('#__action_logs', $row);
        } catch (\Throwable) {
            // The write already happened; a logging failure must not turn it into an error.
        }
    }

    /** @return array<string, string> */
    public static function requestHeaders(): array
    {
        return [
            'x-mcp-client' => isset($_SERVER['HTTP_X_MCP_CLIENT']) ? (string) $_SERVER['HTTP_X_MCP_CLIENT'] : '',
            'user-agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
        ];
    }
}
