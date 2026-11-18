# API Dokter (Semua Rumah Sakit)

Menampilkan semua dokter aktif dari seluruh rumah sakit: nama, spesialis, dan rumah sakitnya.

## Endpoint

```
GET {base_url}/api/dokter
```

## Autentikasi

**HTTP Basic Auth** — wajib. Kredensial diberikan oleh pengelola API dan diset di `.env` SIMUTU:

```
DOKTER_API_USERNAME=
DOKTER_API_PASSWORD=
```

Contoh request dengan `curl`:

```bash
curl -u username:password {base_url}/api/dokter
```

Tanpa kredensial atau kredensial salah → HTTP `401 Unauthorized`.

Permintaan dibatasi per IP oleh penyedia API.

## Contoh respons (200)

```json
{
  "data": [
    {
      "nama": "dr. Andi Contoh, Sp.PD",
      "spesialis": "Penyakit Dalam",
      "rumah_sakit": "RSU Syifa Medika"
    }
  ]
}
```

## Field respons

| Field | Tipe | Keterangan |
|-------|------|------------|
| `data[].nama` | string | Nama dokter |
| `data[].spesialis` | string \| null | Nama spesialis dokter |
| `data[].rumah_sakit` | string \| null | Nama rumah sakit tempat dokter praktik |

Hanya dokter dengan `aktif = true` yang ditampilkan, diurutkan berdasarkan nama.

## Di SIMUTU

- Klien: `app/Services/DokterService.php`
- Konfigurasi: `config/services.php` → `dokter` (URL dan kredensial dari `.env`)
