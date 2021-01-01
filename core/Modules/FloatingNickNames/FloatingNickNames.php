<?php


namespace EvoSC\Modules\FloatingNickNames;


use EvoSC\Classes\Hook;
use EvoSC\Classes\Module;
use EvoSC\Classes\Template;
use EvoSC\Interfaces\ModuleInterface;
use EvoSC\Models\Player;

class FloatingNickNames extends Module implements ModuleInterface
{
    /**
     * @inheritDoc
     */
    public static function start(string $mode, bool $isBoot = false)
    {
        if (isManiaPlanet()) {
            return;
        }

        Hook::add('PlayerConnect', [self::class, 'sendScript']);
        Hook::add('BeginMatch', [self::class, 'sendScript']);
        Hook::add('PlayerDisconnect', [self::class, 'playerPoolChanged']);
        Hook::add('PlayerChangedName', [self::class, 'playerPoolChanged']);
    }

    /**
     * @param Player|null $player
     * @throws \EvoSC\Exceptions\InvalidArgumentException
     */
    public static function sendScript(Player $player = null)
    {
        if(is_null($player)){
            Template::showAll('FloatingNickNames.script');
        }else{
            Template::show($player, 'FloatingNickNames.script');
        }

        self::playerPoolChanged();
    }

    /**
     * @param null $value
     */
    public static function playerPoolChanged($value = null)
    {
        $data = onlinePlayers()->transform(function (Player $player) {
            return [
                'login' => $player->Login,
                'name' => $player->NickName,
                'prefix' => $player->group->chat_prefix,
                'color' => $player->group->color,
            ];
        })->values()->toJson();

        Template::showAll('FloatingNickNames.update', compact('data'));
    }
}