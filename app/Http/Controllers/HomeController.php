<?php

namespace App\Http\Controllers;
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
use Session;
use Illuminate\Support\Facades\DB;
use App\Models\Wallet;
use App\Models\WalletTransaction;


class HomeController extends BaseController
{
   
    public function __construct(Request $request) {
        $pages = \DB::table('pages')->get(['title','slug']);
        View::share('static_page',$pages);

        $settings = \DB::table('settings')
                    ->pluck('field_value','field_key')
                    ->toArray();
       
        View::share('settings',(object)$settings);

    }  

    public function page404(Request $request){
         return view('404');
    }
    public function home(Request $request){
         return view('home');
    }


    public function addMoney(Request $request){
        
        $user_id = $request->user_id??'UNNJAUJJ';

        $user = User::find($user_id);
        $email = $user->email;

        if($request->source=="jk"){
            return view('addMoneyJk', compact('user_id','email'));
        }

         return view('addMoney', compact('user_id','email'));
    }

   
    public function winingDeposit(Request $request){

        $user_id    = $request->user_id;
        $win_amount  = \DB::table('wallets')->where('payment_type',4)->where('user_id',$user_id)->sum('amount');
 

        
        $winning_deposit = (float) env('WINING_DEPOSIT', 0.05);
        if ($request->isMethod('post')) {

            $request->validate([
                'winningAmount' => 'required|numeric|min:10'
            ]);

            $user_id    = $request->user_id;
            $win_amount = (float) $request->winningAmount;


            DB::beginTransaction();

            try {
                // Winning wallet (payment_type = 4)
                $winningWallet = Wallet::where('user_id', $user_id)
                    ->where('payment_type', 4)
                    ->lockForUpdate()
                    ->first();

                if (!$winningWallet || $win_amount > $winningWallet->amount) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Insufficient Balance'
                    ], 400);
                }

                $winning_deposit = (float) env('WINING_DEPOSIT', 0.05);

                $bonusAmount   = round($win_amount * $winning_deposit, 2); 
                $depositAmount = $win_amount + $bonusAmount;

                // Deposit wallet (payment_type = 3)
                $depositWallet = Wallet::where('user_id', $user_id)
                    ->where('payment_type', 3)
                    ->lockForUpdate()
                    ->first();

                // Winning deduction transaction
                WalletTransaction::create([
                    'user_id' => $user_id,
                    'amount' => $win_amount,
                    'payment_type' => 9,
                    'order_id' => 'WD-' . time(),
                    'payment_type_string' => 'Winning to Deposit',
                    'payment_mode' => 'Winning to Deposit',
                ]);

                // Bonus transaction
                WalletTransaction::create([
                    'user_id' => $user_id,
                    'amount' => $bonusAmount,
                    'payment_type' => 9,
                    'order_id' => 'EXT-WD-' . time(),
                    'payment_type_string' => '5% Extra Cash',
                    'payment_mode' => 'Bonus',
                ]);

                // Update wallets
                $winningWallet->decrement('amount', $win_amount);

                $depositWallet->increment('amount', $depositAmount);
                $depositWallet->comment = "Winning converted to deposit ₹{$depositAmount}";
                $depositWallet->save();

                DB::commit();

                return response()->json([
                    'success' => true,
                    'message' => 'Winning converted successfully',
                    'data' => [
                        'winning_used' => $win_amount,
                        'bonus' => $bonusAmount,
                        'deposit_added' => $depositAmount
                    ]
                ]);

            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage()
                ], 500);
            }
        }
        
        $user_id    = $request->user_id ;
         
        return view('wining_deposit', compact('user_id','winning_deposit','win_amount'));
    }


    public function liveChat(Request $request){
        return view('liveChat');
    }

    public function aboutus(Request $request){

        return view('aboutus');
    }


    public function contactus(Request $request){

        if($request->method()=="POST"){

        $request->merge(['request_id'=>time()]);
        $request->merge(['title'=> 'web_request']);
        $request->merge(['name' => $request->name]);
        $request->merge(['mobile'=> $request->mobile]);

        $request->merge(['subject'=> 'Enquiry']);
        
        $table_cname = \Schema::getColumnListing('contacts');
        $except = ['id','created_at','updated_at'];
        $data = [];
        foreach ($table_cname as $key => $value) {
           
           if(in_array($value, $except )){
                continue;
           } 
            if($request->get($value)){
                $data[$value] = $request->get($value);
           }
        }
        \DB::table('contacts')->insert($data);
        Session::put('status','Your Request successfully submitted!');
        
        }

        return view('contactus');
    }
    public function getPage(Request $request, $name=null){
        
        $content = \DB::table('pages')
                ->where('slug',$name)
                ->first();
        if( $content==null){
            return view('404',compact('content'));
        }
        $remove_header = false;
        if($request->get('request')=='mobile'){

            $remove_header = true;

        }


        return view('page',compact('content','remove_header'));
    }
    public function index(){
	
$url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http");
$url .= "://".$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];

if($url=="https://pay.cashigo.info/")
{
	        return redirect()->to('https://cashigo.info');
}
 
  


        return redirect()->to('https://www.infowaydigital.com/home');

    }
}
