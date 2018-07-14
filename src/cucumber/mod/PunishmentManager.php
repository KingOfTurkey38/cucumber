<?php

namespace cucumber\mod;

use cucumber\Cucumber;
use cucumber\utils\CException;
use cucumber\utils\CPlayer;
use cucumber\utils\ErrorCodes;
use cucumber\utils\Queries;
use poggit\libasynql\result\SqlSelectResult;

final class PunishmentManager
{

    /** @var Cucumber */
    private $plugin;

    /** @var string[][] */
    private $messages;

    /** @var SimplePunishment[] */
    private $bans;

    /** @var SimplePunishment[] */
    private $ip_bans;

    /** @var SimplePunishment[] */
    private $mutes;

    /**
     * New punishments that have not yet been saved to permanent storage
     * @var SimplePunishment[][]
     */
    private $not_saved = [];

    /**
     * Newly-pardoned punishments that have not
     * yet been deleted from permanent storage
     * This is a list of UIDs
     * @var string[][]
     */
    private $not_deleted = [];

    public function __construct(Cucumber $plugin)
    {
        $this->plugin = $plugin;
        $this->initMessages();
        $this->load();
    }

    private function initMessages(): void
    {
        $this->messages = [
            'ban' => [
                'already-banned' => '%player% is already banned!',
                'not-banned' => '%player% is not banned!'
            ],
            'ip-ban' => [
                'already-banned' => 'IP %ip% is already banned!',
                'not-banned' => 'IP %ip% is not banned!',
            ],
            'mute' => [
                'already-muted' => '%player% is already muted!',
                'not-muted' => '%player% is not muted!'
            ]
        ];
    }

    private function load(): void
    {
        $connector = $this->plugin->getConnector();
        $queries = [Queries::CUCUMBER_GET_PUNISHMENTS_BANS, Queries::CUCUMBER_GET_PUNISHMENTS_IP_BANS,
            Queries::CUCUMBER_GET_PUNISHMENTS_MUTES];
        $storage = [&$this->bans, &$this->ip_bans, &$this->mutes];

        foreach ($queries as $key => $query)
            $connector->executeSelect(Queries::CUCUMBER_GET_PUNISHMENTS_BANS, [],
                function (SqlSelectResult $result) use ($key, $storage) {
                    foreach ($result as $row)
                        $storage[$key][$row['uid']] = SimplePunishment::from($row);
                });
    }

    public function save(): void
    {
        $connector = $this->plugin->getConnector();
        $queries = ['ban' => Queries::CUCUMBER_PUNISH_BAN, 'ip-ban' => Queries::CUCUMBER_PUNISH_IP_BAN,
            'mute' => Queries::CUCUMBER_PUNISH_MUTE];
        $ids = ['ban' => 'uid', 'ip-ban' => 'ip', 'mute' => 'uid'];

        foreach ($queries as $type => $query)
            foreach ($this->not_saved[$type] as $id => $punishment)
                $connector->executeInsert($query,
                    [
                        $ids[$type] => $id,
                        'reason' => $punishment->getReason(),
                        'expiration' => $punishment->getExpiration(),
                        'moderator' => $punishment->getModeratorId()
                    ]);

        $queries = ['ban' => Queries::CUCUMBER_PUNISH_UNBAN, 'ip-ban' => Queries::CUCUMBER_PUNISH_IP_UNBAN,
            'mute' => Queries::CUCUMBER_PUNISH_UNMUTE];

        foreach ($queries as $type => $query)
            foreach ($this->not_deleted as $id)
                $connector->executeChange($query, [$ids[$type] => $id]);
    }

    /**
     * @param $id
     * @param SimplePunishment $punishment
     * @param string $type
     * @param array $storage
     * @param CException $exception
     * @throws CException If the ID is already punished
     */
    private function punish($id, SimplePunishment $punishment, string $type, array &$storage, CException $exception)
    {
        if (isset($storage[$id]))
            throw $exception;

        $storage[$id] = $punishment;
        $this->not_saved[$type][$id] = $punishment;
    }

    /**
     * @param $id
     * @param string $type
     * @param array $storage
     * @param CException $exception
     * @throws CException If the ID is not punished
     */
    private function pardon($id, string $type, array &$storage, CException $exception)
    {
        if (!isset($storage[$id]))
            throw $exception;

        unset($storage[$id]);
        $this->not_deleted[$type][] = $id;
    }

    /**
     * @param CPlayer $player
     * @param SimplePunishment $punishment
     * @param string $type
     * @param array $storage
     * @param string $error_message
     * @throws CException If the player is already punished
     */
    private function playerPunish(CPlayer $player, SimplePunishment $punishment, string $type, array &$storage, string $error_message)
    {
        $this->punish($player->getUid(), $punishment, $type, $storage,
            new CException(
                $error_message,
                ['player' => $player->getName()],
                ErrorCodes::ATTEMPT_PUNISH_PUNISHED
            ));
    }

    /**
     * @param CPlayer $player
     * @param string $type
     * @param array $storage
     * @param string $error_message
     * @throws CException If the player is not punished
     */
    private function playerPardon(CPlayer $player, string $type, array &$storage, string $error_message)
    {
        $this->pardon($player->getUid(), $type, $storage,
            new CException(
                $error_message,
                ['player' => $player->getName()],
                ErrorCodes::ATTEMPT_PARDON_NOT_PUNISHED
            ));
    }

    /**
     * @param CPlayer $player
     * @param string|null $reason
     * @param int|null $expiration
     * @param string $moderator
     */
    public function ban(CPlayer $player, ?string $reason, ?int $expiration, string $moderator): void
    {
        $this->plugin->getConnector()->executeSelect(Queries::CUCUMBER_GET_FIND_PLAYER_BY_NAME,
            ['name' => $moderator],
            function(SqlSelectResult $result) use ($player, $reason, $expiration) {
                $id = $result[0]['id'];
                $this->playerPunish(
                    $player,
                    new SimplePunishment($reason, $expiration, $id),
                    'ban',
                    $this->bans,
                    $this->messages['ban']['already-banned']
                );
            });
    }

    /**
     * @param CPlayer $player
     * @throws CException If the player is not banned
     */
    public function unban(CPlayer $player): void
    {
        $this->playerPardon($player, 'ban', $this->bans, $this->messages['ban']['not-banned']);
    }

    /**
     * @param int $ip
     * @param string|null $reason
     * @param int|null $expiration
     * @param string $moderator
     */
    public function ipBan(int $ip, string $reason = null, int $expiration = null, string $moderator): void
    {
        $this->plugin->getConnector()->executeSelect(Queries::CUCUMBER_GET_FIND_PLAYER_BY_NAME,
            ['name' => $moderator],
            function(SqlSelectResult $result) use ($ip, $reason, $expiration) {
                $id = $result[0]['id'];
                $this->punish($ip,
                    new SimplePunishment($reason, $expiration, $id),
                    'ip-ban',
                    $this->ip_bans,
                    new CException(
                        $this->messages['ip-ban']['already-banned'],
                        ['ip' => $ip],
                        ErrorCodes::ATTEMPT_PUNISH_PUNISHED
                    ));
            });
    }

    /**
     * @param int $ip
     * @throws CException If the IP is not banned
     */
    public function ipUnban(int $ip)
    {
        $this->pardon($ip, 'ip-ban', $this->ip_bans,
            new CException(
                $this->messages['ip-ban']['not-banned'],
                ['ip' => $ip],
                ErrorCodes::ATTEMPT_PARDON_NOT_PUNISHED
            ));
    }

    /**
     * @param CPlayer $player
     * @param string|null $reason
     * @param int|null $expiration
     * @param string $moderator
     */
    public function mute(CPlayer $player, ?string $reason, ?int $expiration, string $moderator): void
    {
        $this->plugin->getConnector()->executeSelect(Queries::CUCUMBER_GET_FIND_PLAYER_BY_NAME,
            ['name' => $moderator],
            function(SqlSelectResult $result) use ($player, $reason, $expiration) {
                $id = $result[0]['id'];
                $this->playerPunish(
                    $player,
                    new SimplePunishment($reason, $expiration, $id),
                    'mute',
                    $this->bans,
                    $this->messages['mute']['already-muted']
                );
            });
    }

    /**
     * @param CPlayer $player
     * @throws CException If the player is not muted
     */
    public function unmute(CPlayer $player): void
    {
        $this->playerPardon($player, 'mute', $this->mutes, $this->messages['mute']['not-muted']);
    }

    public function isBanned(CPlayer $player): bool
    {
        $banned = false;
        $uid = $player->getUid();
        $ip = $player->getIp();

        if (isset($this->bans[$uid])) {
            if ($this->bans[$uid]->isExpired())
                $this->unban($player);
            else $banned = true;
        }

        if (isset($this->ip_bans[$ip])) {
            if ($this->ip_bans[$ip]->isExpired())
                $this->ipUnban($ip);
            else $banned = true;
        }

        return $banned;
    }

    public function isMuted(CPlayer $player): bool
    {
        $banned = false;
        $uid = $player->getUid();

        if (isset($this->mutes[$uid])) {
            if ($this->bans[$uid]->isExpired())
                $this->unmute($player);
            else $banned = true;
        }

        return $banned;
    }

}