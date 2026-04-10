<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Player extends Eloquent
{

   
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'players';
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    /**
    * The primary key used by the model.
    *
    * @var string
    */
    protected $primaryKey = 'id';

    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
 

    protected $guarded = ['created_at' , 'updated_at' , 'id' ];


    public function teama()
    {
        return $this->hasOne('App\Models\TeamASquad', 'player_id', 'pid') ;
    }
    public function teamb()
    {
       return $this->hasOne('App\Models\TeamBSquad', 'player_id', 'pid') ;
    }

    public function team_a()
    {
       return $this->hasOne('App\Models\TeamA', 'team_id', 'team_id')->select('team_id','short_name') ;
    }

    public function team_b()
    {
       return $this->hasOne('App\Models\TeamB', 'team_id', 'team_id')->select('team_id','short_name') ;
    }

    public function matchPoints()
    {
       return $this->hasOne('App\Models\MatchPoint', 'pid','pid');
    }
     public function teamAId()
    {
        return $this->hasOne('App\Models\TeamA', 'match_id', 'match_id') ;
    }
    public function teamBId()
    {
       return $this->hasOne('App\Models\TeamB', 'match_id', 'match_id') ;
    }
}
