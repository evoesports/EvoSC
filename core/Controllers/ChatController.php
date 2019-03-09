<?php

namespace esc\Controllers;


use esc\Classes\ChatCommand;
use esc\Classes\ChatMessage;
use esc\Classes\Hook;
use esc\Classes\Log;
use esc\Classes\Module;
use esc\Classes\Server;
use esc\Interfaces\ControllerInterface;
use esc\Models\AccessRight;
use esc\Models\Dedi;
use esc\Models\Group;
use esc\Models\LocalRecord;
use esc\Models\Map;
use esc\Models\Player;
use esc\Models\Song;
use Illuminate\Support\Collection;
use Maniaplanet\DedicatedServer\Xmlrpc\FaultException;

class ChatController implements ControllerInterface
{
    /* maniaplanet chat styling
$i: italic
$s: shadowed
$w: wide
$n: narrow
$m: normal
$g: default color
$o: bold
$z: reset all
$t: Changes the text to capitals
$$: Writes a dollarsign
     */

    /**
     * @var Collection
     */
    private static $chatCommands;

    /**
     * @var Collection
     */
    private static $mutedPlayers;

    /**
     * @var Collection
     */
    private static $chatCommandsCompiled;

    /**
     * @var Collection
     */
    private static $aliases;

    public static function init()
    {
        self::$chatCommands = collect();
        self::$mutedPlayers = collect();
        self::$aliases      = collect();

        try {
            Server::call('ChatEnableManualRouting', [true, false]);
        } catch (FaultException $e) {
            $msg = $e->getMessage();
            Log::getOutput()->writeln("<error>$msg There might already be a running instance of EvoSC.</error>");
            exit(2);
        }

        Hook::add('PlayerChat', [ChatController::class, 'playerChat']);

        AccessRight::createIfNonExistent('player_mute', 'Mute/unmute player.');
        AccessRight::createIfNonExistent('admin_echoes', 'Receive admin messages.');

        ChatCommand::add('mute', [ChatController::class, 'mute'], 'Mutes a player by given nickname', '//', 'player_mute');
        ChatCommand::add('unmute', [ChatController::class, 'unmute'], 'Unmute a player by given nickname', '//', 'player_mute');
    }

    public static function getChatCommands(): Collection
    {
        return self::$chatCommands;
    }

    public static function mute(Player $player, $cmd, $nick)
    {
        $target = PlayerController::findPlayerByName($player, $nick);

        if (!$target) {
            //No target found
            return;
        }

        $ply     = collect();
        $ply->id = $target->id;

        self::$mutedPlayers = self::$mutedPlayers->push($ply)->unique();
    }

    public static function unmute(Player $player, $cmd, $nick)
    {
        $target = PlayerController::findPlayerByName($player, $nick);

        if (!$target) {
            //No target found
            return;
        }

        self::$mutedPlayers = self::$mutedPlayers->filter(function ($player) use ($target) {
            return $player->id != $target->id;
        });
    }

    public static function playerChat(Player $player, $text)
    {
        if (self::$chatCommandsCompiled->contains(explode(' ', $text)[0])) {
            //chat command detected
            if (self::executeChatCommand($player, $text)) {
                return;
            }
        }

        if (preg_match('/^(\/|\/\/|##)/', $text)) {
            //Catch invalid chat commands
            warningMessage('Invalid chat command entered.')
                ->send($player);

            return;
        }

        if (self::$mutedPlayers->where('id', $player->id)->isNotEmpty()) {
            //Player is muted
            warningMessage('You are muted.')->send($player);

            return;
        }

        Log::logAddLine("Chat", '[' . $player . '] ' . $text, true);
        $nick = $player->NickName;

        if (preg_match('/([$]+)$/', $text, $matches)) {
            //Escape dollar signs
            $text .= $matches[0];
        }

        if ($player->isSpectator()) {
            $nick = '$eee📷 ' . $nick;
        }

        $prefix   = $player->group->chat_prefix;
        $color    = $player->group->color ?? config('colors.chat');
        $chatText = sprintf('$%s[$z$s%s$z$s$%s] $%s$z$s%s', $color, $nick, $color, config('colors.chat'), $text);

        if ($prefix) {
            $chatText = '$' . $color . $prefix . ' ' . $chatText;
        }

        Server::call('ChatSendServerMessage', [$chatText]);
    }

    private static function executeChatCommand(Player $player, string $text): bool
    {
        $isValidCommand = false;

        //treat "this is a string" as single argument
        if (preg_match_all('/\"(.+?)\"/', $text, $matches)) {
            foreach ($matches[1] as $match) {
                //Replace all spaces in quotes to ;:;
                $new  = str_replace(' ', ';:;', $match);
                $text = str_replace("\"$match\"", $new, $text);
            }
        }

        //Split input string in arguments
        $arguments = explode(' ', $text);

        foreach ($arguments as $key => $argument) {
            //Change ;:; back to spaces
            $arguments[$key] = str_replace(';:;', ' ', $argument);
        }

        //Find command
        $command = self::$chatCommands
            ->filter(function (ChatCommand $command) use ($arguments) {
                return strtolower($command->compile()) == strtolower($arguments[0]);
            })
            ->first();

        if ($command->access != null) {
            if (!$player->hasAccess($command->access)) {
                warningMessage('Sorry, you are not allowed to do that.')->send($player);

                return false;
            }
        }

        //Add calling player to beginning of arguments list
        array_unshift($arguments, $player);

        if ($command) {
            //Command exists
            try {
                //Run command callback
//                call_user_func($command->callback, ...$arguments);
                $command->run($arguments);
            } catch (\Exception $e) {
                Log::logAddLine('ChatController', 'Failed to execute chat command: ' . $e->getMessage(), true);
                Log::logAddLine('ChatController', $e->getTraceAsString(), false);
            }
            $isValidCommand = true;
        }

        return $isValidCommand;
    }

    public static function compileChatCommand(ChatCommand $command)
    {
        return $command->compile();
    }

    /**
     * @param string         $command
     * @param array|\Closure $callback
     * @param string         $description
     * @param string         $trigger
     * @param string|null    $access
     * @param bool           $hidden
     */
    public static function addCommand(string $command, $callback, string $description = '-', string $trigger = '/', string $access = null, $hidden = false)
    {
        if (!self::$chatCommands) {
            self::$chatCommands         = collect();
            self::$chatCommandsCompiled = collect();
        }

        $chatCommand = new ChatCommand($trigger, $command, $callback, $description, $access);
        self::$chatCommands->push($chatCommand);
        self::$chatCommandsCompiled = self::$chatCommands->map([self::class, 'compileChatCommand']);

        Log::info("Chat command added: " . $chatCommand->compile(), false);
    }

    /**
     * @param string $command
     * @param string $alias
     */
    public static function addAlias(string $command, string $alias)
    {
        //TODO: Add aliases
    }
}