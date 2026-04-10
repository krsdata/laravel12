<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Matches extends Eloquent
{  
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'matches';
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

    
    public function player()
    {
        return $this->hasOne('App\Models\Player', 'match_id', 'match_id') ;
    }

    public function teama()
    {
        return $this->hasOne('App\Models\TeamA', 'match_id', 'match_id') ;
    }
     public function teamb()
    {
        return $this->hasOne('App\Models\TeamB', 'match_id', 'match_id') ;
    }
}
