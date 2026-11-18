<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pesan Validasi
    |--------------------------------------------------------------------------
    | Aturan yang tidak tercantum di sini memakai pesan bahasa Inggris bawaan
    | Laravel (fallback_locale).
    */

    'accepted' => ':Attribute harus diterima.',
    'after' => ':Attribute harus berisi tanggal setelah :date.',
    'after_or_equal' => ':Attribute harus berisi tanggal setelah atau sama dengan :date.',
    'alpha' => ':Attribute hanya boleh berisi huruf.',
    'alpha_dash' => ':Attribute hanya boleh berisi huruf, angka, tanda hubung, dan garis bawah.',
    'alpha_num' => ':Attribute hanya boleh berisi huruf dan angka.',
    'array' => ':Attribute harus berupa array.',
    'before' => ':Attribute harus berisi tanggal sebelum :date.',
    'before_or_equal' => ':Attribute harus berisi tanggal sebelum atau sama dengan :date.',
    'between' => [
        'array' => ':Attribute harus memiliki :min sampai :max anggota.',
        'file' => ':Attribute harus berukuran antara :min sampai :max kilobita.',
        'numeric' => ':Attribute harus bernilai antara :min sampai :max.',
        'string' => ':Attribute harus berisi antara :min sampai :max karakter.',
    ],
    'boolean' => ':Attribute harus bernilai Ya atau Tidak.',
    'confirmed' => 'Konfirmasi :attribute tidak cocok.',
    'current_password' => 'Kata sandi salah.',
    'date' => ':Attribute bukan tanggal yang valid.',
    'date_equals' => ':Attribute harus berisi tanggal yang sama dengan :date.',
    'date_format' => ':Attribute tidak sesuai dengan format :format.',
    'different' => ':Attribute dan :other harus berbeda.',
    'digits' => ':Attribute harus terdiri dari :digits angka.',
    'digits_between' => ':Attribute harus terdiri dari :min sampai :max angka.',
    'email' => ':Attribute harus berupa alamat email yang valid.',
    'exists' => ':Attribute yang dipilih tidak valid.',
    'filled' => ':Attribute wajib diisi.',
    'gt' => [
        'numeric' => ':Attribute harus bernilai lebih besar dari :value.',
        'string' => ':Attribute harus berisi lebih dari :value karakter.',
    ],
    'gte' => [
        'numeric' => ':Attribute harus bernilai lebih besar dari atau sama dengan :value.',
        'string' => ':Attribute harus berisi minimal :value karakter.',
    ],
    'in' => ':Attribute yang dipilih tidak valid.',
    'integer' => ':Attribute harus berupa bilangan bulat.',
    'lowercase' => ':Attribute harus menggunakan huruf kecil.',
    'lt' => [
        'numeric' => ':Attribute harus bernilai kurang dari :value.',
        'string' => ':Attribute harus berisi kurang dari :value karakter.',
    ],
    'lte' => [
        'numeric' => ':Attribute tidak boleh lebih besar dari :value.',
        'string' => ':Attribute tidak boleh lebih dari :value karakter.',
    ],
    'max' => [
        'array' => ':Attribute maksimal memiliki :max anggota.',
        'file' => ':Attribute maksimal berukuran :max kilobita.',
        'numeric' => ':Attribute maksimal bernilai :max.',
        'string' => ':Attribute maksimal berisi :max karakter.',
    ],
    'min' => [
        'array' => ':Attribute minimal memiliki :min anggota.',
        'file' => ':Attribute minimal berukuran :min kilobita.',
        'numeric' => ':Attribute minimal bernilai :min.',
        'string' => ':Attribute minimal berisi :min karakter.',
    ],
    'numeric' => ':Attribute harus berupa angka.',
    'password' => [
        'letters' => ':Attribute harus berisi minimal satu huruf.',
        'mixed' => ':Attribute harus berisi minimal satu huruf besar dan satu huruf kecil.',
        'numbers' => ':Attribute harus berisi minimal satu angka.',
        'symbols' => ':Attribute harus berisi minimal satu simbol.',
        'uncompromised' => ':Attribute ini pernah bocor dalam kebocoran data. Silakan pilih :attribute lain.',
    ],
    'regex' => 'Format :attribute tidak valid.',
    'required' => ':Attribute wajib diisi.',
    'required_if' => ':Attribute wajib diisi bila :other adalah :value.',
    'same' => ':Attribute dan :other harus sama.',
    'string' => ':Attribute harus berupa teks.',
    'unique' => ':Attribute sudah digunakan.',
    'uppercase' => ':Attribute harus menggunakan huruf besar.',
    'url' => 'Format :attribute tidak valid.',

    'custom' => [],

    'attributes' => [
        'nip' => 'NIP',
        'name' => 'nama',
        'email' => 'email',
        'password' => 'kata sandi',
        'current_password' => 'kata sandi saat ini',
        'password_confirmation' => 'konfirmasi kata sandi',
        'role' => 'jabatan',
        'unit_id' => 'unit',
        'code' => 'kode',
    ],

];
