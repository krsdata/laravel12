<?php

namespace App\Models;
 
use Jenssegers\Mongodb\Eloquent\Model as Eloquent;
use Auth;
use Jenssegers\Mongodb\Eloquent\SoftDeletes;


class Ninja extends Eloquent {

    use SoftDeletes;    
    /**
     * The database table used by the model.
     *
     * @var string
     */
     protected $collection = 'ninja';
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
     * The attributes that are mass assignable.
     *
     * @var array
     */
    


    /**
     * The attributes that should be hidden for arrays.
     *
     * @var array
     */
    protected $hidden = [
        'password', 'remember_token'
    ];

    protected $guarded = ['created_at' , 'updated_at' , 'id' ];

    

}