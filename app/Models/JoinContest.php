<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class JoinContest extends Eloquent
{

   
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'join_contests';
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

    
    public function createTeam()
    {
        return $this->hasOne('App\Models\CreateTeam', 'id', 'created_team_id') ;
    }
    public function match()
    {
        return $this->hasOne('App\Models\Matches', 'match_id', 'match_id') ;
    }
    public function contest()
    {
        return $this->hasOne('App\Models\CreateContest', 'id', 'contest_id') ;
    }
    public function user()
    {
        return $this->belongsTo('App\User', 'user_id', 'id') ;
    }
}

