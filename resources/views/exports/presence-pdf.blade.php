<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 10px;
            margin: 0;
            padding: 20px;
        }
        
        .header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        
        .header h1 {
            margin: 0;
            font-size: 18px;
            color: #333;
        }
        
        .header p {
            margin: 5px 0;
            color: #666;
        }
        
        .filters {
            margin-bottom: 20px;
            background-color: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
        }
        
        .filters h3 {
            margin: 0 0 10px 0;
            font-size: 12px;
            color: #333;
        }
        
        .filters p {
            margin: 2px 0;
            font-size: 9px;
            color: #666;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        
        th, td {
            border: 1px solid #ddd;
            padding: 4px;
            text-align: left;
            font-size: 8px;
        }
        
        th {
            background-color: #4F46E5;
            color: white;
            font-weight: bold;
            text-align: center;
        }
        
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        
        .text-center {
            text-align: center;
        }
        
        .text-right {
            text-align: right;
        }
        
        .status-active {
            color: #059669;
            font-weight: bold;
        }
        
        .status-inactive {
            color: #DC2626;
            font-weight: bold;
        }
        
        .status-late {
            color: #DC2626;
        }
        
        .status-ontime {
            color: #059669;
        }
        
        .footer {
            margin-top: 30px;
            text-align: center;
            font-size: 8px;
            color: #666;
            border-top: 1px solid #ddd;
            padding-top: 10px;
        }
        
        .page-break {
            page-break-after: always;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <p>Generated on: {{ $generated_at }}</p>
        <p>Total Records: {{ $presences->count() }}</p>
    </div>

    @if(!empty($filters))
    <div class="filters">
        <h3>Applied Filters:</h3>
        @if(!empty($filters['date_from']))
            <p><strong>Date From:</strong> {{ $filters['date_from'] }}</p>
        @endif
        @if(!empty($filters['date_to']))
            <p><strong>Date To:</strong> {{ $filters['date_to'] }}</p>
        @endif
        @if(!empty($filters['user_id']))
            <p><strong>Employee:</strong> {{ $presences->first()->createdBy->name ?? 'Selected Employee' }}</p>
        @endif
        @if(!empty($filters['store_id']))
            <p><strong>Store:</strong> {{ $presences->first()->store->nickname ?? 'Selected Store' }}</p>
        @endif
        @if(!empty($filters['search']))
            <p><strong>Search:</strong> {{ $filters['search'] }}</p>
        @endif
    </div>
    @endif

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">No</th>
                <th style="width: 12%;">Employee</th>
                <th style="width: 8%;">Store</th>
                <th style="width: 8%;">Shift</th>
                <th style="width: 8%;">Date</th>
                <th style="width: 7%;">Check In</th>
                <th style="width: 7%;">Check Out</th>
                <th style="width: 8%;">In Status</th>
                <th style="width: 8%;">Out Status</th>
                <th style="width: 6%;">Late (min)</th>
                <th style="width: 6%;">Lat In</th>
                <th style="width: 6%;">Lng In</th>
                <th style="width: 6%;">Lat Out</th>
                <th style="width: 6%;">Lng Out</th>
                <th style="width: 5%;">Status</th>
            </tr>
        </thead>
        <tbody>
            @foreach($presences as $index => $presence)
            <tr>
                <td class="text-center">{{ $index + 1 }}</td>
                <td>{{ $presence->createdBy->name ?? '-' }}</td>
                <td>{{ $presence->store->nickname ?? '-' }}</td>
                <td>{{ $presence->shiftStore->name ?? '-' }}</td>
                <td class="text-center">{{ $presence->check_in ? $presence->check_in->format('Y-m-d') : '-' }}</td>
                <td class="text-center">{{ $presence->check_in ? $presence->check_in->format('H:i:s') : '-' }}</td>
                <td class="text-center">{{ $presence->check_out ? $presence->check_out->format('H:i:s') : 'Not yet' }}</td>
                <td class="text-center {{ $presence->check_in && $presence->shiftStore && $presence->check_in->format('H:i:s') <= $presence->shiftStore->shift_start_time ? 'status-ontime' : 'status-late' }}">
                    @if($presence->check_in && $presence->shiftStore)
                        {{ $presence->check_in->format('H:i:s') <= $presence->shiftStore->shift_start_time ? 'On Time' : 'Late' }}
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if(!$presence->check_out)
                        Not yet
                    @elseif($presence->shiftStore)
                        {{ $presence->check_out->format('H:i:s') >= $presence->shiftStore->shift_end_time ? 'On Time' : 'Early' }}
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">
                    @if($presence->check_in && $presence->shiftStore)
                        @php
                            $checkInTime = $presence->check_in;
                            $shiftStartTime = $presence->check_in->copy()->setTimeFromTimeString($presence->shiftStore->shift_start_time);
                            $lateMinutes = $checkInTime > $shiftStartTime ? $checkInTime->diffInMinutes($shiftStartTime) : 0;
                        @endphp
                        {{ $lateMinutes }}
                    @else
                        -
                    @endif
                </td>
                <td class="text-center">{{ number_format($presence->latitude_in ?? 0, 6) }}</td>
                <td class="text-center">{{ number_format($presence->longitude_in ?? 0, 6) }}</td>
                <td class="text-center">{{ $presence->latitude_out ? number_format($presence->latitude_out, 6) : '-' }}</td>
                <td class="text-center">{{ $presence->longitude_out ? number_format($presence->longitude_out, 6) : '-' }}</td>
                <td class="text-center {{ $presence->status == 1 ? 'status-active' : 'status-inactive' }}">
                    {{ $presence->status == 1 ? 'Active' : 'Inactive' }}
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        <p>This report was generated automatically by the Presence Management System</p>
        <p>Page generated at {{ $generated_at }}</p>
    </div>
</body>
</html>