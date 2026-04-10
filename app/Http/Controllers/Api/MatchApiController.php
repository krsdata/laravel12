<?php
namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\User;
use Illuminate\Support\Facades\Auth;
use App\Models\Notification;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\QueryException;
use Config,Mail,View,Redirect,Validator,Response;
use Crypt,okie,Hash,Lang,Input,Closure,URL;
use App\Helpers\Helper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Illuminate\Support\Str;
use Monolog\Handler\SyslogUdpHandler;
use App\Models\Competition;
use App\Models\TeamA;
use App\Models\TeamB;
use App\Models\Toss;
use App\Models\Venue;
use App\Models\Matches;
use App\Models\Player;
use App\Models\TeamASquad;
use App\Models\TeamBSquad;
use App\Models\CreateContest;
use App\Models\CreateTeam;
use App\Models\Wallet;
use App\Models\JoinContest;
use App\Models\WalletTransaction;
use App\Models\MatchPoint;
use App\Models\PrizeDistribution;
use App\Models\MatchStat;
use App\Models\ReferralCode;
use App\Models\PrizeBreakup;
use File;
use Razorpay\Api\Api;
use PaytmWallet; 
use paytm\checksum\PaytmChecksumLibrary; 
use Illuminate\Support\Facades\Cache;
use Jenssegers\Agent\Agent;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Kreait\Firebase\Auth as FirebaseAuth;
use Firebase\Auth\Token\Exception\InvalidToken;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Helpers\RedisCache as Redis;
use Facades\Spatie\Referer\Referer;



class MatchApiController extends BaseController
{
    public $token;
    public $date;
    public $cric_url;
    public $is_session_expire;
    public $myid;

    public function __construct(Request $request) {
        
        $this->date = date('Y-m-d');
        $this->token = env('CRIC_API_KEY',"a8bb93d162f1749b546c5149e72690ed");
        $request->headers->set('Accept', 'application/json');
        
        $this->cric_url = 'http://rest.entitysport.com/v2/'; 

        $data['user_id'] = $request->user_id??'API';
        $this->myid     =   $request->user_id;

        $data['url']  = $request->path();
       
       if($request->user_id==285 || $request->user_id=="KUNA2020")
       {
            $_ENV['DEVELOPMENT'] = 'false';
       }


       $url = $_SERVER['SERVER_NAME'];
       $ip = $_SERVER['HTTP_X_FORWARDED_FOR']; //\Request::ip() ;
   
       \DB::connection('mysql2')->table('api_counts')->insert(
                   [
                       'source_url' => URL::previous(),
                       'ip' =>$ip,
                       'token' => $request->token,
                       'api' =>  $request->path()
                   ]
           );


       
       $status = $this->validateUser($request);
       if($status)
       {
          echo json_encode($status);
          die;
       }

    }

    public function validateUser(Request $request)
    {
        
        $token = $request->token;
        
        $key = \DB::connection('mysql2')->table('token_auth')->where('token',$token)->count();

        if($key==0)
        {
            return [

                'status' => 'unauthorized',
                'response' => 'API Token is expired',
                'api_version' => "1.0"
            ];
            exit();
        }
 
    }

    public function statusCheck(Request $request)
    {
        $order_id = $request->order;
        
        if($order_id=='' && $request->user_id){
            $init_t = \DB::table('initiate_transactions')
                    ->where('user_id',$request->user_id)
                    ->where('status',1)
                    ->orderBy('id','desc')
                    ->first();
            $order_id = $init_t->order_id??0;    
        }    


        $status = PaytmWallet::with('status');
        
        $status->prepare(['order' => $order_id]);
        $s = $status->check();
        $response = $status->response(); // To get raw response as array
        
        try{
            if($status->isSuccessful()){
            $response['N_STATUS'] = 1;
           return $response;
        }else if($status->isFailed()){
            $response['N_STATUS'] = 2;
            return $response;
        }else if($status->isPending()){
            $response['N_STATUS'] = 3;
          return $response;
        }
        }catch(\Exception $e){
            $response['N_STATUS'] = 2;
          return $response;
        } 
    }


    public function customMessage(Request $request)
    {    
        $uid = User::where('customer_type',3)->pluck('id');
        $date = Carbon::now()->subDays(30);
        $all_user = WalletTransaction::select('user_id')
                ->whereNotIn('user_id',$uid)
                ->whereIn('payment_type',[6]) 
                ->where('created_at', '>=', $date)
                ->pluck('user_id')
                ->unique();    
      //  dd($all_user);
        $all_user2 = User::where('customer_type',0)
                  //   ->whereNotIn('id',[1149])
                  //  ->whereIn('id',[285,11556,15165,361,107712,103852,108331])
                    ->whereIn('id',$all_user)
                    ->pluck('id');
        
        $message = $request->message??"Deposit ₹1111 and Get 1333, Hurr up!! Offer expiry";
        $image =  env('notify_url');

        $title = $request->title??'Deposit ₹1111 and Get 1333'; 
       
        $data = [
            'action'    => 'notify',
            'title'     => $title,
            'message'   => $message,
            'openURL' => "https://ninja11.in"
        ];              
                        
        $notification = [
            'action'    => 'notify', 
            'title'     => $title,
            'body'      => $message,
            'image'     => env('notify_url'),
            'click_action' => "https://ninja11.in"
        ];


      /* $helper = new Helper;
          $device_id = "d_RW3KUFTASdMtmvOFog4R:APA91bFSTBlXJrO6OBl3yT1upoSAeTnZIjHkH0V_tUqlArgvB1BwbAkKRBhBhXITHGYeUU0SMB1kirRymd0r-yb54Yu_rjvltSX5q5z3RIpm01-g_KmUWkAX0BUncDbLwt6FWkoqZZ7V";

        $this->sendNotification($device_id, $data,$notification);
        die('msg sent');*/

        $count =User::whereIn('id',$all_user2)->count(); 
        $j=1;
        for($i=1; $j<=$count; $i++) {
            $offset = $j;
            $j = $i*900; 
            $device_id = User::whereIn('id',$all_user2)->whereNotNull('device_id')
                  ->skip($offset)
                  ->take(900)
                  ->pluck('device_id')
                  ->toArray(); 
          try{

             // $helpr = new Helper; 
                $this->sendNotification($device_id, $data,$notification);
               // return true;
          }catch(\ErrorException $e){
              //return false;
          }
        }
    }
    

    public function updateUserMatchPoints(Request $request){

        $this->getPlayerPoints($request);
        if($request->match_id){
            $matches = Matches::where('match_id',$request->match_id)
                        ->get();
        }else{
            $matches = Matches::where('status',3)
            ->get();
        }
         
        $mat = $matches->transform(function($item,$key)use($request){
                $request->merge(['match_id'=>$item->match_id]);  
                $contests = \DB::table('create_contests')
                    ->where('match_id',$item->match_id)
                    ->where('is_cancelled',0)
                    ->where('filled_spot','>',0)
                    ->get();
                    // get contest based on contest
                    $contest=   $contests->transform(function($item,$key){
                               
                        $ranking = $this->updateMatchRankByMatchId($item->match_id,$item->id);  

                      //  $item->ranking = $ranking;
                      //  return $item;
                      
                      if(count($ranking)>0)
                        {
                            \DB::table('join_contests')->upsert(
                                    $ranking
                                , ['id']);
                    
                            } 

                    });

                $item->contest= $contest;
                return $item;
           // $this->WinningPrizeDistribution($request);        
        });

    
        $item = Matches::where('match_id',$request->match_id)->first();

        $t1 = $item->manual_date??$item->timestamp_start; 
        $t2 = time();
        //time diff
        $td = round((($t1 - $t2)/60),2); 

        if($td < (-2))
        {
            $this->WinningPrizeDistribution($request);
        }

        return [
            'status'=>true,
            'code' => 200,
            'message' => 'points updated'

        ];
    }
    

    public function updateMatchRankByMatchId($match_id=null,$contest_id=null)
    {      
        try{

            $update_ranks  = \DB::table('join_contests')            
                            ->where('match_id',$match_id)
                            ->where('contest_id',$contest_id)
                            ->orderByDesc('points')
                            ->get(); 

            $temp_point = 0;  
            $temp_rank  = 0;     
            $upsert = [];
        //    \DB::beginTransaction();
            foreach ($update_ranks as $key => $value) {        
                    $actual_point = $value->points; 
                    if($actual_point==$temp_point){
                        $rank = $temp_rank;
                    }else{
                        $rank       = $key+1;
                        $temp_point = $value->points; 
                        $temp_rank  = $rank;
                    }
                    
                //    $jc_rank        =  JoinContest::find($value->id);
                //    $jc_rank->ranks = $rank;
                //    $jc_rank->save();
                    if($value->id)
                    {
                        $upsert[] = ['id'=>$value->id,'ranks' => $rank];
                    }
                    

            }

            return $upsert;
            
            // if(count($upsert)>0)
            // {
            //    \DB::table('join_contests')->upsert(
            //         $upsert
            //     , ['id']);
    
            // }            

          //  \DB::commit();

            return true;
        }catch(\Exception $e){ 
            return false;  
        } 
 
    }
    /*
        Get Points
    */
    public function getPoints(request $request){

        $team_id = CreateTeam::find($request->team_id);
        $validator = Validator::make($request->all(), [
            'team_id' => 'required'
        ]); 
        $data = [];
        // Return Error Message
        if ($validator->fails() ||  $team_id==null) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]??"Team is not available"
                )
            );
        }

        $player_id = json_decode($team_id->teams,true);
        $team_arr  = json_decode($team_id->team_id,true);


        $mpObject    =   MatchPoint::where('match_id',$team_id->match_id)->first();

        $playerObject = Player::where('match_id',$team_id->match_id)
            ->whereIn('pid',$player_id);
            $update = 1;
        $final_p11 = $this->getPlaying11Team($team_id->match_id,$update);


        $pids = $playerObject->pluck('pid');
        $pids_role = $playerObject->pluck('playing_role','pid');

        $player_team_id = $playerObject->pluck('team_id','pid')->toArray();

        if(!$mpObject){
            $captain        =   $team_id->captain;
            $vice_captain   =   $team_id->vice_captain;
           // $trump          =   $team_id->trump;

            $players =$playerObject->get()
                        ->transform(function($item,$key){
                        
                        $playing11_a = \DB::table('team_a_squads')
                                    ->where('match_id',$item->match_id)
                                    ->where('player_id',$item->pid)
                                    ->where('playing11','true')
                                    ->first();
                        
                        $playing11_b = \DB::table('team_b_squads')
                                    ->where('match_id',$item->match_id)
                                    ->where('player_id',$item->pid)
                                    ->where('playing11','true')
                                    ->first();

                        if($playing11_a){
                            $item->playing11 = $playing11_a->playing11=='true'?true:false;
                        }elseif ($playing11_b) {
                           $item->playing11 = $playing11_b->playing11=='true'?true:false;
                        }else{
                           $item->playing11 = false; 
                        }             
                          return $item;
                    });

            foreach ($players as $key => $result) {

                if($result->playing_role=='cap'){
                    $result->playing_role = "wk";
                }
                elseif($result->playing_role=='wkbat'){
                    $result->playing_role = "wk";
                }
                elseif($result->playing_role=='wkcap'){
                    $result->playing_role = "wk";
                }   
                // pname 3
                $short_name  = explode(" ",$result->title);
                $lastn  = array_pop($short_name);
                $p_name = "";

                foreach ($short_name as $pn) {
                    $p_name .= $pn[0];
                }
                $pname = $p_name.' '.($lastn);

                $ur = $this->getPlayerPic($result->pid);
                if($ur){
                    $player_image =  env('api_base_url')."/".$ur;    
                }  
                
                $data[] = [
                    'player_image' => $player_image??null,
                    'pid'       => $result->pid,
                    'team_id'   => $result->team_id,
                    'name'      => $pname, //$result->title??$result->short_name,
                    'short_name'=> $pname, //$result->title??$result->short_name,
                    'points'    => 0,
                    'fantasy_player_rating'    => 0,
                    'role'      => $result->playing_role,
                    'captain'   =>  ($captain==$result->pid)?true:false,
                    'vice_captain'   => ($vice_captain==$result->pid)?true:false,
                 //   'trump'     => ($trump==$result->pid)?true:false,
                    'playing11' => $result->playing11??false
                ];
            }
        }
        $total_points = 0;
        $array_sum[] = 0;
        if($team_id && $mpObject!=null)  {
            $teams_id = json_decode($team_id->team_id,true);
            $captain        =   $team_id->captain;
            $vice_captain   =   $team_id->vice_captain;
           // $trump          =   $team_id->trump;

            $player_id = json_decode($team_id->teams,true);
            
            $player_match_id = Player::where('match_id',$team_id->match_id)
                                ->pluck('playing_role','pid')->toArray();

            $mpObject = MatchPoint::where('match_id',$team_id->match_id)
                ->whereIn('pid',$pids)
                ->select('match_id','pid','name','role','rating','point','starting11')->get();
           // return $mpObject;

            $mpObject->transform(function($item,$key)use($pids_role,$player_match_id){
                        $playing11_a = \DB::table('team_a_squads')
                                    ->where('match_id',$item->match_id)
                                    ->where('player_id',$item->pid)
                                    ->where('playing11','true')
                                    ->first();

                        $playing11_b = \DB::table('team_b_squads')
                                    ->where('match_id',$item->match_id)
                                    ->where('playing11','true')
                                    ->where('player_id',$item->pid)
                                    ->first();
                        $role_cat = ['wkcap','cap','squad'];
                       
                        $item->role = $player_match_id[$item->pid]??$item->role;            
                                    
                        if($playing11_a){
                          //  $item->role = $playing11_a->role;
                            $item->playing11 = 'true';
                            $p11=1;
                        }
                        elseif($playing11_b) {

                          // $item->role = $playing11_b->role; 
                           $item->playing11 = 'true';
                           $p11=1;
                        }else{
                           $item->playing11 = false; 
                        }
                        return $item;
                    });

            foreach ($mpObject as $key => $result) {

                $point = $result->point;
                if($captain==$result->pid){
                    $point = 2*$result->point;
                    $cname = true;
                }
                elseif($vice_captain==$result->pid){
                    $point = (1.5)*$result->point;
                    $vcname =true;
                }
               /* elseif($trump==$result->pid){
                    $point = 3*$result->point;
                    $tname = true;
                }*/

                $array_sum[] = $point;

                if($result->role=='wkbat'){
                    $result->role = "wk";
                }
                if($result->playing_role=='wkcap'){
                    $result->playing_role = "wk";
                }
                if($result->playing_role=='cap'){
                    $result->playing_role = "bat";
                }

                $name = explode(" ",trim($result->name));
                if(count($name)>3){
                    $fname = $name[0][0]??'';
                    $mname = $name[1][0]??''; 
                    $lname = $name[2][0]??''.' '.$name[3]??'';
                }
                elseif(count($name)>2){
                    $fname = $name[0][0]??'';
                    $mname = $name[1][0]??''; 
                    $lname = $name[2]??'';
                }elseif(count($name)==2){
                    $fname = $name[0][0]??'';
                    $mname = $name[1]??''; 
                    $lname = '';
                }else{
                    $fname = $result->name??'';
                    $mname = ''; 
                    $lname = '';
                }
                $name = trim($fname.' '.$mname.' '.$lname);
                //$short_name??$result->name

                $ur = $this->getPlayerPic($result->pid);
                if($ur){
                    $player_image = env('api_base_url')."/player/".$ur;  
                }  
                
                $data[] = [
                    'player_image' => $player_image??null,
                    'pid'       => $result->pid,
                    'team_id'   => $player_team_id[$result->pid]??null,
                    'name'      => $name,
                    'short_name'=> $name,
                    'points'    => (float)$point,
                    'fantasy_player_rating'    => (float)$result->rating,
                    'role'      => ($result->role=='cap')?'bat':$result->role,
                    'captain'   =>  ($captain==$result->pid)?true:false,
                    'vice_captain'   => ($vice_captain==$result->pid)?true:false,
                  //  'trump'     => ($trump==$result->pid)?true:false,
                    'playing11' => $result->playing11??false
                ];
            }
            $array_sum = $array_sum??[];
            $total_points = array_sum($array_sum);
        }
        $data_set = [];
        foreach ($data as $key => $result) {
            $pid[$result['pid']][] = $result['pid'];

            if(count($pid[$result['pid']]) >1){
                continue;
            }else{
                $data_set[] = $result;
            }
        }

        return [
            'status'=>true,
            'code' => 200,
            "match_id" => $team_id->match_id,
            'message' => 'points update',
            'total_points' => $total_points,
            'response' => [
                'player_points' => $data_set
            ]
        ];
    }

    public function getPlayerPoints(Request $request){

            $match_point_result = null;
            $contests = \DB::table('create_contests')
            ->where('match_id',$request->match_id)
            ->where('is_cancelled',0)
            ->get();
            // get contest based on contest
            $contests->transform(function($item,$key)use($match_point_result){
                $jc = \DB::table('join_contests')
                    ->where('match_id',$item->match_id)
                    ->where('contest_id',$item->id)
                    ->get() // get team based on join contest
                    ->transform(function($item,$key)use($match_point_result){
                        $ct = CreateTeam::where('id',$item->created_team_id)
                            ->where('match_id',$item->match_id)
                            ->first();

                        $contest_id = $item->contest_id;    
                        try{
                            $teams  = json_decode($ct->teams);

                            $mp     = MatchPoint::where('match_id',$item->match_id)
                                ->get();

                            $data['points'] = [];    
                            foreach ($mp as $key => $result) {
                                if(in_array($result->pid, $teams))
                                {
                                    $pt = $result->point;
                                    if($ct->captain==$result->pid){
                                        $pt = 2*$result->point;
                                    }
                                    if($ct->vice_captain==$result->pid){
                                        $pt = (1.5)*$result->point;
                                    }
                                    /*if($ct->trump==$result->pid){
                                        $pt = 3*$result->point;
                                    }*/
                                    $data['points'][] = $pt;
                                }
                            }
                            $total_points = array_sum($data['points']);
                            $match_id = $item->match_id;
                            $join_contest_id = $item->id;
                            $user_id = $item->user_id;
                            $ct->points = $total_points;
                            $ct->save();

                            $jc_object = JoinContest::find($join_contest_id);
                            $jc_object->points = $total_points;
                            $jc_object->save();
                         }catch(\Exception $e){

                            return false;  
                        }    
                    });
            });
        return [
            'status'=>true,
            'code' => 200,
            'message' => 'points update'

        ];

    }

    public function updatePoints(Request $request){

         

        if($request->match_id==86325){
            return false;
        }
        
        if($request->match_id){
            if($request->status==3){
                $matches = Matches::where('status',3)
                        ->where('match_id',$request->match_id)
                        ->where('is_flash_back',1)
                        ->get();
            }else{
                $matches = Matches::where('match_id',$request->match_id)
                ->where('is_flash_back',1)
                ->get();
            }
            
        }else{
           $matches = Matches::where('status',3)
                    ->where('is_flash_back',1)
                    ->get();
        }
         
        $m = [];
        if($matches->count()==0){
            die('No match available');
        }
        try{
            foreach ($matches as $key => $match) {   # code...
            
          /*  $cid_in = [120613,120383,120467,120686];
            if(in_array($match->competition_id,$cid_in))
            {                
                $points = file_get_contents($this->cric_url."matches/".$match->match_id."/newpoint2?token=".$this->token);
            }else{
                $points = file_get_contents($this->cric_url."matches/".$match->match_id."/point?token=".$this->token);   
            }*/
            $points = file_get_contents($this->cric_url."matches/".$match->match_id."/newpoint2?token=".$this->token);

            $points_json = json_decode($points);

            $t1 = $match->manual_date??$match->timestamp_start;
            $t2 = time();
            $td = round((($t1 - $t2)/60),2);
            foreach ($points_json->response->points as $team => $teams) {
              
                if($teams==""){
                    continue;
                }
                \DB::beginTransaction();   
                foreach ($teams as $key => $players) {
                    foreach ($players as $key => $result) {
                        $result->match_id = $match->match_id;
                        $result->pid = $result->pid;
                        if($result->pid==null){
                            continue;
                        }
                        foreach ($result as $key => $value) {
                            
                            if($key=='pid'){
                                $result->pid = $value;
                            }
                            elseif($key=='name'){
                                $result->name = $value;
                            }
                            elseif($key=='role'){
                                $result->role = $value;
                            }
                            elseif($key=='match_id'){
                                $result->match_id = $value;
                            }
                            elseif($key=='rating'){
                                $result->rating = $value;
                            }else{
                                $result->$key = $value;
                            }
                        }
                        
                        if($match->match_id==52680 && $result->pid==1745)
                        {
                           $result->point = $result->point-4; 
                        } 
                        
                        $m[$result->role][] = [
                            'point'=> $result->point
                        ];  
                        MatchPoint::updateOrCreate(
                            ['match_id'=>$match->match_id,'pid'=>$result->pid],
                            (array)$result);

                    }
                }
                \DB::commit();
            }
            $request->merge(['match_id' =>  $match->match_id]);

            if($td<0){
                $this->updateUserMatchPoints($request);   
            }
            
            if(isset($points_json->response)){
                $match_obj = Matches::firstOrNew(
                    [
                        'match_id' => $match->match_id
                    ] 
                );
                $match_obj->status = $points_json->response->status;
                $match_obj->status_str = $points_json->response->status_str;
                $match_obj->status_note = $points_json->response->status_note;
                $match_obj->result = $points_json->response->result;
                $match_obj->save();
            }

            /*TEAM A*/
            $team_a = TeamA::firstOrNew(['match_id' => $match->match_id]);
            $team_a->match_id   = $match->match_id;

            if(isset($points_json->response->teama)){
                foreach ($points_json->response->teama as $key => $value) {
                    $team_a->$key = $value;
                }
            }
            $team_a->save(); 

            /*TEAM B*/
              /*TEAM A*/
            $team_b = TeamB::firstOrNew(['match_id' => $match->match_id]);
            $team_b->match_id   = $match->match_id;

            if(isset($points_json->response->teamb)){
                foreach ($points_json->response->teamb as $key => $value) {
                    $team_b->$key = $value;
                }
            }
            $team_b->save(); 
        }
        }catch(\Exception $e){

        } 
        
        if($request->user_id==285 && $m){
          //  $this->updateUserMatchPoints($request);
            return $m;
        }else{
            return 'points updated';
        } 
    }


    public function getProfitLoss(Request $request){

        $match_id   = $request->match_id;
        $contest_id = $request->contest_id;

        $main_user =


        $ninja_user  = User::where('customer_type',3)
                            ->pluck('id')
                            ->toArray();

        $jc_user = JoinContest::where('match_id',$match_id)
                            ->where('contest_id',$contest_id);
                            
    }

    public function getPlaying11Team($match_id=null,$update=null){

        $mat = Matches::where('match_id',$match_id)
                        ->where('is_flash_back',1)
                        ->first();
         if($mat && $mat->is_flash_back==2){
            return false;
         }               


        $playing11a  =\DB::table('team_a_squads')
                ->where('match_id',$match_id)
                ->where('playing11','true')
                ->pluck('role','player_id')->toArray();
        $playing11b  =\DB::table('team_b_squads')
                ->where('match_id',$match_id)
                ->where('playing11','true')
                ->pluck('role','player_id')->toArray();
        $a = array_merge($playing11a,$playing11b);


        $playing11a1  =\DB::table('team_a_squads')
                ->where('match_id',$match_id)
                ->where('playing11','true')
                ->pluck('player_id')->toArray();
        $playing11b1  =\DB::table('team_b_squads')
                ->where('match_id',$match_id)
                ->where('playing11','true')
                ->pluck('player_id')->toArray();
        $b = array_merge($playing11a1,$playing11b1);

        $final_playing11 = array_combine($b, $a);

        if($update){
            return $final_playing11;
        }

        if($final_playing11){
        //  \DB::beginTransaction();
         //   \DB::transaction(function () use($final_playing11,$match_id) {

                foreach ($final_playing11 as $key => $value) {
                $mid =  Matches::where('match_id',$match_id)
                        ->where('is_flash_back',1)
                        ->first();
                if($mid){
                    $play11 = 'true';   
                }else{
                    $play11 = 'false';
                }           
                \DB::table('players')->where('match_id',$match_id)
                            ->where('pid',$key)
                            ->update([
                                'playing11' => $play11
                            ]);
                }

       //     });
          //  \DB::commit();
        }

        return $final_playing11;
    }

    public function setMatchStatusTime($match_id=null){
        
        if(Cache::get('time_'.$match_id))
        {
            return Cache::get('time_'.$match_id);
        }

        $match = Matches::where('match_id',$match_id)->first();

        if($match){
            $arr['match_status'] = $match->status_str;
            $arr['match_time'] = $match->manual_date??$match->timestamp_start;
            Cache::put('time_'.$match_id,$arr,60);
            return $arr;
        }else{

            $arr['match_status'] = null;
            $arr['match_time'] = null;

            return $arr;

        }
    }

    public function getMatchDataFromApi()
    {
        //upcoming
        $upcoming =    file_get_contents($this->cric_url."matches?status=1&token=".$this->token);
        $this->storeMatchInfoAtMachine($upcoming,'upcoming/'.'upcoming.txt');
        
        \File::put(public_path('/upload/json/upcoming.txt'),$upcoming);

        //complted
        $completed =    file_get_contents($this->cric_url."matches?status=2&token=".$this->token);

        $this->storeMatchInfoAtMachine($completed,'completed/'.'completed.txt');
        \File::put(public_path('/upload/json/completed.txt'),$completed);

        //live
        $live =    file_get_contents($this->cric_url."matches?status=3&token=".$this->token);

        $this->storeMatchInfoAtMachine($live,'live/'.'live.txt');
        \File::put(public_path('/upload/json/live.txt'),$live);

        return ['file updated'];
    }

    public function removePlaying11($match_id=null, $is_playing=null){

            \DB::table('players')
                ->where('match_id',$match_id)
                ->update(
                    [
                        'playing11' => "false" 
                    ]
                );
        
            $setPlaying = $is_playing;
         # code...
            $token  =  $this->token;
            $path   = $this->cric_url."matches/".$match_id."/squads?token=".$token;
            $data   = $this->getJsonFromLocal($path);
            // update team a players
            $teama = $data->response->teama;
            foreach ($teama->squads as $key => $squads) {
                $teama_obj = TeamASquad::firstOrNew(
                    [
                        'team_id'=>$teama->team_id,
                        'player_id'=>$squads->player_id,
                        'match_id'=>$match_id
                    ]
                );

                $teama_obj->team_id   =  $teama->team_id;
                $teama_obj->player_id =  $squads->player_id;
                $teama_obj->role      =  $squads->role;
                $teama_obj->role_str  =  $squads->role_str;
                $teama_obj->playing11 =  $setPlaying??$squads->playing11;
                $teama_obj->name      =  $squads->name;
                $teama_obj->match_id  =  $match_id;
                $teama_obj->save();
                $team_id[$squads->player_id] = $teama->team_id;
            }

            $teamb = $data->response->teamb;
            foreach ($teamb->squads as $key => $squads) {

                $teamb_obj = TeamBSquad::firstOrNew(['team_id'=>$teamb->team_id,'player_id'=>$squads->player_id,'match_id'=>$match_id]);

                $teamb_obj->team_id   =  $teamb->team_id;
                $teamb_obj->player_id =  $squads->player_id;
                $teamb_obj->role      =  $squads->role;
                $teamb_obj->role_str  =  $squads->role_str;
                $teamb_obj->playing11 =  $setPlaying??$squads->playing11;
                $teamb_obj->name      =  $squads->name;
                $teamb_obj->match_id  =  $match_id;
                $teamb_obj->save();

                $team_id[$squads->player_id] = $teamb->team_id;
            }
    }

    //access from admin
    public function saveMatchDataByMatchId($match_id=null,Request $request)
    {   
       $matches = Matches::firstOrNew(
                [
                    'match_id' => $match_id
                ]
            );
         
        //upcoming
        $data =  file_get_contents($this->cric_url."matches/".$match_id."/info?token=".$this->token);

        $json = json_decode($data);
        $title = $json->response->title??null;

        if($request->Playing11){
            $this->removePlaying11($match_id, null);
            return "<p style='padding:10px' class='alert alert-success'> Playing11 announced! <p>";

        }else{
            $this->saveMatchDataFromFlashMatch($json); 
            $this->removePlaying11($match_id, "false");
            $matches = Matches::firstOrNew(
                [
                    'match_id' => $match_id
                ]
            );
            $matches->status =1;
            $matches->status_str = 'upcoming';
            $matches->is_flash_back = 2;
            $matches->save();
        }

        return "<p style='padding:10px' class='alert alert-success'> Match $title saved successfully<p>";
    }

    public function updateMatchDataById($match_id=null)
    {
        $endpoint = $this->cric_url."matches/".$match_id."/info?token=".$this->token;
        //$response = Curl::to($endpoint)->get();
        $data =    file_get_contents($endpoint);
        
        $this->saveMatchDataFromAPI2DB($data);
        $this->saveMatchDataById($data);

        return [$match_id.' : match id updated successfully'];
    }

    public function updateMatchStatus(Request $request)
    {   $match_id = $request->match_id;
        $matches = Matches::where('status',1)
                        ->whereDate('date_start',\Carbon\Carbon::today())
                        ->get(); 
                    
        foreach ($matches as $key => $result) {
            $match_id = $result->match_id;
            $data =    file_get_contents($this->cric_url."matches/".$match_id."/info?token=".$this->token);

            $match = json_decode($data);
            if(isset($match->response)){
                \DB::table('matches')->where('match_id',$match->response->match_id)
                        ->update(
                            [
                                'status'=>$match->response->status,
                                'status_str'=>$match->response->status_str
                            ]
                        );
            }
        } 
        return ['match id updated successfully'];
    }

    public function updateMatchInfo(Request $request)
    {
        //upcoming 
        $match_id = $request->match_id;
        if($match_id){
           $matches =  Matches::where('match_id',$match_id)
            ->get(); 
        }else{
            $matches =  Matches::where('status',3)
            ->where('timestamp_start','>=',strtotime("-1 days"))
            ->where('timestamp_start','<=',time())
            ->get();
        }
        
        foreach ($matches as $key => $match) {

            $data =    file_get_contents($this->cric_url."matches/".$match->match_id."/info?token=".$this->token);
                $this->saveMatchDataFromAPI2DB($data);
        }

        return [$matches->count().' Match is updated successfully'];
    }

    public function updateLiveMatchFromApp()
    {
        //upcoming
        $match = Matches::where('status',3)->get();
        foreach ($match as $key => $result) {

            $data =    file_get_contents($this->cric_url."matches/".$result->match_id."/info?token=".$this->token);

            $this->saveMatchDataById($data);
        }
        return [' Live match  updated successfully'];
    }

    public function updateMatchDataByStatus($status=1, Request $request)
    {   
        if( $request->allow=='ninja11'){

        }else{
            die('Your IP is nanned');    
        }
 
        $date    =  date('Y-m-d');
        $url     =  $this->cric_url."matches?status=$status&token=$this->token&per_page=30";
        $data    =  $this->cricketAPICall($url);
        $object  =  json_decode(json_encode($data)); 
        $this->saveMatchDataFromAPI($object);
        return ['match data updated successfully'];
    }

    public function updateMatchDataByMatchId($match_id=null,$status=1)
    {
        if($status==1){
            $fileName="upcoming";
        }
        elseif($status==2){
            $fileName="completed";
        }
        elseif($status==3){
            $fileName="live";
        }elseif($status==4){
            $fileName="cancelled";
        }
        else{
            return ['data not available'];
        }
        //upcoming
        $data =    file_get_contents($this->cric_url."matches/".$match_id."/info?token=".$this->token);

        $this->storeMatchInfoAtMachine($data,'info/'.$match_id.'.txt');
        
        $json = json_decode($data); 
        $datas['status']    = $json->status;
        $arr['items'][]     = $json->response;
        $datas['response']  = $arr;

        $json_data = json_encode($datas); 

        \File::put(public_path('/upload/json/'.$fileName.'.txt'),$json_data);

        $data = $this->storeMatchInfo($fileName);

         $this->saveMatchDataFromAPI($data);

        return [$match_id.' match data updated successfully'];
    }

    public function saveMatchDataFromAPI2DB($data){
        $data = json_decode($data);

        if(isset($data->response)){
            $result_set = $data->response;
            $mid = [];
            //  foreach ($results as $key => $result_set) {

            foreach ($result_set as $key => $rs) {
                $data_set[$key] = $rs;
            }
            $competition = Competition::firstOrNew(['match_id' => $data_set['match_id']]);
            $competition->match_id   = $data_set['match_id'];

            foreach ($data_set['competition'] as $key => $value) {
                $competition->$key = $value;
            }
            $competition->save();
            $competition_id = $competition->cid;

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


            $matches = Matches::firstOrNew(
                [
                    'match_id' => $data_set['match_id']
                ]
            );
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
            $matches->competition_id = $competition_id;

            $matches->save();

            $mid[] = $data_set['match_id'];
            $m_cid[$matches->match_id] = $competition_id;
            $this->createContest($data_set['match_id']);
         
            if(count($mid)){
                $this->saveSquad($mid,$m_cid);
                //$this->getSquad($mid);   
               // $this->saveSquad($mid,$m_cid);
            }
        }
        return [$mid,"match info updated "];
    }

    public function saveMatchDataFromAPI($data){
        
        if(isset($data->response->items) && count($data->response->items)){

            $results = $data->response->items;
            $mid = [];
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
                $competition_id = $competition->cid;
                $league_title   = $competition->title;
                /*TEAM A*/
                $team_a = TeamA::firstOrNew(['match_id' => $data_set['match_id']]);
                $team_a->match_id   = $data_set['match_id'];

                foreach ($data_set['teama'] as $key => $value) {
                    $team_a->$key = $value;
                }

                $team_a->save();

                $p1 = Player::where('match_id',$team_a->match_id)
                         ->where('team_id',$team_a->team_id)
                         ->whereNull('team_name')
                         ->get();
                
                if($p1){
                    Player::where('match_id',$team_a->match_id)
                         ->where('team_id',$team_a->team_id)
                         ->update(['team_name'=>$team_a->short_name]);
                }

                $team_a_id = $team_a->id;
                /*TEAM B*/
                $team_b = TeamB::firstOrNew(['match_id' => $data_set['match_id']]);
                $team_b->match_id   = $data_set['match_id'];

                foreach ($data_set['teamb'] as $key => $value) {
                    $team_b->$key = $value;
                }

                $team_b->save();
                
                $p2 = Player::where('match_id',$team_b->match_id)
                         ->where('team_id',$team_b->team_id)
                         ->whereNull('team_name')
                         ->get();
                
                if($p2 ){
                    Player::where('match_id',$team_b->match_id)
                         ->where('team_id',$team_b->team_id)
                         ->update(['team_name'=>$team_b->short_name]);
                }

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
                $matches = Matches::firstOrNew(
                    [
                        'match_id' => $data_set['match_id']
                    ]
                );                
                if(isset($matches->is_cancelled) && $matches->is_cancelled){
                    continue;
                }

                foreach ($data_set as $key => $value) {

                    if(in_array($key, $remove_data)){
                        continue;
                    }
                    $matches->$key = $value; 
                    if($key=='status_str' && $value=='Scheduled')
                    {  
                        $matches->status_str = 'Upcoming';
                    }
                    if($key=='timestamp_start')
                    {  
                        $matches->timestamp_start = strtotime('+3 minutes', $value);
                    }
                }
                $matches->toss_id           = $toss_id;
                $matches->venue_id          = $venue_id;
                $matches->teama_id          = $team_a_id;
                $matches->teamb_id          = $team_b_id;
                $matches->competition_id    = $competition_id; 
                $matches->league_title      = $league_title; 
                
                 
                $matches->save();
                if($matches->manual_date && $matches->manual_date > $matches->timestamp_start){
                    $matches->timestamp_start = $matches->manual_date;
                    $matches->save();
                }
 

                $request =  new Request;
                $request->merge(['match_id' => $matches->match_id]);

                $this->playerPoints($request);

                $mid[] = $data_set['match_id'];
                $m_cid[$matches->match_id] = $competition_id;

                if($matches->status==1){
                    $this->createContest($data_set['match_id']);   
                }
                if($matches->status==4){
                    $cancelContest_id = JoinContest::where('match_id',$matches->match_id)
                    ->where('cancel_contest',0)
                    ->pluck('contest_id')
                    ->toArray();

                    if(count($cancelContest_id)){
                        $request = new Request;
                        $request->merge(['cancel_contest'=>$cancelContest_id]);
                        $request->merge(['match_id'=>$matches->match_id]); 
                    }
                }
            }
            //save SQuad
            if(count($mid)){ 
                $this->saveSquad($mid,$m_cid);
                // $this->getSquad($mid);
                //$this->saveSquad($mid,$m_cid);
            }
        }
        //
        return ["match info updated "];
    }
       

    public function updateSquad($match_id=null){

        # code...
        $cid = Competition::where('match_id',$match_id)->first();

        $token =  $this->token;
        $path = $this->cric_url."competitions/".$cid->cid."/squads/".$match_id."?token=".$this->token;

        $data = $this->getJsonFromLocal($path);

        foreach ($data->response->squads as $key => $pvalue) {
            if(!isset($pvalue->players)){
                continue;
            }

            foreach ($pvalue->players as $key2 => $results) {


                $data_set =   Player::firstOrNew(
                    [
                        'pid'=>$results->pid,
                        'team_id'=>$pvalue->team_id,
                        'match_id'=>$match_id
                    ]
                );


                foreach ($results as $key => $value) {
                    if($key=="primary_team"){
                        continue;
                        $data_set->$key = json_encode($value);
                    }
                    $data_set->$key  =  $value;
                    $data_set->match_id  =  $match_id;
                    $data_set->team_id = $pvalue->team_id;
                }
                $data_set->save();
            }
        }

        echo "played saved";
        //return ['saved'];
    }


    public function blockAccess(Request $request){
        $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');
        if($request->allow ==='ninja11'){
            
        }
        elseif(!$okhttp){
            echo  json_encode(array(
                    'status' => false,
                    'code' => 201,
                    'message' => 'unauthorise access!'
                ));
            
            exit();
        }
    }

    // get Match by status and all
    public function getCommonMatchCron(Request $request){        
         
        if(Cache::get('match_data_cron')){
           // return 'true';
        } 
        $match = Matches::whereHas('player')->with('teama','teamb')
            ->whereIn('status',[1,3])
            ->select('match_id','status','timestamp_start','timestamp_end','date_start','date_end','is_free','competition_id','manual_date','is_dashboard','is_flash_back')
            ->orderBy('order_by','DESC')
            ->orderBy('timestamp_start','ASC') 
            ->where('timestamp_start','>=' , time())
            ->where('is_cancelled',0)
            ->limit(15)
            ->get()->transform(function($item,$key)use($request){
                    $request->merge(['match_id'=>$item->match_id]);
                    $match_id = $item->match_id; 
                    $item->is_dashboard  = ($item->is_dashboard==2)?true:false;   
                    if($item->manual_date==NULL){  
                        $this->getPlayerFromCache($request);
                        $this->getPlaying11Team($match_id);
                        $this->playerPoints($request);
                    }
                    $this->getAnalytics($match_id);    
                   /* if($item->is_flash_back==2){
                        $this->removePlaying11($match_id, "false");
                    }*/
                    $t1 = $item->manual_date??$item->timestamp_start;

                    $date_start = date('h:i A',$t1);
                    $item->date_start = $date_start;

                    $t2 = time();
                    $td = round((($t1 - $t2)/60),2);
                    
                    if($td<30 && $item->is_lineup==1){
                        $this->getSquadByMatch($match_id);    
                    }
                    return $item;
                      
            });
        Cache::put('match_data_cron', $match, 120);    
    }
     // get Match by status and all
    public function getCommonMatch(Request $request){        
         
        
         
        $match = Matches::whereHas('player')->with('teama','teamb')
            ->whereIn('status',[1,3])
            ->select('match_id','title','short_title','status','status_str','timestamp_start','timestamp_end','date_start','date_end','game_state','game_state_str','is_free','competition_id','format_str','format','manual_date','is_dashboard','show_new_design','is_lineup','dyanamic_message','is_flash_back','notification','league_title')
            ->orderBy('order_by','DESC')
            ->orderBy('timestamp_start','ASC') 
            ->where('timestamp_start','>=' , time())
            ->where('is_cancelled',0) 
            ->limit(15)
            ->get();
            
            $match->transform(function($item,$key)use($request){
                    $request->merge(['match_id'=>$item->match_id]);
                    $match_id = $item->match_id;

                    $this->automateCreateContest($request);
                    $this->getPlayerIntoDB($request);
                    if($item->status==3)
                    {
                        $this->addReverseKey();    
                    }

                    $league_title = ($item->league_title??''); 

                    if($item->is_free==0){
                        $item->has_free_contest  = false;
                        $item->dyanamic_message  = $item->dyanamic_message??'';
                    }else{
                        $item->has_free_contest  = true;
                        $item->dyanamic_message  = $item->dyanamic_message??'GiveAway';
                    }

                    $item->is_dashboard      = ($item->is_dashboard==2)?true:false;
                    $item->show_new_design   = ($item->show_new_design==2)?true:false;

                    $t1 = $item->manual_date??$item->timestamp_start;

                    $date_start = date('h:i A',$t1);
                    $item->date_start = $date_start;

                    $t2 = time();
                    $td = round((($t1 - $t2)/60),2);
                    
                    
                    if($td>1440){
                        $date_start = date('d M Y, h:i A',$t1);
                        $item->date_start = $date_start; 
                    }                                  
                
                    $item->time_left = ($td>0)?$td.'Min':'time up';    

                    if($td>(0.1)){
                        $item->status=1;
                        $item->status_str='Upcoming';
                    }
                    if($td>1 and $td<=30){
                        $item->status=1;
                        $item->status_str='Upcoming'; 
        
                    }else{
                    } 
                    $item->notification = $item->notification;
                    $item->is_lineup = ($item->is_lineup==2)?true:false;
                    
                    $item->league_title = ucfirst($league_title);
                    return $item;
            });
       // Cache::put('match_data', $match, 90);
        Redis::set('match_data', $match); 

        $banner = \DB::table('banners')->select('title','url','actiontype','description')->get();

        Cache::put('banners', $banner, 1000); 

    }
    
    
    public function valideToken($request)
    {   
        $user = User::find($request->user_id);
        try{
            $token = Crypt::decryptString($request->system_token);
            if($token != $user->user_name)
            {
                return response()->json(
                    [
                        "status"=>false,
                        "code"=>1001,
                        "message" => "Session expired,login again to continue!"
                    ]
                );
            }else{
                return false;
            }

        }catch(DecryptException  $e){
            return response()->json(
                [
                    "status"=>false,
                    "code"=>1001,
                    "message" => "Session expired,login again to continue!"
                ]
            );
        }
 
    }
    
    // update player by match_id
    public function getSquad($match_ids=null){

        foreach ($match_ids as $key => $match_id) {
            # code... 
            $token =  $this->token;
            $path = $this->cric_url."matches/".$match_id."/squads?token=".$token;  
            $data = $this->getJsonFromLocal($path);
            
           // update team a players
            $teama = $data->response->teama;

            $playrs = $data->response->players??null;

         /*  foreach( $playrs as $key => $plyr)
            {
                \DB::table('players')->where('match_id',$match_id)
                            ->where('pid',$plyr->pid)
                            ->update([
                                'playing_role' => $plyr->playing_role
                            ]);
            }
        */
            
            foreach ($teama->squads as $key => $squads) {
                $teama_obj = TeamASquad::firstOrNew(
                    [
                        'team_id'=>$teama->team_id,
                        'player_id'=>$squads->player_id,
                        'match_id'=>$match_id
                    ]
                );
                if($squads->role=="squad"){
                    $p11_team[$squads->player_id] = $squads->role;    
                }
                
                $teama_obj->team_id   =  $teama->team_id;
                $teama_obj->player_id =  $squads->player_id;
                $teama_obj->role      =  $squads->role;
                $teama_obj->role_str  =  $squads->role_str;
                $teama_obj->playing11 =  $squads->playing11;
                $teama_obj->name      =  $squads->name;
                $teama_obj->match_id  =  $match_id;
                $teama_obj->save();
            }

            $teamb = $data->response->teamb;

            foreach ($teamb->squads as $key => $squads) {

                $teamb_obj = TeamBSquad::firstOrNew(['team_id'=>$teamb->team_id,'player_id'=>$squads->player_id,'match_id'=>$match_id]);

                $teamb_obj->team_id   =  $teamb->team_id;
                $teamb_obj->player_id =  $squads->player_id;
                $teamb_obj->role      =  $squads->role;
                $teamb_obj->role_str  =  $squads->role_str;
                $teamb_obj->playing11 =  $squads->playing11;
                $teamb_obj->name      =  $squads->name;
                $teamb_obj->match_id  =  $match_id;
                $teamb_obj->save();
               
            }
            
        }
    }

    public function getSquadByMatch($match_id=null){
        $token =  $this->token;
        $path = $this->cric_url."matches/".$match_id."/squads?token=".$token;  
        $data = $this->getJsonFromLocal($path);

        $match = Matches::where('match_id',$match_id)
                ->where('is_flash_back',1)
                ->first();

        if(!$match){
            return false;
        }

        $p11a = TeamASquad::where(
                    [
                        'match_id'=>$match_id
                    ]
                )->where('playing11','true')->count();

        $p11b = TeamBSquad::where(
            [
                'match_id'=>$match_id
            ]
        )->where('playing11','true')->count();

        if($p11a && $p11b){
            $sqd = "Squad found";
            if($match->status==1 && $match_id){
                $match_obj = Matches::firstOrNew(
                    [
                        'match_id'=>$match_id
                    ]
                );
                if($match_obj->status==3){
                    return true;   
                }
                $match_obj->status      =  3;
                $match_obj->is_lineup   =  2;
                $match_obj->order_by    =  2;
                $match_obj->save();
                return ['true'];
            }
           // return ['true'];
        } 
           // update team a players
            $teama = $data->response->teama; 

         //   \DB::beginTransaction();
                 // do all your updates here
                    foreach ($teama->squads as $key => $squads) {
                        \DB::table('players')
                           ->where([
                                'team_id'   =>  $teama->team_id,
                                'pid'       =>  $squads->player_id,
                                'match_id'  =>  $match_id
                            ])
                           ->update(
                                [
                                    'playing11' => $squads->playing11 
                                ]
                        );
                        
                    }
                    $teamb = $data->response->teamb;

                    foreach ($teamb->squads as $key => $squads) {
                        \DB::table('players')
                           ->where([
                                'team_id'   =>  $teamb->team_id,
                                'pid'       =>  $squads->player_id,
                                'match_id'  =>  $match_id
                            ])
                           ->update(
                                [
                                    'playing11' => $squads->playing11 
                                ]
                        );
                        
                    }
                // when done commit
          //  \DB::commit();

            foreach ($teama->squads as $key => $squads) {

                $teama_obj = TeamASquad::firstOrNew(
                    [
                        'team_id'=>$teama->team_id,
                        'player_id'=>$squads->player_id,
                        'match_id'=>$match_id
                    ]
                );
                if($squads->role!="squad"){
                    $p11_team[$squads->player_id] = $squads->role;    
                }




                $teama_obj->team_id   =  $teama->team_id;
                $teama_obj->player_id =  $squads->player_id;
                $teama_obj->role      =  $squads->role;
                $teama_obj->role_str  =  $squads->role_str;
                $teama_obj->playing11 =  $squads->playing11;
                $teama_obj->name      =  $squads->name;
                $teama_obj->match_id  =  $match_id;

                $teama_obj->save();
            }

            $teamb = $data->response->teamb;
            foreach ($teamb->squads as $key => $squads) {

                $teamb_obj = TeamBSquad::firstOrNew(['team_id'=>$teamb->team_id,'player_id'=>$squads->player_id,'match_id'=>$match_id]);

                $teamb_obj->team_id   =  $teamb->team_id;
                $teamb_obj->player_id =  $squads->player_id;
                $teamb_obj->role      =  $squads->role;
                $teamb_obj->role_str  =  $squads->role_str;
                $teamb_obj->playing11 =  $squads->playing11;
                $teamb_obj->name      =  $squads->name;
                $teamb_obj->match_id  =  $match_id;
                $teamb_obj->save(); 
            }
            if(isset($sqd)){
                 return 'true';
            }else{
                return 'false';
            }
           
    }
    /*
    Save Squad
    */
    public function saveSquad($match_ids=null,$m_cid=null)
    {

        foreach ($match_ids as $key => $match_id) {
            $cid = $m_cid[$match_id]??Competition::where('match_id',$match_id)->first()->cid;
            $path   =   $this->cric_url."competitions/".$cid."/squads/".$match_id."?token=".$this->token;
 
            $data = $this->getJsonFromLocal($path);

            foreach ($data->response->squads as $key => $pvalue) {

                if(!isset($pvalue->players)){
                    continue;
                }
                foreach ($pvalue->players as $key2 => $results) {
                    //player Object
                    if(!isset($results->pid)){
                        continue;
                    }

                    $data_set =   Player::firstOrNew(
                        [
                            'pid'       =>  $results->pid,
                            'team_id'   =>  $pvalue->team_id,
                            'match_id'  =>  $match_id,
                            'cid'       =>  $cid
                        ]
                    );

                    // Match Object
                    $data_mp =  MatchPoint::firstOrNew(
                        [
                            'pid'=>$results->pid,
                            'match_id'=>$match_id
                        ]
                    ); 

                    $table_cname = \Schema::getColumnListing('players');

                    foreach ($results as $key => $value) {
                        if($key=="primary_team"){
                            continue;
                            $data_set->$key = json_encode($value);
                        }

                        if(!in_array($key, $table_cname)){
                            continue;
                        }

                        $data_set->$key         =   $value;
                        $data_set->match_id     =   $match_id;
                        $data_set->team_id      =   $pvalue->team_id;
                        $data_set->cid          =   $cid;
                        $data_set->team_name    =   $pvalue->team->abbr??'';
                        // match point
                        $data_mp->match_id  =   $match_id;
                        $data_mp->pid       =   $results->pid; 
                        $data_mp->role      =   $results->playing_role; 
                        $data_mp->name      =   $results->short_name; 
                        $data_mp->rating    =   $results->fantasy_player_rating;
                    }

                    //dd($data_set,$pvalue->team->abbr);
                    $data_set->save();
                    $data_mp->save();
                }
            }
        }
        return true;
    }

    public function getCompetitionByMatchId($match_id=null){
        $d['start_time'] = date('d-m-Y h:i:s A');
        $com = \DB::table('competitions')
            ->select('id','match_id','cid')
            ->where(function($query) use ($match_id){
                $query->where('match_id',$match_id);
            })->get()->toArray();

        $token = $this->token ;
        $players = [];

        foreach ($com as $key => $result) {

            $path = $this->cric_url."competitions/".$result->cid."/squads?token=".$token;

            $data = $this->getJsonFromLocal($path);

            if(isset($data->response->squads)){
                foreach ($data->response->squads as $key => $rs) {
                    if($rs->players){

                        foreach ($rs->players as $pkey => $pvalue) {

                            $data_set =   Player::firstOrNew(['pid'=>$pvalue->pid]);
                            foreach ($pvalue as $key => $value) {

                                if($key=="primary_team"){
                                    continue;
                                    $data_set->$key = json_encode($value);
                                }

                                $data_set->$key = $value;
                            }
                            $data_set->match_id = $result->match_id;
                            $data_set->cid = $result->cid;
                            if($rs->team_id){
                                $data_set->team_id = $rs->team_id;
                            }
                            $data_set->save();
                        }

                    }


                }

            }

        }
        $d['end_time'] = date('d-m-Y h:i:s A');
        $d['message'] ="Player information updated";
        $d['status'] ="ok";
        return  $d;
    }


    public function getLiveScore(Request $request){
        
        $match_id = $request->match_id??46851;
        
        $url = "http://rest.entitysport.com/v2/matches/".$match_id."/scorecard?token=".env('CRIC_API_KEY');
        
        $mt = \DB::table('live_scores')->where('match_id',$match_id)->first();
        
        if($mt){
            return  json_decode($mt->response,true);    
        }
        


        $curl = curl_init();
        curl_setopt_array($curl, array(
          CURLOPT_URL => $url,
          CURLOPT_RETURNTRANSFER => true,
          CURLOPT_ENCODING => "",
          CURLOPT_MAXREDIRS => 10,
          CURLOPT_TIMEOUT => 30,
          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
          CURLOPT_CUSTOMREQUEST => "GET",
          CURLOPT_HTTPHEADER => array(
            "cache-control: no-cache",
            "content-type: application/json"
          ),
        ));

        $response = curl_exec($curl);
        $err = curl_error($curl);

        curl_close($curl);

        if ($err) {
           
        } else {
            $data = json_decode($response);
            $rs = $data->response;
             
            \DB::table('live_scores')->updateOrInsert(
                ['match_id'=>$match_id],
                [
                    'match_id' => $match_id,
                    'response' => json_encode($rs)
                ]
            );
            
            return  (array)$rs; 
        }
    }
    

    public function cricketAPICall($url='')
    {
         $data = Http::get($url)->json();

         return $data;
    }

    public function ninjaApiCount($action=null,$token)
    {
        \DB::connection('mysql2')->table('ninja_api_count')->insert([
            'action' => $action,
            'token' => $token
        ]);
    }

    public function mainApiCount($action=null,$token)
    {
        \DB::connection('mysql2')->table('main_api_count')->insert([
            'action' => $action,
            'token' => $token
        ]);
    }

    /**
     * Method : iccranks
     * @URL: https://rest.krsdata.net/api/v2/iccranks?token=35f25eea6d55228ae473db28cd99695d 
     * Param : null
     */
    
     public function iccranks(Request $request)
     {
         $response =  \DB::connection('mysql2')->table('iccranks')
             ->select('response')
             ->where('action','iccranks')
             ->where('date_start', date('Y-m-d'))
             ->first();
         
         if($response==null)
             {  
                $this->mainApiCount('iccrank',$request->token);
                 $url     =  $this->cric_url."iccranks?token=$this->token";
                 $data =  file_get_contents($url);
                 
                 \DB::connection('mysql2')->table('iccranks')->updateOrInsert(
                     [
                        'action' => 'iccranks'
                     ],
                     [
         
                         'action' => 'iccranks',
                         'date_start' => date('Y-m-d'),
                         'response' => $data
             
                     ]
                 ); 
                 
                 return $data;
             }
             $this->ninjaApiCount('iccrank',$request->token);
             return json_decode($response->response,true);
         
     }
    /**
     * Method : Player stats
     * @URL: https://rest.krsdata.net/api/v2/players/119/stats?token=35f25eea6d55228ae473db28cd99695d 
     * Param : pid
     */
    
    public function playerStat(Request $request, $pid=null)
    {
        $response =  \DB::connection('mysql2')->table('player_stats')
            ->select('response')
            ->where('pid',$pid)
            ->where('action','playerStat')
            ->first();
        
        if($response==null)
            {   
                $this->mainApiCount('player_stats',$request->token);
                $url     =  $this->cric_url."players/$pid/stats?token=$this->token";
                $data =  file_get_contents($url);
                
                \DB::connection('mysql2')->table('player_stats')->updateOrInsert(
                    [
                        'pid' => $pid,
                        'action' => 'playerStat'
                    ],
                    [
        
                        'action' => 'playerStat',
                        'pid' => $pid,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => $data
            
                    ]
                ); 
                
                return $data;
            }
            $this->ninjaApiCount('player_stats',$request->token);
            return json_decode($response->response,true);
        
    }

    // data by cid and mtach_id
    // https://rest.krsdata.net/api/v2/competitions/125586/squads/56398?token=35f25eea6d55228ae473db28cd99695d
    public  function cidWithMatchId(Request $request, $cid=null,$match_id=null)
    {   
        $this->ninjaApiCount('squads',$request->token);
                
        $response =  \DB::connection('mysql2')->table('all_matches')
            ->select('response')
            ->where('competition_id',$cid)
            ->where('match_id',$match_id)
            ->where('action','saveSquad')
            ->first();

        switch ('saveSquad') {
            case 'saveSquad':

                if($response==null)
                {   
                    $this->mainApiCount('squads',$request->token);
                    $url     =  $this->cric_url."competitions/$cid/squads/$match_id?token=$this->token";
                    $data =  file_get_contents($url);
                   
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'competition_id' => $cid,
                            'match_id' => $match_id,
                            'action' => 'saveSquad'
                        ],
                        [
            
                            'action' => 'saveSquad',
                            'match_id' => $match_id,
                            'competition_id' => $cid,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }
                return json_decode($response->response,true);

                break;

            default:
                $res = [
                    'status' => 'error',
                    'response' => 'Invalid request',
                    'api_version' => '2.0'

                ];
                return $res;
                break;
        }


    }

    /* 
    @developby : Kroy
    Date : 26 dec 22
    Desc : match details by match_id
           info, point, squad
    @param : match_id, match_details
    @function : competitions data by cid
    */
    //https://rest.krsdata.net/api/v2/competitions/127174/matches?token=ja58dckohy1490d8a940253adfb3f611e0
    public  function competitionsData(Request $request, $cid=null,$details=null)
    {   
        $this->ninjaApiCount($details,$request->token);
                
        $response =  \DB::connection('mysql2')->table('all_matches')
            ->select('response')
            ->where('competition_id',$cid)
            ->where('action',$details)
            ->first();

        switch ($details) {
            case 'teams':
                
                if($response==null)
                {   
                    $this->mainApiCount('teams',$request->token);
                    $url     =  $this->cric_url."competitions/$cid/info?token=$this->token";
                    $data =  file_get_contents($url);
                   
    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'competition_id' => $cid,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'competition_id' => $cid,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;

            case 'stats':
                
                if($response==null)
                {   
                    $this->mainApiCount('stats',$request->token);
                    $url     =  $this->cric_url."competitions/$cid/stats?token=$this->token";
                    $data =  file_get_contents($url);
                   
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'competition_id' => $cid,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'competition_id' => $cid,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;
            case 'squads':

                if($response==null)
                {   
                    $this->mainApiCount('squads',$request->token);
                    $url     =  $this->cric_url."competitions/$cid/squads?token=$this->token";
                    $data =  file_get_contents($url);
                   
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'competition_id' => $cid,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'competition_id' => $cid,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;

            case 'matches':

                if($response==null)
                {   
                    $this->mainApiCount('matches',$request->token);
                    $url     =  $this->cric_url."competitions/$cid/matches?token=$this->token";
                    $data =  file_get_contents($url);
                    
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'competition_id' => $cid,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'competition_id' => $cid,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;
            
            //
            case 'standings':

                if($response==null)
                {   
                    $this->mainApiCount('standings',$request->token);
                    $url     =  $this->cric_url."competitions/$cid/standings?token=$this->token";
                    $data =  file_get_contents($url);
                    
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'competition_id' => $cid,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'competition_id' => $cid,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;

                
            
            default:
            $res = [
                'status' => 'error',
                'response' => 'Invalid request',
                'api_version' => '2.0'

            ];
            return $res;
            break;
        }


    }

    public function getData(Request $request)
    {
        
        base64_encode(1);

        $d['server'] =  request()->getSchemeAndHttpHost();
        $d['ip']     =  $_SERVER['HTTP_X_FORWARDED_FOR'];
        $d['token']  =  bin2hex(openssl_random_pseudo_bytes('16'));
        $d['url']    =    apache_request_headers();  
        $d['url2']   =  gethostbyaddr($_SERVER['REMOTE_ADDR']);

      //  $url = $_SERVER['REQUEST_URI'];

        return $d;
        
    }

    /* 
    @developby : Kroy
    Date : 26 dec 22
    Desc : match details by match_id
           info, point, squad
    @param : match_id, match_details
    */
    public  function matchDetails(Request $request, $match_id=null,$details='info')
    {   
        if($match_id==86325)
        {
            return  [
                    "status"=> "forbidden",
                    "response"=> "You have no access to this match",
                    "api_version"=> "2.0.1"
                ];
        }
        
        sleep(1);
        $start_time = time();
        $this->ninjaApiCount($details,$request->token);
                
        $response =  \DB::connection('mysql2')->table('all_matches')
            ->select('response','start_time')
            ->where('match_id',$match_id)
            ->where('action',$details)
            ->first();

             $min24 = strtotime('-3 minutes', time());
                    
            // $matches = Matches::where('match_id',$match_id)
            //     ->where('is_lineup',2)
            //     ->whereIn('status',[3])
            //     ->where('timestamp_start','<=',$min24)
            //         ->first();
        
            if(isset($response->start_time) && $response->start_time > $min24)
            {
                //dd(1);   
                
            }else{
               // if($details=="newpoint2" || $details=="live" || $details="scorecard")
               // {
                  //  $old_response = $response;
                    $response = null;
              //  }
            }
           
           

        switch ($details) {
           // https://rest.krsdata.net/api/v2/matches/78442/info?token=n9cf8781ddff5a2db2aa2de0c53481bb9n 
            case 'info':
                
                if($response==null)
                {   
                    $this->mainApiCount('match_info',$request->token);
                    $url     =  $this->cric_url."matches/$match_id/info?token=$this->token";
                    $data =  file_get_contents($url);
                   
                  //  dd($data,$url);
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'match_id' => $match_id,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'match_id' => $match_id,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;
            //https://rest.krsdata.net/api/v2/matches/78442/newpoint2?token=n9cf8781ddff5a2db2aa2de0c53481bb9n    
            case 'newpoint2':
                
                if($response==null)
                {   
                    $this->mainApiCount('match_points',$request->token);
                    
                    $min24 = strtotime('-3 minutes', time());
                    
                    $matches = Matches::where('match_id',$match_id)
                        ->where('is_lineup',2)
                        ->whereIn('status',[3,2,4])
                       // ->where('timestamp_end','>=',$min24)
                            ->get(['date_start','match_id','timestamp_start','status','manual_date','timestamp_end']);

                        //    dd($matches);

                    if($matches->count() || !isset($old_response->response))
                    {

                    }else{
                       // return json_decode($old_response->response,true);
                        break;
                    }

                    $url     =  $this->cric_url."matches/$match_id/newpoint2?token=$this->token";
                    $data =  file_get_contents($url);
                   
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'match_id' => $match_id,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'match_id' => $match_id,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;
            case 'squads':
                //https://rest.krsdata.net/api/v2/matches/78442/squads?token=n9cf8781ddff5a2db2aa2de0c53481bb9n
                if($response==null)
                {   
                    $this->mainApiCount('squads',$request->token);
                    $url     =  $this->cric_url."matches/$match_id/squads?token=$this->token";
                    $data =  file_get_contents($url);
                    
                 //   dd($data);
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'match_id' => $match_id,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'match_id' => $match_id,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;
            case 'scorecard':
                   //https://rest.krsdata.net/api/v2/matches/78442/squads?token=n9cf8781ddff5a2db2aa2de0c53481bb9n
                    if($response==null)
                    {   
                        $url     =  $this->cric_url."matches/$match_id/scorecard?token=$this->token";
                        $data =  file_get_contents($url);
                       
                        
                        \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                            [
                                'match_id' => $match_id,
                                'action' => $details
                            ],
                            [
                
                                'action' => $details,
                                'match_id' => $match_id,
                                'date_start' => date('Y-m-d'),
                                'date_end' => date('Y-m-d'),
                                'response' => $data,
                                'start_time' => time()
                    
                            ]
                        ); 
                        
                        return $data;
                    }
    
                    return json_decode($response->response,true);
    
                    break;
            
            case 'statistics':

                        if($response==null)
                        {   
                            $this->mainApiCount('statistics',$request->token);
                            $url     =  $this->cric_url."matches/$match_id/statistics?token=$this->token";
                            $data =  file_get_contents($url);
                            
                            
                            \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                                [
                                    'match_id' => $match_id,
                                    'action' => $details
                                ],
                                [
                    
                                    'action' => $details,
                                    'match_id' => $match_id,
                                    'date_start' => date('Y-m-d'),
                                    'date_end' => date('Y-m-d'),
                                    'response' => $data,
                                    'start_time' => time()
                        
                                ]
                            ); 
                            
                            return $data;
                        }
        
                        return json_decode($response->response,true);
        
                        break;
            case 'live':

                if($response==null)
                {   
                    $this->mainApiCount('scorecard',$request->token);
                    $url     =  $this->cric_url."matches/$match_id/scorecard?token=$this->token";
                    $data =  file_get_contents($url);
                    
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'match_id' => $match_id,
                            'action' => $details
                        ],
                        [
            
                            'action' => $details,
                            'match_id' => $match_id,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $data,
                            'start_time' => time()
                
                        ]
                    ); 
                    
                    return $data;
                }

                return json_decode($response->response,true);

                break;

            
            default:
                $res = [
                    'status' => 'error',
                    'response' => 'Invalid request',
                    'api_version' => '2.0'

                ];
                return $res;
                break;
        }


    }

    //competitionsOverviewApi

    public  function competitionsApi(Request $request, $cid=null)
    {
        //dd('dsdsd');
        $status = $request->status??1;
        $token = $request->token??1;
        $date    =  date('Y-m-d');
        
        $key = \DB::connection('mysql2')->table('token_auth')->where('token',$token)->count();

        if($key==0 || ($status<0 || $status>=5))
        {
            return [

                'status' => 'unauthorized',
                'response' => 'API Token expired',
                'api_version' => "2.0"
            ];
        }else{

           $response =  \DB::connection('mysql2')->table('all_matches')
                            ->select('response')
                            ->where('action','competitionsApi')
                            ->first();


            if($response==null || $status>=1)
            {   
                $this->mainApiCount('matches_by_status_'.$status,$request->token);
                $url     =  $this->cric_url."competitions/$cid?token=$this->token";
                $data =  file_get_contents($url);
               

                \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                    [
                        'action' => 'competitionsApi',
                        'status' => $status
                    ],
                    [
        
                        'action' => 'competitionsApi',
                        'status' => $status,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => $data,
                        'start_time' => time()
            
                    ]
                );
        
                return $data;
            }

            return   json_decode($response->response,true);

        }

    }


    public function commentaryApi($match_id=null, $inning=2)
    {
        return response()->json([
            'match_id' => $match_id,
            'inning' => $inning,
            'message' => 'Commentary fetched successfully',
        ]);
    }
    //commentary
    //https://rest.krsdata.net/api/v2/matches/79168/innings/2/commentary

    public  function commentary2(Request $request, $match_id=null,$inning=1)
    {
        //dd('dsdsd');
        $status = $request->status??1;
        $token = $request->token??1;
        $date    =  date('Y-m-d');
        $details = "commentary";
        $key = \DB::connection('mysql2')->table('token_auth')->where('token',$token)->count();

        if($key==0 || ($status<0 || $status>=5))
        {
            return [

                'status' => 'unauthorized',
                'response' => 'API Token expired',
                'api_version' => "2.0"
            ];
        }else{

           $response =  \DB::connection('mysql2')->table('all_matches')
                            ->select('response')
                            ->where('action','commentary')
                            ->first();


            if($response==null || $status>=1)
            {   
                $this->mainApiCount('matches_by_status_'.$status,$request->token);
                $url     =  $this->cric_url."matches/$match_id/innings/$inning/commentary?token=$this->token";
                $data =  file_get_contents($url);
               

                \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                    [
                        'action' => 'commentary',
                        'status' => $status
                    ],
                    [
        
                        'action' => 'commentary',
                        'status' => $status,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => $data,
                        'start_time' => time()
            
                    ]
                );
        
                return $data;
            }

            return   json_decode($response->response,true);

        }

    }

   // https://rest.krsdata.net/api/v2/competitions?token=ja58dckohy1490d8a940253adfb3f611e0
    public  function competitions(Request $request)
    {
        //dd('dsdsd');
        $status = $request->status??1;
        $token = $request->token??1;
        $date    =  date('Y-m-d');
        
        $key = \DB::connection('mysql2')->table('token_auth')->where('token',$token)->count();

        if($key==0 || ($status<0 || $status>=5))
        {
            return [

                'status' => 'unauthorized',
                'response' => 'API Token expired',
                'api_version' => "2.0"
            ];
        }else{

           $response =  \DB::connection('mysql2')->table('all_matches')
                            ->select('response')
                            ->where('action','competitions')
                            ->first();


            if($response==null || $status>=1)
            {   
                $this->mainApiCount('matches_by_status_'.$status,$request->token);
                $url     =  $this->cric_url."competitions?token=$this->token&per_page=50";
                $data =  file_get_contents($url);
               

                \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                    [
                        'action' => 'competitions',
                        'status' => $status
                    ],
                    [
        
                        'action' => 'competitions',
                        'status' => $status,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => $data,
                        'start_time' => time()
            
                    ]
                );
        
                return $data;
            }

            return   json_decode($response->response,true);

        }

    }
    // get match info
    public  function matches(Request $request)
    {
        

        $status = $request->status??1;
        $token = $request->token??1;
        $date    =  date('Y-m-d');
        
        
        $key = \DB::connection('mysql2')->table('token_auth')->where('token',$token)->count();

        if($key==0 || ($status<0 || $status>=5))
        {
            return [

                'status' => 'unauthorized',
                'response' => 'API Token expired',
                'api_version' => "2.0"
            ];
        }else{

           $response =  \DB::connection('mysql2')->table('all_matches')
                            ->select('response')
                            ->where('action','getAllMatch')
                            ->where('status',$status)
                            ->first();


            $per_page= $request->per_page??16;
            if($request->pre_squad==true)
            {
                $per_page = $per_page."&pre_squad=true";
            }
            if($request->format)
            {
                $per_page = $per_page."&format=".$request->format;
            }
            if($request->date)
            {
                $per_page = $per_page."&date=".$request->date;
            }
            

            if($response==null || $status>=1)
            {   
                $this->mainApiCount('matches_by_status_'.$status,$request->token);
                $url     =  $this->cric_url."matches?status=$status&token=$this->token&per_page=$per_page";
                $data =  file_get_contents($url);
               

                \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                    [
                        'action' => 'getAllMatch',
                        'status' => $status
                    ],
                    [
        
                        'action' => 'getAllMatch',
                        'status' => $status,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => $data,
                        'start_time' => time()
            
                    ]
                );
        
                return $data;
            }

            return   json_decode($response->response,true);

        }

    }

    public function getJsonFromLocal($path=null)
    {
        return json_decode(file_get_contents($path));
    }

    // getPlaying11
    
    public  function getPlaying11(Request $request)
    {   
        $min24 = strtotime('+120 minutes', time());
        $matches = Matches::whereIn('status',[1,3,4])
                   ->where('is_lineup',1)
                   ->where('match_abondon',0)
                   ->where('timestamp_start','>=',time())
                   ->where('timestamp_start','<=',$min24)
                   ->get(['short_title','date_start','match_id','timestamp_start','status','manual_date']);
           
        

            
        foreach ($matches as $key => $match) {

            $match_id =  $match->match_id;
            $match = Matches::where('match_id',$match_id)->first();

            $t1 = $match->manual_date??$match->timestamp_start;
            $t2 = time();
            $td = round((($t1 - $t2)/60),2);   
            
            if($td > 45 || $td<3){
                continue;
            } 
            
            try{ 
                $this->mainApiCount('getplayig11',$request->token);
                $token =  $this->token;
                $path = $this->cric_url."matches/".$match_id."/squads?token=".$token;
                
                $data = $this->getJsonFromLocal($path);
                $playing =0;
                foreach($data->response as $key => $value){
                   // dd($value);
                    if($key=='teama')
                    {    
                        $collection = collect($value->squads);
                        $result = $collection->where('playing11', 'true');
                        if($result->count()){ 

                            $playing++;
                            continue;
                        }
                        
                    }
                    elseif($key=='teamb')
                    {    
                        $collection = collect($value->squads);
                        $result = $collection->where('playing11', 'true');
                        if($result->count()){ 
                            $playing++;
                            continue;
                        }
                        
                    }
                 
                }

                if($playing==2)
                {
                    $match->is_lineup=2;
                    $match->save();
                }

                \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                    [
                        'match_id' => $match_id,
                        'action' => 'squads'
                    ],
                    [
        
                        'action' => 'squads',
                        'match_id' => $match_id,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => json_encode($data),
                        'start_time' => time()
            
                    ]
                );           
            }catch(\ErrorException $e){
                
                continue;
            }
 
        }
    }

    // update Point

    public  function updatePointCron(Request $request)
    {   
        $min24 = strtotime('+60 minutes', time());
        $matches = Matches::whereIn('status',[1,3,4])
                   ->where('match_abondon',0)
                   ->where('timestamp_start','<=',time())
                   ->where('timestamp_end','>=',$min24)
                   ->get(['short_title','timestamp_end','match_id','timestamp_start','status','manual_date']);
            
                

        foreach ($matches as $key => $match) {

            $match_id =  $match->match_id;
            $match = Matches::where('match_id',$match_id)->first();

            $t1 = $match->manual_date??$match->timestamp_start;
            $t2 = time();
            $td = round((($t1 - $t2)/60),2);   
            
            
            try{ 
                $this->mainApiCount('update_points',$request->token);
                $token =  $this->token;
                $path = $this->cric_url."matches/".$match_id."/newpoint2?token=".$token;
                
                $data = $this->getJsonFromLocal($path);
                $playing =0;
                    
                    if(isset($data->response->status) && $data->response->status==4)
                    {    
                        $match->match_abondon=1;
                        $match->save(); 
                    }else{
                        $match->status=$data->response->status;
                        $match->save();    
                    }
                
                
                \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                    [
                        'match_id' => $match_id,
                        'action' => 'newpoint2'
                    ],
                    [
        
                        'action' => 'newpoint2',
                        'match_id' => $match_id,
                        'date_start' => date('Y-m-d'),
                        'date_end' => date('Y-m-d'),
                        'response' => json_encode($data),
                        'start_time' => time()
            
                    ]
                ); 

                
            }catch(\ErrorException $e){
              //  dd($e);
                continue;
            }
 
        }
    }

}
