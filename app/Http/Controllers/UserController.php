<?php

namespace App\Http\Controllers;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Http\Controllers\Api\BaseController as BaseController;
use App\User;
use Session;
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

class UserController extends BaseController
{
    public $download_link;
    public function __construct(Request $request) {    

    } 

    public function changePasswordToken(Request $request){
        $data =  $request->all(); 
        Session::pull('status_1');
            
        try {
            $token = Crypt::decryptString(\Request::get('token')); 
            
            $user = User::find($token);
            $user->password = Hash::make($request->password);
            $user->save();
            Session::put('status_1','Password changed successfully');
            return back()->withInput();  

        } catch (DecryptException $e) {
             Session::put('status','Token expired or invalid');
             return back()->withInput();       
        }
    }

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
        
      // Send Mail after forget password
        $encrypt_key =  Crypt::encryptString($email);
       
        $links = url('api/v2/changePassword?token='.$encrypt_key);

        $email_content = array(
                        'receipent_email'   => $request->input('email'),
                        'subject'           => 'Your Sportsfight Account Password',
                        'name'              => $user->first_name,
                        'encrypt_key'       => Crypt::encryptString($email),
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
        return view('changePassword',compact('token'));
        
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


    public function resetPassword(Request $request){

        $user_id =  $request->user_id;
        $old_password =  $request->old_password;
        $current_password =  $request->current_password;

        $messages = [
            'user_id.required' => 'Invalid User id', 
            'old_password.required' => 'Old password is required',
            'current_password.required' => 'Current password is required'

        ];
        $validator = Validator::make($request->all(), [
                'user_id' => 'required',   
                 'old_password' => 'required',
                 'current_password' => 'required|min:6'
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
            return response()->json([ "status"=>true,'code'=>200,"message"=>"Temporary Password sent"]);

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
 
   
}
