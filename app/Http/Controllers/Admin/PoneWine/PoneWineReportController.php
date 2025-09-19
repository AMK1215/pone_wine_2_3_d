<?php

namespace App\Http\Controllers\Admin\PoneWine;

use App\Http\Controllers\Controller;
use App\Models\PoneWineBet;
use App\Models\PoneWinePlayerBet;
use App\Models\PoneWineBetInfo;
use App\Models\User;
use App\Enums\UserType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PoneWineReportController extends Controller
{
    /**
     * Show the PoneWine game report based on user role
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $query = $this->buildQuery($user, $request);

        // Apply date filter if provided
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('pone_wine_bets.created_at', [
                $request->input('date_from') . ' 00:00:00',
                $request->input('date_to') . ' 23:59:59',
            ]);
        }

        // Apply player filter if provided
        if ($request->filled('player_name')) {
            $query->where('pone_wine_player_bets.user_name', 'like', '%' . $request->input('player_name') . '%');
        }

        // Apply room filter if provided
        if ($request->filled('room_id')) {
            $query->where('pone_wine_bets.room_id', $request->input('room_id'));
        }

        $reports = $query->orderByDesc('pone_wine_bets.created_at')
                        ->paginate(20);

        // Calculate totals
        $totals = $this->calculateTotals($query);

        return view('admin.ponewine.report.index', compact('reports', 'totals'));
    }

    /**
     * Build query based on user role and permissions
     */
    private function buildQuery($user, Request $request)
    {
        $query = DB::table('pone_wine_bets')
            ->join('pone_wine_player_bets', 'pone_wine_bets.id', '=', 'pone_wine_player_bets.pone_wine_bet_id')
            ->join('users', 'pone_wine_player_bets.user_id', '=', 'users.id')
            ->join('pone_wine_bet_infos', 'pone_wine_player_bets.id', '=', 'pone_wine_bet_infos.pone_wine_player_bet_id')
            ->select([
                'pone_wine_bets.id as game_id',
                'pone_wine_bets.room_id',
                'pone_wine_bets.match_id',
                'pone_wine_bets.win_number',
                'pone_wine_bets.created_at as game_date',
                'pone_wine_player_bets.user_name',
                'pone_wine_player_bets.win_lose_amt',
                'pone_wine_bet_infos.bet_no',
                'pone_wine_bet_infos.bet_amount',
                DB::raw('CASE 
                    WHEN pone_wine_player_bets.win_lose_amt > 0 THEN "Win"
                    WHEN pone_wine_player_bets.win_lose_amt < 0 THEN "Lose"
                    ELSE "Draw"
                END as result')
            ]);

        // Apply role-based filtering
        switch ($user->type) {
            case UserType::Owner->value:
                // Owner can see all data
                break;

            case UserType::Master->value:
                // Master can see all agents' and players' data
                $playerIds = $user->getAllDescendantPlayers()->pluck('id');
                $query->whereIn('pone_wine_player_bets.user_id', $playerIds);
                break;

            case UserType::Agent->value:
                // Agent can see only their players' data
                $playerIds = $user->getAllDescendantPlayers()->pluck('id');
                $query->whereIn('pone_wine_player_bets.user_id', $playerIds);
                break;

            case UserType::SubAgent->value:
                // SubAgent can see only their players' data
                $playerIds = $user->getAllDescendantPlayers()->pluck('id');
                $query->whereIn('pone_wine_player_bets.user_id', $playerIds);
                break;

            case UserType::Player->value:
                // Player can see only their own data
                $query->where('pone_wine_player_bets.user_id', $user->id);
                break;

            default:
                // No data for unknown user types
                $query->whereRaw('1 = 0');
                break;
        }

        return $query;
    }

    /**
     * Calculate totals for the filtered data
     */
    private function calculateTotals($query)
    {
        $totalsQuery = clone $query;
        
        $totals = $totalsQuery->select([
            DB::raw('COUNT(DISTINCT pone_wine_bets.id) as total_games'),
            DB::raw('COUNT(DISTINCT pone_wine_player_bets.user_id) as total_players'),
            DB::raw('SUM(pone_wine_bet_infos.bet_amount) as total_bet_amount'),
            DB::raw('SUM(CASE WHEN pone_wine_player_bets.win_lose_amt > 0 THEN pone_wine_player_bets.win_lose_amt ELSE 0 END) as total_wins'),
            DB::raw('SUM(CASE WHEN pone_wine_player_bets.win_lose_amt < 0 THEN ABS(pone_wine_player_bets.win_lose_amt) ELSE 0 END) as total_losses'),
            DB::raw('SUM(pone_wine_player_bets.win_lose_amt) as net_result')
        ])->first();

        return $totals;
    }

    /**
     * Show detailed report for a specific player
     */
    public function playerDetail(Request $request, $playerId)
    {
        $user = Auth::user();
        $player = User::findOrFail($playerId);

        // Check if user has permission to view this player's data
        $this->checkPlayerAccess($user, $player);

        $query = DB::table('pone_wine_bets')
            ->join('pone_wine_player_bets', 'pone_wine_bets.id', '=', 'pone_wine_player_bets.pone_wine_bet_id')
            ->join('pone_wine_bet_infos', 'pone_wine_player_bets.id', '=', 'pone_wine_bet_infos.pone_wine_player_bet_id')
            ->where('pone_wine_player_bets.user_id', $playerId)
            ->select([
                'pone_wine_bets.id as game_id',
                'pone_wine_bets.room_id',
                'pone_wine_bets.match_id',
                'pone_wine_bets.win_number',
                'pone_wine_bets.created_at as game_date',
                'pone_wine_player_bets.win_lose_amt',
                'pone_wine_bet_infos.bet_no',
                'pone_wine_bet_infos.bet_amount',
                DB::raw('CASE 
                    WHEN pone_wine_player_bets.win_lose_amt > 0 THEN "Win"
                    WHEN pone_wine_player_bets.win_lose_amt < 0 THEN "Lose"
                    ELSE "Draw"
                END as result')
            ]);

        // Apply date filter if provided
        if ($request->filled('date_from') && $request->filled('date_to')) {
            $query->whereBetween('pone_wine_bets.created_at', [
                $request->input('date_from') . ' 00:00:00',
                $request->input('date_to') . ' 23:59:59',
            ]);
        }

        $playerReports = $query->orderByDesc('pone_wine_bets.created_at')
                              ->paginate(20);

        // Calculate player totals
        $playerTotals = $this->calculateTotals($query);

        return view('admin.ponewine.report.player_detail', compact('playerReports', 'player', 'playerTotals'));
    }

    /**
     * Check if user has access to view a specific player's data
     */
    private function checkPlayerAccess($user, $player)
    {
        switch ($user->type) {
            case UserType::Owner->value:
                // Owner can see all
                return true;

            case UserType::Master->value:
                // Master can see all their descendants
                $playerIds = $user->getAllDescendantPlayers()->pluck('id');
                if (!$playerIds->contains($player->id)) {
                    abort(403, 'Unauthorized access to player data');
                }
                return true;

            case UserType::Agent->value:
                // Agent can see only their players
                $playerIds = $user->getAllDescendantPlayers()->pluck('id');
                if (!$playerIds->contains($player->id)) {
                    abort(403, 'Unauthorized access to player data');
                }
                return true;

            case UserType::SubAgent->value:
                // SubAgent can see only their players
                $playerIds = $user->getAllDescendantPlayers()->pluck('id');
                if (!$playerIds->contains($player->id)) {
                    abort(403, 'Unauthorized access to player data');
                }
                return true;

            case UserType::Player->value:
                // Player can see only their own data
                if ($user->id !== $player->id) {
                    abort(403, 'Unauthorized access to player data');
                }
                return true;

            default:
                abort(403, 'Unauthorized access');
        }
    }

    /**
     * Export report to CSV
     */
    public function exportCsv(Request $request)
    {
        $user = Auth::user();
        $query = $this->buildQuery($user, $request);

        $reports = $query->orderByDesc('pone_wine_bets.created_at')->get();

        $filename = 'ponewine_report_' . date('Y-m-d_H-i-s') . '.csv';
        
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($reports) {
            $file = fopen('php://output', 'w');
            
            // Add CSV headers
            fputcsv($file, [
                'No',
                'Game ID',
                'Room ID', 
                'Match ID',
                'Win Number',
                'Player Name',
                'Bet Number',
                'Bet Amount',
                'Win/Lose Amount',
                'Result',
                'Game Date'
            ]);

            $counter = 1;
            foreach ($reports as $report) {
                fputcsv($file, [
                    $counter++,
                    $report->game_id,
                    $report->room_id,
                    $report->match_id,
                    $report->win_number,
                    $report->user_name,
                    $report->bet_no,
                    $report->bet_amount,
                    $report->win_lose_amt,
                    $report->result,
                    $report->game_date
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }
}
