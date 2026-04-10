<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Rank extends Eloquent
{

   
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'ranks';
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

     protected $hidden = [
        'id', 'created_at','updated_at'
    ];
    

    protected $guarded = ['created_at' , 'updated_at' , 'id' ];

    public function match()
    {
        return $this->hasOne('App\Models\Matches', 'match_id', 'match_id') ;
    }

    public function user()
    {
        return $this->hasOne('App\Models\User', 'id', 'user_id') ;
    }

    public function joinContest()
    {
        return $this->hasOne('App\Models\JoinContest', 'id', 'join_contest_id') ;
    }
    public function createdTeam()
    {
        return $this->hasOne('App\Models\CreateTeam', 'id', 'team_id') ;
    }    
}
