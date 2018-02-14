<?php

namespace esc\controllers;


use esc\classes\Config;
use esc\classes\Database;
use esc\classes\File;
use esc\classes\Log;
use esc\classes\RestClient;
use esc\models\Map;

class MapController
{
    public static function initialize()
    {
        self::createTables();

        ChatController::addCommand('add', '\esc\controllers\MapController::addMap', 'Add a map from mx by it\'s id', '@');
    }

    private static function createTables()
    {
        Database::create('maps', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->increments('id');
            $table->string('MxId')->unique();
            $table->string('Name');
            $table->string('FileName');
        });
    }

    public static function addMap(string ...$arguments)
    {
        $mxId = intval($arguments[1]);

        if ($mxId == 0) {
            Log::warning("Requested map with invalid id: " . $arguments[1]);
            ChatController::messageAll("Requested map with invalid id: " . $arguments[1]);
            return;
        }

        $response = RestClient::get('http://tm.mania-exchange.com/tracks/download/' . $mxId);

        if ($response->getStatusCode() != 200) {
            Log::error("ManiaExchange returned with non-success code [$response->getStatusCode()] " . $response->getReasonPhrase());
            ChatController::messageAll("Can not reach mania exchange.");
            return;
        }

        if ($response->getHeader('Content-Type')[0] != 'application/x-gbx') {
            Log::warning('Not a valid GBX.');
            return;
        }

        $fileName = preg_replace('/^attachment; filename="(.+)"$/', '\1', $response->getHeader('content-disposition')[0]);
        $mapFolder = Config::get('server.maps');
        File::put("$mapFolder/$fileName", $response->getBody());

        $name = str_replace('.Map.Gbx', '', $fileName);

        $map = Map::updateOrCreate([
            'MxId' => $mxId,
            'Name' => $name,
            'FileName' => $fileName
        ]);

        RpcController::getRpc()->insertMap($map->FileName);

        ChatController::messageAll("Admin added map \$eee$name.");
    }

    public static function setRandomNext()
    {
        $map = Map::all()->random();
        self::setNext($map);
    }

    public static function setNext(Map $map = null)
    {
        $maps = collect(RpcController::getRpc()->getMapList());
//        var_dump($maps);
//        RpcController::getRpc()->setNextMapIndex($map->FileName);
//        ChatController::messageAll("Next map changed to $map->Name");
    }

    public static function next()
    {
        RpcController::getRpc()->nextMap();
    }
}