<?php

/*
|--------------------------------------------------------------------------
| Jotform reference catalogue
|--------------------------------------------------------------------------
|
| The Jotform forms each indicator category is expected to come from, by
| Form ID (the identity of a form; titles are not used for matching).
| `php artisan jotform:audit` compares this list with Jotform and SIMUTU.
|
| The Jotform account's folders cannot be read through the API (HTTP 400),
| so the category of a form is recorded here.
|
| IMP RS: the 13 forms of the original list (audit 2026-11-13) plus the
| VAS indicator of the Rehabilitation Medicine unit.
|
| INM: the patient satisfaction survey (audit 2026-11-17). Its Jotform
| title spells "Kuisioner".
|
*/

return [
    'catalog' => [
        'INM' => [
            '213401282467047' => 'Kuisioner Survei Kepuasan Pasien RSU Syifa Medika Banjarbaru',
        ],
        'IMP_RS' => [
            '241393736710457' => 'Kepatuhan System SBAR Dalam Regulasi Penerimaan Informasi',
            '241342301834446' => 'Kelengkapan Pengisian CPPT SIMGOS Oleh PPA',
            '241342272638455' => 'Kepatuhan Kelengkapan Pengisian SIMGOS Oleh Dokter Spesialis',
            '241322451430442' => 'Kepatuhan Visite Dokter Spesialis 1x24 Jam Terhadap Pasien Rawat Inap',
            '241322643715451' => 'Kepatuhan Pengkajian Ulang Risiko Jatuh Pasien Rawat Inap',
            '241321700051437' => 'Kepatuhan Penandaan Risiko Pasien Jatuh',
            '241321570433446' => 'Kepatuhan Pelaksanaan SPO Serah Terima Tugas Perawat/Bidan Antar Shift',
            '241320598106452' => 'Identifikasi Pasien Sebelum Pemberian Obat/Produk Darah',
            '241321203745445' => 'Kepatuhan SPO Double Check Sebelum Pemberian Obat High Alert Pada Pasien Ranap',
            '241321455653452' => 'Kepatuhan Pelaksanaan dan Pengisian Formulir Rekam Medik Surgical Safety Checklist',
            '241343017820446' => 'Kelengkapan Pengisian SIMGOS Bagi Setiap Pasien Rawat Inap',
            '241321670186452' => 'Kepatuhan Hand Hygiene Di Pelayanan Rawat Jalan',
            '241342719590458' => 'Kepatuhan Irigasi Pada Pasien Post TURP Oleh Perawat Ruang Rawat Inap',
            '241342243354448' => 'Angka Penurunan VAS Terhadap Pasien Yang Mendapatkan Fisioterapi Di Unit Rehabilitasi Medik',
        ],
    ],
];
