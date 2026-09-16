@extends('layouts.app')

@section('page-title', 'Database playground')

@section('content')

<div class="page-head mb-4">
    <div>
        <h1>Database playground</h1>
        <p>
            Run a statement the way a bot would, against the tables you have made
            readable. Everything the bot is refused, you are refused here too, so
            this is where you find out what your annotations are worth.
        </p>
    </div>
    <a href="{{ route('databases.index') }}" class="btn btn-outline-secondary">Back</a>
</div>

<div class="card mb-3">
    <div class="p-3">
        <form action="{{ route('databases.playground.run') }}" method="POST">
            @csrf
            <div class="mb-3">
                <label class="form-label">Connection</label>
                <select name="connection_id" class="form-select" required>
                    @foreach($connections as $connection)
                        <option value="{{ $connection->id }}" @selected($selected === $connection->id)>
                            {{ $connection->name }}@unless($connection->is_enabled) (switched off)@endunless
                        </option>
                    @endforeach
                </select>
            </div>

            <div class="mb-3">
                <label class="form-label">Statement</label>
                <textarea name="sql" rows="4" class="form-control font-monospace" required
                          placeholder="SELECT id, status FROM orders WHERE status = 'pending'">{{ $sql }}</textarea>
                <div class="form-text">Reading only. A row limit is applied whether or not you write one.</div>
            </div>

            <button class="btn btn-brand">Run</button>
        </form>
    </div>
</div>

@if($result !== null)
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>Result</span>
            @if($result['ok'])
                <span class="text-muted" style="font-size: 0.8rem;">
                    {{ $result['row_count'] }} rows in {{ $result['elapsed_ms'] }} ms
                </span>
            @endif
        </div>

        @if(!$result['ok'])
            <div class="p-3">
                <div class="alert alert-danger mb-0">{{ $result['message'] }}</div>
            </div>
        @elseif(!$result['rows'])
            <div class="empty"><p>The statement ran and matched no rows.</p></div>
        @else
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th class="text-end text-muted" style="width: 48px;">#</th>
                            @foreach($result['columns'] as $column)
                                <th>{{ $column }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($result['rows'] as $row)
                            <tr>
                                <td class="text-end figure-mono text-muted" style="font-size: 0.75rem;">
                                    {{ $loop->iteration }}
                                </td>
                                @foreach($row as $value)
                                    <td>{{ $value === null ? '—' : $value }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endif

@endsection
