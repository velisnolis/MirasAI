<?php

declare(strict_types=1);

namespace Mirasai\Library\Mcp;

use Joomla\CMS\Crypt\Crypt;
use Joomla\CMS\Factory;
use Joomla\CMS\User\User;
use Joomla\CMS\User\UserFactoryInterface;
use Joomla\Database\DatabaseInterface;
use Joomla\Database\ParameterType;

final class JoomlaApiTokenAuthenticator
{
    /** @var list<string> */
    private const ALLOWED_ALGOS = ['sha256', 'sha512'];

    public static function authenticate(string $token): ?User
    {
        if ($token === '') {
            return null;
        }

        $decoded = base64_decode($token, true);

        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }

        $parts = explode(':', $decoded, 3);

        if (count($parts) !== 3) {
            return null;
        }

        [$algo, $userIdRaw, $tokenHmac] = $parts;

        if (!in_array($algo, self::ALLOWED_ALGOS, true)) {
            return null;
        }

        $userId = (int) $userIdRaw;

        if ($userId <= 0 || $tokenHmac === '') {
            return null;
        }

        $app = Factory::getApplication();
        $siteSecret = (string) $app->get('secret');

        if ($siteSecret === '') {
            return null;
        }

        $db = Factory::getContainer()->get(DatabaseInterface::class);
        $tokenSeed = self::loadProfileValue($db, $userId, 'joomlatoken.token');
        $enabled = self::loadProfileValue($db, $userId, 'joomlatoken.enabled');

        if ($tokenSeed === null || (int) $enabled !== 1) {
            return null;
        }

        $referenceTokenData = base64_decode($tokenSeed, true);

        if ($referenceTokenData === false || $referenceTokenData === '') {
            return null;
        }

        $referenceHmac = hash_hmac($algo, $referenceTokenData, $siteSecret);

        if (!Crypt::timingSafeCompare($referenceHmac, $tokenHmac)) {
            return null;
        }

        $user = Factory::getContainer()
            ->get(UserFactoryInterface::class)
            ->loadUserById($userId);

        if ($user->block || !empty(trim((string) $user->activation)) || $user->requireReset) {
            return null;
        }

        return $user;
    }

    private static function loadProfileValue(DatabaseInterface $db, int $userId, string $profileKey): ?string
    {
        $query = $db->getQuery(true)
            ->select($db->quoteName('profile_value'))
            ->from($db->quoteName('#__user_profiles'))
            ->where($db->quoteName('user_id') . ' = :uid')
            ->where($db->quoteName('profile_key') . ' = :pkey')
            ->bind(':uid', $userId, ParameterType::INTEGER)
            ->bind(':pkey', $profileKey);

        $result = $db->setQuery($query)->loadResult();

        return is_string($result) ? $result : null;
    }
}
