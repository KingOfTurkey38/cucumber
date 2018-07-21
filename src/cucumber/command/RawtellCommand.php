<?php
declare(strict_types=1);

namespace cucumber\command;

use cucumber\Cucumber;
use cucumber\utils\CucumberPlayer;
use cucumber\utils\MessageFactory;
use pocketmine\command\CommandSender;

/**
 * Sends a raw private message to a player
 */
class RawtellCommand extends CucumberCommand
{

    public function __construct(Cucumber $plugin)
    {
        parent::__construct($plugin, 'rawtell', 'cucumber.command.rawtell', 'Send a raw message to a player',
            1, '/rawtell <player> <message> [-nom] [-p] [-t]', [
                'nom' => 0,
                'p' => 0,
                't' => 0
            ]);
    }

    public function _execute(CommandSender $sender, ParsedCommand $command): bool
    {
        [$target_name, $message] = $command->get([0, [1, -1]]);
        $message = MessageFactory::colorize($message);

        if ($target = CucumberPlayer::getOnlinePlayer($target_name)) {
            $this->formatAndSend($sender, 'error.player-offline', ['player' => $target_name]);
            return false;
        }

        if (!$command->getTag('nom'))
            $target->sendMessage($message);

        if ($command->getTag('p'))
            $target->sendPopup($message);

        if ($command->getTag('t'))
            $target->addSubTitle($message); // title is too big

        $this->formatAndSend($sender, 'success.rawtell', ['player' => $target_name, 'message' => $message]);

        return true;
    }

}