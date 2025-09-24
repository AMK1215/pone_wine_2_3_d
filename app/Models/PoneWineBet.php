<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PoneWineBet extends Model
{
    use HasFactory;

    protected $fillable = ['room_id', 'match_id', 'status', 'win_number'];

    /**
     * Store game match data for Pone Wine
     * 
     * @param array $gameMatchData
     * @return PoneWineBet
     */
    public static function storeGameMatchData(array $gameMatchData): self
    {
        return self::create([
            'room_id' => $gameMatchData['roomId'],
            'match_id' => $gameMatchData['matchId'],
            'win_number' => $gameMatchData['winNumber'],
            'status' => 1, // Default status (1 = active, 0 = inactive)
        ]);
    }
}
