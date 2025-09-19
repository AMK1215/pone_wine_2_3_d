<?php

namespace App\Http\Controllers\Api\PoneWine;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use App\Models\PoneWineBet;
use App\Models\PoneWineBetInfo;
use App\Models\PoneWinePlayerBet;
use Bavix\Wallet\External\Dto\Extra; 
use Bavix\Wallet\External\Dto\Option; 
use DateTimeImmutable; 
use DateTimeZone;     

class PoneWineClientBalanceUpdateController extends Controller
{
    public function PoneWineClientReport(Request $request)
    {
        Log::info('PoneWine ClientSite: PoneWineClientReport received', [
            'payload' => $request->all(),
            'ip' => $request->ip(),
        ]);

        try {
            $validated = $request->validate([
                'roomId' => 'required|integer',
                'matchId' => 'required|string|max:255',
                'winNumber' => 'required|integer',
                'players' => 'required|array',
                'players.*.player_id' => 'required|string|max:255',
                'players.*.balance' => 'required|numeric|min:0',
                'players.*.winLoseAmount' => 'required|numeric',
                'players.*.betInfos' => 'required|array',
                'players.*.betInfos.*.betNumber' => 'required|integer',
                'players.*.betInfos.*.betAmount' => 'required|numeric|min:0',
                'players.*.client_agent_name' => 'nullable|string',
                'players.*.client_agent_id' => 'nullable|string',
                'players.*.pone_wine_player_bet' => 'nullable|array',
                'players.*.pone_wine_bet_infos' => 'nullable|array',
                // Provider database model information
                'pone_wine_bet' => 'nullable|array',
                'pone_wine_player_bets' => 'nullable|array',
                'pone_wine_bet_infos' => 'nullable|array',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('ClientSite: BalanceUpdateCallback validation failed', [
                'errors' => $e->errors(),
                'payload' => $request->all(),
            ]);
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_REQUEST_DATA',
                'message' => 'Invalid request data: ' . $e->getMessage(),
            ], 400);
        }

        // No signature validation needed - provider doesn't send signature

        
        try {
            DB::beginTransaction();

            // Idempotency Check (CRITICAL) - Check if match already exists
            if (PoneWineBet::where('match_id', $validated['matchId'])->exists()) {
                DB::commit();
                Log::info('ClientSite: Duplicate match_id received, skipping processing.', ['match_id' => $validated['matchId']]);
                return response()->json(['status' => 'success', 'code' => 'ALREADY_PROCESSED', 'message' => 'Match already processed.'], 200);
            }

            $responseData = [];

            foreach ($validated['players'] as $playerData) {
                $user = User::where('user_name', $playerData['player_id'])->first();

                if (!$user) {
                    Log::error('ClientSite: Player not found for balance update. Rolling back transaction.', [
                        'player_id' => $playerData['player_id'], 'match_id' => $validated['matchId'],
                    ]);
                    throw new \RuntimeException("Player {$playerData['player_id']} not found on client site.");
                }

                $currentBalance = $user->wallet->balanceFloat; // Get current balance
                $winLoseAmount = $playerData['winLoseAmount']; // Amount to add/subtract from provider
                $providerExpectedBalance = $playerData['balance']; // Provider's expected final balance

                Log::info('ClientSite: Processing player balance update', [
                    'player_id' => $user->user_name,
                    'current_balance' => $currentBalance,
                    'provider_expected_balance' => $providerExpectedBalance,
                    'win_lose_amount' => $winLoseAmount,
                    'match_id' => $validated['matchId'],
                ]);

                $meta = [
                    'match_id' => $validated['matchId'],
                    'room_id' => $validated['roomId'],
                    'win_number' => $validated['winNumber'],
                    'provider_expected_balance' => $providerExpectedBalance,
                    'client_old_balance' => $currentBalance,
                    'description' => 'Pone Wine game settlement from provider',
                ];

                if ($winLoseAmount > 0) {
                    // Player won or received funds
                    $user->depositFloat($winLoseAmount, $meta);
                    Log::info('ClientSite: Deposited to player wallet', [
                        'player_id' => $user->user_name, 'amount' => $winLoseAmount,
                        'new_balance' => $user->wallet->balanceFloat, 'match_id' => $validated['matchId'],
                    ]);
                } elseif ($winLoseAmount < 0) {
                    // Player lost or paid funds
                    $user->forceWithdrawFloat(abs($winLoseAmount), $meta);
                    Log::info('ClientSite: Withdrew from player wallet', [
                        'player_id' => $user->user_name, 'amount' => abs($winLoseAmount),
                        'new_balance' => $user->wallet->balanceFloat, 'match_id' => $validated['matchId'],
                    ]);
                } else {
                    // Balance is the same, no action needed
                    Log::info('ClientSite: Player balance unchanged', [
                        'player_id' => $user->user_name, 'balance' => $currentBalance, 'match_id' => $validated['matchId'],
                    ]);
                }

                // Add to response data
                $responseData[] = [
                    'playerId' => $user->user_name,
                    'balance' => number_format($user->wallet->balanceFloat, 2, '.', ''),
                    'amountChanged' => $winLoseAmount
                ];

                // Refresh the user model to reflect the latest balance if needed for subsequent operations in the loop
                $user->refresh();
            }

            // Store complete provider data if available
            Log::info('ClientSite: About to store provider data', [
                'has_pone_wine_bet' => isset($validated['pone_wine_bet']),
                'has_pone_wine_player_bets' => isset($validated['pone_wine_player_bets']),
                'has_pone_wine_bet_infos' => isset($validated['pone_wine_bet_infos']),
                'match_id' => $validated['matchId'],
            ]);
            $this->storeProviderData($validated);

            // Store game match data (fallback for basic data)
            $gameMatchData = [
                'roomId' => $validated['roomId'],
                'matchId' => $validated['matchId'],
                'winNumber' => $validated['winNumber'],
                'players' => $validated['players']
            ];

            $gameMatch = PoneWineBet::storeGameMatchData($gameMatchData);
            Log::info('ClientSite: Game match data stored', [
                'match_id' => $gameMatch->match_id,
                'room_id' => $gameMatch->room_id,
                'win_number' => $gameMatch->win_number
            ]);
            

            DB::commit();

            Log::info('ClientSite: All balances updated successfully', ['match_id' => $validated['matchId']]);

            return response()->json([
                'status' => 'Request was successful.',
                'message' => 'Transaction Successful',
                'data' => $responseData
            ], 200);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('ClientSite: Error processing balance update', [
                'error' => $e->getMessage(), 'trace' => $e->getTraceAsString(), 'payload' => $request->all(),
                'match_id' => $request->input('matchId'),
            ]);
            return response()->json([
                'status' => 'error', 'code' => 'INTERNAL_SERVER_ERROR', 'message' => 'Internal server error: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Store complete provider data from callback payload
     */
    private function storeProviderData(array $validated): void
    {
        Log::info('ClientSite: Starting provider data storage', [
            'match_id' => $validated['matchId'],
            'available_keys' => array_keys($validated),
        ]);

        // Store provider's PoneWineBet data if available
        if (isset($validated['pone_wine_bet']) && is_array($validated['pone_wine_bet'])) {
            Log::info('ClientSite: Storing provider bet data');
            $this->storeProviderBetData($validated['pone_wine_bet']);
        } else {
            Log::info('ClientSite: No pone_wine_bet data found');
        }

        // Store provider's PoneWinePlayerBet data if available
        if (isset($validated['pone_wine_player_bets']) && is_array($validated['pone_wine_player_bets'])) {
            Log::info('ClientSite: Storing provider player bets data');
            $this->storeProviderPlayerBets($validated['pone_wine_player_bets']);
        } else {
            Log::info('ClientSite: No pone_wine_player_bets data found');
        }

        // Store provider's PoneWineBetInfo data if available
        if (isset($validated['pone_wine_bet_infos']) && is_array($validated['pone_wine_bet_infos'])) {
            Log::info('ClientSite: Storing provider bet infos data');
            $this->storeProviderBetInfos($validated['pone_wine_bet_infos']);
        } else {
            Log::info('ClientSite: No pone_wine_bet_infos data found');
        }

        Log::info('ClientSite: Provider data storage completed', [
            'match_id' => $validated['matchId'],
            'has_bet_data' => isset($validated['pone_wine_bet']),
            'has_player_bets' => isset($validated['pone_wine_player_bets']),
            'has_bet_infos' => isset($validated['pone_wine_bet_infos']),
        ]);
    }

    /**
     * Store provider's bet data
     */
    private function storeProviderBetData(array $betData): void
    {
        try {
            // Update existing bet or create new one with provider data
            $bet = PoneWineBet::updateOrCreate(
                ['match_id' => $betData['match_id']],
                [
                    'room_id' => $betData['room_id'],
                    'win_number' => $betData['win_number'],
                    'status' => $betData['status'] ?? 1,
                ]
            );

            Log::info('ClientSite: Provider bet data stored', [
                'bet_id' => $bet->id,
                'match_id' => $bet->match_id,
                'room_id' => $bet->room_id,
            ]);
        } catch (\Exception $e) {
            Log::error('ClientSite: Failed to store provider bet data', [
                'error' => $e->getMessage(),
                'bet_data' => $betData,
            ]);
        }
    }

    /**
     * Store provider's player bet data
     */
    private function storeProviderPlayerBets(array $playerBets): void
    {
        foreach ($playerBets as $playerBetData) {
            try {
                // Check if this player bet already exists by user and bet combination
                $existingPlayerBet = PoneWinePlayerBet::where('pone_wine_bet_id', $playerBetData['pone_wine_bet_id'])
                    ->where('user_id', $playerBetData['user_id'])
                    ->first();

                if ($existingPlayerBet) {
                    // Update existing record
                    $existingPlayerBet->update([
                        'user_name' => $playerBetData['user_name'],
                        'win_lose_amt' => $playerBetData['win_lose_amt'],
                    ]);
                    $playerBet = $existingPlayerBet;
                } else {
                    // Create new record (let database assign new ID)
                    $playerBet = PoneWinePlayerBet::create([
                        'pone_wine_bet_id' => $playerBetData['pone_wine_bet_id'],
                        'user_id' => $playerBetData['user_id'],
                        'user_name' => $playerBetData['user_name'],
                        'win_lose_amt' => $playerBetData['win_lose_amt'],
                    ]);
                }

                // Store nested bet infos if available
                if (isset($playerBetData['bet_infos']) && is_array($playerBetData['bet_infos'])) {
                    $this->storeProviderPlayerBetInfos($playerBet->id, $playerBetData['bet_infos']);
                }

                Log::info('ClientSite: Provider player bet stored', [
                    'player_bet_id' => $playerBet->id,
                    'user_name' => $playerBet->user_name,
                    'win_lose_amt' => $playerBet->win_lose_amt,
                    'provider_id' => $playerBetData['id'] ?? 'N/A',
                ]);
            } catch (\Exception $e) {
                Log::error('ClientSite: Failed to store provider player bet', [
                    'error' => $e->getMessage(),
                    'player_bet_data' => $playerBetData,
                ]);
            }
        }
    }

    /**
     * Store provider's bet infos data
     */
    private function storeProviderBetInfos(array $betInfos): void
    {
        foreach ($betInfos as $betInfoData) {
            try {
                // Check if this bet info already exists
                $existingBetInfo = PoneWineBetInfo::where('pone_wine_player_bet_id', $betInfoData['pone_wine_player_bet_id'])
                    ->where('bet_no', $betInfoData['bet_no'])
                    ->first();

                if ($existingBetInfo) {
                    // Update existing record
                    $existingBetInfo->update([
                        'bet_amount' => $betInfoData['bet_amount'],
                    ]);
                    $betInfo = $existingBetInfo;
                } else {
                    // Create new record (let database assign new ID)
                    $betInfo = PoneWineBetInfo::create([
                        'bet_no' => $betInfoData['bet_no'],
                        'bet_amount' => $betInfoData['bet_amount'],
                        'pone_wine_player_bet_id' => $betInfoData['pone_wine_player_bet_id'],
                    ]);
                }

                Log::info('ClientSite: Provider bet info stored', [
                    'bet_info_id' => $betInfo->id,
                    'bet_no' => $betInfo->bet_no,
                    'bet_amount' => $betInfo->bet_amount,
                    'provider_id' => $betInfoData['id'] ?? 'N/A',
                ]);
            } catch (\Exception $e) {
                Log::error('ClientSite: Failed to store provider bet info', [
                    'error' => $e->getMessage(),
                    'bet_info_data' => $betInfoData,
                ]);
            }
        }
    }

    /**
     * Store provider's player bet infos (nested data)
     */
    private function storeProviderPlayerBetInfos(int $playerBetId, array $betInfos): void
    {
        foreach ($betInfos as $betInfoData) {
            try {
                // Check if this bet info already exists
                $existingBetInfo = PoneWineBetInfo::where('pone_wine_player_bet_id', $playerBetId)
                    ->where('bet_no', $betInfoData['bet_no'])
                    ->first();

                if ($existingBetInfo) {
                    // Update existing record
                    $existingBetInfo->update([
                        'bet_amount' => $betInfoData['bet_amount'],
                    ]);
                    $betInfo = $existingBetInfo;
                } else {
                    // Create new record (let database assign new ID)
                    $betInfo = PoneWineBetInfo::create([
                        'bet_no' => $betInfoData['bet_no'],
                        'bet_amount' => $betInfoData['bet_amount'],
                        'pone_wine_player_bet_id' => $playerBetId,
                    ]);
                }

                Log::info('ClientSite: Provider nested bet info stored', [
                    'bet_info_id' => $betInfo->id,
                    'player_bet_id' => $playerBetId,
                    'bet_no' => $betInfo->bet_no,
                    'provider_id' => $betInfoData['id'] ?? 'N/A',
                ]);
            } catch (\Exception $e) {
                Log::error('ClientSite: Failed to store provider nested bet info', [
                    'error' => $e->getMessage(),
                    'player_bet_id' => $playerBetId,
                    'bet_info_data' => $betInfoData,
                ]);
            }
        }
    }
}
