<?php

namespace esc\Modules;


use esc\Classes\Hook;
use esc\Classes\Template;
use esc\Controllers\MapController;
use esc\Models\Player;

class aAddedTimeInfo
{
    public function __construct()
    {
        // Hook::add('PlayerConnect', [self::class, 'playerConnect']);
        // Hook::add('TimeLimitUpdated', [self::class, 'timeLimitUpdated']);
    }

    public static function timeLimitUpdated($timeLimitInSeconds)
    {
        $addedMinutes = floor($timeLimitInSeconds / 60);
        Template::showAll('added-time-info.meter', compact('addedMinutes'));
    }

    public static function playerConnect(Player $player)
    {
        $addedMinutes = floor(MapController::getAddedTime() / 60);
        Template::show($player, 'added-time-info.meter', compact('addedMinutes'));
    }
}