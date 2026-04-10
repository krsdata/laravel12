<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::group([
        'prefix' => 'v3' 
    ],
     function () {
Route::get('/hello', function (Request $request) {
     
echo "hello";

});

     });

 
/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: *');
header('Access-Control-Allow-Credentials: true');
//header("Access-Control-Allow-Origin: *");

// \URL::forceScheme('https'); // Enable only in production via AppServiceProvider

Route::group([
        'prefix' => 'v3',
        'namespace' => 'App\Http\Controllers',
    ],
     function () {

    Route::post('/mobileLogin', 'Api\UserController@mobileLogin'); 
    Route::post('/login', 'Api\UserController@login'); 
   // Route::post('/register', 'Api\UserController@member/registration');
    Route::get('/logout', 'Api\UserController@logout')->middleware('auth:api');
    Route::match(['post','get'], 'email_verification', 'UserController@emailVerification');
    Route::match(['post','get'], 'forgotPassword', 'Api\UserController@forgotPassword');
    Route::match(['post','get'], 'password/reset', 'Api\UserController@resetPassword');
    Route::match(['post','get'], 'changePassword', 'Api\UserController@changePassword');
    Route::match(['post','get'], 'mChangePassword', 'Api\UserController@mChangePassword');

});
Route::middleware('auth:api')->group( function () {
});

//Route::middleware('auth:api')->group( function () {
    Route::group([
        'prefix' => 'v3',
        'namespace' => 'App\Http\Controllers',
    ], function()
    {
        Route::match(['get','post'], 'testProcedure', [
            'as' => 'testProcedure',
            'uses' => 'Api\ApiController@testProcedure'
	        ]);

        Route::match(['get','post'], 'copyTeamFromUser', [
            'as' => 'copyTeamFromUser',
            'uses' => 'Api\ApiController@copyTeamFromUser'
	        ]);


        Route::match(['get','post'], 'processPayout', [
            'as' => 'processPayout',
            'uses' => 'Api\ApiController@processPayout'
	        ]);
        
        Route::match(['get','post'], 'payoutStatus', [
            'as' => 'payoutStatus',
            'uses' => 'Api\ApiController@payoutStatus'
	        ]);

        
    Route::match(['get','post'], 'qrPayment', [
        'as' => 'qrPayment',
        'uses' => 'Api\ApiController@qrPayment'
        ]);



	Route::match(['get','post'], 'paymentCallBack', [
            'as' => 'paymentCallBack',
            'uses' => 'Api\ApiController@paymentCallbackUrl'
        ]);	
	
        Route::match(['get','post'], 'paymentReturnUrl', [
            'as' => 'paymentReturnUrl',
            'uses' => 'Api\ApiController@paymentReturnUrl'
        ]);
	

	 Route::match(['get','post'], 'createMyTeam', [
            'as' => 'createMyTeam',
            'uses' => 'Api\ApiController@create11Team'
        ]);

        Route::match(['get','post'], 'notifyToJoinTelegram', [
            'as' => 'notifyToJoinTelegram',
            'uses' => 'Api\ApiController@notifyToJoinTelegram'   
        ]);


        Route::match(['post','get'],'withdrawAmountNinja', 'Api\ApiController@withdrawAmountNinja');
        // getMatch
        Route::match(['post'],'getMatch', 'Api\ApiController@getMatch');
        Route::match(['post'],'getBanner', 'Api\ApiController@getBanner');
        
        // Contest
       // Route::match(['post','get'],'getContestByMatch', 'Api\ApiController@getData');
        Route::match(['post','get'],'getMyContest', 'Api\ApiController@getMyContest');
        Route::match(['post','get'],'joinContest', 'Api\ApiController@joinContest');
        //Create Team
        Route::match(['post','get'],'createTeam', 'Api\ApiController@createTeam');
        Route::match(['post','get'],'cloneMyTeam', 'Api\ApiController@cloneMyTeam');
        Route::match(['post','get'],'getMyTeam', 'Api\ApiController@getMyTeam');
        
        // Get Players
        Route::match(['post','get'],'getPlayer', 'Api\ApiController@getPlayer');
        //Transaction
        Route::match(['post','get'],'getWallet', 'Api\ApiController@getWallet');
        Route::match(['post','get'],'addMoney', 'Api\ApiController@addMoney');
        Route::match(['post','get'],'transactionHistory', 'Api\PaymentController@transactionHistory');
        // Leaderboard , getpoints and prizedistribution
        Route::match(['post','get'],'leaderBoard', 'Api\ApiController@leaderBoard');
        Route::match(['post','get'],'getPoints', 'Api\ApiController@getPoints');
        Route::match(['post','get'],'getPrizeBreakup', 'Api\ApiController@getPrizeBreakup');
        Route::match(['post','get'],'prizeDistribution', 'Api\ApiController@prizeDistribution');

        Route::match(['post','get'],'joinNewContestStatus', 'Api\ApiController@joinNewContestStatus');

        Route::match(['post','get'],'getScore', 'Api\ApiController@getScore');
        
        Route::match(['get','post'], 'verification', [
            'as' => 'verification',
            'uses' => 'Api\ApiController@verification'
        ]);

        Route::match(['post','get'],'getMatchHistory', 'Api\ApiController@getMatchHistory'); 

        Route::match(['post','get'],'messageApi', 'Api\ApiController@messageApi');
      
        Route::match(['post','get'],'saveDocuments', 'Api\ApiController@saveDocuments'); 

        Route::match(['post','get'],'saveAllDocuments', 'Api\ApiController@saveAllDocuments'); 

        Route::match(['get','post'], 'getMyPlayedMatches', [
        'as' => 'getMyPlayedMatches',
        'uses' => 'Api\ApiController@getMyPlayedMatches'
        ]);

        Route::match(['post','get'],'myReferralDetails', 'Api\UserController@myReferralDetails');

        Route::match(['post','get'],'verifyDocument', 'Api\UserController@verifyDocument');
    });
//});

Route::group(
    [
        'prefix' => 'v1',
        'namespace' => 'App\Http\Controllers',
    ], function()
    {   
        Route::match(['post'],'login', 'Api\UserController@login');  
        Route::match(['post'],'login2', 'Api\UserController@login2');  
        Route::match(['post','get'],'apkUpdate', 'Api\ApiController@apkUpdate');
        Route::match(['post','get'],'create11Team', 'Api\ApiController@create11Team');
    }
);
// Without AuthM
Route::group([
    'prefix' => 'v3',
    'namespace' => 'App\Http\Controllers',
], function()
{   
    
    Route::match(['post','get'],'autoSetOrderBy', 'Api\ApiController@autoSetOrderBy');

    // 15/4/25
    Route::match(['post','get'],'autoJoinTeam', 'Api\ApiController@autoJoinTeam'); 
    Route::match(['post','get'],'createTeam4me', 'Api\ApiController@createTeam4me'); 

    //notification
    //4/7/25
    Route::match(['post','get'],'updateDailySubscriber', 'Api\ApiController@updateDailySubscriber');
    Route::match(['post','get'],'notifyUser', 'Api\ApiController@notifyUser');
    Route::match(['post','get'],'sendSingleNotification', 'Api\ApiController@sendSingleNotification'); 
    

    //Route::match(['post','get'],'create11Team', 'Api\ApiController@create11Team');
    Route::match(['post','get'],'member/updateProfile', 'Api\UserController@updateProfile');
    Route::match(['post','get'],'referralBonusCredit', 'Api\UserController@referralBonusCredit');
    Route::match(['post','get'],'toss', 'Api\ApiController@toss'); 

    //1/13/24
    Route::match(['post','get'],'cancelRailContest', 'Api\ApiController@cancelRailContest');
    
    Route::match(['post','get'],'playerDetails', 'Api\ApiController@playerDetails');    
    
    Route::match(['post','get'],'updateProfile', 'Api\UserController@updateProfile');


    Route::match(['post','get'],'getProfile', 'Api\UserController@getProfile');
   
    Route::match(['post','get'],'updateLeaderboardUser', 'Api\ApiController@updateLeaderboardUser'); 

    Route::match(['post','get'],'globalLeaderBoard', 'Api\ApiController@globalLeaderBoard'); 
    //

    Route::match(['post','get'],'globalLeaderBoardPrize', 'Api\ApiController@globalLeaderBoardPrize');

    Route::match(['post','get'],'getDublicateUser', 'Api\ApiController@getDublicateUser');
    

    Route::match(['post','get'],'autoJoinContest', 'Api\ApiController@autoJoinContest'); 

    // new api date: 27/11/23
    Route::match(['post','get'],'initiateTransaction', 'Api\ApiController@initiateTransaction');
    Route::match(['post','get'],'initiateUPIPayment', 'Api\ApiController@initiateUPIPayment');

    Route::match(['post','get'],'verifyUPIPayment', 'Api\ApiController@verifyUPIPayment');
    
    Route::match(['post','get'],'webhookUPIPayment', 'Api\ApiController@webhookUPIPayment');

    Route::match(['post','get'],'phonepeAPI', 'Api\ApiController@phonepeAPI');

    Route::match(['post','get'],'phonepePaymentStatus', 'Api\ApiController@phonepePaymentStatus');

    //phonepe
    Route::match(['post','get'],'callbackURLPhonePe', 'Api\ApiController@callbackURLPhonePe');
    Route::match(['post','get'],'redirectURLPhonePe', 'Api\ApiController@redirectURLPhonePe');
    Route::match(['post','get'],'phonePeInitiate', 'Api\ApiController@phonePeInitiate');
    
    
    

     Route::match(['post','get'],'validateCoupon', 'Api\ApiController@validateCoupon');
     

     Route::match(['post','get'],'cloningContest', 'Api\ApiController@cloningContest'); 

   
    Route::match(['post','get'],'updateLeaderboard', 'Api\ApiController@globalLeaderBoardLive'); 

    Route::match(['post','get'],'getLeaderBoardUser', 'Api\ApiController@getLeaderBoardUser'); 
    
    Route::match(['post','get'],'updateFinalLB', 'Api\ApiController@updateFinalLB'); 

    Route::match(['post','get'],'apkUpdate', 'Api\ApiController@apkUpdate');

    Route::match(['post','get'],'messageApi', 'Api\ApiController@messageApi');
      
    Route::match(['post'],'login', 'Api\UserController@login'); 
    
    Route::match(['post','get'],'removePrizeAfterAbandon', 'Api\ApiController@removePrizeAfterAbandon');
    Route::match(['post','get'],'revertPrize', 'Api\ApiController@revertPrize');
    Route::match(['post','get'],'getPlaying11', 'Api\ApiController@getPlaying11');
    Route::match(['get','post'], 'updateLiveMatchStatus', [
        'as' => 'updateLiveMatchStatus',
        'uses' => 'Api\ApiController@updateMatchDataByStatus'
    ]);

    Route::match(['get','post'], 'getPlayerPoints', [
        'as' => 'getPlayerPoints',
        'uses' => 'Api\ApiController@getPlayerPoints'
    ]);

    Route::match(['get','post'], 'automateCreateContest', [
        'as' => 'automateCreateContest',
        'uses' => 'Api\ApiController@automateCreateContest'
    ]);

    Route::match(['get','post'], 'playerAnalytics', [
        'as' => 'playerAnalytics',
        'uses' => 'Api\ApiController@playerAnalytics'
    ]);
     Route::match(['post','get'],'updateMatchDataByMatchId/{match_id}/{status}', 'Api\ApiController@updateMatchDataByMatchId'); 

    Route::match(['post','get'],'myReferralDetails', 'Api\UserController@myReferralDetails');
    
    Route::match(['post','get'],'contestFillNotify', 'Api\ApiController@contestFillNotify');

    Route::match(['post','get'],'customMessage', 'Api\ApiController@customMessage');
    Route::match(['post','get'],'deviceNotification', 'Api\UserController@deviceNotification');
    
    Route::match(['post','get'],'sendPushNotification', 'Api\UserController@sendPushNotification');
    // cron from backedn
    Route::match(['post','get'],'getMatchDataFromApiAdmin', 'Api\CronController@getMatchDataFromApi');

    Route::match(['post','get'],'getPlayingMatchHistory', 'Api\ApiController@getPlayingMatchHistory');   

    Route::match(['post','get'],'captureScreenTime', 'Api\ApiController@captureScreenTime');

    

    
    Route::match(['post','get'],'updateMatchDataByStatusAdmin/{status}', 'Api\CronController@updateMatchDataByStatus');
    // system API
    Route::match(['post','get'],'storeMatchInfo/{fileName}', 'Api\ApiController@storeMatchInfo');
    Route::match(['post','get'],'getMatchDataFromApi', 'Api\ApiController@getMatchDataFromApi');
    Route::match(['post','get'],'updateMatchDataByStatus/{status}', 'Api\ApiController@updateMatchDataByStatus');
    Route::match(['post','get'],'updatePlayerFromCompetition', 'Api\ApiController@updatePlayerFromCompetition');
    Route::match(['post','get'],'updatePlayerByMatch/{match_id}', 'Api\ApiController@getCompetitionByMatchId');
    Route::match(['post','get'],'getSquad/{match_id}', 'Api\ApiController@getSquad');
    Route::match(['post','get'],'updateAllSquad', 'Api\ApiController@updateAllSquad');
    Route::match(['post','get'],'createContest/{match_id}', 'Api\ApiController@createContest');
    Route::match(['post','get'],'updateMatchDataById', 'Api\ApiController@updateMatchDataById');

    Route::match(['post','get'],'updateMatchStatus', 'Api\ApiController@updateMatchStatus');

    Route::match(['post','get'],'saveMatchDataByMatchId/{match_id}', 'Api\ApiController@saveMatchDataByMatchId');
    Route::match(['post','get'],'updateMatchInfo', 'Api\ApiController@updateMatchInfo');
    Route::match(['post','get'],'updateSquad/{match_id}', 'Api\ApiController@updateSquad');
    Route::match(['post','get'],'updateLiveMatchFromApp', 'Api\ApiController@updateLiveMatchFromApp');
    Route::match(['post','get'],'updatePoints', 'Api\ApiController@updatePoints');
    Route::match(['post','get'],'getPointsByMatch', 'Api\ApiController@getPointsByMatch');
    Route::match(['post','get'],'updatePointAfterComplete', 'Api\ApiController@updatePointAfterComplete');
    Route::match(['post','get'],'updateUserMatchPoints', 'Api\ApiController@updateUserMatchPoints'); 

    //User API
    Route::match(['post','get'],'member/registration', 'Api\UserController@registration');
    Route::match(['post'],'member/customerLogin', 'Api\UserController@customerLogin');
    Route::match(['post','get'],'email_verification','Api\UserController@emailVerification');
   
    Route::match(['post','get'],'member/logout', 'Api\UserController@logout');
    Route::match(['post','get'],'temporaryPassword', 'Api\UserController@temporaryPassword');
    Route::match(['post','get'],'resetPassword', 'Api\UserController@resetPassword');


    // auth required API
    Route::match(['post','get'],'joinNewContestStatus', 'Api\ApiController@joinNewContestStatus');
    Route::match(['post','get'],'getScore', 'Api\ApiController@getScore');

    Route::match(['post','get'],'getLiveScore', 'Api\ApiController@getLiveScore');
    Route::match(['post','get'],'getMatchFixture', 'Api\ApiController@getMatchFixture');
    Route::match(['post','get'],'reedeemPoint', 'Api\ApiController@reedeemPoint');
    Route::match(['post','get'],'playerStatDetails', 'Api\ApiController@playerStatDetails');

    Route::match(['post','get'],'transactionHistory', 'Api\PaymentController@transactionHistory');
    Route::match(['post','get'],'removeMatch', 'Api\ApiController@removeMatch');

    Route::match(['post','get'],'myReport', 'Api\ApiController@myReport');

    Route::match(['post','get'],'releaseFund', 'Api\ApiController@releaseFund');
    Route::match(['post','get'],'releaseFundStatus', 'Api\ApiController@releaseFundStatus');

    Route::match(['post','get'],'getMatch', 'Api\ApiController@getMatch');
    Route::match(['post','get'],'getBanner', 'Api\ApiController@getBanner');
    Route::match(['post','get'],'getCoupon', 'Api\ApiController@getCoupon');
    Route::match(['post','get'],'generateReportByMatch', 'Api\ApiController@generateReportByMatch');
    Route::match(['post','get'],'updateLastPlayedMatch', 'Api\ApiController@updateLastPlayedMatch');
    
    Route::match(['post','get'],'myPasses', 'Api\ApiController@myPasses');
    
    Route::match(['post','get'],'matchAutoCancelAfterAbondon', 'Api\ApiController@matchAutoCancelAfterAbondon');
    Route::match(['post','get'],'winningReversal', 'Api\ApiController@winningReversal');
    
        // Contest
    Route::match(['post','get'],'getPlayer2', 'Api\ApiController@getPlayer');
    Route::match(['post','get'],'getContestByMatch2', 'Api\ApiController@getContestByMatch2');
    Route::match(['post','get'],'getContestByMatch', 'Api\ApiController@getContestByMatch');

    Route::match(['post','get'],'cloneMyTeam2', 'Api\ApiController@cloneMyTeam'); 
    Route::match(['post','get'],'prizeDistribution', 'Api\PaymentController@prizeDistribution');
    Route::match(['post','get'],'getWallet', 'Api\ApiController@getWallet');
    Route::match(['post','get'],'leaderBoard2', 'Api\ApiController@leaderBoard');
    Route::match(['post','get'],'getPrizeBreakup', 'Api\ApiController@prizeBreakup');
    Route::match(['post','get'],'getContestStat', 'Api\ApiController@getContestStat');
    Route::match(['post','get'],'getPoints', 'Api\ApiController@getPoints');
    

    Route::match(['post','get'],'isLineUp', 'Api\ApiController@isLineUp');
    Route::match(['post','get'],'matchAutoCancel', 'Api\ApiController@matchAutoCancel');

    //added by manoj
    Route::match(['post','get'],'uploadbase64Image', 'Api\ApiController@uploadbase64Image');
    Route::match(['post','get'],'member/uploadImages', 'Api\ApiController@uploadImages');
  

    Route::match(['post','get'],'getAnalytics', 'Api\ApiController@getAnalytics');

    Route::match(['post','get'],'getNotification', 'Api\ApiController@getNotification');
    Route::match(['post','get'],'paytmCallBack', 'Api\ApiController@paytmCallBack');
    Route::match(['post','get'],'paytmCallback', 'Api\ApiController@paytmCallBack');

    Route::match(['post','get'],'callBackUrl', 'Api\ApiController@paytmCallBack');

    Route::match(['post','get'],'paymentCallbackUrl', 'Api\ApiController@paymentCallbackUrl');
    
    Route::match(['post','get'],'statusCheck', 'Api\ApiController@statusCheck');

    Route::match(['post','get'],'paymentCallback', 'Api\ApiController@paymentCallback');


    Route::match(['post','get'],'checkSingnature', 'Api\ApiController@checkSingnature'); 

    Route::match(['post','get'],'eventLog', 'Api\ApiController@eventLog');
    
    Route::match(['post','get'],'getContestByType', 'Api\ApiController@getContestByType');

    Route::match(['post','get'],'detectDevice', 'Api\ApiController@detectDevice');

    Route::match(['post','get'],'playerPoints', 'Api\ApiController@playerPoints');
    
    Route::match(['post','get'],'playerStat', 'Api\ApiController@playerStat');
    Route::match(['post','get'],'distributePrize', 'Api\ApiController@distributePrize');
    Route::match(['post','get'],'affiliateProgram', 'Api\ApiController@affiliateProgram');


    Route::match(['post','get'],'distributeaffiliate', 'Api\ApiController@distributeaffiliate');


    Route::match(['post','get'],'getSquadByMatch/{match_id}', 'Api\ApiController@getSquadByMatch');

     Route::match(['post','get'],'getMatchFK', 'Api\ApiController@getMatchFK');
     Route::match(['post','get'],'createRazorPayOrder', 'Api\ApiController@razorpayOrderId'); 

     Route::match(['post','get'],'updatePointsByMatchID', 'Api\ApiController@updatePointsByMatchID');
     
     Route::match(['post','get'],'createTeamFromAnother', 'Api\ApiController@joinedTeamFromAnother');

    Route::match(['post','get'],'joinContestfromRB', 'Api\ApiController@joinTeamfromRB');

     Route::match(['post','get'],'editTeamFromAnother', 'Api\ApiController@editTeamFromAnother');
    Route::match(['post','get'],'identifyUser', 'Api\ApiController@identifyUser');  

    Route::match(['post','get'],'identifyRealUser', 'Api\ApiController@identifyRealUser');

    Route::match(['post','get'],'deleteHeroTeam', 'Api\ApiController@deleteHeroTeam');

    Route::match(['post','get'],'getContest', 'Api\ApiController@getContest');

    Route::match(['post','get'],'updateMatchPointFromCron', 'Api\ApiController@updateMatchPointFromCron');   
    
    Route::match(['post','get'],'updateMatchRankFromCron', 'Api\ApiController@updateMatchRankFromCron');  

    Route::match(['post','get'],'createTeamNinja', 'Api\ApiController@createTeamNinja');

    Route::match(['post','get'],'joinTeamNinja', 'Api\ApiController@joinTeamNinja');

    Route::match(['post','get'],'leaderBoardNinja', 'Api\ApiController@leaderBoardNinja'); 

     Route::match(['post','get'],'getMyTeamNinja', 'Api\ApiController@getMyTeamNinja');

        Route::match(['post','get'],'railLogic', 'Api\ApiController@railLogic'); 
     
        Route::match(['post','get'],'getCommonMatch', 'Api\ApiController@getCommonMatch'); 
        Route::match(['post','get'],'getCommonMatchCron', 'Api\ApiController@getCommonMatchCron'); 
        
        Route::match(['post','get'],'getCommonPlayer', 'Api\ApiController@getCommonPlayer');

        Route::match(['post','get'],'updateTeam10', 'Api\ApiController@updateTeam10');
        
        Route::match(['post','get'],'affiliateProgram', 'Api\ApiController@affiliateProgram');

        Route::match(['post','get'],'deleteDBEntry', 'Api\ApiController@deleteDBEntry'); 

        Route::match(['post','get'],'getRazorPay', 'Api\ApiController@getRazorPay');

        Route::match(['post','get'],'checkBouncePayment', 'Api\ApiController@checkBouncePayment');
        
        Route::match(['post','get'],'updateRazorPay', 'Api\ApiController@updateRazorPay');

        Route::match(['post','get'],'copyTeam', 'Api\ApiController@copyTeam');

        Route::match(['post','get'],'myAffiliate', 'Api\ApiController@myAffiliate'); 

        Route::match(['post','get'],'autoConfirmContest', 'Api\ApiController@autoConfirmContest');  

        Route::match(['get','post'], 'verificationNinja', [
        'as' => 'verificationNinja',
        'uses' => 'Api\ApiController@verificationNinja'
        ]);

        Route::match(['get','post'], 'addRewardPoints', [
            'as' => 'addRewardPoints',
            'uses' => 'Api\ApiController@addRewardPoints'
        ]);

        Route::match(['get','post'], 'redeemedPoint', [
            'as' => 'redeemedPoint',
            'uses' => 'Api\ApiController@redeemedPoint'
        ]);

        Route::match(['get','post'], 'addMegaExtraCash', [
            'as' => 'addMegaExtraCash',
            'uses' => 'Api\ApiController@addMegaExtraCash'
        ]);

        Route::match(['post','get'],'getMatchHistory', 'Api\ApiController@getMatchHistory'); 


    }
);


// if URL not found
Route::group([
    'prefix' => 'v3',
    'namespace' => 'App\Http\Controllers',
], function()
{
     Route::match(['get','post'], 'getData', [
            'as' => 'getDataTest',
            'uses' => 'Api\ApiController@getData'
        ]);
    
    Route::match(['post','get'],'validateUPI', 'Api\ApiController@validateUPI');
    Route::match(['post','get'],'initiateUPI', 'Api\ApiController@initiateUPI');
    Route::match(['post','get'],'validatePayout', 'Api\ApiController@validatePayout'); 
    Route::match(['post','get'],'releasePayout', 'Api\ApiController@releasePayout'); 
});

// Master api

//header('Access-Control-Allow-Origin: http://rest.ninja11.in');
header('Access-Control-Allow-Origin: *');


/*Governed By Kundan Roy
    rest.krsdata.net
*/

Route::group([
    'prefix' => 'v3',
    'namespace' => 'App\Http\Controllers',
], function()
{
        Route::match(['get', 'post'], 'matches/79168/innings/2/commentary', [
            'as' => 'commentary',
            'uses' => 'Api\MatchApiController@commentaryApi'
        ])->where([
            'match_id' => '[0-9]+',
            'inning' => '[0-9]+',
        ]);
    });


Route::group([
    'prefix' => 'v2',
    'namespace' => 'App\Http\Controllers',
], function()
{
        
        
        Route::match(['get','post'], 'matches', [
            'as' => 'matches',
            'uses' => 'Api\MatchApiController@matches'
        ]);

       // Route::match(['get','post'], 'getData', [
         //   'as' => 'getData',
           // 'uses' => 'Api\MatchApiController@getData'
        //]);
    
        Route::match(['get','post'], 'matches/{match_id}/{details}', [
            'as' => 'matchDetails',
            'uses' => 'Api\MatchApiController@matchDetails'
        ]);

    

        Route::match(['get','post'], 'competitions', [
            'as' => 'competitions',
            'uses' => 'Api\MatchApiController@competitions'
        ]);


        Route::match(['get','post'], 'competitions/{cid}/{details}', [
            'as' => 'competitionsData',
            'uses' => 'Api\MatchApiController@competitionsData'
        ]);


        Route::match(['get','post'], 'competitions/{cid}/{details}', [
            'as' => 'competitionsData',
            'uses' => 'Api\MatchApiController@competitionsData'
        ]);

	
        Route::match(['get','post'], 'competitions/{cid}/squads/{match_id}', [
            'as' => 'cidWithMatchId',
            'uses' => 'Api\MatchApiController@cidWithMatchId'
        ]);

        Route::match(['get','post'], 'players/{pid}/stats', [
            'as' => 'playerStat',
            'uses' => 'Api\MatchApiController@playerStat'
        ]);

        Route::match(['get','post'], 'iccranks', [
            'as' => 'iccranks',
            'uses' => 'Api\MatchApiController@iccranks'
        ]);


        //getPlaying11
        Route::match(['get','post'], 'getPlaying11', [
            'as' => 'getPlaying11',
            'uses' => 'Api\MatchApiController@getPlaying11'
        ]);

         //getPlaying11
         Route::match(['get','post'], 'updatePointCron', [
            'as' => 'updatePointCron',
            'uses' => 'Api\MatchApiController@updatePointCron'
        ]);


        Route::match(['get','post'], 'competitionsApi/{cid}', [
            'as' => 'competitionsApi',
            'uses' => 'Api\MatchApiController@competitionsApi'
        ]);


       

});
 



