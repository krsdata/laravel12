<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model as Eloquent;

class Competition extends Eloquent
{

   
    /**
     * The database table used by the model.
     *
     * @var string
     */
    protected $table = 'competitions';
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


    public function reportedUser()
    {
        return $this->hasMany('App\User', 'id', 'reportedUserId') ;
    }
}
