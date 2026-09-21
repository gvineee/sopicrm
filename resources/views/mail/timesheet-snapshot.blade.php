<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: sans-serif; color: #1a1a1a;">
    <p>გამარჯობა, {{ $employeeName }},</p>

    <p>
        თანდართულია თქვენი სამუშაო ტაბელი პერიოდისთვის
        <strong>{{ $periodStart }} — {{ $periodEnd }}</strong>
        (ვერსია {{ $version }}).
    </p>

    <p>ეს დოკუმენტი არის ტაბელის ასლი გენერაციის მომენტისთვის და შემდგომი ცვლილებები მასზე არ აისახება.</p>

    <p>პატივისცემით,<br>ODA CRM</p>
</body>
</html>
