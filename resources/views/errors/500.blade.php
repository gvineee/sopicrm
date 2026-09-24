{{--
    Audit A01 (P0): the production 500 page must never expose SQL, stack
    traces, file paths, request headers or cookies. With APP_DEBUG=false
    Laravel already renders a generic page instead of Ignition; this view
    additionally surfaces the request's own correlation id (set by
    App\Http\Middleware\AssignRequestId, also returned as the X-Request-Id
    response header) so an operator can hand support one short reference
    that maps to the full, unredacted entry in the server log — the detail
    stays server-side, only the pointer is public.
--}}
@php
    $referenceId = request()->attributes->get('request_id');
@endphp
<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>შეცდომა · ODA CRM</title>
    <style>
        :root { color-scheme: light dark; }
        body {
            margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #faf7f2; color: #1f2421; padding: 24px;
        }
        @media (prefers-color-scheme: dark) { body { background: #16181a; color: #e8e6e3; } }
        .card { max-width: 32rem; width: 100%; text-align: center; }
        h1 { font-size: 1.5rem; margin: 0 0 .75rem; }
        p { margin: 0 0 1rem; line-height: 1.6; opacity: .85; }
        .ref {
            display: inline-block; font-family: ui-monospace, Menlo, Consolas, monospace;
            font-size: .85rem; padding: .5rem .75rem; border-radius: .5rem;
            background: rgba(127,127,127,.14); word-break: break-all;
        }
        a.btn {
            display: inline-block; margin-top: 1.25rem; padding: .6rem 1.1rem;
            border-radius: .5rem; background: #0f5132; color: #fff; text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="card">
        <h1>დროებითი შეცდომა</h1>
        <p>მოთხოვნის დამუშავებისას მოხდა შეცდომა. ტექნიკური დეტალები დაფიქსირდა სერვერის ჟურნალში.</p>
        @if ($referenceId)
            <p>თუ დაგჭირდებათ დახმარება, გადააწოდეთ ეს ნომერი:</p>
            <span class="ref">{{ $referenceId }}</span>
        @endif
        <div><a class="btn" href="{{ url('/') }}">მთავარზე დაბრუნება</a></div>
    </div>
</body>
</html>
