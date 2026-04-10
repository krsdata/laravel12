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
use Crypt,okie,Hash,Lang,JWTAuth,Input,Closure,URL; 
use App\Helpers\Helper as Helper;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
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
use App\Models\MatchStat;
use App\Models\PrizeDistribution;
use App\Models\ReferralCode;



class PaymentController extends BaseController
{
   
    public $token;
    public $date;

    public function __construct(Request $request) {
        $this->date = date('Y-m-d');
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
       // $uname      = Helper::generateRandomString(); 
    } 
  /**
    * Check Repeat Rank;
    *
    */
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
    public function getAmountPerRank($rank,$match_id=null,$contest_id=null,$repeat_rank=1)
    {
        $rank_from  = $rank; //$rank;
        $rank_to    = $rank+($repeat_rank-1);
        $cid        = $contest_id;  
        
        //$rank_id = \DB::table('prize_breakups')->where('default_contest_id',$cid)->where('rank_upto','>',$rank_from)->count();

        $amt = [];
        $count  =1;
        for($i=$rank_from; $i<=$rank_to; $i++){
            $sum_amt = \DB::table('prize_breakups')
                ->where('default_contest_id',$cid)
                ->where('rank_from','<=',$i)
                ->where('rank_upto','>=',$i)
                ->sum('prize_amount');
            if($sum_amt==0){
                $sum_amt = \DB::table('prize_breakups')
                ->where('default_contest_id',$cid)
                ->where('rank_from','=',$i)
                ->where('rank_upto','>=',1)
                ->sum('prize_amount');
            }
            $amt[] = $sum_amt;
        } 
        
        $prize_amount = array_sum($amt)/$repeat_rank;
        return $prize_amount;
        
        /*
        $rank_prize  = \DB::table('prize_breakups')
                                ->where(function($q) use ($rank,$cid,$rank_to){
                                    $q->where('rank_upto','>=',$rank_to);
                                    $q->where('rank_from','<=',$rank_to);
                                    $q->where('default_contest_id',$cid);

                                })
                                ->orwhere(function($q) use ($rank_from,$rank_to,$cid){
                                    $q->where('rank_from','>=',$rank_from);
                                    $q->where('rank_from','<=',$rank_to);
                                    $q->where('default_contest_id',$cid);
                                }) ;
                                if($rank_id==0){
                                    $amt  =  $rank_prize->sum('prize_amount')??0;
                                    $prizeBreakup = $amt/$repeat_rank;
                                  }else{
                                    $prizeBreakup=  $rank_prize->sum('prize_amount')??0;


                                }
                        //        ->avg('prize_amount');  
        if($rank_prize){
            return $prizeBreakup;    
        }else{
            return $prizeBreakup=0;
        }
        */
        
    }

  /**
    *@var match_id
    *Description Prize distribution
    */
    public function prizeDistribution(Request $request)
    {  
        $match_id = $request->match_id;  
        $get_join_contest = JoinContest::where('match_id',  $match_id)
          ->where('winning_amount','>',0)
	  ->where('cancel_contest',0)
          ->get();

        $get_join_contest->transform(function ($item, $key)   {
            
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
            $item->user = $user;
            $item->team_id = $team_id;
            $item->match_id = $match_id;
            $item->user_id = $user_id;
            $item->rank = $rank;
            $item->team_name = $team_name;
            $item->createdTeam = $ct; 

            $contest = CreateContest::find($item->contest_id);

            if($item->contest==null){
            }else{
              //echo $rank.'-'.$match_id.'-'.$user_id.'-'.$team_id.'<br>';
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
                            'user_name'        => $item->user->user_name,
                            'name'             => $item->user->first_name??$item->user->name,
                            'mobile'           => $item->user->phone,
                            'email'            => $item->user->email,
                            'device_id'        => $item->user->device_id,
                            'contest_name'     => $item->contest->contest_type??null,
                            'entry_fees'       => $item->contest->entry_fees,
                            'total_spots'      => $item->contest->total_spots,
                            'filled_spot'      => $item->contest->filled_spot,

                            'first_prize'        => $item->contest->first_prize,
                            'default_contest_id'=> $item->contest->default_contest_id,
 
                            'prize_amount'      => $item->winning_amount,
                            'contest_type_id'   => $item->contest->contest_type??null,
                            'captain'           => $item->createdTeam->captain,
                            'vice_captain'      => $item->createdTeam->vice_captain,
                            'trump'             => $item->createdTeam->trump,
                            'match_team_id'     => $item->createdTeam->team_id,
                            'user_teams'        => $item->createdTeam->teams

                          ]
                        ); 
            }
        });
         
        $prize_distributions = PrizeDistribution::where('match_id',$match_id)
            ->get();
         
        $match_id = $request->match_id;  
        $dist_status = $cid = \DB::table('matches')->where('match_id',$match_id)->first();
        
        if($dist_status && $dist_status->current_status==1){
            return  Redirect::to(URL::Previous().'admin/match?prize=true');
        }

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
                                'payment_type_string' => 'Prize',
                                'amount'        =>  $prize_amount,
                                'prize_amount'  =>  $prize_amount,
                                'prize_distributed_id' => $item->id
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
                                ->whereIn('payment_type',[3,4])
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
                                'match_id'          => $item->match_id,
                                'contest_id'        => $item->contest_id,
                                'transaction_id'    =>  $item->match_id.'N'.$item->contest_id.'N'.$item->user_id,
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
        return  Redirect::to(URL::Previous().'admin/match?prize=true');
    }
    
    public function sendNotification($tokenList, $data){
        
        $serverLKey = 'AIzaSyAFIO8uE_q7vdcmymsxwmXf-olotQmOCgE';
        $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

       $extraNotificationData = $data;

       if(is_array($tokenList)){  
            $fcmNotification = [
           'registration_ids' => $tokenList, //multple token array
         //  'to' => $token, //single token
           //'notification' => $notification,
           'data' => $extraNotificationData
        ];
       }else{
            $fcmNotification = [
          // 'registration_ids' => $tokenList, //multple token array
            'to' => $tokenList, //single token
           //'notification' => $notification,
           'data' => $extraNotificationData
       ];
       }

       

       $headers = [
           'Authorization: key='.$serverLKey,
           'Content-Type: application/json'
       ];


       $ch = curl_init();
       curl_setopt($ch, CURLOPT_URL, $fcmUrl);
       curl_setopt($ch, CURLOPT_POST, true);
       curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
       curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
       curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
       curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($fcmNotification));
       $result = curl_exec($ch);
       //echo "result".$result;
       //die;
       curl_close($ch);
       return true;
    }
    // Add Money
    public function addMoney(Request $request){
        
        $myArr = [];
        $user = User::find($request->user_id);
        

        $validator = Validator::make($request->all(), [
                'user_id' => 'required',
                'deposit_amount' => 'required',
                'transaction_id' => 'required', 
                'payment_mode' => 'required',
                'payment_status' => 'required'
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

        Log::channel('payment_info')->info($request->all());

        if($user){
            $check_user = Hash::check($user->id,$user->validate_user);    
            
            if($check_user){
                $wallet     = Wallet::where('user_id',$user->id)->first();
                $deposit_amount = (float) $request->deposit_amount;
                $message    = "Amount not added successfully";
                $status     = false;
                $code       = 201;
                if($wallet){
                   \DB::beginTransaction();

                    $wallet->deposit_amount   =  $wallet->deposit_amount+$deposit_amount;
                    $wallet->usable_amount    =  $wallet->usable_amount+$deposit_amount;
                    $wallet->save();
                    
                    $myArr['wallet_amount']   = (float) $wallet->usable_amount; 
                    $myArr['bonus_amount']    = (float)$wallet->bonus_amount;
                    $myArr['user_id']         = (float)$wallet->user_id; 

                    $transaction = new WalletTransaction;
                    $transaction->user_id        =  $request->user_id;
                    $transaction->amount         =  $request->deposit_amount;
                    $transaction->transaction_id =  $request->transaction_id;
                    $transaction->payment_mode   =  $request->payment_mode;
                    $transaction->payment_status =  $request->payment_status;
                    $transaction->payment_details =  json_encode($request->all());
                    $transaction->save();

                    $message    = "Amount added successfully";
                    $status     = true;
                    $code       = 200;
                    \DB::commit();                       
                }
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

    public function transactionHistory(Request $request){

        $user = User::find($request->user_id);
        if($user){
            $wallet = Wallet::where('user_id',$user->id)
                    ->select('user_id')
                    ->get()
                    ->transform(function($item,$key){
                        $item->bonus_amount = 0;
                        $item->prize_amount = 0;
                        $item->referral_amount = 0;
                        $item->deposit_amount = 0;
                        
                        $prize_amounts = Wallet::where('user_id',$item->user_id)->get();
                        foreach ($prize_amounts  as $key => $prize_amount) {
                            if($prize_amount->payment_type==1){
                                $item->bonus_amount   = $prize_amount->amount;
                            }
                            elseif($prize_amount->payment_type==4){
                                $item->prize_amount   = $prize_amount->amount;
                            }
                            elseif($prize_amount->payment_type==2){
                                $item->referral_amount = $prize_amount->amount;
                            }
                            elseif($prize_amount->payment_type==3){
                                $item->deposit_amount = $prize_amount->amount;
                            }
                        }
                        $date = \Carbon\Carbon::today()->subDays(7);

                        $transaction = [];
                        $wallet_transactions = \DB::table('wallet_transactions')->where('user_id',$item->user_id)->orderBy('id','desc')
                            //->whereMonth('created_at',date('m'))
                            ->where('created_at','>=',$date)
                            //->limit(30)
                            ->get()
                            ->transform(function($item,$key){

                                if($item->match_id!==null){
                                    $match_name = Matches::where('match_id',$item->match_id)->first();
                                    $item->match_name = ' - '.$match_name->short_title;
                                }else{
                                    $item->match_name = null;
                                }
                                return $item;
                            });
                        foreach ($wallet_transactions as $key => $value) {
                            
                            $dt =  strtotime($value->created_at);
                                                    
                            $d = date('d-M-Y, h:i A',$dt);                        
                             $transaction[] =  [
                                'user_id'        => $item->user_name,
                                'amount'         => $value->amount??0,
                                'payment_mode'   => $value->payment_mode??'Online',
                                'payment_status' => $value->payment_status??'success',
                                'transaction_id' => $value->transaction_id??time(),
                                'payment_type'   => ucfirst($value->payment_type_string.$value->match_name),
                                'debit_credit_status' => $value->debit_credit_status,
                                'date'           => $d

                             ];
                        } 
                        $item->transaction = $transaction;
                        return $item;

                    });       
            return response()->json(
                        [ 
                            "status"=>true,
                            "code"=>200,
                            "message" => "Transaction history",
                            "transaction_history"=>$wallet[0]??null
                        ]
                    );
        }else{

             return response()->json(
                        [ 
                            "status"=>true,
                            "code"=>200,
                            "message" => "Transaction history",
                            "walletInfo"=>null
                        ]
                    );

        }

    }
}
