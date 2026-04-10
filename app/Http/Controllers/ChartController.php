<?php
  
namespace App\Http\Controllers;
   
use Illuminate\Http\Request;
use App\User;
use App\Charts\UserLineChart;
use DB;
   

class ChartController extends Controller
{
     
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    public function chartLine()
    {
        $api = url('/chart-line-ajax');
       // return $api;
        $user = User::all();

        $chart = new UserLineChart;
        $chart->labels(['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'])->load($api);
          
        return view('chartLine', compact('chart'));
    }
   
    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    public function chartLineAjax(Request $request)
    {
        $year = $request->has('year') ? $request->year : date('Y');

        $users = User::select(\DB::raw("COUNT(*) as count"))
                    ->whereYear('updated_at', $year)
                    ->groupBy(\DB::raw("Month(updated_at)"))
                    ->pluck('count');
        
        $chart = new UserLineChart;
  
        $chart->dataset('New User Register Chart', 'line', $users)->options([
                    'fill' => 'true',
                    'borderColor' => '#51C1C0'
                ]);
  
        return $chart->api();
    }
}