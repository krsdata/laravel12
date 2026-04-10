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
use Crypt,okie,Hash,Lang,JWTAuth,Input,Closure,URL;
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
use Modules\Admin\Models\Program;
use Illuminate\Support\Str;
use Kreait\Laravel\Firebase\Facades\Firebase;
use Kreait\Firebase\Auth as FirebaseAuth;
use Firebase\Auth\Token\Exception\InvalidToken;


class UserController extends BaseController
{
    public $download_link;
    public $referral_bonus;
    public $signup_bonus;
    public $smsUrl;
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
        $program  =\DB::table('programms')->get()
            ->transform(function($item, $key){

                if($item->promotion_type==1)
                {
                    $item->referral = true;
                    $item->bonus = false;
                }
                if($item->promotion_type==2)
                {
                    $item->referral = false;
                    $item->bonus = true;
                }
                if($item->trigger_condition==1)
                {
                    $item->signup = true;
                }else{
                    $item->signup = false;
                }

                return $item;
            });
        $signup_bonus = $program->where('bonus',true)->first();
        $referral_bonus = $program->where('referral',true)->first();

        $this->referral_bonus = $referral_bonus->amount??100;
        $this->signup_bonus = $signup_bonus->amount??100;

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
            $wallet_trns['payment_mode']    = "ninja11";
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
            $wallet_trns['payment_mode']    = "ninja11";
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

        $token = $user->createToken('ninja11')->accessToken;
        $user_data['referal_code']     =  $user->user_name;
        $user_data['user_id']          =  $user->id;
        $user_data['name']             =  $user->name;
        $user_data['email']            =  $user->email;
        $user_data['bonus_amount']     =  (float)$wallet->bonus_amount;
        $user_data['usable_amount']    =  (float)$wallet->usable_amount;
        $user_data['mobile_number']    =  ($user->phone==null)?$user->mobile_number:$user->phone;

        $subject = "Welcome to ninja11! Verify your email address to get started";
        $email_content = [
            'receipent_email'=> $request->input('email'),
            'subject'=>     $subject,
            'greeting'=>    'ninja11',
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
            $wallet_trns['payment_mode']    = "ninja11";
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


        try {
            $decrypted = Crypt::decryptString($request->system_token);

            $user = User::find($request->user_id);
            // if failed
            if($user->user_name!=$decrypted)
            {   
                return response()->json(
                [
                        "status"=>false,
                        "code"=>201,
                       'message' => "Wrong attempt. Account will be block after 3 unsuccess attempt!"
                    ]
                );
            }
            
        } catch (DecryptException $e) {
             return Response::json(array(
                    'code' => 201,
                    'status' => false,
                    'message' => "Wrong attempt. Account will be block after 3 unsuccess attempt!"
                )
            );
        }

        $myArr = [];
        
        if($user->team_name==$request->team_name){
            $validator = Validator::make($request->all(), [
                'user_id' => 'required'
            ]);
        }else{
            $validator = Validator::make($request->all(), [
                'user_id' => 'required',
                'team_name' => 'unique:users'
            ]);
        }


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
            $user->city = $request->city;
            $user->dateOfBirth = $request->dateOfBirth;
            $user->gender = $request->gender;
            $user->name = $request->name;
            $user->profile_image = $request->image_url;
            $user->pass_code = $request->pass_code;
            if($request->team_name){
                $user->team_name = $request->team_name;
            }

          //  $user->all = json_encode($request->all());
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
        $t = time();
        $refer_by = User::where('referal_code',$request->referral_code)
                    ->where('block_referral',0)
                    ->first();
        if($refer_by){
            $ref = $request->referral_code;
        }else{
            $refer_by = User::where('referal_code','NINJA11')
                    ->where('block_referral',0)
                    ->first();
            $ref= "NINJA11";
        }

        if($refer_by && $user)
        {
            $referralCode = new ReferralCode;
            $referralCode->referral_code    =   $ref??null; //$request->referral_code;
            $referralCode->user_id          =   $user->id;
            $referralCode->refer_by         =   $refer_by->id;
            $referralCode->referral_amount  =   $this->referral_bonus;
            $referralCode->save();

            // deposit and get referral
           /* \DB::table('referral_cash')->insert(
                [
                    'referral_user_id' => $user->id,
                    'refer_by'         => $refer_by->id,
                    'amount'           => 20
                ]
            );*/


            $wallet_trns['user_id']         =  $refer_by->id??null;
            $wallet_trns['amount']          =  100; //$this->referral_bonus;
            $wallet_trns['payment_type']    =  2;
            $wallet_trns['payment_type_string'] = "Referral Bonus";
            $wallet_trns['transaction_id']  = time().'-'.$refer_by->id??null;
            $wallet_trns['payment_mode']    = "ninja11";
            $wallet_trns['payment_status']  = "success";

            $wallet_transactions = WalletTransaction::create(
                $wallet_trns
            );

            $wallet = Wallet::firstOrNew(
                [
                    'payment_type'  => 1,
                    'user_id'       => $refer_by->id
                ]
            );
            $wallet->user_id        = $refer_by->id;
            $wallet->validate_user  = Hash::make($refer_by->id);
            $wallet->payment_type   = 1 ;
            $wallet->payment_type_string = "Referral Bonus";
            $wallet->amount     = ($wallet->amount)+100;

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

    public function referral_code($request,$user=null){
        $t = time();
        $refer_by = User::where('referal_code',$request->referral_code)
                    ->where('block_referral',0)
                    ->first();
        if($refer_by){
            $ref = $request->referral_code;
        }else{
            $refer_by = User::where('referal_code','NINJA11')
                    ->where('block_referral',0)
                    ->first();
            $ref= "NINJA11";
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
            $wallet_trns['payment_mode']    = "ninja11";
            $wallet_trns['payment_details'] = json_encode($wallet_trns);
            $wallet_trns['payment_status']  = "success";

            $wallet_transactions = WalletTransaction::create(
                $wallet_trns
            );

            $wallet = Wallet::firstOrNew(
                [
                    'payment_type'  => 1,
                    'user_id'       => $refer_by->id
                ]
            );
            $wallet->user_id        = $refer_by->id;
            $wallet->validate_user  = Hash::make($refer_by->id);
            $wallet->payment_type   = 1 ;
            $wallet->payment_type_string = "Referral Extra Cash";
            $wallet->extra_cash     = ($wallet->extra_cash)+$this->referral_bonus;

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
    // Login
    public function login(Request $request)
    {   

        $ip     = $_SERVER['REMOTE_ADDR'];
        $url    = '';
        $state  = null;
 
        $verifyGmail['code'] =1;// $this->verifyGmail($request);
        
        if($verifyGmail['code']==1001){
            // return array(
            //         'status' => false,
            //         'code' => 420,
            //         'message' => 'Unauthorise access!'
            //     );
        }else{ 
            $request->merge(['email'=>$verifyGmail['email']??$request->email]);            
            $request->merge(['user_type'=>'googleAuth']);
            $request->merge(['provider_id'=>$verifyGmail['uid']??'']);
        }
        $data   = [];
        $input  = $request->all();
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

       // dd($request->all());

        $user_type = $request->user_type;
        switch ($user_type) {
            case 'googleAuth':
                $credentials = [
                    'email'=>$request->get('email'),
                    'user_type' => 'googleAuth'
                ];
                $user = User::where('email',$request->email)->first();
                if($user){
                    $wal = Wallet::where('user_id',$user->id)->count();
                    if($wal==0){
                        User::where('email',$request->email)->delete();
                    }    
                }
              //  $data['name']       = $user->name??$request->name;
                $user = User::where('email',$request->email)->first();
                if($user){

                    if($user->status==0){
                        return array(
                            'status' => false,
                            'code' => 420,
                            'message' => 'Your Account is disabled.To activate write an email at '.env('company_email')
                            );
                    }

                    $data['name']       = $user->name??$request->name;
                    $data['email']      = $user->email??$request->email;
                    $data['user_id']    = $user->user_name;
                    $data['team_name']  = $user->team_name??$request->team_name;
                    $data['state']      = $state??''; 
                    $data['mobile_number'] = $request->mobile_number;
                    $data['otpverified'] = true; 
                     $usermodel = User::where('email',$request->email)->first();
                    $usermodel->name = $user->name??$request->name;
                    
                   if($request->mobile_number){
                        $usermodel->mobile_number = $request->mobile_number;
                   }
                   elseif($usermodel->mobile_number){
                        $usermodel->mobile_number = $usermodel->mobile_number;
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
                    $user->profile_image = $request->image_url;
                   // $usermodel->otpverified = true;
                    $usermodel->provider_id = $request->provider_id; 
                    if($usermodel->profile_image==null){
                        $usermodel->profile_image = $verifyGmail['picture']??''; 
                    }
                    $usermodel->state = $state;
                    $usermodel->save();
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
                        exit();
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
                    $user->profile_image = $verifyGmail['picture']??'';
                    $user->state =  $state??'';

                    if($request->referral_code){
                        $referral_code_count = User::where('referal_code',$request->referral_code)->count();

                        if($referral_code_count){
                            $this->signup_bonus = 100;
                        }

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

                //    $send_status = $helper->notifyToAdmin('New Registration',$msg);
                    
                    $request->merge(['user_id'=>$user->id,'mobile_number'=>$user->mobile_number]);
                    
                    if(isset($user) && $user->id){
                        
                        $devid = User::where('device_id',$request->device_id)->count();
                        if($devid==0){
                            $this->saveReferral($request,$user);    
                        }else{
                            $request->merge(['referral_code'=>'NINJA11']);
                            $this->saveReferral($request,$user);
                        }
                            
                        $wallet = new Wallet;
                        $wallet->user_id = $user->id;
                        $wallet->validate_user = Hash::make($user->id);
                        $wallet->payment_type  =  1;
                        $wallet->payment_type_string = "Bonus";
                        $wallet->amount         = $this->signup_bonus;
                        $wallet->save();


                        $wallet = new Wallet;
                        $wallet->user_id = $user->id;
                        $wallet->validate_user = Hash::make($user->id);
                        $wallet->payment_type  =  3;
                        $wallet->payment_type_string = "Deposit";
                        $wallet->amount         = 0;
                        $wallet->save();


                        $wallet = new Wallet;
                        $wallet->user_id = $user->id;
                        $wallet->validate_user = Hash::make($user->id);
                        $wallet->payment_type  =  4;
                        $wallet->payment_type_string = "Prize";
                        $wallet->amount         = 0;
                        $wallet->save();


                        $wallet_trns['user_id']         =  $user->id??null;
                        $wallet_trns['amount']          =  $this->signup_bonus;
                        $wallet_trns['payment_type']    =  1;
                        $wallet_trns['payment_type_string'] = "Bonus";
                        $wallet_trns['transaction_id']  = time().'-'.$user->id??null;
                        $wallet_trns['payment_mode']    = env('company_name');
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

                if($request->device_id){
                      \DB::table('users')->where('email',$request->email)->update([
                        'device_id'=>$request->device_id
                    ]);
                  }else{
                     \DB::table('users')->where('email',$request->email)->update(
                        [
                            'signup_from'=>'ninja11'
                        ]
                    );
            	}

            }else{
                $deviceDetails = json_encode($request->deviceDetails);
                \DB::table('hardware_infos')->insert([
                    'user_id' => $usermodel->id??0,
                    'device_details' => $deviceDetails
                ]);
            }
            if($request->device_id){
                      \DB::table('users')->where('email',$request->email)->update([
                        'device_id'=>$request->device_id
                    ]);
            }else{
                     \DB::table('users')->where('email',$request->email)->update(
                        [
                            'signup_from'=>'ninja11'
                        ]
                );
            }
        } 
       
        $data['apk_url']    =  env('apk_url');
        $data['call_url']   =  env('paytm_call_back_url');
        
        
        if($usermodel){
            $token  = $usermodel->createToken('token')->accessToken;
            $user_id = $data['user_id']??null; 
            $data['name']       = $usermodel->name;
            $data['email']      = $usermodel->email;
            $data['user_id']    = $usermodel->user_name;
            $data['team_name']  = $usermodel->team_name;
            $data['mobile_number'] = $usermodel->mobile_number;
     
            return response()->json([
                'base_url'   => url('api/v3').'/',
                "status"     =>  $status,
                "is_account_verified"=>1,
                "code"      => $code,
                "message"   => $message ,
                'data'      => $data,
                'token'     => $token,
                'system_token'  => Crypt::encryptString($user_id)
            ]);
        }else{
            return response()->json([
                'base_url'   => url('api').'/',
                "status"        =>  $status,
                "is_account_verified" => 1, //0 
                "code"          => $code,
                "message"       => 'Invalid email or password',
                'token'         => $token??Hash::make(1),
                'system_token'  => ''
            ]);
        }
    }

    public function mobileLogin(Request $request)
    {   
        
        //  return array(
        //             'status' => false,
        //             'code' => 420,
        //             'message' => 'Please login with  Gmail id'
        //         );

        $input  = $request->all();
        $validator = Validator::make($request->all(), [
            'mobile_number' => 'required',
            'pass_code' => 'required'
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

        $user_type = "mobile_number";
        switch ($user_type) {
            case 'mobile_number':
                $credentials = [
                    'mobile_number' => $request->get('mobile_number')
                ];
                $user_exit   = User::where('mobile_number',$request->mobile_number)->first();

                $user   = User::where('mobile_number',$request->mobile_number)
                                ->where('pass_code',$request->pass_code)
                                ->first();

                 
                if($user && $user_exit){

                    if($user->status==0){
                        return array(
                            'status' => false,
                            'code' => 420,
                            'message' => 'Your Account is disabled.To activate write an email at '.env('company_email')
                            );
                    }

                    $data['name']       = $user->name??$request->name;
                    $data['email']      = $user->email??$request->email;
                    $data['user_id']    = $user->user_name;
                    $data['team_name']  = $user->team_name??$request->team_name;
                    $data['mobile_number'] = $request->mobile_number;
                    $data['otpverified'] = true; 
                    
                    $usermodel = $user;
                    $usermodel->name = $user->name??$request->name;
                    
                    if($request->mobile_number){
                        $usermodel->mobile_number = $request->mobile_number;
                    }
                    elseif($usermodel->mobile_number){
                        $usermodel->mobile_number = $usermodel->mobile_number;
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
                    
                    $usermodel->save();
                    $status = true;
                    $code = 200;
                    $message = "login successfully";
                  //  $this->generateOtp($request);
                }elseif($user_exit){
                    return array(
                            'status'    => false,
                            'code'      => 201,
                            "error_code"=> 202, 
                            'message'   => 'Please login with email and update passcode Or if you forgot passcode, email us at support@ninja11.in'
                            );
                }
                else{
                    $validator = Validator::make($request->all(), [
                        'mobile_number'  => 'required|unique:users|regex:/^([0-9\s\-\+\(\)]*)$/|min:10',
                        'pass_code' => 'required|min:6|numeric',
                        'email'     => 'required|unique:users|email',
                        'team_name' => 'required|min:3',
                        'name'      => 'required|min:3' 
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
                        exit();
                    }
                    
                    $user = new User;
                    if($request->team_name){
                        $user->team_name = $request->team_name;
                    }
                    $user->name         = $request->name;
                    $user->email         = $request->email;
                    $user->role_type     = 3;//$request->input('role_type'); ;
                    $user->mobile_number = $request->mobile_number;
                    $user->password      = Hash::make(mt_rand(1,9));
                    $user->user_name     = $this->generateUserName();
                    $user->referal_code  = $this->generateReferralCode();
                    $user->reference_code = $request->referral_code;
                    $user->email_verified_at = 1;
                    $user->pass_code     = $request->pass_code;

                    if($request->referral_code){
                        $referral_code_count = User::where('referal_code',$request->referral_code)->count();

                        if($referral_code_count){
                            $this->signup_bonus = $this->signup_bonus;
                        }

                        if($request->referral_code && $referral_code_count==0){
                            return array(
                                    'status'    => false,
                                    'code'      => 201,
                                    'message'   => 'Referral code is invalid'
                            );
                        }   
                    }

                    $user->save() ;
                    
                    $request->merge(['user_id'=>$user->id,'mobile_number'=>$user->mobile_number]);
                    
                    if($user->id){
                        $devid = User::where('device_id',$request->device_id)->count();

                        if($devid==0){
                            $this->saveReferral($request,$user);    
                        }else{
                            $request->merge(['referral_code'=>'NINJA11']);
                            $this->saveReferral($request,$user);
                        }
                            
                        $wallet = new Wallet;
                        $wallet->user_id = $user->id;
                        $wallet->validate_user = Hash::make($user->id);
                        $wallet->payment_type  =  1;
                        $wallet->payment_type_string = "Bonus";
                        $wallet->amount         = $this->signup_bonus;
                        $wallet->save();


                        $wallet_trns['user_id']         =  $user->id??null;
                        $wallet_trns['amount']          =  $this->signup_bonus;
                        $wallet_trns['payment_type']    =  1;
                        $wallet_trns['payment_type_string'] = "Bonus";
                        $wallet_trns['transaction_id']  = time().'-'.$user->id??null;
                        $wallet_trns['payment_mode']    = env('company_name');
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

                if($request->device_id){
                      \DB::table('users')->where('email',$request->email)->update([
                        'device_id'=>$request->device_id
                    ]);
                  } 

            }else{
                $deviceDetails = json_encode($request->deviceDetails);
                \DB::table('hardware_infos')->insert([
                    'user_id' => $usermodel->id??0,
                    'device_details' => $deviceDetails
                ]);
            }
            if($request->device_id){
                      \DB::table('users')->where('email',$request->email)->update([
                        'device_id'=>$request->device_id
                    ]);
            }else{
                     \DB::table('users')->where('email',$request->email)->update(
                        [
                            'signup_from'=>'ninja11'
                        ]
                );
            }
        } 
        if($usermodel){
            $token  = $usermodel->createToken('token')->accessToken;
        }
        $data['apk_url']    =  env('apk_url');
        $data['call_url']   =  env('paytm_call_back_url');
        
        \DB::table('checkin_rewards')
                    ->updateOrInsert([
                        'user_id' => $usermodel->id,
                        'checkin_date' => date('Y-m-d')
                    ],
                    [
                       'user_id' => $usermodel->id,
                        'checkin_date' => date('Y-m-d'),
                        'amount' => 1,
                        'reward_points' => 1
                    ]
                );
        if($data){
            $user_id = $data['user_id']??null; 
            $data['name']       = $usermodel->name;
            $data['email']      = $usermodel->email;
            $data['user_id']    = $usermodel->user_name;
            $data['team_name']  = $usermodel->team_name;
            $data['mobile_number'] = $usermodel->mobile_number;
             
            return response()->json([
                'base_url'   => url('api/v3').'/',
                "status"     =>  $status,
                "is_account_verified"=>1,
                "code"      => $code,
                "message"   => $message ,
                'data'      => $data,
                'token'     => $token,
                'system_token'  => Crypt::encryptString($user_id)
            ]);
        }else{
            return response()->json([
                'base_url'   => url('api/').'/',
                "status"        =>  $status,
                "is_account_verified" => 1, //0 
                "code"          => $code,
                "message"       => 'Invalid email or password',
                'token'         => $token??Hash::make(1),
                'system_token'  => ''
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

        $links = url('api/v3/changePassword?token='.$enc);

        $email_content = array(
            'receipent_email'   => $request->input('email'),
            'subject'           => 'Your ninja11 Account Password',
            'name'              => $user->first_name,
            'greeting'          => 'Ninja11',
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

        $serverLKey = env('serverLKey');
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

        $urlencode = urldecode("Your verification \n OTP is : ".$otp."\n Notes: Ninja11 never calls you asking for OTP.");

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
                'subject'=> 'Ninja11: Otp Verification',
                'receipent_name'=> $user->name,
                'sender_name'=>'Ninja11',
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

        $user = User::select('id as user_id','name','email','referal_code','profile_image','mobile_number','city','gender','dateOfBirth','team_name','user_name','pass_code')
        ->find($request->user_id);
        try {
            $decrypted = Crypt::decryptString($request->system_token);
            // if failed
            if($user->user_name!=$decrypted)
            {   
                return response()->json(
                [
                        "status"=>false,
                        "code"=>503,
                       'message' => "Access Deny"
                    ]
                );
            }
            
        } catch (DecryptException $e) {
             return Response::json(array(
                    "status"=>false,
                    "code"=>503,
                    'message' => "Access Deny"
                )
            );
        }

        
        $pass_code =  false;
        if($user){
            $user->user_id = $user->user_name;
            $status = true;
            $pass_code = ($user->pass_code)?true:false;
            $code = 200;
            $message = "Record found";
            $data = $user;

        }else{
            $status = false;
            $code = 201;
            $message = "Record not found";
            $data = "";
        }
        $data->pass_code = "xxxxxx";

        return response()->json(
            [
                "status"    =>  $status,
                'code'      =>  $code,
                'pass_code' =>  $pass_code,
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

    public function verifyGmail(Request $request)
    {

         $auth = app('firebase.auth');
         $idAuthString = $request->id_token;

         try { // Try to verify the Firebase credential token with Google
    
                $verifiedIdToken = $auth->verifyIdToken($idAuthString);
            //    dd($verifiedIdToken);

             
                $uid        =   $verifiedIdToken->claims()->get('user_id');
                $email      =   $verifiedIdToken->claims()->get('email');
                $picture    =   $verifiedIdToken->claims()->get('picture');

                $data['email']      = $email;
                $data['picture']    = $picture;
                $data['uid']        = $uid;
                $data['code']       = 1001;

                if($request->email==$email || $request->provider_id==$uid){
                    $data['code'] = 200;
                }
                
                return $data; 

                
              } catch (\InvalidArgumentException $e) { // If the token has the wrong format
                
                return [
                    'status' => false,
                    'code' => 1001,
                    'message' => 'Token invalid'
                ];        
                
              } catch (InvalidToken $e) { // If the token is invalid (expired ...)
                
                return [
                    'status' => false,
                    'code' => 1001,
                    'message' => 'Token Expired'
                ];
                
              }

         }

         // bonus
    public function referralBonusCredit(Request $request)
    {
        $rb = \DB::table('referral_cash')->where('status',1)->limit(50)->get();

        foreach($rb as $key => $value)
        {
            $user_id = $value->referral_user_id;

            $refer_by = $value->refer_by;

            $c =  WalletTransaction::where('user_id',$user_id)
                                ->where('payment_type',3)
                                ->where('amount','>',0)
                                ->count();
            if($c)
            {
                $w = Wallet::where('user_id',$refer_by)
                            ->where('payment_type',1)
                            ->first();

                $w->amount = $w->amount+$value->amount;
               
                \DB::table('referral_cash')->where('id',$value->id)->update([
                    'status' => 2
                ]);

                $w->save();

                $wallet_trns['user_id']         =  $refer_by??285;
                $wallet_trns['amount']          =  20;
                $wallet_trns['payment_type']    =  2;
                $wallet_trns['payment_type_string'] = "Referral Bonus";
                $wallet_trns['transaction_id']  = time().'-'.$refer_by??null;
                $wallet_trns['payment_mode']    = "Ninja11";
                $wallet_trns['payment_status']  = "success";

                $wallet_transactions = WalletTransaction::create(
                    $wallet_trns
                );
            }
        }
    }
}
