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
use Config,Mail,View,Redirect,Validator,Response,DB;
use Crypt,okie,Hash,Lang,Input,Closure,URL, File, PaytmWallet;
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
use Razorpay\Api\Api; 
use paytm\checksum\PaytmChecksumLibrary; 
use Illuminate\Support\Facades\Cache;
use Jenssegers\Agent\Agent;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Kreait\Firebase\Auth as FirebaseAuth;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\RegistrationToken;
use Kreait\Firebase\Factory; 
use Firebase\Auth\Token\Exception\InvalidToken;
use Illuminate\Support\Facades\Http;
use Carbon\Carbon;
use App\Helpers\RedisCache as Redis;
use Appy\FcmHttpV1\FcmNotification;
use Google\Client as Google_Client;  
use Laravolt\Avatar\Facade as Avatar;
use thiagoalessio\TesseractOCR\TesseractOCR; 
use App\Services\PaymentTextParser;
use PhonePe\payments\v2\models\request\builders\StandardCheckoutPayRequestBuilder;
use PhonePe\payments\v2\standardCheckout\StandardCheckoutClient;
use PhonePe\Env;


class ApiController extends BaseController  
{
    public $token;
    public $token2;
    public $date;
    public $cric_url;
    public $is_session_expire;
    public $myid;
    public $cric_url2;
    public $uid_cached;

    public $token_url;

    public function __construct(Request $request) {

        //[1:55 PM] Kundan Roy

        $this->date     = date('Y-m-d');
        $this->token    = env('CRIC_API_KEY',"927192a7efcfb0e8d321a41412012af9");
        $this->token2   = env('CRIC_API_KEY2',"927192a7efcfb0e8d321a41412012af9");

        $request->headers->set('Accept', 'application/json');
        
               // $this->cric_url = 'http://rest.entitysport.com/v2/'; 
        $this->cric_url2 = 'https://myfinal11.in/wp-admin/admin-ajax.php?action=wpec_api_request&path=';


        $this->cric_url = "https://myfinal11.in/wp-admin/admin-ajax.php?action=wpec_api_request&path="; //matches/?status=1

        $data['user_id'] = $request->user_id??'API';
        $this->myid     =   $request->user_id;

        $data['url']  = $request->path();

        //?token=".$this->token2

        $this->token_url    =   "?token=".$this->token2;

       
       if( $request->user_id)
       {
            // \DB::table('request_urls')->insert([

            //     'url' => $request->getRequestUri(),
            //     'user_id' => $request->user_id

            // ]);
        }

       $client_id = "rPYuGfRvxK2410";
       $secret_id = "VqvzftjPp1231006051235";
        $this->validateUser($request);

    }

    //13/JAN/23

    public function cancelRailContest(Request $request)
    {

        //SELECT * FROM `join_contests` WHERE match_id=65201 and team_count='T1' and entry_fees=0 and contest_id IN(SELECT id FROM `create_contests` WHERE match_id=65201 and filled_spot=2);

        $match = Matches::where('status',3)
                ->whereDate('date_start',\Carbon\Carbon::today())
                ->where('timestamp_start','<',time())
                ->where('is_cancelled',0)
                ->get(); 
        
        foreach($match as $key2 => $result )
        {
            $contest_id = \DB::table('create_contests')
            ->select('id')
            ->where('match_id',$result->match_id)
            ->where('filled_spot',2)
            ->pluck('id');

        //  dd($contest_id);
                
            $jc = \DB::table('join_contests')
                //  ->select('contest_id','id')
                    ->where('match_id',$result->match_id)
                    ->where('team_count','T1')
                    ->where('entry_fees',0)
                    ->whereIn('contest_id', $contest_id)
                    ->get()
                    ->groupBy('contest_id');


            foreach($jc as $key => $value)
            {
                // CreateTeam::whereIn('id',$value->pluck('created_team_id'))->delete();
                if($value->count()==2)
                {
                    $cc = CreateContest::find($key);
                    $cc->is_cancelled = 1;
                    $cc->save();

                  //  $value->delete();
                }
            }
        }       
    }

    public function validateUser($request)
    {

      //  dd($request->all());
        $user_name = $request->user_id;

        $user_cache = Cache::get('user_'.$user_name);
        if($user_cache)
        {
           $user = $user_cache; 
        }else{
            $user = User::where('user_name',$user_name)->first();    
        }
        
        if($user && $request->user_id){
            $this->is_session_expire = false;
            $request->merge(['referal_code'=>$user->reference_code]);
            $request->merge(['reference_code'=>$user->referal_code]);
            $request->merge(['user_id'=>$user->id]); 
        }else{
            $this->is_session_expire = true;
            $request->merge(['user_id'=>null]);
        }
        if($user && $user->status=='0'){
            $this->is_session_expire = true;
            $request->merge(['user_id'=>null]);
        }
 
        //Cache::put('user_'.$user_name,$user,3600);
        
        Cache::rememberForever('user_' . $user_name, function () use ($user) {
            return $user;
        });
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

    /**
     * Obtain the payment information.
     * after payment calling url 
     * @return Object
     * date : 2025
     */ 
    public function paymentCallbackUrl(Request $request )
    {    
        sleep(1);
        \DB::table('paytm')->insert(
                    [
                        'paytm'=> json_encode($request->all()),
                        'action' => 'payout'
                    ]
                ); 
 
            $payload = $request->all(); 

            if (isset($payload[0])) {
                $res = json_decode($payload[0], true);
            } else {
                $res = json_decode($request->getContent(), true);
            }  

            

            $str = $res['data']['transaction_id'];   
            $tid = str_replace("payout", "", $str); 
            $firstPart = explode("_", $str)[0];   // payout285 
                // only numeric part from firstPart
            $uers_id = preg_replace('/\D/', '', $firstPart); // 285 
            
            $data = (object) $res;
           
                      
            if($data->data['status']=='success')
            {
                $exch_transaction_id = $data->data['exch_transaction_id'];
                
                \DB::table('payouts')->where('order_id',$exch_transaction_id)->delete();

                $walletT = WalletTransaction::where('transaction_id',$tid)->whereNull('order_id')->first();

                if($walletT!=null)
                {
                    $walletT->order_id = $res['data']['exch_transaction_id'];
                    $walletT->transaction_id = $res['data']['exch_transaction_id'];
                     $walletT->withdraw_status = 5;
                    $walletT->save(); 
                        
                    \DB::table('payouts_logs')->insert([

                        'transaction_id' => $res['data']['exch_transaction_id']??time(),
                        'order_id' => $res['data']['transaction_id']??'fail',
                        'amount' => $res['data']['amount']??0,
                        'status' => 2,
                        'message' => $res['message']??'fail',
                        'payout_type' => 'Bank',
                        'user_id' => $uers_id,  
                    ]);

                } 


            }
            else
            {    

                if($data->data['status']=='pending')
                {
                     $exch_transaction_id = $data->data['exch_transaction_id'];
                      $walletT = WalletTransaction::where('transaction_id',$tid)->whereNull('order_id')->first();

                        if($walletT!=null)
                        {
                            $walletT->order_id = $res['data']['exch_transaction_id'];
                            $walletT->transaction_id = $res['data']['exch_transaction_id'];
                            $walletT->withdraw_status   = 2;
                            $walletT->save();  

                        } 
                }
                if($data->data['status']=='failed & refunded')
                {
                       $exch_transaction_id = $data->data['exch_transaction_id']; 

                        $walletT = WalletTransaction::where('order_id',$exch_transaction_id)->first();

                        if($walletT!=null)
                        {
                            $walletT->order_id = $res['data']['exch_transaction_id'];
                            $walletT->transaction_id = $res['data']['exch_transaction_id'].'U'.$uers_id;
                            $walletT->withdraw_status = 1;
                             $walletT->payment_details   = 'Failed';
                            $walletT->save();  

                        }  
                }  
                if($data->data['status']=='queued')
                {
                       $exch_transaction_id = $data->data['exch_transaction_id']; 

                        $walletT = WalletTransaction::where('transaction_id',$tid)->whereNull('order_id')->first();

                        if($walletT!=null)
                        {
                            $walletT->order_id          = $res['data']['exch_transaction_id'];
                            $walletT->transaction_id    = $res['data']['exch_transaction_id'];
                            $walletT->withdraw_status   = 2;
                            $walletT->payment_details   = 'queued';
                            $walletT->save();  

                        }  
                }  
 

            // $walletT = WalletTransaction::where('transaction_id',$tid)->first();
               
                
            }
         

 
    }
    

    public function notifyToJoinTelegram(Request $request)
    {    

        $serviceAccountFilePath = '/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json';
        $accessToken = $this->getAccessToken($serviceAccountFilePath);

        $projectId = 'stumps-40dfd';


        $topic_list = [
            "notifyJoinContest",
            "notifyNinjaOffer",
            "notifyDocumentverified",
            "notifyToWalletsTransaction",
            "notifyUser2025"
        ];
        // this ll return position 0,1,2,3,4
        $topic =  array_rand($topic_list,1);

        

        $payload = [
            'message' => [
                'topic' => $topic_list[$topic],
                'notification' => [
                    'title' => '🏆10k Giveaway 🥷',
                    'body' => '🏏Create and Join your team now!!🏆',
                    'image' => 'https://rest.ninja11.in/ipl.png'
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [ 
                        'sound' => 'default',
                    ],
                ],
                'data' => [
                    'custom_key' => 'custom_value',
                    'action' => 'OPEN_SCREEN',
                ],
            ],
        ];
         

        $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
        ])->post($url, $payload);

        return $response->json();
 

    }

    public function customMessage(Request $request)
    {    
 
       $request->merge(
                    [
                        'topic'     => 'notifyUser2025', 
                        'title'     => "🏆".$request->title.'🔔',
                        'body'      =>  $request->message,
                        'image'     =>  'https://rest.ninja11.in/ipl.png'
                    ]
                    ); 
        
        $this->notifyUser($request); 
    }
    //
    public function contestFillNotify(Request $request)
    {
        $match = Matches::where('status',3)
                ->whereDate('date_start',\Carbon\Carbon::today())
                ->where('timestamp_start','>=',time())
                ->first(); 

        $t1 = $match->manual_date??$match->timestamp_start;
        $t2 = time();
        $td = round((($t1 - $t2)/60),2);
        $cf = $match->short_title??'Contest Filling fast';
        
        $message = '**Contest is filling fast. Create your team and join the contest. Hurry up!!**';
        $title = "🏏 $cf 🕚 🏆🏆 🔔";

        $image =  env('notify_url');

       
        $request->merge(
                    [
                        'topic'     => 'notifyUser2025', 
                        'title'     => $title,
                        'body'      =>  $message,
                        'image'     =>  'https://rest.ninja11.in/ipl.png'
                    ]
                    ); 
       
        
        if($td>5 && $td%15==0 || $td<60){            

            $this->notifyUser($request);  
            echo "sent"; 
        }
        elseif($request->status==1 && $td%5==0){
            $this->notifyUser($request);  
            echo "sent";
        }
    }

    public function apkUpdate(Request $request ){

        $apkUpdateCache = Cache::get('apkUpdate');
        if($apkUpdateCache){
            return $apkUpdateCache;
        }

        $version_code =  (string) $request->version_code;
        $user_id = $this->myid;


        $menu = [
            [
                'title' => 'Live Score',
                'url'   => 'https://ninja11.in/liveScore',
                'icon_url' => 'https://rest.ninja11.in/offers/ss.png',
                'show_menu' => false
            ],
            [
                'title' => 'Check My Available Passes',
                'url'   => 'https://rest.ninja11.in/api/v3/getData?user_id='.$user_id, 
                'icon_url' => 'https://rest.ninja11.in/offers/ss.png',
                'show_menu' => true
            ]
        ];

        /*,
            [
                'title' => 'How to use CPL Pass?',
                'url'   => 'https://ninja11.in/cpl-passes',
                'icon_url' => 'https://rest.fancode11.com/offers/ss.png',
                'show_menu' => false
            ]*/

       // $menu = [];

        $show_scoreboard = true;
        if($version_code){

            $apk_update_status = \DB::table('apk_updates') 
                ->where('version_code','>',$version_code)
                ->first();
            
                \DB::table('live_users')->updateOrInsert(
                    [
                        'user_id' => $request->user_id
                    ],
                    [
                        'user_id' => $request->user_id,
                        'app_version'=>$version_code
                    ]
                );
                
            

            if($apk_update_status > $version_code){
                $ttl = 3600;
                return [
                    'force_update'  => env('force_update',false),
                    'show_scoreboard' => $show_scoreboard,
                    'base_url'      =>  env('api_base_url').'/',
                    'splashScreen'  =>  env('splashScreen'),
                    'status'        =>  true,
                    'code'          =>  200,
                    'menu'          =>  $menu,
                    'message'       =>  $apk_update_status->message?$apk_update_status->message:'Update is available',
                    'url'           =>  'https://rest.ninja11.in/NinjaX11.apk',
                    'title'         =>  $apk_update_status->title,
                    'release_note'  =>  $apk_update_status->release_notes??'new updates'
                ];
            }else{
                $ttl = 86400;
                $apkUpdate =  [
                    'menu' =>       $menu,
                    'show_scoreboard' => $show_scoreboard,
                    'base_url'      =>  env('api_base_url').'/',
                    'splashScreen'  =>  env('splashScreen'),
                    'force_update'  =>  env('force_update',false),
                    'status'        =>  false,
                    'code'          =>  201,
                    'message'       =>  'No update available',
                    'url'           =>  null,
                    'title'         =>  null,
                    'url'           =>  null,
                    'release_note'  =>  null
                ];
            }

        }else{
            $ttl = 86400;
            $apkUpdate =  [
                'show_scoreboard' => $show_scoreboard,
                'force_update' =>   env('force_update',false),
                'splashScreen'  =>  env('splashScreen'),
                'status'        =>  false,
                'code'          =>  201,
                'message'       =>  'No update available',
                'url'           =>  null,
                'title'         =>  null,
                'url'           =>  null,
                'release_note'  =>  null,
                'menu'          => $menu
            ];
        }

        Cache::put('apkUpdate',$apkUpdate,$ttl);

        return $apkUpdate;

    }
    /*
    @var match_id
    @var content_id
    @desc join contest status
    */
    public function joinNewContestStatus(Request $request){ 


        $match_id   = $request->match_id;
        $contest_id = $request->contest_id;
        
        $create_teams = \DB::table('create_teams')
            ->where('match_id',$match_id)
            ->where('user_id',$request->user_id);

        $cc = CreateContest::where('match_id',$match_id)
            ->where('id',$contest_id)
            ->first();

        if(!isset($cc)){
            return [
                    'status'=>false,
                    'code' => 401,
                    'message' => "You don't have Balance. Please Add Money"
                    ]; 
                
         }

        $create_teams_count = $create_teams->count();    
        $user_id = $request->get('user_id');
        
        $wlt =  Wallet::where('user_id',$user_id)
                        ->whereIn('payment_type',[3,4])
                        ->sum('amount');   

        if(isset($cc) && $wlt<=0 && $cc->contest_type!=14 && $cc->contest_type!=21){
                 
            if($cc->contest_type==17 || $cc->contest_type==4){
                  return [
                    'status'=>false,
                    'code' => 201,
                    'message' => "To get Free Entry, Deposit wallet must have at least ₹1." 
                ];   
            }else{
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => "You dont have sufficient balance. Please add Fund!" 
                ];
            }
            
        }else{

            $wlt =  Wallet::where('user_id',$user_id)
                        ->whereIn('payment_type',[3,4,1])
                        ->sum('amount');

            $wlt2 =  Wallet::where('user_id',$user_id)
                        ->whereIn('payment_type',[3,4,1])
                        ->sum('extra_cash');   
             
            if(isset($cc) && $cc->entry_fees>$wlt2 && ($cc->contest_type==1 || $cc->contest_type==8 || $cc->contest_type==13 || $cc->contest_type==21)){

            }else{
                if($create_teams_count>0 && $cc->entry_fees>$wlt  &&  $cc->bonus_contest==0 ){
               return [
                    'status'=>false,
                    'code' => 401,
                    'message' => "You don't have Balance. Please Add Money"
                    ]; 
                }    
            }  
                
        }


         

        $join_contests = \DB::table('join_contests')
            ->where('match_id',$match_id)
            ->where('user_id',$request->user_id)
            ->where('contest_id',$request->contest_id);
        
        $close_team_id = $join_contests->pluck('created_team_id')->toArray();
        $request->merge(['type'=> 'close']);
        $request->merge(['close_team_id'=> $close_team_id]);
        // not join team id
        $close_team_id = $join_contests->pluck('created_team_id')->toArray();
        
        $close_team = $this->getMyTeam($request);
        $ct = $close_team->getdata()->response->myteam;
        if($close_team->getdata()->response->myteam){
        
          //   $team_list[] = ['close_team'=>$ct]; 
        }
        //  join team id
        $open_team_id = $create_teams->whereNotIn('id',$close_team_id)
                                    ->pluck('id')->toArray();
         
        $request->merge(['open_team_id'=> $open_team_id]);
         $request->merge(['type'=> 'open']);
        $open_team = $this->getMyTeam($request);   
        if($open_team->getdata()->response->myteam){
            $ot = $open_team->getdata()->response->myteam;
            $team_list[] = ['open_team' => $ot];   
        }
        
      
        $join_contests_count = $join_contests->count();
        if($cc && ($cc->filled_spot>0 && $cc->total_spots==$cc->filled_spot)){
           // $this->automateCreateContest();

            Redis::del('getContest_'.$request->match_id.'_'.$request->user_id);

            return [
                'status'=>true,
                'code' => 200,
                'message' => 'Contest is full',
                'action'=>3,
                'team_list' => $team_list??null 
            ];
        }elseif($create_teams_count > $join_contests_count){
            return [
                'status'=>true,
                'code' => 200,
                'message' => ' Join contest ',
                'action'=>2,
                'team_list' => $team_list??null
            ];
        }else{
            return [
                'status'=>true,
                'code' => 200,
                'message' => 'create new team to join this contest',
                'action'=>1,
                'team_list' => $team_list??null
            ];
        }
    }

    public function prizeBreakup(Request $request){

        $match_id   = $request->match_id;
        $contest_id = $request->contest_id;

        $rs = Cache::get('prizeBreakup_'.$match_id.'_'.$contest_id);
        if($rs){
            return $rs;
        }

        $contest =  CreateContest::where('match_id',$match_id)
            ->where('id',$contest_id)
            ->get();

        $contest->transform(function ($item, $key)   {

            $prize_breakups =  PrizeBreakup::firstOrNew([
                'default_contest_id' => $item->default_contest_id,
                'contest_type_id' => $item->contest_type,
             //   'match_id' => $item->match_id,
                'contest_id' => $item->id
             ]);
            if($item->filled_spot<=1){
                $prize_amount_unlmited = $item->first_prize;
                $rank_upto  = 1;
            }
            else{
                $prize_amount_unlmited = $item->entry_fees*2;
                $rank_upto  =  $item->filled_spot*(0.4);
            }
            if($item->total_spots==0){ 
                $prize_breakups->default_contest_id = $item->default_contest_id; 
                $prize_breakups->contest_type_id    = $item->contest_type;
                $prize_breakups->rank_from          = 1;
                $prize_breakups->rank_upto          = $rank_upto;
                $prize_breakups->prize_amount       = $prize_amount_unlmited;
                $prize_breakups->match_id           = $item->match_id;
                $prize_breakups->contest_id         = $item->id;
                $prize_breakups->save();
            }

            $defaultContest1  = \DB::table('prize_breakups')
                ->where('default_contest_id',$item->default_contest_id)
                ->where('contest_type_id',$item->contest_type)
                ->where('match_id',$item->match_id)
                ->where('contest_id',$item->id)
                ->get();

            $defaultContest2  = \DB::table('prize_breakups')
                ->where('default_contest_id',$item->default_contest_id)
                ->where('contest_type_id',$item->contest_type)
                ->get();

            if($defaultContest1->count()){
                $defaultContest =  $defaultContest1; 
            }else{
                 $defaultContest =  $defaultContest2;
            }    

            $cid = $item->id;
            $rank = [];
            foreach ($defaultContest as $key => $value) {
                $prize = $value->prize_amount;
                if($value->rank_from == $value->rank_upto || $value->rank_upto==1){
                    $rank_rang = "$value->rank_from";
                }else{
                    $rank_rang = $value->rank_from.'-'.$value->rank_upto;
                }

                /*if($item->total_spots==0 && $rank_rang==1){

                    $prize = round(($item->entry_fees*$item->filled_spot)*(0.25));

                    if($prize<$item->entry_fees){
                        if($item->filled_spot>1){
                            $prize = $item->entry_fees*($item->filled_spot-1);    
                        }else{
                            $prize = $item->entry_fees;
                        }
                    }
                    \DB::table('prize_breakups')->where('id',$value->id)
                        ->update(['prize_amount'=>$prize]);
                }*/
                $p_url = '';
                //https://rest.fancode11.com/offers/watch.jpg
                if($rank_rang==1 && $cid=='196062'){
                    $p_url = 'https://rest.ninja11.in/offers/rcb.png';
                }elseif($rank_rang==1 && $cid=='196661'){
                    $p_url = 'https://rest.ninja11.in/offers/rohit.png';
                }


                $rank[] = [
                    'range' => $rank_rang,
                    'price' => $prize,
                    'prize_url' => $p_url
                ];
            }
            $item->rank = $rank;
            return $item;
        });

        $data['prizeBreakup'] = $contest[0]->rank??null ;
        $result =  [
            'status'=>true,
            'code' => 200,
            'message' => 'Prize Breakup',
            'response' => $data
        ];

       // $memcached->set('prizeBreakup_'.$match_id.$contest_id, $result, 10) ;

       Cache::put('prizeBreakup_'.$match_id.'_'.$contest_id, $result, 1800);
        return $result;

    }

    public function updateUserMatchPoints(Request $request){
        //  echo "<br> --start  getPlayerPoints()-- ";
        // echo date('H:i:s A'); echo "<br>";
         $this->getPlayerPoints($request);
        //  echo date('H:i:s A'); echo "<br>";
        //  echo "<br> --End  getPlayerPoints()-- <br>";

        if($request->match_id){
            $matches = Matches::select('match_id')->where('match_id',$request->match_id)
                        ->get();
        }else{
            $matches = Matches::select('match_id')->where('status',3)
            ->get();
        }
        //  echo date('H:i:s A'); echo "<br>";
        //  echo "<br> --End  matches by id -- <br>"; 
         
        $mat = $matches->transform(function($item,$key)use($request){

                $request->merge(['match_id'=>$item->match_id]);  

                $contests = \DB::table('create_contests')
                    ->select('id', 'match_id') 
                    ->where('match_id',$item->match_id)
                    ->where('is_cancelled',0)
                    ->where('filled_spot','>',0)
                    ->get(); 

                    $rankingsToUpsert = [];
                   
                    $contests->each(function ($contest) use (&$rankingsToUpsert) {
                        // Collect rankings for this contest
                        $rankings = $this->updateMatchRankByMatchId($contest->match_id, $contest->id);

                        if (!empty($rankings)) {
                            $rankingsToUpsert = array_merge($rankingsToUpsert, $rankings);
                        }
                    }); 
                    
                    $chunkSize = 500;
                    $maxRetries = 3;

                    collect($rankingsToUpsert)
                        ->chunk($chunkSize)
                        ->each(function ($chunk) use ($maxRetries) {
                            $retryCount = 0;
                            
                            while ($retryCount < $maxRetries) {
                                try {
                                    \DB::table('join_contests')->upsert(
                                        $chunk->toArray(),
                                        ['id'],      // Unique key
                                        ['ranks']    // Columns to update
                                    );

                                    break; // ✅ Success, exit retry loop
                                } catch (\Illuminate\Database\QueryException $e) {
                                    // MySQL deadlock error code = 1213
                                    if ($e->getCode() == 1213) {
                                        $retryCount++;
                                        usleep(500000); // 0.5 sec (your code had 30000 = 0.03s)
                                    } else {
                                        throw $e; // rethrow other errors
                                    }
                                }
                            }

                            if ($retryCount === $maxRetries) {
                                \Log::error("Upsert failed after {$maxRetries} retries for chunk", [
                                    'chunk_sample' => $chunk->take(5)->toArray()
                                ]);
                            }
                        });

                       
                    
                    $contest=   $contests->transform(function($item,$key){
                          
                    });
                  
                $item->contest= $contest;
                return $item;      
        });


       
        $item = Matches::where('match_id',$request->match_id)->first();

        $t1 = $item->manual_date??$item->timestamp_start; 
        $t2 = time();
        //time diff
        $td = round((($t1 - $t2)/60),2); 

        // echo date('H:i:s A'); echo "<br>";
        // echo "<br> --start  WinningPrizeDistribution by mid-cid -- <br>"; 

        $this->WinningPrizeDistribution($request);
        // echo date('H:i:s A'); echo "<br>";
        // echo "<br> --end  WinningPrizeDistribution--- by mid-cid -- <br>"; 

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
                            ->select('points','ranks','id')           
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

        $ppoint = Cache::get('team_'.$team_id);

        if($ppoint)
        {
            return $ppoint;
        }


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
                  //  dd($players);
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
                    $player_image =  "https://rest.ninja11.in/player/".$ur;    
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

            $playerss = Player::where('match_id',$team_id->match_id)
                                    ->pluck('title as name','pid')->toArray();    


            
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

                $name = explode(" ",trim($playerss[$result->pid]));
                //$result->name = $name;
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

                
                if($name==""){
                    $name = $playerss[$result->pid]; 
                }

                $ur = $this->getPlayerPic($result->pid);
                if($ur){
                    $player_image ="https://rest.ninja11.in/player/".$ur;  
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


        $data_set = [
                'status'=>true,
                'code' => 200,
                "match_id" => $team_id->match_id,
                'message' => 'points update',
                'total_points' => $total_points,
                'response' => [
                    'player_points' => $data_set
                ]
        ];

        
        Cache::put('team_'.$team_id, $data_set, 60);


        return $data_set;
    }
    // get playerPoint
    public function getPlayerPoints(Request $request){

           // echo date('h:i:s A');
            $match_point_result = null;
            $match_id = $request->match_id;

            // Step 1: Fetch all contests for this match
            $contests = \DB::table('create_contests')
                ->where('match_id', $match_id)
                ->where('is_cancelled', 0)
                ->get();

            // Step 2: Get all JoinContests in ONE query
            $joinContests = \DB::table('join_contests')
                ->where('match_id', $match_id)
                ->get();

            // Step 3: Get all CreateTeams related to the match
            $teamIds = $joinContests->pluck('created_team_id')->unique();
            $createTeams = CreateTeam::whereIn('id', $teamIds)
                ->where('match_id', $match_id)
                ->get()
                ->keyBy('id');

            // Step 4: Get all MatchPoints for this match
            $matchPoints = MatchPoint::where('match_id', $match_id)->get()->keyBy('pid');

            // Step 5: Prepare batch updates
            $teamUpdates = [];
            $joinContestUpdates = [];

            foreach ($joinContests as $jc) {
                $team = $createTeams[$jc->created_team_id] ?? null;
                if (!$team) continue;

                $teams = json_decode($team->teams, true);
                if (!is_array($teams)) continue;

                $total_points = 0;

                foreach ($teams as $pid) {
                    $point = $matchPoints[$pid]->point ?? 0;

                    if ($pid == $team->captain) {
                        $point *= 2;
                    } elseif ($pid == $team->vice_captain) {
                        $point *= 1.5;
                    }
                    $total_points += $point;
                }
               

                $teamUpdates[] = [
                    'id' => $team->id,
                    'points' => $total_points,
                    'updated_at' => now(),
                ];

                $joinContestUpdates[] = [
                    'id' => $jc->id,
                    'points' => $total_points,
                    'updated_at' => now(),
                ];

            } 

            
            // Step 6: Batch update using upsert (Laravel >=8)
            $chunkSize = 1000;

            collect($teamUpdates)->chunk($chunkSize)->each(function ($chunk) {
                CreateTeam::upsert($chunk->toArray(), ['id'], ['points', 'updated_at']);
            });

            collect($joinContestUpdates)->chunk($chunkSize)->each(function ($chunk) {
              
                \DB::table('join_contests')->upsert($chunk->toArray(), ['id'], ['points', 'updated_at']);
            });


            return [
                'status' => true,
                'code' => 200,
                'message' => 'points updated'
            ];

    }
    //
    public function updatePoints(Request $request){
        echo '<br>Script end -->'.date('H:i:s A').'<--------<br>';

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
                    ->where('is_cancelled',0)
                    ->get(); 
                   
        }
        
        $m = [];
        if($matches->count()==0){
            die('No match available');
        }
        
        try{ 
            foreach ($matches as $key => $match) {   # code...
           
                
            $points = file_get_contents($this->cric_url."matches/".$match->match_id."/point?token=".$this->token);
            
            if (!$points)
            {
                continue;
            } 
                
               
            $this->mainApiCount($this->token2,'match_points');
                    
                    \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                        [
                            'match_id' => $match->match_id,
                            'action' => 'newpoint2'
                        ],
                        [
            
                            'action' => 'newpoint2',
                            'match_id' => $match->match_id,
                            'date_start' => date('Y-m-d'),
                            'date_end' => date('Y-m-d'),
                            'response' => $points,
                            'start_time' => time()
                
                        ]
                    ); 


            $points_json = json_decode($points);

            $t1 = $match->manual_date??$match->timestamp_start;
            $t2 = time();
            $td = round((($t1 - $t2)/60),2);
            foreach ($points_json->response->points as $team => $teams) {
                
                if (empty($teams)) continue;
                
                 
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
                       
                        
                        $m[$result->role][] = [
                            'point'=> $result->point
                        ];  


                        
                        MatchPoint::updateOrCreate(
                            ['match_id'=>$match->match_id,'pid'=>$result->pid],
                            (array)$result);

                    }
                }
                
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
           
                return $e;
            
        } 

        echo '<br>Script end -->'.date('H:i:s A');
        die('--Upated--');
        
        if($request->user_id==285 && $m){
            return $m;
        }else{
            return 'points updated';
        } 
    }

    public function getContestStat(Request $request){

        $match_stat =  MatchPoint::with(['player' => function($q){
            $q->with('team_a');
            $q->with('team_b');
        }])
            ->where('match_id',$request->match_id)
            ->select('match_id','pid','name','rating','point','role')
            ->get();
        $data = [];
        foreach ($match_stat as $key => $stat) {

            if(isset($stat->player->team_a)){
                $team_name = $stat->player->team_a->short_name;
            }
            if(isset($stat->player->team_b)){
                $team_name = $stat->player->team_b->short_name;
            }

            $data[] = [
                'match_id' => $stat->match_id,
                'pid' => $stat->pid,
                'fantasy_player_rating' => $stat->rating,
                'point' => $stat->point,
                'role' => strtoupper($stat->role),
                'team_id' => $stat->player->team_id,
                'player_name' => $stat->player->short_name,
                'team_name' => $team_name
            ];

        }

        return [
            'status'=>true,
            'code' => 200,
            'message' => 'contestStat',
            'response' => ['contestStat'=>$data]

        ];

    }
    
   
  /**
    * Description : Leaderboard data
    * @var match_is
    * @var user_id
    * @var content_id
    */
    public function leaderBoard(Request $request){
       
        // $join_contests = [];
        $match_id   = $request->match_id;
        $user_id = $request->user_id;
        $contest_id = $request->get('contest_id');
        $userVald = User::find($user_id);

        $lbcache = Cache::get('leaderboard_'.$request->get('contest_id'));

        if($lbcache){
            return $lbcache;
        }

        if(!$userVald || !$contest_id){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Access Deny'
    
            ];
        }else{

            $join_contests = JoinContest::where('match_id',$request->get('match_id'))
            ->where('contest_id',$request->get('contest_id'))
            ->pluck('created_team_id')->toArray();

        
            $limit = CreateContest::find($contest_id);
            $limit = $limit->filled_spot;

           $tcount =  Cache::get('total_team_'.$request->get('match_id'));

           // cache for more than 100 team
           if($limit>100)
           {
                $leaderboard = Cache::get('leaderboard_'.$request->get('contest_id'));
                if($leaderboard){
                    return $leaderboard;
                }
           }

        }

        
        
        $leader_board1 = JoinContest::with('user')
            ->where('match_id',$request->match_id)
            ->where('contest_id',$request->get('contest_id'))
            ->where(function($q) use($user_id){
                $q->where('user_id',$user_id);
            })
            ->orderBy('ranks','ASC')
            ->get();

            $leader_board1->transform(function($item,$key){
                     
                $item->prize_amount = $item->winning_amount??0;
                if($item->cancel_contest==1){
                    $item->prize_amount = 0;    
                }  
                return $item;
            });

        $point = ($leader_board1[0]->points??null);

        $leader_board2 = JoinContest::whereHas('user')
            ->where('match_id',$request->match_id)
            ->where('contest_id',$request->get('contest_id'))
            ->where(function($q) use($user_id,$point){
                $q->where('user_id','!=',$user_id);
                if($point){
                    $q->orderBy('ranks','ASC');
                }else{
                    $q->orderBy('ranks','ASC');
                }
            })
            ->limit(300)
            ->orderBy('ranks','ASC')
            ->get()
            ->transform(function($item,$key){
                $item->prize_amount = $item->winning_amount??0;
                return $item;
            });
        $lb = [];    

        foreach ($leader_board1 as $key => $value) {

            if(!isset($value->user)){
                continue;
            }
          //  $user = 
            

            $data['match_id'] = $value->match_id;
            $data['team_id'] = $value->created_team_id;
            $data['user_id'] = $value->user->user_name??$value->user->id;
            $data['team']   = $value->team_count;
            $data['point']  = $value->points;
            $data['rank']   = $value->ranks;
            $data['prize_amount'] = $value->winning_amount;
            $data['winning_amount'] = $value->winning_amount;
            $data['customer_type'] = $value->user->customer_type??0;
            $user_data =  $value->user->name;
            $fn = explode(" ",$user_data);

          //  $avatar = Avatar::create($value->user->name??"N")->toBase64();
           
          //  $pi = ($value->user->profile_image=="")?$avatar:$value->user->profile_image;
           // echo '<img src="'..'">';

            $data['user'] = [
               // 'first_name'    => $value->user->first_name,
               // 'last_name'     => $value->user->last_name,
                'name'          => strtolower($value->user->name??$value->team_name),
                'user_name'     => strtolower($value->user->user_name??$value->team_name),
              //  'team_name'     => $value->team_name??$value->user->team_name??reset($fn),
                'team_name'     => strtolower($value->team_name??$value->user->team_name),
                'profile_image' => $value->user->profile_image??null,
                'short_name'    => substr($value->user->name,0,1),
                'customer_type'    => $value->user->customer_type,
                'user_id'    => $value->user->user_name
            ];
            $lb[] = $data;
        }

        foreach ($leader_board2 as $key => $value) {

            if(!isset($value->user)){
                continue;
            }


            $data['match_id']   = $value->match_id;
            $data['team_id']    = $value->created_team_id;
            $data['user_id']    = $value->user->user_name??$value->user->id;
            $data['team']       = $value->team_count;
            $data['point']      = $value->points;
            $data['rank']       = $value->ranks;
            $data['prize_amount'] =  $value->prize_amount??$value->winning_amount;
            $data['winning_amount'] = $value->winning_amount;

            $user_data =  $value->user->name;
            $fn = explode(" ",$user_data); 
            $data['customer_type'] = $value->user->customer_type??0; 
            $tn = $value->team_name??reset($fn);
           
          //  $avatar = Avatar::create($value->user->name??"N")->toBase64();
           
           // $pi = ($value->user->profile_image=="")?$avatar:$value->user->profile_image;

            $data['user'] = [
               // 'first_name'    => reset($fn),
               // 'last_name'     => end($fn),
                'name'          => $value->user->name??$value->team_name,
                'user_name'     => strtolower($value->user->user_name??$value->team_name),
                'team_name'     => strtolower($tn),
                'profile_image' => $value->user->profile_image,
                'short_name'    => substr($value->user->name,0,1),
                'user_id'    => $value->user->user_name,
                'customer_type'    => $value->user->customer_type
            ];
            $lb[] = $data;
        }
        $lb = $lb??null;

        //$match_info = $this->setMatchStatusTime($match_id);
      //return($lb);

      // cache
        $c =  $limit;

        if($lb){

            $ouput =  [
                //   'system_time'=>time(),
                   $data['team_count'] =1,
                   'match_status' => $match_info['match_status']??null,
                   'match_time' => $match_info['match_time']??null,
                   'status'=>true,
                   'code' => 200,
                   'message' => 'leaderBoard',
                   'total_team' =>  $c,
                   'leaderBoard' =>mb_convert_encoding($lb, 'UTF-8', 'UTF-8')
   
               ];

            Cache::put('total_team_'.$request->get('match_id'),$c,60);
            Cache::put('leaderboard_'.$request->get('contest_id'),$ouput,60);

            return $ouput;
        }else{
            return [
                'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                'status'=>false,
                'code' => 201,
                'message' => ''
            ];
        }

    }

    public function leaderBoardNinja(Request $request){
        // $join_contests = [];
        $match_id = $request->match_id;
        $join_contests = JoinContest::where('match_id',$request->get('match_id'))
            ->where('contest_id',$request->get('contest_id'))
            ->pluck('created_team_id')->toArray();

        $user_id = $request->user_id;

        $ct = CreateContest::find($request->contest_id);
        $limit = $ct->filled_spot;
        $contest_all_user = JoinContest::with('user')
            ->where('match_id',$request->match_id)
            ->where('contest_id',$request->get('contest_id'))
            ->pluck('user_id')->toArray();

        $main_user = User::where('customer_type',0)
                        ->whereIn('id',$contest_all_user)
                        ->pluck('id')
                        ->toArray();
              
        $actual_amount =   ($ct->entry_fees-$ct->entry_fees*($ct->bonus_contest/100)); 

        $collection =  count($main_user)*$actual_amount;
            
        $distribute = JoinContest::whereIn('user_id',$main_user)
                        ->where('match_id',$request->match_id)
                        ->where('contest_id',$request->get('contest_id'))
                        ->sum('winning_amount');

        if($distribute > $collection){
            $total_loss = round($distribute-$collection); 
        }               
        if($collection > $distribute){
            $total_profit = round($collection - $distribute); 
        }               



        $leader_board1 = JoinContest::with('user')
            ->where('match_id',$request->match_id)
            ->where('contest_id',$request->get('contest_id'))
            ->where(function($q) use($user_id){
                $q->where('user_id',$user_id);
            })
            ->orderBy('ranks','ASC')
            ->get();

            $leader_board1->transform(function($item,$key){
                     
                $item->prize_amount = $item->winning_amount??0;
                if($item->cancel_contest==1){
                    $item->prize_amount = 0;    
                }  
                return $item;
            });

        $point = ($leader_board1[0]->points??null);



        $leader_board2 = JoinContest::whereHas('user')
            ->where('match_id',$request->match_id)
            ->where('contest_id',$request->get('contest_id'))
            ->where(function($q) use($user_id,$point){
                $q->where('user_id','!=',$user_id);
                if($point){
                    $q->orderBy('ranks','ASC');
                }else{
                    $q->orderBy('ranks','ASC');
                }
            })
            ->limit($limit)
            ->orderBy('ranks','ASC')
            ->get()
            ->transform(function($item,$key){
                $item->prize_amount = $item->winning_amount??0;
                return $item;
            });
        $lb = [];    

        foreach ($leader_board1 as $key => $value) {

            if(!isset($value->user)){
                continue;
            }
          //  $user = 
            $data['lastSeen'] = $value->last_seen;
            $data['captured'] = ($value->event_name=='captured')?true:false;
            $data['match_id'] = $value->match_id;
            $data['team_id'] = $value->created_team_id;
            $data['user_id'] = $value->user->user_name??$value->user->id;
            $data['team']   = $value->team_count;
            $data['point']  = $value->points;
            $data['rank']   = $value->ranks;
            $data['prize_amount'] = $value->winning_amount;
            $data['winning_amount'] = $value->winning_amount;
            $data['customer_type'] = $value->user->customer_type??0;
            $user_data =  $value->user->name;
            $fn = explode(" ",$user_data);

            $data['user'] = [
               // 'first_name'    => $value->user->first_name,
               // 'last_name'     => $value->user->last_name,
                'name'          => $value->user->name??$value->team_name,
                'user_name'     => $value->user_name??$value->user->team_name,
              //  'team_name'     => $value->team_name??$value->user->team_name??reset($fn),
                'team_name'     => $value->team_name??$value->user->team_name,
                'profile_image' => $value->user->profile_image,
                'short_name'    => substr($value->user->name,0,1),
                'customer_type'    => $value->user->customer_type,
                'user_id'    => $value->user->user_name
            ];
            $lb[] = $data;
        }

        foreach ($leader_board2 as $key => $value) {

            if(!isset($value->user)){
                continue;
            }
            $data['lastSeen']   = $value->last_seen;
            $data['captured']   = ($value->event_name=='captured')?true:false;
            $data['match_id']   = $value->match_id;
            $data['team_id']    = $value->created_team_id;
            $data['user_id']    = $value->user->user_name??$value->user->id;
            $data['team']       = $value->team_count;
            $data['point']      = $value->points;
            $data['rank']       = $value->ranks;
            $data['prize_amount'] =  $value->prize_amount??$value->winning_amount;
            $data['winning_amount'] = $value->winning_amount;
            $user_data =  $value->user->name;
            $fn = explode(" ",$user_data); 
            $data['customer_type'] = $value->user->customer_type??0; 

            $data['user'] = [
               // 'first_name'    => reset($fn),
               // 'last_name'     => end($fn),
                'name'          => $value->user->name??$value
                ->team_name, //reset($fn).' '.end($fn),
                'user_name'     => $value->user_name??reset($fn),
                'team_name'     => $value->team_name??reset($fn),
                'profile_image' => isset($user_data)?$value->user->profile_image:null,
                'short_name'    => substr($value->user->name,0,1),
                'user_id'    => $value->user->user_name,
                'customer_type'    => $value->user->customer_type
            ];
            $lb[] = $data;
        }
        $lb = $lb??null; 

       $contest_user    =   $this->identifyRealUser($request);
       $profitLoss      =   $this->getProfitLoss($request);

        if($lb){
            return [
                'total_loss' => $total_loss??0,
                'total_profit' => $total_profit??0,
                'main_user' => $contest_user->main_user??0,
                'ninja_user' => $contest_user->robo_user??0,
                'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                
                'status'=>true,
                'code' => 200,
                'message' => 'leaderBoard',
                'total_team' =>  $limit,
                'leaderBoard' =>mb_convert_encoding($lb, 'UTF-8', 'UTF-8')

            ];
        }else{
            return [
                'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                'status'=>false,
                'code' => 201,
                'message' => ''
            ];
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
        $b = array_unique(array_merge($playing11a1,$playing11b1));

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

    /*
    @method : createTeam
   */
    public function getMyTeam(Request $request){
       // return false;
        $match_id =  $request->match_id;
        $user_id  = $request->user_id;
         
        // $mytm =  Cache::get('myteam_'.$request->user_id.$request->match_id);

        // if($mytm)
        // {
        //    return $myTeam; 
        // }

        $userVald = User::find($user_id);
        $matchVald = Matches::where('match_id',$request->match_id)->count();

        $creat_team_obj = CreateTeam::where('match_id',$match_id);

        if(!$userVald || !$matchVald){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'user id or match id is invalid'
    
            ];
        }else{
            // $value = Cache::get('myteam_count_'.$user_id);
            // $tcc = $creat_team_obj->where('user_id',$user_id)->count();
            // if($value==$tcc)
            // {
            //     return Cache::get('myteam_'.$user_id);
            // }else{
                
            // }

        }

        

        if($request->type=="close"){
            $myTeam   = $creat_team_obj->whereIn('id',$request->close_team_id)   
                        ->where('user_id',$user_id )
                        ->get();
        }elseif($request->type=="open"){
            $myTeam   = $creat_team_obj->whereIn('id',$request->open_team_id)
                        ->where('user_id',$user_id)
                        ->get(); 
            
        }else{
            $myTeam   =  $creat_team_obj->where('user_id',$user_id)
            ->get();
        }
        
        /*$myT = Redis::get('createdTeam_'.$request->match_id.'_'.$request->user_id);   

        if($myTeam->count()==$myT){
            $gTeam = Redis::get('getTeam_'.$request->match_id.'_'.$request->user_id); 

            return json_decode($gTeam,true);   
        }*/

        $user_name = User::find($user_id);
        $data = [];
        foreach ($myTeam as $key => $result) {
            $player_ids = [];
            $team_id =  json_decode($result->team_id,true);
            $teams = json_decode($result->teams,true);
            if($team_id==null or $teams==null){
                continue;
            }
            
            $captain = $result->captain;
          //  $trump = $result->trump;
            $vice_captain = $result->vice_captain;
            $team_count = $result->team_count;
            $user_id    = $result->user_id;
            $match_id   = $result->match_id;
            $points     = $result->points;
            $rank       = $result->rank; 

            $k['created_team'] = ['team_id' => $result->id];

            $playing11 = $this->getPlaying11Players($result->match_id);
            if(count($playing11)){
                $playing11 = $playing11;
            }else{
                $playing11 = false;
            }

            $player = Player::WhereIn('team_id',$team_id)
                ->whereIn('pid',$teams)
                ->where('match_id',$result->match_id)
                ->groupBy('pid','id')
                ->pluck('id','pid')->toArray();  
            
            foreach ($player as $key => $rs) {
                $player_ids[] = $rs;
            }   
            $player = Player::whereIn('id',$player_ids)->get();
            $team_role["wk"] = [];
            $team_role["bat"] = [];
            $team_role["all"] = [];
            $team_role["bowl"] = [];
            foreach ($player as $key => $value) {
                if(is_array($playing11) && count($playing11) && isset($playing11[$value->pid])){
                }
                                
                if($value->playing_role=="cap"){
                    $team_role["bat"][] = $value->pid;
                }
                elseif($value->playing_role=="wkcap"){
                    $team_role["wk"][] = $value->pid;
                }
                elseif($value->playing_role=="wkbat"){
                    $team_role["wk"][] = $value->pid;
                }else{   
                    $team_role[$value->playing_role][] = $value->pid;
                }
            }
            foreach ($team_role as $key => $value) {
                $k[$key] = $value;
            }
            $team_role = [];
            $c = Player::WhereIn('team_id',$team_id)
                ->whereIn('pid',[$captain,$vice_captain])
                ->where('match_id',$result->match_id)
                ->pluck('short_name','pid');    
                            
            $k['c']     = ['pid'=> (int)$captain,'name' => $c[$captain]];
            $k['vc']    = ['pid'=>(int)$vice_captain,'name' => $c[$vice_captain]];

            $t_a = TeamA::WhereIn('team_id',$team_id)
                ->where('match_id',$result->match_id)
                ->first();

            $t_b = TeamB::WhereIn('team_id',$team_id)
                ->where('match_id',$result->match_id)
                ->first();

            $tac = Player::Where('team_id',$t_a->team_id)
                    ->whereIn('pid',$teams)
                    ->where('match_id',$result->match_id)
                    ->whereIn('id',$player_ids)
                    ->get();

            $tbc = Player::Where('team_id',$t_b->team_id)
                    ->whereIn('pid',$teams)
                    ->where('match_id',$result->match_id)
                    ->whereIn('id',$player_ids)
                    ->get();

            // team count with name
            $t[]   = ['name' => $t_a->short_name, 'count' => $tac->count()];
            $t[]   = ['name' => $t_b->short_name, 'count' => $tbc->count()];

            $k['match']   = [$t_a->short_name.'-'.$t_b->short_name];
            $k['team']    = $t;
            $k['c_img']   = "";
            $k['vc_img']  = "";


            // username
            $tname = $user_name->team_name??$user_name->name;
            $k['team_name'] =  $tname. '('.$result->team_count.')';
            $k['points']    = $points;
            $k['rank']      = $rank;
            $data[] = $k;
            $t      = [];
        }

        $match_info = $this->setMatchStatusTime($match_id);
        $result =
                [
                    'system_time'=>time(),
                    'match_status' => $match_info['match_status']??null,
                    'match_time' => $match_info['match_time']??null,
                    "status"=>true,
                    "code"=>200,
                    "teamCount" => $myTeam->count(),
                    "message"=>"success",
                    "response"=>["myteam"=>$data]
            ];

            $rs = response()->json($result);

            

        return $rs;
    }
    /*
     @method : createTeam
    */
    public function createTeam(Request $request){
        
        $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');
        if(!$okhttp){
            return array(
                    'status'    => false,
                    'code'      => 201,
                    'message'   => 'unauthorise access!'
                );
        } 
        $this->delRedisId($request);
      //  Cache::forget('myteam_'.$request->user_id.$request->match_id); 

        /*$stoken = $this->valideToken($request);
        if($stoken){
            return $stoken;
        }*/
        $user_id =  $request->user_id;  

        $user_data = User::find($user_id);

        $match_id = $request->match_id;
        $userVald = User::find($request->user_id);
        $matchVald = Matches::where('match_id',$request->match_id)->first();

        if($matchVald){
            $timestamp = $matchVald->manual_date??$matchVald->timestamp_start;
            $t = time();
          if($t > $timestamp){
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'Match time up'

                ];
            }
        }

        if(!$userVald || !$matchVald){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'user_id or match_id is invalid'

            ];
        }

        $ct = CreateTeam::firstOrNew(['id'=>$request->create_team_id]);
        if($request->create_team_id){ 

            if($ct->id==null){
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'Team list is empty!'

                ];
            }
        }

        $tm = array_values(array_filter($request->teams));
        sort($tm);
         if(count($tm)>11){
             return [
                'status'=>false,
                'code' => 201,
                'message' => 'Wrong team selection found.Try again!!'

            ];
         }

     //    

        $is_exist = CreateTeam::where(
                [
                    'match_id'       => $request->match_id,
                    'teams'          => json_encode($tm),
                    'captain'        => $request->captain,
                    'vice_captain'   => $request->vice_captain,
                    'user_id'        => $request->user_id
                ]
            )->count();

        $is_exist_tm = CreateTeam::where(
                [
                    'match_id'       => $request->match_id,
                    'teams'          => json_encode($tm),
                    'captain'        => $request->captain,
                    'vice_captain'   => $request->vice_captain,
                    'user_id'        => $request->user_id
                ]
            )->first();
            
        
            \DB::table('create_teams_edited')->insert(
                [
                    'match_id'       => $request->match_id,
                    'teams'          => json_encode($tm),
                    'captain'        => $request->captain,
                    'vice_captain'   => $request->vice_captain,
                    'user_id'        => $request->user_id,
                    'trump'             => time(),
                    'edited_time'       => date('Y-m-d h:i:s A',time())
                ]
            );
         
        if(($is_exist==1 &&  $request->create_team_id==null)){
            return [
                'status'=>false,
                'code' => 201,
                'message' => "You have already created  this team!"
            ];
        }elseif($is_exist>=1 && $is_exist_tm->id!==$request->create_team_id){

            return [
                'status'=>false,
                'code' => 201,
                'message' => "You have already created  this team!!"
            ];
        }

        $team_count = CreateTeam::where('user_id',$request->user_id)
                                ->where('match_id',$request->match_id)
                                ->count();

        if($team_count>=20 && $request->create_team_id==null){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Max create team limit exceeded'

            ];
        }

        try {
            
            if($request->create_team_id==null){
                $c_t = CreateTeam::where(
                    'match_id',$request->match_id)
                    ->where('user_id' , $request->user_id)
                    ->count();

                $t_count = $c_t+1;

                $ct->team_count = "T".$t_count;
            }

            $ct->match_id       = $request->match_id;
            $ct->contest_id     = $request->contest_id;
            $ct->team_id        = json_encode($request->team_id);
            $ct->teams          = json_encode($tm);
            $ct->captain        = $request->captain;
            $ct->vice_captain   = $request->vice_captain;
           // $ct->trump          = $request->trump;
            $ct->user_id        = $request->user_id;

            if($request->create_team_id){
                $ct->edit_team_count = $ct->edit_team_count+1;
            }
            $ct->save();
            
            $ct->team_id  = $request->team_id;
            $ct->create_team_id  = $ct->id;
            // player analytics
            $request->merge(['created_team_id'=>$ct->create_team_id]);
            
            $this->playerAnalytics($request);

            $check_clone_team = CreateTeam::where('is_cloned',$ct->id)->first();
            
            if($check_clone_team){ 

                $this->autoEditTeam($ct->id, $ct);    
            }

            $this->myjoinedTeamsCache($request);
            return response()->json(
                [
                    'system_time'=>time(),
                    'match_status' => $match_info['match_status']??null,
                    'match_time' => $match_info['match_time']??null,
                    "status"=>true,
                    "code"=>200,
                    "message"=>"Success",
                    "response"=>["matchconteam"=>$ct]
                ]
            );

        } catch (QueryException $e) {

            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message"=>""
                ]
            );
        }
    }

    public function updateTeam10(Request $request)
    {
        $team_id  =  $request->current_team_id;
        $my_userid =  $request->my_userid;

        if($my_userid==262 || $my_userid==285){
           
            $ct_arr = CreateTeam::where('rail_id',$team_id)
                    ->select('id')
                    ->get();

            if($ct_arr->count()<=10){
               // return true;
            }

            $team_name  = CreateTeam::find($team_id);

            
            $data = array_values(array_filter(json_decode($team_name->teams,true))); 
            

            $captain = $team_name->captain;
            $actual_team = $team_name->teams;

            try{
                foreach ($ct_arr as $key => $value) {
                    
                    if(!isset($data[$key])){
                        continue;
                    }

                   if($captain==$data[$key]){
                        continue;
                   }
                   $vc = $data[$key];
                   $ct_arr = CreateTeam::find($value->id);
                   $ct_arr->captain = $captain;
                   $ct_arr->teams = $actual_team;
                   $ct_arr->vice_captain = $vc;

                   $ct_arr->save();
                }
                
            }catch(\Exception $e){
            } 
        }
    }

     /*
     @method : createTeamFke
    */
    public function createTeamNinja(Request $request){
        $match_id = $request->match_id;
        $userVald = User::find($request->user_id); 

        if(!$userVald){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Something went wrong'

            ];
        }
        $matchVald = Matches::where('match_id',$match_id)->first();
        if($matchVald){
            $timestamp = $matchVald->manual_date??$matchVald->timestamp_start;
            $t = time();
         /* if($t > $timestamp || ($request->user_id!=285 || $request->user_id!=262)){
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'Match time up. You can not create Team Now'

                ];
            }*/
        }

        $ct = CreateTeam::firstOrNew(['id'=>$request->create_team_id]);
        $uid = $userVald->id;
        /*
        if($request->create_team_id && ($uid==285 || $uid==262)){  
            $request->merge(['current_team_id' => $ct->id]);
            $request->merge(['my_userid' => $request->user_id]);
            $this->updateTeam10($request);
        }*/

        if($request->create_team_id){ 

            if($ct->id==null){
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'Team list is empty!'

                ];
            }
        }
        $is_exist = CreateTeam::where(
                [
                    'match_id'       => $request->match_id,
                    'contest_id'     => $request->contest_id,
                    'team_id'        => json_encode($request->team_id),
                    'teams'          => json_encode($request->teams),
                    'captain'        => $request->captain,
                    'vice_captain'   => $request->vice_captain,
                   // 'trump'          => $request->trump,
                    'user_id'        => $request->user_id
                ]
            )->count();
         
        if($is_exist>=1 &&  $request->create_team_id==null){
            return [
                'status'    =>  false,
                'code'      =>  201,
                'message'   =>  'You have already created this team!'
            ];
        }

        $team_count = CreateTeam::where('user_id',$request->user_id)
            ->where('match_id',$request->match_id)->count();
        if($team_count>=20 && $request->create_team_id==null){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Max create team limit exceeded'

            ];
        }

        try {
            if($request->create_team_id==null){
                $c_t = CreateTeam::where(
                    'match_id',$request->match_id)
                    ->where('user_id' , $request->user_id)
                    ->count();

                $t_count = $c_t+1;

                $ct->team_count = "T".$t_count;
            }

            $ct->match_id       = $request->match_id;
            $ct->contest_id     = $request->contest_id;
            $ct->team_id        = json_encode($request->team_id);
            $ct->teams          = json_encode($request->teams);
            $ct->captain        = $request->captain;
            $ct->vice_captain   = $request->vice_captain;
         //   $ct->trump          = $request->trump;
            $ct->user_id        = $request->user_id;

            if($request->create_team_id){
                $ct->edit_team_count = $ct->edit_team_count+1;
            }
            $ct->save();
            
            $ct->team_id  = $request->team_id;
            $ct->create_team_id  = $ct->id;
            // player analytics
            $request->merge(['created_team_id'=>$ct->id]);
            
            $this->playerAnalytics($request);
            $check_clone_team = CreateTeam::where('is_cloned',$ct->id)
                                ->first();
            
            if($check_clone_team){
                $this->autoEditTeam($ct->id, $ct);
            }

            $match_info = $this->setMatchStatusTime($match_id);
            if($ct && ($uid==285 || $uid==262)){  
                $request->merge(['current_team_id' => $ct->id]);
                $request->merge(['my_userid' => $request->user_id]);
                $this->updateTeam10($request);
            }

            return response()->json(
                [
                    'system_time'=>time(),
                    'match_status' => $match_info['match_status']??null,
                    'match_time' => $match_info['match_time']??null,
                    "status"=>true,
                    "code"=>200,
                    "message"=>"Success",
                    "response"=>["matchconteam"=>$ct]
                ]
            );

        } catch (QueryException $e) {

            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message"=>"Failed"
                ]
            );
        }
    }

    public function updateContestByMatch($match_id=null){

        return false;

        $default_contest = \DB::table('default_contents')
            ->where('match_id',$match_id)
            ->whereNull('deleted_at')
            ->get()
            ->transform(function($item,$key){
                $contest_type = \DB::table('contest_types')->select('sort_by')->first();
                $item->sort_by = $contest_type->sort_by??0;
                return $item;
            });;

        foreach ($default_contest as $key => $result) {
            $createContest = CreateContest::firstOrNew(
                [
                    'match_id'           =>  $match_id,
                    'default_contest_id' =>  $result->id
                ]
            );

            $createContest->sort_by            =    $result->sort_by;
            $createContest->match_id            =   $match_id;
            $createContest->contest_type        =   $result->contest_type;
            $createContest->total_winning_prize =   $result->total_winning_prize;
            $createContest->entry_fees          =   $result->entry_fees;
            $createContest->total_spots         =   $result->total_spots;
            $createContest->first_prize         =   $result->first_prize;
            $createContest->winner_percentage   =   $result->winner_percentage;
            $createContest->cancellation        =   $result->cancellation?true:false;
            $createContest->default_contest_id  =   $result->id;
            $createContest->save();
            return true;
        }
    }
    // crrate contest dyanamic
    public function createContest($match_id=null){

        $default_contest = \DB::table('default_contents')
            ->whereNull('match_id')
            ->whereNull('deleted_at')
            ->get()
            ->transform(function($item,$key){
                $contest_type = \DB::table('contest_types')
                                ->where('id',$item->contest_type)->select('sort_by')->first();
                $item->sort_by = $contest_type->sort_by??0;
                return $item;
            });


        foreach ($default_contest as $key => $result) {
            $createContest = CreateContest::firstOrNew(
                [
                    'match_id'              =>  $match_id,
                    'default_contest_id'    =>  $result->id

                ]
            );
            $createContest->sort_by             =   $result->sort_by;
            $createContest->match_id            =   $match_id;
            $createContest->contest_type        =   $result->contest_type;
            $createContest->total_winning_prize =   $result->total_winning_prize;
            $createContest->entry_fees          =   $result->entry_fees;
            $createContest->total_spots         =   $result->total_spots;
            $createContest->first_prize         =   $result->first_prize;
            $createContest->winner_percentage   =   $result->winner_percentage;
            $createContest->cancellation        =   $result->cancellation?true:false;
            $createContest->default_contest_id  =   $result->id;
            $createContest->bonus_contest       =   $result->bonus_contest;
            $createContest->usable_bonus        =   $result->usable_bonus??0;
            $createContest->prize_percentage    =   $result->prize_percentage;
            $createContest->usable_extra_cash    =   $result->usable_extra_cash;
            $createContest->extra_cash_usable    =   $result->extra_cash_usable;
           
            $createContest->save();

            $default_contest_id = \DB::table('default_contents')
                ->where('match_id',$match_id)
                ->whereNull('deleted_at')
                ->get();

            if($default_contest_id){
                foreach ($default_contest_id as $key => $value) {
                    $this->updateContestByMatch($match_id);
                }
            }

        }
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
    // get contest details by match id
    public function getContestByType(Request $request){

        $contest_type_id = $request->contest_type_id;

        $match_id =  $request->match_id;
        $matchVald = Matches::where('match_id',$request->match_id)->first();
        
        if(!$matchVald){
            return [
                'system_time'=>time(),
                'status'=>false,
                'code' => 201,
                'message' => 'match id is Required'

            ];
        }
        $contest_type = \DB::table('contest_types')
                        ->where('id',$contest_type_id)
                        ->first();
        if(!$contest_type){
            return [
                'system_time'=>time(),
                'status'=>false,
                'code' => 201,
                'message' => 'Contest not available'

            ];
        }
        
        
        $ct = \DB::table('contest_types')
                ->orderBy('sort_by','asc')
                ->where('id',$contest_type_id)
                ->pluck('id')
                ->toArray();
                
        $contest = CreateContest::with('contestType')
            ->where('match_id',$match_id)
            ->where('is_cancelled',0)
            ->orderBy('sort_by','asc')
           // ->orderBy('id','DESC')
            ->whereIn('contest_type',$ct)
           // ->orderBy('entry_fees','DESC')
            ->orderBy('total_winning_prize','DESC')
            ->get();
           // return $contest;
        if($contest){
            $matchcontests = [];
            foreach ($contest as $key => $result) {
                if($result->total_spots <= $result->filled_spot && $result->total_spots!=0){
                   // continue;
                }
                //notification per
                $data2['contest_type_id'] =   $result->contest_type;
                $data2['isCancelled'] = $result->is_cancelled?true:false;

                $data2['maxAllowedTeam'] =   $result->contestType->max_entries??1;

                $data2['usable_bonus'] =   $result->usable_bonus;
                $data2['bonus_contest'] =   $result->bonus_contest?true:false;
                $data2['totalSpots'] =   $result->total_spots;
                $data2['firstPrice'] =   $result->first_prize;
                $data2['sort_by'] =   $result->sort_by;
                $data2['totalWinningPrize'] =    $result->total_winning_prize;
                if($result->total_spots==0)
                {
                    $data2['totalSpots'] =   0;
                    $twp = round(($result->filled_spot)*($result->entry_fees)*(0.5));

                    if($twp<$result->entry_fees){
                        if($result->filled_spot>1){
                            $prize = $result->entry_fees*($result->filled_spot-1);    
                        }else{
                            $prize = $result->entry_fees;
                        }  
                        $data2['totalWinningPrize'] = $prize;
                        $first_p = $prize;
                    }else{
                        $data2['totalWinningPrize'] = round(($result->filled_spot)*($result->entry_fees)*(0.5));
                        $first_p = round($twp*(0.2));    
                    }
                    $data2['firstPrice'] =   $first_p;

                }
                elseif($result->total_spots > 0 && $result->filled_spot==$result->total_spots)
                {
                   // $this->automateCreateContest();
                    continue;


                }
                $data2['contestId'] =    $result->id;

                $data2['entryFees'] =    $result->entry_fees;

                $data2['filledSpots'] =  $result->filled_spot;

                $data2['winnerPercentage'] = $result->winner_percentage;
                $data2['winnerCount'] = $result->winner_count??$result->prize_percentage;
                $data2['maxAllowedTeam'] =   $result->contestType->max_entries;
               // $data2['sort_by'] =   $result->sort_by;
                
                $data2['cancellation'] = $result->cancellation?true:false;
                $matchcontests[$result->contest_type][] = [
                    'sort_by' => $result->sort_by,
                    'contestTitle'=>$result->contestType->contest_type,
                    'contestSubTitle'=>$result->contestType->description,
                    'contests'=>$data2
                ];
            }
            // $data = [];
            $data[0] = null;
            foreach ($matchcontests as $key => $value) {

                foreach ($value as $key2 => $value2) {
                    //$value2['contests']['sort_by']
                    $k['contestTitle'] = $value2['contestTitle'];
                    $k['contestSubTitle'] = $value2['contestSubTitle'];
                    $k['contests'][] = $value2['contests'];
                }
                $data[] = $k;
                if($k['contestTitle']=='Practise Contest'){
                   // $data[0] = $k;
                }else{
                  // $data[] = $k;
                }
                $k = [];
            }

            $join_contests_team = \DB::table('join_contests')
                           ->where('match_id',$request->match_id)
                           ->where('user_id',$request->user_id)
                           ->pluck('created_team_id')->toArray();

            $join_contests = \DB::table('create_teams')
                ->where('match_id',$request->match_id)
                ->where('user_id',$request->user_id)
             //   ->whereIn('id',$join_contests_team)
                ->select('id as team_id')
                ->get();


            $myjoinedContest = $this->getMyContest2($request);
            $match_info = $this->setMatchStatusTime($match_id);
            return response()->json(
                [
                    'maintainance'=>env('DEVELOPMENT')??false,
                    'session_expired'=>$this->is_session_expire,
                    'system_time'=>time(),
                    'match_status' => $match_info['match_status']??null,
                    'match_time' => $match_info['match_time']??null,
                    "status"=>true,
                    "code"=>200,
                    "message"=>"Success",
                    "response"=>[
                        'matchcontests'=>array_values(array_filter($data)),
                        'myjoinedTeams' =>$join_contests,
                        'myjoinedContest' => ($myjoinedContest)
                    ]
                ]
            );
        }
    }
    // get contest details by match id
    
    public function getContestByMatch(Request $request)
    {  
        $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');
        // if(!$okhttp){
        //     return array(
        //             'status' => false,
        //             'code' => 201,
        //             'message' => 'unauthorise access!'
        //         );
        // }

        $c_r = Redis::get('getContest_'.$request->match_id.'_'.$request->user_id);

        if($c_r){
            $r_c = json_decode($c_r,true);
            return $r_c;
        }

        $passes = \DB::table('passes')
                    ->where('user_id',$request->user_id)
                    ->where('remaining_passes','>=',1)
                    ->get();
        $pass = []; 
        foreach($passes as $key => $value)
        {
            $pass[$value->pass_type] = [
                'total_pass' => $value->total_passes,
                'remaining_pass' => $value->remaining_passes
            ];
        } 

        $free_entry = \DB::table('free_entries')
                    ->where('user_id',$request->user_id)
                    ->get();
        $free_entry_fees = 0;
        $contest_type_id = [1,8,23];
        $date = Carbon::now()->subDays(5);

        $withdraw_amount = Wallet::where('user_id',$request->user_id)
                            ->where('payment_type',5)
                            ->count(); 

        if(Cache::get('cid_'.$request->match_id))
        {
            $cids = Cache::get('cid_'.$request->match_id);
        }else{
            $cids = CreateContest::whereIn('contest_type',$contest_type_id)
                            ->where('match_id',$request->match_id)
                            ->pluck('id')
                            ->toArray();

            Cache::put('cid_'.$request->match_id,$cids,30);
        }
        
        $newuser = JoinContest::where('user_id',$request->user_id)
                        ->where('created_at', '>=', $date)
                        ->whereIn('contest_id',$cids)
                        ->count();

        $new_entry_fees = 1; 
        if($newuser==0 && $withdraw_amount==0){
            $new_entry_fees = 1; //0.8
        }

        $matchVald = Matches::where('match_id',$request->match_id)->first();
        $ct = \DB::table('contest_types')
                ->orderBy('sort_by','asc')
                ->get();
        $match_id  = $request->match_id;

        $contest_obj = CreateContest::select(
                'contest_type as contest_type_id',
                'is_cancelled as isCancelled',
                'usable_bonus',
                'bonus_contest',
                'gift_url',
                'total_spots as totalSpots', 
                'first_prize as firstPrice',
                'sort_by',
                'total_winning_prize as totalWinningPrize',
                'prize_percentage as winnerCount',
                'gift_url',
                'entry_fees as entryFees',
                'id as contestId',
                'filled_spot as filledSpots',
                'winner_percentage as winnerPercentage',
                'cancellation',
                'contest_category_type',
                'discounted_price',
                'extra_cash_usable',
                'offer_end_at',
                'usable_extra_cash'
            )
            ->where('match_id',$match_id)
            ->where('is_cancelled',0)
            ->orderBy('sort_by','asc')
            ->orderBy('filled_spot','DESC')
            ->orderBy('entry_fees','ASC');
            //,11556,9112
        $uid = [285,262,11556];    
        if(in_array($request->user_id, $uid)){
            $contest=$contest_obj->get();
        }else{
            //if($request->referral_code)
            $contest = $contest_obj->whereColumn('total_spots','!=','filled_spot')->where(function($query)use($request){
              //  $query->Where('referral_code', $request->referal_code);
              //  $query->orWhere('referral_code', $request->reference_code);
               // $query->orWhereNull('referral_code');
            })->get();
        }    

        $res = [];
        foreach ($ct as $key => $value) {
            $ctype = $contest->where('contest_type_id',$value->id);
            if($ctype->count()){
                $ctype = $ctype->transform(function ($item, $key) use($value,$new_entry_fees,$contest_type_id,$free_entry_fees,$free_entry,$pass,$request) {
                    if($item->contest_type_id==1 || $item->contest_type_id==21)
                    {
                        $item->title        =  $value->contest_type;
                    }
                    $item->isCancelled      = ($item->isCancelled)?true:false;
                    $item->cancellation     = ($item->cancellation)?true:false;
                    $item->maxAllowedTeam   = $value->max_entries;
                    $item->gift_url         = $item->gift_url??'';
                    $item->contest_category_type = "";
                    $item->bonus_contest    = ($item->bonus_contest)?true:false;

                    if($item->contest_type_id==21)
                    {
                        $item->is_leaderboard   = true;
                    }else{
                        $item->is_leaderboard   = false;
                    }
                    if(in_array($item->contest_type_id,$contest_type_id)){
                       $item->entryFees   = (int)($item->entryFees*$new_entry_fees);
                    }

                   $ct_id =  $free_entry->where('contest_type_id',$item->contest_type_id)->first();

                   $total_allow_team = $free_entry->where('contest_type_id',$item->contest_type_id)->count();
                   if($total_allow_team>11)
                   {
                        $total_allow_team = 11;
                   }

                   $cef = $this->contestEntryFees($item,$pass,$request);
                   if($cef==0)
                   {
                        $item->entryFees   = 0;

                   }elseif($ct_id){
                        if($item->contest_type_id==$ct_id->contest_type_id){
                            $item->entryFees        = $ct_id->fees;
                            $item->maxAllowedTeam   = $total_allow_team;
                        } 
                    }
                    return $item;
                })->toArray();
                
                $data['contest_type_id'] = $value->id;
                $data['contestTitle'] = $value->contest_type;
                $data['title']      = $value->contest_type;
                $data['icon_url']   = $value->emoji_url;
                $data['contestSubTitle'] = $value->description;
                $data['contests']   = array_values($ctype);

                $res[] = $data;
            }
        }


        $mid_uid =    $request->match_id.$request->user_id; 
        if(Cache::get('created_team_'.$mid_uid))
        {
            $created_teams =    Cache::get('created_team_'.$mid_uid) ;
        }else{
            $this->myjoinedTeamsCache($request);
            $created_teams = Cache::get('created_team_'.$mid_uid);
        }   

        if(Cache::get('myjoinedContest_'.$mid_uid))
        {
            $myjoinedContest =    Cache::get('myjoinedContest_'.$mid_uid);  
        }else{
            
            $this->myjoinedContestCache($request);
            $myjoinedContest = Cache::get('myjoinedContest_'.$mid_uid);
        }   

        $match_info = $this->setMatchStatusTime($match_id);
        $result_set =  [
                    'session_expired'   =>  $this->is_session_expire,
                    'system_time'       =>  time(),
                    'match_status'      =>  $match_info['match_status']??null,
                    'match_time'        =>  $match_info['match_time']??null,
                    "status"            =>  true,
                    "code"              =>  200,
                    "message"           =>  "Success",
                    "response" =>[
                        'matchcontests' => $res,
                        'myjoinedTeams' => $created_teams,
                        'myjoinedContest' => $myjoinedContest
                    ]
                ];  

        Redis::set('getContest_'.$request->match_id.'_'.$request->user_id, json_encode($result_set));

        return response()->json($result_set); 
    }

    public function contestEntryFees($item,$pass,$request)
    {    
        $contest_id  = $item->contestId;
        $user_id     = $request->user_id;

        Cache::put('pass_entry_'.$user_id.$contest_id,0);
       // $data['user_id'] = $user_id;
       // $data['contest_id'] = $contest_id;
        
     //   \DB::table('paytm')->insert(['paytm'=> json_encode($data)]);

        $entryFees = 1;
        if(isset($pass['DIAMOND']))
        {
            if(in_array($item->contest_type_id,[1,13])){
                $entryFees   =  0; 
                Cache::put('pass_entry_'.$user_id.$contest_id,1,60);
                Cache::put('multi_entry_'.$user_id.$contest_id,1,60);
            } 
        }
        elseif(isset($pass['GOLDEN']) && isset($pass['SILVER']))
        {
            if(in_array($item->contest_type_id,[1,13])){
                $entryFees   =  0;
                Cache::put('pass_entry_'.$user_id.$contest_id,1,60);
                Cache::put('multi_entry_'.$user_id.$contest_id,1,60); 
            } 
        }
        elseif(isset($pass['SEASONAL']) && isset($pass['SILVER']))
        {
            if(in_array($item->contest_type_id,[1,13])){
               $entryFees   =  0; 
                Cache::put('pass_entry_'.$user_id.$contest_id,1,60);
                Cache::put('multi_entry_'.$user_id.$contest_id,0,60);
            } 
        }
        elseif(isset($pass['GOLDEN']))
        {
            if(in_array($item->contest_type_id,[1])){
                $entryFees   =  0;
                Cache::put('pass_entry_'.$user_id.$contest_id,1,60);
                Cache::put('multi_entry_'.$user_id.$contest_id,1,60);
            } 
        }
        elseif(isset($pass['SILVER']))
        {
            if(in_array($item->contest_type_id,[13])){
                
                $ps = \DB::table('passes_entry')
                        ->where('contest_id',$contest_id)
                        ->where('user_id',$user_id)
                         ->count();   
               if($ps==0){
                    $entryFees   =  0;
                    Cache::put('pass_entry_'.$user_id.$contest_id,1,60);
                    Cache::put('multi_entry_'.$user_id.$contest_id,1,60);
               }
            } 
        }
        elseif(isset($pass['SEASONAL']))
        {
            if(in_array($item->contest_type_id,[1])){
                $ps = \DB::table('passes_entry')
                        ->where('contest_id',$contest_id)
                        ->where('user_id',$user_id)
                         ->count();   
               if($ps==0){
                    $entryFees   =  0;
                    Cache::put('pass_entry_'.$user_id.$contest_id,1,60);
                    Cache::put('multi_entry_'.$user_id.$contest_id,0,60);
               }

            } 
        }
        return $entryFees;
    }

    public function getContestByMatch2(Request $request)
    {   
        $free_entry = \DB::table('free_entries')
                    ->where('user_id',$request->user_id)
                    ->get();



        $passes = \DB::table('passes')
                    ->where('user_id',$request->user_id)
                    ->where('remaining_passes','>=',1)
                    ->get();
        $pass = []; 
        foreach($passes as $key => $value)
        {
            $pass[$value->pass_type] = [
                'total_pass' => $value->total_passes,
                'remaining_pass' => $value->remaining_passes
            ];
        }
        

        $free_entry_fees = 0;
        $contest_type_id = [1,8,23];
        $date = Carbon::now()->subDays(5);

        $cids    = CreateContest::whereIn('contest_type',$contest_type_id)
                            ->where('match_id',$request->match_id)
                            ->pluck('id')
                            ->toArray();

        $newuser = JoinContest::where('user_id',$request->user_id)
                        ->where('created_at', '>=', $date)
                        ->whereIn('contest_id',$cids)
                        ->count();

        $new_entry_fees = 1;

        //$passes_type = $passes->

        if($newuser==0){
            $new_entry_fees = 0.8;
        }

        $matchVald = Matches::where('match_id',$request->match_id)->first();
        $ct = \DB::table('contest_types')
                ->orderBy('sort_by','asc')
                ->get();
        $match_id  = $request->match_id;

        $contest_obj = CreateContest::select(
                'contest_type as contest_type_id',
                'is_cancelled as isCancelled',
                'usable_bonus',
                'bonus_contest',
                'gift_url',
                'total_spots as totalSpots', 
                'first_prize as firstPrice',
                'sort_by',
                'total_winning_prize as totalWinningPrize',
                'prize_percentage as winnerCount',
                'gift_url',
                'entry_fees as entryFees',
                'id as contestId',
                'filled_spot as filledSpots',
                'winner_percentage as winnerPercentage',
                'cancellation',
                'contest_category_type',
                'discounted_price',
                'extra_cash_usable',
                'offer_end_at',
                'usable_extra_cash'
            )
            
            ->where('match_id',$match_id)
            ->where('is_cancelled',0)
            ->orderBy('sort_by','asc')
            ->orderBy('entry_fees','ASC');
            //,11556,9112
        $uid = [285,262,11556];    
        if(in_array($request->user_id, $uid)){
            $contest=$contest_obj->get();
        }else{
            //if($request->referral_code)
            $contest = $contest_obj->whereColumn('total_spots','!=','filled_spot')->where(function($query)use($request){
                $query->Where('referral_code', $request->referal_code);
                $query->orWhere('referral_code', $request->reference_code);
                $query->orWhereNull('referral_code');
            })->get();
        }    

        $res = [];
        foreach ($ct as $key => $value) {
            $ctype = $contest->where('contest_type_id',$value->id);
            if($ctype->count()){
                $ctype = $ctype->transform(function ($item, $key) use($value,$new_entry_fees,$contest_type_id,$free_entry_fees,$free_entry,$pass) {
                    if($item->contest_type_id==1)
                    {
                        $item->title        =  $value->contest_type;
                    }
                    $item->isCancelled      = ($item->isCancelled)?true:false;
                    $item->cancellation     = ($item->cancellation)?true:false;
                    $item->maxAllowedTeam   = $value->max_entries;
                    $item->gift_url         = $item->gift_url??'';
                    $item->contest_category_type = "";
                    $item->bonus_contest    = ($item->bonus_contest)?true:false;

                    if($item->contest_type_id==21)
                    {
                        $item->is_leaderboard   = true;
                    }else{
                        $item->is_leaderboard   = false;
                    }
                    if(in_array($item->contest_type_id,$contest_type_id)){
                       $item->entryFees   = (int)($item->entryFees*$new_entry_fees); 
                    } 

                   $ct_id =  $free_entry->where('contest_type_id',$item->contest_type_id)->first();
                    if(isset($pass['DIAMOND']))
                    {
                        if(in_array($item->contest_type_id,[1,13])){
                            $item->entryFees   = 0; 
                        } 
                    }
                    elseif($ct_id){
                        if($item->contest_type_id==$ct_id->contest_type_id){
                            $item->entryFees   = 1;
                            $item->maxAllowedTeam = 1;
                        } 
                   }
                   

                    return $item;
                })->toArray();
                
                $data['contest_type_id'] = $value->id;
                $data['contestTitle'] = $value->contest_type;
                $data['title']      = $value->contest_type;
                $data['icon_url']   = $value->emoji_url;
                $data['contestSubTitle'] = $value->description;
                $data['contests']   = array_values($ctype);

                $res[] = $data;
            }
        }

        $mid_uid =    $request->match_id.$request->user_id; 

        if(Cache::get('created_team_'.$mid_uid))
        {
            $created_teams =    Cache::get('created_team_'.$mid_uid) ;
        }else{
            $this->myjoinedTeamsCache($request);
            $created_teams = Cache::get('created_team_'.$mid_uid);
        }   

        if(Cache::get('myjoinedContest_'.$mid_uid))
        {
            $myjoinedContest =    Cache::get('myjoinedContest_'.$mid_uid);  
        }else{
            
            $this->myjoinedContestCache($request);
            $myjoinedContest = Cache::get('myjoinedContest_'.$mid_uid);
        }   
        

        $match_info = $this->setMatchStatusTime($match_id);
        $result_set =  [
                    'session_expired'   =>  $this->is_session_expire,
                    'system_time'       =>  time(),
                    'match_status'      =>  $match_info['match_status']??null,
                    'match_time'        =>  $match_info['match_time']??null,
                    "status"            =>  true,
                    "code"              =>  200,
                    "message"           =>  "Success",
                    "response" =>[
                        'matchcontests'   => $res,
                        'myjoinedTeams'   => $created_teams,
                        'myjoinedContest' => $myjoinedContest
                    ]
                ];  

         
        return response()->json($result_set);
    }
    
    public function myjoinedTeamsCache($request)
    {
         $created_teams = \DB::table('create_teams')
                ->where('match_id',$request->match_id)
                ->where('user_id',$request->user_id)
                ->select('id as team_id')
                ->get();
        if($created_teams->count())
        {

        }else{
            $created_teams = [];
        }
        $mid_uid =    $request->match_id.$request->user_id;     
        Cache::put('created_team_'.$mid_uid,$created_teams,now()->addMinutes(30));
    }

    public function myjoinedContestCache($request)
    {

        $myjoinedContest = \DB::table('join_contests')
                            ->select('contest_id')
                           ->where('match_id',$request->match_id)
                           ->where('user_id',$request->user_id)
                           ->groupBy('contest_id')
                           ->get(); 
        if($myjoinedContest->count())
        {

        }else{
            $myjoinedContest = [];
        }                  
        $mid_uid =    $request->match_id.$request->user_id; 
        Cache::put('myjoinedContest_'.$mid_uid,$myjoinedContest,now()->addMinutes(30));
    }

    public function getMatchDataFromApi()
    {
        //upcoming
        $upcoming =    file_get_contents($this->cric_url."matches?status=1&token=".$this->token);
        $this->storeMatchInfoAtMachine($upcoming,'upcoming/'.'upcoming.txt');
        $this->mainApiCount($this->token2,'match_by_status_1');
        
        \File::put(public_path('/upload/json/upcoming.txt'),$upcoming);

        //complted
        $completed =    file_get_contents($this->cric_url."matches?status=2&token=".$this->token);
        $this->mainApiCount($this->token2,'match_by_status_2');
        $this->storeMatchInfoAtMachine($completed,'completed/'.'completed.txt');
        \File::put(public_path('/upload/json/completed.txt'),$completed);

        //live
        $live =    file_get_contents($this->cric_url."matches?status=3&token=".$this->token);
        $this->mainApiCount($this->token2,'match_by_status_3');
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
            $token  =  $this->token2;
            $path   = $this->cric_url2."matches/".$match_id."/squads?token=".$token;
            //$this->mainApiCount('squads',$this->token2);
            $data   = $this->getJsonFromLocal($path);

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

              //  dd($data->response);
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

    public function saveMatchDataFromFlashMatch($data){
        
        if(isset($data->response)){

            $results[] = $data->response;

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

                /*TEAM A*/
                $team_a = TeamA::firstOrNew(['match_id' => $data_set['match_id']]);
                $team_a->match_id   = $data_set['match_id'];


                foreach ($data_set['teama'] as $key => $value) {
                    $team_a->$key = $value;
                }

                $team_a->save();



              //  \DB::transaction(function () use($team_a) {

                \DB::table('players')->where('match_id',$team_a->match_id)
                                ->where('team_id',$team_a->team_id)
                                ->update(
                                    [
                                        'team_name' => $team_a->short_name 
                                    ]
                                );
             //   });

                $team_a_id = $team_a->id;
                /*TEAM B*/
                $team_b = TeamB::firstOrNew(['match_id' => $data_set['match_id']]);
                $team_b->match_id   = $data_set['match_id'];

                foreach ($data_set['teamb'] as $key => $value) {
                    $team_b->$key = $value;
                }

                $team_b->save();

             //   \DB::transaction(function () use($team_b) {

                \DB::table('players')->where('match_id',$team_b->match_id)
                                ->where('team_id',$team_b->team_id)
                                ->update(
                                    [
                                        'team_name' => $team_b->short_name 
                                    ]
                                );

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
                    $matches->status_str = 'Upcoming';
                    if($key=='status_str' && $value=='Scheduled')
                    {  
                        $matches->status_str = 'Upcoming';
                    }
                    $matches->status = 1;
                }
                $matches->toss_id   = $toss_id;
                $matches->venue_id  = $venue_id;
                $matches->teama_id  = $team_a_id;
                $matches->teamb_id  = $team_b_id;
                $matches->competition_id = $competition_id;
                $matches->status_note  = $toss->text??'';

                $matches->save();

                $mid[] = $data_set['match_id'];
                $m_cid[$matches->match_id] = $competition_id;

                if($matches->status==1 || $matches->status==2){
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
                        
                       // $this->cancelMatchContest($request);
                    }
                }
            }


            if(count($mid)){ 
                $this->saveSquad($mid,$m_cid);
              //  $this->getSquad($mid);
               // 
            }
        }
        //
        return ["match info updated "];
    }
    //access from admin
    public function saveMatchDataByMatchId(Request $request, $match_id=null)
    {   
       $matches = Matches::firstOrNew(
                [
                    'match_id' => $match_id
                ]
            );
         
        //upcoming
        $data =  file_get_contents($this->cric_url."matches/".$match_id."/info?token=".$this->token);
        $this->mainApiCount($this->token2,'match_info');
          
        \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
            [
                'match_id' => $match_id,
                'action' => 'info'
            ],
            [

                'action' => 'info',
                'match_id' => $match_id,
                'date_start' => date('Y-m-d'),
                'date_end' => date('Y-m-d'),
                'response' => $data,
                'start_time' => time()
    
            ]
        );

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
        dd('ds');
        $endpoint = $this->cric_url2."matches/".$match_id."/info?token=".$this->token;
        
        
        $this->mainApiCount($this->token2,'match_info');
        $data =    file_get_contents($endpoint);

        \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
            [
                'match_id' => $match_id,
                'action' => 'info'
            ],
            [

                'action' => 'info',
                'match_id' => $match_id,
                'date_start' => date('Y-m-d'),
                'date_end' => date('Y-m-d'),
                'response' => $data,
                'start_time' => time()
    
            ]
        );
        
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
            $data =    file_get_contents($this->cric_url2."matches/".$match_id."/info?token=".$this->token);
            $this->mainApiCount($this->token2,'match_info');
            \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                [
                    'match_id' => $match_id,
                    'action' => 'info'
                ],
                [
    
                    'action' => 'info',
                    'match_id' => $match_id,
                    'date_start' => date('Y-m-d'),
                    'date_end' => date('Y-m-d'),
                    'response' => $data,
                    'start_time' => time()
        
                ]
            );

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

            $data =    file_get_contents($this->cric_url2."matches/".$match->match_id."/info?token=".$this->token);
            $this->mainApiCount($this->token2,'match_info');
            \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                [
                    'match_id' => $match->match_id,
                    'action' => 'info'
                ],
                [
    
                    'action' => 'info',
                    'match_id' => $match->match_id,
                    'date_start' => date('Y-m-d'),
                    'date_end' => date('Y-m-d'),
                    'response' => $data,
                    'start_time' => time()
        
                ]
            ); 
                $this->saveMatchDataFromAPI2DB($data);
        }

        return [$matches->count().' Match is updated successfully'];
    }

    public function updateLiveMatchFromApp()
    {
        //upcoming
        $match = Matches::where('status',3)->get();
        foreach ($match as $key => $result) {

            $data =    file_get_contents($this->cric_url2."matches/".$result->match_id."/info?token=".$this->token);
            $this->mainApiCount($this->token2,'match_info');
            \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                [
                    'match_id' => $result->match_id,
                    'action' => 'info'
                ],
                [
    
                    'action' => 'info',
                    'match_id' => $result->match_id,
                    'date_start' => date('Y-m-d'),
                    'date_end' => date('Y-m-d'),
                    'response' => $data,
                    'start_time' => time()
        
                ]
            );

            $this->saveMatchDataById($data);
        }
        return [' Live match  updated successfully'];
    }

    public function updateMatchDataByStatus( Request $request, $status=1)
    {   
        if( $request->allow=='ninja11' || $_SERVER['HTTP_USER_AGENT']=="curl/7.81.0"){

        }else{  
            die('Your IP is bnanned');    
        }
 
        $date    =  date('Y-m-d');
        $url     =  $this->cric_url."matches?status=$status&token=$this->token&per_page=50";
        $data    =  $this->cricketAPICall($url);
        $this->mainApiCount($this->token2,'match_by_status_'.$status);
        
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
                'response' => json_encode($data),
                'start_time' => time()
    
            ]
        );



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
        $data =    file_get_contents($this->cric_url2."matches/".$match_id."/info?token=".$this->token);
        $this->mainApiCount($this->token2,'match_info');

        \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
            [
                'match_id' => $match_id,
                'action' => 'info'
            ],
            [

                'action' => 'info',
                'match_id' => $match_id,
                'date_start' => date('Y-m-d'),
                'date_end' => date('Y-m-d'),
                'response' => $data,
                'start_time' => time()
    
            ]
        );

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

    //get file data from local
    public function getJsonFromLocal($path=null)
    {
        return json_decode(file_get_contents($path));
    }

    public function storeMatchInfoAtMachine($data,$fileName){

        \File::put(public_path('/data/v2/matches/'.$fileName),$data);                
    }

    public function getMatchInfoFromMachine($fileName=null,$file_path="/upload/json/"){
        if($fileName){
            $files = [$fileName];
        }else{
            $files = ['live','completed','upcoming'];
        }
        try {
            if(in_array($fileName, $files)){
                return $this->getJsonFromLocal(public_path($file_path.$fileName.'.txt'));
            }

        } catch (Exception $e) {
        }
        return ['match info stored'];
    }

    // store by match type
    public function storeMatchInfo($fileName=null){
        if($fileName){
            $files = [$fileName];
        }else{
            $files = ['live','completed','upcoming'];
        }
        try {
            if(in_array($fileName, $files)){
                return $this->getJsonFromLocal(public_path('/upload/json/'.$fileName.'.txt'));
            }

        } catch (Exception $e) {
        }
        return ['match info stored'];
    }

    public function saveMatchDataById($data){
        $data = json_decode($data);

        if(isset($data->response)){

            $result_set = $data->response;

            foreach ($result_set as $key => $rs) {
                $data_set[$key] = $rs;
            }
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
            $matches->save();
        }
        //
        return ["match info updated "];

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

                $remove_data = ['toss','venue','teama','teamb','competition','weather','pitch'];
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
    /*
        cancelMatchContest after abondon
    */

    public function cancelMatchAfetrAbandon(Request $request){

    }    

    public function cancelMatchContest(Request $request){
        $match_id = $request->match_id;
        $contest_id = $request->cancel_contest;

        if($request->cancel_contest){
            $JoinContest = JoinContest::whereHas('user')->with('contest')
                        ->where('match_id',$request->match_id)
                        ->whereIn('contest_id',$request->cancel_contest)
                        ->get()
                        ->transform(function($item,$key){
                        $cancel_contest = CreateContest::find($item->contest_id);
                        if($cancel_contest->usable_bonus){
                            $bonus_amount = $cancel_contest->entry_fees*($cancel_contest->usable_bonus/100);    
                        }else{
                            $bonus_amount = 0;
                        }
                        
                        $amount = $cancel_contest->entry_fees-$bonus_amount;
                        if($item->cancel_contest==0){

                            \DB::beginTransaction();
                            $cancel_contest->is_cancelled = 1;
                            $cancel_contest->save();
                            
                            if(isset($item->contest) && $item->contest->entry_fees){   
                                $transaction_id = $item->match_id.'N'.$item->contest_id.'F'.$item->created_team_id;
                                $wt =    WalletTransaction::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'transaction_id' => $transaction_id
                                        ]
                                    );
                                $wt->user_id            = $item->user_id;   
                                $wt->amount             = $item->contest->entry_fees;  
                                $wt->payment_type       = 7;  
                                $wt->payment_type_string = "Refunded";
                                $wt->transaction_id     = $transaction_id;
                                $wt->payment_mode       = env('company_name');   
                                $wt->payment_status     = "success";
                                $wt->debit_credit_status = "+"; 
                                $wt->match_id  = $item->match_id;
                                $wt->contest_id =  $item->contest_id;
                                $wt->save();

                                $wallet = Wallet::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'payment_type' => 3
                                        ]
                                    );

                                $wallet->user_id        =  $item->user_id;
                                $wallet->amount = $wallet->amount+$amount;
                                
                                $wallet->save();

                                $wallet2 = Wallet::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'payment_type' => 1
                                        ]
                                    );

                                $wallet2->user_id        =  $item->user_id;
                                $wallet2->amount = $wallet2->amount+$bonus_amount;
                                $wallet2->save();

                            }
 
                            \DB::commit();

                            $item->cancel_message = 'Contest Cancelled' ;
                            return $item;
                        }else{
                            $item->cancel_message = 'Already Cancelled' ; 
                            return $item; 
                        }
                    });               
        
        if($JoinContest->count()==0 and count($request->cancel_contest)){
           
            foreach ($request->cancel_contest as $key => $value) {
                $cancel_contest = CreateContest::find($value);
                $cancel_contest->is_cancelled = 1;
                $cancel_contest->save();
            }
           die('Match contest is cancelled');
        }

        $match      = Matches::where('match_id',$match_id)->first();

        $contest_count    = CreateContest::whereIn('id',$contest_id)->count();
        
        $join_contest_user = JoinContest::where('match_id',$match_id)
                            ->whereIn('contest_id',$contest_id)
                            ->where('cancel_contest',0)
                            ->pluck('user_id')
                            ->toArray();
       
        $device_id  = User::whereIn('id',$join_contest_user)
                        ->pluck('device_id')
                        ->toArray();
       // if contest was joined
        $msg = "$match->title contest has been Cancelled";              
        if(count($join_contest_user)){
            $data = [
                    'action' => 'notify' ,
                    'title' => 'Contest Cancelled and amount refunded' ,
                    'message' => $msg
                ];
               
            $this->sendNotification($device_id, $data);
        } 

        $JoinContest = JoinContest::where('match_id',$request->match_id)
                        ->whereIn('contest_id',$request->cancel_contest)
                        ->get()
                        ->transform(function($item,$key){

                            $cancel_contest = JoinContest::find($item->id);
                            $cancel_contest->cancel_contest=1;
                            $cancel_contest->save(); 
                        });
        return true;
        }else{
            return true; 
        }
    }

    public function updateSquad($match_id=null){

        # code...
        $cid = Competition::where('match_id',$match_id)->first();

        $token =  $this->token2;
        $path = $this->cric_url."competitions/".$cid->cid."/squads/".$match_id."?token=".$this->token2;

        $data = $this->getJsonFromLocal($path);
        $this->mainApiCount($this->token2,'squads');

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
                'response' => json_encode($data),
                'start_time' => time()
    
            ]
        ); 

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

    public function getMatchHistory(Request $request){
        //$status =  $request->status;

       

        $actiontype = $request->action_type;
        $user_id = $request->user_id;

        if($actiontype==1){
            $request->merge(['action_type'=>'upcoming']);
            $actiontype = $request->action_type;
        }
        elseif($actiontype==2){
            $request->merge(['action_type'=>'completed']);
            $actiontype = $request->action_type;
        }
        elseif($actiontype==3){
            /*$livem = Redis::get('getMatchHistory_'.$request->user_id);
            if($livem){
                return json_decode($livem,true);
            }*/
            $request->merge(['action_type'=>'live']);
            $actiontype = $request->action_type;
        } 
        
        if(!$user_id){
            return  [
                'system_time'=>time(),
                'status'=>false,
                'code'=>201,
                'message'=>'User not found'
            ];
        }

        $status = '(
                        CASE
                        WHEN status_str = "Scheduled" THEN "Upcoming"
                        ELSE
                        "Scheduled" end) as status_str';


        if($actiontype=="upcoming"){
            $upcomingMatches = Matches::with('teama','teamb')
            ->select('result_type','manual_date','match_id','title','short_title','status','status_str','timestamp_start','timestamp_end','date_start','date_end','game_state','competition_id','game_state_str',\DB::raw($status))
            ->whereIn('match_id',
                \DB::table('join_contests')->where('user_id',$user_id)

                    ->groupBy('match_id')
                    ->pluck('match_id')->toArray()
            )
            ->where('status',1)
            ->where('timestamp_start','>=' , time())
            ->orderBy('created_at','desc')
            ->get();
            
            $upcomingMatches->transform(function($items,$key)use($user_id){

                $league_title = \DB::table('competitions')->where('cid',$items->competition_id)->first()->title??null;

                $items->league_title = $league_title;

                $t1 = $items->manual_date??$items->timestamp_start;
                $date_start = date('d-M-Y, h:i A',$t1);
                $items->date_start = $date_start;
                
                return $items;
            });
        }
        $date = \Carbon\Carbon::today()->subDays(10);
        if($actiontype=="completed"){
            $completedMatches = Matches::with('teama','teamb')
            ->select('is_cancelled','result_type','match_id','title','short_title','status','status_str','timestamp_start','timestamp_end','date_start','date_end','game_state','game_state_str','competition_id','current_status','manual_date','result')
            ->where('is_cancelled',0)
            ->whereIn('match_id',
                \DB::table('join_contests')->where('user_id',$user_id)
                    ->where('created_at','>=',$date)
                 //   ->where('is_cancelled',0)
                    ->groupBy('match_id')
                    ->pluck('match_id')
                    ->toArray()
            )
            ->whereIn('status',[2,4])
            ->orderBy('timestamp_start','desc')
            ->get();

            $completedMatches->transform(function($items,$key)use($user_id){
                
                $league_title = \DB::table('competitions')->where('cid',$items->competition_id)->first()->title??null;


                $items->result  = $items->result;
                if($items->is_free==0){
                    $items->has_free_contest= false;
                }else{
                    $items->has_free_contest= true;
                }

                $t1 = $item->manual_date??$items->timestamp_start;

                $date_start = date('d,M Y, h:i A',$t1);
                $items->date_start = $date_start;

                $join_contests_count =  \DB::table('join_contests')
                    ->where('user_id',$user_id)
                    ->where('match_id',$items->match_id)
                    ->selectRaw('distinct contest_id')
                    ->get();

                $join_match_count   =   \DB::table('create_teams')
                                    ->where('user_id',$user_id)
                                    ->where('match_id',$items->match_id)
                                    ->get();
                                    
                $items->total_joined_team   =  $join_match_count->count();
                $items->total_join_contests =  $join_contests_count->count();

                $prize = \DB::table('join_contests')
                        ->where('match_id' ,$items->match_id)
                        ->where('user_id',$user_id)
                        ->where('ranks','>',0)
                        ->sum('winning_amount');

                $items->prize_amount = round($prize,2);
                if($items->status==2 && $items->current_status==0){
                    $items->status_str = "In Review" ;
                } 

                if($items->status==4){
                    $items->status_str = "Abandoned"; 
                }
                elseif($items->status==2 && $items->current_status==1){
                    $items->status_str = "Completed" ;
                }
                elseif($items->status==1){
                   $items->status_str = "Upcoming"; 
                }elseif($items->status==3){
                   $items->status_str = "Live" ;
                }else{
                   $items->status_str = $items->status_str; 
                }  

                
                if($items->result_type==4)
                {
                    $items->status=4;
                    $items->status_str= $items->result;
                        
                }
                     

                return $items;
            });
        }
        if($actiontype=="live"){
            $liveMatches = Matches::with('teama','teamb')
            ->select('is_cancelled','match_id','title','short_title','status','status_str','timestamp_start','timestamp_end','date_start','date_end','game_state','game_state_str','competition_id','manual_date','status_note') 
            ->where('status',3)
            ->where('is_cancelled',0)
            ->orderBy('updated_at','desc')
            ->get()

            ->transform(function($items,$key)use($user_id){           

                $league_title = \DB::table('competitions')->where('cid',$items->competition_id)->first()->title??null;

                $items->league_title = $league_title;
                $items->short_title = $items->status_note;

                $t1 = $item->manual_date??$items->timestamp_start;
                
                $date_start = date('d-M-Y, h:i A',$t1);
                $items->date_start = $date_start;
                
                $t2 = time();
                $td = round((($t1 - $t2)/60),2);
                                  
                if($td>(0.5)){
                    $items->status      = 1;
                    $items->status_str  = 'Upcoming Live';
                }

                return $items;
            });
        }
        if(isset($upcomingMatches) && count($upcomingMatches)==0){
            $upcomingMatches = null;
        }
        if(isset($completedMatches) && count($completedMatches)==0){
            $completedMatches = null;
        }
        if(isset($liveMatches) && count($liveMatches)==0){
            $liveMatches = null;
        }

        $my_match = null;
        switch ($actiontype) {
            case 'upcoming':
                $type_name = "upcomingMatch";
                $my_match = $upcomingMatches;
                break;
            case 'completed':
                $type_name = "completed";
                $my_match = $completedMatches;
                break;
            case 'live':
                $type_name = "live";
                $my_match = $liveMatches;
                break;

            default:
                $type_name = null;
                $my_match = null;
                break;
        }

        if($type_name && $my_match){
            $data['matchdata'][] = [
                'action_type'=>$actiontype, $type_name => $my_match
            ];
        }else{
            $data['matchdata'] = null;
        }
        $result =  ['status'=>true,'code'=>200,'message'=>'success','system_time'=>time(),'response'=>$data];


        return $result;
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
      
        $match = Matches::whereHas('player')->with('teama','teamb')
            ->whereIn('status',[1,3])
            ->select(
                'series_cancel','format','match_id','status',
                'timestamp_start','timestamp_end','date_start',
                'date_end','is_free','competition_id','manual_date',
                'is_dashboard','is_flash_back','order_by','is_cancelled')
          //  ->whereIn('format',[1,3,6,7,8,17])
            ->where('timestamp_start','>=' , time())
            ->where('is_cancelled',0)
            ->where('series_cancel',1)
            ->whereNotIn('competition_id',[129356,129417,129336,129437]) // exclude cid
            ->orderBy('order_by','DESC')
            ->orderBy('timestamp_start','ASC')
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
                      

                    $t1 = $item->manual_date ?? $item->timestamp_start;
                    $date_start = date('h:i A',$t1);
                    $td = round((($t1 - time()) / 60), 2);
                    $item->date_start = $date_start;


                    if($item->competition_id==129413)
                    {
                        
                        $keyA = 'avatar_' . md5($item->teama->short_name); 
                        $teamAvatar = Cache::remember($keyA, 86400, function () use ($item) { 
                            return Avatar::create($item->teama->short_name)->toBase64();
                        });

                        $keyB = 'avatar_' . md5($item->teamb->short_name);
                        $teamBvatar = Cache::remember($keyB, 86400, function () use ($item) { 
                            return Avatar::create($item->teama->short_name)->toBase64();
                        });

                        $item->teama->logo_url  =  $teamAvatar;
                        $item->teama->thumb_url =  $teamAvatar;
                        $item->teamb->logo_url  =  $teamBvatar;
                        $item->teamb->thumb_url =  $teamBvatar; 
                    }

                    
                    if($td<30 && $item->is_lineup==1){
                        $this->getSquadByMatch($match_id);    
                    }
                    return $item;
                      
            });
      //  Cache::put('match_data_cron', $match, 30);    
    }
    /*
        First Class     -   5
        ODI             -   1
        TEST            -   2
        T20I            -   3
        List A          -   4
        First Class     -   5
        T20             -   6 - interstat
        Women ODI       -   7
        Women T20       -   8
    */
     // get Match by status and all
   
    public function getBanner(Request $request){
        $user   = $request->user_id;
         
        if($user==null){
            return array(
                    'status' => false,
                    'code' => 1001,
                    'message' => 'unauthorised access!'
                );
        }

        $banner =  \DB::table('banners')->select('title','url','actiontype','description')->get();

        $join_cont =  \DB::table('join_contests')->where('user_id',$user);
        $join_contests = $join_cont->get('match_id');
            
        $jm = [];
        $created_team = CreateTeam::where('user_id',$user)
            ->orderBy('updated_at','desc')
            ->orderBy('match_id','ASC')
            ->get()
            ->groupBy('match_id')
            ->slice(0,2);

        
        $data['joinedmatches'] = []; 

        $joinmatch_key =  Cache::get('joinmatch_'.$request->user_id);
        if($joinmatch_key==null){
        if($created_team->count()){
            foreach ($created_team as $match_id => $join_contest) {
                # code...
               // $match_id = $join_contest->match_id;
                $jmatches = Matches::with('teama','teamb')->where('match_id',$match_id)->select('match_id','title','short_title','status','status_str','timestamp_start','timestamp_end','game_state','game_state_str','current_status','competition_id','timestamp_end','format_str','format','manual_date','is_lineup')
                    ->whereIn('status',[1,3])
                    ->first();
                $winning_amount = 0;
                $join_match = $jmatches;
                if($jmatches){
                    $prize = \DB::table('join_contests')
                        ->where('cancel_contest',0)
                        ->where('match_id' ,$match_id)
                        ->where('user_id',$request->user_id) 
                        ->sum('winning_amount');
                    $winning_amount =  round($prize, 2);
                    $join_match->prize_amount = $winning_amount;
                    $join_match->prize_amount = ($winning_amount);

                }else{
                    continue;    
                }    
                
                $league_title = \DB::table('competitions')->where('cid',$jmatches->competition_id)
                    ->first();
                
                $jmatches->league_title = $league_title->title??null;
                $jmatches->short_title = $league_title->title??null;

                if($jmatches->is_free==0){
                    $jmatches->has_free_contest= false;
                }else{
                    $jmatches->has_free_contest= true;
                }

                $join_contests_count =  \DB::table('join_contests')
                    ->where('user_id',$user)
                    ->where('match_id',$match_id)
                    ->selectRaw('distinct contest_id')
                    ->get();

                if($join_match->timestamp_end < time()){
                    if($join_match->status==4){
                       $join_match->status_str = 'Abandoned'; 
                    }
                    $t11 = $jmatches->timestamp_end;
                    $t21 = time();
                    $td11 = round((($t11 - $t21)/60),2);
                    
                }elseif($join_match->current_status==1){
                    $join_match->status_str = "Completed";
                }else{
                    if($join_match->status==4){
                       $join_match->status_str = 'Abandoned'; 
                    }elseif($join_match->status==2){
                       $join_match->status_str = "Completed" ;
                    }
                    elseif($join_match->status==1){
                       $join_match->status_str = "Upcoming"; 
                    }elseif($join_match->status==3){
                       $join_match->status_str = "Live" ;
                        
                        $t11 = $jmatches->timestamp_end;
                        $t21 = time();
                        $td11 = round((($t11 - $t21)/60),2);
                        
                        $request->merge(['match_id'=>$jmatches->match_id]);
                        $request->merge(['status'=>3]);
                       
                    }
                }  

                $lineup = \DB::table('team_a_squads')
                                ->where('match_id',$join_match->match_id)
                                ->where('playing11',"true")->count(); 
                 

                if($lineup>=11){
                    $join_match->is_lineup = true;
                }else{
                    $join_match->is_lineup = false;
                }               

                if($join_match->status==2 && $join_match->current_status==0){
                    $join_match->status_str = "In Review" ;
                }

                $t1 = $join_match->manual_date??$join_match->timestamp_start;
                $date_start = date('h:i A',$t1);
                $t2 = time();
                $td = round((($t1 - $t2)/60),2);
                if($td>0 && $join_match->status==3){
                    $join_match->status_str = "Upcoming" ;
                    $join_match->status = 1 ;  
                }
                
                $join_match_count   =   \DB::table('create_teams')
                    ->where('user_id',$user)
                    ->where('match_id',$match_id)
                    ->get();

                $join_match->total_joined_team   =  $join_match_count->count();
                $join_match->total_join_contests =  $join_contests_count->count();
                $jm[$match_id]                   =  $join_match;
            }

           if($jm){
             $data['joinedmatches'] =  array_values($jm);

             Cache::put('joinmatch_'.$request->user_id,$data,120); 
            }
        }
        }else{
            $data = $joinmatch_key;
        }
        
        $data['banners'] = $banner;
        
        $url_offer_key =  Cache::get('offer_url_'.$request->user_id);
        $date = date('Y-m-d');
        $url_offer = "";    
        if($url_offer_key==null){ 
            //xttra.png
         
            $arr = [
                //'https://rest.fancode11.com/offers/ga.png',
                //'https://rest.fancode11.com/offers/de_off.png',
                //'https://rest.fancode11.com/offers/nkyc.png'
            ];
          //  $key        = array_rand($arr);
            $url_offer  = ''; //$arr[$key];

            Cache::put('offer_url_'.$request->user_id,$url_offer,now()->addMinutes(180)); 
             //now()->addMinutes(120) 
        }else{
            $url_offer = "";
        } 
        
        $img_url = $url_offer;

        $result =  [
            'maintainance'=>env('DEVELOPMENT')??false,
            'date' => $date,
            'status'=>true,
            'offer_image' => $img_url,
            'code'=>200,
            'message'=>'success',
            'system_time'=>time(),
            'response'=>$data
        ]; 
        return $result;   
    }
    
    public function valideToken($request)
    {   
        $user = User::find($request->user_id);
        try{
            $token = Crypt::decryptString($request->system_token);
            
            if($token != $user->user_name)
            {
                return   [
                        "status"=>false,
                        "code"=>1001,
                        "message" => "Session expired,login again to continue!"
                    ];
                
            }else{
                return false;
            }

        }catch(DecryptException  $e){
            return   [
                    "status"=>false,
                    "code"=>1001,
                    "message" => "Session expired,login again to continue!"
                ];
            
        }
 
    }

    public function getCommonMatch(Request $request)
    { 
        $match = Matches::whereHas('player')
            ->with('teama', 'teamb')
            ->whereIn('status', [1, 3])
            ->select([
            'format', 'match_id', 'title', 'short_title', 'status', 'status_str',
            'timestamp_start', 'timestamp_end', 'date_start', 'date_end',
            'game_state', 'game_state_str', 'is_free', 'competition_id',
            'format_str', 'manual_date', 'is_dashboard', 'show_new_design',
            'is_lineup', 'dyanamic_message', 'is_flash_back', 'notification',
            'league_title', 'subtitle', 'order_by', 'is_cancelled'
            ])
          //  ->whereIn('format', [1, 3, 6, 7, 8,17])
            ->where('subtitle', '!=', 'Group A')
            ->whereNotIn('competition_id', [129356, 129417, 129336, 129437])
            ->where('timestamp_start', '>=', time())
            ->where('is_cancelled', 0)
            ->orderBy('order_by', 'DESC')
            ->orderBy('timestamp_start', 'ASC')
            ->limit(15)
            ->get();
       
        
      
        $match->transform(function($item,$key)use($request){
                    $request->merge(['match_id'=>$item->match_id]);
                    $match_id = $item->match_id;
                    // cronjob here
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

                    $t1 = $item->manual_date ?? $item->timestamp_start;
                    $td = round((($t1 - time()) / 60), 2);

                    $date_start = date('h:i A',$t1);
                    $item->date_start = $date_start;
                    
                    
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


                    if($item->competition_id==129413)
                    { 
                        $keyA = 'avatar_' . md5($item->teama->short_name); 
                        $teamAvatar = Cache::remember($keyA, 86400, function () use ($item) { 
                            return Avatar::create($item->teama->short_name)->toBase64();
                        });

                        $keyB = 'avatar_' . md5($item->teamb->short_name);
                        $teamBvatar = Cache::remember($keyB, 86400, function () use ($item) { 
                            return Avatar::create($item->teama->short_name)->toBase64();
                        });

                        $item->teama->logo_url  =  $teamAvatar;
                        $item->teama->thumb_url =  $teamAvatar;
                        $item->teamb->logo_url  =  $teamBvatar;
                        $item->teamb->thumb_url =  $teamBvatar; 
                    
                    }
                    
                    $item->league_title = ucfirst($league_title);
                    return $item;
            });
 
       // Cache::put('match_data', $match, 90);
        Redis::set('match_data', $match);  
        Cache::put('match_data', $match, 175); 

        Cache::remember('banner', 600, fn() => DB::table('banners')->get());
            return  $match;

    }
    
    public function getMatch(Request $request)
    { 
        $user = (int) $request->user_id; 
        if (!$user) {
            return [
                'status' => false,
                'code' => 1001,
                'message' => 'Session expired, login again!'
            ];
        } 
       
        
        $getMatchData = Cache::get('getMatch_data_' . $user);
        if ($getMatchData) {
          return $getMatchData;
        }
        $matchData = Redis::get('match_data');  

        if(!$matchData)
        {
            $matchData = Cache::get('match_data'); 
            
        }

         
        $matchData = json_decode($matchData, true) ?? [];
       
        // Fetch Banners
        $banners = DB::table('banners')->get();
        $mainBanners = $banners->where('location', 1);
        
        // Fetch Joined Matches
        $joinedMatches = $this->getJoinedMatches2($user);

       // dd($joinedMatches);
        
        $data['matchdata'][] = ['viewType' => 1, 'joinedmatches' => $joinedMatches];
        $data['matchdata'][] = ['viewType' => 2, 'banners' => $mainBanners]; 
        $data['matchdata'][] = ['viewType' => 3, 'upcomingmatches' => $matchData];  

        // Get Offer URL
        $offerUrl = Cache::remember('offer_url_' . $user, now()->addMinutes(300), function () use ($banners) {
            $popUpUrls = $banners->where('location', 2)->pluck('url')->toArray();
            return $popUpUrls ? $popUpUrls[array_rand($popUpUrls)] : "";
        });

        // Final Response
        $result = [
            'maintainance' => env('DEVELOPMENT', false),
            'date' => now()->toDateString(),
            'total_result' => count($matchData),
            'status' => true,
            'offer_image' => $offerUrl,
            'code' => 200,
            'message' => 'Success',
            'system_time' => time(),  
            'response' => $data
        ];

        // Cache the result
         Cache::put('getMatch_data_' . $user, $result, 30); 
        
        return $result;   
    }

    private function getJoinedMatches2($user)
    {
    $createdTeams = CreateTeam::where('user_id', $user)
        ->orderByDesc('updated_at')
        ->orderBy('match_id')
        ->limit(10) // Fetch more to ensure at least 3 unique match_ids
        ->get()
        ->unique('match_id')
        ->take(3); // Ensure only 3 unique match_ids are processed

        $matchIds = $createdTeams->pluck('match_id')->toArray();

        $matches = Matches::with(['teama', 'teamb'])
            ->whereIn('match_id', $matchIds)
            ->whereIn('status', [1, 3])
            ->where('series_cancel', 1)
            ->select('match_id', 'title', 'short_title', 'status', 'status_str', 'timestamp_start', 'timestamp_end', 'game_state', 'game_state_str', 'current_status', 'competition_id', 'format_str', 'format', 'manual_date', 'is_lineup', 'status_note', 'league_title')
            ->get()
            ->keyBy('match_id');

        $prizeSums = DB::table('join_contests')
            ->where('cancel_contest', 0)
            ->where('user_id', $user)
            ->whereIn('match_id', $matchIds)
            ->select('match_id', DB::raw('SUM(winning_amount) as prize_amount'))
            ->groupBy('match_id')
            ->pluck('prize_amount', 'match_id');

        $teamCounts = DB::table('create_teams')
            ->where('user_id', $user)
            ->whereIn('match_id', $matchIds)
            ->select('match_id', DB::raw('COUNT(*) as total_joined_team'))
            ->groupBy('match_id')
            ->pluck('total_joined_team', 'match_id');

        $contestCounts = DB::table('join_contests')
            ->where('user_id', $user)
            ->whereIn('match_id', $matchIds)
            ->select('match_id', DB::raw('COUNT(DISTINCT contest_id) as total_join_contests'))
            ->groupBy('match_id')
            ->pluck('total_join_contests', 'match_id');

        $finalMatches = [];

        foreach ($createdTeams as $team) {
            $match = $matches[$team->match_id] ?? null;
            if (!$match) continue;

            $match->prize_amount = $prizeSums[$team->match_id] ?? 0;
            $match->short_title = $this->getScoreboard($match);
            $match->has_free_contest = $match->is_free != 0;
            $match->total_joined_team = $teamCounts[$team->match_id] ?? 0;
            $match->total_join_contests = $contestCounts[$team->match_id] ?? 0;

            $finalMatches[] = $match;
        }

        return $finalMatches;

    }

    private function getJoinedMatches($user)
    {   
        $createdTeams = CreateTeam::where('user_id', $user)
            ->orderByDesc('id')
            ->orderBy('match_id')
            ->limit(3)
            ->get()
            ->unique('match_id');   
        
        $matches = [];
        foreach ($createdTeams as $team) {
            $match = Matches::with('teama', 'teamb')
                ->where('match_id', $team->match_id)
                ->whereIn('status', [1, 3])
                ->where('series_cancel', 1)
                ->select('match_id', 'title', 'short_title', 'status', 'status_str', 'timestamp_start', 'timestamp_end', 'game_state', 'game_state_str', 'current_status', 'competition_id', 'format_str', 'format', 'manual_date', 'is_lineup', 'status_note', 'league_title')
                ->first(); 

              
            if (!$match) continue;
            
            $match->prize_amount = DB::table('join_contests')
                ->where('cancel_contest', 0)
                ->where('match_id', $team->match_id)
                ->where('user_id', $user)
                ->sum('winning_amount');

            $match->short_title = $this->getScoreboard($match);
            $match->has_free_contest = $match->is_free != 0;
            $match->total_joined_team = DB::table('create_teams')->where('user_id', $user)->where('match_id', $team->match_id)->count();
            $match->total_join_contests = DB::table('join_contests')->where('user_id', $user)->where('match_id', $team->match_id)->distinct('contest_id')->count();
            
            $matches[] = $match;
        }
        
        

        return $matches;
    }

    private function getScoreboard($match)
    {
        $teamAScore = $match->teama->overs != 0 ? $match->teama->short_name . ':' . $match->teama->scores_full : "";
        $teamBScore = $match->teamb->overs != 0 ? $match->teamb->short_name . ':' . $match->teamb->scores_full : "";
        
        return trim("$teamAScore, $teamBScore", ", ") ?: $match->league_title;
    }

    //get Match by status and all
    public function getMatchOld(Request $request)
    { 
        $user   = $request->user_id; 
        if($user==null){
            return array(
                    'status' => false,
                    'code' => 1001,
                    'message' => 'Session expired login again!'
                );
        }


        $getMatch_data = Cache::get('getMatch_data_'.$request->user_id);

       if($getMatch_data)
       {
            return $getMatch_data;  
       }


        $match_data =  Redis::get('match_data');
        
        if($match_data){
            $match_data = json_decode($match_data, true);    
        }
        

        if($match_data==null){ 
            $this->getCommonMatch($request);
            $match_data =  Redis::get('match_data');
            $match_data = json_decode($match_data, true);
        }

        $banner_main =  \DB::table('banners')->get();

        $banner = $banner_main->where('location',1);


        $join_cont =  \DB::table('join_contests')->where('user_id',$user);
        $join_contests = $join_cont->get('match_id');
            
        $jm = [];

        $created_team = CreateTeam::where('user_id',$user)
            ->orderBy('updated_at','desc')
            ->orderBy('match_id','ASC')
            ->limit(3)
            ->get()
            ->unique('match_id');       
            
        if($created_team->count()){
            foreach ($created_team as $key => $join_contest) {
                # code...
                $match_id = $join_contest->match_id;
                $jmatches = Matches::with('teama','teamb')->where('match_id',$match_id)->select('match_id','title','short_title','status','status_str','timestamp_start','timestamp_end','game_state','game_state_str','current_status','competition_id','timestamp_end','format_str','format','manual_date','is_lineup','status_note','league_title')
                    ->whereIn('status',[1,3])
		   //->whereIn('format',[1,3,6,7,8])	
                    ->where('series_cancel',1)
                    ->first();
                $winning_amount = 0;
                $join_match = $jmatches;
                if($jmatches){
                    $prize = \DB::table('join_contests')
                        ->where('cancel_contest',0)
                        ->where('match_id' ,$match_id)
                        ->where('user_id',$request->user_id) 
                        ->sum('winning_amount');
                    $winning_amount =  round($prize, 2);
                    $join_match->prize_amount = $winning_amount;
                    $join_match->prize_amount = ($winning_amount);

                }else{
                    continue;    
                }
                $team_ascore = "";
                $team_bscore = "";
                    
                if($jmatches->teama->overs!=0)
                {
                    $team_ascore = $jmatches->teama->short_name.':'.$jmatches->teama->scores_full;
                }
                if($jmatches->teamb->overs!=0)
                {
                    $team_bscore = $jmatches->teamb->short_name.':'.$jmatches->teamb->scores_full;
                }

                $jmatches->league_title = $jmatches->league_title??null;
                $jmatches->short_title =  $jmatches->status_note??$jmatches->title;
                if($team_ascore!="" && $team_bscore!=""){
                    $scoreboard = $team_ascore.', '.$team_bscore;
                }elseif($team_ascore!=""){
                    $scoreboard = $team_ascore;
                }elseif($team_bscore!=""){
                    $scoreboard = $team_bscore;
                }else{
                   $scoreboard = $jmatches->league_title; 
                }
                $jmatches->short_title = $scoreboard ;

                if($jmatches->is_free==0){
                    $jmatches->has_free_contest= false;
                }else{
                    $jmatches->has_free_contest= true;
                }

                $join_contests_count =  \DB::table('join_contests')
                    ->where('user_id',$user)
                    ->where('match_id',$match_id)
                    ->selectRaw('distinct contest_id')
                    ->get();

                if($join_match->timestamp_end < time()){
                    if($join_match->status==4){
                       $join_match->status_str = 'Abandoned'; 
                    }
                    $t11 = $jmatches->timestamp_end;
                    $t21 = time();
                    $td11 = round((($t11 - $t21)/60),2);
                    
                }elseif($join_match->current_status==1){
                    $join_match->status_str = "Completed";
                }else{
                    if($join_match->status==4){
                       $join_match->status_str = 'Abandoned'; 
                    }elseif($join_match->status==2){
                       $join_match->status_str = "Completed" ;
                    }
                    elseif($join_match->status==1){
                       $join_match->status_str = "Upcoming"; 
                    }elseif($join_match->status==3){
                       $join_match->status_str = "Live" ;
                        
                        $t11 = $jmatches->timestamp_end;
                        $t21 = time();
                        $td11 = round((($t11 - $t21)/60),2);
                        
                        $request->merge(['match_id'=>$jmatches->match_id]);
                        $request->merge(['status'=>3]);
                       
                    }
                }  

                $lineup = \DB::table('team_a_squads')
                                ->where('match_id',$join_match->match_id)
                                ->where('playing11',"true")->count();

                if($lineup>=11){
                    $join_match->is_lineup = true;
                }else{
                    $join_match->is_lineup = false;
                }               

                if($join_match->status==2 && $join_match->current_status==0){
                    $join_match->status_str = "In Review" ;
                }

                $t1 = $join_match->manual_date??$join_match->timestamp_start;
                $date_start = date('h:i A',$t1);
                $t2 = time();
                $td = round((($t1 - $t2)/60),2);
                if($td>0 && $join_match->status==3){
                    $join_match->status_str = "Upcoming" ;
                    $join_match->status = 1 ;  
                }
                
                $join_match_count   =   \DB::table('create_teams')
                    ->where('user_id',$user)
                    ->where('match_id',$match_id)
                    ->get();

                $join_match->total_joined_team   =  $join_match_count->count();
                $join_match->total_join_contests =  $join_contests_count->count();
                $jm[$match_id]                   =  $join_match;
            }

           if($jm){
             $data['matchdata'][] = [
                'viewType'=>1,
                'joinedmatches'=>array_values($jm)
                ]; 
            }
        }
        
        $data['matchdata'][] = ['viewType'=>2,'banners'=>$banner]; 
        $data['matchdata'][] = ['viewType'=>3,'upcomingmatches'=>$match_data];  
        $url_offer_key       =  Cache::get('offer_url_'.$request->user_id); 

        $date = date('Y-m-d');
       // $url_offer = "";    
        if($url_offer_key==null){ 
            
            $url_offer = ""; 
            $pop_up_arr = $banner_main->where('location',2)->pluck('url')->toArray();   
            
            if($pop_up_arr)
            {
                $banner_index = array_rand($pop_up_arr); 
            
                $url_offer = $pop_up_arr[$banner_index];
            }  
            
            if($request->user_id==285)
            {
                Cache::put('offer_url_'.$request->user_id,$url_offer,1);     
            }else{
                Cache::put('offer_url_'.$request->user_id,$url_offer,now()->addMinutes(300)); 
            }
              
        }else{
            $url_offer = "";
        } 
        
        $img_url = $url_offer;

        $result =  [
            'maintainance'  =>  env('DEVELOPMENT')??false,
            'date'          =>  $date,
            'total_result'  =>  count($match_data),
            'status'        =>  true,
            'offer_image'   =>  $img_url,
            'code'          =>  200,
            'message'       =>  'success',
            'system_time'   =>  time(),  
            'response'      =>  $data
        ];
      //  $memcached->set('getMatch_'.$user, $result, 30);

        Cache::put('getMatch_data_'.$request->user_id, $result, 60); 



        return $result;   
    }

    public function getMatchFixture(Request $request){ 
        $user   = $request->user_id; 
        if($user==null){
            return array(
                    'status' => false,
                    'code' => 1001,
                    'message' => 'unauthorised access!'
                );
        }
        $match_data =  Cache::get('match_data');
        if($match_data==null){ 
            $this->getCommonMatch($request);
        }
        $match_data =  Cache::get('match_data'); 
        $data = ['upcomingmatches'=>$match_data]; 
        
        $url_offer_key =  Cache::get('offer_url_'.$request->user_id); 
        $date = date('Y-m-d');
        $url_offer = "";    
        if($url_offer_key==null){  
         
            $arr = [
                // 'https://rest.fancode11.com/offers/1rs.png'
                //'https://rest.fancode11.com/offers/de_off.png',
                //'https://rest.fancode11.com/offers/nkyc.png'
            ];
            //$key        = array_rand($arr);
            $url_offer  = ''; //$arr[$key];

            Cache::put('offer_url_'.$request->user_id,$url_offer,now()->addMinutes(120)); 
             //now()->addMinutes(120) 
        }else{
            $url_offer = "";
        } 
        
        $img_url = $url_offer;

        $result =  [
            'maintainance'=>env('DEVELOPMENT')??false,
            'date' => $date,
           // 'session_expired'=>$this->is_session_expire,
            'total_result'=>count($match_data),
            'status'=>true,
            'offer_image' => $img_url,
            'code'=>200,
            'message'=>'success',
            'system_time'=>time(),
            'response'=>$data
        ];
      //  $memcached->set('getMatch_'.$user, $result, 30);
        return $result;   
    }
    
    public function getAllCompetition(){

       $getAllCompetition =  Cache::get('getAllCompetition'); 
        if($getAllCompetition)
        {
            return $getAllCompetition;
        }

        $com = \DB::table('competitions')->select('id','match_id','cid')->get()->toArray();

        Cache::put('getAllCompetition', $com, 180); 

        return $com;
    }
    public function getAnalytics($match_id = null){
        
        $ct = CreateTeam::where('match_id',$match_id)->count();
        if($ct==0){
            return false;            
        }
        $player = \DB::table('player_analytics')->select('player_id',\DB::raw('COUNT(player_id) as count'))->where('match_id',$match_id)->groupBy('player_id')->where('created_team_id','>',0)->get();

            $player->transform(function($item,$key) use($ct,$match_id){
                if($ct){
                  $percent = ($item->count/$ct)*100;  
              }else{
                    $percent = 0;
              }
              /*  $trump = \DB::table('create_teams')
                        ->where('match_id',$match_id)
                        ->where('trump',$item->player_id)
                        ->count();*/
                $vc = \DB::table('create_teams')
                        ->where('match_id',$match_id)
                        ->where('vice_captain',$item->player_id)
                        ->groupBy('vice_captain')
                        ->count();
                $captain = \DB::table('create_teams')
                        ->where('match_id',$match_id)
                        ->where('captain',$item->player_id)
                        ->groupBy('captain')
                        ->count();
                


                $vc_per = ($vc/$ct)*100;
                $captain_per = ($captain/$ct)*100;
                
                $item->selection = number_format($percent,1);
                $item->vice_captain = $vc_per;
                $item->captain = $captain_per;  

                Player::where('match_id',$match_id)
                    ->where('pid',$item->player_id)
                    ->update(
                    [
                        'sell_by'    => number_format($percent,1),
                        'sell_by_c'  => number_format($captain_per,2),
                        'sell_by_vc' => number_format($vc_per,2)
                    ]); 
            return $item;
            });

        return $player;
    }

    public function getPlayerFromCache(Request $request)
    {   
        $match_id   =  $request->match_id;
        
        $analytics  = \DB::table('players')->select('pid as player_id','sell_by as selection','sell_by_c as captain','sell_by_vc as vice_captain','playing11','team_name','played_last_match')
            ->where('match_id',$match_id)->get();

        $matchVald  = Matches::where('match_id',$request->match_id)->count();
        if(!$matchVald){
            return [
                'status'=>false,
                'code' => 201,
                'message' => ' match_id is invalid'

            ];
        }
        //$final_playing11 = $this->getPlaying11Team($match_id);

        $players =  Player::where('match_id',$match_id)
                    ->orderBy('fantasy_player_rating','DESC')
                    ->get();

        if(!$players->count()){
            return ['status'=>false,'code'=>404,'message'=>'Player not found',
                'response'=>[
                    'players'=>null
                ]
            ];
        }
        $rs['wk'] = [];
        $bat['bat'] = [];
        $bat['all'] = [];
        $bat['bowl'] = [];

        $match_points = MatchPoint::where('match_id',$match_id)->pluck('point','pid')->toArray();
        $pid = [];
        // $playerPoints = $this->playerPoints($request);
        

        $team_a = \DB::table('team_a')->where('match_id',$match_id)->first();
        $team_b = \DB::table('team_b')->where('match_id',$match_id)->first();


        foreach ($players as $key => $results) {
            $data['playerPoints'] = (int)($results->player_points??0);

            if($results->team_id==$team_a->team_id){
                $tname = $team_a->short_name;
            }
            elseif($results->team_id==$team_b->team_id){
                $tname = $team_b->short_name;
            }
             
            $data['playing11'] =  filter_var($results->playing11, FILTER_VALIDATE_BOOLEAN);
            
            $data['fantasy_player_rating'] =  $results->fantasy_player_rating??'0';

            $data['team_name']  = $results->team_name??$tname; 
            $pname1             = $results->short_name;
            $data['pid']        = $results->pid;
            $data['match_id']   = $results->match_id;
            $data['played_last_match']   = $results->played_last_match;
            $data['team_id']    = $results->team_id;
            
            $data['points']     = ($match_points[$results->pid])??0;
            $fname = $results->first_name;
            $lname = $results->last_name;
            // pname4
            $title  = $results->title??$results->short_name;
            $short_name  = explode(" ",$results->title);
            $lastn  = array_pop($short_name);
            $p_name = "";

            foreach ($short_name as $pn) {
                $p_name .= $pn[0]??'';
            }
            $pname = $p_name.' '.($lastn);

            $data['short_name'] =  $pname;
            
            $ur = $this->getPlayerPic($results->pid);
            if($ur){
                $data['player_image'] = "https://rest.ninja11.in/player/".$ur;
            } 
            
            if($results->fantasy_player_rating==""){
                $data['fantasy_player_rating'] = "0";
            }else{
                $data['fantasy_player_rating'] = ($results->fantasy_player_rating);    
            }

            $sel_per = $analytics->where('player_id',$results->pid)->first();
            
            if($sel_per){
                $data['analytics'] = $analytics->where('player_id',$results->pid)->first();
            }else{
                $data['analytics'] = [
                    'selection'     => "0.0",
                  //  'trump'         => "0.0",
                    'vice_captain'  => "0.0",
                    'captain'       => '0.0'
                ];
            }
            $pids[$data['pid']][] = $data['pid'];

            if(count($pids[$data['pid']])>1){
                continue;
            }
            $pid = $results->pid;
            
            if($results->playing_role=="cap")
            {
                $rs['bat'][]  = $data;
            }
            if($results->playing_role=="wkcap")
            {
                $rs['wk'][]  = $data;
            }
            elseif($results->playing_role=="wkbat")
            {
                $rs['wk'][]  = $data;
            }else{
                $rs[$results->playing_role][]  = $data;
            }
            $data = [];
        }
        $result =   [
            'system_time'=>time(),
            'status'=>true,
            'code'=>200,
            'message'=>'success',
            'response'=>[
                'players'=>$rs
            ]
        ];

       // 
        Redis::set('getPlayer_'.$request->match_id, json_encode($result),'EX', 60);
        return $result;

        //Cache::put('getPlayer_'.$request->match_id, $result, 60);
    }
    public function getPlayer(Request $request)
    {
        $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');

        if(!$okhttp && $request->pin!='s11'){
            return array(
                    'status' => false,
                    'code' => 201,
                    'message' => 'unauthorise access!'
                );
        }
        
    $match_id = $request->get('match_id');
    $cacheKey = 'getPlayer_' . $match_id;

    // Start timing
   // $startTime = microtime(true);

    // Check cache before proceeding
    if ($cachedData = Cache::get($cacheKey)) {
         return $cachedData;
    }
  //  $cacheTime = microtime(true) - $startTime;

    // Validate Match ID Early
    $matchExists = Matches::where('match_id', $match_id)->exists();
    if (!$matchExists) {
        return [
            'status' => false,
            'code' => 201,
            'message' => 'Invalid match_id',
        ];
    }
   // $matchTime = microtime(true) - $startTime;

    // Fetch Analytics in One Query
    $analytics = \DB::table('players')
        ->select('pid', 'sell_by as selection', 'sell_by_c as captain', 'sell_by_vc as vice_captain', 'played_last_match')
        ->where('match_id', $match_id)
        ->get()
        ->keyBy('pid');
   // $analyticsTime = microtime(true) - $startTime;

    // Fetch Players in One Query
    $players = Player::where('match_id', $match_id)
        ->orderByDesc('playing11')
        ->get();
   // $playersTime = microtime(true) - $startTime;

    // Fetch Match Points in One Query
    $matchPoints = MatchPoint::where('match_id', $match_id)->pluck('point', 'pid');
   // $pointsTime = microtime(true) - $startTime;

    // Process Players
    if ($players->isEmpty()) {
        return [
            'status' => false,
            'code' => 404,
            'message' => 'No players found',
            'response' => ['players' => null],
        ];
    }

    $rs = ['wk' => [], 'bat' => [], 'all' => [], 'bowl' => []];

    foreach ($players as $player) {
        $pid = $player->pid;
        $data = [
            'playerPoints' => (int)($player->player_points ?? 0),
            'playing11' => $player->playing11,
            'fantasy_player_rating' => $player->fantasy_player_rating ?? '0',
            'team_name' => $player->team_name ?? '',
            'played_last_match' => $player->played_last_match??null,
            'pid' => $pid,
            'match_id' => $player->match_id,
            'team_id' => $player->team_id,
            'points' => $matchPoints[$pid] ?? 0,
            'short_name' => $this->getShortName($player->title, $player->short_name),
            'player_image' => "https://rest.ninja11.in/player/" . $this->getPlayerPic($pid),
            'analytics' => $analytics[$pid] ?? ['selection' => "0.0", 'captain' => "0.0", 'vice_captain' => "0.0"]
        ];

        // Group players by role
        $role = $player->playing_role;
        $rs[$role][] = $data;
    }
  //  $processingTime = microtime(true) - $startTime;

    $result = [
        'system_time' => time(),
        'status' => true,
        'code' => 200,
        'message' => 'Success',
        'response' => ['players' => $rs]
        // 'timings' => [
        //     'cache_check' => round($cacheTime * 1000, 2) . "ms",
        //     'match_check' => round($matchTime * 1000, 2) . "ms",
        //     'analytics_fetch' => round($analyticsTime * 1000, 2) . "ms",
        //     'players_fetch' => round($playersTime * 1000, 2) . "ms",
        //     'points_fetch' => round($pointsTime * 1000, 2) . "ms",
        //     'processing' => round($processingTime * 1000, 2) . "ms",
        // ]
    ];

    // Store in Redis for 2 minutes
     Cache::put($cacheKey, $result, 120);

    return $result;
}

// Optimized Short Name Function
private function getShortName($title="", $shortName="")
{
    if (!$title) 
        return $shortName??$title;
    $parts = explode(' ', trim($title??$shortName));
    
    $initials = array_map(fn($part) => strtoupper($part[0]??''), array_slice($parts, 0, -1));
    return implode('', $initials) . ' ' . end($parts);
}
    public function getPlayerOld(Request $request)
    {   
        // $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');

        // if(!$okhttp){
        //    return array(
        //             'status' => false,
        //             'code' => 201,
        //             'message' => 'unauthorise access!'
        //         );
        // }

        $match_id   =  $request->get('match_id');

        $cache_team = Cache::get('getPlayer_'.$request->match_id);
        if($cache_team){ 
            return  $cache_team;
           
        }
        
        

        // $mid        =  collect(\DB::select('call getMatchPlayer(?)',[$match_id]))
        //                 ->first();
        // if($mid){
        //     $gp = json_decode($mid->response,true);
        //     return  $gp;    
        // }

        $analytics  = \DB::table('players')->select('pid as player_id','sell_by as selection','sell_by_c as captain','sell_by_vc as vice_captain','played_last_match')->where('match_id',$match_id)->get();


        $matchVald  = Matches::where('match_id',$request->match_id)->count();
        if(!$matchVald){
            return [
                'status'=>false,
                'code' => 201,
                'message' => ' match_id is invalid'

            ];
        }
        //$final_playing11 = $this->getPlaying11Team($match_id);

        $players =  Player::where('match_id',$match_id)
                 //    ->orderBy('player_points','DESC')
                    ->orderBy('playing11','DESC') 
                    ->get();

        if(!$players->count()){
            return ['status'=>false,'code'=>404,'message'=>'Player not found',
                'response'=>[
                    'players'=>null
                ]
            ];
        }
        $rs['wk'] = [];
        $bat['bat'] = [];
        $bat['all'] = [];
        $bat['bowl'] = [];

        $match_points = MatchPoint::where('match_id',$match_id)->pluck('point','pid')->toArray();
        $pid = [];

        foreach ($players as $key => $results) {
            $data['playerPoints'] = (int)($results->player_points??0);
             
            $data['playing11'] =  filter_var($results->playing11, FILTER_VALIDATE_BOOLEAN);
            
            $data['fantasy_player_rating'] =  $results->fantasy_player_rating??'0';

            $data['team_name']  = $results->team_name??'';
            $data['played_last_match'] = $results->played_last_match; 
            $pname1             = $results->short_name;
            $data['pid']        = $results->pid;
            $data['match_id']   = $results->match_id;
            $data['team_id']    = $results->team_id;
            $data['points']     = ($match_points[$results->pid])??0;
            // first pname
            $title  = $results->title??$results->short_name;            
            $short_name  = explode(" ",$results->title);
            $lastn  = array_pop($short_name);
            $p_name = "";

            foreach ($short_name as $pn) {
                $p_name .= $pn[0]??'N';
            }
            $pname = $p_name.' '.($lastn);

            $data['short_name'] =  $pname;
            $ur = $this->getPlayerPic($results->pid);
            if($ur){
                $data['player_image'] =  "https://rest.ninja11.in/player/".$ur;    
            }
            
            
            if($results->fantasy_player_rating==""){
                $data['fantasy_player_rating'] = "0";
            }else{
                $data['fantasy_player_rating'] = ($results->fantasy_player_rating);    
            }
            

            $sel_per = $analytics->where('player_id',$results->pid)->first();
            
            if($sel_per){
                $data['analytics'] = $analytics->where('player_id',$results->pid)->first();
            }else{
                $data['analytics'] = [
                    'selection'     => "0.0",
                  //  'trump'         => "0.0",
                    'vice_captain'  => "0.0",
                    'captain'       => '0.0'
                ];
            }
            $pids[$data['pid']][] = $data['pid'];

            if(count($pids[$data['pid']])>1){
                continue;
            }
            $pid = $results->pid;
          /*  $data['playing11'] =  false;
            
            if(is_array($final_playing11) && count($final_playing11) && isset($final_playing11[$pid])){

                $rol = $final_playing11[$pid]??$results->playing_role;
                $data['playing11'] =  true;
            }*/
            
            if($results->playing_role=="cap")
            {
                $rs['bat'][]  = $data;
            }
            if($results->playing_role=="wkcap")
            {
                $rs['wk'][]  = $data;
            }
            elseif($results->playing_role=="wkbat")
            {
                $rs['wk'][]  = $data;
            }else{
                $rs[$results->playing_role][]  = $data;
            }
            $data = [];
        }
        $result =   [
            'system_time'=>time(),
            'status'=>true,
            'code'=>200,
            'message'=>'success',
            'response'=>[
                'players'=>$rs
            ]
        ];

        // \DB::table('match_players')->updateOrInsert(
        //     [
        //         'match_id' => $match_id
        //     ],
        //     [
        //         'response' => json_encode($result)
        //     ]
        // );  sdsf

       // Redis::set('getPlayer_'.$request->match_id, json_encode($result),'EX',60);

        Cache::put('getPlayer_'.$request->match_id, $result,120);

        return $result;
    }
    // get players
    
    public function getPlayerIntoDB(Request $request)
    {   
        $match_id   =  $request->match_id; 
        
        $analytics = collect(\DB::select('CALL GetPlayerAnalytics(?)', [$match_id]));
 
        $players =   collect(\DB::select('CALL GetPlayersByRating(?)', [$match_id]));
 
     
        if($players->count()==0){  
            return ['status'=>false,'code'=>404,'message'=>'Player not found',
                'response'=>[
                    'players'=>null
                ]
            ];
        } 
        $rs['wk'] = [];
        $bat['bat'] = [];
        $bat['all'] = [];
        $bat['bowl'] = [];

        $match_points = MatchPoint::where('match_id',$match_id)->pluck('point','pid')->toArray();
        $pid = [];
        // $playerPoints = $this->playerPoints($request);
        

        foreach ($players as $key => $results) {
            $data['playerPoints'] = (int)$results->player_points??0;
             
            $data['playing11'] =  filter_var($results->playing11, FILTER_VALIDATE_BOOLEAN);
            
            $data['fantasy_player_rating'] =  $results->fantasy_player_rating??'0';

            $data['team_name']  = $results->team_name??''; 
            $pname1             = $results->title;
            $data['pid']        = $results->pid;
            $data['match_id']   = $results->match_id;
            $data['team_id']    = $results->team_id;
            $data['points']     = ($match_points[$results->pid])??0;
            $fname              = $results->first_name;
            $lname              = $results->last_name;
            // pname 2
            $title  = $results->title??$results->short_name;            
            $short_name  = explode(" ",$results->title);
            $lastn  = array_pop($short_name);
            $p_name = "";
            
            foreach ($short_name as $pn) {
                $p_name .= $pn[0]??'';
            }
            $pname = $p_name.' '.($lastn); 

            $data['short_name'] =  $pname??$results->title;
            
            if($results->fantasy_player_rating==""){
                $data['fantasy_player_rating'] = "0";
            }else{
                $data['fantasy_player_rating'] = ($results->fantasy_player_rating);    
            }
            

            $sel_per = $analytics->where('player_id',$results->pid)->first();
            
            if($sel_per){
                $data['analytics'] = $analytics->where('player_id',$results->pid)->first();
            }else{
                $data['analytics'] = [
                    'selection'     => "0.0",
                    'vice_captain'  => "0.0",
                    'captain'       => '0.0'
                ];
            }
            $pids[$data['pid']][] = $data['pid'];

            if(count($pids[$data['pid']])>1){
                continue;
            }
            $pid = $results->pid; 
            
            if($results->playing_role=="cap")
            {
                $rs['bat'][]  = $data;
            }
            if($results->playing_role=="wkcap")
            {
                $rs['wk'][]  = $data;
            }
            elseif($results->playing_role=="wkbat")
            {
                $rs['wk'][]  = $data;
            }else{
                $rs[$results->playing_role][]  = $data;
            }
            $data = [];
        }
        $result =   [
            'system_time'=>time(),
            'status'=>true,
            'code'=>200,
            'message'=>'success',
            'response'=>[
                'players'=>$rs
            ]
        ];

        \DB::table('match_players')->updateOrInsert(
            [
                'match_id' => $match_id
            ],
            [
                'response' => json_encode($result)
            ]
        ); 
    }


    // update player by match_id
    public function getSquad($match_ids=null){

        foreach ($match_ids as $key => $match_id) {
            # code... 
            $token =  $this->token2;
            $path = $this->cric_url2."matches/".$match_id."/squads?token=".$token;  
            $data = $this->getJsonFromLocal($path);

            //$this->mainApiCount('squads',$this->token2);
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


    public function updatePayment($tid=1740769694098285)
    {
        $uid = substr($tid, 13);
        
        return $uid??285;
    }

    // phone payment status check

    public function checkPhonepeStatus($merchantOrderId)
    {
        //  Step 1: Get Access Token (reuse if already stored)
        $clientId = env('CLIENT_ID'); // Replace with your Client ID
        $clientVersion = 1 ;           // Replace with your Client Version
        $clientSecret = env('CLIENT_SECRET'); // Replace with your Client Secret
        $env = Env::PRODUCTION;  // Use Env::PRODUCTION for live environment

        $client  = StandardCheckoutClient::getInstance(
        $clientId,
        $clientVersion,
        $clientSecret,
        $env
        ); 

        $data = [];   
        $status = false;

        try {
            $statusCheckResponse = $client->getOrderStatus($merchantOrderId, true);

          
            if($statusCheckResponse->state=="COMPLETED")
            {
                $status     = true;
                $amount     = (($statusCheckResponse->amount)/100); 
            } 


        } catch (\PhonePe\common\exceptions\PhonePeException $e) {
            // Handle exceptions (e.g., log the error)
            echo "Error checking order status: " . $e->getMessage();
        }

        $data =  [
                'status'        =>    $status, 
                'order_id'      =>   $merchantOrderId , 
                'amount'    =>   $amount??0,   
        ]; 



       return $data;

    }
    // phonepe callback url
    public function callbackURLPhonePe(Request $request)
    { 
        
         \DB::table('paytm')->insert(['action'=>'phonepe-test' ,'paytm' => json_encode($request->all())]); 

        try { 
       
        $payload = $request->all();

        $data = $this->checkPhonepeStatus($payload['payload']['merchantOrderId']??time());
       
         
         if($data['status'])
        {
            $orderId   = $data['order_id'];


            $paymentLog = \DB::table('phonepe_logs')->where('tid',$orderId)->first();
            
            $user   = User::find($paymentLog->user_id);

            $amount    = $data['amount'];

            if($user==null)
            {
                return 'transaction failed';
            } 
            $request->merge([

                'payment_mode'      =>  'upi',
                'transaction_id'    =>   $data['order_id'],
                'utr'               =>   $payload['payload']['paymentDetails'][0]['rail']['utr'],
                'deposit_amount'    =>   $amount,
                'status_code'       =>   "PAYMENT_SUCCESS" ,
                'user_id'           =>   $user->id
            ]); 
        }        
          
           
        return $this->addMoney($request); 

 
        } catch (Exception $e) {
            
            return [
                "status"=>false,
                "code" => 500,
                "error" => $e
            ];
        }

    }

    public function redirectURLPhonePe(Request $request){ 
        echo "<br><br><br><h1><center><p>Thank you!!, Please check your wallet now!!</p></center> "; 
    }


    public function generatePaymentChecksum($request)
    {
        $user_id = $request->get('user_id')??'KUNA2020';
        $user           =   User::find($user_id);
        $tid            = time().$user_id;

        


        $json = collect([
            "merchantId" => "M23QJM8F0LZWZ",
            "merchantTransactionId" => $tid,
            "merchantUserId" => $user_id,
            "amount" => (int)($request->get('amount')??1000),
            "callbackUrl" => "https://infowaydigital.com/api/v3/callbackURLPhonePe",
            "mobileNumber" => $user->mobile_number,
            "paymentInstrument" => [
                "type" => "PAY_PAGE"
            ]
        ])->toJson(JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        
        $encodePayload = base64_encode($json);

        return [
            "system_time" => time(),
            "status" => true,
            "code" => 201,
            "encodedRequest" => $encodePayload,
            "transaction_id" => $tid,
            "message" => "Check sum generated",
        ];
    }
     
    public function phonePeInitiate(Request $request)
    {
        

        $user_id    =   $request->uid??285;
        $user       =   User::find($user_id);
        $amount     =   $request->amount??100;  

        $timestamp = time();  
        date_default_timezone_set('Asia/Kolkata');   

         $merchantOrderId = "TXT_".time(); 


        \DB::table('phonepe_logs')->insert([

            'user_id' => $user_id,
            'amount'  => $amount,
            'tid'     => $merchantOrderId ,
            'code'    => 'PAYMENT_PENDING', 

            ]);   


        $redirectUrl = "https://infowaydigital.com/api/v3/paymentReturnUrl"; 


        $clientId = env('CLIENT_ID'); // Replace with your Client ID
        $clientVersion = 1 ;           // Replace with your Client Version
        $clientSecret = env('CLIENT_SECRET'); // Replace with your Client Secret
        $env = Env::PRODUCTION;  // Use Env::PRODUCTION for live environment

        $client = StandardCheckoutClient::getInstance(
        $clientId,
        $clientVersion,
        $clientSecret,
        $env
        );

        // Unique order ID
        
        $message = "IT Support!!";

        $payRequest = StandardCheckoutPayRequestBuilder::builder()
            ->merchantOrderId($merchantOrderId)
            ->amount($amount*100)
            ->redirectUrl($redirectUrl)
            ->message($message)  //Optional Message
            ->udf1('udf1')
            ->udf2('udf2')
            ->udf3('udf3')
            ->udf4
        ('udf4
        ')
            ->udf5('udf5')
            ->build();


        $url = null;
        try {
            $payResponse = $client->pay($payRequest);

            // Handle the response
            if ($payResponse->getState() === "PENDING") {
                // Redirect the user to the PhonePe payment page
                $url =   $payResponse->getRedirectUrl();
                
            } else {
                // Handle the error (e.g., display an error message)
                echo "Payment initiation failed: " . $payResponse->getState();
            }
        } catch (\PhonePe\common\exceptions\PhonePeException $e) {
            // Handle exceptions (e.g., log the error)
            echo "Error initiating payment: " . $e->getMessage();
        }


        if(isset($url))
        {
               header("Location: " . $payResponse->getRedirectUrl());
        exit();


            }else{
                 $source = $request->source;

                    if ($source == "jk") {
                        return [
                            'ok' => 'success',
                            'status' => true, 
                            'url' => 'https://rest.justkhelo.com/payment?email=' . urlencode($user->email ?? null) . '&amount=' . $amount . '&source=' . $source, 
                            'data' => $result ?? null
                        ];
                    } else {
                        return [
                            'ok' => 'success',
                            'status' => true, 
                            'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email ?? null) . '&amount=' . $amount, 
                            'data' => $result ?? null
                        ];
                    }

            } 
    


            die('');



        //-----------------------------


        // ✅ Amount in paise
        $amount = (int)(($request->get('amount') ?? 100) * 100);

        $user_id = $request->get('user_id') ?? 285;
        $user = User::find($user_id);

        if (!$user) {
            return [
                'status' => 400,
                'message' => 'Invalid user'
            ];
        }

        // ✅ URLs
        $redirect_url = env('redirect_url_phonepe', 'https://infowaydigital.com/api/v3/redirectURLPhonePe');
        $callback_url = env('callback_url_phonepe', 'https://infowaydigital.com/api/v3/callbackURLPhonePe');

        // ✅ Unique Transaction ID
        $order_id = 'ORD_' . time() . rand(1000, 9999);

        // ✅ Credentials
        $merchant_id = env('PHONEPE_MERCHANT_ID', 'M23QJM8F0LZWZ');
        $salt_key    = env('PHONEPE_SALT_KEY', 'a0f68b13-df20-4496-80a1-7fabd57ad1d6');
        $salt_index  = env('PHONEPE_SALT_INDEX', '1');


        // ✅ Log request
        \DB::table('paytm')->insert([
            'action'  => 'phonePeInitiate',
            'user_id' => $user_id,
            'paytm'   => json_encode($request->all())
        ]);

        // ✅ Payload
        $data = [
            'merchantId' => $merchant_id,
            'merchantTransactionId' => $order_id,
            'merchantUserId' => (string)$user_id,
            'amount' => $amount,
            'redirectUrl' => $redirect_url,
            'redirectMode' => 'POST',
            'callbackUrl' => $callback_url,
            'mobileNumber' => $user->mobile_number ?? '9999999999',
            'paymentInstrument' => [
                'type' => 'PAY_PAGE'
            ]
        ];

        // ✅ Encode
        $base64 = base64_encode(json_encode($data));

        // ✅ Generate checksum
        $checksum = hash('sha256', $base64 . '/pg/v1/pay' . $salt_key) . '###' . $salt_index;

        // ✅ API URL (change to preprod for testing)
        $url =  'https://api.phonepe.com/apis/pg/checkout/v2/pay';

        // ✅ CURL call
        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['request' => $base64]),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',  
                'Authorization: O-Bearer '. $checksum
            ],
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        $responseData = json_decode($response, true);

        // ✅ Extract redirect URL (important)
        $redirectUrl = $responseData['data']['instrumentResponse']['redirectInfo']['url'] ?? null;

        return [
            'status' => 200,
            'message' => 'Payment initiated',
            'transaction_id' => $order_id,
            'redirect_url' => $redirectUrl,
            'response' => $responseData,
            'error' => $error
        ];
    }

    // c246fadd-6523-4def-be15-685fc96aa160
     public function phonePeInitiate2(Request $request)
     {
 
        
 
         $amount         =   (int)($request->get('amount')??100);
         $user_id        =   $request->get('user_id')??'285';
         $user           =   User::find($user_id);

         $redirect_url   =   env('redirect_url_phonepe','https://infowaydigital.com/api/v3/redirectURLPhonePe');
         $callback_url   =   env('callback_url_phonepe','https://infowaydigital.com/api/v3/callbackURLPhonePe');
         $order_id       =    "order12321321";
         $merchant_id    =  "M23QJM8F0LZWZ";
         $phonePeKey     =   'a0f68b13-df20-4496-80a1-7fabd57ad1d6';
        
         \DB::table('paytm')->insert(['action'=>'phonePeInitiate','user_id'=>$user_id ,'paytm' => json_encode($request->all())]);
       


         $data = [
 
             'amount'           => $amount,
             'merchantUserId'       => $user_id,
             'redirectUrl'          => $redirect_url,
             'callbackUrl'          => $callback_url,
             'merchantTransactionId'      => $order_id,
             'merchantId'           => $merchant_id,
             'phonePeKey'           => $phonePeKey,
             'redirectMode'         => "POST",
             'mobileNumber'         => $user->mobile_number,
             'paymentInstrument'    => ['type'=>'PAY_PAGE']
 
         ];
 
         $base64 = base64_encode(json_encode($data));
 
       //  $checksum =  sha256($base64+'/pg/v1/pay'+$phonePeKey)+ "#### + 1; 
 
 
 

         $inputString =  $base64.'/pg/v1/pay'.$phonePeKey; 

         $sha256Hash = hash('sha256', $inputString).'###1';



         // \DB::table('phonepe_logs')->insert([
 
         //     'user_id' => $user_id,
         //     'amount'  => $amount/100,
         //     'tid'     => $order_id,
         //     'code'    => 'PAYMENT_PENDING',
         //     'hash_key' => $sha256Hash
         //     ]);  
       
     
 
         $curl = curl_init();
 
                 curl_setopt_array($curl, array(
                 CURLOPT_URL => 'https://api.phonepe.com/apis/hermes/pg/v1/pay',
                 CURLOPT_RETURNTRANSFER => true,
                 CURLOPT_ENCODING => '',
                 CURLOPT_MAXREDIRS => 10,
                 CURLOPT_TIMEOUT => 0,
                 CURLOPT_FOLLOWLOCATION => true,
                 CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                 CURLOPT_CUSTOMREQUEST => 'POST',
                 CURLOPT_POSTFIELDS => json_encode(['request'=>$base64]),
                 CURLOPT_HTTPHEADER => array(
                        'Content-Type: application/json',
                        'X-VERIFY: '.$sha256Hash,
                        'accept: application/json'
                 ),
                 ));
 
        $response = curl_exec($curl);
        $err = curl_error($curl);
        curl_close($curl);
 
       
 

         return [
 
             'status'        => 200,
             'message'       => 'success',
             'response' => json_decode($response,true),
             'encodedRequest' => $encodedRequest->encodedRequest,
             'transaction_id' => $encodedRequest->transaction_id,
             "system_time" =>  $encodedRequest->system_time,
             "hash" => $sha256Hash,
             "base64" => $base64,
             "uid" => $user_id
         ];
 
    } 

    public function getSquadByMatch($match_id=null){
        $token =  $this->token2;
        $path = $this->cric_url2."matches/".$match_id."/squads?token=".$token;  

     //  dd($path);     

        $data = $this->getJsonFromLocal($path);
       // $this->mainApiCount('squads',$this->token2);

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
            $this->mainApiCount($this->token2,'squads');
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
                    'response' => json_encode($data),
                    'start_time' => time()
        
                ]
            );  

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
            $this->mainApiCount($this->token2,'squads');

            \DB::connection('mysql2')->table('all_matches')->updateOrInsert(
                [
                    'competition_id' => $cid,
                    'action' => 'squads'
                ],
                [
    
                    'action' => 'squads',
                    'competition_id' => $cid,
                    'date_start' => date('Y-m-d'),
                    'date_end' => date('Y-m-d'),
                    'response' => json_encode($data),
                    'start_time' => time()
        
                ]
            ); 

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


    public function updateAllSquad(){

        $com =  Matches::where('status',3)->select('match_id')->get();
        $players = [];

        foreach ($com as $key => $value) {
            $this->getSquad([$value->match_id]);
        }

        echo date('h:i:s');
    }

    public function maxAllowedTeam($request){
        if($request->created_team_id==null){
            return false;
        }    
        $created_team = CreateTeam::whereIn('id',$request->created_team_id)->count();

        $contest = CreateContest::find($request->contest_id);

        $total_spots = $contest->total_spots??0;
        $filled_spot = $contest->filled_spot??0;

        $allowed_team = 0;
        
        if($total_spots>0){
            $allowed_team = $total_spots-$filled_spot;
            if($allowed_team<0){
                 return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'This Contest is already full'
                ];
            }
        } 

        if($allowed_team<$created_team && $total_spots!=0){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Only '.$allowed_team.' spot left!'
            ];

        }elseif($created_team>$total_spots && $total_spots!=0){

            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Max allowed spot exceeded!'
            ];
        }
        elseif($total_spots == $filled_spot && $total_spots!=0){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'Spot already full!'
            ];
        }
            
        $check_join_contest = \DB::table('join_contests')
            ->whereIn('created_team_id',$request->created_team_id)
            ->where('match_id',$request->match_id)
            ->where('user_id',$request->user_id)
            ->where('contest_id',$request->contest_id)
            ->get();
        
        $created_team_id  = $request->created_team_id;
        $contest_id       = $request->contest_id;  

        if(count($created_team_id)==1 AND  $check_join_contest->count()==1){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'This team already Joined'

            ];
        }

        $cc = CreateContest::find($contest_id);

        if($cc && ($cc->total_spots>0 && $cc->filled_spot>=$cc->total_spots)){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'This contest is already full!'

            ];
        }        
        return true;
    }

    public function getRandomHero($match_id,$contest_id,$team_id){
        $ct_user    = JoinContest::where('match_id',$match_id)
                        ->where('contest_id',$contest_id)
                        ->where('created_team_id',$team_id)
                        ->pluck('user_id')
                        ->toArray();

        $ninja_user = User::where('customer_type',3)
                        ->whereNotIn('id',$ct_user)
                        ->orderBy('id','asc')
                        ->pluck('id')
                        ->toArray();

        $index      = array_rand($ninja_user);
        $user_id    = $ninja_user[$index];

        return $user_id;
    }

    public function reverseOrSame(Request  $request)
    {
        $ctest = CreateContest::find($request->contest_id);
        
        $user_id    = $request->user_id;
        $team_id    = $request->team_id;
        $match_id   = $request->match_id;
        $contest_id = $request->contest_id;
        $spot_size  = $request->spot_size;

        $created_team_id    = $request->created_team_id??[];

        //$cont = CreateContest::find($contest_id);

        foreach ($created_team_id as $key => $value) {

            $data['contest_id'] = $contest_id;
            $data['match_id']   = $match_id;
            $data['user_id']    = $user_id; 
            $data['user_id']    = $user_id; 
            $data['team_id']    = $value;
            $data['spot_size']  = $spot_size;

            \DB::table('rev_sames')->insert($data);
        }
    }

    public function cloningContest(Request  $request)
    {
         
        \DB::table('rev_sames')
                        ->where('spot_size','<', 50)
                        ->delete();

        $rev_sames =   \DB::table('rev_sames')
                        ->where('spot_size','>=',111)
                        ->orderBy('spot_size','asc')
                        ->limit(10)
                        ->get();
        $i=1;
        $a= [];
        foreach ($rev_sames as $key => $value) {

            $match = Matches::where('match_id',$value->match_id)->first();

            $t1 = $match->timestamp_start;
            $t2 = time();
            $td = round((($t1 - $t2)/60),2); 

            if($td<0)
            {

               \DB::table('rev_sames')
                            ->where('match_id',$value->match_id)->delete(); 
                continue;
            }

            
            $a[] =$value->id;
            $id = $value->id;
            \DB::table('rev_sames')
                            ->where('match_id',$value->match_id)->delete(); 
               
            try{
                

                $request->merge(['user_id'=>$value->user_id]);
                $request->merge(['team_id'=>$value->team_id]);
                $request->merge(['match_id'=>$value->match_id]);
                $request->merge(['contest_id'=>$value->contest_id]);
                $request->merge(['created_team_id'=>[$value->team_id]]);
                
                $this->cloneJoinContest($request);
                \DB::table('rev_sames')
                            ->where('id',$value->id)->delete();
                           
            }catch(\Exception $e){ 
                print_r('errr');
            }
        } 

        \DB::table('rev_sames')->whereIn('id', $a)->delete(); 
    }


    public function cloneJoinContest(Request  $request)
    {
        $ctest = CreateContest::find($request->contest_id);
        
        $user_id    = $request->user_id;
        $team_id    = $request->team_id;
        $match_id   = $request->match_id;
        $contest_id = $request->contest_id;
        
        // && $ctest->bonus_contest==0
        //  && $ctest->total_spots!=444
        if($ctest!=null && $ctest->total_spots>10){
            $match_id           = $request->match_id;
            //$user_id            = $request->user_id;
            $created_team_id    = $request->created_team_id??[];
            $contest_id         = $request->contest_id;

            // foreach ($created_team_id as $key => $ct_id) {
            //     # code...
            //  $nuser = $this->getRandomHero($match_id,$contest_id,$ct_id);

            //     $request->merge(['team_id'=>$ct_id]);
            //     $request->merge(['with_edit'=>1]);
            //     $request->merge(['ninja_user_id'=>$nuser]);
            //     $this->railLogic($request);
            // }

            if($ctest->total_spots>=111){
                foreach ($created_team_id as $key => $ct_id) {
                # code...
                    $nuser = $this->getRandomHero($match_id,$contest_id,$ct_id);
                    $request->merge(['ninja_user_id'=>$nuser]);
                    $request->merge(['team_id'=>$ct_id]);
                    $request->merge(['with_edit'=>1]);
                    $request->merge(['with_same'=>0]);
                     $this->railLogic($request);
                # with same
                    $request->merge(['team_id'=>$ct_id]);
                    $request->merge(['with_edit'=>0]);
                    $request->merge(['with_same'=>1]);
                    $this->railLogic($request);
                    
                }    
            }
                
        }elseif($ctest!=null && $ctest->total_spots<=4)
        {
            $match_id  = $request->match_id;
            $contest_ids = CreateContest::where('match_id',$match_id)
                ->whereIn('contest_type',[1,13])
                ->get(); 
                
            foreach ($contest_ids as $key => $cid) {

                if($cid->filled_spot >= $cid->total_spots){
                    continue;
                }

                if($ctest!=null && $ctest->total_spots <=4){
                    $created_team_id    = $request->created_team_id??[];
                    
                    $request->merge(['contest_id'=> $cid->id]);

                    foreach ($created_team_id as $key => $ct_id) {
                    # code...

                        $already_c = CreateTeam::where('is_cloned',$ct_id)->first();
                        if($already_c){
                            continue;
                        }
                        $nuser = $this->getRandomHero($match_id,$contest_id,$ct_id);
                        $request->merge(['ninja_user_id'=>$nuser]);

                        $request->merge(['team_id'      => $ct_id]);
                        $request->merge(['with_same'    => 1]);   
                        $this->railLogic($request);
                    }
                }
            }
        }
    }

    public function delRedisId($request)
    {
        Redis::del('getContest_'.$request->match_id.'_'.$request->user_id);
        Redis::del('mywallet_'.$request->user_id);
        Redis::del('mywallet_'.$request->user_id);
    }
    // join contest
    public function  joinContest(Request  $request)
    {   
        $user_details       = User::find($request->user_id);
        Cache::forget('mywallet_'.$request->user_id);
        Cache::forget('getMyContest_'.$request->match_id.$request->user_id);
        Cache::forget('leaderboard_'.$request->get('contest_id'));

        $match_id           = $request->match_id;
      //  Cache::forget('getMyContest_'.$match_id.$request->user_id);
     //   Cache::forget('getMatchHistory_'.$request->user_id);
        /*\DB::table('paytm')->insert([
            'paytm' => json_encode($request->all())
        ]);*/
        /*$stoken = $this->valideToken($request);
        if($stoken){
            return $stoken;
        }*/
        /*if($request->user_id==285)
        {

        }else{
            return [
                    'status'=>false,
                    'code' => 201,
                    'message' => "Kindly try after 4:30PM. Maintainance in progress"
                ];    
        }*/ 
        
        $user_id            = $request->user_id;
        $created_team_id    = $request->created_team_id;
        $contest_id         = $request->contest_id;
        $user_id2           = $request->user_id;
        $date = Carbon::now()->subDays(5);
        
        $contest_type_id = [1,8,23];
        if(Cache::get('cid_'.$request->match_id))
        {
            $cids = Cache::get('cid_'.$request->match_id);
        }else{
            $cids = CreateContest::whereIn('contest_type',$contest_type_id)
                            ->where('match_id',$request->match_id)
                            ->pluck('id')
                            ->toArray();
            Cache::put('cid_'.$request->match_id,$cids,1800);
        }
        $newuser = JoinContest::where('user_id',$user_id)
                ->whereIn('contest_id', $cids)
                ->where('created_at', '>=', $date)
                ->count();

        $cc = CreateContest::find($contest_id); 
        // free Entry
        $free_entry_c = \DB::table('free_entries')
                    ->where('user_id',$user_id)
                    ->where('contest_type_id',$cc->contest_type);
                    

        $free_entry = $free_entry_c->first();
        $free_entry_t = $free_entry_c->limit(count($created_team_id))->get();

        $free_entry_fees = -1;

        $pass_entry  = Cache::get('pass_entry_'.$user_id.$contest_id);
        $multi_entry = Cache::get('multi_entry_'.$user_id.$contest_id);

        if($pass_entry==1)
        {
           $free_entry_fees = 2; 

        }elseif(isset($free_entry->user_id)){
            $free_entry_fees = $free_entry->fees??1;
            if(count($created_team_id)>count($free_entry_t)+1){
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'Only'.count($free_entry_t).'Team Allowed for this contest'
                ];
            }
        }

        $max_t = $this->maxAllowedTeam($request);
        $request->merge(['user_id'=>$user_id2]);
        $user_id            = $request->user_id;  

        $validator = Validator::make($request->all(), [
            'match_id'          => 'required',
            'user_id'           => 'required',
            'contest_id'        => 'required',
            'created_team_id'   => 'required'
        ]);
        // Return Error Message
        if ($validator->fails() || !isset($created_team_id)) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'session_expired'=> $this->is_session_expire,
                    'system_time'   => time(),
                    'status'        => false,
                    "code"          => 201,
                    'message'       => $error_msg[0]??'Team is missing'
                )
            );
        }

        $check_join_contest = \DB::table('join_contests')
            ->whereIn('created_team_id',$created_team_id)
            ->where('match_id',$match_id)
         //   ->where('user_id',$user_id)
            ->where('contest_id',$contest_id)
            ->get();

        if(count($created_team_id)==1 &&  $check_join_contest->count()==1){
            $this->delRedisId($request);
            return [
                'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'This team already Joined'

            ];
        }

        if($cc && ($cc->total_spots!=0 && $cc->filled_spot>=$cc->total_spots)){
            $this->delRedisId($request);
            return [
                'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'This contest already full'

            ];
        }

        $contest_type_id = [1,8,3];
        $new_entry_fees = 1;
        $discount_p = 0;
        if($newuser==0 && $pass_entry!=1){
            if(in_array($cc->contest_type,$contest_type_id)){
                $new_entry_fees = 1;
                $discount_p = 1;
                
            }
        }
        
        if($max_t!==true){
            return $max_t;
            exit();
        }

        $userVald = User::find($user_id);
        $matchVald = Matches::where('match_id',$request->match_id)->first();

        if($matchVald){
            $timestamp = $matchVald->manual_date??$matchVald->timestamp_start;
            $t = time();
          if($t > $timestamp && ($user_id!=285 || $user_id!=262)){
                return [
                    'status'=>false,
                    'code' => 201,
                    'message' => 'Match time up. You can not join'

                ];
            }
        }
        $matchVald = $matchVald->count();

        if(!$userVald || !$matchVald || !$contest_id){
            return [
                'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'Invalid request'

            ];
        }
        $data = [];
        $cont = [];
        $ct = \DB::table('create_teams')
            ->whereIn('id',$created_team_id)->count();
        if($ct)
        {   
            foreach ($created_team_id as $key => $ct_id) {
              //  \DB::beginTransaction();
                $is_full = CreateContest::find($contest_id);                
                if($is_full==null){
                    return [
                        'session_expired'=>$this->is_session_expire,
                        'status'=>false,
                        'code' => 201,
                        'message' => 'invalid contest'
                    ];
                }
                
                if($is_full && $is_full->total_spots>0  && ($is_full->total_spots==$is_full->filled_spot)){
                    $this->delRedisId($request);
                    return [
                        'session_expired'=>$this->is_session_expire,
                        'status'=>false,
                        'code' => 201,
                        'message' => 'This Contest is already full'
                    ];
                }
                // free contest validation, if more than two team 
                $check_max_contest = \DB::table('join_contests')
                        ->where('match_id',$match_id)
                        ->where('user_id',$user_id)
                        ->where('contest_id',$contest_id)
                        ->count(); 

                $contestT = CreateContest::find($contest_id);

                $contestTyp = \DB::table('contest_types')->where('id',$contestT->contest_type)->first();


                if(
                    isset($check_max_contest) 
                    && $check_max_contest>=$contestTyp->max_entries
                    || isset($request->created_team_id) && count($request->created_team_id) >$contestTyp->max_entries
                ){
                    return [
                        'session_expired'=>$this->is_session_expire,
                        'status'=>false,
                        'code' => 201,
                        'message' => "Only $contestTyp->max_entries teams are allowed"
                    ];
                }                

                $check_join_contest = \DB::table('join_contests')
                    ->where('created_team_id',$ct_id)
                    ->where('match_id',$match_id)
                    ->where('user_id',$user_id)
                    ->where('contest_id',$contest_id)
                    ->first();

                if($check_join_contest){
                    continue;
                }
                $data['entry_fees'] = $contestT->entry_fees;
                $data['match_id'] = $match_id;
                $data['user_id'] = $user_id;
                $data['created_team_id'] = $ct_id;
                $data['contest_id'] = $contest_id;

                $ctid  = CreateTeam::find($ct_id);
                $data['team_count'] = $ctid->team_count??null;

                    $total_fee          =  $cc->entry_fees;
                    //$payable_amount     =  $total_fee*$new_entry_fees;
                    $payable_amount     =  $total_fee*$new_entry_fees;  
                    if($free_entry_fees==0 || $free_entry_fees==1){
                        $payable_amount = $free_entry_fees;  
                    }
                    elseif($free_entry_fees==2){
                        $payable_amount = 0;
                        $cda['pass_fees']  = $total_fee; 
                    }elseif($discount_p){
                        $payable_amount     =  (int)($total_fee*$new_entry_fees);  
                    } 

                    //cdm = contest deduct amoount
                    $cda['entry_fees']  = $payable_amount;

                    if($contestT->bonus_contest || $contestT->usable_bonus==100){
                        $deduct_from_bonus  =  $payable_amount*($contestT->usable_bonus/100);
                    }else{
                        $per = $contestT->usable_bonus;
                        $deduct_from_bonus  =  $payable_amount*($per/100);
                    }
                    // extra cash code 1 june 21
                   

                    if($contestT->usable_extra_cash>1){
                        $deduct_from_xtracash  =  $payable_amount*($contestT->usable_extra_cash/100);
                    }else{
                        $deduct_from_xtracash  =  0;
                    }

                    // end
                    $final_paid_amount  =  $payable_amount;
                    //-$deduct_from_bonus-$deduct_from_xtracash;

                     
                    $item = Wallet::where('user_id',$user_id)->get();
                    
                    $bonus_amount = $item->where('payment_type',1)->first();

                    $item->where('payment_type',2)->first();
                    
                    $depos_amount = $item->where('payment_type',3)->first();
                    $ec_amount = $item->where('payment_type',3)->first();
                    
                    $prize_amount = $item->where('payment_type',4)->first();
                    $extra_cash   = $depos_amount;

                    $main_balance = ($prize_amount->amount??0)+($depos_amount->amount??0);
                    $cda['actual_amount'] = $main_balance;
                    $cda['remaining_amount'] = $main_balance-$final_paid_amount;
                    $cda['in_deposit']    = $depos_amount->amount??0; 
                    $cda['in_winning']    = $prize_amount->amount??0; 

                  //  $ref_prize_depos =   
                    $transaction_amt = 0;
                    if($bonus_amount && $bonus_amount->amount>=$deduct_from_bonus && !$contestT->bonus_contest){
                        $final_paid_amount = $final_paid_amount-$deduct_from_bonus;
                        
                        $bonus_amount->amount = $bonus_amount->amount-$deduct_from_bonus;
                        $bonus_amount->save();
                        $cda['bonus_amount']    = $deduct_from_bonus;
                    }elseif($bonus_amount && $bonus_amount->amount<=$deduct_from_bonus){
                         
                        $final_paid_amount = $final_paid_amount-$bonus_amount->amount;
                        $cda['bonus_amount']  = $bonus_amount->amount;
                        $bonus_amount->amount = 0;
                        $bonus_amount->save();
                    }
                    // extra cash
                    if($ec_amount 
                        && $ec_amount->extra_cash>=$deduct_from_xtracash 
                        && $deduct_from_xtracash>0)
                    {
                        $final_paid_amount = $final_paid_amount-$deduct_from_xtracash;

                        $ec_amount->extra_cash = $extra_cash->extra_cash-$deduct_from_xtracash;
                        $ec_amount->save();
                        $cda['extra_cash']    = $deduct_from_xtracash??0;
                    }
                    // end
                    if($contestT->bonus_contest && isset($bonus_amount)){
                         
                       if($bonus_amount->amount>=$final_paid_amount){
                          $bonus_amount->amount = $bonus_amount->amount-$final_paid_amount;
                            $bonus_amount->save(); 
                            $cda['bonus_amount']   = $deduct_from_bonus; 
                       }else{ 

                            $this->delRedisId($request);
                            return [
                                'session_expired' => $this->is_session_expire,
                                'status'  => false,
                                'code'    => 201,
                                'message' => "You don't have sufficient bonus balance!"
                            ];
                       } //
                    }elseif($depos_amount && $depos_amount->amount >= $final_paid_amount){

                        $depos_amount->amount = $depos_amount->amount-$final_paid_amount;
                        $depos_amount->save(); 

                        $cda['deposit_amount'] =$final_paid_amount;
                        
                    }elseif($prize_amount && $prize_amount->amount >= $final_paid_amount && (!isset($depos_amount->amount) || $depos_amount->amount==0)){

                          $prize_amount->amount = $prize_amount->amount-$final_paid_amount;
                        $prize_amount->save();
                        $cda['winning_amount'] =$final_paid_amount;

                    }else{
                        $fpa = $final_paid_amount; 

                        $prize_ref_depo = \DB::table('wallets')
                                ->whereIn('payment_type',[3,4])
                                ->where('user_id',$request->user_id)
                                ->get();  
                        
                        $d = 0; //deposit amount
                        $ra = 0; //remaing amountt    
                        if($prize_ref_depo->count() && $prize_ref_depo->sum('amount') >= $final_paid_amount){
                            
                            $da = Wallet::where('user_id',$request->user_id)
                                      ->where('payment_type',3)
                                      ->sum('amount');
                            $dw = Wallet::where('user_id',$request->user_id)
                                      ->where('payment_type',4)
                                      ->sum('amount');

                            if($da>0){
                                $wt_da = Wallet::where('user_id',$request->user_id)
                                      ->where('payment_type',3)
                                      ->first();
                                $fpa = $final_paid_amount-$da;
                                $wt_da->amount = $wt_da->amount-$da;
                                $wt_da->save() ;  
                                $cda['deposit_amount'] = $da;
                                $d = $da; 
                            }

                            if($dw>0){
                               $wt_wa = Wallet::where('user_id',$request->user_id)
                                      ->where('payment_type',4)
                                      ->first();
                                 $wt_wa->amount = $wt_wa->amount - $fpa;
                                 $wt_wa->save();
                                $cda['winning_amount'] = $fpa;
                            } 
                        }
                        else{

                            if(isset($is_full) && $is_full->entry_fees>0){
                                $this->delRedisId($request);
                                return [
                                    'session_expired'=>$this->is_session_expire,
                                    'status'=>false,
                                    'code' => 201,
                                    'message' => "You don't have sufficient balance!!"
                                ];
                            }
                       }
                    } 
                    $contest_id = $request->contest_id;
                    $match_id = $request->match_id;
                    if(isset($cda)){
                        $cda['match_id']   = $match_id;
                        $cda['contest_id'] = $contest_id;
                        $cda['user_id']    = $request->user_id;
                        $cda['team_id']    = $ct_id??null; 

                        \DB::table('contest_amount_deductions')
                            ->insert($cda);
                            // pass
                        if($free_entry_fees==2){
                            \DB::table('passes_entry')
                            ->insert([
                                'match_id'  => $match_id,
                                'contest_id'=> $contest_id,
                                'user_id'   => $request->user_id 
                            ]);    
                        }    
                        
                    }
                 //   $cc->save(); 
                    // transaction histoory
                    if($free_entry_fees>=0){
                       $total_fee =  $free_entry_fees;
                    } 
                    if($final_paid_amount){
                        $wt             =  new WalletTransaction;
                        $wt->user_id    = $user_id;
                        $wt->amount     = $final_paid_amount??$cda['entry_fees'];
                        $wt->match_id   = $match_id??null;
                        $wt->contest_id = $contest_id??null;
                        $wt->payment_type = 6;
                        $wt->payment_type_string = 'Join Contest';
                        $wt->transaction_id = $match_id.'N'.$contest_id;
                        $wt->payment_mode =  'N';
                        $wt->payment_status =  'Success';
                        $wt->in_deposit   = $cda['in_deposit']??0;
                        $wt->remaining_amount = $cda['remaining_amount']??0;
                        $wt->in_winning   = $cda['in_winning']??0;
                        $wt->debit_credit_status = "-";
                        $wt->total_amount = $cda['actual_amount']??0; 
                        $wt->payment_details = json_encode($request->all()); 
                        
                        $wt->save();
                    } 

                $jcc = \DB::table('join_contests')
                    ->where('match_id',$match_id)
                    ->where('contest_id',$contest_id)
                    ->where('user_id',$user_id)
                    ->count();
               // if($jcc<=$cc->total_spots || $cc->total_spots==0){
                // join contest   
                $data['user_name'] = $userVald->name;
                $data['team_name'] = $userVald->team_name;
                $data['contest_type_name'] = $contestTyp->contest_type??"";

                $t =   JoinContest::updateOrCreate($data,$data);

               // }
                // End spot count
                $cont[] = $data;
                $ct = \DB::table('create_teams')
                    ->where('id',$ct_id)
                    ->update(['team_join_status'=>1]);

                $cc->filled_spot = CreateTeam::where('match_id',$match_id)
                    ->where('team_join_status',1)->count();
                $cc->save();

                $is_full = CreateContest::find($contest_id);
                $c_count = (int)$is_full->is_full+1;
                $is_full->is_full = $c_count;
                $is_full->filled_spot =  $c_count;
                $is_full->save();

                $request->merge(['spot_size' => $is_full->total_spots]); 
            //    \DB::commit();


            if($request->user_id==103855){ 

                $request->merge([
                    'team_id'       => $ct_id,
                    'contest_id'    => $request->contest_id,
                    'match_id'      => $request->match_id
                  //  'with_edit'     => 1
                ]);   

               $this->railLogic($request);
            }

            }
            $this->delRedisId($request);
            Cache::forget('leaderboard_'.$request->get('contest_id'));  
            $message = "Contest Joined successfully!";
            $created_team_id = $request->created_team_id; 
            
            if(count($free_entry_t)>1){
                foreach($free_entry_t as $key => $val)
                {
                    \DB::table('free_entries')
                    ->where('id',$val->id)
                    ->delete();
                }
            }
            if($new_entry_fees==1 && isset($free_entry)){
                \DB::table('free_entries')
                ->where('id',$free_entry->id)
                ->delete();
            }

        }else{
            $cont = ["error"=>"contest id not found"];
            $message = "Something went wrong!";
        }

            $this->reverseOrSame($request);
           
            $this->myjoinedContestCache($request);
            return response()->json(
                [
                'session_expired'=>$this->is_session_expire,    
                'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                "status"=>true,
                "code"=>200,
                "message"=>$message,
                "response"=>["joinedcontest"=>$cont]
            ]
        );
    }
    // get contest details by match id
    public function getMyContest(Request $request){
        
        $match_id  =  $request->match_id;
        $matchVald = Matches::where('match_id',$request->match_id)->count();

        $version_code = null;

        $gmc = Cache::get('getMyContest_'.$request->match_id.$request->user_id);

        if($gmc)
        {
            return $gmc;
        }


        if(!$matchVald){
            return [
                'system_time'=>time(),
                'status'=>false,
                'code' => 201,
                'message' => 'match id is invalid'

            ];
        }

        $join_contests = JoinContest::where('user_id',$request->user_id)
            ->where('match_id',$match_id)->groupBy('contest_id')
            ->pluck('contest_id')->toArray();
            
        $validator = Validator::make($request->all(), [
            //  'match_id' => 'required'
        ]);

        // Return Error Message
        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]
                )
            );
        }

        $contest = CreateContest::with('contestType')
            ->where('match_id',$match_id)
            ->whereIn('id',$join_contests)
            ->orderBy('sort_by','ASC')
            ->get();

        if($contest){
            $matchcontests = [];
            foreach ($contest as $key => $result) {
                if($version_code ==null && $result->bonus_contest){
                   // continue;
                }
                $myjoinedContest = $this->myJoinedTeam($request->match_id,$request->user_id,$result->id);
                $data2['isCancelled'] =   $result->is_cancelled?true:false;
                $data2['maxAllowedTeam'] =   $result->contestType->max_entries??1;
                $data2['usable_bonus'] =   $result->usable_bonus;
                $data2['bonus_contest'] =   $result->bonus_contest?true:false;
                $data2['totalSpots'] =   $result->total_spots;
                $data2['firstPrice'] =   $result->first_prize;
                $data2['totalWinningPrize'] =    $result->total_winning_prize;
                if($result->total_spots==0)
                {
                    $data2['totalSpots'] =   0;

                    $twp = round(($result->filled_spot)*($result->entry_fees)*(0.7));
                    $data2['totalWinningPrize'] = round(($result->filled_spot)*($result->entry_fees)*(0.7));

                    $data2['firstPrice'] =   $twp;
                }
                elseif($result->total_spots!=0 && $result->filled_spot==$result->total_spots)
                {
                  //  continue;
                }

                $data2['contestTitle']      =  $result->contestType->contest_type;
                $data2['contestSubTitle']   =  $result->contestType->description;
                $data2['contestId']         =  $result->id;

                $data2['entryFees']         =  $result->entry_fees;
                $data2['filledSpots']       =  $result->filled_spot;
                $data2['winnerPercentage']  =  $result->winner_percentage;
                $data2['winnerCount']       = $result->winner_count??$result->prize_percentage;
                $data2['maxAllowedTeam']    =  $result->contestType->max_entries;
                $data2['cancellation']      =  $result->cancellation?true:false;
                $data2['maxEntries']        =  $result->contestType->max_entries;
                $data2['joinedTeams']       =  $myjoinedContest;
                $matchcontests[] = $data2;
            }
            $data = $matchcontests;

            $match_info = $this->setMatchStatusTime($match_id);

            $data = [
                    'system_time'=>time(),
                    'match_status' => $match_info['match_status']??null,
                    'match_time' => $match_info['match_time']??null,
                    'system_time'=>time(),
                    "status"=>true,
                    "code"=>200,
                    "message"=>"Success",
                    "response"=>[
                        'my_joined_contest'=>$data
                    ]
                ];

            Cache::put('getMyContest_'.$request->match_id.$request->user_id,$data,30);
            return $data;
        }
    }
    public function getMyContest2(Request $request){

        $match_id =  $request->match_id;

        $matchVald = Matches::where('match_id',$request->match_id)->count();
        
        $join_contests = JoinContest::where('user_id',$request->user_id)
            ->where('match_id',$match_id)->groupBy('contest_id')
            ->pluck('contest_id')->toArray();

        $contest = CreateContest::with('contestType')
            ->where('match_id',$match_id)
            ->whereIn('id',$join_contests)

            ->orderBy('contest_type','ASC')
            ->get();

        if($contest){
            $matchcontests = [];

            foreach ($contest as $key => $result) {

                $myjoinedContest = $this->myJoinedTeam($request->match_id,$request->user_id,$result->id);

                $data2['isCancelled'] =   $result->is_cancelled?true:false;
                $data2['maxAllowedTeam'] =   $result->contestType->max_entries??1;
                $data2['usable_bonus'] =   $result->usable_bonus;
                $data2['bonus_contest'] =   $result->bonus_contest?true:false;
                $data2['totalSpots'] =   $result->total_spots;
                $data2['firstPrice'] =   $result->first_prize;
                $data2['totalWinningPrize'] =    $result->total_winning_prize;
                if($result->total_spots==0)
                {
                    $data2['totalSpots'] =   0;

                    $twp = round(($result->filled_spot)*($result->entry_fees)*(0.5));
                    $data2['totalWinningPrize'] = round(($result->filled_spot)*($result->entry_fees)*(0.5));

                    $data2['firstPrice'] =   round($twp*(0.2));
                }
                elseif($result->total_spots!=0 && $result->filled_spot==$result->total_spots)
                {
                  //  continue;
                }

                $data2['contestTitle'] = $result->contestType->contest_type;
                $data2['contestSubTitle'] =$result->contestType->description;
                $data2['contestId'] =    $result->id;
                //  $data2['totalWinningPrize'] =    $result->total_winning_prize;
                $data2['entryFees'] =    $result->entry_fees;
                // $data2['totalSpots'] =   $result->total_spots;
                $data2['filledSpots'] =  $result->filled_spot;
                //  $data2['firstPrice'] =   $result->first_prize;
                $data2['winnerPercentage'] = $result->winner_percentage;
                $data2['winnerCount'] = $result->winner_count??$result->prize_percentage;
                $data2['maxAllowedTeam'] =   $result->contestType->max_entries;
                $data2['cancellation'] = $result->cancellation?true:false;
                $data2['maxEntries'] =  $result->contestType->max_entries;
                $data2['joinedTeams'] = $myjoinedContest;

                $matchcontests[] = $data2;
            }
            return $matchcontests;
        }
    }

    public function myJoinedTeam($match_id=null,$user_id=null,$contest_id=null)
    {
        $joinMyContest =  JoinContest::with('createTeam','contest')
            ->where('match_id',$match_id)
            ->where('user_id',$user_id)
            ->where('contest_id',$contest_id)
            ->orderBy('ranks','ASC')
            ->get()
            ->transform(function($item,$key){
                 /*$prize = \DB::table('prize_distributions')
                        ->where('match_id' ,$item->match_id)
                        ->where('user_id',$item->user_id)
                        ->where('contest_id',$item->contest_id)
                        ->where('created_team_id',$item->created_team_id)
                        ->first();
                
                if(isset($prize->rank)){
                    $item->prize_amount = $prize->prize_amount??$item->winning_amount;    
                }else{
                    $item->prize_amount = $item->winning_amount??0;
                }*/
                
                $item->prize_amount = $item->winning_amount??0;
                if($item->cancel_contest==1){
                    $item->prize_amount = 0;
                }
                
                return $item;
            });

        $userVald = User::find($user_id);
        if($joinMyContest){
            $matchcontests = [];

            foreach ($joinMyContest as $key => $result) {
                if(isset($userVald)){
                     $uname = $result->team_name??$userVald->name;     
                }else{
                    $uname = "";
                }

                $data2['team_name'] = ($uname).'('.$result->team_count.')';
                // $data2['team'] = $result->createTeam->team_count;
                $data2['createdTeamId'] =    $result->created_team_id;
                $data2['contestId'] =    $result->contest_id;
                $data2['isWinning'] =   false;
                $data2['rank']      = $result->ranks??$result->rank;
                $data2['points']    = $result->points;
                if(isset($result->prize_amount)){
                    $data2['prize_amount']    = $result->prize_amount??0; 
                }
                $matchcontests[] =  $data2 ;
                $data2 = [];
            }
            return $matchcontests;
        }
    }
    public function myJoinedContest($match_id=null,$user_id=null)
    {

        $check_my_contest = \DB::table('join_contests')
            ->where('match_id',$match_id)
            ->where('user_id',$user_id);

        $contest_id = $check_my_contest->pluck('created_team_id');
        $myContest  =     $check_my_contest->first();


        $joinMyContest =  JoinContest::with('createTeam','contest')
            ->where('match_id',$match_id)
            ->where('user_id',$user_id)
            ->whereIn('created_team_id',$contest_id)
            ->get();
        $userVald = User::find($user_id);
        if($joinMyContest){
            $matchcontests = [];

            foreach ($joinMyContest as $key => $result) {
                $t_c = $result->createTeam->team_count;
                $data2['teamName'] = ($result->team_name??$userVald->name).'('.$t_c.')';
                // $data2['team'] = $result->createTeam->team_count;
                $data2['createdTeamId'] =    $result->created_team_id;
                $data2['contestId'] =    $result->contest_id;
                $data2['totalWinningPrize'] =    $result->contest->total_winning_prize;
                $data2['entryFees']     =  $result->contest->entry_fees;
                $data2['totalSpots']    =  $result->contest->total_spots;
                $data2['filledSpots']   =  $result->contest->filled_spot;
                $data2['firstPrice']    =  $result->contest->first_prize;
                $data2['winnerPercentage'] = $result->contest->winner_percentage;
                $data2['winnerCount']   = $result->winner_count??$result->prize_percentage;
                $data2['cancellation']  = $result->contest->cancellation?true:false;
                $contest_type_id = $result->contest->contest_type;

                $contestType = \DB::table('contest_types')
                    ->where('id',$contest_type_id)
                    ->first();

                $data2['maxEntries'] = $contestType->max_entries;

                $matchcontests[$result->contest_type][] = [
                    'contestTitle'=>$contestType->contest_type,
                    'contestSubTitle'=>$contestType->description,
                    'contests'=>$data2
                ];
            }

            $data = [];
            foreach ($matchcontests as $key => $value) {

                foreach ($value as $key2 => $value2) {
                    $k['contestTitle'] = $value2['contestTitle'];
                    $k['contestSubTitle'] = $value2['contestSubTitle'];
                    $k['contests'][] = $value2['contests'];
                }
                $data[] = $k;
                $k= [];
            }

            return $data;

        }
    }
    //date 6 march 25
    public function getWallet(Request $request)
{
    $myArr = [];
    $user = User::find($request->user_id);

    if (!$user) {
        return response()->json(["status" => false, "code" => 201, "message" => 'Wallet not available']);
    }

    if ($invalidToken = $this->valideToken($request)) {
       // return $invalidToken;
    }

    if (!Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp') && $request->allow != 'ninja11') {
      //  return response()->json(["status" => false, "code" => 201, "message" => 'Unauthorised access!']);
    }

    $wallet = Wallet::where('user_id', $user->id)->first();
    $wallet_amount = 0;
    $extra_cash = 0;
    $bonus_amount = 0;
    $prize_amount = 0;
    $referral_amount = 0;
    $deposit_amount = 0;

    if ($wallet) {
        $wallet_amount = $wallet->usable_amount;
        $bonus_amount = $wallet->bonus_amount;
        $prize_amounts = Wallet::where('user_id', $user->id)->get();

        foreach ($prize_amounts as $prize) {
            if ($prize->payment_type == 1) {
                $bonus_amount += $prize->amount;
            } elseif ($prize->payment_type == 4) {
                $wallet_amount += $prize->amount;
                $prize_amount += $prize->amount;
            } elseif ($prize->payment_type == 2) {
                $wallet_amount += $prize->amount;
                $referral_amount += $prize->amount;
            } elseif ($prize->payment_type == 3) {
                $wallet_amount += $prize->amount;
                $deposit_amount += $prize->amount;
                $extra_cash = $prize->extra_cash;
            }
        }
    }

    $myArr = [
        'user_id' => $user->user_name ?? null,
        'bonus_amount' => round($bonus_amount, 2),
        'prize_amount' => round($prize_amount, 2),
        'referral_amount' => round($referral_amount, 2),
        'deposit_amount' => round($deposit_amount, 2),
        'is_account_verified' => $this->isAccountVerified($request),
        'refferal_friends_count' => $this->getRefferalsCounts($request),
        'bank_account_verified' => DB::table('bank_accounts')->where('user_id', $user->id)->value('status') ?? 0,
        'document_verified' => 0,
        'paytm_verified' => DB::table('verify_documents')->where('user_id', $user->id)->where('doc_type', 'paytm')->value('status') ?? 0,
        'wallet_amount' => round($wallet_amount, 2),
        'withdrawal_amount' => DB::table('wallet_transactions')->where('user_id', $user->id)->where('payment_type', 5)->sum('amount'),
        'pmid' => env('paytm_mid', '1PRscwi&opK94P!5'),
        'call_url' => env('call_url', 'https://securegw.paytm.in/theia/paytmCallback?ORDER_ID'),
        'min_deposit' => env('min_deposit', '5'),
        'ninja_point' => DB::table('ninja_rewards')->where('user_id', $user->id)->sum('amount') + DB::table('checkin_rewards')->where('user_id', $user->id)->sum('reward_points'),
        'extra_cash' => round($extra_cash, 2)
    ];

    $response = [
        'min_withdrawal' => env('min_withdrawal'),
        'min_deposit' => env('min_deposit', '5'),
        'pmid' => env('paytm_mid', '1PRscwi&opK94P!5'),
        'call_url' => env('call_url', 'https://securegw.paytm.in/theia/paytmCallback?ORDER_ID'),
        'paytm_show' => false,
        'rozarpay_show' => false,
        'gpay_show' => false,
        'phonepe_show' => true,
        'upi_show' => false,
        'bank_withdrawal' => false,
        'paytm_withdrawal' => false,
        'paytm_withdrawal_btn' => true,
        'upi_withdrawal' => true,
        'status' => true,
        'code' => 200,
        'walletInfo' => $myArr
    ];

    return response()->json($response);
}

    

    //Added by manoj
    public function getWallet1(Request $request){
        $myArr = array();
        $user_id = User::find($request->user_id);

        $stoken = $this->valideToken($request);
        if($stoken){
          //  return $stoken;
        }
        $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');
        if(!$okhttp && $request->allow!='ninja11'){
            return array(
                    'status' => false,
                    'code' => 201,
                    'message' => 'unauthorise access!'
                );
        }

        if($request->user_id==null){
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message"=>'Wallet not available'
                ]
            );   
        }        
        
        $wallet = Wallet::where('user_id',$request->user_id)->first();
        if($wallet){
            $wallet = User::find($wallet->user_id);

            $myArr['wallet_amount']   = $wallet->usable_amount;
            $myArr['bonus_amount']    = $wallet->bonus_amount;
            $myArr['is_account_verified']    = $this->isAccountVerified($request);
            $myArr['refferal_friends_count']    = $this->getRefferalsCounts($request);
            $myArr['user_id']        =  $wallet->user_name??null;

            $myArr['withdrawal_amount']    = 0;
            $myArr['ninja_point']   =   100;
        }else{
            $myArr['wallet_amount']   = 0;
            $myArr['bonus_amount']    = 0;
            $myArr['withdrawal_amount']    = 0;
            $myArr['is_account_verified']    = $this->isAccountVerified($request);
            $myArr['refferal_friends_count']    = $this->getRefferalsCounts($request);
            $myArr['user_id']   = $user_id->user_name??null;
            $myArr['ninja_point'] = 100; 
        }
        $wallet = Wallet::where('user_id',$request->user_id)
                    ->select('user_id')
                    ->get()
                    ->transform(function($item,$key)use($request){
                        $wallet_amount = 0;
                        $item->bonus_amount = 0;
                        $item->prize_amount = 0;
                        $item->referral_amount = 0;
                        $item->deposit_amount = 0;
                        $item->is_account_verified = $this->isAccountVerified($request);
                        $item->refferal_friends_count = $this->getRefferalsCounts($request);
                        
                        $prize_amounts = Wallet::where('user_id',$item->user_id)->get();

                        foreach ($prize_amounts  as $key => $prize_amount) {
                            if($prize_amount->payment_type==1){
                                $item->bonus_amount   = $prize_amount->amount;

                            }
                            elseif($prize_amount->payment_type==4){
                                $wallet_amount = $wallet_amount+$prize_amount->amount;
                                $item->prize_amount   = $prize_amount->amount;
                            }
                            elseif($prize_amount->payment_type==2){
                                $wallet_amount = $wallet_amount+$prize_amount->amount;
                                $item->referral_amount = $prize_amount->amount;
                            }
                            elseif($prize_amount->payment_type==3){
                                $wallet_amount  = $wallet_amount+$prize_amount->amount;   
                                $extra_cash     = $prize_amount->extra_cash;
                                $item->deposit_amount = $prize_amount->amount;
                            }
                        }

                        $bank_account_verified = \DB::table('bank_accounts')
                                                ->where('user_id',$item->user_id)
                                                ->first();

                        $pancard =  \DB::table('verify_documents')
                                                ->where('user_id',$item->user_id)
                                                ->whereIn('doc_type',['pancard'])
                                                ->first();
                        $adharcard =  \DB::table('verify_documents')
                                                ->where('user_id',$item->user_id)
                                                ->whereIn('doc_type',['adharcard'])
                                                ->first();
                        $payment_status =   \DB::table('verify_documents')
                                                ->where('user_id',$item->user_id)
                                                ->where('doc_type','paytm')
                                                ->first(); 
                        if($payment_status){
                            $payment_status = $payment_status->status;
                        }else{
                            $payment_status = 0;
                        }                     

                        $doc_status = 0;                        
                        if($pancard && $adharcard){
                            if($pancard->status==2 || $adharcard->status==2){
                                $doc_status =2;
                            }
                        }elseif ($pancard) {
                           $doc_status =$pancard->status;
                        }
                        elseif ($adharcard) {
                           $doc_status =$adharcard->status;
                        }
                        if(isset($bank_account_verified) && $bank_account_verified->status)
                        {
                            $item->bank_account_verified = $bank_account_verified->status;

                        }else{
                            $item->bank_account_verified = 0 ;
                        }
                        
                        $item->document_verified = $doc_status;
                        $item->paytm_verified = $payment_status;
                        $item->wallet_amount =  round($wallet_amount,2);//sprintf('%0.2f', $wallet_amount);
                        $withdrawal_amount = \DB::table('wallet_transactions')
                                            ->where('payment_type',5)
                                            ->sum('amount');

                        $item->withdrawal_amount = $withdrawal_amount;

                        $user = User::find($item->user_id);
                        $item->user_id = $user->user_name;
                        
                        $item->pmid = env('paytm_mid','WGLKxC65302220855782');
                        $item->call_url = env('call_url', 'https://securegw.paytm.in/theia/paytmCallback?ORDER_ID=');
                        
                        $item->min_deposit = env('min_deposit',15);

                        $re_points = \DB::table('ninja_rewards')
                                    ->where('user_id',$user->id)
                                    ->sum('amount');

                        $chk_points = \DB::table('checkin_rewards')
                                    ->where('user_id',$user->id)
                                    ->sum('reward_points');

                        $item->ninja_point  = $re_points+$chk_points;
                        $item->extra_cash   = $extra_cash??0;  

                        return $item; 
                    });
        
        $only_paytm = 0; //

        $myArr['pmid']          =  env('paytm_mid','WGLKxC65302220855782');
        $myArr['call_url']      =  env('call_url', 'https://securegw.paytm.in/theia/paytmCallback?ORDER_ID='); 
        $myArr['min_deposit']   =  env('min_deposit');
        $myArr['g_pay']         =  env('gpay_id','7974343960-1@okbizaxis');
        $myArr['rozar_key']     =  env('rozar_key','rzp_live_SiMilNQfyJNzJe');
        $myArr['paytm_show']    =  false; 
        $myArr['upi_show']      =  false; 
        $myArr['phonepe_show']  =  true; 
        $myArr['rozarpay_show'] =  true;
        $myArr['gpay_show']     =  true;    

        $mywall = [
                'min_withdrawal'    =>  env('min_withdrawal'),
                'min_deposit'       =>  env('min_deposit'),
                'pmid'              =>  env('paytm_mid','WGLKxC65302220855782'),
                'call_url'          =>  env('call_url', 'https://securegw.paytm.in/theia/paytmCallback?ORDER_ID='),
                'paytm_show'        =>  env('paytm_show','false'),
                'rozarpay_show'     =>  false,
                'gpay_show'         =>  env('gpay_show',false),
                'phonepe_show'      =>  env('phonepe_show',true),
                'upi_show'          =>  env('upi_show',false),   
                'bank_withdrawal'   =>  ($only_paytm<=1)?false:true, 
                'paytm_withdrawal'  =>  ($only_paytm<=1)?false:true,
                'paytm_withdrawal_btn'  =>  true,
                'upi_withdrawal'    =>  ($only_paytm<=1)?true:true,
                "status"            =>  true,
                "code"              =>  200,
                "walletInfo"        =>  $wallet[0]??$myArr
            ];

            /*Redis::set('mywallet_'.$request->user_id, json_encode($mywall),'EX',120);*/

           // Cache::put('mywallet_'.$request->user_id,$mywall,300);

            return response()->json($mywall);
            
    }

    private function getRefferalsCounts(Request $request){

        return \DB::table('referral_codes')
            ->where('refer_by',$request->user_id)
            ->count();
    }

    private function isAccountVerified(Request $request){
        /*
         Documents submitted status code
           1. EMAIL VERIFIED
           2. PAN OR ADHAR
           3. BANK ADDRESS
           4. PAYTM NO
         */
        $emailStatus = 0;
        $documentsStatus = 0;
        $addressProofStatus = 0;
        $paytmStatus = 0;

        $documentsTable = \DB::table('verify_documents')
            ->where('user_id',$request->user_id)
            ->get();
        if($documentsTable){
            foreach ($documentsTable as $key => $value) {
                // print_r($value);
                // die;
                $docType = $value->doc_type;
                if($docType == 'adharcard' OR $docType == 'pancard'){
                    if($value->status ==2){
                        $documentsStatus = 2;
                    }elseif($value->status ==1){
                        $documentsStatus = 1;
                    }elseif($value->status ==3){
                        $documentsStatus = 3;
                    }
                    else {
                        $documentsStatus = 0;
                    }
                }
                if($docType == 'paytm'){
                    $paytmStatus = 2;
                }
            }
        }

        $bankAccounts  = \DB::table('bank_accounts')
            ->where('user_id',$request->user_id)
            ->first();
        if($bankAccounts){
            if($bankAccounts->status ==1){
                $addressProofStatus = 2;
            }else {
                $addressProofStatus = 1;
            }
        }

        $data = array();
        $data['email_verified'] = $emailStatus;
        $data['documents_verified'] = $documentsStatus;
        $data['address_verified'] = $addressProofStatus;
        $data['paytm_verified'] = $paytmStatus;

        return $data;
    }
    
    public function addFreeEntry($deposit_amount=0,$request=null)
    {
        $offer =  \DB::table('offers')
            ->where('deposit_amount',$deposit_amount)
            ->where('status',1)
            ->first();
 

        if($offer && $offer->passes==1){
            \DB::table('passes')->insert(
                [
                    'user_id'   => $request->user_id??285,
                    'amount'    => $deposit_amount,
                    'league_titile'  => "CPL2021",
                    'total_passes'   => $offer->total_passes,
                    'remaining_passes' => $offer->total_passes,
                    'pass_type'      => $offer->pass_type
                ]);
        }elseif($offer && $offer->contest_entry_id==1){
            $total = $offer->total_passes??1;
         //   for($i=1; $i<=$total; $i++){
             /*   \DB::table('free_entries')->insert(
                [
                    'user_id'   => $request->user_id??285,
                    'amount'    => $deposit_amount,
                    'match_id'  => $request->match_id,
                    'fees'      => $offer->contest_entry,
                    'contest_type_id' => $offer->contest_entry_id
                ]);*/
           // }
        }
    }
    // Add Money

    public function addMoney2(Request $request)
    {

        if($request->status_code=="PAYMENT_SUCCESS")
        {
            \DB::table('paytm')->insert(['user_id'=>$request->user_id,'action'=>'addmoney_callback2','paytm' => json_encode($request->all())]);

        }else{
            \DB::table('paytm')->insert(['user_id'=>$request->user_id,'action'=>'addmoney_fail2','paytm' => json_encode($request->all())]);

            return response()->json(
                [
                    "status"    =>  true,
                    "code"      =>  200,
                    "message"  => 'transaction failed'
                ]
            );

        }
      
        Cache::forget('mywallet_'.$request->user_id);
        $actual_amt = $request->deposit_amount;
        $ex         = env('extra_cash',0);
        $extra_cash = $request->deposit_amount;
        $user_id    = $request->user_id??null;
        $bonus      = 0;
        $real_cash  = 0;
        $money      = [5];
        $is_first_time_user = 0; 
 
        
         

      //  $money = [$request->deposit_amount];
        if($request->payment_mode == "upi" || $request->payment_mode == "UPI")
        {
            $request->merge(['user_id'=>$request->user_id]);
            $request->merge(['payment_mode' => 'UPI']); 
        }else{
            if($request->payment_mode == null)
            {
                $request->merge(['user_id'=>$request->user_id]);
                $request->merge(['payment_mode' => 'upi']); 
            } 
        }
        // deposit offers 
        $deposit_offers =   \DB::table('offers')->where('status',1)->get();
        
        $deposit_offer  =   $deposit_offers->where('deposit_amount',$request->deposit_amount)->first();

        $offer_used =  WalletTransaction::where('user_id',$user_id)
                                 ->whereDate('created_at',\Carbon\Carbon::today())
                                 ->where('payment_type',3)
                                 ->where('amount',$request->deposit_amount)
                                 ->count();
        
        $extra_cash         =   0;
        $bonus              =   0;
        if($deposit_offer && $offer_used<=1){
            $extra_cash     = $deposit_offer->extra_cash;
            $entry_fees     = $deposit_offer->contest_entry??0;
            if($deposit_offer->bonus){
               // $money[] =   $deposit_offer->bonus;
                $bonus  =  $deposit_offer->bonus;   
            }
            if($deposit_offer->real_cash>0){
                $real_cash = $deposit_offer->real_cash;
            } 
        }else{
            if($request->deposit_amount>=1000 && $offer_used<=1)
            {
              //  $extra_cash = (int) $request->deposit_amount*(0.1);
            } 
        }
       
        $txn_money = 0;
        
        if($request->payment_mode == "upi" || $request->payment_mode == "UPI")
        {
          
            $request->merge(['order'=>$request->transaction_id]);
            $request->merge(['user_id'=>$request->user_id]);
 
              
                $deposit_amount_1   =   $request->deposit_amount;
                $deposit_amount     =   $request->deposit_amount + $real_cash;
                
                $my_amount=0;
                $total_pass = 0;

                $date = date('Y-m-d');
                
                if(isset($deposit_offer) && $deposit_offer->deposit_amount)
                {
                    $total_pass     =    0;
                    $extra_cash     =    $deposit_offer->extra_cash;
                    $bonus          =    $deposit_offer->bonus??$bonus;
                }
                
                
                if(isset($deposit_offer) && $deposit_offer->total_passes)
                {        
                    // for($i = 1; $i <= $deposit_offer->total_passes; $i++)
                    // {
                    //     \DB::table('free_entries')->insert(
                    //     [
                    //         'user_id'           =>       $request->user_id??285,
                    //         'amount'            =>       $deposit_offer->deposit_amount,
                    //         'match_id'          =>       $deposit_offer->match_id,
                    //         'fees'              =>       $deposit_offer->contest_entry,
                    //         'contest_type_id'   =>       $deposit_offer->contest_entry_id,
                    //         'extra_amount'      =>       $deposit_offer->extra_cash
                    //     ]);    
                    // }
                }
                 
                

                $txt_id     =   $request->transaction_id;
                $order_id   =   $request->transaction_id;

                

                $check_dp = WalletTransaction::where('transaction_id',$txt_id)
                    ->first();
 

                if($check_dp && $check_dp->order_id && $check_dp->transaction_id){
                    $deposit_amount = 0;
                }
                $request->merge(['order_id'=>$order_id]);
                $request->merge(['deposit_amount'=>$deposit_amount]);
            
            
        }else{
            return Response::json(array(
                    'code' => 201,
                    'status' => false,
                    'message' => 'Unauthorized Transaction'
                )
            );
        }

        
        
        try{
            //$this->addFreeEntry($deposit_amount_1,$request);
            }catch(\Exception $e){
             $request->merge(['payment_status'=>'failed']); 
        }
        

        $myArr = [];
        $user = User::find($request->user_id);

        $validator = Validator::make($request->all(), [
            'user_id'           => 'required',
            'deposit_amount'    => 'required',
            'transaction_id'    => 'required',
            'payment_mode'      => 'required'
          //  'payment_status'    => 'required'
        ]);

        // Return Error Message
        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

           return Response::json(array(
                    'code'      => 201,
                    'status'    => false,
                    'message'   => $error_msg[0]
                )
            );
        }

        if($user){
            $check_user = Hash::check($user->id,$user->validate_user);

            $wts = WalletTransaction::where('transaction_id',$request->transaction_id)->count();
            if($wts){
                    return Response::json(array(
                        'code' => 201,
                        'status' => false,
                        'message' => 'Duplicate Transaction found'
                    )
                );
            }
           // dd($request->all());
            if($check_user || $user){
                
                    $wallet     =   Wallet::firstOrNew([
                                    'user_id' => $user->id,
                                    'payment_type' => 3
                                ]);
                
                $message    = "Amount  added successfully";
                $status     = false;
                $code       = 201;

                $msg = "$user->name has added INR $request->deposit_amount using   $request->payment_mode";

                $this->notifyToAdmin($message,  $msg);

                $dp_amount = [0];
                
                if(in_array($deposit_amount,$dp_amount)){
                    $deposit_amount = 0;
                  //  $request->merge(['deposit_amount' => 0]); 
                }

                 
                if($wallet){
                    $deposit_amount = (float) $request->deposit_amount;
                }else{
                    $wallet =  new Wallet; 
                    $deposit_amount = (float) $request->deposit_amount;
                }
                if($wallet){ 
                    \DB::beginTransaction();

                    if($my_amount==0)
                    {
                        $wallet->amount         =  $wallet->amount+$request->deposit_amount+$extra_cash;
                        
                     //   $wallet->extra_cash     =  $wallet->extra_cash+$request->deposit_amount;


                       if($extra_cash){
                            $transaction_ext                    = new WalletTransaction;
                            $transaction_ext->user_id           =  $request->user_id;
                            $transaction_ext->amount            =  $extra_cash;
                            $transaction_ext->transaction_id    =  'EXT'.time();
                            $transaction_ext->payment_mode      =  $request->payment_mode??'online';
                            $transaction_ext->payment_status    =  'success';
                            $transaction_ext->payment_type      =  9;
                            $transaction_ext->payment_type_string = 'Extra cash';

                            $transaction_ext->save();    
                        }


                    }

                    $wallet->payment_type   =  3;
                    $wallet->user_id        =  $user->id;
                    $wallet->validate_user  =  Hash::make($user->id);
                    $wallet->deposit_amount =  $wallet->amount;
                    $wallet->payment_type_string =  'Deposit';
                    
                    $wallet->save(); 

                    $myBlance = Wallet::where('user_id',$wallet->user_id)
                                ->whereIn('payment_type',[3,4])->sum('amount');


                    $myArr['wallet_amount']   = $myBlance??0;
                    $myArr['user_id']         = $wallet->user_id;
                    $amt = $request->deposit_amount;
                    
                    $in_winning = Wallet::where('user_id',$wallet->user_id)
                                ->where('payment_type',4)
                                ->sum('amount');
                    $in_deposit = $wallet->amount;  

                    $transaction          = new WalletTransaction;
                    $transaction->in_winning  =  $in_winning;
                    $transaction->in_deposit  =  $in_deposit;
                    $transaction->remaining_amount  =  $myBlance;
                    $transaction->total_amount  =  $myBlance;
                    $transaction->actual_amount = $deposit_amount_1;
                    $transaction->user_id =  $request->user_id; 
                    $transaction->amount  =  $deposit_amount_1;
                    $transaction->transaction_id =  $request->transaction_id??time();
                    $transaction->payment_mode =  $request->payment_mode??'upi';
                    $transaction->payment_status =  $request->payment_status??'success';
                    $transaction->utr = $request->utr??'';
                    $transaction->payment_type =  3;
                    $transaction->payment_type_string = 'Top Up';
                    $transaction->order_id = $request->order_id;
                    $transaction->save(); 

                  

                    
                    $check_cash_back = WalletTransaction::where('user_id',$request->user_id)
                        ->where('payment_type',3)
                        ->count();
                    if($bonus){
                        $txt = new WalletTransaction;
                        $txt->user_id        =  $request->user_id;
                        $txt->amount         =  $bonus;
                        $txt->transaction_id =  time();
                        $txt->payment_mode   =  $request->payment_mode??'online';
                        $txt->payment_status =  $request->payment_status??'success';
                       // $txt->payment_details =  json_encode($request->all());
                        $txt->payment_type =  8;
                        $txt->payment_type_string =  'Deposit Bonus'; 
                     //   $transaction->order_id = $request->order_id;
                        $txt->save();

                        /// bonus
                        $myBlanceBonus = Wallet::where('user_id',$request->user_id)
                            ->where('payment_type',1)
                            ->first();
                        if($myBlanceBonus)
                        {

                        }else{
                            $myBlanceBonus = new Wallet;
                            $myBlanceBonus->payment_type = 1;
                            $myBlanceBonus->user_id = $request->user_id;
                               
                        }

                        $blance_Bonus = $myBlanceBonus->amount+$bonus??0;
                        $myBlanceBonus->amount = $blance_Bonus;
                        $myBlanceBonus->save();
                       
                       if($bonus){
                            $device_id = $user->device_id??null;
                            $data = [ 
                                        'title' => "Deposit Bonus",
                                        'message' => "Cashback bonus amount added of INR $txt->amount in your wallet."
                                    ];
                          //  $this->sendNotification($data,$device_id);
                       } 
                                                
                    }

                    $message    = "Amount added successfully";
                    $status     = true;
                    $code       = 200;
                    \DB::commit();
 

                }
                $myArr['user_id'] = $user->user_name;



                return response()->json(
                    [
                        "status"=>$status,
                        "code"=>$code,
                        "message" =>$message,
                        "walletInfo"=>$myArr
                    ]
                );
            }else{
                return response()->json(
                    [
                        "status"=>false,
                        "code"=>201,
                        "message" => "user is not valid",
                        "walletInfo"=>$myArr
                    ]
                );
            }

        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "User is invalid",
                    "walletInfo"=>$myArr
                ]
            );
        }
    }

     public function addMoney(Request $request)
    {

        if($request->status_code=="PAYMENT_SUCCESS")
        {
            \DB::table('paytm')->insert(['user_id'=>$request->user_id,'action'=>'addmoney_callback2','paytm' => json_encode($request->all())]);

        }else{
            \DB::table('paytm')->insert(['user_id'=>$request->user_id,'action'=>'addmoney_fail2','paytm' => json_encode($request->all())]);

            return response()->json(
                [
                    "status"    =>  true,
                    "code"      =>  200,
                    "message"  => 'transaction failed'
                ]
            );

        }
      
        Cache::forget('mywallet_'.$request->user_id);
        $actual_amt = $request->deposit_amount;
        $ex         = env('extra_cash',0);
        $extra_cash = $request->deposit_amount;
        $user_id    = $request->user_id??null;
        $bonus      = 0;
        $real_cash  = 0;
        $money      = [5];
        $is_first_time_user = 0; 
 
          

      //  $money = [$request->deposit_amount];
        if($request->payment_mode == "upi" || $request->payment_mode == "UPI")
        {
            $request->merge(['user_id'=>$request->user_id]);
            $request->merge(['payment_mode' => 'UPI']); 
        }else{
            if($request->payment_mode == null)
            {
                $request->merge(['user_id'=>$request->user_id]);
                $request->merge(['payment_mode' => 'upi']); 
            } 
        }
        // deposit offers 
        $deposit_offers =   \DB::table('offers')->get();
        
        $deposit_offer  =   $deposit_offers->where('deposit_amount',$request->deposit_amount)->first();

        $offer_used =  WalletTransaction::where('user_id',$user_id)
                                 ->whereDate('created_at',\Carbon\Carbon::today())
                                 ->where('payment_type',3)
                                 ->where('amount',$request->deposit_amount)
                                 ->count(); 
        
        $extra_cash         =   0;
        $bonus              =   0;
        $entry_fees         =   0;
        $real_cash          =   0;
        $extra_cash25       =   0;
        $rmoney             =   0;

        $user = User::find($request->user_id);
        // offer money
        if($deposit_offer && $offer_used==0){

            $extra_cash     = $deposit_offer->extra_cash;
            $entry_fees     = $deposit_offer->contest_entry??0;
            $bonus          = $deposit_offer->bonus??0; 
            $rmoney         = $deposit_offer->extra_cash??0;

        }else{
            if($request->deposit_amount>1000 && $offer_used==0)
            {
                
                if($user->app_name=="justkhelo")
                {
                    $extra_cash         = (int)( $request->deposit_amount*(0.05));
                 }

                 //else{

                //   //  $extra_cash = (int)( $request->deposit_amount*(0.15)); 

                //     $extra_cash25 = 1;

                //     $rmoney = (int)( $request->deposit_amount*(0.05));
                // }
            }
        } 



       
        $txn_money = 0; 
        
        
        if($request->payment_mode == "upi" || $request->payment_mode == "UPI")
        {
          
            $request->merge(['order'=>$request->transaction_id]);
            $request->merge(['user_id'=>$request->user_id]);
 
              
                $deposit_amount_1   =   $request->deposit_amount;
                $deposit_amount     =   $request->deposit_amount;
                
                $my_amount          =   0;
                $total_pass         =   0;

                $date = date('Y-m-d');
                
                if(isset($deposit_offer) && $deposit_offer->deposit_amount)
                {
                    $total_pass     =    0;  
                }
                
                 
                if(isset($deposit_offer) && $deposit_offer->total_passes)
                {        
                    for($i = 1; $i <= $deposit_offer->total_passes; $i++)
                    {
                        \DB::table('free_entries')->insert(
                        [
                            'user_id'           =>       $request->user_id??285,
                            'amount'            =>       $deposit_offer->deposit_amount,
                            'match_id'          =>       $deposit_offer->match_id,
                            'fees'              =>       $deposit_offer->contest_entry,
                            'contest_type_id'   =>       $deposit_offer->contest_entry_id,
                            'extra_amount'      =>       $deposit_offer->extra_cash,
                            'cid'               =>       129908
                        ]);    
                    }
                }
                 
                 

                $txt_id     =   $request->transaction_id;
                $order_id   =   $request->transaction_id; 
                

                $check_dp = WalletTransaction::where('transaction_id',$txt_id)
                    ->first();
     

                if($check_dp && $check_dp->order_id && $check_dp->transaction_id){
                    $deposit_amount = 0;
                }
                $request->merge(['order_id'=>$order_id]);
                $request->merge(['deposit_amount'=>$deposit_amount]);
            
            
        }else{
            return Response::json(array(
                    'code' => 201,
                    'status' => false,
                    'message' => 'Unauthorized Transaction'
                )
            );
        }

        
        
        try{
            //$this->addFreeEntry($deposit_amount_1,$request);
            }catch(\Exception $e){
             $request->merge(['payment_status'=>'failed']); 
        }
        

        $myArr = [];
       

        $validator = Validator::make($request->all(), [
            'user_id'           => 'required',
            'deposit_amount'    => 'required',
            'transaction_id'    => 'required',
            'payment_mode'      => 'required'
          //  'payment_status'    => 'required'
        ]);

        // Return Error Message
        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

           return Response::json(array(
                    'code'      => 201,
                    'status'    => false,
                    'message'   => $error_msg[0]
                )
            );
        }

        if($user){
            $check_user = Hash::check($user->id,$user->validate_user);

            $wts = WalletTransaction::where('transaction_id',$request->transaction_id)->count();
            if($wts){
                    return Response::json(array(
                        'code' => 201,
                        'status' => false,
                        'message' => 'Duplicate Transaction found'
                    )
                );
            }

           // dd($request->all());
            if($check_user || $user){
                
                    $wallet     =   Wallet::firstOrNew([
                                    'user_id' => $user->id,
                                    'payment_type' => 3
                                ]);  
                
                $message    = "Amount  added successfully";
                $status     = false;
                $code       = 201;

                $msg = "$user->name has added INR $request->deposit_amount using   $request->payment_mode"; 

                 
                if($wallet){
                    $deposit_amount = (float) $request->deposit_amount;
                }else{
                    $wallet =  new Wallet; 
                    $deposit_amount = (float) $request->deposit_amount;
                }

               

                if($wallet){ 
                    \DB::beginTransaction();

                    if($my_amount==0)
                    {
                        if($extra_cash25==0)
                        {
                            $wallet->amount         =  $wallet->amount+$request->deposit_amount+$extra_cash; 
                        }else
                        {
                            $wallet->amount             =  $wallet->amount+$request->deposit_amount+$rmoney;
                            $wallet->extra_cash         =  $wallet->extra_cash+$extra_cash;
                        }
                        


                       if($extra_cash){


                            $transaction_ext                    = new WalletTransaction;
                            $transaction_ext->user_id           =  $request->user_id;
                            $transaction_ext->amount            =  $extra_cash;
                            $transaction_ext->transaction_id    =  'EXT'.time();
                            $transaction_ext->payment_mode      =  'system';
                            $transaction_ext->payment_status    =  'success';
                            $transaction_ext->payment_type      =  9;
                            $transaction_ext->payment_type_string = 'Extra cash';

                            $transaction_ext->save();    
                        }


                    }

                    $wallet->payment_type   =  3;
                    $wallet->user_id        =  $user->id;
                    $wallet->validate_user  =  Hash::make($user->id);
                    $wallet->deposit_amount =  $wallet->amount;
                    $wallet->payment_type_string =  'Deposit';
                    
                    $wallet->save(); 

                    $myBlance = Wallet::where('user_id',$wallet->user_id)
                                ->whereIn('payment_type',[3,4])->sum('amount');


                    $myArr['wallet_amount']   = $myBlance??0;
                    $myArr['user_id']         = $wallet->user_id;
                    $amt = $request->deposit_amount;
                    
                    $in_winning = Wallet::where('user_id',$wallet->user_id)
                                ->where('payment_type',4)
                                ->sum('amount');

                    $in_deposit = $wallet->amount;  

                    $transaction                    = new WalletTransaction;
                    $transaction->in_winning        =  $in_winning;
                    $transaction->in_deposit        =  $in_deposit;
                    $transaction->remaining_amount  =  $myBlance;
                    $transaction->total_amount      =  $myBlance;
                    $transaction->actual_amount     = $deposit_amount_1;
                    $transaction->user_id           =  $request->user_id; 
                    $transaction->amount            =  $deposit_amount_1;
                    $transaction->transaction_id    =  $request->transaction_id??time();
                    $transaction->payment_mode      =  $request->payment_mode??'upi';
                    $transaction->payment_status    =  $request->payment_status??'success';
                    $transaction->utr               = $request->utr??'';
                    $transaction->payment_type      =  3;
                    $transaction->payment_type_string = 'Top Up';
                    $transaction->order_id          = $request->order_id;
                    $transaction->save(); 

                  

                    
                    $check_cash_back = WalletTransaction::where('user_id',$request->user_id)
                        ->where('payment_type',3)
                        ->count();

                    if($bonus){
                        $txt = new WalletTransaction;
                        $txt->user_id        =  $request->user_id;
                        $txt->amount         =  $bonus;
                        $txt->transaction_id =  time();
                        $txt->payment_mode   =  $request->payment_mode??'online';
                        $txt->payment_status =  $request->payment_status??'success';
                       // $txt->payment_details =  json_encode($request->all());
                        $txt->payment_type =  8;
                        $txt->payment_type_string =  'Deposit Bonus'; 
                     //   $transaction->order_id = $request->order_id;
                        $txt->save();

                        /// bonus
                        $myBlanceBonus = Wallet::where('user_id',$request->user_id)
                            ->where('payment_type',1)
                            ->first();

                        if($myBlanceBonus)
                        {

                        }else{
                            $myBlanceBonus = new Wallet;
                            $myBlanceBonus->payment_type = 1;
                            $myBlanceBonus->user_id = $request->user_id;
                               
                        }

                        $blance_Bonus = $myBlanceBonus->amount+$bonus??0;
                        $myBlanceBonus->amount = $blance_Bonus;
                        $myBlanceBonus->save();
                       
                       if($bonus){
                            $device_id = $user->device_id??null;
                            $data = [ 
                                        'title' => "Deposit Bonus",
                                        'message' => "Cashback bonus amount added of INR $txt->amount in your wallet."
                                    ];
                          //  $this->sendNotification($data,$device_id);
                       } 
                                                
                    }

                    $message    = "Amount added successfully";
                    $status     = true;
                    $code       = 200;
                    \DB::commit();
 

                }
                $myArr['user_id'] = $user->user_name;



                return response()->json(
                    [
                        "status"=>$status,
                        "code"=>$code,
                        "message" =>$message,
                        "walletInfo"=>$myArr
                    ]
                );
            }else{
                return response()->json(
                    [
                        "status"=>false,
                        "code"=>201,
                        "message" => "user is not valid",
                        "walletInfo"=>$myArr
                    ]
                );
            }

        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "User is invalid",
                    "walletInfo"=>$myArr
                ]
            );
        }
    }

    public function WinningPrizeDistribution(Request $request)
    {
       // echo date('H:i:s A');

        $match_id = $request->match_id;

        // Step 1: Load all join_contests (avoid transformations for now)
        $joinContests = JoinContest::where('match_id', $match_id)
            ->where('cancel_contest', 0)
            ->orderBy('ranks','asc')
            ->get();

        if ($joinContests->isEmpty()) {
            return ['winningAmount' => 'no entries found'];
        }

        // Step 2: Preload required IDs
        $userIds = $joinContests->pluck('user_id')->unique();
        $teamIds = $joinContests->pluck('created_team_id')->unique();
        $contestIds = $joinContests->pluck('contest_id')->unique();

        // Step 3: Bulk load related models
        $teams = CreateTeam::where('match_id', $match_id)
            ->whereIn('user_id', $userIds)
            ->whereIn('id', $teamIds)
            ->get()
            ->keyBy(function ($item) {
                return $item->match_id . '_' . $item->user_id . '_' . $item->id;
            });

        $users = User::whereIn('id', $userIds)->get()->keyBy('id');

        $contests = CreateContest::with(['contestType', 'defaultContest', 'prizeBreakup'])
            ->where('match_id', $match_id)
            ->whereIn('id', $contestIds)
            ->where('is_cancelled', 0)
            ->get()
            ->keyBy('id');

        // Step 4: Prepare batch updates
        $updates = [];

        foreach ($joinContests as $item) {
            $teamKey = $item->match_id . '_' . $item->user_id . '_' . $item->created_team_id;
            $team = $teams[$teamKey] ?? null;
            $user = $users[$item->user_id] ?? null;
            $contest = $contests[$item->contest_id] ?? null;

            if (!$team || !$user || !$contest) continue;

            $rank = $item->ranks;
            $rank_repeat = $this->checkReaptedRank($rank, $match_id, $item->contest_id);

            // Determine rank amount from prize breakup
            $rank_amount = $this->getAmountPerRank(
                $rank,
                $match_id,
                $contest->default_contest_id,
                $rank_repeat,
                $contest->id,
                $contest->contest_type
            );

            $updates[] = [
                'id' => $item->id,
                'winning_amount' => $rank ? $rank_amount : 0,
                'updated_at' => now(),
            ];
            

        }

        // Step 5: Bulk update using upsert
        collect($updates)->chunk(1000)->each(function ($chunk) {

            JoinContest::upsert($chunk->toArray(), ['id'], ['winning_amount', 'updated_at']);
        });

        return ['winningAmount' => 'updated'];
    }

    // update on WinningPrizeDistribution on 30 nov 24
    public function WinningPrizeDistribution2(Request $request)
    {  
        
        $match_id = $request->match_id;  
        $get_join_contest = JoinContest::where('match_id',  $match_id)
          ->where('cancel_contest',0)
          ->orderBy('ranks','asc')
          ->get();

        if ($get_join_contest->isEmpty()) {
            return ['winningAmount' => 'no entries found'];
        }

        
            $get_join_contest->transform(function ($item, $key)   {
            
            if($item->match_id)
            { 

            $ct = CreateTeam::where('match_id',$item->match_id)
                            ->where('user_id',$item->user_id)
                            ->where('id',$item->created_team_id)
                            ->first();
            
            $user = User::where('id',$item->user_id)->select('id','first_name','last_name','user_name','email','profile_image','validate_user','phone','device_id','name')->first();
             
            $team_id    =   $item->created_team_id;
            $match_id   =   $item->match_id;
            $user_id    =   $item->user_id;
            $rank       =   $item->ranks; 
            $team_name  =   $item->team_count;
            $points     =   $item->points;
            $contest_id =   $item->contest_id;

            $contest    =  CreateContest::with('contestType','defaultContest')
                              ->with(['prizeBreakup'=>function($q) use($rank,$points,$contest_id  )
                                {
                                  $q->where('rank_from','>=',$rank);
                                  $q->orwhere('rank_upto','<=',$rank)
                                  ->where('rank_from','>=',$rank); 
                                }
                              ]
                            )
                          ->where('match_id',$item->match_id)
                          ->where('id',$item->contest_id) 
                          ->where('is_cancelled',0) 
                          ->get() 
                          ->transform(function ($contestItem, $ckey) use($team_id,$match_id,$user_id,$rank,$team_name,$points, $contest_id,$item)  {
                                
                                $contest_type_ID = $contestItem->contest_type;
                                $rank_repeat = $this->checkReaptedRank($rank, $match_id,$contest_id);
                                //get average amount in case of repeated rank
                                $rank_amount = $this->getAmountPerRank($rank,$match_id,$contestItem->default_contest_id,$rank_repeat,$contestItem->id,$contest_type_ID);
                              
                                $update_join_contest = JoinContest::find($item->id);
                                $update_join_contest->winning_amount = $rank?$rank_amount:0;

                                $update_join_contest->save();
                                return $contestItem;

                           }); 
            }
        });
        


        return  ['winningAmount'=>'updated'];
    }
    
    public function prizeDistribution(Request $request)
    {  
        $match_id = $request->match_id;  
        $get_join_contest = JoinContest::where('match_id',  $match_id)
          ->where('winning_amount','>',0)
          ->where('cancel_contest',0)  
          ->get();
            

        $get_join_contest->transform(function ($item, $key)
        {
           
            $ct = CreateTeam::where('match_id',$item->match_id)
                            ->where('user_id',$item->user_id)
                            ->where('id',$item->created_team_id)
                            ->first();
            
            $user = User::where('id',$item->user_id)->select('id','first_name','last_name','user_name','email','profile_image','validate_user','phone','device_id','name')->first();
             
            $team_id    =   $item->created_team_id;
            $match_id   =   $item->match_id;
            $user_id    =   $item->user_id;
            $rank       =   $item->ranks; 
            $team_name  =   $item->team_count;
            $points     =   $item->points;
            $contest_id =   $item->contest_id;

           // $item->createdTeam = $ct;
            $item->user     = $user;
            $item->team_id  = $team_id;
            $item->match_id = $match_id;
            $item->user_id  = $user_id;
            $item->rank     = $rank;
            $item->team_name = $team_name;
            $item->createdTeam = $ct; 

            $contest = CreateContest::find($item->contest_id);
            $filed_spot = $contest->filled_spot??0;

            if($item->contest==null || $filed_spot==1){
                // cancel contest if  spot not filled
                $this->cancelContest($match_id,$contest_id);
            }else{
              //echo $rank.'-'.$match_id.'-'.$user_id.'-'.$team_id.'<br>';
            
            try{
                $prize_dist =  PrizeDistribution::updateOrCreate(
                          [
                            'match_id'        => $match_id,
                            'user_id'         => $user_id,
                            'created_team_id' => $team_id,
                            'team_name'       => $team_name,
                            'contest_id'       => $item->contest_id
                          ],
                          [
                            'points'          => $points,
                            'match_id'        => $match_id,
                            'user_id'         => $user_id,
                            'created_team_id' => $team_id,
                            'rank'            => $rank,
                            'contest_id'        => $item->contest_id,

                            'team_name'        => $item->team_name,
                             
                            'email'            => $item->user->email??null,
                            'device_id'        => $item->user->device_id??null,
                            'contest_name'     => $item->contest->contest_type??null,
                            'entry_fees'       => $item->contest->entry_fees,
                            'total_spots'      => $item->contest->total_spots,
                            'filled_spot'      => $item->contest->filled_spot,

                            'first_prize'        => $item->contest->first_prize,
                            'default_contest_id'=> $item->contest->default_contest_id,
 
                            'prize_amount'      => $item->winning_amount,
                            'contest_type_id'   => $item->contest->contest_type??null,
                            'match_team_id'     => $item->createdTeam->team_id??null,
                            'user_teams'        => $item->createdTeam->teams??null

                          ]
                        );
            }catch(\ErrorException $e){
                 dd($e);
            }

            }
        });
         
        $prize_distributions = PrizeDistribution::where('match_id',$match_id)
            ->get();

        

        $match_id = $request->match_id;  
        $dist_status = $cid = \DB::table('matches')->where('match_id',$match_id)->first();
        
        
        $puser = PrizeDistribution::where('match_id',$match_id)->pluck('user_id')->toArray();
        $device_id = User::whereIn('id',$puser)->pluck('device_id')->toArray();
        if(count($device_id)){
            $data = [
                'action' => 'notify' ,
                'title' => 'Prize is distributed for '.$cid->short_title,
                'message' => 'Check your wallets!'
            ];
            $this->sendNotification($device_id,$data);
            $data['entity_id'] = $match_id;
            $data['message_type'] = 'notify';
                
            \DB::table('user_notifications')->insert($data);
            
        }    
        $prize_distributions->transform(function($item,$key) use($match_id){
              $cid = \DB::table('matches')
                    ->where('match_id',$match_id)
                    ->first();

            //$subject = "You won prize for match - ".$cid->short_title??null;
            if($item->prize_amount > 0){

                $prize_amount = PrizeDistribution::where('match_id',$item->match_id)
                           ->where('user_id',$item->user_id)
                           ->where('contest_id',$item->contest_id)
                           ->where('created_team_id',$item->created_team_id)
                           ->where('team_name',$item->team_name)
                           ->sum('prize_amount');
               

                $wallet_amount_c =  Wallet::where(
                            [
                                'user_id'       => $item->user_id,
                                'payment_type'  => 4
                            ])->first();

                if($wallet_amount_c){
                  $prize_amount = $wallet_amount_c->amount+$prize_amount;
                }
                
                $wallets = Wallet::updateOrCreate(
                            [
                                'user_id'       => $item->user_id,
                                'payment_type'  => 4
                            ],
                            [
                                'user_id'       =>  $item->user_id,
                                'validate_user' =>  Hash::make($item->user_id),
                                'payment_type'  =>  4,
                                'payment_type_string' => 'prize',
                                'amount'        =>  $prize_amount,
                                'prize_amount'  =>  $prize_amount
                            ]
                        );

                $in_winning =  Wallet::where(
                            [
                                'user_id'       => $item->user_id,
                                'payment_type'  => 4
                            ])->sum('amount');

                $in_deposit =  Wallet::where(
                            [
                                'user_id'       => $item->user_id,
                                'payment_type'  => 3
                            ])->sum('amount');

                $tota_balance =  Wallet::where('user_id',$item->user_id)
                                ->whereIn('payment_type',[4,3])
                                ->sum('amount');


                $walletsTransaction = WalletTransaction::updateOrCreate(
                            [
                                'user_id'               => $item->user_id,
                                'prize_distributed_id'  => $item->id
                            ],
                            [
                                'user_id'           =>  $item->user_id, 
                                'payment_type'      =>  4,
                                'payment_type_string' => 'Prize',
                                'amount'            =>  $item->prize_amount,
                                'prize_distributed_id' => $item->id,
                                'payment_mode'      =>  'N',
                                'payment_details'   =>  json_encode($item),
                                'payment_status'    =>  'success',
                                'match_id'          =>  $item->match_id,
                                'contest_id'        =>  $item->contest_id,
                                'transaction_id'    =>  $item->match_id.'N'.$item->contest_id,
                                'in_deposit' => $in_deposit,
                                'in_winning' => $in_winning,
                                'remaining_amount' => $tota_balance,
                                'total_amount' => $tota_balance
                            ]
                        );

               
                $item->user_id = $item->user_id;
                $item->email = $item->email;
            }   
            return $item;
        });
         $match_id = $request->match_id; 
        \DB::table('matches')->where('match_id',$match_id)->update(['current_status'=>1]);

       // $this->affiliateProgram($request);

       // return  Redirect::to(route('match','prize=true'));
    }
    public function checkReaptedRank($rank, $match_id,$contest_id){
        $rank = JoinContest::where('match_id',$match_id)
                            ->where('contest_id',$contest_id)
                            ->where('ranks',$rank)
                            ->count();
        return $rank; 
    }
    /**
    *@var match_id
    *@var contest_id
    *@var rank
    *Description get Amount as per Rank
    */
    public function getAmountPerRank($rank,$match_id=null,$default_contest_id=null,$repeat_rank=1,$contest_id=null, $contest_type_id=null)
    {   
        $rank_from = $rank; 
        $rank_to   =  $rank+($repeat_rank-1);
        $cid =  $default_contest_id; 
        
        $rank_id =0 ;// \DB::table('prize_breakups')->where('default_contest_id',$cid)->whereBetween('rank_upto',[$rank,$rank_to])->count();
        $amt = [];
        $count  =1;
        for($i=$rank_from; $i<=$rank_to; $i++){
             
            $sum_amt1 = \DB::table('prize_breakups')
                ->where('default_contest_id',$cid)
                ->where('contest_type_id',$contest_type_id)
                ->where('rank_from','<=',$i)
                ->where('rank_upto','>=',$i)
                ->sum('prize_amount');

            $sum_amt2 = \DB::table('prize_breakups')
                ->where('default_contest_id',$cid)
                ->where('contest_type_id',$contest_type_id)
                ->where('contest_id',$contest_id)
                ->where('rank_from','<=',$i)
                ->where('rank_upto','>=',$i)
                ->sum('prize_amount');

            if($sum_amt2){
                $sum_amt = $sum_amt2;
            }else{
                $sum_amt = $sum_amt1;
            }   
            if($sum_amt==0){
                $sum_amt1 = \DB::table('prize_breakups')
                ->where('default_contest_id',$cid)
                ->where('contest_type_id',$contest_type_id)
                ->where('rank_from','=',$i)
                ->where('rank_upto','>=',1)
                ->sum('prize_amount');

                 $sum_amt2 = \DB::table('prize_breakups')
                ->where('default_contest_id',$cid)
                ->where('contest_type_id',$contest_type_id)
                ->where('match_id',$match_id)
                ->where('contest_id',$contest_id)
                ->where('rank_from','=',$i)
                ->where('rank_upto','>=',1)
                ->sum('prize_amount');

                if($sum_amt2){
                    $sum_amt = $sum_amt2;
                }else{
                    $sum_amt = $sum_amt1;
                }
            }
            $amt[] = $sum_amt;
        } 
        if($repeat_rank==0){
           $repeat_rank  =1;  
        }

        $prize_amount = array_sum($amt)/$repeat_rank;
        
        return $prize_amount;
       
    }
    /*getScore*/
    public function getScore(Request $request){

        $score_cache = Cache::get('score_'.$request->match_id);
         
        if($score_cache){
            
           // dd($score_cache);

            return $score_cache;
        }

        if($request->match_id==null){
            return [
                "status"=>true,
                "code"=>200,
                "message" => "Score not available"
            ];
        }

        $score = Matches::with(['teama' => function ($query) {
            $query->select('match_id', 'team_id', 'name','short_name','scores_full','scores','overs');
        }])->with(['teamb' => function ($query) {
                $query->select('match_id', 'team_id', 'name','short_name','scores_full','scores','overs');
            }])
            ->where('match_id',$request->match_id)
            ->select('match_id','title','short_title','status','status_str','result','status_note','timestamp_start')
            ->first();
            $match_id = $request->match_id;
            $match_info = $this->setMatchStatusTime($match_id);
            
            $score_data =  [
                'session_expired'=>$this->is_session_expire,
                'system_time'  => time(),
                'match_status' => $match_info['match_status']??null,
                "status"       => true,
                "code"         => 200,
                "message"      => "Match Score",
                "scores"       => $score
            ];

        $t1 = $score->manual_date??$score->timestamp_start;
        $t2 = time();
        $td = round((($t1 - $t2)/60),2);  

        Cache::put('score_'.$match_id,$score_data,60);
        
        return $score_data;

    }

    // generate 20 team from single teams
    public function cloneMyTeam(Request $request){


        $contest_ids = CreateContest::where('match_id',$request->match_id)
                                    ->whereIn('contest_type',[1,2,17,15,4,8,13,14,21])
                                    ->where('total_spots','>=',400)
                                    ->pluck('id');  
                                    

        if($contest_ids)
        {      
            

                    $clone_team =   CreateTeam::where('match_id',$request->match_id)->where('user_id',$request->user_id)->first();
                    
                    if( !$clone_team )
                    {
                        return ['you dont have any team!!'];
                    }

                    $total_team = CreateTeam::where('match_id',$clone_team->match_id)
                                        ->where('user_id',$request->user_id)
                                        ->count();

                    if($total_team>=20)
                    {
                        return ['no need to create more team, already 20'];
                    }
                    
                    $total_team_count = "T".($total_team+1);
                    
                    $data = null;
                    $t = 1;
                    if($clone_team){

                        if($contest_ids->count()==0)
                        {
                            $team_count= 2;
                        }else{
                            $team_count= 20;
                        }

                        for($i=1; $i<$team_count;$i++)
                        {
                            $clone_team2  = new CreateTeam;

                            $t++;

                            $clone_team2->match_id      =   $clone_team->match_id;
                            $clone_team2->user_id       =   $clone_team->user_id;
                            $clone_team2->contest_id    =   $clone_team->contest_id;
                            $clone_team2->team_id       =   $clone_team->team_id;
                            $clone_team2->teams         =   $clone_team->teams;
                            $clone_team2->captain       =   $clone_team->captain;
                            $clone_team2->vice_captain  =   $clone_team->vice_captain;
                        //  $clone_team2->trump         =   $clone_team->trump;

                            $clone_team2->team_count    =   "T".$t;
                            $clone_team2->team_join_status =   $clone_team->team_join_status;
                            $clone_team2->rank          =   $clone_team->rank;
                            $clone_team2->edit_team_count =   $clone_team->edit_team_count;

                        //   dd($clone_team2);


                            $clone_team2->save();
                        }
                        $data = ['created_team_id'=> $clone_team2->id];
                    }
        }            
        return response()->json(
            [
                "status"=>true,
                "code"=>200,
                "message" => "team created",
                "response"=>$data
            ]
        );
    }

    public function uploadImages(Request $request)
    {
        if ($request->file('imagefile')) {

            $photo = $request->file('imagefile');
            $destinationPath = storage_path('uploads');
            $photo->move($destinationPath, time().$photo->getClientOriginalName());
            $photo_name = time().$photo->getClientOriginalName();

            $data = [
                "success"=>"1",
                "msg"=>"Image uplaoded successfully",
                "imageurl"=> 'https://rest.ninja11.in/storage/uploads/'. $photo_name
            ];
        }
        else
        {
            $data=array("successd"=>"0", "msg"=>"Image Type Not Right");
        }
        return $data;
    }


    public function createImage2($request,$userId,$documentsType)
    {   
        try{
            $user_id = $request->user_id;
            $base64 = $request->get('image_bytes'); 
            $image = base64_decode($base64);
            $image_name= time().'.jpg';
            $photo = $request->file('image_bytes');
            if($photo){
                
                $image_name = $userId.'_'.$photo->getClientOriginalName();
                
                if($documentsType=='profile'){
                    $destinationPath = storage_path() . "/image/profile/";
                    $photo->move($destinationPath, $image_name); 
                    $url    =  url::to(asset('storage/image/profile/'.$image_name));
                    $u      =  User::find($user_id);
                    if($u)
                    {   
                        $u->profile_image =$url ;  
                        $u->save();  
                    } 
                    return  $url;
                }else{
                    $destinationPath = storage_path() ."/image/bank_docs/". date("Y-m-d")."/".$userId."/". $documentsType;
                    
                    $photo->move($destinationPath, $image_name);

                    return  url::to(asset('storage'."/image/bank_docs/". date("Y-m-d")."/".$userId."/". $documentsType."/". $image_name));
                }
            }else{
                if($documentsType=='profile'){
                    $path = storage_path() . "/image/profile/" . $image_name;
                    file_put_contents($path, $image); 
                    $url = url::to(asset('storage/image/profile/'.$image_name));
                    $user = User::find($user_id);
                    if($user){
                        $user->profile_image = $url;
                        $user->save();
                    }
                    return $url;
                    
                }else{
                    $path = storage_path() ."/image/bank_docs/". date("Y-m-d")."/".$userId."/". $documentsType."/". $image_name;

                    file_put_contents($path, $image); 

                    return url::to(asset('storage'."/image/bank_docs/". date("Y-m-d")."/".$userId."/". $documentsType."/". $image_name));
                }
            } 
        }catch(Exception $e){
            return false;
        }
        
    }

    public function createImage($request, $userId, $documentsType)
    {
        try {
            $user = User::find($userId);
            if (!$user) {
                return response()->json(["status" => false, "message" => "User not found"], 404);
            }

            $photo = $request->file('image_bytes');
            $imageName = time() . '.jpg';
            $destinationPath = storage_path("/image/");
            
            if ($photo) {
                // Validate MIME type
                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/jpg'];
                if (!in_array($photo->getMimeType(), $allowedMimeTypes)) {
                    return response()->json(["status" => false, "message" => "Invalid image format. Only JPG and PNG are allowed."], 400);
                }
                
                $imageName = $userId . '_' . $photo->getClientOriginalName();
                
                if ($documentsType == 'profile') {
                    $destinationPath .= "profile/";
                } else {
                    $destinationPath .= "bank_docs/" . date("Y-m-d") . "/$userId/$documentsType/";
                }
                
                if (!File::isDirectory($destinationPath)) {
                    File::makeDirectory($destinationPath, 0777, true, true);
                }
                
                $photo->move($destinationPath, $imageName);
            } else {
                $base64 = $request->get('image_bytes');
                $image = base64_decode($base64);
                
                if (!$image) {
                    return response()->json(["status" => false, "message" => "Invalid base64 image"], 400);
                }
                
                $finfo = finfo_open();
                $mimeType = finfo_buffer($finfo, $image, FILEINFO_MIME_TYPE);
                finfo_close($finfo);
                
                if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
                    return response()->json(["status" => false, "message" => "Invalid image format. Only JPG and PNG are allowed."], 400);
                }
                
                if ($documentsType == 'profile') {
                    $destinationPath .= "profile/";
                } else {
                    $destinationPath .= "bank_docs/" . date("Y-m-d") . "/$userId/$documentsType/";
                }
                
                if (!File::isDirectory($destinationPath)) {
                    File::makeDirectory($destinationPath, 0777, true, true);
                }
                
                file_put_contents($destinationPath . $imageName, $image);
            }

            $url = url::to(asset('storage/image/' . ($documentsType == 'profile' ? 'profile/' : "bank_docs/" . date("Y-m-d") . "/$userId/$documentsType/") . $imageName));
            
            if ($documentsType == 'profile') {
                $user->profile_image = $url;
                $user->save();
            }

            return $url;
        } catch (Exception $e) {
            return response()->json(["status" => false, "message" => "Error uploading image", "error" => $e->getMessage()], 500);
        }
    }


    public function uploadbase64Image(Request $request)
    {
        $userId = Input::get('user_id');
        $documentsType = Input::get('documents_type');
        $image_name= time().'.jpg';
        $storagePath = "";
        $internalPath = "";
        if(isset($userId) && isset($documentsType) && $documentsType!='profile'){
            $internalPath = "/image/bank_docs/". date("Y-m-d")."/".$userId."/". $documentsType;

            $storagePath = storage_path() .$internalPath ;
            
            if(!File::isDirectory($storagePath)){
                File::makeDirectory($storagePath, 0777, true, true);
            }
             
            $url =  $this->createImage($request,$userId,$documentsType);
        }else {
            $internalPath  = "/image/".$documentsType;
            $storagePath = storage_path() .  $internalPath;
            
            $url =  $this->createImage($request,$userId,$documentsType);
        }

        if(!File::isDirectory($storagePath)){
            File::makeDirectory($storagePath, 0777, true, true);
        }
        
        $urls =$url; // url::to(asset("/storage".$internalPath.$image_name));

        $request->merge(['urls'=>$urls]);
 

        return response()->json(
            [
                "status" =>true,
                'image_url'   => $urls,
                "message"=> "image uploaded successfully"
            ]
        );

    }
     
    public function sendNotification($token, $data,$notification=null){
     
        $serverLKey = env('serverLKey');
        $fcmUrl = 'https://fcm.googleapis.com/fcm/send';
    
        // Ensure notification is properly set
        $notification = $notification ?? [
            'title' => 'Free Giveaway Available #wpl',
            'body' => 'Join Now!!',
            'sound' => 'default'
        ];
        
            // Set up FCM payload
        if (is_array($token)) {
            $fcmNotification = [
                'registration_ids' => $token, // Multiple tokens
                'notification' => $notification,
                'data' => $data
            ];
        } else {
            $fcmNotification = [
                'to' => $token, // Single token
                'notification' => $notification,
                'data' => $data
            ];
        }

        // Set request headers
        $headers = [
            'Authorization: key=' . $serverLKey,
            'Content-Type: application/json'
        ];

        // Initialize cURL request
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $fcmUrl);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Secure SSL verification
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmNotification));

        // Execute request
        $result = curl_exec($ch);

        if ($result === false) {
            error_log("cURL Error: " . curl_error($ch));
            curl_close($ch);
            return false;
        }

        curl_close($ch);
        return json_decode($result, true); // Return FCM API response
    }
    // notifyAdmin
    public function notifyToAdmin($title, $msg){
        $user_email = ['kroy.aws@gmail.com','kroy.adv@gmail.com'];
        $user = User::select('email','device_id')->whereIn('email',$user_email)->get(); 

        
        $notification =   [
                'title' =>  $title,
                'body' => $msg 
            ]; 

        
        foreach ($user as $key => $result) { 

            try{  
                $token = $result->device_id;
                $this->sendNotificationAndroid($notification, $token);

            }catch(\ErrorException $e){
              $notification = [ 
                    'title' => $title ,
                    'message' =>  $e
                ];
                $token = $result->device_id;
                $this->sendNotificationAndroid($notification, $token);
            }
            
        }
    }
    // Add Money
    public function saveDocuments(Request $request){

       
        $myArr = [];
        $user = User::find($request->user_id);
        $validator = Validator::make($request->all(), [
            'documentType' => 'required'
        ]);


       // $this->eventLog($request);
        // Return Error Message
        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'code' => 201,
                    'status' => false,
                    'message' => $error_msg[0]
                )
            );
        }
        if($user){
            $documentType = $request->documentType;

            $helper = new Helper;

            $msg = "$user->name has uploaded $documentType";

           // $helper->notifyDocUploadToAdmin('Document uploaded',$msg);

            if($documentType=='pancard'){
                $data = array();
                $data['user_id'] = $request->user_id;
                $data['doc_type'] = $documentType;
                $data['doc_number'] = $request->panCardNumber;
                $data['doc_name'] = $request->panCardName;
                $data['doc_url_front'] = $request->pancardDocumentUrl;
                $data['status']  =1;
                \DB::table('verify_documents')->updateOrInsert(['user_id' => $request->user_id,'doc_type'=>$documentType],$data);
               
            }
            if($documentType=='adharcard'){
                $data = array();
                $data['user_id'] = $request->user_id;
                $data['doc_type'] = $documentType;
                $data['doc_number'] = $request->panCardNumber;
                $data['doc_name'] = $request->panCardName;
                $data['doc_url_front'] = $request->aadharCardDocumentUrlFront;
                $data['doc_url_back'] = $request->aadharCardDocumentUrlBack;
                $data['status']  =1;

                \DB::table('verify_documents')->updateOrInsert(['user_id' => $request->user_id,'doc_type'=>$documentType],$data);
               
            }
            if($documentType=='paytm'){
                $data = array();
                $data['user_id'] = $request->user_id;
                $data['doc_type'] = $documentType;
                $data['doc_number'] = $request->paytmNumber;
                $data['status']  =1;
                \DB::table('verify_documents')->updateOrInsert(['user_id' => $request->user_id,'doc_type'=>$documentType],$data);
                
            }
            if($documentType=='passbook'){
                    $data = array();
                    $data['user_id'] = $request->user_id;
                    $data['bank_name'] = $request->bankName;
                    $data['account_name'] = $request->accountHolderName;
                    $data['account_number'] = $request->accountNumber;
                    $data['ifsc_code'] = $request->ifscCode;
                    $data['account_type'] = $request->accountType;
                    $data['bank_passbook_url'] = $request->bankPassbookUrl;
                    $data['status']  =1;
                    \DB::table('bank_accounts')->updateOrInsert(['user_id' => $request->user_id],$data);
                  
                }
            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Document updated successfully"
                ]
            );
        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "User is invalid"
                ]
            );
        }
    }


    public function saveAllDocuments(Request $request){



        $myArr = [];
        $user = User::find($request->user_id);
        $validator = Validator::make($request->all(), [
            'documentType' => 'required'
        ]);
        //$request->merge(['upi_id'=>$request->upi_id]);

       // $this->eventLog($request);
        // Return Error Message
        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'code' => 201,
                    'status' => false,
                    'message' => $error_msg[0]
                )
            );
        }
        if($user){
            $documentType = $request->documentType;

            $helper = new Helper;

            $msg = "$user->name has uploaded $documentType";

          //  $helper->notifyDocUploadToAdmin('Document uploaded',$msg);

            if($documentType=='pancard'){
                $data = array();
                $data['user_id'] = $request->user_id;
                $data['doc_type'] = 'pancard';
                $data['doc_number'] = $request->panCardNumber;
                $data['doc_name'] = $request->panCardName;
                $data['doc_url_front'] = $request->pancardDocumentUrl;
                $data['status']  =1;

                $data['upi_id']  = $request->upi_id;
                \DB::table('verify_documents')->insert($data);


                $data = array();
                $data['user_id'] = $request->user_id;
                $data['doc_type'] = 'Paytm';
                $data['doc_number'] = $request->paytmNumber;
                $data['status']  =1;
                $data['upi_id']  = $request->upi_id;
                \DB::table('verify_documents')->insert($data);
                
                $data = array();
                    $data['user_id'] = $request->user_id;
                    $data['bank_name'] = $request->bankName;
                    $data['account_name'] = $request->accountHolderName;
                    $data['account_number'] = $request->accountNumber;
                    $data['ifsc_code'] = $request->ifscCode;
                    $data['account_type'] = 'passbook';
                    $data['bank_passbook_url'] = $request->bankPassbookUrl;
                    $data['status']  =1;
                    $data['upi_id']  = $request->upi_id;
                    
                    \DB::table('bank_accounts')->updateOrInsert(['user_id' => $request->user_id],$data);   
            }
            
           
            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Document updated successfully"
                ]
            );
        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "User is invalid"
                ]
            );
        }
    }

    public function updateProfile(Request $request){

        $myArr = [];
        $user = User::find($request->user_id);


        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'city' => 'required',
            'dateOfBirth' => 'required',
            'email' => 'required',
            'gender' => 'required',
            'mobile_number' => 'required',
            'name' => 'required',
            'pinCode' => 'required',
            'state' => 'required'
        ]);


        // Return Error Message
        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'code' => 201,
                    'status' => false,
                    'message' => $error_msg
                )
            );
        }

        if($user){
            $data = array();
            $data['user_id'] = $request->user_id;
            $data['city'] = $request->city;
            $data['dateOfBirth'] = $request->dateOfBirth;
            $data['gender'] = $request->gender;
            $data['pinCode'] = $request->pinCode;
            $data['state'] = $request->state;

            \DB::table('users')
                ->update($data)
                ->where('user_id',$request->user_id)
                ->where('email',$request->email);
            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Document updated successfully"
                ]
            );
        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "User is invalid"
                ]
            );
        }
    }
    /*Player sell percetages*/
    public function playerAnalytics(Request $request){

        $teams = $request->teams;
        if($teams){
            $data['match_id'] = $request->match_id;
            $data['created_team_id'] = $request->created_team_id;
            $data['captain'] = $request->captain;
            $data['vice_captain'] = $request->vice_captain;
            $data['user_id'] = $request->user_id;
            \DB::table('player_analytics')
                    ->where('created_team_id',$request->created_team_id)
                    ->where('user_id',$request->user_id)
                    ->delete();

            foreach ($teams as $key => $result) {
                if($result==0){
                    continue;
                }
                $data['player_id'] = $result;   
                \DB::table('player_analytics')
                        ->insert($data);
            }
            return ['Player details added'];
        }
    }

    /*getMyPlayedMatches*/
    public function getMyPlayedMatches(Request $request)
    {
         $join_contest =\DB::table('join_contests')->where('user_id',$request->user_id)
                    ->get();
    }

    public function matchInfo($request, $method=null){
        $data['match_id']   = $request->match_id??null;
        $data['method']     = $method;
        $data['content']    = json_encode($request->all());

        \DB::table('match_contents')->insert($data);
    }

    /*captureScreenTime*/
    public function captureScreenTime(Request $request){
        $user_id = $request->user_id;
        $screen_name = $request->screen_name;

        $start_time = date('Y-m-d h:i:s');
        $end_time   = date('Y-m-d h:i:s');

        $data['user_id']        =  $user_id;
        $data['screen_name']    =  $screen_name??null;
        $data['start_time']     =  $start_time;

        $last_id = \DB::table('capture_screen_times')->insertGetId($data);
        
        $id =  \DB::table('capture_screen_times') //->where('id', '<=', $last_id)
            ->orderBy('id', 'desc')
            ->skip(1)
            ->take(1)
            ->first();
        if($id){
            \DB::table('capture_screen_times')
                    ->where('id',$id->id)
                    ->update(
                        [
                            'end_time'=>$end_time
                        ]
                    );    
        }    
        return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Screen captured"
                ]
            );

    }
    /*Playing history*/
    public function getPlayingMatchHistory(Request $request){

        $user_id = $request->user_id;
        Cache::forget('mywallet_'.$request->user_id);
        $user = User::where('id',$user_id)->get('id as user_id');
        $user->transform(function($item, $key){

            $join_contest = JoinContest::where('user_id',$item->user_id);
            $total_contest_joined = $join_contest->count();
            $total_unique_contest = $join_contest->groupBy('contest_id')->count();

            $total_affiliate_commission = round(( \DB::table('affiliate_programs')
                                            ->where('user_id',$item->user_id)
                                            ->sum('amount')),0);
            
            $total_match_played = JoinContest::where('user_id',$item->user_id)
                    ->select(\DB::raw('count(*)'))
                    ->groupBy('match_id')->get()->count();
            //->groupBy('created_team_id')
            $total_team =  JoinContest::where('user_id',$item->user_id)
                          //  ->select(\DB::raw('count(*)'))
                            ->get()->count();

            $total_match_win = $prize = \DB::table('join_contests')
                        ->where('user_id',$item->user_id)
                        ->where('winning_amount','>', 0) 
                        ->count();

            $total_winning_amount = $prize = JoinContest::where('user_id',$item->user_id)
                        ->where('winning_amount','>', 0)
                        ->sum('winning_amount');

            $transaction = WalletTransaction::where('user_id',$item->user_id);


            $item->total_team_joined    = $total_team;   
            $item->total_match_played   = $total_match_played;
            $item->total_contest_joined = $total_contest_joined;
            $item->total_unique_contest = $total_unique_contest;
            $item->total_match_win      = $total_match_win;
            $item->total_winning_amount = (int)$total_winning_amount;
            $item->total_my_deposit     = $transaction->where('payment_type',3)
                                            ->sum('amount');

            $item->total_affiliate_commission = $total_affiliate_commission;
            


             $transaction = WalletTransaction::where('user_id',$item->user_id)
                            ->where('payment_type',5)->sum('amount');
             $item->total_my_withdrawal = $transaction; 
            
            return $item;  
        });  
        if(isset($user) && isset($user[0])){
             return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Record Found",
                    "response" => $user[0]
                ]
            );
        } else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "No record found"
                ]
            );
        } 
    }

    public function verificationNinja(Request $request){  

        //\Crypt::encryptString($request->player_id);

        $user = User::where('user_name',$request->get('match_id'))->first();
        $uid = $user->id??0;

        try{
             
         if($uid){
           $pancard =  \DB::table('verify_documents')->where('user_id',$uid)->where('doc_type','pancard')->first();
           $paytm =  \DB::table('verify_documents')->where('user_id',$uid)->where('doc_type','paytm')->first();

           $bank_accounts =  \DB::table('bank_accounts')->where('user_id',$uid)->first();


           $data['pan_number'] = $pancard->doc_number;
           $data['pan_name'] = $pancard->doc_name;
           $data['pan_url'] = $pancard->doc_url_front;
           $data['bank_name'] = $bank_accounts->bank_name;
           $data['account_name'] = $bank_accounts->account_name;
           $data['account_number'] = $bank_accounts->account_number;
           $data['ifsc_code'] = $bank_accounts->ifsc_code;
           $data['account_type'] = $bank_accounts->account_type;
           $data['bank_url'] = $bank_accounts->bank_passbook_url;
           $data['paytm_number'] = $paytm->doc_number;
           $data['upi_id'] = $paytm->upi_id;
           
                       

        }   

        }catch(\ErrorException $e){
             
        }

        return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "verification status",
                    "response" => $data??[]
                ]
            ); 
            
    }
    public function verification(Request $request){    
        $user = User::find($request->user_id);

        if($user){
           $verify_documents =  \DB::table('verify_documents')->where('user_id',$user->id)->get();
           $bank_accounts =  \DB::table('bank_accounts')->where('user_id',$user->id)->first();
           
            foreach ($verify_documents as $key => $vd) {
                $s = "Pending";
                
                if($vd->status==1){
                    $s="Pending";
                }
                elseif($vd->status==2){
                    $s="Approved";
                }
                elseif($vd->status==3){
                    $s="Rejected";
                } 

                if($vd->doc_type=='paytm'){  
                    $status['paytm'][] = [
                    'status' =>  $vd->status, 
                    'message' => $s,
                    'data' => $vd
               ] ;

                }else{
                    $status['documents'][] = [
                    'status' =>  $vd->status,
                    'message' => $s,
                    'data' => $vd
               ] ;
                } 
            } 
            if(isset($bank_accounts)){
                
                if($bank_accounts->status==1){
                  $s = "Pending";  
                }
                elseif($bank_accounts->status==2){
                  $s = "Approved";  
                }
                elseif($bank_accounts->status==3){
                  $s = "Rejected";  
                }else{
                  $s = "Not uploaded";  
                }

                $status['bank_accounts'][] = [
                'status' =>  $bank_accounts->status,
                'message' => $s,
                'data' => $bank_accounts
           ] ;

            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "verification status",
                    "response" => $status
                ]
            );

            } 
            
        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "Verification is pending"
                ]
            );
        }
    }
    /*
    Automate Create Contest
    contest will create as its full
    */
    public function automateCreateContest(){
        //return false; 
        // $contest = CreateContest::whereColumn('total_spots','filled_spot')
        //     ->where('cancellation',1)
        //     ->where('is_cloned',0)
        //     ->where('total_spots','>',0)
        //     ->get(); 

       $contest = collect(\DB::select('CALL GetFilledContests()'));

       
       
        if(empty($contest))
        {
            return false;
        } 
         
        $match_id = $contest->pluck('match_id')->toArray(); 

        $match = Matches::whereIn('match_id',$match_id)->get(['match_id']);

        $match->transform(function($item,$key)use($contest){
            $contest_id = $contest->where('match_id',$item->match_id)->first();
            $contest_copy = CreateContest::find($contest_id->id);
          
            $contest_copy->is_cloned = 1;
            $contest_copy->save();
            $contest_copy = $contest_copy->toArray();
            $contest_copy['filled_spot'] = 0;
            $contest_copy['is_cloned'] = 0;
            $contest_copy['is_full'] = 0;
            $contest_copy['is_free'] = 0;
            $contest_copy['is_reversed'] = 0;

            \DB::table('create_contests')->insert($contest_copy);
            $item->contest = $contest_copy;
            return $item;
        });
        return "Contest cloned";          
    }
    /*get playing11*/
    public  function getPlaying11(Request $request)
    {   
        $min24 = strtotime('+120 minutes', time());
        $matches = Matches::whereIn('status',[1,3])
                 //  ->whereDate('date_start',\Carbon\Carbon::today())
                    ->where('is_cancelled',0)
	          //  ->whereIn('format',[1,3,6,7,8])
                    ->where('match_abondon',0)
                    ->where('timestamp_start','>=',time())
                    ->where('timestamp_start','<=',$min24)
                    ->Orwhere(function($q)use($request){
                        $q->where('match_id',$request->match_id);
                    })
                    //->where('is_flash_back',1) 
                    ->get(['match_id','timestamp_start','status','manual_date']);


        $request_match = $request->match_id;
        
        $data_p = 'No playing 11';             
        foreach ($matches as $key => $match) {

            $match_id = $match->match_id;
            $match = Matches::where('match_id',$match_id)->first();

            if($match->is_flash_back==2){
               // continue;
            }

            $t1 = $match->manual_date??$match->timestamp_start;
            $t2 = time();
            $td = round((($t1 - $t2)/60),2);   
            

            if($td > 45 ){
                continue;
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

            if(($p11a<11  || $p11b<11) && $td<=45){
                
                    //This method for sending notifications 
                if($p11a && $p11b  && ($td%5==0 || $td<10)){
                        $this->isLineUp($match_id);   
                }
                
            } 
           
            if($p11a<11 && $p11b<11){
                if($td<4)
                {
                    $timestamp_start = strtotime('+5 minutes', $match->timestamp_start);
                    $match->timestamp_start=$timestamp_start;
                }
            }
            

            // check both team has lineup 
            if($p11a>=11 && $p11b>=11 && $td>=0){

                Cache::forget('getPlayer_'.$request->match_id);

                $data_p = "Playing11";
                if($match->status==1 || $match->status==1){
                    $match_obj = Matches::firstOrNew(
                        [
                            'match_id'=>$match_id
                        ]
                    );
                    if($match_obj->status==3 && $match_obj->is_lineup==2){
                        continue;   
                    }
                    $match_obj->status =  3;
                     $match->is_lineup =  2;
                    $match->order_by   =  2;
                    $match_obj->save();
                    continue;
                }
                continue;
            }
            $is_playing_11 = false;
            # code...

            try{ 
                $token =  $this->token;
                $path = $this->cric_url."matches/".$match_id."/squads?token=".$token;
                //dd($path);
                $data = $this->getJsonFromLocal($path);
                $this->mainApiCount($this->token2,'squads');

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
            // update team a players
            $teama = $data->response->teama;
            
            if(isset($teama)){

                foreach ($teama->squads as $key => $squads) {
                    $teama_obj = TeamASquad::firstOrNew(
                        [
                            'team_id'=>$teama->team_id,
                            'player_id'=>$squads->player_id,
                            'match_id'=>$match_id
                        ]
                    );

                    $teama_obj->playing11 =  $squads->playing11;
                    $teama_obj->role =  $squads->role;
                    $teama_obj->save();
                    $data_p = $squads->playing11;

                    if($teama_obj->playing11=="true"){
                          
                       $match->is_lineup=2;
                       $match->order_by =2; 
                       \DB::table('players')->where('match_id',$match_id)
                                ->where('pid',$teama_obj->player_id)
                                ->where('team_id',$teama_obj->team_id)
                                ->update([
                                    'playing11' => $teama_obj->playing11
                                ]); 
                    }
                }
            }   
            
            $teamb = $data->response->teamb;

            if(isset($teamb)){
                foreach ($teamb->squads as $key => $squads) {

                    $teamb_obj = TeamBSquad::firstOrNew([
                        'team_id'=>$teamb->team_id,
                        'player_id'=>$squads->player_id,
                        'match_id'=>$match_id
                    ]);

                    $teamb_obj->playing11 =  $squads->playing11;
                    $teamb_obj->role =  $squads->role;
                    $teamb_obj->save();
                    $data_p = $squads->playing11;

                    if($teama_obj->playing11=="true"){  
                        $match->is_lineup=2;
                        $match->order_by =2;
                        \DB::table('players')
                                ->where('match_id',$match_id)
                                ->where('pid',$teamb_obj->player_id)
                                ->where('team_id',$teamb_obj->team_id)
                                ->update(
                                    [
                                    'playing11' => $teamb_obj->playing11
                                    ]
                                );

                    }
                }   
            }
           
            $match->save(); 
 
        }

        return $data_p;
    }

    public function mainApiCount($token,$action=null)
    {
        \DB::connection('mysql2')->table('main_api_count')->insert([
            'action' => $action,
            'token' => $token
        ]);
    }

    public function recheckPlaying11($request){
        $match_id = $request->match_id;
        try{ 

            $token  =   $this->token2;
            $path   =   $this->cric_url2."matches/".$match_id."/squads?token=".$token;
           // $this->mainApiCount('squads',$this->token2);
            
            $data   =   $this->getJsonFromLocal($path);

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

            $teama  =   $data->response->teama;


            $mat = Matches::where('match_id',$match_id)
                        ->where('is_flash_back',1)
                        ->first();

            if($mat && $mat->is_flash_back==2){
                return false;
            }

            if(isset($teama)){
                foreach ($teama->squads as $key => $squads) {
                    $teama_obj = TeamASquad::firstOrNew(
                        [
                            'team_id'=>$teama->team_id,
                            'player_id'=>$squads->player_id,
                            'match_id'=>$match_id
                        ]
                    );

                    $teama_obj->playing11 =  $squads->playing11;
                    $teama_obj->role =  $squads->role;
                    $teama_obj->save();
                    if($teama_obj->playing11=="true"){
                            $is_playing1==true;
                    }
                }
            }   
            //getSquad($match_ids=null,$cid=null)           
            $teamb = $data->response->teamb;

                if(isset($teamb)){
                    foreach ($teamb->squads as $key => $squads) {

                        $teamb_obj = TeamBSquad::firstOrNew([
                            'team_id'=>$teamb->team_id,
                            'player_id'=>$squads->player_id,
                            'match_id'=>$match_id
                        ]);

                        $teamb_obj->playing11 =  $squads->playing11;
                        $teamb_obj->role =  $squads->role;
                        $teamb_obj->save();

                        if($teamb_obj->playing11=="true"){
                            $is_playing2==true;
                        }
                    }   
                }
            }catch(\ErrorException $e){
                //continue;
            }
            if(isset($is_playing1) && isset($is_playing2))
            {
                 return  true;
            }else{
                return false;
            }
           
    }

    public function isLineUp($match_id=null){

        $matches = Matches::where('match_id',$match_id)
                    ->where('is_flash_back',1)
                    ->whereDate('date_start',\Carbon\Carbon::today())
                        ->get()
                        ->transform(function($item,$key){
                            
                            $t1 = $item->manual_date??$item->timestamp_start;
                            $t2 = time();
                            //time diff
                            $td = round((($t1 - $t2)/60),2);    

                            $lineup = \DB::table('team_a_squads')->where('match_id',$item->match_id)
                                ->where('playing11',"true")->count();
                            
                            $device_id = User::whereNotNull('device_id')->pluck('device_id')->toArray();
                                                                  
                            if($td>0 && $td%5==0){
                                $title = "🏏🏏 $item->short_title - $item->format_str 🏏🏏 ".date('h:i A',$t1);
                                
                                $msg = 'Contest is filling fast. Create your team and join the contest. Hurry up!!';

                                $helper = new Helper;
                                $helper->notifyToAll($title,$msg);
                            }
                            //&& $td%5==0
                            if($lineup && $td > 1){ 
                                
                                $td = (int)$td;

                                if($td>30){
                                    $msg = "$td minute left. Create, Join or edit  your team";
                                }else{
                                    $msg = "Last $td minute left.Create, Join or edit  your team. Hurry Up!!";
                                }
                                $title = "🏏 $item->short_title  🏏 Deadline - ".date('h:i A',$t1);
                                
                                $helper = new Helper;
                                $helper->notifyToAll($title,$msg);
                               
                                //$this->sendNotification($device_id, $data);
                                return $item; 
                            }               
                        }); 


        return 'Matches lined up';
    }
    /*Match auto cancel if not filled*/
    public function matchAutoCancel(){
          
        $cancel_match = Matches::where('status',3)
                       ->get()
                        ->transform(function($item,$key){
                            $t1 = $item->manual_date??$item->timestamp_start;
                            $t2 = time();
                            $td = round((($t1 - $t2)/60),2); 
                           
                        if($td<=(-5)){
                           
                            $contests = CreateContest::where('match_id',$item->match_id)
                                        ->where('cancellation',1)
                                        ->where('is_cancelled',0)
                                        ->get()
                                        ->transform(function($item,$key){
                                          
                                    $total_winning_prize = $item->total_winning_prize;
                                    $total_amount_recvd = $item->filled_spot*$item->entry_fees;
                                    
                                    if($item->entry_fees!=0 && $total_winning_prize > $total_amount_recvd && $item->total_winning_prize!=0
                                        ){

                                        //&& $item->entry_fees!=5
                                        $match_id = $item->match_id;
                                        $contest_id = $item->id;

                                        $over_starta = \DB::table('team_a')->where('overs','>',1)->where('match_id',$match_id)->count();
                                        $over_startb = \DB::table('team_b')->where('overs','>',1)->where('match_id',$match_id)->count();
                                        
                                        if($over_starta || $over_startb){
                                            $this->cancelContest($match_id,$contest_id);
                                        }    
                                    }
                            });
                            $item->total_cancel = $contests->count();
                        }
                        return $item;
                    });
        $c = $cancel_match->first();

        if($c){
            return [$c->total_cancel.' Contest is Cancelled successfully']; 
        }else{
            return ['No Contest Cancelled successfully']; 
        }
    }

    /*
    withdraw_status = 0 Pending
    withdraw_status = 1 Requested
    withdraw_status = 2 In progress
    withdraw_status = 3 Success
    withdraw_status = 4 Rejected
    */
    public function withdrawAmountMethodAbondon(Request $request){

        $user = $request->user_id;

        $verify_documents = \DB::table('verify_documents')
                ->where('user_id',$user)
                ->where('status',2)
                ->count();

       // $deposit_status = \DB::table('')        
        $with_limit = WalletTransaction::where('user_id',$user)
                    ->where('payment_type',5)
                    ->whereDate('created_at',\Carbon\Carbon::today())
                    ->first();

        
            $deposit    = WalletTransaction::where('user_id',$user)
                        ->where('payment_type',3)
                        ->sum('amount');

            if($deposit<100){
                return response()->json(
                    [
                        "status"=>false,
                        "code"=>201,
                        "message" => "You are not eligible to withdraw. Minimum deposit 100 is required" 
                    ]
                );
            }

        if($with_limit){
            return response()->json(
                [
                    "status"=>false,
                    "code"=> 201,
                    "message" => 'Only 1 withdrawal allowed in a day!!' 
                ]
                );
        }     

        if($verify_documents && $verify_documents<2){
            $msg = "Document approval pending";
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => $msg 
                ]
                );
        }

        $payment_in = $request->payment_taken_in;

        if($payment_in=='UPI'){

            $check_upi =  \DB::table('verify_documents')
                ->where('user_id',$request->user_id)
                ->whereNotNull('upi_id')
                ->firts();

            if($check_upi==null){
                return response()->json(
                [
                    "status"=>false,
                    "code"=>405,
                    "message" => 'Please Provide your UPI Id' 
                ]
                );
            } 
            
        }

        $user = User::find($request->user_id);
        if($user && $request->withdraw_amount){
            
            $withdraw_amount = $request->withdraw_amount;
            $payment_taken_in = $request->payment_taken_in;
            
            $wallet = Wallet::where('user_id',$user->id)
                            ->whereIn('payment_type',[3,4])
                            ->get();
                            
            $referral   = $wallet->where('payment_type',2)->first()->amount??0;
           // $deposit    = $wallet->where('payment_type',3)->first()->amount??0;
            $prize      = $wallet->where('payment_type',4)->first()->amount??0;
           

            if($withdraw_amount<100){
                return response()->json(
                    [
                        "status"=>false,
                        "code"=>201,
                        "message" => "Minimum withdrawal amount 100 INR" 
                    ]
                );
            }

            if($prize>=100 && $prize >= $withdraw_amount){
                
                $amt  = $prize-$withdraw_amount;
                $prize = $wallet->where('payment_type',4)->first();
                $tota_balance = $prize->amount;
                $prize->amount = $amt;
                $access = true;

            }elseif ($referral>=100 && $referral >= $withdraw_amount){
                
                $amt    = $referral-$withdraw_amount;
                $prize  = $wallet->where('payment_type',2)->first();
                $tota_balance = $prize->amount;
                $prize->amount = $amt;
                $access = true;

            }else{
                return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "You don't have sufficient balance to withdraw"
                ]
                );
            } 

            if($access){
                if($prize){
                    $prize->total_withdrawal_amount = $withdraw_amount;
                }

                \DB::beginTransaction();
                $prize->save();
                $wdl = Wallet::firstOrNew([
                        'user_id' => $user->id,
                        'payment_type' => 5
                    ]);
                $wdl->payment_type_string = 'withdraw';
                $wdl->validate_user = Hash::make($user->id);
                
                $wdl->amount = $wdl->amount+$withdraw_amount;
                $wdl->save();

                $wt = new WalletTransaction;
                $wt->amount = $withdraw_amount;
                $wt->user_id = $user->id;
                $wt->payment_type = 5;
                $wt->payment_type_string = 'withdraw';
                $wt->payment_mode = 'N';
               // $wt->payment_details = json_encode($request->all());
                $wt->payment_status  = 'request';
                $wt->transaction_id = time().'WDL'.$user->user_name??$user->id;
                $wt->withdraw_status = 1;
                $wt->payment_status = 'Pending';
                $wt->payment_taken_in = $payment_taken_in;
                $wt->debit_credit_status = "-";
                $wt->save();
                \DB::commit();
            }

            $msg = "Hi Ram/Kandy, $user->name has requested withdrawal amount $withdraw_amount. His total balance is $tota_balance";
            $helper = new Helper;
            $helper->notifyDocUploadToAdmin('New Withdrawal Request',$msg);

            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Withdraw request submitted successfully!"
                ]
            );

        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "Withdrawal amount can't be null"
                ]
            );
        }
    }

    public function withdrawAmountNinja(Request $request){

      
        $user =  $request->user_id; 
        $user_id =  $request->user_id;  
        Cache::forget('mywallet_'.$request->user_id);
        $user_data = User::find($user_id);
        $token = $this->valideToken($request);

        if($token)
        {
            return $token;
        }

        \DB::table('paytm')->insert(
            [
                'paytm' => json_encode($request->all()),
                'action' => "withdrawal"
            ]
        );

       

        $with_limit = WalletTransaction::where('user_id',$user_id)
                    ->where('payment_type',5)
                    ->whereDate('created_at',\Carbon\Carbon::today())
                    ->first();

        $deposit    =  WalletTransaction::where('user_id',$user_id)
                        ->where('payment_type',3)
                        ->sum('amount');

        //----------
 
       

        if($deposit<100){
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "Your total deposit is less than ₹100. Minimum deposit  ₹100 is required"
                ]
            );
        }

                                   
        if($with_limit){
            return response()->json(
                [
                    "status"=>false,
                    "code"=> 201,
                    "message" => 'Only one withdrawal allowed in a day!!' 
                ]
            );
        }

        $verify_documents = \DB::table('verify_documents')
                ->where('user_id',$user_id)
                ->where('status',2)
                ->count();
        $payment_in = base64_decode($request->payment_taken_in);

        if($verify_documents && $verify_documents<2 && $payment_in!=="paytm"){
            $msg = "Document approval pending";
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => $msg 
                ]
                );
        }

        $payment_in = base64_decode($request->payment_taken_in);

        $check_paytm =  \DB::table('verify_documents')
            ->where('user_id',$request->user_id)
            ->where('doc_type','Paytm')
            ->first(); 

        if($payment_in=='paytm'){

            if($check_paytm || $request->paytm_number)
            {       
                    if(isset($check_paytm) && $check_paytm!=null)
                    {
                        $upi_id     =   $check_paytm->upi_id??$check_paytm->doc_number;
                    }else{
                        $upi_id     =   "";
                    }

                    $request->merge(['upi' => $request->paytm_number??$upi_id]);

                if($request->paytm_number)
                {
                    \DB::table('verify_documents')
                    ->updateOrInsert([
                        'user_id'=>$request->user_id,
                        'doc_type' => 'Paytm'
                    ],[
                        'doc_number'    =>  $request->paytm_number,
                        'upi_id'        =>  $request->paytm_number,
                        'doc_type'      =>  'Paytm',
                        'user_id'       =>  $request->user_id
                    ]); 
                }

                
            }
            
            $check_paytm =  \DB::table('verify_documents')
            ->where('user_id',$request->user_id)
            ->where('doc_type','Paytm')
            ->first();

            if(!$check_paytm){

                return response()->json(
                [
                    "status"=>false,
                    "code"=>406,
                    "message" => 'Please Provide your UPI' 
                ]
                );
            }   
        }  

        if($payment_in=='UPI'){

            if($request->upi_id){
                \DB::table('verify_documents')
                    ->where('user_id',$request->user_id)
                    ->update([
                        'upi_id'=> $request->upi_id
                    ]);
            }
            
            $check_upi =  \DB::table('verify_documents')
                ->where('user_id',$request->user_id)
                ->first(); 
   
            $request->merge(['upi' => $check_upi->upi_id]);
            
            if($check_upi && $check_upi->upi_id==null){
                return response()->json(
                [
                    "status"=>false,
                    "code"=>405,
                    "message" => 'Please Provide your UPI Id' 
                ]
                );
            } 
            
        }

        $user = User::find($request->user_id);
        if($user && $request->withdraw_amount){
            
            $withdraw_amount = base64_decode($request->withdraw_amount);
            $payment_taken_in = base64_decode($request->payment_taken_in);
            
            $wallet = Wallet::where('user_id',$user->id)
                            ->whereIn('payment_type',[3,4])
                            ->get();
                            
          /*  $referral   = $wallet->where('payment_type',2)->first()->amount??0;*/
            //$deposit    = $wallet->where('payment_type',3)->first()->amount??0;
            $prize      = $wallet->where('payment_type',4)->first()->amount??0;
           
            $access = false;

            if($withdraw_amount<200 && $payment_in=='paytm'){
                return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "Minimum paytm withdrawal amount 200 INR" 
                ]
                );
            }
            elseif($withdraw_amount<200){
                return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "Minimum withdrawal amount 200 INR" 
                ]
                );
            }
            //100
            if($prize>=200 && $prize >= $withdraw_amount){
                
                $amt  = $prize-$withdraw_amount;
                $prize = $wallet->where('payment_type',4)->first();
                $tota_balance = $prize->amount;
                $prize->amount = $amt;
                $access = true;

            }else{
                return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "You don't have sufficient balance to withdraw"
                ]
                );
            } 

            if($access){
                if($prize){
                    $prize->total_withdrawal_amount = $withdraw_amount;
                }

                \DB::beginTransaction();
                $prize->save();
                $wdl = Wallet::firstOrNew([
                        'user_id' => $user->id,
                        'payment_type' => 5
                    ]);
                $wdl->payment_type_string = 'withdraw';
                $wdl->validate_user = Hash::make($user->id);
                
                $wdl->amount = $wdl->amount+$withdraw_amount;
                $wdl->save();
                

                $txt_id = $user->id.'_'.time();

                $wt = new WalletTransaction;
                $wt->amount = $withdraw_amount;
                $wt->user_id = $user->id;
                $wt->payment_type = 5;
                $wt->payment_type_string = 'withdraw';
                $wt->payment_mode = 'N';
                $wt->payment_status  = 'request';
                $wt->transaction_id = $txt_id;
                $wt->withdraw_status = 1;
                $wt->payment_status = 'Pending';
                $wt->payment_taken_in = $payment_taken_in;
                $wt->debit_credit_status = "-";
                $wt->save();


                $user_id = $request->user_id;
                \DB::table('paytm_payouts')
                        ->insert(
                            [
                                'user_id'       => $user_id,
                                'amount'        => $withdraw_amount,
                                'payout_type'   => $payment_in,
                                'order_id'      => "S11_".$txt_id,
                                'date'          => date('Y-m-d'),
                                'transaction_id' =>  $txt_id,
                                'upi_id'        =>   $request->upi,
                                'status'        => 2       
                            ]
                        );

                \DB::commit();

                 

                        
                $item = Wallet::where('user_id',$user_id)->get();
                            
                $bonus_amount = $item->where('payment_type',1)->first();

                $item->where('payment_type',2)->first();
                
                $depos_amount = $item->where('payment_type',3)->first();
                
                $ec_amount = $item->where('payment_type',3)->first();
                
                $prize_amount = $item->where('payment_type',4)->first();
                $extra_cash   = $depos_amount;

                $main_balance       = ($prize_amount->amount??0)+($depos_amount->amount??0);
                $actual_amount      = $main_balance;
                $remaining_amount   = $main_balance-$withdraw_amount;
                $in_deposit         = $depos_amount->amount??0; 
                $in_winning         = $prize_amount->amount??0;
                

            }

            $msg = "Hi Admin, $user->name has requested withdrawal amount $withdraw_amount. His total balance is $tota_balance"; 

            
            $send_status = $this->notifyToAdmin('New Withdrawal',$msg); 



                $title = 'Kindly allow some time for the payout to process.';
                $msg_s =  "Your withdrawal is under process, Thank you!!";
               

                $this->remindAdmin($title,$msg_s,$user_id);

            

            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Withdraw request submitted successfully!"
                ]
            );

        }else{
            return response()->json(
                [
                    "status"=>false,
                    "code"=>201,
                    "message" => "Withdrawal amount can't be null"
                ]
            );
        }
    }

    public function getNotification(Request $request)
    {
        $match = Matches::whereDate('date_start',\Carbon\Carbon::today())->first(); 
        $msg = "";
        if($match){
            $msg = " | $match->short_title";
        }   
        $user_id = $request->user_id;
        
        $jc = JoinContest::where('user_id',$user_id)
            ->whereDate('created_at',\Carbon\Carbon::today())
            ->orderBy('id','desc')->get(['match_id','winning_amount'])
            ->transform(function($item,$key){

                $match = Matches::where('match_id',$item->match_id)->first();
                if($match->status==2){
                    $msg = "You won INR $item->winning_amount";
                }elseif($match->status==4){
                    $msg = "Match is cancelled";
                }elseif($match->status==3){
                    $msg = "You are winning INR $item->winning_amount";
                }else{
                    $msg = "You have joined Upcoming match contest. Match will start at ".date('h:i:s A, d-m-Y',$match->timestamp_start);
                }

                $data = [
                    'title' => "$match->short_title",
                    'messages' => $msg
                ];

                $item->data = $data;
                return $item;
        
            });

        foreach ($jc as $key => $value) {
               $data[] = $value->data;
            }
        if(!isset($data)){
           $data[] = [
                    'title' => "Join Contest $msg",
                    'messages' => "Join content with maximum and win the cash."
                ];     
        }        

        return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "You have notification",
                    "notification_list" => $data
                ]
            );
    }


    public function  paymentCallback(Request $request)
    {

        sleep(1);
        \DB::table('paytm')->insert(
                    [
                        'paytm'=> json_encode($request->all()),
                        'action' => 'paymentCallback-payin-2J2Kxj4'
                    ]
                );

        $data =  $request->all() ;
        $event = $request->event; 
      
    
        if(isset($data['key']) && ($data['key']==="J2Kxj4" && $data['status']==='Success'))
        {   
            $orderId    = $data['merchantTransactionId'];
            $user       = User::where('txn_id',$orderId)->first();
        
            $amount     = $data['amount'];
            if($user==null)
            {
                return 'transaction failed';
            } 
            $request->merge([

                'payment_mode'      =>  'upi',
                'transaction_id'    =>   $orderId,
                'utr'               =>   $data['bankRefNum'],
                'deposit_amount'    =>   $amount,
                'status_code'       =>   "PAYMENT_SUCCESS" ,
                'user_id'           =>   $user->id
            ]); 
   
           
            return $this->addMoney($request); 

        } 

      
        elseif($event=="payment.captured")
            {
                    $data = $request->all();

                $amount   = data_get($data, 'payload.payment.entity.amount');
                $orderId  = data_get($data, 'payload.payment.entity.order_id');
                $vpa      = data_get($data, 'payload.payment.entity.vpa');
                $email    = data_get($data, 'payload.payment.entity.email');
                $contact  = data_get($data, 'payload.payment.entity.contact');
                $utr      = data_get($data, 'payload.payment.entity.acquirer_data.rrn');
                $user = User::where('email', $email)->first();

                $amt = ($amount/100);

                // return response()->json([
                //     'amount'   => ($amount/100),
                //     'order_id'=> $orderId,
                //     'vpa'     => $vpa,
                //     'email'   => $email,
                //     'contact' => $contact,
                //     'user_id' => $user->id
                // ]);

            $request->merge([

                'payment_mode'      =>  'upi',
                'transaction_id'    =>   $orderId,
                'utr'               =>   $utr,
                'deposit_amount'    =>   $amt,
                'status_code'       =>   "PAYMENT_SUCCESS" ,
                'user_id'           =>   $user->id
            ]);  
           
            return $this->addMoney($request); 
        
            }
 

        

        elseif(isset($data['order_id']) && $data['status']=='SUCCESS')
        {
            $orderId    = $data['order_id'];
            $user       = User::where('modeOfreach',$orderId)->first();
             $amount = $data['amount'];
            if($user==null)
            {
                return 'transaction failed';
            } 
            $request->merge([

                'payment_mode'      =>  'upi',
                'transaction_id'    =>   $data['order_id'],
                'utr'               =>   $data['utr'],
                'deposit_amount'    =>   $amount,
                'status_code'       =>   "PAYMENT_SUCCESS" ,
                'user_id'           =>   $user->id
            ]); 
 
           
            return $this->addMoney($request); 

        } 
    }

    public function payoutCallback(Request $request)
    {
        
          \DB::table('paytm')->insert(
                    [
                        'paytm'=> json_encode($request->all()),
                        'action' => 'payoutCallback'
                    ]
                );

       $res = json_decode($request->all(), true);
       

       $str = $res['data']['transaction_id']; 
        // first part before underscore
        $firstPart = explode("_", $str)[0];   // payout285 
        // only numeric part from firstPart
        $uers_id = preg_replace('/\D/', '', $firstPart); // 285 

       \DB::table('payouts_logs')->insert([

        'transaction_id' => $res['data']['exch_transaction_id']??'fail',
        'order_id' => $res['data']['transaction_id']??'fail',
        'amount' => $res['data']['amount']??'fail',
        'status' => 2,
        'message' => $res['message']??'fail',
        'payout_type' => 'Bank',
        'user_id' => $uers_id,  
       ]);

    }

    public function paytmCallBack(Request $request)
    {

        $data['paytm'] = json_encode($request->all());
        $data['user_id'] =   $request->user_id;
        $data['email'] =   $request->email;
        if($request->user_id){
            $user = User::find($request->user_id); 
            $data['email'] =   $user->email;   
        }

        $data['deposit_amount'] =   $request->deposit_amount;
        $data['transaction_id'] =   $request->transaction_id;
        $data['payment_mode']   =   $request->payment_mode;
        $data['payment_status']   =   $request->payment_status;
            
      //
    }

    public function checkSingnature(Request $request)
    {
        $data = [
            'action' => 'notify' ,
            'title' => "ALERT",
            'message' => 'Signature override'
        ];

        $status = \DB::table('eventLogs')->groupBy('signature')->count();       
        if($status>1){       
            $helper = new Helper;
           $send_status = $helper->notifyToAdmin('Wrong signature detected'); 
        }
    }

    public function eventLog(Request $request){
        //return true;
        try{
            $user_info          = (object)$request->user_info;
            $signature          = (object)$request->deviceDetails;
            $data['user_id']    = $request->user_id??$user_info->user_id;
            $data['email']      = $user_info->email??null;
            $data['mobile_number'] = $user_info->mobile_number??null;
            $data['event_name'] = $request->event_name??null;
           
           // $data['storage_permission'] = $request->storage_permission;
           // $data['signature']  = $signature->signature??null;
            $data['match_id']   = $request->match_id??null;
            $data['contest_id'] = $request->contest_id??null;
            $data['date_time']  = date('m-d-Y, h:i:s A',time());
            $data['team_id']   = $request->team_id;
           
            \DB::table('eventLogs')->insert($data); 

            $elog = \DB::table('eventLogs')->where('user_id',$request->user_id)->first();

            $tid      = $request->team_id;
            
            $utid  = CreateTeam::find($tid);
            $seen_team = '';
            if($utid->rail_id){
                $cid  = CreateTeam::find($utid->rail_id); 

                // $cid->rail_id=0;
                // $cid->is_cloned=0;
                // $cid->save();

                if($cid->user_id==285){
                    $seen_team = "K".$cid->team_count;
                }
                elseif($cid->user_id==262){
                    $seen_team = "R".$cid->team_count;
                }
                elseif($cid->user_id==9112){
                    $seen_team = "N".$cid->team_count;
                }
                elseif($cid->user_id==13244){
                    $seen_team = "M".$cid->team_count;
                }
                elseif($cid->user_id==11556){
                    $seen_team = "kk".$cid->team_count;
                }
            }  

            $event_name['last_seen'] =  $seen_team.'-'.$user_info->team_name.'('.date('h:i',time()).')';

            if($request->event_name=='captured'){
               $event_name['event_name'] = 'captured';  
            }

            if($elog){
                \DB::table('join_contests')
                    ->where('user_id',$request->user_id)
                    ->where('contest_id',$request->contest_id)
                    ->where('match_id',$request->match_id)
                    ->where('created_team_id',$request->team_id)
                    ->update($event_name);
            }

        
            $ct = CreateTeam::find($request->team_id);
            $ct->rail_id=0;
            $ct->is_cloned=0;
            $ct->save();



        }catch(\Exception $e){ 
            $data['eventLog'] = json_encode($e->getMessage()) ;
            \DB::table('eventLogs')->insert($data); 

            $ct = CreateTeam::find($request->team_id);
            $ct->rail_id=0;
            $ct->is_cloned=0;
            $ct->save();

        }
        
        return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "success"
                ]
            );
    }

    public function detectDevice(Request $request){

        try{
            

        }catch(\Exception $e){

        }
    }

    /*
    * Player Stat
    */
    public function playerStat(Request $request){

        try{
            $match_id = $request->match_id;

            $match = Matches::where('match_id',$match_id)->first();
             

            $players =  Player::select('pid','playing11','team_id','match_id','nationality','short_name','sell_by as selection','sell_by_c as c_selection','sell_by_vc as vc_selection','player_points as point','team_name','playing_role as role','fantasy_player_rating as rating','player_match_points')
            ->orderBy('player_points','DESC')
            ->where('match_id',$match_id)
            ->where('playing11',"true")
            ->get()
            ->transform(function($item,$key){
                $pid = $item->pid;
                $match_id = $item->match_id;

                $points = \DB::table('match_player_points')
                        ->where('match_id',$match_id)
                        ->where('pid',$pid)
                        ->first();

               $item->point = $points->point; 
               $p = [];

               $not_in = [
                'match_id','pid','name','role','rating','created_at','updated_at','id'
               ];

               foreach($points as $key => $value)
               {
                    if(in_array($key,$not_in)){
                        continue;
                    }

                    $p[] = [
                        'key' => $key,
                        'value' => $value

                    ] ;
               }
               $mp = $p;
               $item->match_points = $mp;
               return $item;
            }); 

            $data = [];
            $playerd = [];
            foreach($players as $key => $plyr)
            {
               $data['pid']         =   $plyr->pid;
               $data['name']        =   $plyr->short_name;
               $data['role']        =   $plyr->role;
               $data['rating']      =   $plyr->rating;
               $data['point']       =   $plyr->point;
               $data['team_name']   =   $plyr->team_name;
               $data['selection']   =   $plyr->selection;
               $data['c_selection'] =   $plyr->c_selection;
               $data['vc_selection']=   $plyr->vc_selection;
               $data['nationality'] =   $plyr->nationality;
               $data['match_points']=  $plyr->match_points;
               $playerd[] = $data;
            }
              
            $team_a = \DB::table('team_a')
                            ->where('match_id',$match->match_id)
                            ->first();
            $team_b = \DB::table('team_b')
                            ->where('match_id',$match->match_id)
                            ->first();   

            return response()->json(
                [   
                    "match_title" => $match->title,
                    "short_title" => $match->short_title,
                    "match_status" => $match->status_str,
                    "match_status_note" => $match->status_note,
                    "team_a_name" => $team_a->short_name,
                    "team_a_logo" => $team_a->thumb_url,
                    "team_a_full_scrore" => $team_a->scores_full,
                    "team_b_name" => $team_b->short_name,
                    "team_b_logo" => $team_b->thumb_url,
                    "team_b_full_scrore" => $team_b->scores_full,
                    "status"=> count($data)?true:false,
                    "code" => count($data)?200:201,
                    "message" => count($data)?"success":"Player Stat not found",
                    'data' => count($data)?$playerd:null
                ]
            );


        }catch(\Error  $e){
            return response()->json(
                [
                    "status" => false,
                    "code" => 201,
                    "message" => "No Stat found",
                    "data" => []
                ]
            );
        }
    }
    public function playerStatDetails(Request $request){
        try{
            $match_id   =   $request->match_id;
            $pid        =   $request->pid;
            $match      =   Matches::where('match_id',$match_id)->first();
           
            $players    =   Player::with('matchPoints')
                                    ->select('pid','sell_by','sell_by_c','sell_by_vc','player_points','team_name','playing11','playing_role','fantasy_player_rating')
                                    ->where('match_id', $match_id)
                                    ->where('pid',$pid)
                                    ->first();
               
            return response()->json(
                [     
                    "match_title"   =>  $match->short_title??'',
                    "status"        =>  true,
                    "code"          =>  200,
                    "message"       =>  "Player Stat",
                    'data'          =>  $players
                ]
            );
        }catch(\Error  $e){
            return response()->json(
                [
                    "status" => false,
                    "code" => 201,
                    "message" => "No Stat found"
                ]
            );
        }
    }
    public function playerPoints(Request $request){

        $match_id =  $request->match_id; 
        $cid = Competition::where('match_id',$match_id)
                    ->pluck('cid')->first();
                   
        $match = Matches::where('match_id',$match_id)->first();
    
        $competitions_match_id = Matches::where('competition_id',Competition::where('match_id',$match_id)
                    ->pluck('cid')->first()
                )
                ->where('format',$match->format)
                ->pluck('match_id');
        
        $match_pid = MatchPoint::where('match_id',$match_id)
                ->pluck('pid'); 
        $mathcPoint = MatchPoint::select('pid','match_id','point')
                ->whereIn('match_id',$competitions_match_id)    
                ->whereIn('pid',$match_pid)
                ->get()
                ->groupBy('pid');                
                $mathcPoint->transform(function($item,$key){
                    $item->playerPoints = (int)($item->where('pid',$key)->sum('point'));
                    return $item;
                });
        $data = []; 
            
            foreach ($mathcPoint as $key => $value) {
                $data[$key] = (int)$value->playerPoints; 
                \DB::table('players')
                    ->where('match_id',$match_id)
                    ->where('pid',$key)
                    ->update(
                        [
                            'player_points' => $value->playerPoints
                        ]
                    );
            }

        return $data;
    }

    public function distributePrize(Request $request){
        sleep(1);
        ini_set('max_execution_time', 0); // 0 = Unlimited
        $data = null;
        try{
            
            $match = Matches::where('status',2)
                        ->where('current_status',0)
                        ->where('is_cancelled',0)
                        ->whereIn('result_type',[1,2])
                        ->where('verified',"true")
                        ->limit(1)
                        ->get();   
                 
            if($match->count()){
                foreach ($match as $key => $value) {
                $request->merge(['match_id'=>$value->match_id]);
                $request->merge(['distribute_prize'=>$value->match_id]);
                $this->updatePoints($request);
                $this->WinningPrizeDistribution($request);
                $this->prizeDistribution($request);
               // $this->addMegaExtraCash($request);
                $data[$value->match_id] = $value->short_title;
            }
            return [
                'message' => "Prize Distributed for ",
                "data" => $data
            ];    
            }else{
                echo "Prize distribution Already Done!!";    
            }
        }catch(\Exception $e){
             ini_set('max_execution_time', 300); // 0 = Unlimited
            echo "Already distributed";
        }
         ini_set('max_execution_time', 300); // 0 = Unlimited
    }
    // Affiliates Program

    public  function distributeaffiliate(Request $request)
    {
        $match = Matches::where('status',2)
                            ->where('affiliate_winning',0)
                            ->whereDate('updated_at',\Carbon\Carbon::today())
                                ->first();
        if($match)
        {
            $request->merge([
                'match_id' => $match->match_id
            ]);    
            $this->affiliateProgram($request);  
        }

    }

    public function affiliateProgram(Request $request){
        
        $match_id = $request->match_id;

        $match = Matches::where('match_id',$match_id)->first();
        
        if($match->status!=2){
            echo "Match is not completed.So you can't run";
            die;
        }
        if($match->affiliate_winning==1){
             die('Affiliate Amount Already distributed');
        }
        if($match_id==null){
            die('No Match Found');
        }

        $join_contests = JoinContest::where('match_id',$match_id)
                        ->where('cancel_contest',0)
                        ->where('affiliated_user',0)
                        ->get()
                        ->groupBy('contest_id');

        \DB::beginTransaction();
        foreach ($join_contests as $contest_key => $jc) {
            //affiliated_user
           
            $jc_users       =   $jc->pluck('user_id')->toArray();

            $contest1        =   CreateContest::where('match_id',$match_id)
                                ->where('id',$contest_key)
                                ->where('entry_fees','!=',0)
                                ->where('bonus_contest',0)
                                ->first();

            $contest2        =   CreateContest::where('match_id',$match_id)     
                                ->where('id',$contest_key)
                                ->where('entry_fees','!=',0)
                                ->where('bonus_contest',0)
                                ->first();

            $contest  = $contest1??$contest2;                    
            if($contest){
                
            }else{
                continue;
            }

            \DB::table('join_contests')
                    ->where('contest_id',$contest_key)
                    ->update(
                        [
                            'affiliated_user' => 1
                        ]
                    );

            $actual_entry = ($contest->entry_fees - $contest->entry_fees*($contest->usable_bonus/100));
                  
            $total_deposit  =   ($actual_entry)*($contest->total_spots);

            $company_profit =   $total_deposit - $contest->total_winning_prize;
            if($company_profit<0){
                continue;
            }

            // $uid    =   $reference_code->whereIn('id',$jc_users)->get();
            $reference_code     =   User::whereIn('id',$jc_users)
                                    ->pluck('reference_code')
                                    ->toArray();

            $affiliate_user     =   User::where('affiliate_user',1)
                                        ->whereIn('referal_code',$reference_code)
                                        ->select('id','name','email',
                                            'referal_code',
                                            'reference_code',
                                            'affiliate_user',
                                            'affiliate_commission'
                                        )->get();

            foreach ($affiliate_user as $key => $user) {
                $action = true;
                $commsn = $user->affiliate_commission;
                $percentage_amount = $company_profit*$commsn*(0.01);
                

                $actual_payout = round(($percentage_amount/count($jc_users)),2);

                if($actual_payout <= 0)
                {   
                    continue;
                }

                $ap['user_id']      = $user->id;
                $ap['amount']       = $actual_payout;
                $ap['match_id']     = $contest->match_id;
                $ap['contest_id']   = $contest->id;
                $ap['entry_fees']   = $contest->entry_fees;
                $ap['total_spots']   = $contest->total_spots;
                $ap['commission']   = $commsn??0;
                $ap['is_distribute'] = 1; 

                \DB::table('affiliate_programs')->insert($ap);

                $wt                 =   new WalletTransaction;
                $wt->user_id        =   $user->id;
                $wt->amount         =   $actual_payout;
              //  $wt->match_id       =   $contest->match_id??null;
                $wt->contest_id     =   $contest->id??null;
                $wt->payment_type   =   8;
                $wt->payment_type_string = 'Affiliate';
                $wt->transaction_id =   $contest->match_id.'N'.$contest->id.'N'.$user->user_name??$user->id;
                $wt->payment_mode   =   'n11';
                $wt->payment_status =   'Success';
                $wt->debit_credit_status = "+";
                
                $wt->save();

                $winning_amount = $actual_payout;
                $wallet_amount_c =  Wallet::where(
                            [
                                'user_id'       => $user->id,
                                'payment_type'  => 4
                            ])->first();
                
                if($wallet_amount_c){
                    $winning_amount = $wallet_amount_c->amount+$winning_amount;
                }
                
                $wallets = Wallet::firstOrNew(
                            [
                                'user_id'       => $user->id,
                                'payment_type'  => 4
                            ]);

                            
                $wallets->user_id       =  $user->id;
                $wallets->validate_user =  Hash::make($user->id);
                $wallets->payment_type  =  4;
                $wallets->payment_type_string = 'Prize';
                $wallets->amount =  $winning_amount;
                $wallets->save();
            }
          
        }
        $match->affiliate_winning=1;
        $match->save();

          \DB::commit();
        if(isset($action)){
            echo "Affiliate amount distributed";
        }else{
            echo "Already Affiliate amount distributed";
        }
    }
    //Remove removePrizeAfterAbandon
    public function removePrizeAfterAbandon(Request $request)
    {
        try {
            $contest = JoinContest::where('cancel_contest',1)
                        ->where('winning_amount','>',0)
                        ->get()
                        ->transform(function($item,$key){
                            $item->winning_amount = 0;
                            $item->save();
                            return $item;
                        }); 


        } catch (Exception $e) {
              return false;
        }
    } 

    public function razorpayOrderId(Request $request)     {         
             $api = new Api('rzp_live_g7gBQZ3EyOwgQW', 'pwzP6sZMGLs2OmD0c2o38JVq');   
            $receipt = "N".time();  
            $order  = $api->order->create(array('receipt' => $receipt, 'amount' => $request->amount, 'currency' => 'INR')); // Creates order    
            
            
            $orderId = $order['id']; // Get the created Order ID  and save this orderid in table for this payment request

            \DB::table('razor_pay')->insert([
                'user_id' => $request->user_id,
                'amount' => $request->amount,
                'order_id' => $orderId
            ]);

        return [
            'status'=>true,
            'code'=>200,
            'message'=>'success',
            'system_time'=>time(),
            'order_id'=>$orderId
        ];
       
   }


   public function updatePointsByMatchID(Request $request)
   {
        //return false;
        $live = Matches::where('status',3)->get();
        if($request->match_id){
            $live = Matches::where('match_id',$request->match_id)->get(); 
           // $this->updateLivePoints($request);
           // $this->updateRankByMatchId($request);
        }
     //   \DB::beginTransaction();

        foreach ($live as $key => $value) {
            $request->merge(['match_id'=>$value->match_id]);
            $this->updateLivePoints($request);
            $this->updateRankByMatchId($request);
        }
      //  \DB::commit();

   } 


   public function updateMatchPointFromCron(Request $request)
   {
        //return false;
        $live = Matches::where('status',3)->get();
        if($request->match_id){
            $live = Matches::where('match_id',$request->match_id)->get(); 
        }
//        \DB::beginTransaction();
        foreach ($live as $key => $value) {
            $request->merge(['match_id'=>$value->match_id]);
            $this->updateLivePoints($request);
        }
  //      \DB::commit();
   }
   public function updateMatchRankFromCron(Request $request)
   {
        //return false;
        $live = Matches::where('status',3)->get();
        if($request->match_id){
            $live = Matches::where('match_id',$request->match_id)->get(); 
        }
        \DB::beginTransaction();

        foreach ($live as $key => $value) {
            $request->merge(['match_id'=>$value->match_id]);
            $this->updateRankByMatchId($request);
        }
        \DB::commit();
   } 

   public function updateRankByMatchId(Request $request)
    {   
        $contests = \DB::table('create_contests')
            ->where('match_id',$request->match_id)
            ->where('is_cancelled',0)
            ->where('filled_spot','>',0)
            ->get();

            $servername =  env('DB_HOST','localhost');
            $username   =  env('DB_USERNAME','root');
            $password   =  env('DB_PASSWORD','Server@db2019');
            $dbname     =  env('DB_DATABASE','ninja');
            $conn = mysqli_connect($servername, $username, $password, $dbname);

            foreach ($contests as $key => $cnt) {
            
                $match_id = $cnt->match_id;
                $contest_id = $cnt->id;
                // Create connection

                $sql = "SELECT id, user_id, match_id, contest_id, points, (SELECT COUNT(*)+1 FROM join_contests B WHERE A.points<B.points and ( match_id=$match_id and contest_id=$contest_id)) AS Rank FROM join_contests A where match_id=$match_id and contest_id=$contest_id ORDER BY `Rank` DESC";

                $result = mysqli_query($conn, $sql);
                $i=1;
                if ($result && mysqli_num_rows($result) > 0) {
                    while($row = $result->fetch_object()) {
                        
                        if($row->Rank > 0)
                        {           
                            /*$jc = JoinContest::find($row->id);
                            if($jc){
                                $jc->ranks = $row->Rank;
                                $jc->save();
                            }*/
                            \DB::table('join_contests')
                               ->where('id', '=', $row->id)
                               ->update(
                                [
                                    'ranks' => $row->Rank 
                                ]
                            );
                        }
                    }
                }  
            }
        mysqli_close($conn);    
    }
    public function updateLivePoints($request){
            $match_point_result = null;
            $contests = \DB::table('create_contests')
            ->where('match_id',$request->match_id)
            ->where('is_cancelled',0)
            ->where('filled_spot','>',0)
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
                        }    
                    });    
            });
        return [
            'status'=>true,
            'code' => 200,
            'message' => 'points update'
        ];
    }

    // Robotics from other teams clone
    public function joinedTeamFromAnother(Request $request){
        
        $validator = Validator::make($request->all(), [
            'match_id' => 'required'
        ]); 

        $match_id = $request->match_id;
        $limit = $request->limit??1;

        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return [
                    'session_expired'=>$this->is_session_expire,
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg
                ];
            
        }

        $ro_users = User::where('customer_type',3)->pluck('id')->toArray();
        $ct = \DB::table('create_teams')
                ->where('match_id',$match_id)
                ->whereNotIn('user_id',$ro_users)
                ->whereNotIn('id', CreateTeam::where('match_id',$match_id)->where('is_cloned','>',0)->pluck('is_cloned')->toArray() )
                ->limit($limit)
                ->get();

        foreach ($ct as $key => $value) {
            $index = array_rand($ro_users);
            $user = $ro_users[$index];
            $rt = \DB::table('create_teams')->where('user_id',$user)
                    ->where('match_id',$match_id)->first();
            unset($ro_users[$index]);

            if(!$rt){
                $create_team = new CreateTeam;
                $create_team->match_id  = $value->match_id;
                $create_team->user_id   = $user;
                $create_team->team_id   = $value->team_id;
                $create_team->teams     = $value->teams;
                $create_team->captain   = $value->vice_captain;
                $create_team->vice_captain = $value->captain;
              //  $create_team->trump = $value->captain;
                $create_team->team_count = 'T1';
                $create_team->edit_team_count = 1;
                $create_team->is_cloned = $value->id;
                $create_team->save();
                $t[] = $create_team;
            }
            
        }
        return $t??[];
    }

    public function joinTeamfromRB(Request $request){

        $match_id = $request->match_id;
        $contest_id = $request->contest_id;

        $validator = Validator::make($request->all(), [
            'match_id' => 'required',
            'contest_id' => 'required'
        ]); 

        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return [
                    'session_expired'=>$this->is_session_expire,
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg
                ];
            
        }

        $limit = $request->limit??1;
        $ro_users = User::where('customer_type',3)->pluck('id')->toArray();
        $rbt =  CreateTeam::where('is_cloned','!=',0)
              //  ->where('team_join_status',0)
        //->whereIn('user_id',$ro_users)
                ->where('match_id',$match_id)
                ->orderBy('created_at','desc')
                ->limit($limit)
                ->get();
        if($rbt->count()==0){
            die('Team not available');
        }        
        foreach ($rbt as $key => $ct) {
            $contest = CreateContest::find($contest_id);        
           // if($contest->total_spots > $contest->filled_spot){
                
                $request->merge([
                    'match_id'  => $match_id,
                    'user_id'   => $ct->user_id,
                    'created_team_id' => [$ct->id],
                    'contest_id' => $contest_id,
                    'match_id'  => $match_id
                ]);
                $jc = $this->joinContestRB($request);
                if($jc['status']!=false){
                    $ct->team_join_status = 1;
                    $ct->save();
                    $t['joined'][] = $ct->id;
                }else{
                    $t['skiped'][] = $ct->id;
                    continue;
                }
                
        }
        return $t??'No team available';

    }
    // joinContest BY RB
    public function  joinContestRB(Request  $request)
    {  
        $match_id           = $request->match_id;
        $user_id            = $request->user_id;
        $created_team_id    = $request->created_team_id;
        $contest_id         = $request->contest_id;
        $max_t = $this->maxAllowedTeam($request);

        $user_details = User::find($user_id);

        $validator = Validator::make($request->all(), [
            'match_id' => 'required',
            'user_id' => 'required',
            'contest_id' => 'required',
            'created_team_id' => 'required'

        ]);  
        // Return Error Message
        if ($validator->fails() || !isset($created_team_id)) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return [
                    'session_expired'=>$this->is_session_expire,
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]??'Team id missing'
                ];
            
        }

        $check_join_contest = \DB::table('join_contests')
            ->whereIn('created_team_id',$created_team_id)
            ->where('match_id',$match_id)
            ->where('user_id',$user_id)
            ->where('contest_id',$contest_id)
            ->get();

        if(count($created_team_id)==1 AND  $check_join_contest->count()==1){
            return [
                'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'This team already Joined'

            ];
        }

        $cc = CreateContest::find($contest_id);

        if($cc && ($cc->total_spots!=0 && $cc->filled_spot>=$cc->total_spots)){
            return [
                'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'This contest already full'

            ];
        }

        if($max_t!==true){
            return $max_t;
            exit();
        }

        $userVald = User::find($request->user_id);
        $matchVald = Matches::where('match_id',$request->match_id)->count();

        if(!$userVald || !$matchVald || !$contest_id){
            return [
                'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'user_id or match_id or contest_id is invalid'

            ];
        }
           
        $data = [];
        $cont = [];

        $ct = \DB::table('create_teams')
            ->whereIn('id',$created_team_id)->count();

        if($ct)
        {   
            foreach ($created_team_id as $key => $ct_id) {
               \DB::beginTransaction();
                $is_full = CreateContest::find($contest_id);
                
                if($is_full==null){
                    return [
                        'session_expired'=>$this->is_session_expire,
                        'status'=>false,
                        'code' => 201,
                        'message' => 'invalid contest'
                    ];
                }
                 
                if($is_full && $is_full->total_spots>0  && ($is_full->total_spots==$is_full->filled_spot)){
                    return [
                        'session_expired'=>$this->is_session_expire,
                        'status'=>false,
                        'code' => 201,
                        'message' => 'This Contest is already full'
                    ];
                } 
                // free contest validation, if more than two team 
                $check_max_contest = \DB::table('join_contests')
                        ->where('match_id',$match_id)
                        ->where('user_id',$user_id)
                        ->where('contest_id',$contest_id)
                        ->count(); 

                $contestT = CreateContest::find($contest_id);
                
                $contestTyp = \DB::table('contest_types')->where('id',$contestT->contest_type)->first();
                if(
                    isset($check_max_contest) 
                    && $check_max_contest>=$contestTyp->max_entries
                    || isset($request->created_team_id) && count($request->created_team_id) >$contestTyp->max_entries
                ){

                    return [
                        'session_expired'=>$this->is_session_expire,
                        'status'=>false,
                        'code' => 201,
                        'message' => "Only $contestTyp->max_entries teams are allowed"
                    ];
                }                

                $check_join_contest = \DB::table('join_contests')
                    ->where('created_team_id',$ct_id)
                    ->where('match_id',$match_id)
                    ->where('user_id',$user_id)
                    ->where('contest_id',$contest_id)
                    ->first();

                if($check_join_contest){
                    continue;
                }
                $data['match_id'] = $match_id;
                $data['user_id'] = $user_id;
                $data['created_team_id'] = $ct_id;
                $data['contest_id'] = $contest_id;

                $ctid  = CreateTeam::find($ct_id);
                $data['team_count'] = $ctid->team_count??null;

                    $total_fee          =  $cc->entry_fees;
                    $payable_amount     =  $total_fee; 

                    if($contestT->bonus_contest){
                        $deduct_from_bonus  =  $payable_amount*($contestT->usable_bonus/100);
                    }else{
                        $per = $contestT->usable_bonus;
                        $deduct_from_bonus  =  $payable_amount*($per/100);
                    }
                    
                    $final_paid_amount  =  $payable_amount;

                    $item = Wallet::where('user_id',$user_id)->get();
                    $bonus_amount = $item->where('payment_type',1)->first();

                    $refer_amount = $item->where('payment_type',2)->first();
                    $depos_amount = $item->where('payment_type',3)->first();
                    $prize_amount = $item->where('payment_type',4)->first();

                  //  $ref_prize_depos = $item->whereIn('payment_type',[2,3,4])->get();
                       
                    $transaction_amt = 0;
                    if($bonus_amount && $bonus_amount->amount>$deduct_from_bonus && !$contestT->bonus_contest){
                        $final_paid_amount = $final_paid_amount-$deduct_from_bonus;

                        $bonus_amount->amount = $bonus_amount->amount-$deduct_from_bonus;
                        $bonus_amount->save();
                    }else{
                        $final_paid_amount = $final_paid_amount;
                    }

                 //   $cc->save(); 
                    // transaction histoory
                    $contest_id = $request->contest_id;
                    $match_id = $request->match_id;

                    if($final_paid_amount){
                        $wt             =   new WalletTransaction;
                        $wt->user_id    =   $user_id;
                        $wt->amount     =   $total_fee;
                        $wt->match_id   =   $match_id??null;
                        $wt->contest_id =   $contest_id??null;
                        $wt->payment_type = 6;
                        $wt->payment_type_string = 'Join Contest';
                        $wt->transaction_id = $match_id.'N'.$contest_id;
                        $wt->payment_mode =  env('company_name');
                        $wt->payment_status =  'Success';
                        $wt->debit_credit_status = "-";
                      //  $wt->payment_details = json_encode($request->all());
                       
                        $wt->save();
                    } 

                $jcc = \DB::table('join_contests')
                    ->where('match_id',$match_id)
                    ->where('contest_id',$contest_id)
                    ->where('user_id',$user_id)
                    ->count();
               // if($jcc<=$cc->total_spots || $cc->total_spots==0){
                // join contest   
                $data['user_name'] = $userVald->name;
                $data['team_name'] = $userVald->team_name;

                $t =   JoinContest::updateOrCreate($data,$data);

               // }
                // End spot count
                $cont[] = $data;
                $ct = \DB::table('create_teams')
                    ->where('id',$ct_id)
                    ->update(['team_join_status'=>1]);

                $cc->filled_spot = CreateTeam::where('match_id',$match_id)
                    ->where('team_join_status',1)->count();
                $cc->save();

                $is_full = CreateContest::find($contest_id);
                $c_count = (int)$is_full->is_full+1;
                $is_full->is_full = $c_count;
                $is_full->filled_spot =  $c_count;
                $is_full->save();
            \DB::commit();
            }
            $message = "Contest Joined successfully!";
        }else{
            $cont = ["error"=>"contest id not found"];
            $message = "Something went wrong!";
        }
        return 
                [
                'session_expired'=>$this->is_session_expire,    
                'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                "status"=>true,
                "code"=>200,
                "message"=>$message,
                "response"=>["joinedcontest"=>$cont]
            ];
    }

    //edit team
    // 
    public function autoEditTeam($edited_id, $clone_team_obj){

        $copy_team  = CreateTeam::where('is_cloned',$edited_id)->get();

        foreach ($copy_team as $key => $team) {
            $create_team =  CreateTeam::find($team->id);
            if($create_team){

                if($create_team->editable==2){

                    $tm = array_filter(json_decode($clone_team_obj->teams));
                    if (($key = array_search($clone_team_obj->vice_captain, $tm)) !== false) {
                        unset($tm[$key]);
                    }
                    if (($key = array_search($clone_team_obj->captain, $tm)) !== false) {
                        unset($tm[$key]);
                    }

                    $vice_captain   = $tm[array_rand($tm,1)];

                    $captain        =  $clone_team_obj->captain;
                 //   $vice_captain   =  $clone_team_obj->vice_captain; 
                }else{
                    $captain        =  $clone_team_obj->vice_captain;       
                    $vice_captain   =  $clone_team_obj->captain;
                }

                $create_team->teams     = $clone_team_obj->teams;
                $create_team->captain   = $captain;
                $create_team->vice_captain = $vice_captain;
                /*$create_team->edit_team_count = $clone_team_obj->edit_team_count;*/
                $create_team->save();
            }     
        } 
    }

    public function editTeamFromAnother(Request $request){
        
        $validator = Validator::make($request->all(), [
            'match_id' => 'required'
        ]); 

        $match_id = $request->match_id;

        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return [
                    'session_expired'=>$this->is_session_expire,
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg
                ];
            
        }

        $ro_users = User::where('customer_type',3)->pluck('id')->toArray();
        $ct = \DB::table('create_teams')
                ->where('match_id',$match_id)
                ->whereIn('user_id',$ro_users)
                ->whereNotNull('is_cloned')
                ->get();

        foreach ($ct as $key => $value) {

            $rt = \DB::table('create_teams')->where('id',$value->is_cloned)
                    ->where('match_id',$match_id)
                    ->where('edit_team_count','>',1)
                    ->first();
                    
            if($rt){

                $create_team =  CreateTeam::find($value->id);
                
                $create_team->teams     = $rt->teams;
                $create_team->captain   = $rt->vice_captain;
                $create_team->vice_captain = $rt->captain;
             //   $create_team->trump = $rt->captain;
                $create_team->edit_team_count = $rt->edit_team_count;
                $create_team->save();
                $t[] = $create_team;
            }else{
                //$t[] = "No team edited: $value->id ";
            }
            
        }
        return $t??'No team edited';
    }
    public function identifyUser(Request $request){

        if($request->allow!='ninja11'){
            die('access deny');
        }

        $match_id = $request->match_id; //45587

        $usersRobo  = User::where('customer_type',3)->pluck('id')->toArray();

        $contest = CreateContest::where('match_id',$match_id)
                    ->where('total_spots','>',51)
                    ->get();

        $contest->transform(function ($item, $key) use($usersRobo) {

                $contest_name = \DB::table('contest_types')
                                ->where('id',$item->contest_type)
                                ->first();

                $join_contests_robo = JoinContest::whereIn('user_id',$usersRobo)->where('contest_id',$item->id)->where('match_id',$item->match_id)
                                    ->count();
                $join_contests_user = JoinContest::whereNotIn('user_id',$usersRobo)->where('match_id',$item->match_id)->where('contest_id',$item->id)
                                    ->count();

                $item->robo_user = $join_contests_robo;
                $item->main_user = $join_contests_user;      
                $item->entry =   $item->entry_fees;
                $item->contestName =   $contest_name->contest_type;
                $item->total_spots =   $item->total_spots;
                $item->filled_spot =   $item->filled_spot;
                
                return $item;

        } ); 

        //return $contest;          
       return ['status'=>  true,'code'=>200, 'message' => 'user and robo',
                'data' => $contest] ;
    }

    public function identifyRealUser(Request $request){

        $match_id   = $request->match_id; //45587
        $contest_id = $request->contest_id;

        $usersRobo  = User::where('customer_type',3)->pluck('id')->toArray();

        try{ 
            $item    = CreateContest::find($contest_id);

            $join_contests_robo = JoinContest::whereIn('user_id',$usersRobo)->where('contest_id',$item->id)->where('match_id',$item->match_id)
                                ->count();
            $join_contests_user = JoinContest::whereNotIn('user_id',$usersRobo)->where('match_id',$item->match_id)->where('contest_id',$item->id)
                                ->count();

            $item->robo_user = $join_contests_robo;
            $item->main_user = $join_contests_user;

            return $item;

        }catch (\ErrorException $e){
            return false;   
        }
    }

    public function deleteHeroTeam()
    {
        $match_id = 45596;
        $ct_user = CreateTeam::where('match_id',$match_id)
                ->whereNotNull('is_cloned')
                ->pluck('id')
                ->toArray();

        $user = User::where('customer_type',3)->pluck('id')->toArray();
        
        $jc = JoinContest::where('match_id',45596)
                ->where('contest_id',52815)
                ->whereNotIn('user_id',$user)
                ->sum('winning_amount'); 
               
        $wt = WalletTransaction::where('match_id',45596)
                    ->where('contest_id',52815)
                    ->where('payment_type',6)
                    ->whereNotIn('user_id',$user)
                    ->get();
        $u = [];
        foreach ($wt as $key => $value) {
                
                $Wallet = Wallet::where('user_id',$value->user_id)->whereIn('payment_type',[3,4]) 
                            ->sum('amount');
              if($Wallet==0){
                    $value->delete(); 
              }                                  
        }           
    }

    public function getContest(Request $request)
{
    $userId = $request->user_id;
    $matchId = $request->match_id;
    $cacheTTL = 600; // 10 minutes in seconds

    // Cache Key
    $cacheKey = "contest_data_{$matchId}_{$userId}";

    // Attempt to retrieve from cache
    if ($cachedData = Redis::get($cacheKey)) {
        return response()->json(json_decode($cachedData, true));
    }

    // Fetch User Passes & Free Entries Together
    $passes = \DB::table('passes')
        ->where('user_id', $userId)
        ->where('remaining_passes', '>=', 1)
        ->pluck('remaining_passes', 'pass_type')
        ->toArray();

    $freeEntries = \DB::table('free_entries')
        ->where('user_id', $userId)
        ->get()
        ->keyBy('contest_type_id');

    // Cache Optimized Contest IDs
    $contestTypeIds = [1, 8, 23];
    $cids = Cache::remember("cid_{$matchId}", 300, function () use ($contestTypeIds, $matchId) {
        return CreateContest::whereIn('contest_type', $contestTypeIds)
            ->where('match_id', $matchId)
            ->pluck('id')
            ->toArray();
    });

    // Check new user status and withdrawals in a single step
    $dateThreshold = now()->subDays(5);
    $userStats = JoinContest::where('user_id', $userId)
        ->where('created_at', '>=', $dateThreshold)
        ->whereIn('contest_id', $cids)
        ->exists();

    $withdrawAmount = Wallet::where('user_id', $userId)
        ->where('payment_type', 5)
        ->exists();

    $newEntryFees = (!$userStats && !$withdrawAmount) ? 1 : 1; // Adjust logic if needed

    // Fetch contest types
    $contestTypes = Cache::remember('contest_types', 600, function () {
        return \DB::table('contest_types')->orderBy('sort_by', 'asc')->get();
    });

    // Retrieve contests efficiently
    $contests = CreateContest::select([
            'contest_type as contest_type_id', 'is_cancelled as isCancelled',
            'usable_bonus', 'bonus_contest', 'gift_url', 'total_spots as totalSpots',
            'first_prize as firstPrice', 'sort_by', 'total_winning_prize as totalWinningPrize',
            'prize_percentage as winnerCount', 'entry_fees as entryFees', 'id as contestId',
            'filled_spot as filledSpots', 'winner_percentage as winnerPercentage',
            'cancellation', 'contest_category_type', 'discounted_price', 'extra_cash_usable',
            'offer_end_at', 'usable_extra_cash'
        ])
        ->where('match_id', $matchId)
        ->where('is_cancelled', 0)
        ->when(!in_array($userId, [285, 262, 11556]), function ($query) {
            return $query->whereColumn('total_spots', '!=', 'filled_spot');
        })
        ->orderBy('sort_by', 'asc')
        ->orderBy('filled_spot', 'DESC')
        ->orderBy('entry_fees', 'ASC')
        ->get()
        ->groupBy('contest_type_id'); // Group contests by contest_type_id for faster lookup

    // Process contest data
    $res = $contestTypes->map(function ($contestType) use ($contests, $newEntryFees, $contestTypeIds, $freeEntries, $passes) {
        if (isset($contests[$contestType->id])) {
            $filteredContests = $contests[$contestType->id]->map(function ($contest) use ($contestType, $newEntryFees, $contestTypeIds, $freeEntries) {
                $contest->title = in_array($contest->contest_type_id, [1, 21]) ? $contestType->contest_type : '';
                $contest->isCancelled = (bool) $contest->isCancelled;
                $contest->cancellation = (bool) $contest->cancellation;
                $contest->maxAllowedTeam = $contestType->max_entries;
                $contest->gift_url = $contest->gift_url ?? '';
                $contest->contest_category_type = '';
                $contest->bonus_contest = (bool) $contest->bonus_contest;
                $contest->is_leaderboard = $contest->contest_type_id == 21;

                if (in_array($contest->contest_type_id, $contestTypeIds)) {
                    $contest->entryFees = (int) ($contest->entryFees * $newEntryFees);
                }

                if (isset($freeEntries[$contest->contest_type_id])) {
                    $contest->entryFees = $freeEntries[$contest->contest_type_id]->fees;
                    $contest->maxAllowedTeam = min(11, count($freeEntries));
                }

                return $contest;
            })->values();

            return [
                'contest_type_id' => $contestType->id,
                'contestTitle' => $contestType->contest_type,
                'title' => $contestType->contest_type,
                'icon_url' => $contestType->emoji_url,
                'contestSubTitle' => $contestType->description,
                'contests' => $filteredContests->toArray()
            ];
        }
    })->filter()->values();

    // Cache & Fetch Teams and Contests
    $midUid = "{$matchId}_{$userId}";

    $createdTeams = Cache::remember("created_team_{$midUid}", $cacheTTL, function () use ($request) {
        $this->myjoinedTeamsCache($request);
        return Cache::get("created_team_{$request->match_id}_{$request->user_id}");
    });

    $myJoinedContest = Cache::remember("myjoinedContest_{$midUid}", $cacheTTL, function () use ($request) {
        $this->myjoinedContestCache($request);
        return Cache::get("myjoinedContest_{$request->match_id}_{$request->user_id}");
    });

    // Match status
    $matchInfo = $this->setMatchStatusTime($matchId);

    $resultSet = [
        'session_expired' => $this->is_session_expire,
        'system_time' => time(),
        'match_status' => $matchInfo['match_status'] ?? null,
        'match_time' => $matchInfo['match_time'] ?? null,
        'status' => true,
        'code' => 200,
        'message' => 'Success',
        'response' => [
            'matchcontests' => $res,
            'myjoinedTeams' => $createdTeams,
            'myjoinedContest' => $myJoinedContest
        ]
    ];

    Redis::setex($cacheKey, $cacheTTL, json_encode($resultSet));

    return response()->json($resultSet);
}



    public function getContestOld(Request $request){
        

        try
            {
                $memcached = new Memcached();
                $memcached->addServer("127.0.0.1", 11211); 
                $response = $memcached->get("sample_key");
             
                if($response==true) 
                {
                  echo $response;
                } 

                else

                {
                echo "Cache is empty";
                $memcached->set("sample_key", "Sample data from cache") ;
                }
                die;
            }
            catch (exception $e)
            {
                echo $e->getMessage();
            }
    
        $contests = CreateContest::where('match_id',$match_id)
                      //  ->whereIn('contest_type',$contest_types_id)
                        ->get();
        return ($contests);
    }
    public function revertPrize(Request $request){
        
        $match_id  = $request->match_id;

        $check = WalletTransaction::where('match_id',$match_id)
                                    ->where('payment_type',9)
                                    ->get();
        if($check->count())
        {
            JoinContest::where('match_id',$match_id)->update(['prize_amount'=>0,'winning_amount'=>0]);
            return response()->json(['Amount already reverted']);
        }
        
        return false;
        $wt =  WalletTransaction::where('match_id',$match_id)
                ->where('payment_type',4)
                ->chunk(100,function($wt){

                    foreach ($wt as $key => $data) {
                        # code...
                        $wallet = Wallet::where('user_id',$data->user_id)
                                ->where('payment_type',4)
                                ->first();
                       
                        $amount = $wallet->amount - $data->amount;
                        $wallet->amount = $amount;
                        $wallet->save();

                        $t =   new WalletTransaction;
                        $t->user_id = $data->user_id;
                        $t->amount  = $data->amount;
                        $t->payment_type = 9;
                        $t->payment_type_string = 'Prize Amount Reverted';
                        $t->match_id    = $data->match_id;
                        $t->contest_id  = $data->contest_id;
                        $t->refund_id   = $data->user_id;
                        $t->debit_credit_status   = '-';
                        $t->transaction_id   = time();
                        $t->payment_status = 'success';
                        $t->save();
                    }
                });
        echo "payment refunded";
    }
    public function getMyTeamNinja(Request $request){

        $match_id =  $request->match_id;
        $user_id  = $request->user_id;
         
        $userVald = User::find($user_id);
        $matchVald = Matches::where('match_id',$request->match_id)->count();

        if(!$userVald || !$matchVald){
            return [
                'status'=>false,
                'code' => 201,
                'message' => 'user id or match id is invalid'
    
            ];
        }

        if($request->type=="close"){
            $myTeam   =  CreateTeam::where('match_id',$match_id)
                        ->whereIn('id',$request->close_team_id)   
                        ->where('user_id',$user_id )
                        ->get();
        }elseif($request->type=="open"){
            $myTeam   =  CreateTeam::where('match_id',$match_id)
                        ->whereIn('id',$request->open_team_id)
                        ->where('user_id',$user_id)
                        ->get(); 
            
        }else{
            $myTeam   =  CreateTeam::where('match_id',$match_id)
            ->where('user_id',$user_id )
            ->where('id',$request->team_id)
            ->get();
        }

        $user_name = User::find($user_id);
        $data = [];
        foreach ($myTeam as $key => $result) {
            $player_ids = [];
            $team_id =  json_decode($result->team_id,true);
            $teams = json_decode($result->teams,true);
            if($team_id==null or $teams==null){
                continue;
            }

            $captain = $result->captain;
            $trump = $result->trump;
            $vice_captain = $result->vice_captain;
            $team_count = $result->team_count;
            $user_id    = $result->user_id;
            $match_id   = $result->match_id;
            $points     = $result->points;
            $rank       = $result->rank;

            $k['created_team'] = ['team_id' => $result->id];

            $playing11 = $this->getPlaying11Team($result->match_id);
            if(count($playing11)){
                $playing11 = $playing11;
            }else{
                $playing11 = false;
            }

            $player = Player::WhereIn('team_id',$team_id)
                ->whereIn('pid',$teams)
                ->where('match_id',$result->match_id)
                ->groupBy('pid','id')
                ->pluck('id','pid')->toArray();  
            
            foreach ($player as $key => $rs) {
                $player_ids[] = $rs;
            }   
            $player = Player::whereIn('id',$player_ids)->get();

            foreach ($player as $key => $value) {
                if(is_array($playing11) && count($playing11) && isset($playing11[$value->pid])){
                  
                }
                                
                if($value->playing_role=="cap"){
                    $team_role["bat"][] = $value->pid;
                }
                elseif($value->playing_role=="wkcap"){
                    $team_role["wk"][] = $value->pid;
                }
                elseif($value->playing_role=="wkbat"){
                    $team_role["wk"][] = $value->pid;
                }else{   
                    $team_role[$value->playing_role][] = $value->pid;
                }
            }
            foreach ($team_role as $key => $value) {
                $k[$key] = $value;
            }
            $team_role = [];
            $c = Player::WhereIn('team_id',$team_id)
                ->whereIn('pid',[$captain,$vice_captain,$trump])
                ->where('match_id',$result->match_id)
                ->pluck('short_name','pid');
                            
            $k['c']     = ['pid'=> (int)$captain,'name' => $c[$captain]];
            $k['vc']    = ['pid'=>(int)$vice_captain,'name' => $c[$vice_captain]];

            $t_a = TeamA::WhereIn('team_id',$team_id)
                ->where('match_id',$result->match_id)
                ->first();

            $t_b = TeamB::WhereIn('team_id',$team_id)
                ->where('match_id',$result->match_id)
                ->first();

            $tac = Player::Where('team_id',$t_a->team_id)
                    ->whereIn('pid',$teams)
                    ->where('match_id',$result->match_id)
                    ->whereIn('id',$player_ids)
                    ->get();

            $tbc = Player::Where('team_id',$t_b->team_id)
                    ->whereIn('pid',$teams)
                    ->where('match_id',$result->match_id)
                    ->whereIn('id',$player_ids)
                    ->get();

            // team count with name
            $t[]   = ['name' => $t_a->short_name, 'count' => $tac->count()];
            $t[]   = ['name' => $t_b->short_name, 'count' => $tbc->count()];

            $k['match']   = [$t_a->short_name.'-'.$t_b->short_name];
            $k['team']    = $t;
            $k['c_img']   = "";
            $k['vc_img']  = "";
            // username
            $tname = $user_name->team_name??$user_name->name;
            $k['team_name'] =  $tname. '('.$result->team_count.')';
            $k['points']    = $points;
            $k['rank']      = $rank;
            $data[] = $k;
            $t      = [];
        }

        $match_info = $this->setMatchStatusTime($match_id);
            return response()->json(
                [
                'system_time'=>time(),
                'match_status' => null,
                'match_time' => null,
                "status"=>true,
                "code"=>200,
                "teamCount" => $myTeam->count(),
                "message"=>"success",
                "response"=>["myteam"=>$data]
            ]
        );
    } 

    /*Rail Logic*/
    public function railLogic(Request $request){
        //return false;
        $team_id    = $request->team_id;
        $match_id   = $request->match_id;
        $contest_id = $request->contest_id;

 

        $ct = CreateTeam::where('rail_id',$team_id)
                        ->where('contest_id',$contest_id)
                        ->count();                 
        if($ct){
                return [
                    'status' => false,
                    'code' => 201,
                    'message' => 'Rail already used'
                ];
        }


        $validator = Validator::make($request->all(), [
            'team_id'       => 'required',
            'match_id'      => 'required',
            'contest_id'    => 'required'
        ]);

        // Return Error Message
        if ($validator->fails() ||  $team_id==null) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array( 
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]
                )
            );
        }

        $team_name  = CreateTeam::find($team_id);

        if($team_name==null){
            die('Team not found!!');
        }

        $data = array_filter(json_decode($team_name->teams,true));
        $captain        = $team_name->captain;
        $vice_captain   = $team_name->captain; 

        $ct_user = JoinContest::where('match_id',$match_id)
                    ->where('contest_id',$contest_id)
                    ->where('created_team_id',$team_id)
                    ->pluck('user_id')
                    ->toArray();

        $ninja_user  = User::where('customer_type',3)
                        ->whereNotIn('id',$ct_user) 
                        ->orderBy('id','asc')
                        ->pluck('id')
                        ->toArray();
 
        $is_break  = 0;
        $with_edit = $request->with_edit;
        $with_same = $request->with_same;
        $index = array_rand($ninja_user);

        foreach ($data as $key => $value) {
            
            $teamcc = "T1";
            $t1 = $key++; 
            if($t1==2){ 
                $teamcc = 'T2';
            }else{
                $index = array_rand($ninja_user);
                if($t1%3==0)   
                {
                  $teamcc = "T3"; 
                }elseif($t1%5==0)   
                {
                  $teamcc = "T5"; 
                }    
            }
            
            if($vice_captain == $value || $captain == $value){
                continue;
            }
            $vice_captain = $value;
            $captain    = $team_name->captain;
            $ct         =  new CreateTeam;
              

            if($with_edit){
                //$index = array_rand($request->ninja_user)??$index;

                $user_id        = $request->ninja_user_id??$ninja_user[$index];

                $ct->is_cloned  = $team_name->id;
                $is_break=1;

                $vice_captain   = $team_name->captain;
                $captain        = $team_name->vice_captain;
                $tcount = 'T1';
            }elseif($with_same){
                //$index = array_rand($request->ninja_user)??$index;
                //$vice_captain   =   ;
                $captain        =   $team_name->captain;

                $tm = array_filter(json_decode($team_name->teams));
                if (($key = array_search($team_name->vice_captain, $tm)) !== false) {
                    unset($tm[$key]);
                }
                if (($key = array_search($team_name->captain, $tm)) !== false) {
                    unset($tm[$key]);
                }
                
                $vice_captain   = $tm[array_rand($tm)]??$team_name->vice_captain;

                $user_id        = $request->ninja_user_id??$ninja_user[$index];

                $ct->is_cloned  =   $request->team_id;
                $is_break       =   1;    
                
                $ct->editable   =   2;
                $tcount = 'T2';
            }else{
                $tcount         = $teamcc??'T1';
                $ct->rail_id    = $team_name->id;
                $ct->contest_id = $contest_id??null;
                $user_id        = $ninja_user[$index];   
            }

            $ct->match_id   = $team_name->match_id; 
            
            $ct->team_id    = $team_name->team_id;
            $ct->teams      = $team_name->teams;
            $ct->captain    = $captain;
            $ct->vice_captain = $vice_captain; 
            $ct->team_count = $tcount;

            $x=0 ;               
            while($x==0) {
                $CreateTeam = CreateTeam::where('match_id',$team_name->match_id)
                            ->where('user_id',$user_id)
                            ->where('team_count',$tcount)
                            ->first(); 
                if($CreateTeam){
                    $index      = array_rand($ninja_user);
                    $user_id    = $ninja_user[$index];
                }else{
                    $x++;
                }

            } 
            $ct->user_id    = $user_id;
            
            if($CreateTeam){
                    continue;
            }else{
                $ct->save();
            }  
             
            $teams_id[] = $ct->id;
            $request->merge(['created_team_id' => $teams_id]); 
            $request->merge(['match_id' => $team_name->match_id]); 
            $request->merge(['contest_id' => $contest_id]); 
            $request->merge(['user_id' => $user_id]);
            $s = $this->joinContestFK($request);
            $teams_id = [];
            if($is_break){
                break;
            }
        }
        if($is_break){
            return true;
        }else{
            return true;
          
        }
    }

    public function array_random($array, $amount = 1)
    {
        $keys = array_rand($array, $amount);
        
        if ($amount == 1) {
            return $array[$keys];
        }

        $results = [];
        foreach ($keys as $key) {
            $results[] = $array[$key];
        }

        return $results;
    }

    public function  joinContestFK(Request  $request)
    {   
        $match_id           = $request->match_id;
        $user_id            = $request->user_id;
        $created_team_id    = $request->created_team_id;
        $contest_id         = $request->contest_id;
        $max_t = $this->maxAllowedTeam($request);

        $user_details = User::find($user_id);

        $validator = Validator::make($request->all(), [
            'match_id' => 'required',
            'user_id' => 'required',
            'contest_id' => 'required',
            'created_team_id' => 'required'

        ]);  
        // Return Error Message
        if ($validator->fails() || !isset($created_team_id)) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                   // 'session_expired'=>$this->is_session_expire,
                    'system_time'=>time(),
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]??'Team id missing'
                )
            );
        }

        $check_join_contest = \DB::table('join_contests')
            ->whereIn('created_team_id',$created_team_id)
            ->where('match_id',$match_id)
            ->where('user_id',$user_id)
            ->where('contest_id',$contest_id)
            ->get();

        if(count($created_team_id)==1 AND  $check_join_contest->count()==1){
            return [
              //  'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'This team already Joined'

            ];
        }

        $cc = CreateContest::find($contest_id);

        if($cc && ($cc->total_spots!=0 && $cc->filled_spot>=$cc->total_spots)){
            return [
               // 'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'This contest already full'

            ];
        }

        if($max_t!==true){
           // return $max_t;
           // exit();
        }

        $userVald = User::find($request->user_id);
        $matchVald = Matches::where('match_id',$request->match_id)->count();

        if(!$userVald || !$matchVald || !$contest_id){
            return [
               // 'session_expired'=>$this->is_session_expire,
                'status'=>false,
                'code' => 201,
                'message' => 'user_id or match_id or contest_id is invalid'

            ];
        }
        
        $data = [];
        $cont = [];

        $ct = \DB::table('create_teams')
            ->whereIn('id',$created_team_id)->count();

        if($ct)
        {   
            foreach ($created_team_id as $key => $ct_id) {
               \DB::beginTransaction();
                $is_full = CreateContest::find($contest_id);
                
                if($is_full==null){
                    return [
                       // 'session_expired'=>false,
                        'status'=>false,
                        'code' => 201,
                        'message' => 'invalid contest'
                    ];
                }
                
                if($is_full && $is_full->total_spots>0  && ($is_full->total_spots==$is_full->filled_spot)){
                    return [
                       // 'session_expired'=>false,
                        'status'=>false,
                        'code' => 201,
                        'message' => 'This Contest is already full'
                    ];
                }
                // free contest validation, if more than two team 
                $check_max_contest = \DB::table('join_contests')
                        ->where('match_id',$match_id)
                        ->where('user_id',$user_id)
                        ->where('contest_id',$contest_id)
                        ->count(); 

                $contestT = CreateContest::find($contest_id);
                
                $contestTyp = \DB::table('contest_types')->where('id',$contestT->contest_type)->first();
                if(
                    isset($check_max_contest) 
                    && $check_max_contest>=$contestTyp->max_entries
                    || isset($request->created_team_id) && count($request->created_team_id) >$contestTyp->max_entries
                ){

                    return [
                       // 'session_expired'=>$this->false,
                        'status'=>false,
                        'code' => 201,
                        'message' => "Only $contestTyp->max_entries teams are allowed"
                    ];
                }                

                $check_join_contest = \DB::table('join_contests')
                    ->where('created_team_id',$ct_id)
                    ->where('match_id',$match_id)
                    ->where('user_id',$user_id)
                    ->where('contest_id',$contest_id)
                    ->first();

                if($check_join_contest){
                    continue;
                }
                $data['match_id'] = $match_id;
                $data['user_id'] = $user_id;
                $data['created_team_id'] = $ct_id;
                $data['contest_id'] = $contest_id;

                $ctid  = CreateTeam::find($ct_id);
                $data['team_count'] = $ctid->team_count??null;

                    $total_fee          =  $cc->entry_fees;
                    $payable_amount     =  $total_fee; 

                    if($contestT->bonus_contest){
                        $deduct_from_bonus  =  $payable_amount*($contestT->usable_bonus/100);
                    }else{
                        $per = $contestT->usable_bonus;
                        $deduct_from_bonus  =  $payable_amount*($per/100);
                    }
                    
                    $final_paid_amount  =  $payable_amount;

                    $item = Wallet::where('user_id',$user_id)->get();
                    $bonus_amount = $item->where('payment_type',1)->first();
                    $refer_amount = $item->where('payment_type',2)->first();
                    $depos_amount = $item->where('payment_type',3)->first();
                    $prize_amount = $item->where('payment_type',4)->first();

                  //  $ref_prize_depos = $item->whereIn('payment_type',[2,3,4])->get();
                       
                    $transaction_amt = 0;
                    if($bonus_amount && $bonus_amount->amount>$deduct_from_bonus && !$contestT->bonus_contest){
                        $final_paid_amount = $final_paid_amount-$deduct_from_bonus;

                        $bonus_amount->amount = $bonus_amount->amount-$deduct_from_bonus;
                    }else{
                        $final_paid_amount = $final_paid_amount;
                    }
 

                 //   $cc->save(); 
                    // transaction histoory
                    $contest_id = $request->contest_id;
                    $match_id = $request->match_id;

                    if($final_paid_amount){
                        $wt =  new WalletTransaction;
                        $wt->user_id = $user_id;
                        $wt->amount  = $total_fee;
                        $wt->match_id  =$match_id??null;
                        $wt->contest_id  =$contest_id??null;
                        $wt->payment_type = 6;
                        $wt->payment_type_string = 'Join Contest';
                        $wt->transaction_id = $match_id.'N'.$contest_id;
                        $wt->payment_mode =  'N';
                        $wt->payment_status =  'Success';
                        $wt->debit_credit_status = "-";
                       // $wt->payment_details = json_encode($request->all());
                       
                        $wt->save();
                    } 

                $jcc = \DB::table('join_contests')
                    ->where('match_id',$match_id)
                    ->where('contest_id',$contest_id)
                    ->where('user_id',$user_id)
                    ->count();
               // if($jcc<=$cc->total_spots || $cc->total_spots==0){
                // join contest   
                $data['user_name'] = $userVald->name;
                $data['team_name'] = $userVald->team_name;

                $t =   JoinContest::updateOrCreate($data,$data);

               // }
                // End spot count
                $cont[] = $data;
                $ct = \DB::table('create_teams')
                    ->where('id',$ct_id)
                    ->update(['team_join_status'=>1]);

                $cc->filled_spot = CreateTeam::where('match_id',$match_id)
                    ->where('team_join_status',1)->count();
                $cc->save();

                $is_full = CreateContest::find($contest_id);
                $c_count = (int)$is_full->is_full+1;
                $is_full->is_full = $c_count;
                $is_full->filled_spot =  $c_count;
                $is_full->save();
            \DB::commit();
            }
            $message = "Contest Joined successfully!";
        }else{
            $cont = ["error"=>"contest id not found"];
            $message = "Something went wrong!";
        }
        // return 
        //         [
        //         "status"=>true,
        //         "code"=>200,
        //         "message"=>$message,
        //         "response"=>["joinedcontest"=>$cont]
        //     ]
        // ;
    }

    public function messageApi(Request $request){

        $m_api =         Cache::get('msgAPI_'.$request->user_id);

        if($m_api){
            return $m_api;
        }

        $version_code = (object)$request->deviceDetails;
        $version_code = $version_code->versionCode??null;

        $pass_count = \DB::table('free_entries')->where('user_id',$request->user_id)->count();

        if($pass_count)
        {
            $msg = "Available GL Passes ".$pass_count. ". Join Telegram & get ₹5🚨";
        }else
        {
            $msg = "🚨Deposit ₹999 and get ₹1111. Hurry up!!";
        }


        if(($version_code<=6002) && $version_code!=null){
            $data[] = [
            "message_type" => "HTML",
            "message_status" => 1,
            'message' => '📢 New Update available  <a href="https://link.ninja11.in"> Download and Install </a>'
            ] ;  
        }else{
            $data[] = [
            "message_type" => "HTML",
            "message_status" => 1,
            'message' => '📢 <a href="#">'.$msg.'</a>'
            ] ;   
        }  

       
        
        //
        $data[] = [
            "message_type" => "HTML",
            "message_status" => 1,
            'message' => '⚠️ *Only UPI and Bank withdrawal Allowed.<br>
                          ✔ On Sunday, withdrawal allow less than 1000 Only <br>
                          ✔ Minimum UPI withdrawal ₹200 <br>
                          ✔ No Kyc Withdrawal charge ₹10 <br>
                          ✔ Instant Withdrwal Charge ₹10 or 2% <br>
                          ₹ Convert winning into Deposit and get 10% Extra in Deposit </p>
                           ✔Verify your bank details before Withdrawal!! <br> '
        ];
                                                                    
        
        $data[] = [
            "message_type" => "HTML",
            "message_status" => 1,
            'message' => 'Withdrawal is on hold for 24 hours'
        ] ;

        //

        $offer_banners_cache = Cache::get('offer_banners');
        if($offer_banners_cache)
        {
            $offer_banners =   $offer_banners_cache;  
        }else{
            $offer_banners = \DB::table('offer_banners')
                        ->select('message_type','status as message_status','url as image_url','description as message')
                        ->orderBy('id','desc')
                        ->get()->toArray();

            Cache::put('offer_banners',$offer_banners,5000);    
        }
        


        $data[] = [
            "message_type" => "HTML",
            "message_status" => 1,
            "message" => "",
            'offer_array' =>  $offer_banners
        ] ;

       $msg_api =  [

            "status" => true,
            "code" => 200,
            "data" => $data
        ];

        Cache::put('msgAPI_'.$request->user_id, $msg_api, 10000); 

        //$memcached->set('msg_api', $msg_api, 100) ;
        return $msg_api;
    }

    public function deleteDBEntry(Request $request)
    {
        $uag = $request->server();
                
        
        $rbu = User::where('customer_type',3)->pluck('id')->toArray();

        $wt = WalletTransaction::whereIn('user_id',$rbu)->delete();

        if($request->match_id){    
        }

        return $delpp??$wt;  

    }

    public function getRazorPay(Request $request){
        $api = new Api('rzp_live_SiMilNQfyJNzJe', 'lrZuzY0DssKHVufW5lISDzOA');    
            $id = (string)$request->razorpay_id;
            
        try {
               $data = $api->payment->fetch($id);

               $datas =  [
                'status' =>  $data->status,
                'id'     =>  $data->id,
                'amount' =>  $data->amount 
               ] ;

                return $datas;
         } catch (\Razorpay\Api\Errors\BadRequestError $e) {
                 $datas =  [
                    'status' =>  'failed',
                    'id'     =>  0,
                    'amount' =>  1 
                   ] ;
                return $datas;
         } 
    }

    public function myUser(Request $request)
    {
        $referral = $request->code??'MAAHI';

        $u = User::select('id','name','team_name')->where('reference_code',$referral)->get();
         
        $u->transform(function ($item, $key)   {  
                $wt = WalletTransaction::where('user_id',$item->id)
                    ->where('payment_type',3)
                    ->sum('amount');
                 $item->deposit = round($wt,2);   

                 $winning = WalletTransaction::where('user_id',$item->id)
                    ->where('payment_type',4)
                    ->sum('amount');
                 $item->winning = round($winning,2);

                 return $item;
        });              

        $total_deposit =  $u->sum('deposit');
        $total_winning =  $u->sum('winning');

        echo "<table border='1' cellspacing='3' cellpadding='5' align='center'><td>My Referral Code</td><td>Total User Deposit</td><td>Total User Winning</td></tr>";
         
            echo '<tr>'; 
            echo '<td>'. $referral. '</td>';
            echo '<td>'. $total_deposit . '</td>';
            echo '<td>'. $total_winning . '</td>'; 
            echo '<tr>';
         

         echo '</table>';


        echo "<table border='1' cellspacing='3' cellpadding='5' align='center'><tr><td>Sr no</td><td>Name</td><td>Team name</td><td>Deposit</td><td>Winning</td></tr>";
         foreach ($u as $key => $value) {
            
            echo '<tr>';
            echo '<td>'. ++$key . '</td>';
            echo '<td>'. $value->name . '</td>';
            echo '<td>'. $value->team_name . '</td>';
            echo '<td>'. $value->deposit . '</td>';
            echo '<td>'. $value->winning . '</td>';
            echo '<tr>';
         }  

         echo '</table>';

    }

    public function checkBouncePayment(Request $request){

        $bouce = \DB::table('razorpay_success')
                    ->where('credit',1)
                    ->get();

        foreach ($bouce as $key => $value) {
            $email = $value->email;
            $txt_id = $value->txt_id;
            $amount = $value->amount;

            $user = User::where('email',$email)->first();
            $amt = round(($amount/100),2);

            $wt = WalletTransaction::where('transaction_id',$txt_id)
                    ->first();

            if($wt){

                \DB::table('razorpay_success')
                ->where('txt_id',$txt_id)
                ->update([
                    'credit' =>2
                ]);

               
            }else{
                $helper = new Helper;
                $send_status = $helper->notifyToAdmin("The $amt INR is bounced","
                    Mr. $user->name ($email) has added amount from rozar pay but not credited in his wallet.
                    - Yuvraj or Maahi.
                    - Please Add Fund.
                    ");

              /*  $helper = new Helper;
                $device_id = "dBZpjgCrQxWuE6KBjyBcSx:APA91bFBnDYI_J-9wQJDqgXJIcRytfc_nH6WRMOQB1vMyaiJNg5lgSRcQZI9Y-qAWln27ZcRVYHSTM8VYI_5NYjd8LvIvSwfyBNFcoSgxe-XrRv3iNNImgkR2QV_PJO4HVTKbIm2eVGx";
                $t = $this->sendNotification($device_id, $data, $data);*/

            } 
        }
    }

    public function updateRazorPay(Request $request){

      //  $this->checkBouncePayment($request);
        $razor_pay = \DB::table('razorpay_success')
            ->where('credit',10)
            ->get();

        foreach ($razor_pay as $key => $rp) {
            $email          = $rp->email;
            $capture_time   = $rp->capture_time;
            $txt            = $rp->txt_id;
            $amount         = $rp->amount/100;
 
            $t1 =  time();
            $t2 =  $capture_time;
            $td = round((($t1 - $t2)/60),2); 
           
            if($td>2){ 
                $user = User::where('email',$email)
                        ->select('email','id','device_id')
                        ->first();

                $wallet = Wallet::firstOrNew([
                            'user_id' => $user->id,
                            'payment_type' => 3
                        ]);
                
                $wallet->amount = $wallet->amount+$amount;

                \DB::table('razorpay_success')
                    ->where('txt_id',$txt)
                    ->update([
                        'credit' =>2,
                        'user_id' => $user->id
                    ]);

                $wt =  new WalletTransaction;
                $wt->user_id = $user->id;
                $wt->amount  = $amount;
                $wt->payment_type = 3;
                $wt->payment_type_string = 'Deposit';
                $wt->transaction_id = $txt;
                $wt->payment_mode =  'razorpay';
                $wt->payment_status =  'Success';
                $wt->debit_credit_status = "+";

              //  $wt->save();    


                $wtt = WalletTransaction::where('transaction_id',$txt)->count();
                if($wtt>=1){
                    //  
                }else{
                    $wt->save();
                    $wallet->save();  
                }

                $device_id = $user->device_id??null;
                        $data = [
                                    'action'    => 'notify' ,
                                    'title'     => "Amount added in wallet",
                                    'message'   => "INR $amount is credited in your wallet "
                                ];
                
                $this->sendNotification($device_id, $data);
            }
        }


        $paytm = \DB::table('initiate_transactions')->where('status',1)
                    ->get();

        foreach ($paytm as $key => $value) {
                   
            $user_id    = $value->user_id;
            $oid        = $value->order_id;
            $amount     = $value->amount;
            $request->merge(['order_id' => $oid]);
            $request->merge(['order' => $oid]);
            $request->merge(['user_id' => $user_id]);

            $payment_status = $this->statusCheck($request);

            if(isset($payment_status['STATUS'])&& $payment_status['STATUS']=="TXN_FAILURE"){

                    \DB::table('initiate_transactions')
                        ->updateOrInsert(
                        [
                            'user_id'  => $user_id,
                            'order_id' => $oid
                        ],
                        [
                            'status' => 2
                        ]
                    );
                    \DB::table('all_in_one_paytm')
                            ->where('order_id',$oid)   
                            ->update([
                                'status' => 'failed'
                            ]); 
                        continue;
            }
             
            if(isset($payment_status['ORDERID']) && $payment_status['ORDERID']==$oid && $payment_status['STATUS']=='TXN_SUCCESS'){

                $check_payment_status = \DB::table('wallet_transactions')
                                        ->where('transaction_id',$payment_status['TXNID'])
                                        ->count();
                if($check_payment_status>=1){
                    
                    \DB::table('initiate_transactions')
                        ->updateOrInsert(
                        [
                            'user_id'  => $user_id,
                            'order_id' => $oid
                        ],
                        [
                            'status' => 2
                        ]
                    );
                        \DB::table('all_in_one_paytm')
                            ->where('order_id',$oid)   
                            ->update([
                                'status' => 'success'
                            ]); 

                        continue;
                }else{ 

                    $wallet = Wallet::firstOrNew([
                            'user_id' => $user_id,
                            'payment_type' => 3
                        ]);
                
                    $wallet->amount = $wallet->amount+$amount;
                    $wallet->deposit_amount = $wallet->deposit_amount+$amount;
                    $txt = $payment_status['TXNID']??time();

                    $wt =  new WalletTransaction;
                    $wt->user_id = $user_id;
                    $wt->amount  = $amount;
                    $wt->payment_type = 3;
                    $wt->payment_type_string = 'Deposit';
                    $wt->transaction_id = $txt;
                    $wt->payment_mode =  'paytm';
                    $wt->payment_status =  'Success';
                    $wt->debit_credit_status = "+";

                    //$wt->save();    

                    $wtt = WalletTransaction::where('transaction_id',$txt)->count();
                    if($wtt>=1){
                        //$wallet->save();    
                    }
                    else{
                        $wt->save();
                        $wallet->save();  
                    }

                    \DB::table('initiate_transactions')
                        ->updateOrInsert(
                        [
                            'user_id'  => $user_id,
                            'order_id' => $oid
                        ],
                        [
                            'status' => 2
                        ]
                    );

                }

            }else{
                if($payment_status['N_STATUS']==2){
                    \DB::table('initiate_transactions')
                    ->where('order_id',$oid)
                    ->delete();   
                }
            }
        }        
        echo "amount credited";
    }


    public function copyTeam(){
            
        $cancel_match = Matches::where('status',3) 
                       ->get()
                        ->transform(function($item,$key){
                            $t1 = $item->manual_date??$item->timestamp_start;
                            $t2 = time();
                            $td = round((($t1 - $t2)/60),2); 
                           
                            if($td<0 && $td > (-5)){
                                  
                                $ct = CreateTeam::where('match_id',$item->match_id)->get();
                                
                                foreach ($ct as $key => $value_ct) {
                                    $value_ct_id = $value_ct;
                                    
                                    $value_ct =  $value_ct->toArray(); 
                                    $ct_new = \DB::table('create_teams_bkup')
                                    ->updateOrInsert([
                                        'id'=>$value_ct_id->id
                                    ],$value_ct); 
                                }
                            }
                        
                    }); 
         echo "copy";
    }

    public function myAffiliate(Request $request){


        $match_id = $request->match_id;

        $result = \DB::table('join_contests')
            ->select('user_id', 'match_id', 'created_team_id', DB::raw('COUNT(*) as total_entries'))
            ->where('match_id', $match_id)
            ->where('team_count', 'T1')
            ->where('entry_fees', '>=', 77)
            ->groupBy('user_id', 'match_id', 'created_team_id')
            ->orderBy('match_id')
            ->orderBy('total_entries', 'DESC')
            ->limit(1)
            ->get();

            
        foreach($result as $key => $rs)
        {
            $tid = $rs->created_team_id;

            $team = CreateTeam::find($tid);
            
             CreateTeam::updateOrCreate(
                [
                    'user_id' => 160193,
                    'team_count' => 'T1',
                    'match_id' => $team->match_id
                ],
                [
                    'match_id'      =>  $team->match_id,
                    'user_id'       =>  160193,
                    'team_id'       =>  $team->team_id,
                    'teams'         => $team->teams,
                    'captain'       =>  $team->captain ,
                    'vice_captain'  =>  $team->vice_captain,
                    'team_count'    =>  'T1',
                    'team_join_status' => $team->team_join_status,
                    'edit_team_count' => $team->edit_team_count,
                ]

            );
    

        }
            
            
        die('---------------');
       
      $grouped = Wallet::orderBy('id', 'desc')->get()->groupBy('user_id');

        foreach ($grouped as $userId => $rows) {

            // take latest row
            $latest = $rows->first();


            Wallet::updateOrCreate(
                [
                    'user_id' => $latest->user_id,
                    'payment_type' => 1
                ],
                [
                    'amount' => '100',
                    'validate_user' => $latest->validate_user,
                    'payment_type' => 1,
                    'payment_type_string' => 'Bonus',
                ]
            );
        }

    }

    /*
    Auto Confirm Contest 3,4,5
    */
     public function autoConfirmContest(Request $request){
       // sleep(15);
        
        $cancel_match = Matches::where('status',3)
                        ->where('timestamp_start','<',time())
                       ->get()
                        ->transform(function($item,$key) use($request) {
                            sleep(1);
                            $t1 = $item->manual_date??$item->timestamp_start;
                            $t2 = time();
                            $td = round((($t1 - $t2)/60),2); 
            if($td<0 && $td>(-2))
            {   
                $match_id = $item->match_id;
                $request->merge(['match_id'=>$match_id]);
                $this->autoJoinContest($request);
            }
            if($td<(-5) && $td > (-30)){ 
                $contests = CreateContest::where('match_id',$item->match_id)
                ->where('cancellation',1)
                ->where('is_cancelled',0)
                ->get()
                ->transform(function($item,$key){
                    $request =  new Request;

                    if($item->total_spots>=10 and $item->total_spots<=111){
                         
                        $request->merge(['contest_id'=>$item->id]);
                        $acr = $this->autoResizeContest($request);

                    }elseif($item->total_spots>=3 && $item->total_spots<=6 && $item->filled_spot>=2){
                         
                        $request->merge(['contest_id'=>$item->id]);
                        $acr = $this->autoResizeContest($request);

                    }
                });
               
            }
            $match_id = $item->match_id;
            if($td >= (-30)){ 

                $over_starta = \DB::table('team_a')->where('overs','>',1)->where('match_id',$match_id)->count();
                $over_startb = \DB::table('team_b')->where('overs','>',1)->where('match_id',$match_id)->count();

                if($over_starta || $over_startb){
                    $this->matchAutoCancel(); 
                }   
            }  
        });
    }

    public function autoResizeContest(Request $request){
        
        $contest_id = $request->contest_id;

        $ct = CreateContest::find($contest_id);
        
      //  PrizeBreakup::where('default_contest_id',$ct)
        if($ct->total_spots==$ct->filled_spot)
        {
            $winning_priz = (int)($ct->total_winning_prize);//

            return false;   
        }else{
            $winning_priz = (int)($ct->entry_fees*$ct->filled_spot*(0.85));

        }

        
        $data['contest_type']   = $ct->contest_type;
        $data['entry_fees']     = $ct->entry_fees;
        $data['total_spots']    = $ct->filled_spot;
        $data['first_prize']    = $winning_priz;
        $data['prize_percentage'] = 1;//$ct->prize_percentage;
        $data['winner_percentage'] = 50;
        $data['cancellation']   = $ct->cancellation;
        $data['total_winning_prize'] = $winning_priz;
        $data['match_id']       = $ct->match_id;
        $data['bonus_contest']  = $ct->bonus_contest;
        $data['usable_bonus']   = $ct->usable_bonus;
        // last default ID
        $last_id = \DB::table('default_contents')->insertGetId($data);
        // Contest update
        $ct->total_spots        = $ct->filled_spot;
        $ct->default_contest_id = $last_id;
        $ct->is_cancelled  = 0;
        $ct->total_winning_prize = $winning_priz;
        $ct->first_prize = $winning_priz;
        $ct->save();

        // Prize Break up Dyanamic Create
        $prize_breakups = new PrizeBreakup;
        $prize_breakups->default_contest_id = $last_id;
        $prize_breakups->contest_type_id = $ct->contest_type;
        $prize_breakups->rank_from = 1;
        $prize_breakups->rank_upto = 1;
        $prize_breakups->prize_amount = $ct->first_prize;
        $prize_breakups->match_id = $ct->match_id;
        $prize_breakups->contest_id = $ct->id;
        $prize_breakups->save();

        return $ct;
    }
    /*
    Add Rewards Point
    */
    public function addRewardPoints(Request $request){

        $match = Matches::where('status',3)
                ->whereDate('date_start',\Carbon\Carbon::today())
                ->where('is_cancelled',0)
                ->get();
        $match_ids = $match->pluck('match_id');        

        $create_contests = CreateContest::whereIn('match_id',$match_ids)
                            ->whereIn('contest_type',[1,8,13])
                            ->get();

        foreach ($create_contests as $key => $value) {
            $my_joined_contest = JoinContest::whereIn('match_id',$match_ids)
                            ->where('contest_id',$value->id)
                            ->where('is_rewarded',1)
                            ->get();

            $amt    = $value->entry_fees;
            $points = $value->entry_fees;

            foreach ($my_joined_contest as $key => $value) {       

                    \DB::table('ninja_rewards')
                        ->updateOrInsert(
                            [
                              'user_id'     => $value->user_id,
                              'contest_id'  => $value->contest_id,
                              'match_id'    => $value->match_id,
                              'team_id'     => $value->created_team_id
                            ],[
                              'user_id'     => $value->user_id,
                              'contest_id'  => $value->contest_id,
                              'match_id'    => $value->match_id,
                              'team_id'     => $value->created_team_id,
                              'amount'      => $amt,
                              'reward_points' => $points
                            ]);

                    $jc = JoinContest::find($value->id);
                    $jc->is_rewarded = 2;
                    $jc->entry_fees  = $amt;
                    $jc->save();
            }
        }       
    }

    /*
        Add Extra Cash Option
    */
    public function redeemedPoint(Request $request ){

            $ninja_rewards = \DB::table('ninja_rewards')->get()->groupBy('user_id');
            
            $ninja_rewards->transform(function ($item, $key)   {
                 $amount = $item->sum('amount');
            $Wallet = Wallet::where('payment_type',3)
                                    ->where('user_id',$key)
                                    ->first();
            if($amount>1000){

                if($Wallet){
                        $Wallet->extra_cash = $Wallet->extra_cash + ($amount/100);
                        $Wallet->save();
                }else{
                        $Wallet =  new Wallet;
                        $Wallet->payment_type = 3;
                        $Wallet->payment_type_string = 'Deposit';
                        $Wallet->extra_cash = ($amount/100);

                        $Wallet->save();
                }
                  

                $user_id = $key;  
                $wt             =   new WalletTransaction;
                $wt->user_id    =   $user_id;
                $wt->amount     =   ($amount/100);
                $wt->payment_type = 9;
                $wt->payment_type_string = 'Reward points redeemed';
                $wt->transaction_id = time().'N'.(int)$amount;
                $wt->payment_mode =  'N';
                $wt->payment_status =  'Success';
                $wt->debit_credit_status = "+"; 
                $wt->save();

                \DB::table('ninja_rewards')
                            ->where('user_id',$user_id)
                            ->delete();
                 $Wallet->save();
            }               
        });
    }
    /*Add extra cash*/
    public function addMegaExtraCash(Request $request ){
        //distributePrize 
        return false;
        $data = null;
        try{
            $match_id = $request->match_id;

            $create_contests = CreateContest::where('match_id',$match_id)
                            ->whereIn('contest_type',[1,8])
                            ->get();
            

            $user_ids = User::where('customer_type',0)->pluck('id');
                $cc = [];
            foreach ($create_contests as $key => $value) {

                $jc = JoinContest::where('match_id',$match_id)
                            ->where('contest_id',$value->id)
                            ->whereIn('user_id',$user_ids)
                            ->get()
                            ->groupBy('user_id');
                 
                $amount = $value->entry_fees;

                $contest_id         = $value->id;
                $contest_type_id    = $value->contest_type;
                $match_id           = $request->match_id;

            \DB::beginTransaction();
                foreach ($jc as $user_id => $value) {
                        if($value->count()>=3){
                            $cc[] = $user_id;        
                        } 

                    $mega_rewards = \DB::table('mega_rewards')
                        ->where('user_id',$user_id)
                        ->where('match_id',$match_id)
                        ->first();
                    
                    if($mega_rewards){
                        continue;
                    }
                    $Wallet = Wallet::where('payment_type',3)
                                        ->where('user_id',$user_id)
                                        ->first();

                    if($Wallet){
                    }else{
                       continue;
                    }
                    $wt             =   new WalletTransaction;
                    $wt->user_id    =   $user_id;
                    
                    $wt->payment_type   = 10;
                    $wt->payment_type_string = 'GL Extra Cash';
                    $wt->transaction_id = $match_id.'N'.time();
                    $wt->payment_mode   =  'N';
                    $wt->payment_status =  'Success';
                    $wt->debit_credit_status = "+"; 
                    $wt->match_id       = $request->match_id;
                    $team_count         = $value->count();    
                    $c = 1;

                    if($team_count>=5 && $team_count<9){ 
                        $wt->amount  =   $amount;
                        $wt->save(); 
                        $Wallet->extra_cash = $Wallet->extra_cash + $amount; 
                        $Wallet->save();
                        $c = 1;
                    }elseif($team_count>=9 && $team_count<=10){
                        $wt->amount  =   $amount*2;
                        $wt->save();
                        $Wallet->extra_cash = $Wallet->extra_cash + $amount*2;
                        $Wallet->save();
                        $c = 2;
                    }elseif($team_count==11){ 
                        $wt->amount  =   $amount*3;
                         
                        $wt->save();
                        $Wallet->extra_cash = $Wallet->extra_cash + $amount*3;
                        $Wallet->save();
                        $c = 3;
                    }

                    if($team_count>=5){
                        
                        $mega1 = [
                              'user_id'     => $user_id,
                              'contest_id'  => $contest_id,
                              'match_id'    => $match_id,
                              'contest_type_id' => $contest_type_id
                            ];
                        $mega2 =    [
                              'user_id'     => $user_id,
                              'contest_id'  => $contest_id,
                              'match_id'    => $match_id,
                              'contest_type_id'  => $contest_type_id,
                              'amount'      => $amount*$c,
                              'reward_points' => $amount*$c
                        ];

                        \DB::table('mega_rewards')
                        ->updateOrInsert($mega1,$mega2);
                    }  

                } 
            \DB::commit();  
        }                   

        }catch(\Exception $e){
            echo "Something went wrong";
            exit();
        }      

       return "Done";

    } 
    public function testProcedure(Request $request){

        $user_id = $request->user_id;
        $match_id = $request->match_id; 
        if($match_id && $user_id)
        {   
            $ct1 = createTeam::where('match_id',$match_id)->where('user_id',$user_id)->first();
            $teams = json_decode($ct1->teams,true);
             
            $j=2;
            for($i=0;$i<=10;$i++)
            {   
                if($teams[$i]==$ct1->captain || $teams[$i]==0)
                {   
                    continue;
                }else{
                    $tname = 'T'.$j;
                }
                $ct         =  new CreateTeam;	
                $ct->match_id   = $ct1->match_id; 
                $ct->user_id    = $ct1->user_id;         
                $ct->team_id    = $ct1->team_id;
                $ct->teams      = $ct1->teams;
                $ct->captain    = $teams[$i];
                $ct->vice_captain = $ct1->captain; 
                $ct->team_count = $tname;
                $ct->team_join_status = 0;
                $ct->points = 0;
                $ct->rank = 0;
                $ct->prize_amount = 0;
                $ct->edit_team_count = 1; 
                $j++;
                $ct->save(); 
            }
            die('10 team copy ');
        }
        echo "no match";
    }

    public function checkAuthToken($request)
    {   
        // Launch Firebase Auth

        if($request->user_id==$request->user()->id)
        {
            return [
                'status'   => true
            ];

        }else{
            return [
                'status'   => false
            ];
            
        }
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

    public function updateFinalLB(){

        $lb = \DB::table('leaderboard_matches')
                ->where('status',3)
                ->orderBy('priority','desc')->get();
         
        foreach ($lb as $key => $value) {
            $ll = \DB::table('leaderboard_contests')
                    ->where('cid',$value->cid)
                    ->get()
                    ->groupBy('user_id')
                    ->transform(function($item,$key){ 
                       
                        $points      = $item->sum('points');

                        $lb_obj      = $item->sortByDesc('points')->first();
                        $team_count  = $lb_obj->team_count; 
                        $user_id     = $key;
                        
                        $final_leaderboards  = \DB::table('final_leaderboards')
                                                ->updateOrInsert(
                                    [
                                        'user_id' => $user_id,
                                        'cid'     => $lb_obj->cid
                                    ],
                                    [
                                        'match_id'      => $lb_obj->match_id,
                                        'user_id'       => $user_id,
                                        'contest_id'    => $lb_obj->contest_id,
                                        'team_count'    => $team_count,
                                        'points'        => $points,
                                        'user_name'     => $lb_obj->user_name,
                                        'team_name'     => $lb_obj->team_name,
                                        'cid'           => $lb_obj->cid, 
                                        'team_id'       => $lb_obj->created_team_id
                                    ]
                                );
                                                 
                        
                    });

            $select = \DB::raw("@i := coalesce(@i + 1, 1) ranks, user_id,match_id,contest_id,points,user_name,team_name,id, cid as series_id,points as max_point,user_code");

            $update_ranks  = \DB::table('final_leaderboards')
                            ->select($select) 
                            ->where('cid',$value->cid)
                            ->orderByDesc('points')
                            ->get();
            \DB::beginTransaction();
            foreach ($update_ranks as $update_ranks_key => $update_ranks_result) {
                $uid = (User::find($update_ranks_result->user_id))->user_name??'';
                \DB::table('final_leaderboards')->updateOrInsert(
                                    [
                                        'user_id' => $update_ranks_result->user_id,
                                        'cid'     => $update_ranks_result->series_id
                                    ],
                                    [
                                        'ranks'      => $update_ranks_result->ranks,
                                        'user_code'  => $uid
                                    ] );
            }
            \DB::commit();
        }    
    }

    // Main leaderboard contest details
    public function globalLeaderBoard(Request $request){
            $lb = \DB::table('leaderboard_matches')
                ->where('status',3)
                ->orderBy('priority','desc')->get();

        $data= [];
        foreach ($lb as $key => $value) { 
            $select = \DB::raw("ranks,points,user_name,team_name,cid as series_id,points as max_point,user_code as user_id,team_count, user_id as uid");

            $update_ranks1  = \DB::table('final_leaderboards')
                            ->select($select) 
                            ->where('cid',$value->cid)
                            ->orderByDesc('points')
                            ->limit($value->total_winner)
                            ->get(); 
            
            $league_name        = $value->series_name;
            $league_duration    = $value->league_duration;
            $total_record       =  $update_ranks1->count();
                      
            $lb_prize_breakups = \DB::table('lb_prize_breakups')
                                ->where('leaderboard_id',$value->id)
                                ->get();    
                $lb_ranks = [];                
                foreach ($lb_prize_breakups as $key => $lb_result) {
                    
                    if($lb_result->rank_upto==1){
                        $lb_ranks[] =  [
                            'key'       => '#'.$lb_result->rank_from, 
                            'value'     =>  $lb_result->prize_amount
                        ];
                    }else{
                        $lb_ranks[] =  [
                            'key'       => '#'.$lb_result->rank_from.'-'.$lb_result->rank_upto, 
                            'value'     =>  $lb_result->prize_amount
                        ];  
                    }
                } 

            $update_ranks2  = \DB::table('final_leaderboards')
                            ->select($select) 
                            ->where('cid',$value->cid)
                            ->orderByDesc('points') ;

            $ud = $update_ranks2->where('user_id',$request->user_id)->first();
            

            if($ud==null){
                $uid = User::find($request->user_id);
                    
                      $rs =     [
                        'ranks'     => 0,
                        'points'    => 0,
                        'user_name' =>  $uid->name??"#team_name",
                        'team_name' =>  $uid->team_name??$uid->name??'#name',
                        'series_id' =>  $value->cid,
                        'max_point' =>  0,
                        'user_id'   => $uid->user_name??'',
                        'team_count' =>  "T1"
                      //  'uid'       => $uid->id??null,
                    ];
                $final_data[] =  $rs;     
            }else{
                $final_data[] =  $ud;      
            }

                    
            foreach ($update_ranks1 as $key => $value) {
                $uid = User::find($value->uid);
                $str = "";
                if(isset($uid->customer_type) && $uid->customer_type==3 && ($request->user_id==285 || $request->user_id==262))
                {
                    $str = "*";
                }
                if($value->uid==$request->user_id){
                  continue;
                }else{
                    if($value->team_name==null){
                         $value->team_name =  $value->user_name.$str;
                    }else{
                        $value->team_name =  $value->team_name.$str;
                    }
                   $final_data[] = $value; 
                }
            }


            $data[] = [

                'rank' => $lb_ranks,
                'total_record' => $total_record,
                'match_name' => $league_name,
                'leaderBoard' => $final_data
            ] ;
            
        }


        if(empty($data)){
             $data[] = [
                'rank' => $lb_ranks??'',
                'total_record' => $total_record??'',
                'match_name' => $league_name??'PSL',
                'leaderBoard' => $final_data??''
            ] ;
        }

        return [
            'status'    => true,
            'code'      => 200,
            'data'      => $data
        ]; 
    }

    public function updateRanks(Request $request){

            $select = \DB::raw("@i := coalesce(@i + 1, 1) ranker, user_id,match_id,contest_id,points,winning_amount,team_name,id");

            $update_ranks  = \DB::table('join_contests')
                        ->select($select)                     
                        ->where('match_id',$request->match_id)
                        ->where('contest_id',$request->contest_id)
                        ->orderByDesc('points')
                        ->get(); 

            $temp_point = 0;  
            $temp_rank  = 0;              

            foreach ($update_ranks as $key => $value) {        
                    $actual_point = $value->points; 
                    if($actual_point==$temp_point){
                        $rank = $temp_rank;
                    }else{
                        $rank       = $key+1;
                        $temp_point = $value->points; 
                        $temp_rank  = $rank;
                    }
                    $value->rank = $rank;

                    $data[] = $value;
            }
            
        return  $data;
    }

    public function cricketAPICall($url='')
    {
         $data = Http::get($url)->json();

         return $data;
    }

    public function generateReportByMatch(Request $request)
    {
        if($request->match_id)
        {
            $jc = JoinContest::where('entry_fees','>',0)
                ->where('match_id',$request->match_id)
                ->get()
                ->groupBy('match_id');
        }else{
            $jc = JoinContest::where('entry_fees','>',0)
                ->whereDate('updated_at', '=', date('Y-m-d'))
                ->whereIn('match_id', Matches::select('match_id')
                                    ->where('status',3)
                                    ->pluck('match_id')
                                    ->toArray()
                                )
                ->get()
                ->groupBy('match_id');
        }
       //  dd($jc);
                
        $jc->transform(function($item,$key){
            $uid = [285,262,11556,9112,13244,361];
            $distribute = $item->whereNotIn('user_id',$uid)->sum('winning_amount');
            $match_id = $key;

             $cid =  JoinContest::where('match_id',$match_id)
                                ->where('cancel_contest',1)
                                ->pluck('contest_id')
                                ->toArray(); 

            
            $actual_amount =   \DB::table('contest_amount_deductions')
                            ->where('match_id',$match_id)
                            ->whereNotIn('user_id',$uid)
                            ->whereNotIn('contest_id', $cid)
                            ->sum('entry_fees');

            $extra_cash =   \DB::table('contest_amount_deductions')
                            ->whereNotIn('user_id',$uid)
                            ->whereNotIn('contest_id', $cid)
                            ->where('match_id',$match_id)
                            ->sum('extra_cash');

            $bonus_cash =   \DB::table('contest_amount_deductions')
                            ->whereNotIn('user_id',$uid)
                            ->whereNotIn('contest_id', $cid)
                            ->where('match_id',$match_id)
                            ->sum('bonus_amount');

            $collection =  round(($actual_amount-$extra_cash-$bonus_cash),2);

            $profit = $collection-$distribute;
            
            $income = 0;
            $loss   = 0;
            if($profit>0)
            {
                $income = $profit;   
            }
            if($profit<0)
            {
                $loss = $profit;   
            }

            \DB::table('join_contests')->where('match_id',$match_id)
                            ->update([
                                'report_generated' => 2
                            ]);
            \DB::table('matches')->where('match_id',$match_id)
                            ->update([
                                'total_collection' => $collection,
                                'profit'    => $income,
                                'loss'      => $loss
                            ]);
        });
        echo "Generated";
    }
    // Add fund to wallet
    public function addFundToWallet(Request $request)
    {
        $amount    = $request->add_amount??"1.00";
        $checksumObj = new \paytm\paytmchecksum\PaytmChecksum;
        $mid        = "NINJA135011322855955";
        $mkey       = "#xCxpj7Ytit9fCL8";
        $amount     = (float)($amount);

        $paytmParams = array();
        $paytmParams["subwalletGuid"] = "bedde85c-bc2f-492c-a794-edace6b31667";
        $paytmParams["amount"]    = $amount;

        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);
        $checksum = $checksumObj::generateSignature($post_data, $mkey);
        
        $x_mid      = $mid;
        $x_checksum = $checksum;
        $url = "https://dashboard.paytm.com/bpay/api/v1/account/credit";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "x-mid: " . $x_mid, "x-checksum: " . $x_checksum)); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        $response = curl_exec($ch);
    }

    public function getPlayerPic($pid=null)
    {
        
        $img = Cache::get('pid_'.$pid);
        if($img){
           // echo $pid;
            return $img;
        }
        
        $pimg =  \DB::table('player_img')->where('pid',$pid)->first();
        
        Cache::put("pid_".$pid,$pimg->profile_pic??0,36000);

        return $pimg->profile_pic??0;

    }
    public function releaseFundStatus(Request $request)
    {
        $user_id    = $request->user_id??'285';

        $checksumObj = new \paytm\paytmchecksum\PaytmChecksum;

        $mid        = "NINJA135011322855955";
        $mkey       = "#xCxpj7Ytit9fCL8";
        
        $order_id   = $request->order_id??$user_id.time();
        
        $paytmParams = array();
        $paytmParams["orderId"] = $order_id;

        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);
               
        $checksum = $checksumObj::generateSignature($post_data, $mkey);
        
        $x_mid      = $mid;
        $x_checksum = $checksum;
        //$url = "https://dashboard.paytm.com/bpay/api/v1/disburse/order/wallet/gratification"; 
        $url = "https://dashboard.paytm.com/bpay/api/v1/disburse/order/query";

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "x-mid: " . $x_mid, "x-checksum: " . $x_checksum)); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        $response = curl_exec($ch);
        return $response;    
    }
    public function releaseFund(Request $request)
    {
        $user_id    = $request->user_id??'285';
        $paytm_no   = $request->paytm_no??"7974343960";
        $wamount    = $request->withdrawal_amount??"1.00";

        $checksumObj = new \paytm\paytmchecksum\PaytmChecksum;

        $mid        = "NINJA135011322855955";
        $mkey       = "#xCxpj7Ytit9fCL8";
        
        $order_id   = $request->order_id??$user_id.time();
        $amount    = (float)($wamount);

        $paytmParams = array();
        $paytmParams["subwalletGuid"]      = "bedde85c-bc2f-492c-a794-edace6b31667";
        $paytmParams["orderId"]            = "S11".$order_id;
        $paytmParams["beneficiaryPhoneNo"] = $paytm_no;
        $paytmParams["amount"]             = $amount;
        $paytmParams['comments']           = "S11 Fund Withdrawal";
        $paytmParams['narration'] = "bfe710ae-74c3-4fbe-a9c8-6ddd13586af4";


        $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);
               
        $checksum = $checksumObj::generateSignature($post_data, $mkey);
        
        $x_mid      = $mid;
        $x_checksum = $checksum;
        $url = "https://dashboard.paytm.com/bpay/api/v1/disburse/order/wallet/gratification"; 

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json", "x-mid: " . $x_mid, "x-checksum: " . $x_checksum)); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
        $response = curl_exec($ch);
        $oid = $paytmParams["orderId"];
        $url = "https://rest.ninja11.in/api/v3/releaseFundStatus?order_id=".$oid;
        return '<a  target="_blank" href="'.$url.'">Check status</a>';       
    }

    public function globalLeaderBoard2(Request $request)
    {
        $lb = \DB::table('leaderboard_matches')
                ->where('status',3)
                ->orderBy('priority','desc')->get();
         
        $glb      = [];
        $lb_ranks = [];
            foreach ($lb as $key => $value) {
                $series_id = $value->cid;

                $lb_prize_breakups  = \DB::table('lb_prize_breakups')
                                ->where('leaderboard_id',$value->id)
                                ->get();

                foreach ($lb_prize_breakups as $key => $lb_result) {
                    
                    $lb_cid[$value->series_name]        = $series_id;
                    $lb_duration[$value->series_name]   = $value->league_duration;

                    if($lb_result->rank_upto==1){
                        $lb_ranks[$value->series_name][] =  [
                            'key'       => '#'.$lb_result->rank_from, 
                            'value'     =>  $lb_result->prize_amount
                        ];
                    }else{
                        $lb_ranks[$value->series_name][] =  [
                            'key'       => '#'.$lb_result->rank_from.'-'.$lb_result->rank_upto, 
                            'value'     =>  $lb_result->prize_amount
                        ];  
                    }
                }
                
                $lbc = \DB::table('leaderboard_contests')
                        ->where('cid',$value->cid)
                        ->orderBy('points','desc')
                        ->get()
                        ->groupBy('user_id')
                        //->slice(0,100)
                        ->transform(function ($item, $key) use($value) {

                            $ldp = $item->sum('points');
                            $jc = $item->first();
                             
                            $item->max_point    = $ldp;
                            $item->user_id      = $key;
                            $item->match_id     = $jc->match_id;
                            $item->contest_id   = $jc->contest_id;
                            $item->user_name    = $jc->user_name;
                            $item->team_name    = $jc->team_name??$jc->user_name;
                            $item->team_count   = $jc->team_count; 
                            $item->ranks        = $jc->ranks;
                            $item->series_id    = $value->cid;
                            return $item;
                        }); 
                $data[$value->series_name] = $lbc; 
            }
            
            $user_id = User::find($request->user_id); 
             
            foreach ($data as $key => $series_name) {
                $name = $key;
                $i=0;
                 
                $rank       = $lb_ranks[$key]??[];
                $series_id  = $lb_cid[$key]??0;

                foreach ($series_name as $key => $value) {
                    $i++;
                    if($value->max_point==0){
                        continue;
                    }
                    
                    if(isset($user_id) && $user_id->id==$value->user_id){
                        $myboard =  [
                            'max_point' => $value->max_point,
                            'user_name' => $value->user_name,
                            'team_name' => $value->team_name,
                            'user_id'   => User::find($value->user_id)->user_name,
                            'team_count'   => $value->team_count,
                            'points'    => (int)$value->max_point,
                            'ranks'     => $i++,
                            'series_id' => $series_id
                         ];
                    } 
                    $glb[] =  [
                        'max_point' => $value->max_point,
                        'user_name' => $value->user_name,
                        'team_name' => $value->team_name,
                        'user_id'   => User::find($value->user_id)->user_name,
                        'team_count'   => $value->team_count,
                        'points'    => (int)$value->max_point,
                        'ranks'     => $i++,
                        'series_id' => $series_id
                     ];
                     
                 } 

               // $glb[0] = $myboard; 
                $k          =   collect($glb);
                $data       =   array_values($k->sortByDesc('max_point')->toArray() );
                $adata      =   [];   

                foreach ($data as $key => $value) {
                    $value['ranks'] = $key;

                    $adata[] = $value; 
                }
                $uid = $user_id->user_name??null;
                $myboard = collect($adata)->where('user_id',$uid)->first();
                 
                if(!isset($myboard) || $myboard==null){
                     $myboard =  [
                        'max_point' => 0,
                        'user_name' => $user_id->name??'#uname',
                        'team_name' => $user_id->team_name??'#team',
                        'user_id'   => $user_id->user_name??'N100',
                        'team_count'   => 'T1',
                        'points'    => 0,
                        'ranks'     => 0,
                        'series_id' => $series_id
                     ];
                    $adata[0] =   $myboard; 
                }else{
                    $adata[0] =   $myboard; 
                }  

               $data_set[] = [
                    'rank'          =>  $rank,
                    'total_record'  => count($adata),
                    'match_name'    => $name,
                    'leaderBoard'   => array_slice($adata,0,101)
               ];
               //$rank = [];
            }
           
            return  [
                'status' => true,
                'code' => 200,
                'data' => $data_set
            ];
    }

    public function getLeaderBoardUser(Request $request){

        /*$stoken = $this->valideToken($request);
        if($stoken){
            return $stoken;
        }*/
        $user = User::find($request->user_id);
        try{
            
            $lb = \DB::table('leaderboard_contests')
                ->where('user_id',$user->id)
                ->where('cid',$request->series_id)
                ->orderBy('match_id','desc')->get();

            $league_name  = \DB::table('leaderboard_matches')
                            ->where('cid',$request->series_id)
                            ->first();

            $name  = explode(" ", $user->name);
            $team_name = $name[0]??null;                
            
            $user_name    =  $user->name;
            $team_name    =  $user->team_name;
            $league_name1 =  $league_name->series_name??'NA';
            $lb_duration  = $league_name->league_duration??'NA';
            $league_title =  $league_name1??'No Series';
             

            $data = [];
            foreach ($lb as $key => $result) {
                $match = Matches::where('match_id',$result->match_id)->first();

                $data[] = [
                        'match_name' => $match->short_title,
                        'match_start_date' => date('d-m-Y',$match->timestamp_start),
                        'team'       => $result->team_count,
                        'points'     => $result->points,
                        'team_id'     => $result->created_team_id??0
                   ];
                }    
            $points          =   $lb->sum('points');
            $rank           =   1;    
            $data           =   $data;
            $status         =   true;
            $code           =   200;
            $message        =   'Leaderboard';    
          //  $league_title   =   'AbuDhabu-T10';
            $name  = explode(" ", $user->name);
            $team_name = $name[0]??null;   
            
            $user_name      =   $user->name;
            $team_name      =   $team_name??$user->name;
             
            $series_end_date =  $lb_duration;

        }catch(\Exception $e){ 
            $points         =   0;
            $rank           =   0;
            //$league_title   =   'AbuDhabu-T10';
            $user_name      =   $user->name??'';
            $team_name      =   $user->team_name??$user->name;
            $data           =   [];
            $status         =   true;
            $code           =   404;
            $message        =   'Leaderboard not available';
            $series_end_date =  $lb_duration??'NA';
        }

        $rank =  (\DB::table('final_leaderboards')
                ->where('user_id',$request->user_id)
                ->where('cid',$request->series_id)
                ->first())->ranks??0;

        $result = [
            'status'        =>  $status,
            'message'       =>  $message,
            'series_end_date'=>  $series_end_date,
            'league_title'  =>  $league_title??'NA',
            'user_name'     =>  $user_name,
            'team_name'     =>  $team_name,
            'points'        =>  $points??0,
            'rank'          =>  $rank,
            'data'          =>  $data
        ];

        return $result;
    }
    // update leaderboard points
    public function globalLeaderBoardLive(Request $request)
    {
        $lb = \DB::table('leaderboard_matches')
                ->where('status',3)
                ->get(); 

        $lb->transform(function ($item, $key)   {
            $matches = Matches::where('competition_id',$item->cid)
                ->where('status',2)
               // ->whereMonth('updated_at','09')
               // ->orWhereMonth('updated_at','10')
                ->get();


            $contest_type= [21,1,8];
            $cid = $item->cid;
            foreach ($matches as $key => $mid) {
                $contest_id = CreateContest::whereIn('contest_type',$contest_type)
                                ->where('match_id',$mid->match_id)
                                ->pluck('id');
                 
                               
                $jc = JoinContest::where('match_id',$mid->match_id)
                                    ->whereIn('contest_id',$contest_id)
                                    ->where('ranks','>',0)
                                    ->get()
                                    ->groupBy('user_id');
                $match_id = $mid->match_id;  

                $data = $jc->transform(function ($item, $key) use($match_id)   { 

                    $ldp        = $item->max('points');

                    $jc         = $item->sortByDesc('points')->first();
                    $total_team = $item->count();

                    $item->total_team   = $total_team; 
                    $item->max_point    = $ldp;
                    $item->user_id      = $key;
                    $item->match_id     = $jc->match_id;
                    $item->contest_id   = $jc->contest_id;
                    $item->user_name    = $jc->user_name;
                    $item->team_name    = $jc->team_name;
                    $item->team_count   = $jc->team_count;
                    $item->created_team_id = $jc->created_team_id;
                                    
                    return $item;
                });

                foreach ($data as $key => $value) {
                    $leaderBoard  = \DB::table('leaderboard_contests')
                                    ->updateOrInsert([
                                        'user_id'       => $value->user_id,
                                        'match_id'      => $value->match_id,
                                        'contest_id'    => $value->contest_id,
                                        'created_team_id' => $value->created_team_id
                                    ],[
                                        'user_id'    =>  $value->user_id,
                                        'match_id'   =>  $value->match_id,
                                        'contest_id' =>  $value->contest_id,
                                        'user_name'  =>  $value->user_name,
                                        'team_name'  =>  $value->team_name,
                                        'team_count' =>  $value->team_count,
                                        'points'     =>  $value->max_point,
                                        'cid'        =>  $cid,
                                        'created_team_id' =>  $value->created_team_id ,
                                        'total_team' => $value->total_team
                                    ]);

                }
            }
        });

        $this->updateFinalLB();
    }
    public function getBaseUrl(Request $request)
    {
       return url('api/v3/'); 
    }

    public function createTeam4me(Request $request)
    {
        $mid = Matches::where('status',1)->where('timestamp_start','>',time())->pluck('match_id');

        // $is_team = CreateTeam::whereIn('match_id',$mid)->count();

        foreach ($mid as $match_id) {
            $ct = CreateTeam::where('match_id',$match_id)->orderBy('id','DESC')->first();
            if(!$ct)
            {
                continue;
            }
            else{
                //'11556','285','361',
                $is_team = CreateTeam::whereIn('user_id',['285'])->where('match_id',$match_id)->count();

                if($is_team){
                    $request->merge(['match_id' => $match_id ]);
                    $request->merge(['user_id' => 285 ]);
                    $this->cloneMyTeam($request);

                    continue;
                }else{
                
                    $clone_team2  = new CreateTeam;

                    $clone_team2->match_id      =   $ct->match_id;
                    $clone_team2->user_id       =   285;
                    $clone_team2->contest_id    =   $ct->contest_id;
                    $clone_team2->team_id       =   $ct->team_id;
                    $clone_team2->teams         =   $ct->teams;
                    $clone_team2->captain       =   $ct->captain;
                    $clone_team2->vice_captain  =   $ct->vice_captain;

                    $clone_team2->team_count    =   "T1";
                    $clone_team2->team_join_status  =   $ct->team_join_status;
                    $clone_team2->rank              =   $ct->rank;
                    $clone_team2->edit_team_count   =   $ct->edit_team_count;

                    $clone_team2->save();
                    $request->merge(['match_id' => $match_id ]);
                    $request->merge(['user_id' => 285 ]);
                    $this->cloneMyTeam($request);
                }
            }

        }
        return ['team created'];
    }
    // date : 15/4/25
    // autojointeam
    public function autoJoinTeam(Request $request)
    {

        $mid = Matches::where('status',1)->where('timestamp_start','>',time())->pluck('match_id');
        
            foreach ($mid as $match_id) {
        
                $contest_ids = CreateContest::where('match_id',$match_id)
                                    ->whereIn('contest_type',[21,1,2,17,15,4,8,13,14])
                                    ->where('total_spots','>=',400)
                                    ->pluck('id');  

                if($contest_ids->count()==0){
                    continue;
                }
                 
                $ct = CreateTeam::whereIn('user_id',[285])->where('match_id',$match_id)->pluck('id');

                if($ct){   
                     
                    foreach ($contest_ids as $key2 => $cid) {                    
                        foreach ($ct as $key => $created_team_id) {
                            $team_id    = $created_team_id;
                            $contest_id = $cid;
                            $match_id   = $match_id;

                            $request->merge([
                                'team_id'       => $team_id,
                                'contest_id'    => $contest_id,
                                'match_id'      => $match_id
                            ]);
                             
                            $this->railLogic($request);
                        } 
                    }
                }
            }

    }

    public function autoJoinContest(Request $request){

        $match_id = $request->match_id;

        if($match_id==null){
            return false;
        }

        $contest_type_id = CreateContest::where('match_id',$match_id)
                            ->where('total_spots','>',400)
                            ->whereIn('contest_type',[4,17,21])->pluck('id');


        // withdrawal type =5
        $winner_user_list = Wallet::where('payment_type',5)
                            ->where('amount','>',200)
                            ->pluck('user_id');


        $ct = JoinContest::where('match_id',$match_id)
                            ->whereIn('team_count',['T1','T2'])
                            ->whereIn('user_id',$winner_user_list)
                            ->whereNotIn('contest_id',$contest_type_id)
                            ->orderBy('id','desc')
                        ->get();
        if($ct->count()==0)
        {
            return ['no team found'];
        }
        
        $contest_ids = CreateContest::where('match_id',$match_id)
                            ->whereIn('contest_type',[1,2,15,4,17,8,13,14])
                            ->pluck('id');   

        foreach ($contest_ids as $key2 => $cid) {                    
            foreach ($ct as $key => $value) {
                $team_id    = $value->created_team_id;
                $contest_id = $cid;
                $match_id   = $value->match_id;

                $request->merge([
                    'team_id'       => $team_id,
                    'contest_id'    => $contest_id,
                    'match_id'      => $match_id
                ]);
                $this->railLogic($request);
            } 
        }
    }

    public function getDublicateUser(Request $request){

        $user = User::where('customer_type',0)->pluck('id');

        $w = Wallet::where('amount',200)
                    ->where('payment_type',1)
                    ->where('created_at','<','2021-01-01')
                    ->whereIn('user_id',$user)
                    ->whereNotIn('payment_type',[2,3,4,5,6,7,8,9,10])
                    ->get()
                    ->groupBy('user_id');
        $u_id = [];
        foreach ($w as $key => $value) {
            if($value->count()==1){
                $u_id[] = $value->first()->user_id;
            }
        }


        $uu = User::whereIn('id',$u_id)->get();
        //$user_ro = User::where('customer_type',3)->pluck('id');
        $i=1;
        foreach ($uu as $key => $value) {

            $user = new User;
              

            foreach ($value->toArray() as $key2 => $rs) {
                if($key2=='id'){
                    continue;
                }

                $user->$key2 = $rs;
            }
            $user->email = base64_encode($key).'@ninja11.in';
            $user->reference_code   = 'HERO11';
            $user->referal_code     =  base64_encode($key);
            $user->customer_type    =  3;
            $user->device_id        =  "";
            $user->user_name        =  base64_encode($key);

            $user->save();


             
           /* if($key>2080){
                break;
            }

            if($value->team_name){
                continue;
            } 
        */
         /*   $uuu = $user_ro[$key];
            $ro_u = User::find($uuu);
            $ro_u->team_name = $value->team_name;
            $ro_u->name = $value->name; 
            $ro_u->reference_code = 'N11100';
           // $ro_u->save();

           */
            $t[] = $i++;
        }


        $user = User::where('customer_type',0)
                ->select('email','id','customer_type','referal_code','reference_code','device_id') 
                ->whereNotNull('device_id') 
                ->get()->groupBy('device_id');
            $uid = [];    
            foreach ($user  as $key => $value) {
                  if($value->count()>3){
                    
                    foreach ($value as $key2 => $rs) {
                        if($key2==0){
                            continue;
                        }
                        $uid[] = $rs->id;


                        $Wallet = Wallet::where('user_id',$rs->id)
                                ->get();
                        if($Wallet->count()==1){
                             
                            $u = User::find($rs->id);
                            $u->customer_type = 3;
                            $u->email = $rs->id.'@ninja11.in';
                            $u->save();

                        }

                    }

                }
            }
    }

    // cron
    public function globalLeaderBoardPrize(Request $request){
            $cid = $request->cid;

            $lb = \DB::table('leaderboard_matches')
                ->where('status',3)
                ->where('cid',$cid)->get();
        $lb_ranks = []; 
        $final_data = [];
        foreach ($lb as $key => $value) { 
            $select = \DB::raw("ranks,points,user_name,team_name,cid as series_id,points as max_point,user_code as user_id,team_count, user_id as uid");

            $update_ranks1  = \DB::table('final_leaderboards')
                            ->select($select) 
                            ->where('cid',$value->cid)
                            ->orderByDesc('points')
                            ->get(); 
            
            $league_name        = $value->series_name;
            $league_duration    = $value->league_duration;
            $total_record       =  $update_ranks1->count();
                      
            $lb_prize_breakups = \DB::table('lb_prize_breakups')
                                ->where('leaderboard_id',$value->id)
                                ->get();    
                $lb_ranks = [];                
                foreach ($lb_prize_breakups as $key => $lb_result) {
                    
                    if($lb_result->rank_upto==1){
                        $lb_ranks[] =  [
                            'rank_from'       =>  $lb_result->rank_from, 
                            'rank_to' => $lb_result->rank_from,
                            'prize'     =>  $lb_result->prize_amount
                        ];
                    }else{
                        $lb_ranks[] =  [
                            'rank_from'       =>  $lb_result->rank_from, 
                            'rank_to' => $lb_result->rank_upto,
                            'prize'     =>  $lb_result->prize_amount
                        ];  
                    }
                } 

            $update_ranks2  = \DB::table('final_leaderboards')
                            ->select($select) 
                            ->where('cid',$value->cid)
                            ->orderByDesc('points') ;

            $ud = $update_ranks2->where('user_id',$request->user_id)->first();
            
            if($ud==null){
                $uid = User::find($request->user_id);
                
                      $rs =     [
                        'ranks'     => 0,
                        'points'    => 0,
                        'user_name' =>  $uid->name??"#team_name",
                        'team_name' =>  $uid->team_name??$uid->name??'#name',
                        'series_id' =>  $value->cid,
                        'max_point' =>  0,
                        'user_id'   => $uid->user_name??'',
                        'team_count' =>  "T1",
                        'uid'       => $uid->id,
                        'customer_type' => $uid->customer_type
                    ];
                $final_data[] =  $rs;     
            }else{
                $final_data[] =  $ud;      
            }

                    
            foreach ($update_ranks1 as $key => $value) {
                //user_id=KUNA2020
                $uid = User::find($value->uid);
                $value->customer_type = $uid->customer_type;

                if($value->uid==$request->user_id){
                  continue;
                }else{
                    if($value->team_name==null){
                         $value->team_name =  $value->user_name;
                    }
                   $final_data[] = $value; 
                }
            }


            /*$data[] = [

                'rank' => $lb_ranks,
                'total_record' => $total_record,
                'match_name' => $league_name,
                'leaderBoard' => $final_data
            ] ;*/
            
        }
        

        $finaal = [];
        foreach ($lb_ranks as $key => $rnk) {
             
             for($i=$rnk['rank_from']; $i<=$rnk['rank_to']; $i++)
             {
                $finaal[$i] = $rnk['prize'];
             }  
        }
        

        $final_data = array_slice($final_data, 0, 49);  

        \DB::beginTransaction();

        foreach ($final_data as $key => $value) {

            $prize = $finaal[$value->ranks];
            $puid[$value->uid] = $prize ;

            $sid = WalletTransaction::where('leaderbord_id',$cid)->first();
            if($sid){
                continue;
            } 

                $wallets = Wallet::firstOrNew(
                            [
                                'user_id'       => $value->uid,
                                'payment_type'  => 4
                            ]);

                $wallets->user_id       =  $value->uid;
                $wallets->validate_user =  Hash::make($value->uid);
                $wallets->payment_type  =  4;
                $wallets->payment_type_string = 'Prize';
                $wallets->amount        =  $wallets->amount + $prize;
              //  $wallets->save();

                $wt =  new WalletTransaction;
                $wt->user_id = $value->uid;
                $wt->amount  = $prize;
                $wt->payment_type = 11;
                $wt->payment_type_string = 'Leaderboard prize';
                $wt->transaction_id = time();
                $wt->payment_mode =  'Wallet';
                $wt->payment_status =  'Success';
                $wt->debit_credit_status = "+";
                $wt->leaderbord_id = $value->series_id;
                $wt->save();
                $wallets->save(); 
        }

        \DB::commit();
        
    }

    public function validateCoupon(Request $request){
       
        try{

            $code   = $request->code;
            $coupon = \DB::table('coupon')->where('code',$code)->first();

        if($coupon){
            $dtype  = $coupon->discount_type;
            $atype  = $coupon->amount_type;
            $amt    = $coupon->amount;
        }else{
            $dtype  = '';
            $atype  = '';
            $amt    = 0;
        }
        
        if($atype=='fixed'){
            $amount     =   $amt;
            $message    =   "Coupon applied successfully";
            $status     =   true;
            $code       =   200;
        }
        elseif($atype=='percent'){
            $amount     =   $amt;
            $message    =   "Coupon applied successfully";
            $status     =   true;
            $code       =   200;
        }else{
            $amount     =   0;
            $message    =   "Invalid coupon code applied. Try again!!";
            $status     =   false;
            $code       =   201;
        }

        return [

            'status' => $status,
            'message' => $message,
            'code' => $code,
            'data' => [
                'extra_cash' => $amount
            ]
        ];



        }catch(\Exception $e){
           return [

            'status' => false,
            'message' => 'coupon expired',
            'code' => 200,
            'data' => [
                'extra_cash' => $amount
            ]
        ];
        }

        \DB::table('coupon_history')->insert(
            [
                'code'      => $code,
                'user_id'   => $request->user_id,
                'amount'    => $amount,
                'amount_type' => 'fixed',
                'discount_type' => 'deposit'

            ]
        );
    }

    public function playerDetails( Request $request ){

        $match_id = $request->match_id??47401;
        $player_id =  $request->player_id??159;
        

        $player = Player::select('match_id','cid','pid','title','country','playing_role','fantasy_player_rating','birthdate','player_points','sell_by','playing11','team_name')
                ->where('match_id',$match_id)
                ->where('pid',$player_id)
                ->first();

        $format2 =  [
            1 => 'ODI',
            2 => 'Test',
            3 => 'T20i',
            4 => '',
            5 => '',
            6 => 'T20',
            7 => 'W-ODI',
            8 => 'W-T20',
            9 => '',
            10 => '',
            11 => '',
            17 => 'T10'
        ];        

	 $format =  [
            1 => 'ODI',
            3 => 'T20i',
            6 => 'T20',
            7 => 'W-ODI',
            8 => 'W-T20',
            17 => 'T10'
        ];
  

        $stats = [];            
        if($player){
            $cid = $player->cid;
            $match = Matches::where('competition_id',$cid)
                        ->whereIn('status',[2,3])
                        ->get();
            
            $mid = $match->pluck('match_id');
            

            $stat = Player::where('cid',$cid)
                            ->whereIn('match_id',$mid)
                            ->where('pid',$player_id)->get();

          //  \DB::beginTransaction();
            foreach ($stat as $key => $value) {
                $mat_inf = $match->where('match_id',$value->match_id)->first();

                $pt = \DB::table('match_player_points')
                        ->where('match_id',$value->match_id)
                        ->where('pid',$value->pid)
                        ->first();

                $data['selection']      = $value->sell_by;
                $data['player_points']  = $pt->point;
                $data['match_name']     = $mat_inf->short_title;
                // .' | '.$format[$mat_inf->format]??'';
                $data['date']           = date('d M,Y',$mat_inf->timestamp_start);

                $stats[] = $data;
            }
           // \DB::commit();
        }


        $result = [
            'code'          => 200,
            'status'        =>  true,
            'message'       =>  '',
            'data'          =>  [
                'player_info' => $player,
                'match_stat'  => $stats
            ]
        ];

        return $result;          
    }


    // phone status check
    // 4/10/2025
    public function phonepePaymentStatus2(Request $request)
    { 
      
        $baseUrl = 'https://api.phonepe.com/apis/hermes';
        $saltKey = "c246fadd-6523-4def-be15-685fc96aa160";
        $saltIndex = "1"; // Set your correct salt index
        
        // Step 1: Decode the base64 response
        $base64 = $request->get('response');
        $b64json = json_decode(base64_decode($base64), true);
        
        if (!isset($b64json['data']['merchantTransactionId'])) {
            return response()->json(['error' => 'Invalid transaction ID'], 400);
        }
        
        $transactionId = $b64json['data']['merchantTransactionId'];
        $merchantId = 'NINJA11ONLINE';
        
        // Step 2: Generate the hash
        $apiPath = "/pg/v1/status/{$merchantId}/{$transactionId}";
        $rawSignature = $apiPath . $saltKey;
        $hashed = hash('sha256', $rawSignature);
        $finalXHeader = $hashed . "###" . $saltIndex;
        
        // Step 3: Call PhonePe status API
        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => $baseUrl . $apiPath,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
                'accept: application/json',
                'X-VERIFY: ' . $finalXHeader,
                'X-MERCHANT-ID: ' . $merchantId // Correct value here
            ),
        ));
        
        $response = curl_exec($curl);
        curl_close($curl);
        
        // Optionally parse JSON and return
        return response()->json(json_decode($response, true));
        
 

    }



    // check failed cgpay payment status auto

    public function phonepePaymentStatus(Request $request)
    { 
      
         
        $payment_miss =  \DB::table('payment_logs')->where('status',1)->get();

        if($payment_miss->count()==0)
        {
            return 'no pending payment';
        }

        foreach($payment_miss as $key => $value)
        {
                 $data = [
                            "transaction_id"    => $value->txt_id,
                            
                        ];

            
                $curl = curl_init(); 
                   

                curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://merchant.cgpey.com/api/v2/payment-check-status',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_ENCODING => '',
                CURLOPT_MAXREDIRS => 10,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>  json_encode($data),
                CURLOPT_HTTPHEADER => array(
                    'x-api-key: 35DbqHbSI8f3V8q0vD7QgooEf3wWfET87qIQlu3dQv',
                    'x-secret-key: 35PTGiOf4phpZRIribotaJLPgjqVRvibV2ZTD7uwZiWhuUUq4p8DaP8jR5C1xxFpKCrKjd88',
                    'x-owner-name: kroy',
                    'Content-Type: application/json'
                    ),
                ));  
                    
                curl_close($curl);

                $response = (object)json_decode(curl_exec($curl),true);
                
                if(isset($response->status) && $response->status=='intiated')
                {
                    continue;
                }
                
                 if(isset($response->status) && $response->status=='pending')
                 { // dd($response );
                        \DB::table('payment_logs')->where('status',1)->where('id',$value->id)->update([
                            'check_count' => $value->check_count+1
                        ]);

                         if($value->check_count>13)
                        {
                                \DB::table('payment_logs')->where('id',$value->id)->update([
                                    'message' => 'failed' , 'status'=> 2
                                ]);
                        }
                        
                 }
                 if(isset($response->status) && $response->status=='failed')
                 {  
                        \DB::table('payment_logs')->where('status',1)->where('id',$value->id)->update([
                            'check_count' => $value->check_count+1
                        ]);
                        if($value->check_count>5)
                        {
                                \DB::table('payment_logs')->where('id',$value->id)->update([
                                    'message' => 'failed' , 'status'=> 2
                                ]);
                        }
                        
                 }
                 elseif(isset($response->status) && $response->status=='success')
                 {  
                         
                            $request->merge([

                                'payment_mode'      =>  'upi',
                                'transaction_id'    =>   $response->transaction_id,
                                'utr'               =>   $response->utr,
                                'deposit_amount'    =>   $response->amount,
                                'status_code'       =>   "PAYMENT_SUCCESS" ,
                                'user_id'           =>   $value->user_id
                            ]);  

                       $status =  $this->addMoney($request); 

                        \DB::table('payment_logs')->where('id',$value->id)->update([
                            'status' => 2, 'message' => 'payment added in wallet after cron run'
                        ]);
                       //  dd($response,$value); 
 
                 }
                 
                
        }
       
        return "Payment added!!";


    }

    //webhook upi
    // UPI payment status
    public function webhookUPIPayment(Request $request){


        $response =   $request->all();
        if(isset($response['event'])  && isset($response['data']['order_id']) && $response['event']=="TRANSACTION_CREDIT")
        {
            $order_id = $response['data']['order_id'];
            $data['paytm'] =  json_encode($request->all()); 
            
        //    \DB::table('paytm')->insert($data);


        }else{
            return false;
        }

        $order_id = $order_id;

               try{
                  
                if(isset($response['data']['order_id']) && $response['data']['order_id']==$order_id)
                {
                    $order_id = $response['data']['order_id'];
                    

                    $rs = \DB::table('initiate_transactions')->where('order_id',$order_id)->first();
                    
                    $user_id = $rs->user_id;
                    
                    $request->merge(['user_id'=>$user_id]);

                  // dd($rs);
                    $amount = $rs->amount;
                    if($rs->is_payment_added==1)
                    {
                        
                        $wallet = Wallet::firstOrNew([
                            'user_id' => $user_id,
                            'payment_type' => 3
                        ]);
                    
                        $wallet->amount = $wallet->amount+$amount;
                        $wallet->deposit_amount = $wallet->deposit_amount+$amount;

                        $txt = $order_id;

                        $wt =  new WalletTransaction;
                        $wt->user_id = $user_id;
                        $wt->amount  = $amount;
                        $wt->payment_type = 3;
                        $wt->payment_type_string    = 'Deposit';
                        $wt->transaction_id         = $txt;
                        $wt->order_id               = $txt;
                        $wt->payment_mode           =  'UPI';
                        $wt->payment_status         =  'Success';
                        $wt->debit_credit_status    = "+";
                        $wt->actual_amount = $amount??0; 

                        $wtt = WalletTransaction::where('transaction_id',$txt)->count();
                       
                        if($wtt>=1){
                             // skip     
                        }else{
                            $wt->save();
                            $wallet->save();
                        }

                        \DB::table('initiate_transactions')->where('order_id',$order_id)
                                ->update(
                                    [
                                        'status'            =>  2,
                                        'is_payment_added'  =>  2
                                ]
                            );
                        
                    }

                  return  $request->all();
                }
                else{
                    //echo "This order is not required to update :  $order_id";

                    return  $request->all();
                }

               }catch(\Exception $e){
                return  $e;

               }

        
    }

    // verify UPI payment
    public function verifyUPIPayment(){

        $rs = \DB::table('initiate_transactions')->where('is_payment_added',1)
                            ->where('payment_mode',"UPI")
                            ->get();

       // dd($rs);
        if($rs){

            foreach($rs as $key => $value){

                $data = $this->upiPaymentStatus($value->order_id,$value->user_id);
                
            }

        }

    }

    // UPI payment status
    public function upiPaymentStatus($order_id=null,$user_id=null, $source=null){

        if($order_id==null || $user_id==null){
            return "401";
        }
        
        $curl = curl_init();

        $order_id = $order_id;
        //$user_id = $request->user_id;

        $payment_mode = "UPI";

        $param = [
            'order_id'=>$order_id
        ];

        


            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://jupiter.haodapayments.com/api/v3/collection/status',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>json_encode($param),
                CURLOPT_HTTPHEADER => array(
                    'x-client-id: rPYuGfRvxK2410',
                    'x-client-secret: VqvzftjPp1231006051235',
                    'Content-Type: application/json'
                ),
                ));
        
                curl_close($curl);

                $response = json_decode(curl_exec($curl),true);

                if($source=="addMoney")
                {
                    return $response;
                }

               // return $response; 
                curl_close($curl);
               // dd($response['data']['order_id']);

               try{
                  
                if(isset($response['data']['order_id']) && $response['data']['order_id']==$order_id)
                {
                    $order_id = $response['data']['order_id'];

                    $rs = \DB::table('initiate_transactions')->where('order_id',$order_id)->first();
                  // dd($rs);
                    $amount = $rs->amount;
                    if($rs->is_payment_added==1)
                    {
                        
                        $wallet = Wallet::firstOrNew([
                            'user_id' => $user_id,
                            'payment_type' => 3
                        ]);
                    
                        $wallet->amount = $wallet->amount+$amount;
                        $wallet->deposit_amount = $wallet->deposit_amount+$amount;

                        $txt = $order_id;

                        $wt =  new WalletTransaction;
                        $wt->user_id = $user_id;
                        $wt->amount  = $amount;
                        $wt->payment_type = 3;
                        $wt->payment_type_string    = 'Deposit';
                        $wt->transaction_id         = $txt;
                        $wt->order_id               = $txt;
                        $wt->payment_mode           =  'UPI';
                        $wt->payment_status         =  'Success';
                        $wt->debit_credit_status    = "+";
                        $wt->actual_amount = $amount??0; 

                        $wtt = WalletTransaction::where('transaction_id',$txt)->count();
                       
                        if($wtt>=1){
                             // skip     
                        }else{
                            $wt->save();
                            $wallet->save();
                        }

                        \DB::table('initiate_transactions')->where('order_id',$order_id)
                                ->update(
                                    [
                                        'status'            =>  2,
                                        'is_payment_added'  =>  2
                                ]
                            );
                        
                    }

                  return  $response;
                }
                else{
                    //echo "This order is not required to update :  $order_id";

                    return  $response;
                }

               }catch(\Exception $e){
                return  $e;

               }

        
    }

    // UPI payment from haoday pay
    public function initiateUPIPayment(Request $request){

        $curl = curl_init();

        $order_id = $request->user_id.'UID'.time();
        $user_id = $request->user_id;
        $amount = $request->deposit_amount;

        $param = [
            'order_id'=>$order_id,
            'order_amount' => $amount,
            'order_currency' => "INR"

        ];
        
        // return [
        //     'status'    => false,
        //     'code'      => 201,
        //     'message'   => 'Please select another payment option',
        //     'data'      => ""
        // ]; 
    

        try{
            curl_setopt_array($curl, array(
                CURLOPT_URL => 'https://jupiter.haodapayments.com/api/v3/collection',
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_CUSTOMREQUEST => 'POST',
                CURLOPT_POSTFIELDS =>json_encode($param),
                CURLOPT_HTTPHEADER => array(
                    'x-client-id: rPYuGfRvxK2410',
                    'x-client-secret: VqvzftjPp1231006051235',
                    'Content-Type: application/json'
                ),
                ));
        
                //$response = curl_exec($curl);
                $response = json_decode(curl_exec($curl),true);
              //  return $response; 
                curl_close($curl);
                //echo $response; 

               try{



                    \DB::table('initiate_transactions')->insert(
                        [
                            'order_id'  => $order_id,
                            'status'    => 1,
                            'user_id'   => $user_id,
                            'amount'    => $amount,
                            'payment_mode' => "UPI"
                        ]
                    );

                    

                return [
                    'status'    => true,
                    'code'      => 200,
                    'message'   => 'success',
                    'data'      => $response['data']
                ]; 

               }catch(\Exception $e){
              //  return $e;

                return [
                    'status'    => false,
                    'code'      => 201,
                    'message'   => 'Minimum deposit amount should be 100, or try Other Payment Option',
                    'data'      => $response
                ]; 

               } 

        }catch(\Exception $e){

            echo $e;
        }

        
    }


    /*
        paytm transaction for all in payment
    */
    public function initiateTransaction(Request $request)
    {
        $checksumObj = new \paytm\paytmchecksum\PaytmChecksum;

        $mid        = env('PAYTM_MERCHANT_ID','tpJmKe81092739039978');
        $mkey       = env('PAYTM_MERCHANT_KEY','1PRscwi&opK94P!5'); 
        
        $user_id    = $request->get('user_id')??285;
        $user = User::find($user_id);
        $order_id   = 'ORDER_'.time();

        

        $amount    = $request->get('deposit_amount')??100;


        $payment = PaytmWallet::with('receive');

        $payment->prepare([
          'order'           =>  $order_id,
          'user'            =>  $request->get('user_id')??285,
          'mobile_number'   =>  $user->mobile_number??8103194076,
          'email'           =>  $user->email,
          'amount'          =>  $amount ,
          'callback_url'    => 'https://rest.ninja11.in/api/v3/getData?user_id='.$user->user_name
        ]);

    // dd($payment);

        \DB::table('paytm_logs')->insert(
            [
                'user_id' => $request->user_id,
                'status' => 1,
                'code'   => 200,
                'tid'    => "",
                'order_id' => $order_id,
                'amount' => $amount,
                'responseCode' => $request->RESPCODE,
                'data'  => json_encode($request->all())
            ]
        );

    // \DB::table('paytm')->insert(

    //     [
    //         'paytm' => json_encode([
    //             'user_id' => $request->user_id,
    //             'deposit_amount' => $request->deposit_amount,
    //             'mobile_number' => $request->mobile_number
    //         ])
    //     ]
    //         );

        return $payment->receive();

      //  dd($payment->receive());

      return  $data = [
            'status'    => 'true',
            'code'     => 200,
            'response'   => $payment->receive()
        ]; 
        
        

        $paytmParams = array();

            $paytmParams["body"] = array(
                "requestType"   => "Payment",
                "mid"           => $mid,
                "websiteName"   => "WEBSTAGING",
                "orderId"       => "ORDERID_".$order_id,
                "callbackUrl"   => "https://rest.ninja11.in/api/v3/paytmCallback?ORDER_ID="."S11_".$order_id,
               // "callbackUrl"   => "https://securegw-stage.paytm.in/theia/paytmCallback?ORDER_ID="."ORDERID_".$order_id,

                "txnAmount"     => array(
                    "value"     => intval($amount),
                    "currency"  => "INR",
                ),
                "userInfo"      => array(
                    "custId"    => "CUST_".$user_id,
                ),
            );  
          //  return  $paytmParams;

       // $checksum = $checksumObj::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES), $mkey);
        $checksum = \paytm\paytmchecksum\PaytmChecksum::generateSignature(json_encode($paytmParams["body"], JSON_UNESCAPED_SLASHES), $mkey);

       // dd($checksum);

        $paytmParams["head"] = array(
                "signature"    => $checksum
            );

            $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);
        //    $post_data = json_encode($paytmParams, JSON_UNESCAPED_SLASHES);


            /* for Staging */
            $url = "https://securegw.paytm.in/theia/api/v1/initiateTransaction?mid=".$mid."&orderId=ORDERID_".$order_id;



            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); 
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json")); 
            $response = curl_exec($ch);

            echo $response;
            die;
            dd($response,$post_data);
            die;

          //  dd($response);

        try{ 

            $result = json_decode($response,true);

            $resultCode = $result['body']['resultInfo']['resultCode'];

             \DB::table('all_in_one_paytm')->insert(
                [
                    'order_id'  => "S11_".$order_id,
                    'status'    => "pending",
                    'user_id'   => $user_id,
                    'amount'    => $amount,
                    'datetimes' => date('d-m-Y H:i:s')
                ]
             );

             \DB::table('initiate_transactions')->insert(
                [
                    'order_id'  => "S11_".$order_id,
                    'status'    => 1,
                    'user_id'   => $user_id,
                    'amount'    => $amount
                ]
             );

            if($resultCode=='0000'){
                return [
                    'status'    => true,
                    'code'      => 200,
                    'message'   => 'Success',
                    'data'      => [

                        'mid'       => $mid,
                        'txnToken'  => $result['body']['txnToken']??'',
                        'order_id'  => "S11_".$order_id

                        ]
                ];    
            }else{
                return [
                    'status'    => false,
                    'code'      => 201,
                    'message'   => 'Invalid request',
                    'data'      => [

                        'mid'       => $mid,
                        'txnToken'  => $result['body']['txnToken']??'',
                        'order_id'  => "S11_".$order_id

                        ]
                ]; 
            }

        }catch(\Exception $e){
             return [
                    'status'    => false,
                    'code'      => 201,
                    'message'   => 'Invalid request',
                    'data'      => [

                        'mid'       => '',
                        'txnToken'  => '',
                        'order_id'  => "S11_".$order_id

                        ]
                ];
        }
    }
    //
    public function  toss()
    {
        $live_match = Matches::whereIn('status',[1,3])
                ->where('timestamp_start','>',time())
                ->get(); 
       
        foreach($live_match as $key => $value)
        {   
            $match_id = $value->match_id;
            $toss   = Toss::where('match_id',$match_id)
                        ->where('status',1)
                        ->whereNotNull('text')
                        ->first();
                       
            if(isset($toss->match_id)){
                $toss->status = 2;
                \DB::table('matches')->where('match_id',$toss->match_id)->update([
                    'notification' => $toss->text,
                    'order_by' => 2
                ]);
                $toss->save();
            }else{
                continue;
            }

            $title    = $value->short_title .' | '.'Deadline @'.date('h:i A',$value->timestamp_start);
            $message  = " Hurry up!! Create and join your team now!!";
            $data     = [
                            'action'    => 'notify',
                            'title'     => $title,
                            'message'   => $message
                        ];  
            $count = User::where('customer_type',0)->count(); 
            
            $j=1;
            for($i=1; $j<=$count; $i++) {
                $offset = $j;
                $j = $i*900; 
                $device_id = User::whereNotNull('device_id')
                      ->skip($offset)
                      ->take(900)
                      ->pluck('device_id')
                      ->toArray();
              try{
                 // $helpr = new Helper; 
                    $this->sendNotification($device_id, $data);
                   // return true;
              }catch(\ErrorException $e){
                    return false;
              }
            }
        }
    }
    //releaseWining

    public function notifyToAllUsers($title=null,$message=null)
    {
        $data = [
                'action'    => 'notify',
                'title'     => $title,
                'message'   => $message
            ];  
        $count = User::where('customer_type',0)->count(); 
        $j=1;
        for($i=1; $j<=$count; $i++) {
            $offset = $j;
            $j = $i*900; 
            $device_id = User::whereNotNull('device_id')
                  ->skip($offset)
                  ->take(900)
                  ->pluck('device_id')
                  ->toArray();
            try{
                 // $helpr = new Helper; 
                    $this->sendNotification($device_id, $data);
                   // return true;
            }catch(\ErrorException $e){
                    return false;
            }
        }
    }
    public function getCoupon(Request $reques)
    {
        $coupon = \DB::table('coupon')->select('code','amount','description','bonus','extra_cash')->get();

         return [
                    'status'    => true,
                    'code'      => 200,
                    'message'   => 'coupon list',
                    'data'      => $coupon
                ]; 
    }

    public function updateLastPlayedMatch(Request $request)
    {
         $played_last = Matches::select('match_id','status','format','competition_id','short_title')
            ->where('status',1)
            ->where('timestamp_start','>',time())
            ->chunk(20,function($matches){

                foreach($matches as $key => $match){
                    $match_ids = [];
                    $current_match_id = $match->match_id; 

                    $short_title = explode(" ",$match->short_title);
                    
                   
                    $competition = Competition::where('cid',$match->competition_id)
                            ->pluck('match_id')->toArray();

                    try{

                      //  

                    $players1 = Player::where('cid',$match->competition_id) 
                                    ->where('playing11',"true")
                                    ->whereIn('team_name',$short_title)
                                    ->orderBy('id','desc') 
                                    ->first();
                    $players2 = Player::where('cid',$match->competition_id) 
                                    ->where('playing11',"true")
                                    ->whereIn('team_name',$short_title)
                                    ->whereNotIn('team_name',[$players1->team_name])
                                    ->orderBy('id','desc') 
                                    ->first();


                    $match_ids[] = $players1->match_id??[];
                    $match_ids[] = $players2->match_id??[];

                   

                    
                        if(count($match_ids)){
                         $players = Player::whereIn('match_id',$match_ids)
                                    ->select('match_id','pid','playing11')
                                    ->where('playing11',"true")
                                    ->get();
                            
                        foreach($players as $key => $player)
                        {       

                             $p = Player::where('match_id',$current_match_id)
                                        ->where('pid',$player->pid)
                                        ->first(); 
                            
                           
                            if(isset($p->id)){
                                $p->played_last_match = "Played Last Match";
                                $p->save();
                            }

                        }            
                    }else{
                        continue;
                    } 
                    }catch(\Exception $e){
                        //
                    }       
                }
            });
        echo date('H:i:s A');
    }

    public function matchAutoCancelAfterAbondon(Request $request){
        
        $cancel_match = Matches::where('status',4)
                        ->where('match_abondon',1)
                        ->where('is_cancelled',0)
                        ->where('timestamp_start','>=',strtotime("-1 days"))
                        ->get();
                    //dd($cancel_match);    
                        $cancel_match->transform(function($item,$key) use($request){ 
                         
                        $contest_ids = JoinContest::where('match_id',$item->match_id)
                                        ->where('cancel_contest',0)
                                        ->pluck('contest_id')
                                        ->unique()
                                        ->toArray();

                       
                        foreach($contest_ids as $key =>  $contest_id)
                        {
                            $this->cancelContest($item->match_id,$contest_id);
                            $abondon =true;
                        }
                        
                       if(isset($abondon)){
                            \DB::table('matches')->where('match_id',$item->match_id)
                            ->update([
                                'match_abondon' => 1
                            ]); 
                       }    
                         
                    }); 
         
    }

    
    public function cancelContest($match_id=null,$contest_id=null){
         
        $request = new Request;
        $cancel_contest = CreateContest::find($contest_id);

                        
        if($match_id && $contest_id){
            $JoinContest = JoinContest::whereHas('user')
                        ->with('contest')
                        ->where('match_id',$match_id)
                        ->where('contest_id',$contest_id)
                        ->get()
                        ->transform(function($item,$key)use($cancel_contest){
                        
                        if($cancel_contest->usable_bonus){
                            $bonus_amount = $cancel_contest->entry_fees*($cancel_contest->usable_bonus/100);    
                        }else{
                            $bonus_amount = 0;
                        }
                            
                        $amont_deduction = \DB::table('contest_amount_deductions')
                                    ->where('contest_id',$item->contest_id)
                                    ->where('match_id',$item->match_id)
                                    ->where('user_id',$item->user_id)
                                    ->first();

                        $ec = $amont_deduction->extra_cash??0;
                        $da = $amont_deduction->deposit_amount??0;
                        $wa = $amont_deduction->winning_amount??0;
                        $ba = $amont_deduction->bonus_amount??0;
                        $ef = $amont_deduction->entry_fees??0;
                       
                      //  $aa = $ec + $da +  $wa + $ba;
                        $aa = $ef;
                        $amount = $ef-$bonus_amount-$ec;
                        
                        if($item->cancel_contest==0){
                            \DB::beginTransaction();  
                            $cancel_contest->is_cancelled = 1;
                            $cancel_contest->save();

                            if(isset($item->contest) && $item->contest->entry_fees){
                                
                               $transaction_id = $item->match_id.'N'.$item->contest_id.'N'.$item->created_team_id;

                            $user_wallets =  Wallet::where('user_id',$item->user_id)->get();
                            $in_deposit = $user_wallets->where('payment_type',3)->where('user_id',$item->user_id)
                                        ->sum('amount');
                            $in_winning = $user_wallets->where('payment_type',4)->where('user_id',$item->user_id)
                                        ->sum('amount');

                                $wt =    WalletTransaction::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'transaction_id' => $transaction_id
                                        ]
                                    );
                                $wt->user_id            = $item->user_id;   
                                $wt->amount             = $aa;  
                                $wt->payment_type       = 7;  
                                $wt->payment_type_string= "Refunded";
                                $wt->transaction_id     = $transaction_id;
                                $wt->payment_mode       = 'N';    
                                $wt->payment_status     = "success";
                                $wt->debit_credit_status= "+";
                                $wt->match_id           = $item->match_id;
                                $wt->contest_id         =  $item->contest_id;

                                $dawa = $da+$wa;
                                $wt->in_deposit = $in_deposit;
                                $wt->remaining_amount = $in_deposit+$in_winning;
                                $wt->in_winning = $in_winning;
                                $wt->total_amount = $in_deposit+$in_winning+$dawa;
   
                                
                                $wt->save();
                                
                                // main Balance 
                                 $wallet = Wallet::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'payment_type' => 3
                                        ]
                                    );

                                $wallet->user_id        =  $item->user_id;
                                $wallet->amount         =  $wallet->amount+$da;
                                $wallet->extra_cash     =  $wallet->extra_cash+$ec;
                                
                                $wallet->save();
                                // Bonus Balance
                                $wallet2 = Wallet::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'payment_type' => 1
                                        ]
                                    );

                                $wallet2->user_id        =  $item->user_id;
                                $wallet2->amount = $wallet2->amount+$ba;
                                $wallet2->save();

                                //winning amount

                                $wallet3 = Wallet::firstOrNew(
                                        [
                                           'user_id' => $item->user_id,
                                           'payment_type' => 4
                                        ]
                                    );
                                
                                $wallet3->user_id        =  $item->user_id;
                                $wallet3->amount = $wallet3->amount+$wa;
                                $wallet3->save();
                                //  history
                                $wt->in_deposit = $wallet->amount;
                                $wt->remaining_amount = $wallet->amount+$wallet3->amount;
                                $wt->in_winning = $wallet3->amount;
                                $wt->total_amount = $wallet->amount+$wallet3->amount;     
                                $wt->save(); 
                            }
                            \DB::commit();  

                            $item->cancel_message = 'Contest Cancelled' ;
                            return $item;
                        }else{
                            $item->cancel_message = 'Already Cancelled' ; 
                            return $item; 
                        }
                    });               
        
        if($JoinContest->count()==0 and $contest_id){
           
          //  foreach ($request->cancel_contest as $key => $value) {
                $cancel_contest = CreateContest::find($contest_id);
                $cancel_contest->is_cancelled = 1;
                $cancel_contest->save();
          //  }

           return  ['Selected contest is cancelled'];

        }
                
        $match      = Matches::where('match_id',$match_id)->first();
        $contest    = $cancel_contest;

        $join_contest_user = JoinContest::where('match_id',$match_id)
                            ->where('contest_id',$contest_id)
                            ->where('cancel_contest',0)
                            ->pluck('user_id')
                            ->toArray();
                           
        $device_id  = User::whereIn('id',$join_contest_user)
                        ->pluck('device_id')
                        ->where('customer_type',0)
                        ->toArray();

        $data = [
                    'action' => 'notify' ,
                    'title' => "Contest Cancel | $match->short_title" ,
                    'message' => $match->short_title. " Contest of  $contest->entry_fees Rupess entry is cancelled"
                ];
                
        $this->sendNotification($device_id, $data);

        $JoinContest = JoinContest::where('match_id',$match_id)
                        ->where('contest_id',$contest_id)
                        ->update(['cancel_contest'=>1]);

        return [' Contest Cancelled successfully'];
        }else{
            return ['No Contest selected for cancellation']; 
        }
    }

    //reedeemPoint'

    public function reedeemPoint(Request $request)
    {  
        $points = $request->points;
        $user_id = $request->user_id;
        try{

            $re_points = \DB::table('ninja_rewards')
                                    ->where('user_id',$user_id)
                                    ->sum('amount');

            $chk_points = \DB::table('checkin_rewards')
                                    ->where('user_id',$user_id)
                                    ->sum('reward_points');

            $total_rewards =  $re_points+$chk_points;
            
            if($points<2000){
                return [
                    'code' => 201,
                    'status' => false,
                    'message' => "Minimum 2000 points is required to redeem"
                ];
            }elseif($total_rewards<2000){
                return [
                    'code' => 201,
                    'status' => false,
                    'message' => "Minimum 2000 points is required to redeem"
                ];
            }

            return [
                    'code' => 200,
                    'status' => true,
                    'message' => "Your amount will be credited in your wallet in next 24 hours"
                ];


        }catch(\Exception $e){
            return false;
        }

    }   
    /*
        refund
    */
    public function winningReversal(Request $request)
    {
        $match_id   = $request->match_id??91557;
        $contest_id = $request->contest_id??822069;
        /*
        if($request->user_id)
        {
           $user_id    =  [$request->user_id];
        }else{
         //  $user_id    = ['80392','80123','54564','75760'];  
        }
        
        $joining_fees = \DB::table('contest_amount_deductions')
                        ->where('match_id',$match_id)
                        ->where('contest_id',$contest_id)
                      //  ->whereIn('user_id',$user_id)
                        ->get();
                        // mega contest amount refund
                      //  dd($joining_fees);
        foreach($joining_fees as $key => $result)
        {
            $bonus_amount   = $result->bonus_amount;
            $deposit_amount = $result->deposit_amount;
            $winning_amount = $result->winning_amount;
            $extra_cash     = $result->extra_cash;
    
            $total = $deposit_amount+$winning_amount+$extra_cash;
    
            if($deposit_amount || $extra_cash) {   
                $wallet     =  Wallet::where('user_id',$result->user_id)
                                ->where('payment_type',3)
                                    ->first();

                $wallet->amount = $wallet->amount+$deposit_amount;
                $wallet->extra_cash = $wallet->extra_cash+$extra_cash;
              //  $wallet->save();
            }
            if($winning_amount){    
                $wallet_w =  Wallet::where('user_id',$result->user_id)
                                ->where('payment_type',4)
                                    ->first();

                $wallet_w->amount = $wallet_w->amount+$winning_amount;
              //  $wallet_w->save();
            }
            if($bonus_amount>0 && $extra_cash<1) {   
                $wallet_b =   Wallet::where('user_id',$result->user_id)
                                ->where('payment_type',1)
                                    ->first();

                $wallet_b->amount = $wallet_b->amount+$bonus_amount;
             //   $wallet_b->save();
            }

            $wt_status = new WalletTransaction;
            $wt_status->refund_status     = 1;
            $wt_status->amount            = $total;
            $wt_status->order_id          =  time();
            $wt_status->payment_type      = 7;
             $wt_status->payment_type_string      = "Amount Refund";
            $wt_status->user_id           = $result->user_id;
            $wt_status->payment_status    = "success";
            $wt_status->debit_credit_status = "+";
            $wt_status->match_id            = $match_id;
            $wt_status->contest_id          = $contest_id;
            $wt_status->payment_details     = "amount reversal";
            
           // $wt_status->save();            
        }
        */
        

        $match_id = 95839;

DB::transaction(function () use ($match_id) {

    $wts = WalletTransaction::where('match_id', $match_id)
        ->where('payment_type', 4)
        ->where('refund_status', 0)
        ->lockForUpdate() // 🔒 prevent race condition
        ->get();

 

    foreach ($wts as $wt) {

        $wallet = Wallet::where('user_id', $wt->user_id)
            ->where('payment_type', 4)
            ->lockForUpdate() // 🔒 lock wallet row
            ->first();


        if (!$wallet) { 

            WalletTransaction::where('id', $wt->id)->delete();


            continue; // skip if wallet not found

        }

        // Deduct amount
        // $wallet->amount -= $wt->amount;
        // $wallet->save();

        // // Update transaction
        // $wt->refund_status = 1;
        // $wt->in_winning    = $wallet->amount;
        // $wt->save();

        // $data[$wt->user_id][] = $wt->amount;
    }

});

die('--');



            $match_id =     95838;
            $wts = WalletTransaction::where('match_id',$match_id)
                                ->where('payment_type',4)
                                ->where('refund_status',0) 
                                ->get();
 
            foreach($wts as $key => $wt)
            {
               $wallet =  Wallet::where('user_id',$wt->user_id)->where('payment_type',4)
                        ->first();
                        $am = $wallet->amount;
                $wallet->amount = $wallet->amount-$wt->amount;
                $wallet->save();

                $wt_status = WalletTransaction::find($wt->id);
                $wt_status->refund_status = 1;
                $wt_status->in_winning    = $wallet->amount;
                $wt_status->save();
 
                 
                $daata[$wt->user_id][] =  $wt->amount;
            }     
    }
    public function removematch(){
        $match = Matches::where('status',3)
                    ->get()
                    ->transform(function($item,$key){
                        $jc = JoinContest::where('match_id',$item->match_id)
                                ->count();
                        if($jc==0 &&  time() > $item->timestamp_start){
                             $mid = Matches::find($item->id);
                             $mid->status = 4;
                             $mid->is_cancelled = 1;
                             $mid->save();
                        }
                    });       
    }
    public function getPlaying11Players($match_id)
    {
        $p = Player::where('match_id',$match_id)
                ->where('playing11',"true")
                ->pluck('playing_role','pid');

           return $p;
    }


    public function deleteUnusedData()
    {
        
        $match_id = Matches::whereYear('created_at',2021)
                    ->pluck('match_id');
        $idz = [];
        $contest_id = CreateContest::whereIn('match_id',$match_id)
                        ->whereYear('created_at',2021)
                        ->get();

        $join_contests = JoinContest::whereIn('contest_id',$contest_id)
                    ->get();

        dd($join_contests);

         $del = [];
         $i=1;
        foreach($contest_id as  $key => $value)
        {
            $jc = JoinContest::where('contest_id',$value->id)->first();
            if($jc)
            {
                $idz[] = $value->id;
            }else{
                $del[] = $value->id;  
            }
        }
        dd($del);


        $user_id = User::where('customer_type',3)->pluck('id');

        $join_contests = JoinContest::whereIn('user_id',$user_id)
                    ->whereIn('contest_id',$contest_id)
                    //->whereYear('created_at',2021)
                    ->get();




        $teams = CreateTeam::whereIn('id',$join_contests)
                ->get();

        dd($join_contests,$teams);
    }

    public function addReverseKey()
    {
        $live_match = Matches::whereIn('status',[3])
                   // ->where('timestamp_start','>=' ,time())
                    ->get();
                  
        foreach($live_match as $key => $match)
        {
            $match_id = $match->match_id;

            $cc = CreateContest::where('match_id', $match_id)
                        ->where('filled_spot','>=',2)
                        ->pluck('id');

            $jc = JoinContest::whereIn('contest_id',$cc)
                                ->where('match_id',$match_id)
                                ->where('entry_fees',0)
                                ->get()
                                ->groupBy('contest_id');
            
            foreach($jc as $key => $value)
            {
                $c = CreateContest::find($key);
                $c->is_reversed = 1;
                $c->save();
            }
        }
    }

     public function getTimestampfromUrl($url=null)

            {
                if($url==null)
                    {
                        return false;
                    } 

                    // 1️⃣ Parse the path from URL
                    $path = parse_url($url, PHP_URL_PATH);

                    // 2️⃣ Split path segments
                    $segments = explode('/', trim($path, '/'));

                    // 3️⃣ Get the timestamp value (4th segment in your case)
                    $timestamp = $segments[3] ?? null;
                    return   $timestamp ;
                     
            }
    public function returnTeamId()
    {
        $ct = createTeam::where('match_id',52688)->where('team_join_status',1)->where('team_count','T1')->get();

        //$ct = createTeam::where('match_id',52684)->where('team_join_status',1)->where('contest_id','>',0)->get();
        
        
        foreach($ct as $key =>$value)
        {
            
            if(0)
            {
                $cad = \DB::table('eventLogs')
                                ->where('match_id',$value->match_id)
                                ->where('user_id',$value->user_id)
                                ->where('team_id',$value->id)
                                ->get();
                       
               //  dd($value);        

                $jc =  joinContest::firstOrNew([
                    'user_id' => $value->user_id,
                    'match_id' => $value->match_id,
                    'contest_id' => $value->contest_id,
                    'created_team_id' => $value->id
                    
                ]);
                $jc->match_id = $value->match_id;
                $jc->contest_id = $value->contest_id;
                $jc->user_id = $value->user_id;
                $jc->created_team_id = $value->id;
                $jc->team_count = $value->team_count;
                $jc->points = $value->points;

                $jc->save();
                
            }else{
                $cad = \DB::table('eventLogs')
                                ->where('match_id',$value->match_id)
                                ->where('user_id',$value->user_id)
                                ->where('team_id',$value->id)
                                ->get();
                       
                $wt = WalletTransaction::where('match_id',$value->match_id)
                                ->where('user_id',$value->user_id)
                            //  ->where('contest_id',$value->contest_id)
                                ->where('payment_type',6)
                                ->get();
                //  dd($value);        

                foreach($wt as $key =>$result)
                {
                    $jc =  joinContest::firstOrNew([
                        'user_id' => $value->user_id,
                        'match_id' => $value->match_id,
                        'contest_id' => $result->contest_id,
                        'created_team_id' => $value->id
                        
                    ]);
                    $jc->match_id = $value->match_id;
                    $jc->contest_id = $result->contest_id;
                    $jc->user_id = $value->user_id;
                    $jc->created_team_id = $value->id;
                    $jc->team_count = $value->team_count;
                    $jc->points = $value->points;

                    $jc->save();
                    
                }
            }
            

        }


    }

    // table object
    public function getTableObject($table_cname=null)
    {

        $tableObject = \DB::table($table_cname);
        return $tableObject;
    }

    // notification 
            public function sendSingleNotification(Request $request)
            {
        
                $token=$request->token;
                $notification =   [
                        'title' =>  $request->title??'10k Giveaway 🥷',
                        'body' => $request->message??'Create and Join your team now!',
                        'image' => $request->image??"https://rest.ninja11.in/ipl.png"
                    ];
            $rs =  $this->sendNotificationAndroid($notification, $token);

                return $rs;
            }


            public function sendNotificationAndroid($notifications, $token=null,$image=null){

                $accessToken = $this->getAccessToken();
            
                $fcmUrl = "https://fcm.googleapis.com/v1/projects/stumps-40dfd/messages:send";
                            
            
            
                $notification =  $notifications??[
                        'title' =>  $request->title??'10k Giveaway 🥷',
                        'body' => $request->message??'Create and Join your team now!',
                        'image' => $request->image??"https://rest.ninja11.in/ipl.png"
                    ];
                
            
                $payload = [
                    'message' => [
                        'token' => $token, // Single device token
                        'notification' => $notification,
                        'data' => ['hello' => 'roy'], // Include data payload if needed
                        'android' => [
                            'priority' => 'high' // Set priority for Android
                        ],
                        'apns' => [
                            'headers' => [
                                'apns-priority' => '10' // Set priority for iOS
                            ]
                        ]
                    ]
                ];
        
                $headers = [
                    'Authorization: Bearer ' . $accessToken,
                    'Content-Type: application/json'
                ];
            
            
                // Initialize cURL request
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $fcmUrl);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true); // Secure SSL verification
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            
                // Execute request
                $result = curl_exec($ch);
            
                if ($result === FALSE) {
                    die('Curl failed: ' . curl_error($ch));
                }
            
                curl_close($ch);
            
                // Debug: Print the FCM response
            return  $result;
            } 
            // notification key
            public function getAccessToken($serviceAccountFilePath="/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json") {
                $client = new Google_Client();
                $client->setAuthConfig($serviceAccountFilePath);
                $client->addScope('https://www.googleapis.com/auth/firebase.messaging');
                $client->fetchAccessTokenWithAssertion();
                $token = $client->getAccessToken();
                return $token['access_token'];
            }

            // notification function
        
            
            public function notifyUser(Request $request)
            {
                $serviceAccountFilePath = '/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json';
                $accessToken = $this->getAccessToken($serviceAccountFilePath);

                $projectId = 'stumps-40dfd';
                $topic = $request->topic??'notifyDocumentverified';  
                $payload = [
                    'message' => [
                        'topic' => $topic,
                        'notification' => [
                            'title' =>  $request->title??'10k Giveaway 🥷',
                            'body' => $request->message??'Create and Join your team now!',
                            'image' => $request->image??"https://rest.ninja11.in/ipl.png"
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [ 
                                'sound' => 'default',
                            ],
                        ],
                        'data' => [
                            'custom_key' => 'custom_value',
                            'action' => 'OPEN_SCREEN',
                        ],
                    ],
                ];
                

                $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                return $response->json();
            }
            public function sendTopicNotification(Request $request)
            {
                $serviceAccountFilePath = '/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json';
                $accessToken = $this->getAccessToken($serviceAccountFilePath);

                $projectId = 'stumps-40dfd';
                $topic = 'notifyToWalletsTransaction';  
                $payload = [
                    'message' => [
                        'topic' => $topic,
                        'notification' => [
                            'title' => '10k Giveaway 🥷',
                            'body' => 'Create and Join your team now!',
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [ 
                                'sound' => 'default',
                            ],
                        ],
                        'data' => [
                            'custom_key' => 'custom_value',
                            'action' => 'OPEN_SCREEN',
                        ],
                    ],
                ];
                

                $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type' => 'application/json',
                ])->post($url, $payload);

                return $response->json();
            }

            public function returnMsg()
            {
                $serviceAccountFilePath = '/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json';
                $accessToken = $this->getAccessToken($serviceAccountFilePath); 

                $factory    = (new Factory)->withServiceAccount($serviceAccountFilePath);
                $messaging  = $factory->createMessaging(); 

                return $messaging;
            }

            public function updateDailySubscriber()
            {
                echo date('h:i:s A'); 
                $serviceAccountFilePath = '/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json';
                $factory = (new Factory)->withServiceAccount($serviceAccountFilePath);
                $messaging = $factory->createMessaging();   
            
                $tokens = User::whereNotNull('device_id')
                        ->where('customer_type',0)
                        ->pluck('device_id')
                        ->filter()
                        ->unique()
                        ->values()
                        ->toArray();

            
                $topic = "notifyUser2025";
                $batchSize = 999; // Firebase allows up to 1000 tokens per request, keep it safer with 500
                $chunks = array_chunk($tokens, $batchSize);
            
                foreach ($chunks as $index => $chunk) {
                    try {
                        $messaging->subscribeToTopic($topic, $chunk);
                        // Optional: Log progress for monitoring large batch runs
                        Log::info("Subscribed chunk {$index} to topic {$topic}");
                    } catch (MessagingException $e) {
                        Log::error("Failed to subscribe chunk {$index}: " . $e->getMessage());
                        // Optional: Retry mechanism or store failed tokens for retry
                    } catch (\Throwable $e) {
                        Log::error("Unexpected error in chunk {$index}: " . $e->getMessage());
                    }
            
                    // Sleep briefly to prevent rate limit issues
                    usleep(100000); // 100ms
                }
                echo date('h:i:s A');
            
                return response()->json(['status' => true, 'message' => 'Topic subscription complete']);

            }

           public function paymentReturnUrl(Request $request)
            {
                echo "<br><br>
                    <h2 style='text-align:center; color:green'>
                        Thank you!! Check your wallet Now!!
                    </h2>

                    <p style='text-align:center; font-size:18px;'>
                        Closing in <span id='count'>5</span> seconds...
                    </p>

                    <script>
                        let count = 5;

                        let counter = setInterval(function() {
                            count--;
                            document.getElementById('count').innerText = count;

                            if (count <= 0) {
                                clearInterval(counter);

                                // Try to close tab
                                window.open('', '_self');
                                window.close();

                                // Fallback (if browser blocks close)
                                window.location.href = '/'; 
                            }
                        }, 1000);
                    </script>";
            }


            public function updateNotification($topic, $title ,$body_msg, $accessToken )
            {
                $projectId  = 'stumps-40dfd';
                $payload = [
                    'message' => [
                        'topic' => $topic,
                        'notification' => [
                            'title' => $title,
                            'body'  => $body_msg,
                        ],
                        'android' => [
                            'priority' => 'high',
                            'notification' => [
                                'sound' => 'default',
                            ],
                        ],
                        'data' => [
                            'custom_key' => 'custom_value',
                            'action'     => 'OPEN_SCREEN',
                        ],
                    ],
                ];

                $url = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

                $response = Http::withHeaders([
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                ])->post($url, $payload);

                return $response->json();
            }


         
            
            public function extractPaymentDetails($text)
            {
                $results = [
                    'merchant_name' => null,
                    'amount' => null,
                    'upi_name' => null,
                    'upi_id' => null,
                    'utr_number' => null,
                ];

                // 1️⃣ Extract UPI name & ID (BHARATPE + something@fbpe)
                $upi_pattern = '/(hdfcbank)[\s\n\r]*\.?([A-Z0-9]+@fbpe)/i';
                if (preg_match($upi_pattern, $text, $matches)) {
                    $results['upi_name'] = trim($matches[1]);
                    $results['upi_id'] = trim($matches[2]);
                }elseif (preg_match('/([A-Za-z0-9]+@[a-z]+\.?[a-z]*)/i', $text, $matches)) {
                    $results['upi_id'] = strtolower(trim($matches[1]));
                }

                

                // 2️⃣ Extract UTR number
                $utr_pattern = '/UTR[:\s]+(\d+)/i';
                if (preg_match($utr_pattern, $text, $matches)) {
                    $results['utr_number'] = trim($matches[1]);
                }elseif (preg_match('/(Ref\.?\s*No[:\s]*)?(\d{9,15})/i', $text, $matches)) {
                    $results['utr_number'] = trim($matches[2]);
                } 

                // 3️⃣ Extract merchant name and amount
                // Handles cases like: "SERVICES 285", "SERVICES =100", "SERVICES %565", "SERVICES ₹450"
                $merchant_pattern = '/([A-Z\s]+hdfcbank)\s*[%=₹:\-]?\s*(\d{2,6})/i';
                if (preg_match($merchant_pattern, $text, $matches)) {
                    $results['merchant_name'] = trim($matches[1]);
                    $results['amount'] = (int) trim($matches[2]);
                }

                return $results;
            }

            public function updateDeposit( $request)
            {
                $wallets = Wallet::find($request->wallet_id);
                
                
                $wt     = $wallets;
                $tid    = trim($request->transaction_id,'s'); 

                $wtt    = WalletTransaction::where('transaction_id',$request->transaction_id)->count();

                if($wtt)
                {
                    return 'Transaction id is already exist!!.';
                }

                $wt_tsn = new WalletTransaction;
                $wt_tsn->user_id        =   $wt->user_id;
                $wt_tsn->amount         =   $request->amount;
                $wt_tsn->payment_type   =   3;
                $wt_tsn->order_id       =   $request->transaction_id;
                $wt_tsn->payment_type_string = "Deposit Success";
                $wt_tsn->transaction_id =   $request->transaction_id;
                $wt_tsn->payment_mode   =   "Manual Adjustment";
                $wt_tsn->payment_details =  json_encode($request->all());
                $wt_tsn->email          = 'system@ninjax11.com'; 

             
                $wt_tsn->save(); 

                $wt->amount = $wallets->amount+ $request->amount;
                $wt->old_amount = $wt->amount; 
                
                $wallets->save();

                
                WalletTransaction::where('transaction_id',$tid)
                    ->where('payment_type',10)
                    ->update([
                        'payment_type' => 11,
                        'payment_type_string' => 'Deposit Success'
                    ]);

                
                return "amount added";
            }

            public function parseReceipt($text)
                {
                                
                                                
                    $clean = preg_replace('/\s+/', ' ', $text);

                    // ----------------------------------------------------
                    // 1. Extract UPI ID
                    // ----------------------------------------------------
                    preg_match('/[A-Za-z0-9.\-_]+@[A-Za-z]+/', $clean, $upiMatch);
                    $upiId = $upiMatch[0] ?? null;

                    // ----------------------------------------------------
                    // 2. Extract UTR
                    // ----------------------------------------------------
                    preg_match('/(?:UTR[: ]+|UPI.?transaction.?ID[: ]+)(\d{6,20})/i', $clean, $utrMatch);
                    $utr = $utrMatch[1] ?? null;

                    // ----------------------------------------------------
                    // 3. Extract Amount (multiple fallback rules)
                    // ----------------------------------------------------
                    $amount = null;

                    // A) Try ₹xxx first
                    if (preg_match_all('/₹\s*([0-9][0-9,]*)/u', $clean, $m)) {
                        $amount = max(array_map(fn($x)=>floatval(str_replace(',', '', $x)), $m[1]));
                    }

                    // B) If still empty → look for "<NAME> <AMOUNT>" pattern
                    if (!$amount) {
                        if (preg_match('/INFOWAY DIGITAL\s+([0-9][0-9,]*)/i', $clean, $m2)) {
                            $amount = floatval(str_replace(',', '', $m2[1]));
                        }
                    }

                    // C) Fallback: take the largest 2–5 digit number (ignore years)
                    if (!$amount) {
                        preg_match_all('/\b\d{2,5}\b/', $clean, $nums);
                        $filtered = array_filter($nums[0], fn($n) =>
                            ($n > 10 && $n < 50000 && ($n < 1900 || $n > 2100)) // avoid years
                        );
                        if (!empty($filtered)) {
                            $amount = max($filtered);
                        }
                    }

                    return [
                        'upi_id' => $upiId,
                        'utr'    => $utr,
                        'amount' => $amount, 
                    ];
                  
                }

            public function processPayout(Request $request)
            {
                
                    $amount = $request->amount;

                    if($amount >10000)
                    {
                      // echo '<h3> Payout server is busy, Try after sometime!!</h3>';
                      // die; 
                    }

                    $timestamp = time(); 
                    // IST Time (UTC+5:30)
                    date_default_timezone_set('Asia/Kolkata'); 
                    // Get hour in 24-hour format
                    $current_hour = (int)date('H', $timestamp);

                    // Check if time is between 10 PM (22:00) and 6 AM (06:00)
                     $charge = "0.8";  
                    if($current_hour >= 22 || $current_hour < 6)
                    {
                       $charge = "0.8";  
                    }

                    if($amount>700)
                    {
                         return "In test mode max 700 allowed";
                    } 
                    
                    $fc_payout = \DB::table('fc_payouts')->first(); 

                    if($fc_payout->current_blance < $amount)
                    {
                        return [
                            "code" => 200,
                            "status" => false,
                            "message" => "insufficient Balance" 
                        ];
                    }
                   

                    $final_amount = (int)($amount*($charge)); 
                    
                    
                    $curl = curl_init(); 
                    $data = [
                        "transaction_id"    => "payout".$request->txn_id,
                        "amount"            => (int) $final_amount,
                        "creditorAccountNo" => $request->account_number,
                        "creditorIFSC"      => $request->ifsc_code,
                        "creditorName"      => $request->account_name,
                        "creditorEmail"     => $request->email,
                        "creditorMobile"    => $request->mobile_number,
                        "paymentType"       => "IMPS",
                        "address"          => [
                                            "address_1" => "Luckno Uttar Pradesh",
                                            "address_2" => $user->city??'delhi',
                                            "state"     => $user->city??'delhi',
                                            "city"      => $user->city??'delhi',
                                            "pin"       => "100001"
                                        ]
                    ];

                  
                        
                    curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://corpelastic.cgpey.com/api/v1/payout/payments',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS =>  json_encode($data),
                    CURLOPT_HTTPHEADER => array(
                        'x-api-key: 168RQ4iSAnE6AEtBZGTjV2wFECMQSJoOxxZCaOuMBcX',
                        'x-secret-key: 16863TRnV7nqgxKv0GNxcKq9dz0xKu9wCpP9asYaY2jRdCCNdZrGomuGnlNEuEr1vRs3s0P4S',
                        'x-owner-name: kroy',
                        'Content-Type: application/json'
                        ),
                    ));

                    
                    $response = (object)json_decode(curl_exec($curl),true);
                   
                    curl_close($curl);
                    if($response->status)
                    {   
                        $remaining_blnc = $fc_payout->current_blance - $request->amount;
                      
                        \DB::table('fc_payouts')
                            ->where('id', $fc_payout->id)
                            ->update([
                                'current_blance'    => $remaining_blnc,
                                'previous_blance'   => $fc_payout->current_blance,
                                'total_release'     => $fc_payout->total_release+$request->amount
                            ]); 

                            $trb = $fc_payout->total_release+$request->amount;
 
                        \DB::table('payouts')->updateOrInsert(
                                    // Match condition
                                    ['transaction_id' => $request->txn_id],

                                    // Values to update/insert
                                    [
                                       
                                        'requested_by'  => 'FC11',
                                        'email'         => $request->email,
                                        'mobile_number' => $request->mobile_number,
                                        'name'          => $request->account_name,
                                        'amount'        => $request->amount,
                                        'order_id'      => $response->exch_transaction_id,
                                        'payout_type'   => 'bank',
                                        'status'        => 2,
                                        'message'       => $response->message
                                    ]
                                );

                        return  [ 
                                        'code'          => 200,
                                        'status'        => true,
                                        'requested_by'  => 'FC11', 
                                        'amount'        => $request->amount,
                                        'order_id'      => $response->exch_transaction_id,  
                                        'available_payout_balance' => $remaining_blnc,
                                        'total_release_balance' => $trb,
                                        'message'       => $response->message 
                                    ];

                    } else{
                          return  $response; 
                    }

            }
            public function payoutStatus(Request $request)
            {
                $data = [
                            "transaction_id"    => $request->transaction_id,
                            
                        ];


                    $curl = curl_init(); 
                   

                    curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://corpelastic.cgpey.com/api/v1/payout/transactionStatusById', //'https://merchant.cgpey.com/api/v2/payment-check-status',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS =>  json_encode($data),
                     CURLOPT_HTTPHEADER => array(
                        'x-api-key: 1864IfmiYHrMwtvhW9c0eBdi5B9rNJHReRPyP03aMpz',
                        'x-secret-key: 186QJmxHLiTVs4VqlElkUL6YsxH9gsMQLEgavzhTodX7BBPmvT4SqtWBmHPSWQTvcRvnLjwp2',
                        'x-owner-name: Kroyspark',
                        'Content-Type: application/json'
                        ),
                    ));

                    

                    
                    curl_close($curl);

                    $response = (object)json_decode(curl_exec($curl),true);
                    return  $response;
            }


            public function qrPayment(Request $request)
            {
                if($request->get('qr'))
                    {
                        $uid = $request->uid??285;
                         return view('qrHtml', compact('uid'));
                    }
                

            
                $curl       =   curl_init();
                        $user_id    =   $request->uid??285;
                        
                        if($request->amount<100)
                        {
                            $amount     = 100;
                        }else{
                            $amount     = $request->amount??100; 
                        }  
                         
                        $user = User::find($user_id);
                        if(!$user){
                              return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://rest.ninja11.in/payment?email=' . urlencode(($user->email)??NULL).'&amount='.$amount, 
                                        'data' => $result??null
                                ];
                        }

                        $txt_id             =  'TXT'.$user_id. time();
                        $user->modeOfreach  = $txt_id;
                        

                        curl_setopt_array($curl, array(
                            CURLOPT_URL => 'https://merchant.cgpey.com/api/v2/makepayment',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => 'POST',
                            CURLOPT_POSTFIELDS => json_encode([ 
                                "name"           => $user->name,
                                "mobile_number"  => $user->mobile_number,
                                "amount"         => $amount, 
                                "return_url"     => "https://infowaydigital.com/api/v3/paymentReturnUrl?email=$user->email&txid=$txt_id",
                                "transaction_id" => $txt_id
                            ]),
                            CURLOPT_HTTPHEADER => array(
                                'x-api-key: 83mXpuwfCynwLc0S8DFKp2Xz93fpKueXn3EovyPFVK',
                                'x-secret-key: 83qzPPXoIhWHLsTj50ghBeaZY0f1FTsy4kCRWNm7PmYWCG4gn3GX0J5WopCv6hoTflzM1kPR', 
                                'ip-address: 3.7.137.238',
                                'Content-Type: application/json'
                            ),
                        ));
                        
                    $response = curl_exec($curl);
                    $result =(object)json_decode($response, true);

                    //

                    $qr = $result->data['intentData'];

                    if(!isset($result->status) ||  $result->status==false)
                        {  
                            
                        
                            return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email).'&amount='.$amount, 
                                        'data' => $result??null
                                ];
                        

                    }else{ 

                            $txt_timestamp =  $this->getTimestampfromUrl($result->data['intentData']);   
                            $user->txt_timestamp = $txt_timestamp;   
                            
                            \DB::table('payment_logs')->insert([
                                        'txt_id'    =>      $txt_id,
                                        'order_id'  =>      $txt_timestamp,
                                        'user_id'   =>      $user_id,
                                        'amount'    =>      $amount,
                                        'content'   =>      json_encode($result)
                            
                                ]); 


                            $user->txn_id       =  $txt_id;
                            $user->last_amount  = $amount;
                            $user->save();


                            curl_close($curl);
                            $qr = $result->data['intentData'];
                            $amount = $result->data['amount']; 

                            $env_pay_qr = env('enable_qrpay',2);
                            $env_pay_hdfc = env('env_pay_hdfc',2);

                            if(  $amount <=2000 && $env_pay_qr==2){
                                     return [
                                                'ok' => 'success',
                                                'status' => true, 
                                                'url' => $qr,
                                                'data' => $result??null
                                        ]; 
                                }
                                elseif(  $amount >2000 ||  $env_pay_hdfc==1){
                                      return [
                                                'ok' => 'success',
                                                'status' => true, 
                                                'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email).'&amount='.$amount, 
                                                'data' => $result??null
                                        ];
                                }
                                else{
                                    return [
                                                'ok' => 'success',
                                                'status' => true, 
                                                'url' => "https://rzp.io/rzp/ninjaxpay", 
                                                'data' => $result??null
                                        ];
                                } 
                           
                        }
            }

            public function takeMoney($amount=2000)
            {

                    $user   = User::find(285); 
                   
                    $curl = curl_init(); 
                    $data = [
                        "transaction_id"    => "payout"."285_".date('ymdhis'),
                        "amount"            => $amount??500,
                        "creditorAccountNo" => "5251156230", 
                       "creditorIFSC"      => "KKBK0004614", 
                       "creditorName"      => "Deoraj Singh",

                         // "creditorName"      => "infoway digital solution",
                         // "creditorAccountNo" => "50200106424725",
                         //  "creditorIFSC"      => "HDFC0001771",
                        "creditorEmail"     => "hdfcbank123@gmail.com",
                        "creditorMobile"    => "9243055792",
                        "paymentType"       => "IMPS",
                        "address"          => [
                                            "address_1" => "Delhi",
                                            "address_2" => $user->city??'delhi',
                                            "state"     => $user->city??'delhi',
                                            "city"      => $user->city??'delhi',
                                            "pin"       => "100001"
                                        ]
                    ];
                       
                    curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://corpelastic.cgpey.com/api/v1/payout/payments',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS =>  json_encode($data),
                    // CURLOPT_HTTPHEADER => array(
                    //     'x-api-key: 194Bj09do0HlnTgVNXdR4tkvFUIyquES4r7PqNuuzQs',
                    //     'x-secret-key: 194dXH5cIzr3VbkeQYAgyyLDxHqokmKu7XQkuE0WZYZz69N18DEYn4XXyCEQWv0mxch4LLFZR',
                    //     'x-owner-name: Kundayroy',
                    //     'Content-Type: application/json'
                    //     ),
                    // ));
 

                    CURLOPT_HTTPHEADER => array(
                             'x-api-key: 1864IfmiYHrMwtvhW9c0eBdi5B9rNJHReRPyP03aMpz',
                            'x-secret-key: 186QJmxHLiTVs4VqlElkUL6YsxH9gsMQLEgavzhTodX7BBPmvT4SqtWBmHPSWQTvcRvnLjwp2',
                            'x-owner-name: Kroyspark',
                            'Content-Type: application/json'
                            ),
                    ));


                    
                    $response = (object)json_decode(curl_exec($curl),true);
  // dd($response );
                    curl_close($curl);
                    return $response;

            }

            public function bankCheck($request)
            {
                $headers = [
                            "consumerSecret: ab398f4be45240bc",
                            "consumerKey: 13c479a80d559e66",
                            "partnerid: 240061",
                            "Content-Type: application/json"
                        ];
                        

 
                        $data = [
                                "purpose_message" => 'This is penniless',
                                "validation_type" => 'penniless',
                                "account_number" =>  $request->account_number,
                                "ifscCode" => $request->ifsc_code
                        ];

                        $curl = curl_init();

                        curl_setopt_array($curl, array(
                          CURLOPT_URL => 'https://api.sparkuptech.in/api/dto/validate_account',
                          CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_ENCODING => '',
                          CURLOPT_MAXREDIRS => 10,
                          CURLOPT_TIMEOUT => 0,
                          CURLOPT_FOLLOWLOCATION => true,
                          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                          CURLOPT_CUSTOMREQUEST => 'POST',
                          CURLOPT_POSTFIELDS =>json_encode($data),
                          CURLOPT_HTTPHEADER => $headers 
                      ));

                        $response = curl_exec($curl);

                        curl_close($curl);
                        echo $response;

                    
            }

            public function getData(Request $request){ 

                  $serviceAccountFilePath = '/var/www/mobile-api/ninja11-bd832-firebase-adminsdk-6t119-b1caf1be5a.json';
                  $accessToken = $this->getAccessToken($serviceAccountFilePath); 

                  $factory = (new Factory)->withServiceAccount($serviceAccountFilePath);
                  $messaging = $factory->createMessaging(); 
             
               switch ($request->type) {
                
                case 'img2txt':

                    $img_path = $request->img_path;
                    $fullPath = base_path('../mobile-api/'.$img_path);  


                     $text = (new TesseractOCR($fullPath))->lang('eng')->run();   

                            $data1 =  $this->extractPaymentDetails($text);  
                            $data3 = $this->parseReceipt($text); 


                            dd($data1, $data3);

                    // Optional: Preprocess image for better accuracy  

                    $wt = WalletTransaction::where('payment_type',10)->get();

                    $fake_user = \DB::table('users')->where('status',0)->pluck('id')->toArray();


                  
                    foreach($wt as $key => $result)
                        { 
                           
                            $user_id = $result->user_id;

                            if(in_array( $user_id, $fake_user) || $result->amount==300 || $result->amount==19 )
                            {
                                continue;
                            }
                  
                            
                            $utr = $result->transaction_id;


                            $wallet = Wallet::where('user_id',$user_id)->where('payment_type',3)->first(); 
                         
                            $img_path = 'storage/app/public/'.$result->screenshot_path;
                            $fullPath = base_path('../mobile-api/'.$img_path);   
                            $text = (new TesseractOCR($fullPath))->lang('eng')->run();   

                            $data1 =  $this->extractPaymentDetails($text);  
                            $data3 = $this->parseReceipt($text); 
                           
                              $amount = 0;  
                              if( ($data1['utr_number'] ==  $utr || $data3['utr'] ==  $utr ) )
                                {
                                        if( ($data1['upi_id'] == '174500462835@hdfcbank' || $data3['upi_id'] == 'vyapar.174500462835@hdfebank' ) )
                                        {
                                            
                                            $amt1 = $data3['amount'];
                                            $amt2 = substr($data3['amount'],1); 

                                            if($amt1 ==$result->amount )
                                            {
                                                 $amount  = $result->amount;
                                            }
                                            elseif($amt2 ==$result->amount )
                                            {
                                                 $amount  = $result->amount;
                                            }else
                                            {
                                                if($result->amount<100)
                                                {
                                                     $amount  = $result->amount;
                                                }
                                            }

                                        }
                                }else{

                                      if( ($data1['upi_id'] == '174500462835@hdfcbank' || $data3['upi_id'] == 'vyapar.174500462835@hdfebank' ) )
                                        {
                                            
                                            $amt1 = $data3['amount'];
                                            $amt2 = substr($data3['amount'],1); 

                                            if($amt1 ==$result->amount )
                                            {
                                                 $amount  = $result->amount;
                                            }
                                            elseif($amt2 ==$result->amount )
                                            {
                                                 $amount  = $result->amount;
                                            }else
                                            {
                                                if($result->amount<100)
                                                {
                                                     $amount  = $result->amount;
                                                }
                                            }

                                        } 
                                }
                                  
 
                            $request->merge(
                                [
                                    'transaction_id' => 's'.$utr,
                                    'order_id' => $utr,
                                    'wallet_id' => $wallet->id,
                                    'amount' =>  $amount,
                                    'user_id' => $user_id ,
                                    'old_amount' => $wallet->amount??0
                                ]
                        ); 
                        
                        
                        if($amount>0)
                        {
                             $status =    $this->updateDeposit( $request); 
                        } 
                            

                    }     

                        die('success');
                    break;
                case 'getDiamond': 
                    
			         $amount = $request->amount??2100;
                     
                      $payments = DB::table('wallet_transactions as wt')
                            ->join('users as u', 'u.id', '=', 'wt.user_id')
                            ->join('bank_accounts as ba', 'ba.user_id', '=', 'wt.user_id')
                            ->where('wt.payment_type', 5)
                            ->where('wt.withdraw_status', 1)
                        ->where('wt.amount', '<',$amount)
                            ->select(
                                'u.name',
                                'u.email',
                                'u.mobile_number',
                                'ba.bank_name',
                                'ba.account_name',
                                'ba.account_number',
                                'ba.ifsc_code',
                                'ba.upi_id',
                                'wt.amount',
                                DB::raw('ROUND(wt.amount * 0.03, 2) as deduction'),
                                DB::raw('ROUND(wt.amount * 0.97, 2) as actual_amount')
                            )
                            ->get();

                        $totalAmount = 0;
                        $totalActual = 0;

                        $html = '
                        <!DOCTYPE html>
                        <html>
                        <head>
                            <title>Withdraw Payments</title>
                            <style>
                                table { border-collapse: collapse; width: 100%; font-family: Arial; }
                                th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
                                th { background-color: #f2f2f2; }
                                tr:nth-child(even) { background-color: #f9f9f9; }
                                tfoot { font-weight: bold; background: #eaeaea; }
                            </style>
                        </head>
                        <body>
                        <h2>Withdraw Payment List</h2>
                        <table>
                        <tr>
                            <th>Name/Email/Mobile</th> 
                            <th>Bank Name</th>
                            <th>Account Name</th>
                            <th>Account Number</th>
                            <th>IFSC</th>
                            <th>UPI ID</th>
                            <th>Amount</th>
                            <th>Deduction (3%)</th>
                            <th>Actual Amount</th>
                        </tr>';

                        foreach ($payments as $row) {

                            $totalAmount += $row->amount;
                            $totalActual += $row->actual_amount;

                            $html .= "<tr>
                                <td>".e($row->name).".<br>".e($row->email).".<br>".e($row->mobile_number)."</td>
                                <td>".e($row->bank_name)."</td>
                                <td>".e($row->account_name)."</td>
                                <td>".e($row->account_number)."</td>
                                <td>".e($row->ifsc_code)."</td>
                                <td>".e($row->upi_id)."</td>
                                <td>{$row->amount}</td>
                                <td>{$row->deduction}</td>
                                <td>{$row->actual_amount}</td>
                            </tr>";
                        }

                        $html .= "
                        <tr style='font-weight:bold;background:#eaeaea;'>
                            <td colspan='6'>TOTAL</td>
                            <td>".number_format($totalAmount,2)."</td>
                            <td></td>
                            <td>".number_format($totalActual,2)."</td>
                        </tr>";

                        $html .= '</table></body></html>';

                        return response($html);                 
                   

                    break;

                case 'payu':
                    $curl       =   curl_init();
                        $user_id    =   $request->uid??285;
                        $user       = User::find($user_id);
                        $amount     = $request->amount??100; 

                        if($request->amount<100)
                        {
                            $amount     = 100;
                        }else{
                            $amount     = $request->amount??100; 
                        }  
                        
                      
                        $timestamp = time(); 
                        // IST Time (UTC+5:30)
                        date_default_timezone_set('Asia/Kolkata'); 
                        // Get hour in 24-hour format
                        $current_hour = (int)date('H', $timestamp);

                         

                        $txt_id             =  'TXT'.$user_id. time();
                        $user->modeOfreach  = $txt_id;
                        $user->txn_id       =  $txt_id;
                        $user->last_amount  = $amount;
                        $user->save();


                        $posted = array(
                              'key' =>  'J2Kxj4',
                              'txnid' =>  $txt_id,
                              'amount'  =>   $amount ,
                              'firstname' =>  $user->name,
                              'email' =>  $user->email,
                              'phone' =>  $user->mobile_number,    
                              'productinfo' =>  'Consultancy Services Fees',
                              'surl'  =>  "https://pay.cashigo.info/api/v3/paymentReturnUrl?email=$user->email&txid=$txt_id",
                              'furl'  =>  'https://pay.cashigo.info/api/v3/paymentCallback',
                              'service_provider'  =>  'payu_paisa',
                        );
                        

                        return view('payu', compact('posted'));
                         
                        

                     break;  

                case 'bankCheck':


                      $rs = $this->bankCheck($request);

                      dd($rs);
  
                case 'phonepe':


                        $user_id    =   $request->uid??285;
                        $user       =   User::find($user_id);
                        $amount     =   $request->amount??100; 


                        $timestamp = time();  
                        date_default_timezone_set('Asia/Kolkata'); 
                        // Get hour in 24-hour format
                        $current_hour = (int)date('H', $timestamp);

                         

                        $txt_id             =  'TXT'.$user_id. time(); 


                        \DB::table('phonepe_logs')->insert([

                            'user_id' => $user_id,
                            'amount'  => $amount,
                            'tid'     => $txt_id ,
                            'code'    => 'PAYMENT_PENDING', 
                            'hash_key' => 'justkhelo'
                            ]);  

                        

                        $clientId       = env('CLIENT_ID'); // Replace with your Client ID
                        $clientVersion  = 1 ;           // Replace with your Client Version
                        $clientSecret   = env('CLIENT_SECRET'); // Replace with your Client Secret
                        $env            = Env::PRODUCTION;  // Use Env::PRODUCTION for live environment

                        $client = StandardCheckoutClient::getInstance(
                        $clientId,
                        $clientVersion,
                        $clientSecret,
                        $env
                        );
 
                        $amount = $amount*100;

                        $redirectUrl = "https://infowaydigital.com/api/v3/paymentReturnUrl"; // URL to which PhonePe will redirect after payment
                        $message = "IT Support!!";

                        $payRequest = StandardCheckoutPayRequestBuilder::builder()
                            ->merchantOrderId($txt_id)
                            ->amount($amount)
                            ->redirectUrl($redirectUrl)
                            ->message($message)  //Optional Message
                            ->udf1('udf1')
                            ->udf2('udf2')
                            ->udf3('udf3')
                            ->udf4
                        ('udf4
                        ')
                            ->udf5('udf5')
                            ->build();


                        $url = "";
                        try {
                            $payResponse = $client->pay($payRequest);

                            // Handle the response
                            if ($payResponse->getState() === "PENDING") {
                                // Redirect the user to the PhonePe payment page
                                $url =   $payResponse->getRedirectUrl();
                              //  header("Location: " . $payResponse->getRedirectUrl());

                                  return redirect()->away($url); 

                             exit();
                                  
                            } else {
                                // Handle the error (e.g., display an error message)
                                echo "Payment initiation failed: " . $payResponse->getState();
                            }
                        } catch (\PhonePe\common\exceptions\PhonePeException $e) {
                            // Handle exceptions (e.g., log the error)
                            echo "Error initiating payment: " . $e->getMessage();
                        } 


                case 'payinOld': 

                

  
                        //echo "processing...";

                        $curl       =   curl_init();
                        $user_id    =   $request->uid??285;
                        $user       = User::find($user_id);
                        $amount     = $request->amount??100; 

                        if($amount< 150)
                        {
                             $amount = 150; //  env('min_deposit');
                        }
                        
                     
                        $timestamp = time(); 
                        // IST Time (UTC+5:30)
                        date_default_timezone_set('Asia/Kolkata'); 
                        // Get hour in 24-hour format
                        $current_hour = (int)date('H', $timestamp);


                        $txt_id             =  str_pad(random_int(0,999999999999999), 10, '0', STR_PAD_LEFT);


                        $user->modeOfreach  = $txt_id;  


                        $source = $request->source;

                        if ($source == "jk") {
                                return [
                                    'ok' => 'success',
                                    'status' => true, 
                                    'url' => 'https://rest.justkhelo.com/payment?email=' . urlencode($user->email ?? null) . '&amount=' . $amount . '&source=' . $source, 
                                    'data' => $result ?? null
                                ];
                            } else {
                                return [
                                    'ok' => 'success',
                                    'status' => true, 
                                    'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email ?? null) . '&amount=' . $amount, 
                                    'data' => $result ?? null
                                ];
                            }


                            if ($source == "jk") {
                                return [
                                    'ok' => 'success',
                                    'status' => true, 
                                    // 'url' => 'https://pay.cashigo.info/api/v3/getData?type=payu&uid='.$user_id.'&amount='.$amount . '&source=' . $source, 
                                    'url' => 'https://infowaydigital.com/api/v3/getData?type=phonepe&uid='.$user_id.'&amount='.$amount.'&source=' . $source,
                                    'data' => $result ?? null
                                ];
                            }



                        if($amount < 100 )
                        {  
                            return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://infowaydigital.com/api/v3/getData?type=phonepe&uid='.$user_id.'&amount='.$amount, 
                                ];  
                       }

                        

                        $curl = curl_init();

                        $data = [
                            "user_name" => $user->name,
                            "mobile_number" => $user->mobile_number,
                            "amount" => $amount??100,
                            "return_url" =>  "https://infowaydigital.com/msg.html?email=$user->email&txid=$txt_id&amount=$amount&",
                            "sub_service_name" => "UPI Payin",
                            "clientReqId" =>  $txt_id
                        ];

                        $headers = [
                            "consumerSecret: ab398f4be45240bc",
                            "consumerKey: 13c479a80d559e66",
                            "partnerid: 240061",
                            "Content-Type: application/json"
                        ];
 

                        curl_setopt_array($curl, array(
                          CURLOPT_URL => 'https://api.sparkuptech.in/api/c-payin/initiate',
                          CURLOPT_RETURNTRANSFER => true,
                          CURLOPT_ENCODING => '',
                          CURLOPT_MAXREDIRS => 10,
                          CURLOPT_TIMEOUT => 0,
                          CURLOPT_FOLLOWLOCATION => true,
                          CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                          CURLOPT_CUSTOMREQUEST => 'POST',
                          CURLOPT_POSTFIELDS => json_encode($data),
                          CURLOPT_HTTPHEADER => ($headers)
                        ));

                        $response = curl_exec($curl);

                     //   dd($response );

                        if (curl_errno($curl)) {
                            echo 'Error:' . curl_error($curl);
                        }

                        
                        $result =(object)json_decode($response, true);

                        //  \DB::table('paytm')->insert(
                        //     [
                        //         'paytm'=> json_encode($request->all()),
                        //         'action' => 'payin-request-'.$user->name
                        //     ]
                        // );
                         
                       

                        if ((isset($result->success) && $result->success == false) || (!isset($result->success))) 
                        {  
                            $source = $request->source;

                            if ($source == "jk") {
                                return [
                                    'ok' => 'success',
                                    'status' => true, 
                                    'url' => 'https://rest.justkhelo.com/payment?email=' . urlencode($user->email ?? null) . '&amount=' . $amount . '&source=' . $source, 
                                    'data' => $result ?? null
                                ];
                            } else {
                                return [
                                    'ok' => 'success',
                                    'status' => true, 
                                    'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email ?? null) . '&amount=' . $amount, 
                                    'data' => $result ?? null
                                ];
                            }
                        }   else{ 

                            $txt_timestamp =  $this->getTimestampfromUrl($result->data['intentData']);   
                            $user->txt_timestamp = $txt_timestamp;   
                            
                            \DB::table('payment_logs')->insert([
                                        'txt_id'    =>      $txt_id,
                                        'order_id'  =>      $txt_timestamp,
                                        'user_id'   =>      $user_id,
                                        'amount'    =>      $amount,
                                        'content'   =>      json_encode($result)
                            
                                ]); 


                            $user->txn_id       =  $txt_id;
                            $user->last_amount  = $amount;
                            $user->save();

                            $env_payin = env('env_payin',0);

                        


                                
                                $url = $result->data['intentData'];
                                $amount = $result->data['amount'];
                                    return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' =>  $url ,
                                        'data' => $result
                                    ];
                                }
                    break;  

                case 'payin': 
  
                        $curl       =   curl_init();
                        $user_id    =   $request->uid??285;
                        $user       = User::find($user_id);
                        $amount     = $request->amount??200; 

                        if($request->amount<100)
                        {
                            $amount     = 200;
                        }  
                        
                      
                        $timestamp = time(); 
                        // IST Time (UTC+5:30)
                        date_default_timezone_set('Asia/Kolkata'); 
                        // Get hour in 24-hour format
                        $current_hour = (int)date('H', $timestamp); 

                        $txt_id             =  'TXT'.$user_id. time();
                        $user->modeOfreach  = $txt_id; 
                       
                        $source = $request->source; 
                          

 

                       
                      //  if(!$user){

                           
                            if($source=="jk")
                            {
                                return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://rest.justkhelo.com/payment?email=' . urlencode(($user->email)??NULL).'&amount='.$amount.'&source='.$source, 
                                        'data' => $result??null
                                ];
                            }else{
                                return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://rest.ninja11.in/payment?email=' . urlencode(($user->email)??NULL).'&amount='.$amount, 
                                        'data' => $result??null
                                ];
                            }
                              
                    //    } 

                       
                        $mobile = '9' . rand(100000000, 999999999);

                        curl_setopt_array($curl, array(
                            CURLOPT_URL => 'https://merchant.cgpey.com/api/v2/makepayment',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => 'POST',
                            CURLOPT_POSTFIELDS => json_encode([ 
                                "name"           => $user->name,
                                "mobile_number"  => $mobile,
                                "amount"         => $amount, 
                                "return_url"     => "https://infowaydigital.com/api/v3/paymentReturnUrl?email=$user->email&txid=$txt_id",
                                "transaction_id" => $txt_id
                            ]),
                            CURLOPT_HTTPHEADER => array(
                                'x-api-key: 109aBqkSu0eB9GcnjkIkJEkfN63CcKcuSisIVzOblII',
                                'x-secret-key: 1093T1F7LF2cjMYQBAwGRb96of2Mpa5E72Rz47QQ5MdYxNkr9hKluWewpFC1JEnPMdFZKOI0z', 
                                'ip-address: 3.7.137.238',
                                'Content-Type: application/json'
                            ),
                        ));
                        
                        $response = curl_exec($curl); 

                       

                        if (curl_errno($curl)) {
                            echo 'Curl error: ' . curl_error($curl);
                        }
                        $result =(object)json_decode($response, true);
                          

                        if(!isset($result->status) ||  $result->status==false)
                        {  
                            
                             return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email).'&amount='.$amount.'&uid='.$user_id, 
                                        'data' => $result??null
                                ];

                        }else{ 

                            $txt_timestamp =  $this->getTimestampfromUrl($result->data['intentData']);   
                            $user->txt_timestamp = $txt_timestamp;   
                            
                            \DB::table('payment_logs')->insert([
                                        'txt_id'    =>      $txt_id,
                                        'order_id'  =>      $txt_timestamp,
                                        'user_id'   =>      $user_id,
                                        'amount'    =>      $amount,
                                        'content'   =>      json_encode($result)
                            
                                ]); 


                            $user->txn_id       =  $txt_id;
                            $user->last_amount  = $amount;
                            $user->save();

                        $env_payin = env('env_payin',0);

                        if($amount>=5000 && $env_payin==1) 
                            {
                                 return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' => 'https://rest.ninja11.in/payment?email=' . urlencode($user->email).'&amount='.$amount.'&uid='.$user_id, 
                                        'data' => $result??null
                                ];
                            }
                            


                                curl_close($curl);
                                $url = $result->data['intentData'];
                                $amount = $result->data['amount'];
                                    return [
                                        'ok' => 'success',
                                        'status' => true, 
                                        'url' =>  $url ,
                                        'data' => $result
                                    ];
                                }
                    break;                        

               
                case 'transferBalance': 

            
                    $curl = curl_init();

                    curl_setopt_array($curl, array(
                      CURLOPT_URL => 'https://api.sparkuptech.in/api/fzep/payout/bankList',
                      CURLOPT_RETURNTRANSFER => true,
                      CURLOPT_ENCODING => '',
                      CURLOPT_MAXREDIRS => 10,
                      CURLOPT_TIMEOUT => 0,
                      CURLOPT_FOLLOWLOCATION => true,
                      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                      CURLOPT_CUSTOMREQUEST => 'POST',
                      CURLOPT_HTTPHEADER => array(
                        'partnerid: 240061',
                        'consumerkey: 13c479a80d559e66',
                        'consumersecret: ab398f4be45240bc'
                      ),
                    ));



                    $response = curl_exec($curl);

                   

                    curl_close($curl);

                    $json = json_decode($response, true);

                     $data = [];
                     foreach($json['data']  as $key => $value)
                     {
                         $data[$value['code']] = $value['id'];
                     } 
 
 
                    //return false;
                     $user   = User::find($request->uid); 
                    $amount = $request->amount;

                    if($amount < 500)
                    {
                         die('Minimum withdrawal 500');
                    }
  
                  //  return false;
                    $timestamp = time(); 
                    // IST Time (UTC+5:30)
                    date_default_timezone_set('Asia/Kolkata'); 
                    // Get hour in 24-hour format
                    $current_hour = (int)date('H', $timestamp);

                    // Check if time is between 10 PM (22:00) and 6 AM (06:00)
                    if($current_hour >= 22 || $current_hour < 9)
                    {
                        $charge = "0.95";
                    }
                    else
                    {
                        $charge = "0.97";  
                    }
    
   
                    $bank_accounts = \DB::table('bank_accounts')
                                        ->where('user_id', $request->uid)
                                        ->first();

                    $wt = WalletTransaction::where('user_id',$request->uid)->where('transaction_id',$request->tid)->count();
                  
                    if ($wt == 0 && !in_array($request->uid, [285, 160193])) {
                        dd("Account no found");
                    }

                     
                    $final_amount =  (int) ($amount*($charge));
                    
                 //  dd(  $final_amount );
                   // dd($current_hour,$charge,$final_amount );


                    $prefix = substr($bank_accounts->ifsc_code, 0, 4 );
                    
                    $bank_code = $data[$prefix]??0;
            

                    if($bank_accounts->account_number=="50100736173589")
                    {
                        die('not allowed');
                    }

                    $data = [
                            "AccountNo" => $bank_accounts->account_number??'5251156230',
                            "AmountR" => (int)$final_amount,
                            "APIRequestID" => $request->tid??time(), 
                            "BeneMobile" => $user->mobile_number??'8103194076',
                            "BeneName" => $bank_accounts->account_name??'sandeep kumar',
                            "bankName" =>   $bank_accounts->bank_name,
                            "IFSC" =>  $bank_accounts->ifsc_code??'KKBK0004614',
                            "SenderEmail" =>  $user->email,
                            "SenderMobile" => $user->mobile_number,
                            "SenderName" => $user->name,
                            "BankID" =>  $bank_code,
                            "paymentType" => "IMPS",
                            "WebHook" => "https://infowaydigital.com/api/v3/paymentReturnUrl",
                            "extraParam1" => "NA",
                            "extraParam2" => "NA",
                            "extraField1" => "utility",
                            "sub_service_name" => "ExpressPay",
                            "remark" => "infoway digital"
                        ]; 
 
                        
                    curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.sparkuptech.in/api/fzep/payout/expressPay2',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS =>  json_encode($data), 
                    CURLOPT_HTTPHEADER =>  [
                            "consumerSecret: ab398f4be45240bc",
                            "consumerKey: 13c479a80d559e66",
                            "partnerid: 240061",
                            "Content-Type: application/json"
                        ],
                    )); 
                    
                     $response = (object)json_decode(curl_exec($curl),true); 

                    

                    if($request->uid==160193){
                        return $response ;
                    
                    } 
                    curl_close($curl);
                    if(isset($response->success) && $response->success==true)
                    {   
                        

                        $wt = WalletTransaction::where('transaction_id',$request->tid)
                                ->where('user_id',$request->uid)
                                ->first();

                        $wt->withdraw_status = 5;
                        $wt->order_id       = $response->data['transaction_id']??$request->tid;
                        $wt->transaction_id = $response->data['transaction_id']??$request->tid;
                        $wt->save(); 

 
                        \DB::table('payouts')->updateOrInsert(
                                    // Match condition
                                    ['transaction_id' => $request->tid],

                                    // Values to update/insert
                                    [
                                        'user_id'       => $request->uid,
                                        'upi_id'        => $request->upi,
                                        'name'          => $user->name,
                                        'amount'        => $request->amount,
                                        'order_id'      => $response->data['transaction_id'],
                                        'payout_type'   => 'bank',
                                        'status'        => 2,
                                        'message'       => $response->message
                                    ]
                                );

                        return $response->message;

                    }else{ 

                        \DB::table('payouts')->updateOrInsert(
                            // Match condition
                            ['transaction_id' => $request->tid],

                            // Values to update/insert
                            [
                                'user_id'       => $request->uid,
                                'upi_id'        => $request->upi,
                                'name'          => $user->name,
                                'amount'        => $request->amount,
                                'order_id'      => time(),
                                'payout_type'   => 'bank',
                                'status'        => 1,
                                'message'       => $response->message
                            ]
                        );
                        return $response->message;
                    }

                    break;

                case 'withdrawalInfo':
                    return false; 
                    $timestamp = time(); 
                        // IST Time (UTC+5:30)
                    date_default_timezone_set('Asia/Kolkata'); 
                    $current_hour = (int)date('H', $timestamp);

                        // Check if time is between 10 PM (22:00) and 6 AM (06:00)
                    if($current_hour >= 22 || $current_hour < 7)
                    {
                        return $current_hour;
                    }else{

                        $enable_payout = env('enable_payout',2);
                        if($enable_payout == 2)
                            {
                                  return false;
                            }

                      
                    }
                    $wt = WalletTransaction::where('payment_type', 5)->where('withdraw_status', 1)->get();
                    
                    foreach($wt as $key => $request)
                    {
                       if($request->amount>1000)
                        {
                            continue;
                        }
                        
                        // Get hour in 24-hour format 

                        $user = User::find($request->user_id); 
                        $amount = $request->amount;

                        $bank_accounts = \DB::table('bank_accounts')
                                            ->where('user_id', $request->user_id)
                                            ->first();

                        $wt = WalletTransaction::where('user_id',$request->user_id)->where('transaction_id',$request->user_id)->count();
                    
                    //  dd($wt,$user,$request->all());
                        if((!isset($bank_accounts->account_number) || $user==null || $wt==0) && ($user->id!=285 || $user->id!=161384))
                        {
                            
                            die('something went worng'); 
                        } 

                            $charge = "0.97";

                            if($amount <= 5000 ){
                              //  $charge = "0.95";
                            }  
                            $final_amount = (int) $amount*($charge);
                        
                       

                            $curl = curl_init(); 
                            $data = [
                                "transaction_id"    => "payout".$request->transaction_id,
                                "amount"            => (int) $final_amount,
                                "creditorAccountNo" => $bank_accounts->account_number,
                                "creditorIFSC"      => $bank_accounts->ifsc_code,
                                "creditorName"      => $bank_accounts->account_name,
                                "creditorEmail"     => $user->email,
                                "creditorMobile"    => $user->mobile_number,
                                "paymentType"       => "IMPS",
                                "address"          => [
                                                    "address_1" => "Kanpur Uttar Pradesh",
                                                    "address_2" => $user->city??'delhi',
                                                    "state"     => $user->city??'delhi',
                                                    "city"      => $user->city??'delhi',
                                                    "pin"       => "100001"
                                                ]
                            ];

                        
                                
                            curl_setopt_array($curl, array(
                            CURLOPT_URL => 'https://corpelastic.cgpey.com/api/v1/payout/payments',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_ENCODING => '',
                            CURLOPT_MAXREDIRS => 10,
                            CURLOPT_TIMEOUT => 0,
                            CURLOPT_FOLLOWLOCATION => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CUSTOMREQUEST => 'POST',
                            CURLOPT_POSTFIELDS =>  json_encode($data),
                            CURLOPT_HTTPHEADER => array(
                                 'x-api-key: 194Bj09do0HlnTgVNXdR4tkvFUIyquES4r7PqNuuzQs',
                                'x-secret-key: 194dXH5cIzr3VbkeQYAgyyLDxHqokmKu7XQkuE0WZYZz69N18DEYn4XXyCEQWv0mxch4LLFZR',
                                'x-owner-name: Kroyspark',
                                'Content-Type: application/json'
                                ),
                            ));

                            
                            $response = (object)json_decode(curl_exec($curl),true);
                        // dd( $response);
                            curl_close($curl);
                            if($response->status)
                            {   

                                $wt = WalletTransaction::where('transaction_id',$request->transaction_id)
                                        ->where('user_id',$request->user_id)
                                        ->first();

                                $wt->withdraw_status = 5;
                                $wt->order_id = $response->exch_transaction_id??$request->transaction_id;
                                $wt->save(); 

        
                                \DB::table('payouts')->updateOrInsert(
                                            // Match condition
                                            ['transaction_id' => $request->transaction_id],

                                            // Values to update/insert
                                            [
                                                'user_id'       => $request->user_id, 
                                                'name'          => $user->name,
                                                'amount'        => $request->amount,
                                                'order_id'      => $response->exch_transaction_id,
                                                'payout_type'   => 'bank',
                                                'status'        => 2,
                                                'message'       => $response->message
                                            ]
                                        );

                             //   return $response->message;

                            }else{ 

                                \DB::table('payouts')->updateOrInsert(
                                    // Match condition
                                    ['transaction_id' => $request->transaction_id],

                                    // Values to update/insert
                                    [
                                        'user_id'       => $request->user_id, 
                                        'name'          => $user->name,
                                        'amount'        => $request->amount,
                                        'order_id'      => time(),
                                        'payout_type'   => 'bank',
                                        'status'        => 1,
                                        'message'       => $response->message
                                    ]
                                );
                              //  return $response->message;
                            }
                    }

                    die('payment sent');

                    break;

                case 'getPayoutInfo':
                    $payout = \DB::table('payouts')->get();
                     
                    foreach($payout as $key => $result)
                    {
                        $request->merge([
                            'transaction_id'    =>   $result->order_id,
                            'type'              =>  'transactionStatusById'
                        ]);

                        $data = $this->getData($request);  

                      
                        if($data->message=='success')
                        {
                            $exch_transaction_id = $data->data['exch_transaction_id'];
                           
                            \DB::table('payouts')->where('order_id',$exch_transaction_id)->delete();
                        }
                        elseif($data->message=='failed')
                        {  
                            $exch_transaction_id = $data->data['exch_transaction_id'];
                          
                            $wt = WalletTransaction::where('transaction_id',$result->transaction_id)->first();
                            if($wt->order_id==$exch_transaction_id)
                            {
                                \DB::table('payouts')->where('order_id',$exch_transaction_id)->delete();
                            }else{
                                $wt->withdraw_status = 1;
                                $wt->order_id = $exch_transaction_id; 
                                $wt->save();
                                \DB::table('payouts')->where('order_id',$exch_transaction_id)->delete();
                            } 
                            
                        }
                    }
                    return 'withdraw status updated';
                    break;

                //https://infowaydigital.com/api/v3/getData?type=transactionStatusById&transaction_id=
                case 'transactionStatusById':  

                     $data = [
                            "transaction_id"    => $request->transaction_id,
                            
                        ];


                        $headers = [
                            "consumerSecret: ab398f4be45240bc",
                            "consumerKey: 13c479a80d559e66",
                            "partnerid: 240061",
                            "Content-Type: application/json"
                        ];

                    $curl = curl_init(); 
                   

                    curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://api.sparkuptech.in/api/c-payin/checkStatus',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST',
                    CURLOPT_POSTFIELDS =>  json_encode($data),
                    CURLOPT_HTTPHEADER => $headers,
                    ));

                    

                    
                    curl_close($curl);

                    $response = (object)json_decode(curl_exec($curl),true);
                    return  $response;


                    break;

                case 'getBalance':

                    $curl = curl_init(); 
                    $data = [
                        "transaction_id"    => $request->transaction_id,
                         
                    ];

                    curl_setopt_array($curl, array(
                    CURLOPT_URL => 'https://corpelastic.cgpey.com/api/v1/payout/getBalance',
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_ENCODING => '',
                    CURLOPT_MAXREDIRS => 10,
                    CURLOPT_TIMEOUT => 0,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                    CURLOPT_CUSTOMREQUEST => 'POST', 
                    CURLOPT_POSTFIELDS =>  json_encode($data),
                    CURLOPT_HTTPHEADER => array(
                        'x-api-key: 168RQ4iSAnE6AEtBZGTjV2wFECMQSJoOxxZCaOuMBcX',
                        'x-secret-key: 16863TRnV7nqgxKv0GNxcKq9dz0xKu9wCpP9asYaY2jRdCCNdZrGomuGnlNEuEr1vRs3s0P4S',
                        'x-owner-name: kroy',
                        'Content-Type: application/json'
                        ),
                    ));

 

                    $response = curl_exec($curl);


                    curl_close($curl);
                    $response = (object)json_decode(curl_exec($curl),true);
                    
                    $amt = 0; //$response->wallet['amount']??0; 

                    
                    return  $amt;

                    break;
                case 'delete_contest':
                     $delete_conetst =  \DB::table('create_contests')
                                        ->where('filled_spot', 0)
                                                ->where('created_at', '<', \Carbon\Carbon::now()->subDays(3)) 
                                                ->delete();

                                    return $delete_conetst;
                    break;
                
                case 'paymentinlast30min':

                    $transactions = WalletTransaction::whereBetween('created_at', [
                            Carbon::now()->subMinutes(60),
                            Carbon::now()
                        ])
                        ->where('payment_type', 10)
                        ->get();

                    $topic = 'paymentInfo'; // ✅ same topic as subscription 

                    $title = "Total number of payment requested in last 60 min =>" . $transactions->count();
                    $body_msg = "Total Pending Amount in last 60 min =>" . $transactions->sum('amount');
                
                   return  $this->updateNotification($topic, $title ,$body_msg, $accessToken );

                    break;
                case 'paymentInfo':
                    // Get tokens (unique, non-empty)
                  $tokens = User::whereIn('id', [285,160165,162808])
                    ->whereNotNull('device_id')
                    ->pluck('device_id')
                    ->filter()   // remove empty/null
                    ->unique()   // keep unique only
                    ->values()
                    ->toArray();

                // Subscribe tokens to topic
                    $topic = "paymentInfo"; 
                    $batchSize = 100; // Firebase max
                    $chunks = collect($tokens)->chunk($batchSize);

                    foreach ($chunks as $chunk) {
                        $messaging->subscribeToTopic($topic, $chunk->all());
                    }
    
                    $topic      = 'paymentInfo'; // ✅ same topic as subscription

                    $manual_pay = WalletTransaction::where('payment_type', 10)->count();
                    $manual_pay_sum = WalletTransaction::where('payment_type', 10)->sum('amount'); 

                    if($manual_pay==30)
                    {
                        return false;
                    }
                    $title = "Total number of payment request :".$manual_pay;
                    $body_msg = "Total Pending Amount  as per Dashboard is :".$manual_pay_sum;
                    if($manual_pay>0)
                    {
                        return $this->updateNotification($topic, $title ,$body_msg, $accessToken );
                    }
                  

                    break;
                case 'notifyAllUser':

                    $topic      = 'notifyAllUser'; // ✅ same topic as subscription  
                    $title      = "🏆 IND vs AUS 🏏";
                    $body_msg   = "Dear User, Create and Join your team!!."; 
                
                    $msg = $this->updateNotification($topic, $title ,$body_msg, $accessToken );
                    return  $msg;
                    break;

                case 'lineupInfo':


                    $match = Matches::whereIn('status',[3])
                            ->whereDate('date_start',\Carbon\Carbon::today())
                            ->where('timestamp_start','>=',time())
                          //  ->where('series_cancel','!=',1)
                            ->first(); 

                    if($match==null)
                    {
                        return false;
                    }

                    $t1 = $match->manual_date??$match->timestamp_start;
                    $t2 = time();
                    $td = round((($t1 - $t2)/60),2);
                      $topic      = 'notifyAllUser'; // ✅ same topic as subscription  
                    if($td<30)
                    {
                        $lineup = $match->is_lineup;
                        if($lineup==1)
                            {
                                $title = " 🏏 $match->short_title";
                                $body_msg = "Dear User, Create and join your team, before it's filled.🏆"; 
                                  $this->updateNotification($topic, $title ,$body_msg, $accessToken );
                            } 
                            
                        elseif($lineup==3   )
                            {
                                $title = "Lineup Out  🏏 $match->short_title";
                                $body_msg = "Dear User, Create and join your team, before it's filled.🏆"; 
                                  $this->updateNotification($topic, $title ,$body_msg, $accessToken );
                            } 
                    }
                
                    return 'lineupinfo';
                    break;
                case 'update_user_device':
                     $tokens = User::where('updated_at','>', '2025-09-01')
                    ->whereNotNull('device_id')
                    ->pluck('device_id')
                    ->filter()   // remove empty/null
                    ->unique()   // keep unique only
                    ->values()
                    ->toArray();
                        
                    // Subscribe tokens to topic
                    $topic = "notifyAllUser"; 
                    $batchSize = 999; // Firebase max
                    $chunks = collect($tokens)->chunk($batchSize);

                    foreach ($chunks as $chunk) {
                        $messaging->subscribeToTopic($topic, $chunk->all());
                    }
                    break;
                default:
                    return 'get data';
                    break;
                }

                
                die('test'); 


                    if($request->method()=="POST")
                {
                        $request->merge(['order'=>$request->ORDERID]);
                        $data = $this->statusCheck($request);
                    
                        if($data['STATUS']=='TXN_SUCCESS')
                        {
                

                $request->merge(
                    [
                        'order_id'=>$request->ORDERID,
                        'user_id' =>$request->user_id,
                        'deposit_amount' => $request->TXNAMOUNT,
                        'payment_mode' => 'paytm',
                        'transaction_id' => $request->TXNID
                    ]);
                 
                
                \DB::table('paytm_logs')->updateOrInsert(
                    [
                        'user_id' => $request->user_id,
                        'order_id' => $request->ORDERID
                    ],
                    [
                        'user_id' => $request->user_id,
                        'status' => 2,
                        'code'   => $request->referal_code,
                        'tid'    => $request->TXNID,
                        'order_id' => $request->ORDERID,
                        'amount' => $request->TXNAMOUNT,
                        'responseCode' => $request->STATUS,
                        'data'  => json_encode($request->all())
                    ]
                );

                echo '<b>Transaction is successful and your order id is '.$request->ORDERID.'</b>';
                die("<br>Thank you.!!");

                }else{

                \DB::table('paytm_logs')->updateOrInsert(
                    [
                        'user_id' => $request->user_id,
                        'order_id' => $request->ORDERID
                    ],
                    [
                        'user_id' => $request->user_id,
                        'status' => 1,
                        'code'   => $request->referal_code,
                        'tid'    => $request->TXNID,
                        'order_id' => $request->ORDERID,
                        'amount' => $request->TXNAMOUNT,
                        'responseCode' => $request->STATUS,
                        'data'  => json_encode($request->all())
                    ]
                );
                echo "<b>Transaction is failed :".$request->STATUS.'</br>';
                die("<br>Thank you. Try Again!!");
            }
            
                }else{
                    echo "Transaction is failed";
                    die("<br>Thank you. Try Again!!");
                }
                    die();
         

                $match_id = 65203;
                $contest = CreateContest::with('contestType')
                    ->select(
                        'contest_type',
                        'contest_type as contest_type_id',
                        'is_cancelled as isCancelled',
                        'usable_bonus',
                        'bonus_contest',
                        'gift_url',
                        'total_spots as totalSpots', 
                        'first_prize as firstPrice',
                        'sort_by',
                        'total_winning_prize as totalWinningPrize',
                        'prize_percentage as winnerCount',
                        'gift_url',
                        'entry_fees as entryFees',
                        'id as contestId',
                        'filled_spot as filledSpots',
                        'winner_percentage as winnerPercentage',
                        'cancellation',
                        'contest_category_type',
                        'discounted_price',
                        'extra_cash_usable',
                        'offer_end_at',
                        'usable_extra_cash'
                    )
                    ->where('match_id',$match_id)
                    ->where('is_cancelled',0)
                    ->orderBy('sort_by','asc')
                    ->orderBy('entry_fees','DESC')
                 //   ->whereIn('contest_type',$ct)
                    ->orderBy('total_winning_prize','DESC')
                    ->get();

                    $contest_data_arr = [];
                    foreach($contest as $key => $result)
                    {
                      //  $contest_data = [];
                      //  dd($result->contestType->id);

                        $contest_data = [

                            'contest_type_id' =>   $result->contest_type_id,
                            'contestTitle' => $result->contestType->contest_type,
                            'title' => $result->contestType->contest_type,
                            'icon_url' => $result->contestType->emoji_url,
                            'contestSubTitle' =>  $result->contestType->description,
                            'contests' => $result
                        ];
                        unset($result->contestType);

                        $contest_data_arr[] = $contest_data;

                        
                    }


                    $array = [

                        'session_expired' => true,
                        'system_time' => time(),
                        'match_status' => "Upcoming",
                        'match_time' => time(),
                        'status' => true,
                        'code' => 200,
                        'message' => 'success',
                        'response' => [
                                    'matchcontests' => $contest_data_arr,
                                    'myjoinedTeams' =>  [],
                                    'myjoinedContest' => []
                        ]

                    ];


                return $array;
                
                $cid = [127336];

                \DB::table('matches')->whereIn('competition_id',$cid)->update(['series_cancel'=>2]);

                //series_cancel

            //  return $_SERVER;


      //$d=  $this->cricketAPICall("http://rest.krsdata.net/api/v2/getData?token=t96e3640f28aa327705fa720f8c231ad8t");
      //  dd($d);
     //   $this->returnTeamId();

     //$data = \DB::table('wallet_transactions')->where('payment_type',6)->pluck('user_id')->toArray();
     
        //$uid = User::where('customer_type',3)->pluck('id');
        // $date = Carbon::now()->subDays(90);
        // $all_user = WalletTransaction::select('user_id')
        //         ->whereIn('payment_type',[3]) 
        //         ->where('amount','>=',50)
        //         ->where('created_at', '>=', $date)
        //         ->pluck('user_id')
        //         ->unique();  
        
         
                //$date = Carbon::now()->subDays(365);
                // $all_user = WalletTransaction::select('user_id')
                //        // ->where('amount','>=',50)
                //         ->where('payment_type',3)
                //         ->whereNotIn('user_id',$all_user1) 
                //       //  ->where('created_at', '>=', $date)
                //         ->pluck('user_id')
                //         ->unique();  
                            


                
      //  $u = User::select('mobile_number')->whereIn('id',$all_user)->pluck('mobile_number');

        //        return $u;
//


       // die('========================');
        //  get pass code
        $user_id = $request->user_id;

        $free_entry = \DB::table('free_entries')
                    ->where('user_id',$user_id)
                    ->count();

        if($user_id==285){
            $free_entry = \DB::table('prize_distributions')
                    ->where('user_id',$user_id)
                    ->where('filled_spot',1)
                    ->count();
        }
        
        $offer_banners = \DB::table('offer_banners')
                        ->select('url')
                        ->orderBy('id','desc')
                        ->get();
        
        $html   = '<table><tr><td style="
        background: forestgreen;
        color: white;
        font-family: system-ui;padding:5px;
             ">More offer available for you!!👇👇</td></tr>';
        
        foreach($offer_banners  as $key => $result)
        {
            $html .= '<tr><td ><img src="'.$result->url.'" width="100%"></td><tr>';
        }

        $html .= "</html>";

        $str = "Total Available GL Entry Passes: <b>".$free_entry.'</b>';
        echo '<table width="100%"><tr><td style="padding:5px;background: darkorchid;border-radius: 5px;color: white;font-size: large;">'.$str.'</td><tr></table><br>'.$html; die;

        die();
        $match_id = 55982; 

        $b = \DB::table('banners')->get();
 



       

        $match = Matches::where('status',3)->select('match_id')->get()->toArray();    

        $cc = CreateContest::whereIn('match_id',$match)
                    ->where('total_spots',2)
                    ->where('filled_spot',2)->pluck('id');
        
        $jc = JoinContest::whereIn('contest_id',$cc)->where('entry_fees',0)->get()->groupBy('contest_id');
        
        foreach($jc as $key => $rs)
        {
            if($rs->count()==2)
            {   $ccc =  CreateContest::find($key);
                $ccc->is_cancelled = 1;
                $ccc->save();
                
                foreach($rs as $key => $jcObj)
                {
                    $jcO = JoinContest::find($jcObj->id);
                    $jcO->cancel_contest = 1;
                    $jcO->save();

                }
            }
        }

        dd('-done-rest');



        /*  delete teams
        $created_team_id= JoinContest::whereMonth('created_at','03')->pluck('created_team_id');

        $tms = CreateTeam::whereMonth('created_at','03')->whereIn('created_team_id',$created_team_id)->count();

        dd($tms);*/

        Matches::where('match_id',$match_id)->delete();
        CreateContest::where('match_id',$match_id)->delete();
        CreateTeam::where('match_id',$match_id)->delete();      
        JoinContest::where('match_id',$match_id)->delete();      
        Player::where('match_id',$match_id)->delete();
        MatchPoint::where('match_id',$match_id)->delete(); 
        TeamA::where('match_id',$match_id)->delete(); 
        TeamB::where('match_id',$match_id)->delete(); 
        TeamASquad::where('match_id',$match_id)->delete(); 
        TeamBSquad::where('match_id',$match_id)->delete(); 
        WalletTransaction::where('match_id',$match_id)->delete(); 
            

        $this->deleteUnusedData();
        die('---------');

        $live_match = Matches::whereIn('status',[3])
                   // ->where('timestamp_start','>=' ,time())
                    ->get();
                  
        foreach($live_match as $key => $match)
        {
            $match_id = $match->match_id;

            $cc = CreateContest::where('match_id', $match_id)
                        ->where('filled_spot','>=',2)
                        ->pluck('id');

            $jc = JoinContest::whereIn('contest_id',$cc)
                                ->where('match_id',$match_id)
                                ->where('entry_fees',0)
                                ->get()
                                ->groupBy('contest_id');
            
            foreach($jc as $key => $value)
            {
                $c = CreateContest::find($key);
                $c->is_reversed = 1;
                $c->save();
            }
        }
        die('--done--183-api');

        die('--rest--');
        /*DELETE FROM `create_teams` WHERE  id IN(SELECT created_team_id FROM `join_contests` WHERE contest_id IN(SELECT id FROM `create_contests` WHERE `usable_bonus` = 100 AND created_at < "2021-07-01") and winning_amount=0 and created_at < "2021-07-01"
            );*/


       

        $date = '2021-12-01';
        $create_contests    = CreateContest::where('bonus_contest',1)
                            ->whereDate('created_at','<',$date)
                            ->pluck('id');

        
        $join_contest = JoinContest::whereIn('contest_id',$create_contests)->select('created_at','contest_id','created_team_id')->whereDate('created_at','<',$date)
        ->limit(5000)
        ->pluck('created_team_id');

        $join_contest_id = JoinContest::whereIn('contest_id',$create_contests)->select('id','created_at','contest_id','created_team_id')->whereDate('created_at','<',$date)
        ->limit(5000)
        ->pluck('id');

        $cc = CreateTeam::whereIn('id',$join_contest)->get();

        if($cc->count())
        {   
             foreach($cc as $key => $result)
            {  
               $ccc = CreateTeam::find($result->id)->delete(); 
            }
            dd('ct'.$ccc);
        }else{
            foreach($join_contest_id as $key => $result)
            { 
               $join_contest = JoinContest::find($result)->delete(); 
            }
          //  dd('jc'.$join_contest);
 
        }
        

        die('---------no team found-------------');
        $w = \DB::table('wallets')
                ->select('user_id', \DB::raw('count(user_id) as total_wall'))
                ->orderBy('user_id','desc')
                ->groupBy('user_id')
                ->havingRaw('total_wall=1')
                ->limit(500)
                ->get();

        $u = [];            
        foreach($w as $key =>$value)
        {
            $wc = Wallet::where('user_id',$value->user_id)
                    ->count();
            $user_id = $value->user_id; 
            
            if($wc==1)
            {   $u[] = $user_id;

                $wallet = new Wallet;
                $wallet->user_id = $user_id;
                $wallet->validate_user = Hash::make($user_id);
                $wallet->payment_type  =  3;
                $wallet->payment_type_string = "Deposit";
                $wallet->amount         = 0;
                $wallet->save();

            }

        }

        dd($w->count(),$u);


        die('--');
        $contest_id = 377847;
        $match_id   = 498310000;
        $contestId =$request->contestId;
        if($contestId)
        {

        }else{
            die('contest_id_missing');
        }

        $jc = JoinContest::where('match_id',$match_id)
                            ->where('contest_id',$contest_id)
                            ->where('entry_fees',"0.00")
                            ->limit(100)
                            ->groupBy('user_id') 
                            ->pluck('user_id');
                            
        $users = User::whereIn('id',$jc)->where('customer_type',3)->get();
        
        $users_id = $users->pluck('id')->toArray();
        

        $jc2 = JoinContest::where('contest_id',$contestId) 
                            ->where('entry_fees',"0.00")
                            ->whereNull('last_seen')
                            ->whereNotIn('user_id', $jc) 
                            ->limit(count($users_id))
                            ->get();
         

        foreach($jc2 as $key => $jcu)
        {  

            if(isset($users_id[$key]))
            {
                $u = User::find($users_id[$key]);

                $team_name =  $u->team_name;
                $user_name =  $u->name;
                if($u->team_name==null)
                {
                   $name  = explode(" ", $u->name);
                   $team_name = $name[0];
                }
                
                $jcu->user_name = $user_name;
                $jcu->team_name = $team_name;
                $jcu->user_id   = $u->id;

                $ct =CreateTeam::find($jcu->created_team_id);
                $ct->user_id = $u->id;

                $jcu->save();
                $ct->save();              
            }else{
                continue;
            }
        }

        die('done');  
    }
    // My Report
    public function myReport(Request $request)
    {   
        $lu = \DB::table('live_users' )
                ->whereRaw('created_at >= now() - interval 1 minute')
                ->count();

           return $lu;    
    }

    public function updateLeaderboardUser(Request $request)
    { 
        $contest_id = 514228;
        $match_id   = 87709;

        $contestId = $request->contest_id;
        
        if($contestId && $match_id)
        {

        }else{
            die('match_contest_id_missing');
        }

        $jc = JoinContest::where('match_id',$match_id)
                            ->where('contest_id',$contest_id)
                            ->where('entry_fees',"0.00")
                            ->limit(300)
                            ->groupBy('user_id') 
                            ->pluck('user_id');

        //dd($jc,$contest_id,$match_id);

        $users = User::whereIn('id',$jc)->where('customer_type',3)->get();
        
        $users_id = $users->pluck('id')->toArray();
        
        

        $jc2 = JoinContest::where('contest_id',$contestId) 
                            ->where('entry_fees',"0.00")
                            ->whereNull('last_seen')
                            ->whereNotIn('user_id', $jc) 
                            ->limit(count($users_id))
                            ->get();
         

        foreach($jc2 as $key => $jcu)
        {  

            if(isset($users_id[$key]))
            {
                $u = User::find($users_id[$key]);

                $team_name =  $u->team_name;
                $user_name =  $u->name;
                if($u->team_name==null)
                {
                   $name  = explode(" ", $u->name);
                   $team_name = $name[0];
                }
                
                $jcu->user_name = $user_name;
                $jcu->team_name = $team_name;
                $jcu->user_id   = $u->id;

                $ct =CreateTeam::find($jcu->created_team_id);
                $ct->user_id = $u->id;
                $jcu->save();
                $ct->save();              
            }else{
                continue;
            }
        }

        die('done');  
    }
 
    public function create11Team(Request $request)
    {
        
    }

    public function validateUPI(Request $request)
    {
        $curl = curl_init();
        $upi = $request->upi;
        $post = ['vpa' => $upi];         

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://kepler.haodapayments.com/api/v1/upi/validate',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode($post) ,
            CURLOPT_HTTPHEADER => array(
                'x-client-id: '.env('x_client_id','rPYuGfRvxK2410'),
                'x-client-secret: '.env('x_client_secret','VqvzftjPp1231006051235'),
                'Content-Type: application/json'
            ),
        ));
        
        $response = curl_exec($curl);        
        curl_close($curl);
        //$result     =    (object)json_decode($response,true);

        $result     =    json_decode($response);
        return $result;
        
    }


    public function releasePayout(Request $request)
    {
        $data = \DB::table('paytm_payouts')
                ->where('status',2)
                ->whereIn('payout_type',['paytm','UPI'])
                ->get();

            
       // $msg = "";
        foreach($data as $key => $result)
        {

            if($result->amount>501)
            {
                $title = "Withdrawl is Pending";
                $msg_s =  "Hello Team, Withdrawl is pending of INR $result->amount ";
                $this->remindAdmin($title,$msg_s,285);

                continue;
                
            }

            $amt = $result->amount*(0.02);
            if($amt<10)
            {
                $amt = 10;
            }

            $amount = (int)($result->amount)-$amt;

            $request->merge([
                    'upi'   => $result->upi_id,
                    'user_id'   => $result->user_id,
                    'amount'    => $amount,
                    'transaction_id' => $result->transaction_id
                ]
            );

           $res =  $this->initiateUPI($request);
           $msg = $res->message??'Something went wrong';
           if(isset($res->status_code) && $res->status_code==200){

                \DB::table('paytm_payouts')->where('id',$result->id)->update(['status'=>3]);

                $title = $msg;
                $msg_s =  "Your withdrawal is sent to ".$result->upi_id. ' .Kindly Check your bank account!!';
                $user_id = $result->user_id;

                $this->remindAdmin($title,$msg_s,$user_id);
                
           }else{

                $date = date('i');
                if($date%15!=0)
                {
                    die();
                }

                $title = $msg;
                $msg_s =  "Hello Team, Payout account has low balance, Inform Kroy to recharge";
                $this->remindAdmin($title,$msg_s);
                
           }
           
        }

        if($data->count()==0)
        {   
            echo  "No withdrawal Pending!!";

        }
    }
    

    public function remindAdmin($title=null,$msg=null,$user_id=285)
    {  

        $result = User::find($user_id);
                
        $notification = [ 
            'title' => $title ,
            'message' =>  $msg
        ];
        $token = $result->device_id;
        $this->sendNotificationAndroid($notification, $token);
    }

    // Initiate UPI
    public function initiateUPI(Request $request)
    {

        
        $request->merge(['upi' => $request->upi]);
       
        $validateUPI = $this->validateUPI($request);
        
        if($validateUPI==""){
            die('Invalid UPI');
        }

        $upi = $request->upi;

        $post = [
                'vpa'               =>  $request->upi,
                'beneficiary_name'  =>  $validateUPI->customerName,
                'amount'            =>  $request->amount,
                'narration'         =>  "Payment Released",
                'reference'         =>  'S11_'.time()
            ];

     

        $headers = [
            'x-client-id: '.env('x_client_id','rPYuGfRvxK2410'),
            'x-client-secret: '.env('x_client_secret','VqvzftjPp1231006051235'),
                'Content-Type' => 'application/json'
                ];

        $curl = curl_init();
        curl_setopt_array($curl, array(
        CURLOPT_URL => 'https://kepler.haodapayments.com/api/v1/upi/payout/initiate',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 0,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => 'POST',
        CURLOPT_POSTFIELDS => json_encode( $post),
        CURLOPT_HTTPHEADER => array(
            'x-client-id: '.env('x_client_id','rPYuGfRvxK2410'),
            'x-client-secret: '.env('x_client_secret','VqvzftjPp1231006051235'),
            'Content-Type: application/json'
        ),
        ));

        $response = (object)json_decode(curl_exec($curl),true);
       // dd( $response);
        curl_close($curl);
        if($response->status_code==200)
        {
            echo $response->message;

            $wt = WalletTransaction::where('transaction_id',$request->transaction_id)
                    ->where('user_id',$request->user_id)
                    ->first();

            $wt->withdraw_status = 5;
            $wt->save();


            \DB::table('payouts')->insert(
                [
                    'user_id'       =>  $request->user_id,
                    'upi_id'        =>  $request->upi,
                    'name'          =>  $validateUPI->customerName,
                    'amount'        =>  $request->amount,
                    'order_id'      =>  time(),
                    'transaction_id' => $request->transaction_id,
                    'payout_type'   => 'UPI',
                    'status'        =>  2
                ]
            );

            return $response;

        }else{
            echo $response->message;

            \DB::table('payouts')->insert(
                [
                    'user_id'       =>  $request->user_id,
                    'upi_id'        =>  $request->upi,
                    'name'          =>  $validateUPI->customerName,
                    'amount'        =>  $request->amount,
                    'order_id'      =>  time(),
                    'transaction_id' => $request->transaction_id,
                    'payout_type'   => 'UPI',
                    'status'        =>  1,
                    'message'       =>  $response->message
                ]
            );
            return $response;
        }
    }
    // Validate Payout
    public function validatePayout(Request $request)
    {

         dd('test');

         
        $client = new \GuzzleHttp\Client();
        
        $headers = [
        'x-client-id: '.env('x_client_id','rPYuGfRvxK2410'),
        'x-client-secret: '.env('x_client_secret','VqvzftjPp1231006051235'),
        'Content-Type' => 'application/json'
        ];
        
        $body = '{
            "payout_id": "HOAD650297148300"
        }';


        $request = $client->request('POST', 'https://kepler.haodapayments.com/api/v1/payout/checkstatus', $headers, $body);
        $res = $client->sendAsync($request)->wait();
        echo $res->getBody();
    }
    public function autoSetOrderBy(Request $request)
    { 
    


        $match_id = 95606;


        $wt = WalletTransaction::where('match_id',$match_id)->where('payment_type',9)->get();
//dd($wt);
         foreach($wt  as $key => $value)
         {

            dd($value);
         }






	 $mobileNumbers = DB::table('users as u')
            ->select('u.mobile_number')
            ->where('u.customer_type', 0)
            ->whereIn('u.id', function ($query) {
                $query->select('user_id')
                    ->from('wallet_transactions')
                    ->where('payment_type', 6)
                    ->groupBy('user_id')
                    ->havingRaw('MAX(created_at) < ?', [now()->subDays(3)]);
            })
            ->orderBy('u.id', 'asc')
            ->pluck('mobile_number');

            return $mobileNumbers;



         $data = \DB::table('contest_amount_deductions')
                                ->where('match_id',91557)
                                ->where('revert',1)
                                ->get()->groupBy('user_id');

                $response = $data->transform(function ($item, $key)   { 

                  

                    $wallet = Wallet::where('user_id',$key)->whereIn('payment_type',[3,4])->get();

                    $deposit    = Wallet::where('user_id',$key)->where('payment_type',3)->first();

                    $winning    = Wallet::where('user_id',$key)->where('payment_type',4)->first();

                   // dd($winning,$key);

                    $record = DB::table('contest_amount_deductions')
                                ->where('user_id', $key)
                                ->where('match_id', 91557)
                                ->orderByDesc('actual_amount')
                                ->first(); 
                   


                    if( $deposit->amount > $record->in_deposit)
                        {

                             $deposit->amount = $record->in_deposit;
                             $deposit->save();
                        }
                    

                    if( $winning->amount >$record->in_winning)
                        {
                                $winning->amount = $record->in_winning;
                                $winning->save();  
                        }

                       

                    \DB::table('contest_amount_deductions')
                                    ->where('match_id',94709)
                                    ->where('user_id', $key)
                                    ->update([
                                        'revert'=>2
                                    ]);


                                    $array = [

                                        'uid' => $key,
                                        'deposit_amount' => $wallet[0]->amount??0,
                                        'winning' => $wallet[1]->amount??0,
                                        'record' => $record

                                    ];

                   // dd( $array);


                });


        dd('----');


   



        return false;
        $competition_id = ['129650'];
        $is_dashboard = 2;
        $dfc_ids = [280,27811,23880];

        $currentDay = \Carbon\Carbon::now()->format('l');

        // if($currentDay=='Saturday' || $currentDay=='Saturday')
        // {
        //     $dfc_ids = [32230,32440,23880];
        // }
        

        $match = Matches::whereIn('competition_id',$competition_id)
                            ->where('status',1)
                            ->where('timestamp_start','>',time())
                            ->get();

        foreach($match as $key => $value)
        {
            $updateMatch                    = Matches::find($value->id);
            $updateMatch->order_by          = 2;
            $updateMatch->is_free           = 1;
            $updateMatch->dyanamic_message  = "Mega Available";
          //  $updateMatch->is_dashboard      =  $is_dashboard;
           // $updateMatch->notification      = "Deposit 999 and get 1111. Hurry up!!";

            $updateMatch->save();


            // foreach($dfc_ids as $dfc_id){

            //     $contest =  CreateContest::where('default_contest_id', $dfc_id)->first();
            //     $update_contest = CreateContest::where('default_contest_id', $dfc_id)->where('match_id',$updateMatch->match_id)->get();
               

            //     if($update_contest->count()==0)
            //     {  
            //         $default_contest_id = $dfc_id;
            //         $this->updateContestMGB($contest, $updateMatch->match_id, $default_contest_id);
            //     }
            // }

           


        }
    }

    // update contest mega, giveaway and bonus to match
    public function updateContestMGB($contest, $match_id, $default_contest_id)
    {
            $update_contest = new CreateContest;
                

            foreach ($contest->getAttributes() as $key => $value) {
                if($key == 'id' || $key == 'created_at' || $key == 'updated_at')
                {
                    continue;
                }
                if($key=='filled_spot' || $key== 'is_full')
                {
                    $update_contest->$key = 0;
                }else{
                    $update_contest->$key = $value; 
                }

                if($key=='match_id')
                {
                    $update_contest->$key = $match_id;
                }   
            }

            \DB::table('default_contents')
            ->where('id', $default_contest_id)  // First, apply the where condition
            ->update(['match_id' => $match_id]);  // Then apply the update

            $update_contest->save();
    }
 }
