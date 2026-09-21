<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <title>ტაბელი — {{ $employeeName }}</title>
    <style>
        @page {
            margin: 20mm 15mm;
        }

        * {
            font-family: 'NotoSansGeorgian', sans-serif;
        }

        body {
            font-size: 11px;
            color: #1a1a1a;
        }

        h1 {
            font-size: 16px;
            margin: 0 0 4px 0;
        }

        .meta {
            margin-bottom: 16px;
            color: #444;
        }

        .meta table {
            width: 100%;
            border-collapse: collapse;
        }

        .meta td {
            padding: 2px 0;
            vertical-align: top;
        }

        .meta td.label {
            width: 140px;
            color: #666;
        }

        table.lines {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
        }

        table.lines th,
        table.lines td {
            border: 1px solid #ccc;
            padding: 4px 6px;
            text-align: left;
        }

        table.lines th {
            background-color: #f0f0f0;
            font-weight: bold;
        }

        table.lines td.numeric,
        table.lines th.numeric {
            text-align: right;
        }

        tr.total td {
            font-weight: bold;
            background-color: #f7f7f7;
        }

        .status-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 3px;
            background-color: #eef;
            font-size: 10px;
        }

        .footer {
            margin-top: 24px;
            padding-top: 8px;
            border-top: 1px solid #ccc;
            font-size: 9px;
            color: #777;
        }

        .rejected-note {
            margin-top: 12px;
            padding: 8px;
            background-color: #fff3f3;
            border: 1px solid #f0c0c0;
        }
    </style>
</head>
<body>
    <h1>ტაბელი</h1>

    <div class="meta">
        <table>
            <tr>
                <td class="label">ორგანიზაცია</td>
                <td>{{ $organizationName }}</td>
            </tr>
            <tr>
                <td class="label">თანამშრომელი</td>
                <td>{{ $employeeName }}</td>
            </tr>
            <tr>
                <td class="label">პერიოდი</td>
                <td>{{ $periodStart }} — {{ $periodEnd }}</td>
            </tr>
            <tr>
                <td class="label">სტატუსი</td>
                <td><span class="status-badge">{{ $statusLabel }}</span></td>
            </tr>
            <tr>
                <td class="label">ვერსია</td>
                <td>{{ $version }}</td>
            </tr>
            @if($approvedAt)
                <tr>
                    <td class="label">დამტკიცების თარიღი</td>
                    <td>{{ $approvedAt }}</td>
                </tr>
            @endif
        </table>
    </div>

    <table class="lines">
        <thead>
            <tr>
                <th>თარიღი</th>
                <th>პროექტი</th>
                <th>ტიპი</th>
                <th class="numeric">საათები</th>
            </tr>
        </thead>
        <tbody>
            @forelse($lines as $line)
                <tr>
                    <td>{{ $line['work_date'] }}</td>
                    <td>{{ $line['project_name'] ?? '—' }}</td>
                    <td>{{ $line['rate_type'] === 'hourly' ? 'საათობრივი' : 'დღიური' }}</td>
                    <td class="numeric">{{ $line['hours'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="4">ჩანაწერები არ არის.</td>
                </tr>
            @endforelse
            <tr class="total">
                <td colspan="3">სულ</td>
                <td class="numeric">{{ $totalHours }}</td>
            </tr>
        </tbody>
    </table>

    @if($rejectedReason)
        <div class="rejected-note">
            <strong>დაბრუნების მიზეზი:</strong> {{ $rejectedReason }}
        </div>
    @endif

    <div class="footer">
        დოკუმენტი გენერირებულია: {{ $generatedAt }} — ტაბელის ID: {{ $timesheetId }}, ვერსია {{ $version }}.
        ეს არის მოცემული მომენტისთვის ტაბელის სწორი ასლი (snapshot); ტაბელის შემდგომი ცვლილება ვერსიის ნომერს გაზრდის.
    </div>
</body>
</html>
