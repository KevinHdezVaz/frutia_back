<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Equipo;
use App\Models\DailyMatch;
use App\Models\Field;

class HomeController extends Controller
{
    public function home(Request $request)
    {
        $monthLabels = ['Ene', 'Feb', 'Mar', 'Abr', 'May', 'Jun', 'Jul', 'Ago', 'Sep', 'Oct', 'Nov', 'Dic'];
        
        $monthlyUsers = User::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->pluck('count', 'month')
            ->toArray();
            
        $monthlyTeams = Equipo::selectRaw('MONTH(created_at) as month, COUNT(*) as count')
            ->whereYear('created_at', now()->year)
            ->groupBy('month')
            ->pluck('count', 'month')
            ->toArray();

        $userData = array_map(function($month) use ($monthlyUsers) {
            return $monthlyUsers[$month] ?? 0;
        }, range(1, 12));

        $teamData = array_map(function($month) use ($monthlyTeams) {
            return $monthlyTeams[$month] ?? 0;
        }, range(1, 12));

        $matchesByDay = DailyMatch::selectRaw('DATE(schedule_date) as date, COUNT(*) as count')
            ->whereBetween('schedule_date', [now()->startOfWeek(), now()->endOfWeek()])
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totalFields = Field::count();
        $occupiedFields = DailyMatch::whereDate('schedule_date', now()->today())->distinct('field_id')->count('field_id');
        $occupationPercentage = $totalFields ? ($occupiedFields / $totalFields) * 100 : 0;

        $matchesPlayedThisMonth = DailyMatch::whereMonth('schedule_date', now()->month)->count();

        $matches = DailyMatch::with(['field', 'teams'])
            ->when($request->date, function ($query) use ($request) {
                $query->whereDate('schedule_date', $request->date);
            })
            ->when($request->status, function ($query) use ($request) {
                $query->where('status', $request->status);
            })
            ->orderBy('schedule_date', 'desc')
            ->orderBy('start_time', 'asc')
            ->paginate(10);

        $upcomingMatches = DailyMatch::with(['field', 'teams'])
            ->whereDate('schedule_date', '>=', now()->today())
            ->whereDate('schedule_date', '<=', now()->tomorrow())
            ->orderBy('schedule_date', 'asc')
            ->orderBy('start_time', 'asc')
            ->take(5)
            ->get();

        $fields = Field::all(); // Para el mapa (opcional)

        return view('dashboard', [
            'newUsersCount' => User::whereMonth('created_at', now()->month)->count(),
            'newTeamsCount' => Equipo::whereMonth('created_at', now()->month)->count(),
            'monthLabels' => $monthLabels,
            'userData' => $userData,
            'teamData' => $teamData,
            'matchesByDay' => $matchesByDay,
            'occupationPercentage' => $occupationPercentage,
            'matchesPlayedThisMonth' => $matchesPlayedThisMonth,
            'matches' => $matches,
            'upcomingMatches' => $upcomingMatches,
            'fields' => $fields,
        ]);
    }
}