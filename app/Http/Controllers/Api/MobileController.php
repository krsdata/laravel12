<?php

namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\User;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;
use Illuminate\Support\Facades\Auth;
use App\Models\Notification;
use Illuminate\Contracts\Encryption\DecryptException;
use Config,Mail,View,Redirect,Validator,Response;
use Crypt,okie,Hash,Lang,Input,Closure,URL;
use App\Helpers\Helper as Helper;
use PHPMailerAutoload;
use PHPMailer;
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
use App\Models\WalletTransaction;
use App\Models\Rank;
use App\Models\JoinContest;
use App\Models\ReferralCode;
use App\Models\DefaultContest;
use Modules\Admin\Models\Program;
use Illuminate\Support\Str;
use Illuminate\Support\Arr;

use App\Models\MatchPoint;
use App\Models\PrizeDistribution;
use App\Models\MatchStat;

use App\Models\PrizeBreakup;
use File;
use Ixudra\Curl\Facades\Curl;
use Jenssegers\Agent\Agent;


class MobileController extends BaseController
{
    public $download_link;
    public $referral_bonus;
    public $signup_bonus;
    public $smsUrl;
    public $token;
    public $date;
    public $cric_url;
    public $is_session_expire;

    public function __construct(Request $request) {
        // SMS Url
        $this->smsUrl = "https://sms.waysms.in/api/api_http.php";
        /*APK URL*/
        $apk_updates = \DB::table('apk_updates')->orderBy('id','desc')->first();
        $this->download_link = $apk_updates->url??null;

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
        /*Promotion*/
        //whereDate('end_date','>=',date('Y-m-d'))
        // $program  = Program::get()
        //     ->transform(function($item, $key){

        //         if($item->promotion_type==1)
        //         {
        //             $item->referral = true;
        //             $item->bonus = false;
        //         }
        //         if($item->promotion_type==2)
        //         {
        //             $item->referral = false;
        //             $item->bonus = true;
        //         }
        //         if($item->trigger_condition==1)
        //         {
        //             $item->signup = true;
        //         }else{
        //             $item->signup = false;
        //         }

        //         return $item;
        //     });
       // $signup_bonus = $program->where('bonus',true)->first();
        //$referral_bonus = $program->where('referral',true)->first();

        //$this->referral_bonus = $referral_bonus->amount??5;
        //$this->signup_bonus = $signup_bonus->amount??100;

        
        $this->referral_bonus = 5;
        $this->signup_bonus = 100;

    }

    public function inviteUser(Request $request,User $inviteUser)
    {
        $messages = [
            'user_id.required' => 'Invalid User id',
            'email.required' => 'Provide email id'

        ];
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'email' => 'required|email'
        ],$messages);

        $user_id = $request->get('user_id');
        $invited_user = User::find($user_id);
        // Return Error Message
        if ($validator->fails() || $invited_user ==null) {
            $error_msg =[];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg??'Opps! This user is not available'
                )
            );
        }

        $user_first_name = $invited_user->name ;

        $user_email = $request->input('email');

        /** --Send Mail after Sign Up-- **/

        $user_data     = User::find($user_id);
        $sender_name   = $user_data->name;
        $invited_by    = $user_data->name==null?$invited_user->first_name.' '.$invited_user->last_name:$user_data->name;
        $referal_code  = $user_data->user_name;
        $receipent_name = "Hi,";
        $subject       = ucfirst($sender_name)." has invited you to join SportsFight";

        $email_content = [
            'receipent_email'=> $user_email,
            'subject'=>$subject,
            'receipent_name'=>$receipent_name,
            'invite_by'=>$invited_by,
            'download_link' => $this->download_link,
            'referal_code' => $referal_code
        ];

        $helper = new Helper;

        $invite_notification_mail = $helper->sendNotificationMail($email_content,'invite_notification_mail');

        //$user->save();

        return  response()->json([
                "status"=>1,
                "code"=> 200,
                "message"=>"You've invited your colleague, nice work!",
                'data' => ['receipentEmail'=>$user_email]
            ]
        );
    }

    public function generateUserName(){
        $uname =  Helper::generateRandomString(8);
        $is_user = 1;
        while ($is_user=null) {
            $is_user = User::where('user_name',$uname)->first();
            if($is_user){
                $uname      = Helper::generateRandomString(8);
            }
        }
        return $uname;
    }

    public function generateReferralCode(){
        $referal_code =  Helper::generateRandomString(5);
        $is_user = 1;
        while ($is_user=null) {
            $is_user = User::where('referal_code',$referal_code)->first();
            if($is_user){
                $referal_code = Helper::generateRandomString(5);
            }
        }
        return $referal_code;
    }

    public function verifyDocument(Request $request){

        $user = User::find($request->user_id);
        $messages = [
            'user_id.required' => 'Invalid User id',
            'adhar.required' => 'Please upload Adhar card'

        ];
        $validator = Validator::make($request->all(), [
            'user_id'   => 'required',
            'pan'       => 'mimes:jpeg,bmp,jpg,png,gif,pdf',
            'adhar'     => 'mimes:jpeg,bmp,jpg,png,gif,pdf'
        ],$messages);

        // Return Error Message
        if ($validator->fails() || $user ==null) {
            $error_msg =[];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg??'Opps! This user is not available'
                )
            );
        }
        $doc = \DB::table('verify_documents')
            ->where('user_id',$user->id)
            ->first();
        if($doc){

            return Response::json(array(
                    'status' => true,
                    "code"=> 200,
                    'message' => $doc->status==1?'Document already verified':'Waiting for approval'
                )
            );
        }

        $data['user_id'] = $user->id;

        if ($request->get('pan')) {

            $bin = base64_decode($request->get('pan'));

            $im = imageCreateFromString($bin);
            if (!$im) {
                die('Base64 value is not a valid image');
            }

            $image_name= $user->id.'_pan'.'.jpg';
            $path = storage_path() . "/image/" . $image_name;
            //file_put_contents($path, $im);
            imagepng($im, $path, 0);
            $urls = url::to(asset('storage/image/'.$image_name));

            $request->merge(['pan_url'=>$urls]);
            $data['pan_url']  = $urls;
            $data['pan'] = $image_name;
            $data['upload_status'] = 'uploaded';
        }

        if ($request->get('adhar')) {
            $bin = base64_decode($request->get('adhar'));
            $im = imageCreateFromString($bin);
            if (!$im) {
                die('Base64 value is not a valid image');
            }

            $image_name= $user->id.'_pan'.'.jpg';
            $path = storage_path() . "/image/" . $image_name;
            //file_put_contents($path, $im);
            imagepng($im, $path, 0);
            $urls = url::to(asset('storage/image/'.$image_name));

            $request->merge(['adhar_url'=>$urls]);
            $data['adhar_url']  = $urls;
            $data['adhar'] = $image_name;
            $data['upload_status'] = 'uploaded';
        }

        if ($request->get('address_proof')) {
            $bin = base64_decode($request->get('address_proof'));
            $im = imageCreateFromString($bin);
            if (!$im) {
                die('Base64 value is not a valid image');
            }

            $image_name= $user->id.'_pan'.'.jpg';
            $path = storage_path() . "/image/" . $image_name;
            //file_put_contents($path, $im);
            imagepng($im, $path, 0);
            $urls = url::to(asset('storage/image/'.$image_name));

            $request->merge(['address_proof_url'=>$urls]);
            $data['address_proof_url']  = $urls;
            $data['address_proof'] = $image_name;
            $data['upload_status'] = 'uploaded';
        }


        $doc = \DB::table('verify_documents')
            ->updateOrInsert(['user_id'=>$user->id],$data);

        return Response::json(array(
                'status' => true,
                "code"=> 200,
                'message' => "Document uploaded.We'll notify you soon."
            )
        );

    }
    public function myReferralDetails(Request $request)
    {
        $referal_user = ReferralCode::where('refer_by',$request->user_id)
            ->select('referral_amount','user_id','is_verified','created_at')
            ->orderBy('id','desc')
            ->get()
            ->transform(function($item,$key){
                $user = User::find($item->user_id);
                if($user){
                    $item->name = $user->name;
                    return $item;
                }

            })->toArray();

        if($referal_user){
            return Response::json(array(
                    'status' => true,
                    "code"=> 200,
                    'message' => "List of referal",
                    'referal_user' => array_values(array_filter($referal_user))
                )
            );
        }else{
            return Response::json(array(
                    'status' => false,
                    "code"=> 201,
                    'message' => "No referal user found"
                )
            );
        }

    }
    public function updateAfterLogin(Request $request){

        $refer_by = User::where('referal_code',$request->referral_code)
            ->orWhere('user_name',$request->referral_code)
            ->first();

        $user_id = $request->user_id;
        $user = User::find($user_id);

        if($refer_by && $user)
        {
            $referralCode = new ReferralCode;
            $referralCode->referral_code    =   $request->referral_code;
            $referralCode->user_id          =   $user_id;
            $referralCode->refer_by         =   $refer_by->id??$user->id;
            $referralCode->save();


            $wallet_trns['user_id']         =  $refer_by->id??null;
            $wallet_trns['amount']          =  $this->referral_bonus;
            $wallet_trns['payment_type']    =  2;
            $wallet_trns['payment_type_string'] = "Referral";
            $wallet_trns['transaction_id']  = time().'-'.$refer_by->id??null;
            $wallet_trns['payment_mode']    = "sportsfight";
            $wallet_trns['payment_details'] = json_encode($wallet_trns);
            $wallet_trns['payment_status']  = "success";

            $wallet_transactions = WalletTransaction::create(
                $wallet_trns
            );

            $wallet = Wallet::firstOrNew(
                [
                    'payment_type' => 2,
                    'user_id' => $refer_by->id
                ]
            );

            $wallet->user_id        = $refer_by->id;
            $wallet->validate_user  = Hash::make($refer_by->id);
            $wallet->payment_type   = 2 ;
            $wallet->payment_type_string = "Referral";
            $wallet->referal_amount = ($wallet->referal_amount)+$this->referral_bonus;
            $wallet->amount = ($wallet->referal_amount)+$this->referral_bonus;

            $wallet->save();
        }

        if($user){
            $user->name             = $request->name;
            $user->mobile_number    = $request->mobile_number;
            $user->phone            = $request->phone;
            $user->profile_image    = $request->image_url;
            $user->reference_code   = $request->referral_code;
            $user->save();

            return Response::json(array(
                    'status' => true,
                    "code"=> 200,
                    'message' => "Details successfully saved",
                    'login_user' =>$user->id
                )
            );
        }else{
            return Response::json(array(
                    'status' => false,
                    "code"=> 201,
                    'message' => "user is not registered"
                )
            );
        }

    }
    public function registration(Request $request)
    {
        $input['first_name']    = $request->get('first_name')??$request->get('name');

        $input['name']          = $request->name;
        $input['email']         = $request->get('email');
        $input['password']      = Hash::make($request->input('password'));
        $input['role_type']     = 3; //$request->input('role_type'); ;
        $input['user_type']     = $request->get('user_type');
        $input['provider_id']   = $request->get('provider_id');
        $input['mobile_number']     = $request->get('mobile_number');

        if($input['user_type']=='googleAuth' || $input['user_type']=='facebookAuth' ){
            //Server side valiation
            $validator = Validator::make($request->all(), [
                'email' => 'required|email',
                'name' => 'required',
                'provider_id' => 'required'
            ]);
        }else{
            //Server side valiation
            //  $request->merge(['mobile' => $request->mobile_number]);

            $validator = Validator::make($request->all(), [
                'email'          => 'required|email|unique:users',
                'mobile_number'  => 'required|unique:users',
                'password'       => 'required'
            ]);
        }

        /** Return Error Message **/
        if ($validator->fails()) {
            $error_msg      =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'status' => false,
                    'code'=>201,
                    'message' => $error_msg[0]
                )
            );
        }

        \DB::beginTransaction();

        $helper = new Helper;
        /** --Create USER-- **/
        $user = new User;
        foreach ($input as $key => $value) {
            $user->$key = $value;
        }
        $uname = strtoupper(substr($user->name, 0, 3)).$this->generateUserName();
        $user->user_name    = $uname;
        $user->referal_code = $uname;

        $user->save();

        if($user->id){
            $wallet = new Wallet;
            $wallet->user_id = $user->id;
            $wallet->validate_user = Hash::make($user->id);
            $wallet->payment_type  =  1;
            $wallet->payment_type_string = "Bonus";
            $wallet->amount         = $this->signup_bonus;
            $wallet->bonus_amount   = $this->signup_bonus;
            $wallet->save();
            $wallet  =  Wallet::find($wallet->id);

            $wallet_trns['user_id']         =  $user->id??null;
            $wallet_trns['amount']          =  $this->signup_bonus;
            $wallet_trns['payment_type']    =  1;
            $wallet_trns['payment_type_string'] = "Bonus";
            $wallet_trns['transaction_id']  = time().'-'.$user->id??null;
            $wallet_trns['payment_mode']    = "sportsfight";
            $wallet_trns['payment_details'] = json_encode($wallet_trns);
            $wallet_trns['payment_status']  = "success";

            $wallet_transactions = WalletTransaction::updateOrCreate(
                [
                    'payment_type' => 1,
                    'user_id' => $user->id
                ],
                $wallet_trns
            );
        }

        \DB::commit();

        $user  = User::find($user->id);
        $user->validate_user    = Hash::make($user->id);
        $user->reference_code   = $request->referral_code;
        $user->mobile_number    = $request->mobile_number;
        $user->phone            = $request->phone;
        $user->save();

        $token = $user->createToken('SportsFight')->accessToken;
        $user_data['referal_code']     =  $user->user_name;
        $user_data['user_id']          =  $user->id;
        $user_data['name']             =  $user->name;
        $user_data['email']            =  $user->email;
        $user_data['bonus_amount']     =  (float)$wallet->bonus_amount;
        $user_data['usable_amount']    =  (float)$wallet->usable_amount;
        $user_data['mobile_number']    =  ($user->phone==null)?$user->mobile_number:$user->phone;

        $subject = "Welcome to SportsFight! Verify your email address to get started";
        $email_content = [
            'receipent_email'=> $request->input('email'),
            'subject'=>     $subject,
            'greeting'=>    'SportsFight',
            'first_name'=> $request->input('name')??$request->input('first_name')
        ];

        //$verification_email = $helper->sendMailFrontEnd($email_content,'verification_link');


        $notification = new Notification;
        $notification->addNotification('user_register',$user->id,$user->id,'User register','');

        // user device details
        $devD = \DB::table('hardware_infos')->where('user_id',$user->id)->first();
        if($devD){
            $deviceDetails = json_encode($request->deviceDetails);
            \DB::table('hardware_infos')->where('user_id',$devD->user_id)->update([
                'user_id' => $user->id,
                'device_details' => $deviceDetails
            ]);
        }else{
            $deviceDetails = json_encode($request->deviceDetails);
            \DB::table('hardware_infos')->insert([
                'user_id' => $user->id??0,
                'device_details' => $deviceDetails
            ]);
        }
        $apk_updates    = \DB::table('apk_updates')
                            ->orderBy('id','desc')
                            ->first();

        $data['apk_url'] =  $apk_updates->url??null;
        //reference_code
        $refer_by = User::where('referal_code',$request->referral_code)
            ->orWhere('user_name',$request->referral_code)
            ->first();

        if($refer_by && $user)
        {
            $referralCode = new ReferralCode;
            $referralCode->referral_code    =   $request->referral_code;
            $referralCode->user_id          =   $user->id;
            $referralCode->refer_by         =   $refer_by->id;
            $referralCode->save();

            $wallet_trns['user_id']         =  $refer_by->id??null;
            $wallet_trns['amount']          =  $this->referral_bonus;
            $wallet_trns['payment_type']    =  2;
            $wallet_trns['payment_type_string'] = "Referral";
            $wallet_trns['transaction_id']  = time().'-'.$refer_by->id??null;
            $wallet_trns['payment_mode']    = "sportsfight";
            $wallet_trns['payment_details'] = json_encode($wallet_trns);
            $wallet_trns['payment_status']  = "success";

            $wallet_transactions = WalletTransaction::create(
                $wallet_trns
            );


            $wallet = Wallet::firstOrNew(
                [
                    'payment_type' => 2,
                    'user_id' => $refer_by->id
                ]
            );

            $wallet->user_id        = $refer_by->id;
            $wallet->validate_user  = Hash::make($refer_by->id);
            $wallet->payment_type   = 2 ;
            $wallet->payment_type_string = "Referral";
            $wallet->referal_amount = ($wallet->referal_amount)+$this->referral_bonus;
            $wallet->amount = ($wallet->referal_amount)+$this->referral_bonus;

            $wallet->save();

        }
        if($user){
            $user->name             = $request->name;
            $user->mobile_number    = $request->mobile_number;
            $user->phone            = $request->phone;
            $user->profile_image    = $request->image_url;
            $user->reference_code   = $request->referral_code;
            $user->save();
        }
        $request->merge(['user_id'=>$user->id]);
       // $this->generateOtp($request);

        return response()->json(
            [
                "status"=>true,
                "code"=>200,
                "message"=>"Thank you for registration. Otp sent to your register email id and mobile number.",
                'data' => $user_data,
                'token' => $token??null
            ]
        );
    }

    public function updateProfile(Request $request){

        $myArr = [];

        $validator = Validator::make($request->all(), [
            'user_id' => 'required'
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
                    'message' => $error_msg[0]
                )
            );
        }
        $user = User::find($request->user_id);
        if($user){
            $user->city = $request->city;
            $user->dateOfBirth = $request->dateOfBirth;
            $user->gender = $request->gender;
            $user->name = $request->name;
            if($request->team_name){
                $user->team_name = $request->team_name;
            }

            $user->all = json_encode($request->all());
            $user->save();

            return response()->json(
                [
                    "status"=>true,
                    "code"=>200,
                    "message" => "Profile updated successfully"
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


    // Image upload

    public function createImage($request)
    {
        try{
            //  $request->get('image_bytes');
            $bin = base64_decode($request->get('profile_image'));
            $im = imageCreateFromString($bin);
            if (!$im) {
                die('Base64 value is not a valid image');
            }

            $image_name= time().'.jpg';
            $path = storage_path() . "/image/" . $image_name;
            //file_put_contents($path, $im);
            imagepng($im, $path, 0);
            $urls = url::to(asset('storage/image/'.$image_name));
            return $urls;
        }catch(Exception $e){
            return false;
        }
    }

    // Validate user
    public function validateInput($request,$input){
        //Server side valiation

        $validator = Validator::make($request->all(), $input);

        /** Return Error Message **/
        if ($validator->fails()) {
            $error_msg      =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            if($error_msg){
                return array(
                    'status' => false,
                    'code' => 201,
                    'message' => $error_msg[0],
                    'data'  =>  $request->all()
                );
            }

        }
    }

    public function saveReferral($request,$user=null){

        $refer_by = User::where('referal_code',$request->referral_code)
                    ->where('block_referral',0)
                    ->first();
        if($refer_by){
            $ref = $request->referral_code;
        }else{
            $refer_by = User::where('referal_code','SPORTSFIGHT')
                    ->where('block_referral',0)
                    ->first();
            $ref= "SPORTSFIGHT";
        }

        if($refer_by && $user)
        {
            $referralCode = new ReferralCode;
            $referralCode->referral_code    =   $ref??null; //$request->referral_code;
            $referralCode->user_id          =   $user->id;
            $referralCode->refer_by         =   $refer_by->id;
            $referralCode->referral_amount  =   $this->referral_bonus;
            $referralCode->save();

            $wallet_trns['user_id']         =  $refer_by->id??null;
            $wallet_trns['amount']          =  $this->referral_bonus;
            $wallet_trns['payment_type']    =  2;
            $wallet_trns['payment_type_string'] = "Referral Bonus";
            $wallet_trns['transaction_id']  = time().'-'.$refer_by->id??null;
            $wallet_trns['payment_mode']    = "sportsfight";
            $wallet_trns['payment_details'] = json_encode($wallet_trns);
            $wallet_trns['payment_status']  = "success";

            $wallet_transactions = WalletTransaction::create(
                $wallet_trns
            );

            $wallet = Wallet::firstOrNew(
                [
                    'payment_type' => 1,
                    'user_id' => $refer_by->id
                ]
            );
            $wallet->user_id        = $refer_by->id;
            $wallet->validate_user  = Hash::make($refer_by->id);
            $wallet->payment_type   = 1 ;
            $wallet->payment_type_string = "Referral Bonus";
            $wallet->amount = ($wallet->amount)+$this->referral_bonus;

            $wallet->save();

        }
        if($user){
            $user->reference_code   = $request->referral_code;
            $user->save();
            return true;
        }else{
            return false;
        }
    }

    public function changeMobile(Request $request){

        $validator = Validator::make($request->all(), [
                        'user_id' => 'required',
                        'mobile_number'  => 'required|unique:users|regex:/^([0-9\s\-\+\(\)]*)$/|min:10'

                    ]);

                    if ($validator->fails()) {
                        $error_msg = [];
                        foreach ($validator->messages()->all() as $key => $value) {
                            array_push($error_msg, $value);
                        }
                        if ($error_msg) {
                            return array(
                                'status' => false,
                                'code' => 201,
                                'message' => $error_msg[0],
                                'data' => $request->all()
                            );
                        }
                    }

            $user = User::find($request->user_id);

            if($user){
               // $this->generateOtp($request);
                $user->mobile_number = $request->mobile_number;
                $user->is_account_verified=0;
                $user->save();

            return response()->json([
                "status"=>true,
                "code"=>200,
                "message" => 'Mobile number updated and otp sent'

            ]);

            }else{
                return response()->json([
                    "status"=>true,
                    "code"=>201,
                    "message" => 'Mobile number not updated'

                ]);
            }
    }

    public function login(Request $request)
    {
       /* $okhttp = Str::contains($_SERVER['HTTP_USER_AGENT'], 'okhttp');
        if(!$okhttp){
            return array(
                    'status' => false,
                    'code' => 201,
                    'message' => 'unauthorise access!'
                );
        }*/

        $request->merge(['user_type'=>'googleAuth']);
        $data = [];
        $input = $request->all();
        // print_r ($input);
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);
        if ($validator->fails()) {
            $error_msg = [];
            foreach ($validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            if ($error_msg) {
                return array(
                    'status' => false,
                    'code' => 201,
                    'message' => $error_msg[0],
                    'data' => $request->all()
                );
            }
        }

        $user_type = $request->user_type;
        switch ($user_type) {
            case 'googleAuth':

                $credentials = [
                    'email'=>$request->get('email'),
                    'user_type' => 'googleAuth'
                ];

                $user = User::where('email',$request->email)->first();
                if($user){

                    if($user->status==0){
                        return array(
                            'status' => false,
                            'code' => 420,
                            'message' => 'Your Account is disabled.To activate write an email at info@sportsfight.in'
                            );
                    }

                    $data['name']       = $user->name??$request->name;
                    $data['email']      = $user->email??$request->email;
                    $data['user_id']    = $user->user_name;
                    $data['team_name']  = $user->team_name??$request->team_name;
                    $data['profile_image'] = $user->profile_image;
                    $data['mobile_number'] = $request->mobile_number??$user->mobile_number;
                    $data['otpverified'] = $user->is_account_verified?true:false;
                     // dd($user->mobile_number);
                     $usermodel = User::where('email',$request->email)->first();
                    if($request->name) {
                        $usermodel->name = $request->name;
                    }

                   if($request->mobile_number){
                        $usermodel->mobile_number = $request->mobile_number;
                   }
                   elseif($usermodel->mobile_number){
                        $usermodel->mobile_number = $usermodel->mobile_number;
                   }
                   if(!$user->profile_image){
                        $usermodel->profile_image = $request->profile_image;
                   }

                    if(empty($user->mobile_number)){

                        if(empty($user->mobile_number) && $request->mobile_number==null){
                        return array(
                            'status' => true,
                            'code' => 200,
                            'message' => 'Mobile number required',
                            'data' => $data
                            );
                        }
                        if(empty($user->name) && $request->name==null){
                            return array(
                            'status' => true,
                            'code' => 200,
                            'message' => 'Name is required',
                            'data' => $data
                            );
                        }
                    }elseif($user->is_account_verified==0){

                        $request->merge([
                                'user_id'=>$user->id,
                                'mobile_number'=>$user->mobile_number
                            ]
                        );

                    }
                    $usermodel->email_verified_at = date('Y-m-d h:i:s');

                    $usermodel->provider_id = $request->get('provider_id');
                    if($usermodel->referal_code){
                        $usermodel->referal_code  = $usermodel->referal_code;
                    }else{
                        $usermodel->referal_code = $this->generateReferralCode();
                        $usermodel->reference_code = $request->referral_code;
                    }

                    if($request->team_name){
                        $usermodel->team_name = $request->team_name;
                    }else{
                        if($usermodel->team_name){
                           $usermodel->team_name = $usermodel->team_name;
                        }else{
                            $usermodel->team_name = $usermodel->name;
                        }
                    }
                    $usermodel->provider_id = $request->provider_id;
                    //$usermodel->save();
                    $status = true;
                    $code = 200;
                    $message = "login successfully";
                  //  $this->generateOtp($request);

                }else{
                    $validator = Validator::make($request->all(), [
                        'email'          => 'required|email|unique:users',
                        'mobile_number'  => 'required|unique:users|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
                        'name' => 'required|min:3'
                    ]);

                    if ($validator->fails()) {
                        $error_msg = [];
                        foreach ($validator->messages()->all() as $key => $value) {
                            array_push($error_msg, $value);
                        }
                        if ($error_msg) {
                            return array(
                                'status' => false,
                                'code' => 201,
                                'message' => $error_msg[0],
                                'data' => $request->all()
                            );
                        }
                    }

                    $user = new User;
                    if($request->team_name){
                        $user->team_name = $request->team_name;
                    }
                    $user->name          = $request->name;
                    $user->email         = $request->get('email');
                    $user->role_type     = 3;//$request->input('role_type'); ;
                    $user->mobile_number = $request->mobile_number;
                    $user->provider_id   = $request->get('provider_id');
                    $user->password      = Hash::make(mt_rand(1,9));
                    $user->user_name     = $this->generateUserName();
                    $user->referal_code  = $this->generateReferralCode();
                    $user->reference_code = $request->referral_code;
                    $user->email_verified_at = 1;

                    if($request->referral_code){
                        $referral_code_count = User::where('referal_code',$request->referral_code)->count();
                        if($request->referral_code && $referral_code_count==0){
                            return array(
                                    'status'    => false,
                                    'code'      => 201,
                                    'message'   => 'Referral code is invalid'
                            );
                        }
                    }

                    $user->save() ;

                    $msg = "$user->name has registered using gmail id $user->email";

                    $helper = new Helper;
                    $send_status = $helper->notifyToAdmin('New Registration',$msg);

                    $request->merge(['user_id'=>$user->id,'mobile_number'=>$user->mobile_number]);

                    //$this->generateOtp($request);

                    if($user->id){
                        $devid = User::where('device_id',$request->device_id)->count();
                       // if($devid<2){
                            $this->saveReferral($request,$user);
                      //  }

                        $wallet = new Wallet;
                        $wallet->user_id = $user->id;
                        $wallet->validate_user = Hash::make($user->id);
                        $wallet->payment_type  =  1;
                        $wallet->payment_type_string = "Bonus";
                        $wallet->amount         = $this->signup_bonus;
                        $wallet->bonus_amount   = $this->signup_bonus;
                        $wallet->save();


                        $wallet_trns['user_id']         =  $user->id??null;
                        $wallet_trns['amount']          =  $this->signup_bonus;
                        $wallet_trns['payment_type']    =  1;
                        $wallet_trns['payment_type_string'] = "Bonus";
                        $wallet_trns['transaction_id']  = time().'-'.$user->id??null;
                        $wallet_trns['payment_mode']    = "sportsfight";
                        $wallet_trns['payment_details'] = json_encode($wallet_trns);
                        $wallet_trns['payment_status']  = "success";

                        $wallet_transactions = WalletTransaction::updateOrCreate(
                            [
                                'payment_type' => 1,
                                'user_id' => $user->id
                            ],
                            $wallet_trns
                        );
                    }

                    //$token = $user->createToken('token')->accessToken;
                    $user->validate_user = Hash::make($user->id);
                    $user->save();
                  //  $this->generateOtp($request);
                    $usermodel =  $user;
                    $status     = true;
                    $code       = 200;
                    $message    = "login successfully";
                    $token      = $usermodel->createToken('token')->accessToken;
                }
                break;
            default:

                $usermodel = null;
                $status = false;
                $code = 201;
                $message = "login failed";

                break;
        }
        $data = [];
        if($usermodel){
            $wallet  = Wallet::where('user_id',$usermodel->id)->first();
            if($wallet!=null){
                $data['referal_code']   = $usermodel->referal_code;
                $data['name']           = $usermodel->name;
                $data['email']          = $usermodel->email;
                $data['profile_image']  = isset($usermodel->profile_image)?$usermodel->profile_image:"https://image";
                $data['user_id']        = $usermodel->user_name;

                $data['mobile_number']  = $usermodel->mobile_number??$request->mobile_number;
                $data['otpverified']    = $usermodel->is_account_verified?true:false;
                $data['team_name']      = $usermodel->team_name??null;
            }

            $devD = \DB::table('hardware_infos')->where('user_id',$usermodel->id)->first();

            if($devD){
                $deviceDetails = json_encode($request->deviceDetails);
                \DB::table('hardware_infos')->where('user_id',$devD->user_id)->update([
                    'user_id' => $usermodel->id??0,
                    'device_details' => $deviceDetails
                ]);

                \DB::table('users')->where('email',$request->email)->update([
                    'device_id'=>$request->device_id
                ]);

            }else{
                $deviceDetails = json_encode($request->deviceDetails);
                \DB::table('hardware_infos')->insert([
                    'user_id' => $usermodel->id??0,
                    'device_details' => $deviceDetails
                ]);
            }
            \DB::table('users')->where('id',$usermodel->id)->update([
                'login_status' => true,
                'device_id' => $request->device_id
            ]);
        }
        if($usermodel){
            $token  = $usermodel->createToken('token')->accessToken;
        }
        $apk_updates = \DB::table('apk_updates')->orderBy('id','desc')->first();
        $data['apk_url'] =  'https://sportsfight.in/apk'??$apk_updates->url;
        //
      //  $data['pmid']    =  env('paytm_mid','tpJmKe81092739039978');
        $data['pmid']    =  'kroy';

        $data['call_url']   =  'https://sportsfight.in/api/v2/paymentCallback?ORDER_ID=';
        $data['g_pay'] =  'sportsfight.in-1@okaxis';

        if($data){
            $server = [
                'USER_DEVICE_IP' => $_SERVER['HTTP_X_FORWARDED_FOR']??null,
                'COUNTRY_CODE' => $_SERVER['HTTP_CF_IPCOUNTRY']??null,
                'SERVER_ADDR' => $_SERVER['SERVER_ADDR']??null,
                'SERVER_NAME' => $_SERVER['SERVER_NAME']??null,
                'SERVER_ADDR' => $_SERVER['SERVER_ADDR']??null,
                'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR']??null,
                'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD']??null,
                'HTTP_USER_AGENT' => $_SERVER['HTTP_USER_AGENT']??null,
                'HTTP_HOST' => $_SERVER['HTTP_HOST']??null,
                'user_id' => $usermodel->id??null

            ];

            $user_id = $data['user_id']??null;
            $user_agents = \DB::table('user_agents')
                ->updateOrInsert(['user_id'=>$user_id],$server);


         // $user_id = $this->generateUserName();
          //  $data['user_id'] = $user_id;
          //  $usermodel->user_name = $user_id;
          //  $usermodel->save();

            return response()->json([
                'pmid'    =>  env('paytm_mid','xmHOCa32667710380797'),
                'call_url'   =>  'https://sportsfight.in/api/v2/paymentCallback?ORDER_ID=',
                'g_pay' =>  'sportsfight.in-1@okaxis',
                "status"=>$status,
                "is_account_verified" => $usermodel->is_account_verified??0,
                "code"=>$code,
                "message"=> $message ,
                'data'=> $data,
                'token' => $token??Hash::make(1)
            ]);
        }else{
            return response()->json([
                'pmid'          =>  env('paytm_mid','xmHOCa32667710380797'),
                'call_url'      =>  'https://sportsfight.in/api/v2/paymentCallback?ORDER_ID=',
                'g_pay'         =>  'sportsfight.in-1@okaxis',
                "status"        =>  $status,
                "is_account_verified" => 0,
                "code"          => $code,
                "message"       => 'Invalid email or password',
                'token'         => $token??Hash::make(1)
            ]);
        }
    }

    /* @method : Email Verification
     * @param : token_id
     * Response : jsonṭ
     * Return :token and email
     */
    public function forgotPassword(Request $request)
    {
        $email = $request->input('email');
        //Server side valiation
        $validator = Validator::make($request->all(), [
            'email' => 'required|email'
        ]);

        $helper = new Helper;

        if ($validator->fails()) {
            $error_msg  =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'status' => 0,
                    'message' => $error_msg[0],
                    'data'  =>  ''
                )
            );
        }

        $user =   User::where('email',$email)->first();
        if($user==null){
            return Response::json(array(
                    'status' => false,
                    'code' => 201,
                    'message' => "Oh no! The address you provided isn't in our system",
                    'data'  =>  $request->all()
                )
            );
        }
        $user_data = $user;
        $enc = Crypt::encryptString($user->id);

        $links = url('api/v2/changePassword?token='.$enc);

        $email_content = array(
            'receipent_email'   => $request->input('email'),
            'subject'           => 'Your Sportsfight Account Password',
            'name'              => $user->first_name,
            'greeting'          => 'Sportsfight',
            'links'             => $links

        );
        $helper = new Helper;
        $email_response = $helper->sendNotificationMail(
            $email_content,
            'forgot_password_link'
        );

        return   response()->json(
            [
                "status"=>true,
                "code"=> 200,
                "message"=>"Reset password link has sent. Please check your email.",
                'data' => $request->all()
            ]
        );
    }

    public function changePassword(Request $request)
    {
        $token = $request->token;
        $pages = \DB::table('pages')->get(['title','slug']);
        View::share('static_page',$pages);

        $settings = \DB::table('settings')
                    ->pluck('field_value','field_key')
                    ->toArray();

        View::share('settings',(object)$settings);

        return view('changePassword',compact('token','pages'));
    }

    public function emailVerification(Request $request)
    {
        $verification_code = $request->input('verification_code');
        $email    = $request->input('email');

        if (Hash::check($email, $verification_code)) {
            $user = User::where('email',$email)->get()->count();
            if($user>0)
            {
                User::where('email',$email)->update(['status'=>1]);
            }else{
                echo "Verification link is Invalid or expire!"; exit();
                return response()->json([ "status"=>0,"message"=>"Verification link is Invalid!" ,'data' => '']);
            }
            echo "Email verified successfully."; exit();
            return response()->json([ "status"=>1,"message"=>"Email verified successfully." ,'data' => '']);
        }else{
            echo "Verification link is Invalid!"; exit();
            return response()->json([ "status"=>0,"message"=>"Verification link is invalid!" ,'data' => '']);
        }
    }
    public function mChangePassword(Request $request){

        $user_id =  $request->user_id;
        $current_password =  $request->current_password;
        $new_password = $request->new_password;

        $messages = [
            'user_id.required' => 'User id is required',
            'new_password.required' => 'New password is required',
            'current_password.required' => 'current password is required'

        ];

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'current_password' => 'required',
            'new_password' => 'required|min:6'
        ],$messages);

        $user = User::where('id',$user_id)->first();

        // Return Error Message
        if ($validator->fails() || $user ==null) {
            $error_msg =[];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]??'Opps! This user is not available'
                )
            );
        }

        $credentials = [
            'email'=>$user->email,
            'password'=>$current_password
        ];

        $auth = Auth::attempt($credentials);
        if($auth){
            $user->password = Hash::make($new_password);
            $user->save();
            return response()->json(
                [
                    "status"=>true,
                    'code'=>200,
                    "message"=>"Password changed successfully"
                ]);

        }else{
            return response()->json([ "status"=>false,'code'=>201,"message"=>"Old password do not match. Try again!"]);

        }
    }
    public function resetPassword(Request $request){

        $user_id =  $request->user_id;
        $old_password =  $request->old_password;
        $current_password =  $request->new_password;

        $messages = [
            'user_id.required' => 'User id is required',
            'old_password.required' => 'Old password is required',
            'new_password.required' => 'New password is required'

        ];
        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'old_password' => 'required',
            'new_password' => 'required|min:6'
        ],$messages);

        $user = User::where('id',$user_id)->first();

        // Return Error Message
        if ($validator->fails() || $user ==null) {
            $error_msg =[];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }
            return Response::json(array(
                    'status' => false,
                    "code"=> 201,
                    'message' => $error_msg[0]??'Opps! This user is not available'
                )
            );
        }

        $credentials = [
            'email'=>$user->email,
            'password'=>$old_password
        ];

        $auth = Auth::attempt($credentials);
        if($auth){
            $user->password = Hash::make($current_password);
            $user->save();
            return response()->json(
                [
                    "status"=>true,
                    'code'=>200,
                    "message"=>"Password reset successfully"
                ]);

        }else{
            return response()->json([ "status"=>false,'code'=>201,"message"=>"Old password do not match. Try again!"]);

        }
    }
    public function temporaryPassword(Request $request){

        $user_id =  $request->user_id;
        $user = User::where('id',$user_id)->first();
        if($user){
            return Response()->json([ "status"=>true,'code'=>200,"message"=>"Temporary Password sent"]);

        }else{
            return response()->json([ "status"=>false,'code'=>201,"message"=>"Email does not exist!"]);
        }
    }

    public function logout(Request $request){
        $user_id =  User::find($request->user_id);
        if($user_id){
            $user_id->login_status = false;
            $user_id->save();
            return response()->json([ "status"=>true,'code'=>200,"message"=>"Logout successfully"]);
        }else{
            return response()->json([ "status"=>false,'code'=>201,"message"=>"User does not"]);
        }
    }
    public function deviceNotification(Request $request){

        $user_id =  User::find($request->user_id);
        $device_id = $request->device_id;

        $validator = Validator::make($request->all(), [
            'user_id' => 'required',
            'device_id' => 'required'
        ]);
        /** Return Error Message **/
        if ($validator->fails()) {
            $error_msg      =   [];
            foreach ( $validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'status' => false,
                    'code'=>201,
                    'message' => $error_msg[0]
                )
            );
        }

        if($user_id){
            $user_id->device_id = $device_id;
            $user_id->save();
            return response()->json([ "status"=>true,'code'=>200,"message"=>"notification updated"]);
        }else{
            return response()->json([ "status"=>false,'code'=>201,"message"=>"something went wrong"]);
        }
    }

    public function sendNotification($token, $data){

        $serverLKey = 'AIzaSyAFIO8uE_q7vdcmymsxwmXf-olotQmOCgE';
        $fcmUrl = 'https://fcm.googleapis.com/fcm/send';

        $extraNotificationData = $data;

        $fcmNotification = [
            //'registration_ids' => $tokenList, //multple token array
            'to' => $token, //single token
            //'notification' => $notification,
            'data' => $extraNotificationData
        ];

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
    public function generateOtp(Request $request){
        $rs = $request->all();
        //dd($rs);
        $validator = Validator::make($request->all(), [
            'user_id' => "required"
        ]);

        if ($validator->fails()) {
            $error_msg = [];
            foreach ($validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'status' => false,
                    'code' => 201,
                    'message' => $error_msg[0],
                    'data' => count($request->all())?$request->all():null
                )
            );
        }

        $otp = mt_rand(1000, 9999);

        $data['otp'] = $otp;
        $user = User::find($request->get('user_id'));

        if($user){
            $data['mobile'] = $request->mobile_number??$user->mobile_number;
            $request->merge(['mobile_number' => $request->mobile_number??$user->mobile_number]);
        }else{
            $data['mobile'] = $request->get('mobile_number');
        }

        $data['user_id'] = $request->get('user_id');
        $data['timezone'] = config('app.timezone');

        \DB::table('mobile_otp')->insert($data);

        $data['email'] = $user->email??$request->get('email');

        $urlencode = urldecode("Your verification \n OTP is : ".$otp."\n Notes: Sportsfight never calls you asking for OTP.");

        if($request->mobile_number){
            $this->sendSMS($request->mobile_number,$urlencode);
        }

       // $this->sendOtpOverEmail($user,$otp);

        return response()->json(
            [
                "status"    =>  count($data)?true:false,
                'code'      =>  count($data)?200:201,
                "message"   =>  count($data)?"Otp generated and sent":"Something went wrong",
                'data'      =>  $data
            ]
        );
    }
    public function sendOtpOverEmail($user=null,$otp=null){

        if($user){
            $email_content = [
                'receipent_email'=> $user->email,
                'subject'=> 'Sportsfight: Otp Verification',
                'receipent_name'=> $user->name,
                'sender_name'=>'Sportsfight',
                'data' => 'Welcome! <br><br>Your verification Otp is : <br><b>'.$otp.'</b>'
            ];

            $helper = new Helper;
            $helper->sendNotificationMail($email_content, 'mail');
            return true;
        }else{
            return false;
        }
    }
    public function verifyOtp(Request $request){
        $rs = $request->all();
        $validator = Validator::make($request->all(), [
            'otp' => "required",
            'user_id' => 'required'
        ]);

        if ($validator->fails()) {
            $error_msg = [];
            foreach ($validator->messages()->all() as $key => $value) {
                array_push($error_msg, $value);
            }

            return Response::json(array(
                    'status' => false,
                    'code' => 201,
                    'message' => $error_msg[0],
                    'data' => $request->all()
                )
            );
        }


        $data = \DB::table('mobile_otp')
            ->where('otp',$request->get('otp'))
            ->where('user_id',$request->get('user_id'))->first();
        if($data){
            \DB::table('mobile_otp')
                ->where('otp',$request->get('otp'))
                ->where('user_id',$request->get('user_id'))->update(['is_verified'=>1]);
            \DB::table('referral_codes')
                ->where('user_id',$request->get('user_id'))
                ->update(['is_verified'=>1,'referral_amount'=>$this->referral_bonus]);

            if($data->mobile){
                \DB::table('users')
                    ->where('id',$request->get('user_id'))
                    ->update(['is_account_verified'=>1]);
            }
        }
        return response()->json(
            [
                "status"    =>  ($data!=null)?true:false,
                'code'      =>  ($data!=null)?200:201,
                "message"   =>  ($data!=null)?"Otp Verified":"Invalid Otp",
                'data'      =>  $request->all()
            ]
        );
    }
    /*get profile*/
    public function getProfile(Request $request){

        $user = User::select('id as user_id','name','email','referal_code','profile_image','mobile_number','city','gender','dateOfBirth','team_name','user_name')
        ->find($request->user_id);

        if($user){
            $user->user_id = $user->user_name;
            $status = true;
            $code = 200;
            $message = "Record found";
            $data = $user;

        }else{
            $status = false;
            $code = 201;
            $message = "Record not found";
            $data = null;
        }

        return response()->json(
            [
                "status"    =>  $status,
                'code'      => $code,
                "message"   =>  $message,
                'data'      =>  $data
            ]
        );
    }





    public function sendSMS($mobileNumber=null,$message=null)
    {

        $url = $this->smsUrl;
        $recipients[] = $mobileNumber;
        $text =  $message;

        $param = array(
            'username' => 'infoway',
            'password' => 'iwapi@!2020',
            'senderid' => 'INFOWA',
            'text' => $text,
            'route' => 'Informative',
            'type' => 'text',
            'datetime' => date('Y-m-d H:i:s'),
            'to' => implode(';', $recipients),
        );
        //dd($param);
        $post = http_build_query($param, '', '&');
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array("Connection: close"));
        $result = curl_exec($ch);
        if(curl_errno($ch)) {
            $result = "cURL ERROR: " . curl_errno($ch) . " " . curl_error($ch);
        } else {
            $returnCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            switch($returnCode) {
                case 200 :
                    break;
                default :
                    $result = "HTTP ERROR: " . $returnCode;
            }
        }
        curl_close($ch);
        return true;
    }


//added by nishit

      public function getAllAccounts(Request $request){

         $account_type = $request->account_type;

         $userDetail = User::where('customer_type',$account_type)->orderBy('id','desc')->get();


        $myRoboList = array();

        foreach ($userDetail as  $result) {

            $data = array();
            $data['robot_id'] = $result->user_name;
            $data['robot_full_name'] = $result->name;
            $data['robot_email'] = $result->email;
            $data['robot_team_name'] = $result->team_name;
            $data['robot_mobile'] = $result->mobile_number;
            $data['robot_total_matches'] = "0";
            $data['robot_total_match_win'] = "0";
            $data['robot_total_match_loss'] = "0";


            array_push($myRoboList,$data);
        }


      return response()->json(
            [
                "status"    =>  true,
                'code'      =>  404,
                "message"   =>  "all user name listing",
                'fake_users'      =>  $myRoboList
            ]
        );
    }




      public function getUserByUserID(Request $request){


         $userid = $request->userid;



          $userDetail = User::where('id',$userid)->get();


        return response()->json(
            [
                "status"    =>  true,
                'code'      =>  404,
                "message"   =>  "user list by id",
                'data'      =>  $userDetail
            ]
        );
    }

    public function getAllMatchesByDate(Request $request){

          $date = $request->date;

          $match_type = $request->match_type;


        $completedMatches = Matches::select('match_id','short_title','status_str','created_at')->where('created_at','>=',$date)->where('status_str',$match_type)->get();




             return response()->json(
            [
                "status"    =>  true,
                'code'      =>  202,
                "message"   =>  "matches listing",
                'data'      =>  $completedMatches
            ]
        );
    }


     public function getCancelContest(Request $request){

            $contest_id = $request->contest_id;
            $contest_type = $request->contest_type;
            $match_id = $request->match_id;


            $cancelcontest = CreateContest::where('default_contest_id',$contest_id)->where('contest_type',$contest_type)->where('match_id',$match_id)->update(array('is_cancelled'=> 1));


            return response()->json(
            [
                "status"    =>  true,
                'code'      =>  202,
                "message"   =>  "contest listing",
                'data'      =>  $cancelcontest
            ]
        );

     }


     public function getUpdateMatchTime(Request $request){


            $match_id = $request->match_id;
            $date_start = $request->date_start;
            $date_end = $request->date_end;


            $updateMatchTime = Matches::where('match_id',$match_id)->update(array('date_start'=> $date_start,'date_end' => $date_end,'timestamp_start' => strtotime($date_start), 'timestamp_end' => strtotime($date_end)));


            return response()->json(
            [
                "status"    =>  true,
                'code'      =>  202,
                "message"   =>  "update match listing",
                'data'      =>  $updateMatchTime
            ]
        );

     }


     public function prizeDistributionMobile(Request $request)
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
            $item->user     = $user;
            $item->team_id  = $team_id;
            $item->match_id = $match_id;
            $item->user_id  = $user_id;
            $item->rank     = $rank;
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
            return  Redirect::to(route('match','prize=true'));
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
                                'payment_type_string' => 'prize',
                                'amount'        =>  $prize_amount,
                                'prize_amount'  =>  $prize_amount,
                                'prize_distributed_id' => $item->id
                            ]
                        );

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
                                'payment_mode'      =>  'sf',
                                'payment_details'   =>  json_encode($item),
                                'payment_status'    =>  'success',
                                'match_id'          =>  $item->match_id,
                                'contest_id'        =>  $item->contest_id,
                                'transaction_id'    =>  $item->match_id.'S'.$item->contest_id.'F'.$item->user_id
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

        return  Redirect::to(route('match','prize=true'));

     }

      public function getWalletTransactionByUserID(Request $request){


         $account_type = $request->account_type;
         $userid = $request->userid;



         $walletDetails = Wallet::select(\DB::raw("sum(wallets.amount) as Transactions"))->whereIn('payment_type', array(3, 4))->where('user_id',$userid)->get();

        return response()->json(
            [
                "status"    =>  true,
                'code'      =>  404,
                "message"   =>  "transaction listing",
                'data'      =>  $walletDetails
            ]
        );
    }

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
            $createContest->usable_bonus        =   $result->usable_bonus;
            $createContest->prize_percentage    =   $result->prize_percentage;

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
            });


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


      public function createDefaultContest(Request $request)
    {

        $defaultContest = new DefaultContest;


        $defaultContest->cancellation = $request->cancellation?true:false;
        $defaultContest->bonus_contest = $request->bonus_contest?true:false;
        $defaultContest->usable_bonus = $request->usable_bonus;
        $defaultContest->contest_type = $request->contest_type;
        $defaultContest->entry_fees = $request->entry_fees;
        $defaultContest->total_spots = $request->total_spots;
        $defaultContest->first_prize = $request->first_prize;
        $defaultContest->prize_percentage = $request->prize_percentage;
        $defaultContest->winner_percentage = $request->winner_percentage;
        $defaultContest->total_winning_prize = $request->total_winning_prize;
        $defaultContest->match_id = $request->match_id;
        $defaultContest->is_free = $request->is_free;

        $defaultContest->save();
        echo "hi"; die;


        $default_contest_id = $defaultContest->id;


        if($request->match_id){
            $match  = Matches::where('match_id',$request->match_id)->get('match_id');
            \DB::table('matches')->where('match_id',$request->match_id)->update(['is_free'=>$request->is_free]);

        }else{
            $match  = Matches::where('status',1)->get('match_id');
        }


        $sort_by = \DB::table('contest_types')->where('id',$request->contest_type)->first()->sort_by??0;

        // $defaultContest->merge(['sort_by'=>$sort_by]);

        // $defaultContest->merge(['filled_spot' => 0]);



        foreach ($match as $key => $result) {

            // $defaultContest->merge(['match_id' => $result->match_id]);

            // $defaultContest->merge(['default_contest_id' => $default_contest_id]);


            $data = array();
                $data['cancellation'] = $defaultContest->cancellation;
                $data['bonus_contest'] = $defaultContest->bonus_contest;
                $data['usable_bonus'] = $defaultContest->usable_bonus;
                $data['contest_type'] = $defaultContest->contest_type;
                $data['entry_fees'] = $defaultContest->entry_fees;
                $data['total_spots'] = $defaultContest->total_spots;
                $data['first_prize'] = $defaultContest->first_prize;
                $data['prize_percentage'] = $defaultContest->prize_percentage;
                $data['winner_percentage'] = $defaultContest->winner_percentage;
                $data['total_winning_prize'] = $defaultContest->total_winning_prize;
                $data['match_id'] = $result->match_id;
                $data['is_free'] = $defaultContest->is_free;

            //print_r($defaultContest);
            \DB::table('create_contests')->insert($data);



        }



          return response()->json(
            [
                "status"    =>  true,
                'code'      =>  404,
                "message"   =>  "contest created successfully",
                'data'      =>  "data added"
            ]
        );

    }




     public function deleteContest(Request $request) {

        $id = $request->id;

        DefaultContest::where('id',$id)->delete();
        $contest = \DB::table('create_contests')
                    ->where('default_contest_id',$id)
                    ->where('filled_spot',0)
                    ->update([
                        'deleted_at'=>date('Y-m-d h:i'),
                        'is_cancelled'=>1
                      ]);

        return response()->json(
            [
                "status"    =>  true,
                'code'      =>  404,
                "message"   =>  "contest deleted successfully",
                'data'      =>  "data deleted"
            ]
        );
    }

    public function getDocuments(Request $request) {

        $status = $request->status;
        $notes = $request->notes;

        $getDocuments = \DB::table('verify_documents')->where('status',$status)->where('notes',$notes)->get();



        return response()->json(
            [
                "status"    =>  true,
                'code'      =>  404,
                "message"   =>  "all documents listing",
                'data'      =>  $getDocuments
            ]
        );
    }






    public function prizeBreakup(Request $request){



        $contest = DefaultContest::where('id',$request->id)->get();


             foreach ($contest as $key => $result) {

                $prizeBreakup = new PrizeBreakup;

                $prizeBreakup->default_contest_id = $result->id;

                $prizeBreakup->contest_type_id = $result->contest_type;

                $prizeBreakup->rank_from = $request->rank_from;
                $prizeBreakup->rank_upto = $request->rank_upto;
                $prizeBreakup->prize_amount = $request->prize_amount;

                $prizeBreakup->save();

        }

      return response()->json(
            [
                "status"    =>  true,
                'code'      =>  202,
                "message"   =>  "prize breakup created",
                'response'      =>   $prizeBreakup
            ]
        );


    }


     public function deletePrizeBreakup(Request $request){

         $default_contest_id = $request->default_contest_id;
         $rank_from = $request->rank_from;
         $rank_upto = $request->rank_upto;

         PrizeBreakup::where('default_contest_id',$default_contest_id)->where('rank_from',$rank_from)->where('rank_upto',$rank_upto)->delete();




        return [
            'status'=>true,
            'code' => 200,
            'message' => 'Prize Breakup',
            'response' => "prize breakup deleted"
        ];

    }


       public function updatePrizeBreakup(Request $request){


         $default_contest_id = $request->default_contest_id;
         $rank_from = $request->rank_from;
         $rank_upto = $request->rank_upto;
         $rank_from_update = $request->rank_from_update;
         $rank_upto_update = $request->rank_upto_update;
         $prize_amount_update = $request->prize_amount_update;




            $updatePrizeBreakup = PrizeBreakup::where('default_contest_id',$default_contest_id)->where('rank_from',$rank_from)->where('rank_upto',$rank_upto)->update(array('rank_from'=> $rank_from_update, 'rank_upto' => $rank_upto_update, 'prize_amount' => $prize_amount_update));

            $updatedPrizeBreakup = PrizeBreakup::where('default_contest_id',$default_contest_id)->where('rank_from',$rank_from_update)->where('rank_upto',$rank_upto_update)->get();



            return response()->json(
            [
                "status"    =>  true,
                'code'      =>  202,
                "message"   =>  "prize breakup update",
                'response'      =>   $updatedPrizeBreakup
            ]
        );
    }

     public function AutoBot(Request $request){



        $match_id = $request->match_id;
        $playing11a  =\DB::table('team_a_squads')
                ->where('match_id',$match_id)
                ->select('playing11','role','player_id','id')
                ->get();

        $playing11b  =\DB::table('team_b_squads')
                ->where('match_id',$match_id)
                 ->select('playing11','role','player_id','id')

                ->get();

       $playing11List = $playing11a->merge($playing11b);

       $wk = [];
       $bat = [];
       $ar = [];
       $bowl = [];



       foreach ($playing11List as $key => $result) {

                $actionType = $result->role;
                $playing11 = $result->playing11;
                $player_id = $result->player_id;
                $id1 = $result->id;

                switch ($actionType){

                     case 'wk':

                                 //$array = [$is_playing];
                                 //$wkSelection = Arr::random($wk);

                                 if($actionType=='wk') {

                                    for($i=0;$i<=$player_id->count();$i++){

                                     $wk[$i] =['player_id' => $player_id];
                                     if(($wk[$i]) && ($playing11=='false')){
                                        $wkSelect = checkWk($match_id);

                                        $create_teams = CreateTeam::select('captain','vice_captain','trump')
                                                      ->where('match_id',$match_id)
                                                      ->get();
                                        if(($wk[$i]) == ($create_teams->captain)){
                                            $create_teams->captain = $wkSelect;
                                        }

                                        elseif(($wk[$i]) == ($create_teams->trummp)){
                                            $create_teams->trump = $wkSelect;
                                        }
                                        elseif(($wk[$i]) == ($create_teams->vice_captain)){
                                            $create_teams->vice_captain = $wkSelect;
                                        }
                                        else{
                                            continue;
                                        }
                                     }
                                     else{
                                        continue;
                                     }

                                   }
                               }

                             break;

                     case 'bat':

                             if($actionType=='bat') {

                                    for($i=0;$i<=$player_id->count();$i++){

                                     $bat[$i] =['player_id' => $player_id];
                                     if(($bat[$i]) && ($playing11=='false')){
                                        $batSelect = checkBat($match_id);

                                        $create_teams = CreateTeam::select('captain','vice_captain','trump')
                                                      ->where('match_id',$match_id)
                                                      ->get();
                                        if(($bat[$i]) == ($create_teams->captain)){
                                            $create_teams->captain = $batSelect;
                                        }

                                        elseif(($bat[$i]) == ($create_teams->trummp)){
                                            $create_teams->trump = $batSelect;
                                        }
                                        elseif(($bat[$i]) == ($create_teams->vice_captain)){
                                            $create_teams->vice_captain = $batSelect;
                                        }
                                        else{
                                            continue;
                                        }
                                     }
                                     else{
                                        continue;
                                     }

                                   }
                               }

                             break;

                    case 'ar':

                             if($actionType=='ar') {

                                    for($i=0;$i<=$player_id->count();$i++){

                                     $ar[$i] =['player_id' => $player_id];
                                     if(($ar[$i]) && ($playing11=='false')){
                                        $arSelect = checkAr($match_id);

                                        $create_teams = CreateTeam::select('captain','vice_captain','trump')
                                                      ->where('match_id',$match_id)
                                                      ->get();
                                        if(($ar[$i]) == ($create_teams->captain)){
                                            $create_teams->captain = $arSelect;
                                        }

                                        elseif(($ar[$i]) == ($create_teams->trummp)){
                                            $create_teams->trump = $arSelect;
                                        }
                                        elseif(($ar[$i]) == ($create_teams->vice_captain)){
                                            $create_teams->vice_captain = $arSelect;
                                        }
                                        else{
                                            continue;
                                        }
                                     }
                                     else{
                                        continue;
                                     }

                                   }
                               }

                             break;


                    case 'bowl':

                             if($actionType=='bowl') {

                                    for($i=0;$i<=$player_id->count();$i++){

                                     $bowl[$i] =['player_id' => $player_id];
                                     if(($bowl[$i]) && ($playing11=='false')){
                                        $bowlSelect = checkBowl($match_id);

                                        $create_teams = CreateTeam::select('captain','vice_captain','trump')
                                                      ->where('match_id',$match_id)
                                                      ->get();
                                        if(($bowl[$i]) == ($create_teams->captain)){
                                            $create_teams->captain = $bowlSelect;
                                        }

                                        elseif(($bowl[$i]) == ($create_teams->trummp)){
                                            $create_teams->trump = $bowlSelect;
                                        }
                                        elseif(($bowl[$i]) == ($create_teams->vice_captain)){
                                            $create_teams->vice_captain = $bowlSelect;
                                        }
                                        else{
                                            continue;
                                        }
                                     }
                                     else{
                                        continue;
                                     }

                                   }
                               }

                             break;

                      default:
                    // echo " switch case ended";
                     break;
                 }
        }

            // if($playing11List){
            //     foreach ($playing11List as $key => $value) {
            //         $this->updateAutoBotWK($match_id);
            //     }
            // }



            return response()->json(
            [
                "status"    =>  true,
                'code'      =>  202,
                "message"   =>  "autobot team update",
                'response'      => "autobot updated"
            ]
        );
    }

    // public function checkWk($match_id){

    //     $playing11a  =\DB::table('team_a_squads')
    //             ->where('match_id',$match_id)
    //             ->select('playing11','role','player_id','id')
    //             ->get();

    //     $playing11b  =\DB::table('team_b_squads')
    //             ->where('match_id',$match_id)
    //              ->select('playing11','role','player_id','id')

    //             ->get();

    //    $playing11List = $playing11a->merge($playing11b);
    //    $wicket_keeper[];

    //    foreach($playing11List as $key => $result){

    //      if(($result->playing11 == 'true') && ($result->role == 'wk')){

    //         $wicket_keeper[] =  ['wicket_keeper' => $result->player_id];

    //         $array = [$wicket_keeper];
    //         $wkSelection = Arr::random($array);
    //         return $wkSelection;


    //        }

    //    }

    // }

     public function getAllPayments(Request $request) {

        $status = $request->status;

        $getPayment = \DB::table('wallet_transactions')
            ->where('withdraw_status', $request->withdrawalStatus)
            ->get();


        $resultdata[] = array();
        foreach ($getPayment as  $result) {

            $data = array();
            $data['user_id'] = $result->user_id;
            $data['amount'] = $result->amount;
            $data['payment_type_string'] = $result->payment_type_string;
            $data['payment_status'] = $result->payment_status;

            //$userData = User::where('id', $result->user_id)->first();
            //$data['name'] = $userData->name;
            //$result[] = $data;
            array_push($resultdata,$data);
        }
         return response()->json(
                [
                    "status" => true,
                    'code' => 404,
                    "message" => "all documents listing",
                    'payment_data' =>  $resultdata
                ]
            );
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
            return $max_t;
            exit();
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
                        $wt->transaction_id = $match_id.'S'.$contest_id.'F'.$user_id;
                        $wt->payment_mode =  'sf';
                        $wt->payment_status =  'Success';
                        $wt->debit_credit_status = "-";
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
            $message = "Team created successfully!";
        }else{
            $cont = ["error"=>"contest id not found"];
            $message = "Something went wrong!";
        }
        return response()->json(
                [
                'session_expired'=>false,    
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

    /**
    * Description : Leaderboard data
    * @var match_is
    * @var user_id
    * @var content_id
    */
    public function leaderBoardFK(Request $request){
        // $join_contests = [];
        $match_id = $request->match_id;
        $join_contests = JoinContest::where('match_id',$request->get('match_id'))
            ->where('contest_id',$request->get('contest_id'))
            ->pluck('created_team_id')->toArray();

        $user_id = $request->user_id;

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
           // ->limit(100)
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

            $user_data =  $value->user->name;
            $fn = explode(" ",$user_data);

            $data['user'] = [
                'first_name'    => $value->user->first_name,
                'last_name'     => $value->user->last_name,
                'name'          => $value->user_name??$value->user->team_name,
                'user_name'     => $value->user_name??$value->user->team_name,
              //  'team_name'     => $value->team_name??$value->user->team_name??reset($fn),
                'team_name'     => $value->team_name??$value->user->team_name,
                'profile_image' => $value->user->profile_image,
                'short_name'    => $value->user->customer_type
            ];
            $lb[] = $data;
        }

        /*$leader_board2 = JoinContest::whereHas('user')
                        ->where('match_id',$request->match_id)
                        ->where('contest_id',$request->contest_id)
                        ->orderBy('ranks','ASC')
                        ->get();
        */
        foreach ($leader_board2 as $key => $value) {

            if(!isset($value->user)){
                continue;
            }

            $data['match_id'] = $value->match_id;
            $data['team_id'] = $value->created_team_id;
            $data['user_id'] = $value->user->user_name??$value->user->id;
            $data['team'] = $value->team_count;
            $data['point'] = $value->points;
            $data['rank'] = $value->ranks;
            $data['prize_amount'] =  $value->prize_amount??$value->winning_amount;
            $data['winning_amount'] = $value->winning_amount;
            $user_data =  $value->user->name;
            $fn = explode(" ",$user_data);    

            $data['user'] = [
                'first_name'    => reset($fn),
                'last_name'     => end($fn),
                'name'          => $value->user_name, //reset($fn).' '.end($fn),
                'user_name'     => $value->user_name??reset($fn),
                'team_name'     => $value->team_name??reset($fn),
                'profile_image' => isset($user_data)?$value->user->profile_image:null,
                'short_name'    => $value->user->customer_type
            ];
            $lb[] = $data;
        }
        $lb = $lb??null;

        //$match_info = $this->setMatchStatusTime($match_id);
      //return($lb);
        if($lb){
            return [
             //   'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                'status'=>true,
                'code' => 200,
                'message' => 'leaderBoard',
                'total_team' =>  count($lb),
                'leaderBoard' =>mb_convert_encoding($lb, 'UTF-8', 'UTF-8')

            ];
        }else{
            return [
                'system_time'=>time(),
                'match_status' => $match_info['match_status']??null,
                'match_time' => $match_info['match_time']??null,
                'status'=>false,
                'code' => 201,
                'message' => 'leaderBoard not available'
            ];
        }

    }  


     // get contest details by match id
   

     public function getContestByMatch(Request $request){
        $this->automateCreateContest();

        $match_id =  $request->match_id;
        $matchVald = Matches::where('match_id',$request->match_id)->first();
       
        if(!$matchVald){
            return [
                'system_time'=>time(),
                'status'=>false,
                'code' => 201,
                'message' => 'match id is invalid'

            ];
        }
        $ct = \DB::table('contest_types')
                ->orderBy('sort_by','asc')
                ->pluck('id')
                ->toArray();
                
        $contest = CreateContest::with('contestType')
            ->where('match_id',$match_id)
             ->where('is_cancelled',0)
            ->orderBy('sort_by','asc')
            ->whereIn('contest_type',$ct)
            ->orderBy('total_winning_prize','DESC')
            ->get();
    
       if($contest){
            $matchcontests = [];
            foreach ($contest as $key => $result) {
                if($result->total_spots <= $result->filled_spot && $result->total_spots!=0){
                   // continue;
                }
                //notification per
                $data2['contest_type_id'] =   $result->contest_type;
                $data2['isCancelled'] =   $result->is_cancelled?true:false;
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
                    
                    $twp = round(($result->filled_spot)*($result->entry_fees)*(0.7));
                    
                    $data2['firstPrice'] = $twp;

                    if($twp<$result->entry_fees){
                        $data2['firstPrice'] = $result->entry_fees;
                        if($result->filled_spot>1){
                            $prize = $result->entry_fees*($result->filled_spot-1);    
                        }else{
                            $prize = $result->entry_fees;
                        }  
                        $data2['totalWinningPrize'] = $prize;
                        $first_p = $prize;
                    }else{
                        $data2['totalWinningPrize'] = round(($result->filled_spot)*($result->entry_fees)*(0.7));
                        $first_p = round($twp*(0.2));    
                    }
                   // $data2['firstPrice'] =   $first_p;
                }
                elseif($result->total_spots!=0 && $result->filled_spot==$result->total_spots)
                {
                   // $this->automateCreateContest();
                    $u = ['285','262','395','379','388','3884'];
                    if(in_array($request->user_id, $u))
                    {

                    }else{
                        continue;
                    } 

                }
                $data2['contestId'] =    $result->id;

                $data2['entryFees'] =    $result->entry_fees;

                $data2['filledSpots'] =  $result->filled_spot;

                $data2['winnerPercentage'] = $result->winner_percentage;
                $data2['winnerCount'] = $result->winner_count??$result->prize_percentage;
                $data2['maxAllowedTeam'] =   $result->contestType->max_entries;
                
                $data2['cancellation'] = $result->cancellation?true:false;
                $matchcontests[$result->contest_type][] = [
                    'sort_by' => $result->sort_by,
                    'contestTitle'=>$result->contestType->contest_type,
                    'contestSubTitle'=>$result->contestType->description,
                    'contests'=>$data2,
                    'contest_type_id' =>   $result->contest_type
                
                ];
            }
            // $data = [];
            $data[0] = null;
            foreach ($matchcontests as $key => $value) {

                foreach ($value as $key2 => $value2) {
                    //$value2['contests']['sort_by']
                    $k['contest_type_id'] = $value2['contest_type_id'];
                    $k['contestTitle'] = $value2['contestTitle'];
                    $k['contestSubTitle'] = $value2['contestSubTitle'];
                    $k['contests'][] = $value2['contests'];
                }
                $data[] = $k;
                if(isset($k['contestTitle']) && $k['contestTitle']=='Practise Contest'){
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
          //  dd($match_info);
            return response()->json(
                [
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


    /*
    Automate Create Contest
    contest will create as its full
    */
    public function automateCreateContest(){
        //return false;
        $contest = CreateContest::whereColumn('total_spots','filled_spot')
            ->where('cancellation',1)
            ->where('is_cloned',0)
            ->where('total_spots','>',0)
            ->get();
        //->where('entry_fees','>',0)
        $match_id = $contest->pluck('match_id')->toArray();
        $match = Matches::whereIn('match_id',$match_id)->get(['match_id']);

        $match->transform(function($item,$key)use($contest){
            $contest_copy = $contest->where('match_id',$item->match_id)->first();

            $contest_copy->is_cloned = 1;
            $contest_copy->save();
            $contest_copy = $contest_copy->toArray();
            $contest_copy['filled_spot'] = 0;
            $contest_copy['is_cloned'] = 0;
            $contest_copy['is_full'] = 0;
            $contest_copy['is_free'] = 0;
            \DB::table('create_contests')->insert($contest_copy);
            $item->contest = $contest_copy;
            return $item;
        });
        return "Contest cloned";          

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

     
    public function setMatchStatusTime($match_id=null){
        $match = Matches::where('match_id',$match_id)->first();

        if($match){
            $arr['match_status'] = $match->status_str;
            $arr['match_time'] = $match->timestamp_start;

            return $arr;
        }else{

            $arr['match_status'] = null;
            $arr['match_time'] = null;

            return $arr;

        }
    }

}