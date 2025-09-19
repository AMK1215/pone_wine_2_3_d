@extends('layouts.admin')

@section('title', 'PoneWine Game Report')

@section('content')
<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">
                        <i class="fas fa-dice mr-2"></i>PoneWine Game Report
                    </h3>
                    <div class="card-tools">
                        <button type="button" class="btn btn-success btn-sm" onclick="exportToCsv()">
                            <i class="fas fa-download mr-1"></i>Export CSV
                        </button>
                    </div>
                </div>

                <!-- Filter Form -->
                <div class="card-body">
                    <form method="GET" action="{{ route('admin.ponewine.report.index') }}" class="mb-4">
                        <div class="row">
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="date_from">Date From:</label>
                                    <input type="date" name="date_from" id="date_from" class="form-control" 
                                           value="{{ request('date_from', now()->subDays(30)->format('Y-m-d')) }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="date_to">Date To:</label>
                                    <input type="date" name="date_to" id="date_to" class="form-control" 
                                           value="{{ request('date_to', now()->format('Y-m-d')) }}">
                                </div>
                            </div>
                            <div class="col-md-3">
                                <div class="form-group">
                                    <label for="player_name">Player Name:</label>
                                    <input type="text" name="player_name" id="player_name" class="form-control" 
                                           value="{{ request('player_name') }}" placeholder="Search player...">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="form-group">
                                    <label for="room_id">Room ID:</label>
                                    <input type="number" name="room_id" id="room_id" class="form-control" 
                                           value="{{ request('room_id') }}" placeholder="Room ID">
                                </div>
                            </div>
                            <div class="col-md-1">
                                <div class="form-group">
                                    <label>&nbsp;</label>
                                    <button type="submit" class="btn btn-primary form-control">
                                        <i class="fas fa-search"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </form>

                    <!-- Summary Cards -->
                    <div class="row mb-4">
                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-info">
                                <div class="inner">
                                    <h3>{{ $totals->total_games ?? 0 }}</h3>
                                    <p>Total Games</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-gamepad"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-success">
                                <div class="inner">
                                    <h3>{{ $totals->total_players ?? 0 }}</h3>
                                    <p>Total Players</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-users"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box bg-warning">
                                <div class="inner">
                                    <h3>{{ number_format($totals->total_bet_amount ?? 0, 2) }}</h3>
                                    <p>Total Bet Amount</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-coins"></i>
                                </div>
                            </div>
                        </div>
                        <div class="col-lg-3 col-6">
                            <div class="small-box {{ ($totals->net_result ?? 0) >= 0 ? 'bg-success' : 'bg-danger' }}">
                                <div class="inner">
                                    <h3>{{ number_format($totals->net_result ?? 0, 2) }}</h3>
                                    <p>Net Result</p>
                                </div>
                                <div class="icon">
                                    <i class="fas fa-chart-line"></i>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Report Table -->
                    <div class="table-responsive">
                        <table class="table table-bordered table-striped table-hover">
                            <thead class="thead-dark">
                                <tr>
                                    <th width="5%">No</th>
                                    <th width="8%">Game ID</th>
                                    <th width="8%">Room ID</th>
                                    <th width="12%">Match ID</th>
                                    <th width="8%">Win Number</th>
                                    <th width="12%">Player Name</th>
                                    <th width="8%">Bet Number</th>
                                    <th width="10%">Bet Amount</th>
                                    <th width="12%">Win/Lose Amount</th>
                                    <th width="8%">Result</th>
                                    <th width="9%">Game Date</th>
                                </tr>
                            </thead>
                            <tbody>
                                @forelse($reports as $index => $report)
                                    <tr>
                                        <td>{{ ($reports->currentPage() - 1) * $reports->perPage() + $index + 1 }}</td>
                                        <td>
                                            <span class="badge badge-secondary">{{ $report->game_id }}</span>
                                        </td>
                                        <td>
                                            <span class="badge badge-info">{{ $report->room_id }}</span>
                                        </td>
                                        <td>
                                            <small class="text-muted">{{ Str::limit($report->match_id, 15) }}</small>
                                        </td>
                                        <td>
                                            <span class="badge badge-primary">{{ $report->win_number }}</span>
                                        </td>
                                        <td>
                                            <a href="{{ route('admin.ponewine.report.player.detail', $report->user_id ?? '') }}" 
                                               class="text-primary font-weight-bold">
                                                {{ $report->user_name }}
                                            </a>
                                        </td>
                                        <td>
                                            <span class="badge badge-warning">{{ $report->bet_no }}</span>
                                        </td>
                                        <td class="text-right">
                                            <strong>{{ number_format($report->bet_amount, 2) }}</strong>
                                        </td>
                                        <td class="text-right">
                                            <span class="font-weight-bold {{ $report->win_lose_amt >= 0 ? 'text-success' : 'text-danger' }}">
                                                {{ $report->win_lose_amt >= 0 ? '+' : '' }}{{ number_format($report->win_lose_amt, 2) }}
                                            </span>
                                        </td>
                                        <td>
                                            @if($report->result == 'Win')
                                                <span class="badge badge-success">{{ $report->result }}</span>
                                            @elseif($report->result == 'Lose')
                                                <span class="badge badge-danger">{{ $report->result }}</span>
                                            @else
                                                <span class="badge badge-secondary">{{ $report->result }}</span>
                                            @endif
                                        </td>
                                        <td>
                                            <small>{{ \Carbon\Carbon::parse($report->game_date)->format('M d, Y H:i') }}</small>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="11" class="text-center text-muted py-4">
                                            <i class="fas fa-inbox fa-2x mb-2"></i><br>
                                            No PoneWine game data found for the selected criteria.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <div class="d-flex justify-content-center mt-4">
                        {{ $reports->withQueryString()->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function exportToCsv() {
    // Get current form parameters
    const params = new URLSearchParams(window.location.search);
    
    // Add export parameter
    params.set('export', 'csv');
    
    // Redirect to export URL
    window.location.href = '{{ route("admin.ponewine.report.export") }}?' + params.toString();
}
</script>
@endsection
