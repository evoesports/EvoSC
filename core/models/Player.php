<?php

namespace esc\models;


use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    protected $table = 'players';

    protected $fillable = ['NickName', 'LadderScore'];
}