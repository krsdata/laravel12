<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\User;
use Illuminate\Support\Facades\Auth; 
use App\Models\Notification;
use Illuminate\Contracts\Encryption\DecryptException;
use Config,Mail,View,Redirect,Validator,Response; 
use Crypt,okie,Hash,Lang,JWTAuth,Input,Closure,URL; 
use App\Helpers\Helper as Helper;
use Illuminate\Support\Facades\Storage;
use App\Models\Competition;
use App\Models\TeamA;
use App\Models\TeamB;
use App\Models\Toss;
use App\Models\Venue;
use App\Models\Matches;
use App\Models\ReferralCode;



class CronController extends BaseController
{
   
    public function __construct(Request $request) {

        if ($request->header('Content-Type') != "application/json")  {
            $request->headers->set('Content-Type', 'application/json');
        }
        $user_name = $request->user_id;
        $user = User::where('user_name',$user_name)->first();
        if($user && $request->user_id){
            $request->merge(['user_id'=>$user->id]);    
        }else{
            $request->merge(['user_id'=>null]);
        }
    } 

     public function getMatchDataFromApi()
     {

        $date = date('Y-m-d');
        $token = "8740931958a5c24fed8b66c7609c1c49";
        //upcoming
        $upcoming =    file_get_contents('https://rest.entitysport.com/v2/matches/?status=1&pre_squad=true&date='.$date.'&per_page=100&paged=1&token='.$token);

        \File::put(public_path('/upload/json/upcoming.txt'),$upcoming);

        //complted
        $completed =    file_get_contents('https://rest.entitysport.com/v2/matches/?status=2&pre_squad=true&date='.$date.'&per_page=10&paged=1&token='.$token);

        \File::put(public_path('/upload/json/completed.txt'),$completed);

        //live
        $live =    file_get_contents('https://rest.entitysport.com/v2/matches/?status=3&pre_squad=true&date='.$date.'&per_page=10&paged=1&token='.$token);

        \File::put(public_path('/upload/json/live.txt'),$live);

        sleep(1);
        $this->updateMatchInDB();

       return  ['Match info updated'];

    } 

     public function updateMatchDataByStatus($status=1)
     {

        if($status==1){
            $fileName="upcoming";
        }
        elseif($status==2){
            $fileName="completed";
        }
        elseif($status==3){
            $fileName="live";
        }else{
            return ['data not available'];
        }

        $date = date('Y-m-d');
        $token = "8740931958a5c24fed8b66c7609c1c49";
        //upcoming
        $data =    file_get_contents('https://rest.entitysport.com/v2/matches/?status='.$status.'&pre_squad=true&date='.$date.'&per_page=10&paged=1&token='.$token);
        
        \File::put(public_path('/upload/json/'.$fileName.'.txt'),$data);

        $this->updateMatchInDB();
        

        return Redirect::to(URL::previous())
                            ->with('flash_alert_notice', $fileName.' Match status updated');

    } 

    //get file data from local
    public function getJsonFromLocal($path=null)
    {
        return json_decode(file_get_contents($path));

    }

    // store by match type
    public function updateMatchInDB(){

        $files = ['live','completed','upcoming'];
        
        try {
            foreach ($files  as $key => $fileName) {
                $data = $this->getJsonFromLocal(public_path('/upload/json/'.$fileName.'.txt'));
                $this->saveMatchDataFromAPI($data);
            } 
            
        } catch (Exception $e) {
                dd($e);
        }

        return ['match info stored'];
    }

    public function saveMatchDataFromAPI($data){

       // dd($data->response->items);
        if(count($data->response->items)){

            $results = $data->response->items;
            
            foreach ($results as $key => $result_set) {
                    
                    foreach ($result_set as $key => $rs) {
                        $data_set[$key] = $rs;
                    }    
                        $competition = Competition::firstOrNew(['match_id' => $data_set['match_id']]);
                        $competition->match_id   = $data_set['match_id'];

                        foreach ($data_set['competition'] as $key => $value) {
                            $competition->$key = $value;
                        }
                        $competition->save();
                        $competition_id = $competition->id;

                        /*TEAM A*/
                        $team_a = TeamA::firstOrNew(['match_id' => $data_set['match_id']]);
                        $team_a->match_id   = $data_set['match_id'];

                        foreach ($data_set['teama'] as $key => $value) {
                            $team_a->$key = $value;
                        }

                        $team_a->save();

                        $team_a_id = $team_a->id;


                        /*TEAM B*/
                        $team_b = TeamB::firstOrNew(['match_id' => $data_set['match_id']]);
                        $team_b->match_id   = $data_set['match_id'];

                        foreach ($data_set['teamb'] as $key => $value) {
                            $team_b->$key = $value;
                        }

                        $team_b->save();
                        $team_b_id = $team_b->id;


                          /*Venue */
                        $venue = Venue::firstOrNew(['match_id' => $data_set['match_id']]);
                        $venue->match_id   = $data_set['match_id'];

                        foreach ($data_set['venue'] as $key => $value) {
                            $venue->$key = $value;
                        }

                        $venue->save();
                        $venue_id = $venue->id;


                          /*Venue */
                        $toss = Toss::firstOrNew(['match_id' => $data_set['match_id']]);
                        $toss->match_id   = $data_set['match_id'];

                        foreach ($data_set['toss'] as $key => $value) {
                            $toss->$key = $value;
                        }

                        $toss->save();
                        $toss_id = $toss->id;
                 
                        $remove_data = ['toss','venue','teama','teamb','competition'];

                       
                        $matches = Matches::firstOrNew(['match_id' => $data_set['match_id']]);
                        
                        foreach ($data_set as $key => $value) {
                            
                            if(in_array($key, $remove_data)){
                                continue;
                            }
                            $matches->$key = $value;

                        }
                        $matches->toss_id = $toss_id;
                        $matches->venue_id = $venue_id;
                        $matches->teama_id = $team_a_id;
                        $matches->teamb_id = $team_b_id;
                        $matches->competition_id = $toss_id;

                        $matches->save();
                    
            }            

        }
        return ["match info updated "];

    }
 
 
}
