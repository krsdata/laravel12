<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class MatchPoint extends Eloquent
{

   
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'match_player_points'; 
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
        return $this->hasOne('App\Models\TeamA', 'match_id', 'match_id') ;
    }
     public function teamb()
    {
        return $this->hasOne('App\Models\TeamB', 'match_id', 'match_id') ;
    }
    public function player()
    {
        return $this->hasOne('App\Models\Player', 'pid', 'pid')->select('pid','team_id','match_id','short_name','fantasy_player_rating');
    }
}
