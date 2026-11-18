# API Karyawan Rumah Sakit

Mengambil daftar karyawan aktif rumah sakit: NIP, nama, divisi, departemen, dan perusahaan.

> Perubahan: field identitas sebelumnya `nik`; sekarang `nip` (string, umumnya 11 digit). SIMUTU hanya membaca `nip`.

## Endpoint

```
GET /api/karyawan-rs
```

## Autentikasi

HTTP Basic. Kirim header `Authorization: Basic base64(username:password)`. Username dan password diberikan oleh admin HRIS.

Tanpa kredensial atau kredensial salah: `401 Unauthorized`.

## Batas pemakaian

60 request per menit. Lebih dari itu dijawab `429 Too Many Requests`.

## Parameter query

| Parameter  | Wajib | Keterangan |
|------------|-------|------------|
| `kode`     | Ya    | Kode perusahaan rumah sakit (hanya 2 RS yang tersedia). Kode lain dijawab `404`. |
| `nama`     | Tidak | Cari nama karyawan, cocok sebagian (mengandung teks). |
| `nip`      | Tidak | Cari NIP, harus cocok persis. |
| `per_page` | Tidak | Jumlah data per halaman, 1-200. Bawaan 50. |
| `page`     | Tidak | Nomor halaman. Bawaan 1. |

`nama` dan `nip` bisa dipakai bersamaan, hasilnya harus memenuhi keduanya. Hanya karyawan aktif yang ditampilkan.

## Contoh

```bash
curl -u USERNAME:PASSWORD "https://DOMAIN/api/karyawan-rs?kode=0001&nama=ahmad"
```

Respons `200`:

```json
{
  "success": true,
  "message": "Daftar karyawan rumah sakit.",
  "data": [
    {
      "nip": "19870100001",
      "nama": "Ahmad Fauzi",
      "divisi": "Divisi Pelayanan Medis",
      "departemen": "Departemen Keperawatan",
      "perusahaan": "RSU Syifa Medika Banjarbaru"
    }
  ],
  "pagination": {
    "total": 1,
    "count": 1,
    "per_page": 50,
    "current_page": 1
  }
}
```

`divisi` dan `departemen` bernilai `null` jika karyawan belum ditempatkan pada jabatan atau unit tersebut. `nip` bernilai `null` jika belum diisi.

## Kode error

| Status | Penyebab |
|--------|----------|
| 401 | Kredensial kosong atau salah. |
| 404 | `kode` bukan kode perusahaan rumah sakit yang tersedia. |
| 422 | Parameter tidak valid, mis. `kode` tidak dikirim atau `per_page` di luar 1-200. |
| 429 | Melebihi 60 request per menit. |

Isi respons error:

```json
{ "success": false, "message": "Unauthorized.", "errors": null }
```

## Catatan keamanan

NIP adalah data kepegawaian. Simpan kredensial dengan aman, jangan ditaruh di kode klien atau repositori publik.
