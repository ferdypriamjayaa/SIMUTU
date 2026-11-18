@php
    // Embedded as data URI so DomPDF needs no file access settings.
    $logo = 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('images/logo-syifa-medika-192.png')));
@endphp
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <title>Laporan Observasi Mutu</title>
    <style>
        @page { margin: 28px 28px 36px; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #172a46; }
        .header { width: 100%; border-bottom: 3px solid #d500b9; padding-bottom: 8px; margin-bottom: 10px; }
        .header td { border: 0; padding: 0; vertical-align: middle; }
        .logo { width: 46px; height: 46px; }
        .org { font-size: 13px; font-weight: bold; color: #172a46; margin: 0; }
        .app { font-size: 9px; color: #64748b; margin: 2px 0 0; }
        h1 { font-size: 13px; margin: 0; color: #b8009f; text-align: right; }
        .printed { font-size: 8px; color: #64748b; text-align: right; margin-top: 3px; }
        .meta { width: 100%; margin: 0 0 10px; border-collapse: collapse; }
        .meta td { border: 0; padding: 4px 8px; background: #f2f6fb; color: #213c66; }
        .meta strong { color: #172a46; }
        table.data { width: 100%; border-collapse: collapse; }
        /* Every heading and cell centred both ways, same padding; long text wraps. */
        table.data th, table.data td { border: 1px solid #c2d1e6; padding: 5px 4px; text-align: center; vertical-align: middle; word-wrap: break-word; }
        table.data td { height: 16px; }
        table.data td.no { width: 22px; }
        table.data th { background: #172a46; color: #ffffff; font-weight: bold; }
        table.data tr:nth-child(even) td { background: #f8fafc; }
        .note { margin-top: 8px; color: #b45309; }
        h2.section { font-size: 12px; color: #172a46; margin: 0 0 6px; }
        h2.section .category { font-size: 8px; font-weight: normal; color: #64748b; }
        .footer { position: fixed; bottom: -22px; left: 0; right: 0; font-size: 8px; color: #94a3b8; }
        .accent { height: 3px; background: #228b3a; width: 60px; }
    </style>
</head>

<body>
    <table class="header">
        <tr>
            <td style="width: 54px;"><img src="{{ $logo }}" class="logo" alt="Logo RSU Syifa Medika"></td>
            <td>
                <p class="org">RSU Syifa Medika</p>
                <p class="app">Sistem Informasi Mutu (SIMUTU)</p>
            </td>
            <td>
                <h1>Laporan Observasi Mutu</h1>
                <p class="printed">Dicetak: {{ now()->format('d-m-Y H:i') }}</p>
            </td>
        </tr>
    </table>

    {{-- One indicator per report; "Semua unit" combines that indicator's observations only. --}}
    <h2 class="section">{{ $report->indicator->name }}
        <span class="category">{{ \App\Support\ObservationWorkbook::categoryLabel($report->indicator) }}</span>
    </h2>

    <table class="meta">
        <tr>
            <td>Periode: <strong>{{ \App\Support\ObservationWorkbook::periodLabel($report->scope()) }}</strong></td>
            <td>Unit: <strong>{{ $report->unit?->name ?? 'Semua unit' }}</strong></td>
            <td>Jumlah observasi: <strong>{{ $total }}</strong></td>
            <td>Unit melapor: <strong>{{ $units }}</strong></td>
        </tr>
    </table>

    <table class="data">
        <thead>
            <tr>
                @foreach ($layout->headings() as $heading)
                    <th>{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @forelse ($observations as $observation)
                <tr>
                    @foreach ($layout->row($observation, $loop->iteration) as $cell)
                        <td{!! $loop->first ? ' class="no"' : '' !!}>{!! nl2br(e($cell)) !!}</td>
                    @endforeach
                </tr>
            @empty
                <tr>
                    <td colspan="{{ count($layout->headings()) }}">Tidak ada data observasi pada filter ini.</td>
                </tr>
            @endforelse
        </tbody>
    </table>

    @if ($total > $limit)
        <p class="note">
            PDF hanya memuat {{ $limit }} dari {{ $total }} data. Gunakan unduhan Excel atau persempit filter untuk data lengkap.
        </p>
    @endif

    <div class="footer">
        <div class="accent"></div>
        SIMUTU · RSU Syifa Medika — dokumen ini dibuat otomatis dari sistem.
    </div>
</body>

</html>
